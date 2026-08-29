<?php

namespace App\Services;

use App\Enums\TicketActivityEvent;
use App\Models\TicketActivity;
use Illuminate\Support\Facades\DB;
use LogicException;

class ActivityRecorder
{
    /** @param array<string, mixed> $attributes */
    public function record(int $ticketId, TicketActivityEvent $event, array $attributes = []): void
    {
        $this->recordMany([$ticketId], $event, $attributes + [
            'user_id' => null, 'field' => null, 'old_value' => null,
            'new_value' => null, 'meta' => [],
        ]);
    }

    public function recordMany(array $ticketIds, TicketActivityEvent $event, array $attributes = []): void
    {
        if ($ticketIds === []) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivityRecorder must be called inside a database transaction.');
        }
        $attributes += [
            'user_id' => null, 'field' => null, 'old_value' => null,
            'new_value' => null, 'meta' => [],
        ];
        $createdAt = now();
        $rows = array_map(fn (int $ticketId): array => [
            'ticket_id' => $ticketId, 'event' => $event->value, 'created_at' => $createdAt,
            'user_id' => $attributes['user_id'], 'field' => $attributes['field'],
            'old_value' => $attributes['old_value'], 'new_value' => $attributes['new_value'],
            'meta' => json_encode($attributes['meta'], JSON_UNESCAPED_UNICODE),
        ], $ticketIds);
        foreach (array_chunk($rows, 500) as $chunk) {
            TicketActivity::insert($chunk);
        }
    }
}
