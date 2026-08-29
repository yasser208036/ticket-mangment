<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\AssignmentChange;
use App\Enums\TicketActivityEvent;
use App\Enums\UserRole;
use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketEscalated;
use App\Events\TicketStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignTicketRequest;
use App\Http\Requests\Api\V1\ChangeTicketStatusRequest;
use App\Http\Requests\Api\V1\EscalateTicketRequest;
use App\Http\Requests\Api\V1\IndexTicketRequest;
use App\Http\Requests\Api\V1\StoreTicketRequest;
use App\Http\Requests\Api\V1\UpdateTicketRequest;
use App\Http\Resources\V1\TicketResource;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\TicketReferenceGenerator;
use App\Services\TicketSearch;
use App\Services\TicketStats;
use App\Services\TicketTimestamps;
use App\Services\TicketWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class TicketController extends Controller
{
    public function stats(Request $request, TicketStats $stats): JsonResponse
    {
        $this->authorize('viewAny', Ticket::class);

        return response()->json(['data' => $stats->for($request->user())]);
    }

    public function index(IndexTicketRequest $request, TicketSearch $search): AnonymousResourceCollection
    {
        $q = $request->string('q')->value();
        $searching = filled($q);
        $sort = $request->string('sort', $searching ? 'relevance' : 'created_at')->value();
        $direction = $request->string('direction', 'desc')->value();
        $assignee = $request->assigneeFilter();
        $tickets = Ticket::query()
            ->with(['requester', 'category', 'priority', 'status', 'assignee', 'creator'])
            ->when($request->has('status_id'), fn ($query) => $query->whereIn('status_id', $request->input('status_id')))
            ->when($request->has('priority_id'), fn ($query) => $query->whereIn('priority_id', $request->input('priority_id')))
            ->when($request->has('category_id'), fn ($query) => $query->whereIn('category_id', $request->input('category_id')))
            ->when($assignee === 'unassigned', fn ($query) => $query->whereNull('assigned_to'))
            ->when(is_int($assignee), fn ($query) => $query->where('assigned_to', $assignee))
            ->when($request->has('escalated'), fn ($query) => $request->boolean('escalated') ? $query->where('escalation_level', '>', 0) : $query->where('escalation_level', 0))
            ->when($searching, fn ($query) => $search->apply($query, $q))
            ->tap(fn ($query) => $this->applySort($query, $sort, $direction, $q, $search))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15))
            ->withQueryString();

        return TicketResource::collection($tickets);
    }

    /** @param Builder<Ticket> $query */
    private function applySort(Builder $query, string $sort, string $direction, string $q, TicketSearch $search): void
    {
        if ($sort === 'relevance') {
            $search->applyRelevanceOrder($query, $q);

            return;
        }
        if ($sort === 'priority') {
            $query->orderBy(Priority::query()->select('level')->whereColumn('priorities.id', 'tickets.priority_id'), $direction);

            return;
        }

        $query->orderBy(IndexTicketRequest::SORTS[$sort], $direction);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return TicketResource::make($ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy'])->loadCount(['activities as reopen_count' => fn ($query) => $query->where('event', TicketActivityEvent::Reopened)]))->response();
    }

    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, TicketWorkflow $workflow, ActivityRecorder $recorder): JsonResponse
    {
        $actor = $request->user();
        $target = Status::query()->findOrFail($request->integer('status_id'));
        [$ticket, $fromStatusId] = DB::transaction(function () use ($request, $ticket, $workflow, $recorder, $actor, $target): array {
            Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->refresh()->load('status');
            $from = $ticket->status;
            $workflow->assertCanTransition($ticket, $target, $actor);
            $ticket->status_id = $target->getKey();
            app(TicketTimestamps::class)->apply($ticket, $from, $target);
            $ticket->save();
            $reopening = $target->slug === Status::SLUG_REOPENED;
            $recorder->record($ticket->getKey(), $reopening ? TicketActivityEvent::Reopened : TicketActivityEvent::StatusChanged, ['user_id' => $actor->getKey(), 'field' => 'status_id', 'old_value' => (string) $from->getKey(), 'new_value' => (string) $target->getKey(), 'meta' => array_filter(['from_name' => $from->name, 'to_name' => $target->name, 'resolution' => $request->validated('resolution'), 'reason' => $request->validated('reason')], fn ($fieldValue) => $fieldValue !== null)]);

            return [$ticket, $from->getKey()];
        });

        // AC1. Outside the transaction on purpose: TM-57 requires notifications
        // to fire after commit, and an email naming a status that rolled back is
        // worse than no email. A refused transition throws from
        // assertCanTransition() inside the closure and never reaches this line.
        TicketStatusChanged::dispatch(
            $ticket->getKey(),
            $fromStatusId,
            $ticket->status_id,
            $request->validated('resolution'),
        );

        return TicketResource::make($ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }

    public function escalate(EscalateTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $actor = $request->user();
        $reason = $request->string('reason')->value();

        $ticket = DB::transaction(function () use ($ticket, $actor, $reason, $recorder): Ticket {
            Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $ticket->refresh()->load(['status', 'priority', 'assignee']);

            // Checked under the lock, so a ticket someone else resolved a
            // moment ago is caught here instead of being escalated after the
            // fact. A denied policy can only ever produce a 403, so this half
            // of the precondition has to live here to return a 422.
            if ($ticket->status->is_terminal) {
                throw ValidationException::withMessages(['status' => ["A {$ticket->status->name} ticket cannot be escalated."]]);
            }

            $admin = $this->escalationTarget($ticket);
            $fromPriority = $ticket->priority;
            $toPriority = $this->nextPriority($fromPriority);
            $fromAssigneeId = $ticket->assigned_to;
            $fromAssigneeName = $ticket->assignee?->name;

            $ticket->escalation_level = $ticket->escalation_level + 1;
            $ticket->escalated_at = now();
            $ticket->escalated_by = $actor->getKey();
            $ticket->escalation_reason = $reason;
            $ticket->priority_id = $toPriority->getKey();
            $ticket->assigned_to = $admin->getKey();
            $ticket->save();

            $recorder->record($ticket->getKey(), TicketActivityEvent::Escalated, [
                'user_id' => $actor->getKey(),
                'field' => 'escalation_level',
                'old_value' => (string) ($ticket->escalation_level - 1),
                'new_value' => (string) $ticket->escalation_level,
                'meta' => ['reason' => $reason, 'from_priority' => $fromPriority->name, 'to_priority' => $toPriority->name],
            ]);

            // Only when the assignee really moved. Assignment history must be
            // findable by field = 'assigned_to', so this is a second row in
            // the assign endpoint's exact shape rather than a key on the
            // escalated row.
            if ($fromAssigneeId !== $admin->getKey()) {
                $recorder->record($ticket->getKey(), TicketActivityEvent::Assigned, [
                    'user_id' => $actor->getKey(),
                    'field' => 'assigned_to',
                    'old_value' => $fromAssigneeId === null ? null : (string) $fromAssigneeId,
                    'new_value' => (string) $admin->getKey(),
                    'meta' => ['from_name' => $fromAssigneeName, 'to_name' => $admin->name, 'reason' => $reason],
                ]);
            }

            return $ticket;
        });

        // AC1. Outside the transaction on purpose: TM-57 requires notifications
        // to fire after commit, and an email announcing an escalation that
        // rolled back is worse than no email. A terminal ticket throws
        // ValidationException from inside the closure and never reaches here.
        TicketEscalated::dispatch(
            $ticket->getKey(),
            $actor->getKey(),
            $ticket->escalation_level,
            $reason,
        );

        return TicketResource::make($ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }

    private function escalationTarget(Ticket $ticket): User
    {
        // Already held by an active admin? Leave it there -- reassigning to a
        // less busy admin would churn ownership for no gain.
        if ($ticket->assignee !== null && $ticket->assignee->is_active && $ticket->assignee->isAdmin()) {
            return $ticket->assignee;
        }

        // "Open" is is_terminal = false, never bucket = 'open'. Ties break on
        // the lowest id so the choice is deterministic. The actor is NOT
        // excluded: an admin escalating a ticket they hold keeps it, and
        // still gets the level and priority raised.
        $admin = User::query()->active()->where('role', UserRole::Admin)
            ->withCount(['assignedTickets as open_tickets_count' => fn ($query) => $query->whereHas('status', fn ($status) => $status->where('is_terminal', false))])
            ->orderBy('open_tickets_count')->orderBy('id')->first();

        if ($admin === null) {
            throw ValidationException::withMessages(['assigned_to' => ['There is no active administrator to escalate to. Activate an admin account first.']]);
        }

        return $admin;
    }

    private function nextPriority(Priority $current): Priority
    {
        // One level up, capped at the highest. Levels ascend -- Low, Medium,
        // High, Urgent -- so "up" is the smallest level greater than this
        // one, and `?? $current` is the cap: the caller never branches on
        // null and meta.to_priority is always a real name.
        return Priority::query()->where('level', '>', $current->level)->orderBy('level')->first() ?? $current;
    }

    public function assign(AssignTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $actorId = $request->user()->getKey();
        $change = new AssignmentChange($actorId, $request->input('assigned_to') === null ? null : (int) $request->input('assigned_to'), $request->input('reason'));
        $changed = DB::transaction(fn (): bool => $this->changeAssignee($ticket, $recorder, $change));
        if ($changed && $change->targetId !== null) {
            TicketAssigned::dispatch($ticket->getKey(), $change->targetId, $actorId, $change->reason);
        }

        return TicketResource::make($ticket->fresh()->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }

    private function changeAssignee(Ticket $ticket, ActivityRecorder $recorder, AssignmentChange $change): bool
    {
        Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
        $ticket->refresh();
        $previousId = $ticket->assigned_to;
        $ticket->assigned_to = $change->targetId;
        if ($ticket->getDirty() === []) {
            return false;
        }
        $ticket->save();
        $this->recordAssignment($ticket, $recorder, $previousId, $change);

        return true;
    }

    private function recordAssignment(Ticket $ticket, ActivityRecorder $recorder, ?int $previousId, AssignmentChange $change): void
    {
        $recorder->record($ticket->getKey(), $change->targetId === null ? TicketActivityEvent::Unassigned : TicketActivityEvent::Assigned, [
            'user_id' => $change->actorId, 'field' => 'assigned_to',
            'old_value' => $previousId === null ? null : (string) $previousId,
            'new_value' => $change->targetId === null ? null : (string) $change->targetId,
            'meta' => [...($change->reason === null ? [] : ['reason' => $change->reason]), 'from_name' => $this->userName($previousId), 'to_name' => $this->userName($change->targetId)],
        ]);
    }

    private function userName(?int $userId): ?string
    {
        return $userId === null ? null : User::query()->whereKey($userId)->value('name');
    }

    public function claim(Request $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $this->authorize('claim', $ticket);
        $claimant = $request->user();
        $holderId = DB::transaction(fn (): int|false|null => $this->claimTicket($ticket, $claimant, $recorder));
        if ($holderId !== null) {
            return $this->claimConflict($holderId);
        }

        return TicketResource::make($ticket->fresh()->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }

    private function claimTicket(Ticket $ticket, User $claimant, ActivityRecorder $recorder): int|false|null
    {
        $won = Ticket::query()->whereKey($ticket->getKey())->whereNull('assigned_to')->update(['assigned_to' => $claimant->getKey()]);
        if ($won === 0) {
            $holderId = Ticket::query()->whereKey($ticket->getKey())->value('assigned_to');

            return $holderId === null ? false : ((int) $holderId === $claimant->getKey() ? null : (int) $holderId);
        }
        $recorder->record($ticket->getKey(), TicketActivityEvent::Claimed, ['user_id' => $claimant->getKey(), 'field' => 'assigned_to', 'old_value' => null, 'new_value' => (string) $claimant->getKey(), 'meta' => ['to_name' => $claimant->name]]);

        return null;
    }

    private function claimConflict(int|false $holderId): JsonResponse
    {
        $holder = $holderId === false ? null : User::query()->find($holderId, ['id', 'name']);
        if ($holder === null) {
            return response()->json(['message' => 'This ticket\'s assignment changed while you were claiming it. Reload and try again.'], 409);
        }

        return response()->json(['message' => "{$holder->name} already claimed this ticket.", 'assignee' => ['id' => $holder->id, 'name' => $holder->name]], 409);
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $actorId = $request->user()->getKey();
        DB::transaction(function () use ($request, $ticket, $recorder, $actorId): void {
            $ticket->fill($request->safe()->only(UpdateTicketRequest::EDITABLE));
            $changes = $ticket->getDirty();
            if ($changes === []) {
                return;
            }
            $originals = array_map(fn (string $field) => $ticket->getOriginal($field), array_combine(array_keys($changes), array_keys($changes)));
            $ticket->save();
            foreach ($changes as $field => $newValue) {
                $recorder->record($ticket->getKey(), TicketActivityEvent::Updated, ['user_id' => $actorId, 'field' => $field, 'old_value' => $originals[$field] === null ? null : (string) $originals[$field], 'new_value' => $newValue === null ? null : (string) $newValue, 'meta' => ['reason' => 'edited']]);
            }
        });

        return TicketResource::make($ticket->fresh()->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }

    public function destroy(Request $request, Ticket $ticket, ActivityRecorder $recorder): Response
    {
        $this->authorize('delete', $ticket);
        $actorId = $request->user()->getKey();
        DB::transaction(function () use ($ticket, $recorder, $actorId): void {
            $ticket->delete();
            $recorder->record($ticket->getKey(), TicketActivityEvent::Deleted, ['user_id' => $actorId, 'meta' => ['reference' => $ticket->reference, 'subject' => $ticket->subject]]);
        });

        return response()->noContent();
    }

    public function store(StoreTicketRequest $request, TicketReferenceGenerator $references, ActivityRecorder $recorder): JsonResponse
    {
        $this->authorize('create', Ticket::class);
        $actorId = $request->user()->getKey();
        $ticket = DB::transaction(function () use ($request, $references, $recorder, $actorId): Ticket {
            $requesterInput = $request->validated('requester');
            $requester = Requester::firstOrCreate(['email' => $requesterInput['email']], Arr::only($requesterInput, ['name', 'phone', 'company']));
            $ticket = new Ticket($request->safe()->only(['subject', 'description', 'category_id']));
            $ticket->requester_id = $requester->getKey();
            $ticket->priority_id = $request->has('priority_id') ? $request->integer('priority_id') : $this->defaultKey(Priority::query(), 'priority');
            $ticket->status_id = $request->has('status_id') ? $request->integer('status_id') : $this->defaultKey(Status::query(), 'status');
            $ticket->created_by = $actorId;
            $ticket->reference = $references->next();
            $ticket->save();
            $recorder->record($ticket->getKey(), TicketActivityEvent::Created, ['user_id' => $actorId, 'meta' => ['reference' => $ticket->reference]]);

            return $ticket;
        });

        // AC1, and the only new line in this controller. Outside the
        // transaction on purpose: TM-57 requires notifications to fire after
        // commit, and a confirmation quoting a rolled-back reference is worse
        // than no confirmation. A failed POST dispatches nothing because the
        // exception propagates before this line.
        TicketCreated::dispatch($ticket->getKey());

        return TicketResource::make($ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator']))->response()->setStatusCode(201);
    }

    /** @param Builder<Priority|Status> $query */
    private function defaultKey(Builder $query, string $label): int
    {
        $key = $query->where('is_default', true)->value('id');
        if ($key === null) {
            throw new LogicException("No default {$label} is configured. Run `php artisan db:seed` to restore master data.");
        }

        return (int) $key;
    }
}
