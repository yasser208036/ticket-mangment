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
        $statuses = Status::query()->ordered()->get();
        $priorities = Priority::query()->ordered()->get();
        $byStatus = $this->scoped($user)->groupBy('status_id')->pluck(DB::raw('count(*)'), 'status_id');
        $byPriority = $this->scoped($user)->groupBy('priority_id')->pluck(DB::raw('count(*)'), 'priority_id');
        $totals = $this->scoped($user)->selectRaw('count(*) as total, coalesce(sum(escalation_level > 0), 0) as escalated')->firstOrFail();
        $mineOpen = Ticket::query()->where('assigned_to', $user->getKey())->whereIn('status_id', Status::query()->select('id')->where('is_terminal', false))->count();
        // An agent's visibility scope also covers the unassigned queue (so
        // they can see what to request), but the dashboard's "My escalated
        // tickets" card and its `assignee=me` link both mean tickets they
        // hold. Counting the whole scope here would include escalated
        // tickets nobody currently owns -- the same assigned-to-me split
        // `mine_open` already makes.
        $escalated = $user->isAgent()
            ? Ticket::query()->where('assigned_to', $user->getKey())->where('escalation_level', '>', 0)->count()
            : (int) $totals->escalated;

        return [
            'scope' => match (true) {
                $user->isAdmin() => 'all',
                $user->isAgent() => 'assigned',
                default => 'authored',
            },
            'total' => (int) $totals->total,
            'unassigned' => $this->scoped($user)->whereNull('assigned_to')->count(),
            'escalated' => $escalated,
            'mine_open' => $mineOpen,
            'by_status' => $statuses->map(fn (Status $status): array => ['id' => $status->id, 'name' => $status->name, 'slug' => $status->slug, 'color' => $status->color, 'bucket' => $status->bucket->value, 'is_terminal' => $status->is_terminal, 'count' => (int) ($byStatus[$status->id] ?? 0)])->all(),
            'by_priority' => $priorities->map(fn (Priority $priority): array => ['id' => $priority->id, 'name' => $priority->name, 'slug' => $priority->slug, 'color' => $priority->color, 'level' => $priority->level, 'count' => (int) ($byPriority[$priority->id] ?? 0)])->all(),
        ];
    }

    /** @return Builder<Ticket> */
    private function scoped(User $user): Builder
    {
        return Ticket::query()->visibleTo($user);
    }
}
