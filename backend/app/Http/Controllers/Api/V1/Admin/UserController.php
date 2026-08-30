<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\TicketActivityEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\DestroyUserRequest;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Administrative CRUD for staff accounts.
 *
 * destroy() is not a plain delete: tickets.created_by is ON DELETE RESTRICT, so
 * a user who ever created a ticket cannot be removed until those rows have
 * somewhere else to go. The endpoint refuses with a 422 naming the destination
 * it needs, exactly as CategoryController::destroy does. Deactivation remains
 * the ordinary answer; the screen, not the router, expresses that preference.
 */
class UserController extends Controller
{
    private const LAST_ADMIN = 'This is the last active administrator. Promote someone else first.';

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $users = User::query()
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $query->where(fn ($query) => $query->whereLike('name', $term)->orWhereLike('email', $term));
            })
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->active())
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->when(filled($filters['role'] ?? null), fn ($query) => $query->where('role', $filters['role']))
            ->orderBy('name')->orderBy('id')
            ->paginate($filters['per_page'] ?? 15)->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);
        $user = new User($request->safe()->only(['name', 'email', 'password', 'is_active']));
        $user->role = $request->enum('role', UserRole::class);
        $user->save();

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return UserResource::make($user)->response();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $deactivating = $request->has('is_active') && ! $request->boolean('is_active');
        $demoting = $request->has('role') && $request->enum('role', UserRole::class) !== UserRole::Admin && $user->isAdmin();
        DB::transaction(function () use ($request, $user, $deactivating, $demoting): void {
            if ($deactivating || $demoting) {
                $this->guardAgainstLockout($request, $user, $deactivating);
            }
            $user->fill($request->safe()->only(['name', 'email', 'is_active']));
            if ($request->has('role')) {
                $user->role = $request->enum('role', UserRole::class);
            }
            $user->save();
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        return UserResource::make($user->refresh())->response();
    }

    public function destroy(DestroyUserRequest $request, User $user, ActivityRecorder $recorder): Response|JsonResponse
    {
        $this->authorize('delete', $user);
        $targetId = $request->has('reassign_to') ? $request->integer('reassign_to') : null;

        return DB::transaction(function () use ($request, $user, $recorder, $targetId): Response|JsonResponse {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            // Deleting an agent can never empty the admin role, so only count
            // when it could. Self-deletion is already a 403 from the policy.
            if ($user->isAdmin() && $this->otherActiveAdmins($user) === 0) {
                throw ValidationException::withMessages(['user' => self::LAST_ADMIN]);
            }
            $ticketCount = $this->ticketCountFor($user);
            if ($ticketCount > 0) {
                if ($targetId === null) {
                    return $this->reassignmentRequired($user, $ticketCount);
                }
                $this->reassignTickets($user, $targetId, $request->user()->getKey(), $recorder);
            }
            // personal_access_tokens is a morph with no foreign key, so nothing
            // cascades — the rows would simply be orphaned.
            $user->tokens()->delete();
            $user->delete();

            return response()->noContent();
        });
    }

    private function guardAgainstLockout(Request $request, User $user, bool $deactivating): void
    {
        $field = $deactivating ? 'is_active' : 'role';
        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                $field => $deactivating ? 'You cannot deactivate your own account.' : 'You cannot change your own role.',
            ]);
        }
        if ($this->otherActiveAdmins($user) === 0) {
            throw ValidationException::withMessages([$field => self::LAST_ADMIN]);
        }
    }

    /** Active admins other than this one, counted under a row lock. */
    private function otherActiveAdmins(User $user): int
    {
        return User::query()->where('role', UserRole::Admin)->where('is_active', true)
            ->whereKeyNot($user->getKey())->lockForUpdate()->count();
    }

    /** Soft-deleted tickets still hold a live foreign key, hence withTrashed(). */
    private function ticketCountFor(User $user): int
    {
        return Ticket::withTrashed()
            ->where(fn ($query) => $query->where('created_by', $user->getKey())->orWhere('assigned_to', $user->getKey()))
            ->count();
    }

    private function reassignmentRequired(User $user, int $ticketCount): JsonResponse
    {
        $options = User::query()->active()->whereKeyNot($user->getKey())->orderBy('name')->orderBy('id')->get();
        $message = $options->isEmpty()
            ? "This user still has {$ticketCount} tickets, and there is no other active account to move them to."
            : "This user still has {$ticketCount} tickets. Choose who inherits them, then delete again.";

        return response()->json([
            'message' => $message,
            'errors' => ['reassign_to' => [$message]],
            'ticket_count' => $ticketCount,
            'reassign_to_options' => UserResource::collection($options)->resolve(),
        ], 422);
    }

    private function reassignTickets(User $user, int $targetId, int $actorId, ActivityRecorder $recorder): void
    {
        $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
        $assignedIds = Ticket::withTrashed()->where('assigned_to', $user->getKey())->pluck('id')->all();
        $authoredIds = Ticket::withTrashed()->where('created_by', $user->getKey())->pluck('id')->all();
        Ticket::withTrashed()->whereIn('id', $assignedIds)->update(['assigned_to' => $target->getKey()]);
        Ticket::withTrashed()->whereIn('id', $authoredIds)->update(['created_by' => $target->getKey()]);
        // Written before the delete, while the name is still readable: meta
        // is the only place the deleted person survives in the trail.
        $meta = ['reason' => 'user_deleted', 'from_name' => $user->name, 'to_name' => $target->name];
        $recorder->recordMany($assignedIds, TicketActivityEvent::Assigned, [
            'user_id' => $actorId, 'field' => 'assigned_to',
            'old_value' => (string) $user->getKey(), 'new_value' => (string) $target->getKey(), 'meta' => $meta,
        ]);
        $recorder->recordMany($authoredIds, TicketActivityEvent::Updated, [
            'user_id' => $actorId, 'field' => 'created_by',
            'old_value' => (string) $user->getKey(), 'new_value' => (string) $target->getKey(), 'meta' => $meta,
        ]);
    }
}
