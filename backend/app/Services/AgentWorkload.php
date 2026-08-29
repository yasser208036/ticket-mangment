<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Http\Resources\V1\UserResource;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

class AgentWorkload
{
    public function overview(): array
    {
        $priorities = Priority::query()->ordered()->get();
        $openStatusIds = Status::query()->where('is_terminal', false)->pluck('id');
        [$counts, $totals] = $this->ticketCounts($openStatusIds);
        $people = $this->people(array_keys($totals));
        $cohort = $people->filter(fn (User $user) => $user->is_active && $user->role === UserRole::Agent);
        $average = $cohort->isEmpty() ? null : $cohort->sum(fn (User $user) => $totals[$user->getKey()] ?? 0) / $cohort->count();
        $band = $average === null ? null : max(1, (int) ceil($average * 0.25));

        $countMatrix = ['by_priority' => $counts, 'totals' => $totals];
        $loadThresholds = ['average' => $average, 'band' => $band];

        return ['average_open' => $average === null ? null : round($average, 1), 'band' => $band, 'priorities' => $this->priorities($priorities), 'open_status_ids' => $openStatusIds->all(), 'agents' => $this->rows($people, $priorities, $countMatrix, $loadThresholds)];
    }

    private function ticketCounts(Collection $openStatusIds): array
    {
        $groupedCounts = Ticket::query()->whereNotNull('assigned_to')->whereIn('status_id', $openStatusIds)->groupBy('assigned_to', 'priority_id')->selectRaw('assigned_to, priority_id, count(*) as total')->get();
        $counts = [];
        $totals = [];
        foreach ($groupedCounts as $groupedCount) {
            $counts[$groupedCount->assigned_to][$groupedCount->priority_id] = $groupedCount->total;
            $totals[$groupedCount->assigned_to] = ($totals[$groupedCount->assigned_to] ?? 0) + $groupedCount->total;
        }

        return [$counts, $totals];
    }

    private function people(array $holderIds): Collection
    {
        return User::query()->where(fn ($query) => $query->where(fn ($agents) => $agents->where('role', UserRole::Agent)->where('is_active', true))->orWhereIn('id', $holderIds))->orderBy('name')->orderBy('id')->get();
    }

    private function priorities(Collection $priorities): array
    {
        return $priorities->map(fn (Priority $priority): array => ['id' => $priority->id, 'name' => $priority->name, 'slug' => $priority->slug, 'color' => $priority->color, 'level' => $priority->level])->all();
    }

    private function rows(Collection $people, Collection $priorities, array $countMatrix, array $loadThresholds): array
    {
        return $people->map(fn (User $user): array => $this->row($user, $priorities, $countMatrix, $loadThresholds))->all();
    }

    private function row(User $user, Collection $priorities, array $countMatrix, array $loadThresholds): array
    {
        $total = $countMatrix['totals'][$user->getKey()] ?? 0;
        $inAverage = $user->is_active && $user->role === UserRole::Agent;

        return ['user' => UserResource::make($user)->resolve(), 'open_total' => $total, 'in_average' => $inAverage, 'load' => $this->loadBand($total, $inAverage, $loadThresholds['average'], $loadThresholds['band']), 'needs_reassignment' => $total > 0 && ! $inAverage, 'by_priority' => $priorities->map(fn (Priority $priority): array => ['priority_id' => $priority->id, 'count' => $countMatrix['by_priority'][$user->getKey()][$priority->id] ?? 0])->all()];
    }

    private function loadBand(int $total, bool $inAverage, ?float $average, ?int $band): ?string
    {
        if (! $inAverage || $average === null || $band === null) {
            return null;
        }
        if ($total > $average + $band) {
            return 'high';
        }
        if ($total < $average - $band) {
            return 'low';
        }

        return 'normal';
    }
}
