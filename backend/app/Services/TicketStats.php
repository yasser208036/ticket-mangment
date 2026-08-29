<?php

namespace App\Services;

use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class TicketStats
{
    public function for(User $user): array
    {
        $scopeToSelf = ! $user->isAdmin();
        $statuses = Status::query()->ordered()->get();
        $priorities = Priority::query()->ordered()->get();
        $byStatus = $this->scoped($scopeToSelf, $user)->groupBy('status_id')->pluck(DB::raw('count(*)'), 'status_id');
        $byPriority = $this->scoped($scopeToSelf, $user)->groupBy('priority_id')->pluck(DB::raw('count(*)'), 'priority_id');
        $totals = $this->scoped($scopeToSelf, $user)->selectRaw('count(*) as total, coalesce(sum(escalation_level > 0), 0) as escalated')->firstOrFail();
        $mineOpen = Ticket::query()->where('assigned_to', $user->getKey())->whereIn('status_id', Status::query()->select('id')->where('is_terminal', false))->count();

        return [
            'scope' => $scopeToSelf ? 'own' : 'all', 'total' => (int) $totals->total,
            'unassigned' => Ticket::query()->whereNull('assigned_to')->count(), 'escalated' => (int) $totals->escalated,
            'mine_open' => $mineOpen,
            'by_status' => $statuses->map(fn (Status $status): array => ['id' => $status->id, 'name' => $status->name, 'slug' => $status->slug, 'color' => $status->color, 'bucket' => $status->bucket->value, 'is_terminal' => $status->is_terminal, 'count' => (int) ($byStatus[$status->id] ?? 0)])->all(),
            'by_priority' => $priorities->map(fn (Priority $priority): array => ['id' => $priority->id, 'name' => $priority->name, 'slug' => $priority->slug, 'color' => $priority->color, 'level' => $priority->level, 'count' => (int) ($byPriority[$priority->id] ?? 0)])->all(),
        ];
    }

    private function scoped(bool $scopeToSelf, User $user): Builder
    {
        return Ticket::query()->when($scopeToSelf, fn (Builder $query) => $query->where('assigned_to', $user->getKey()));
    }
}
