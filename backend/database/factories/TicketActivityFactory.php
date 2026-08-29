<?php

namespace Database\Factories;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TicketActivity> */
class TicketActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'event' => TicketActivityEvent::Created,
            'field' => null,
            'old_value' => null,
            'new_value' => null,
            // Never null: ActivityRecorder's json_encode(null) footgun is
            // Story 38's to fix. A factory defaulting to [] cannot reach it.
            'meta' => [],
            'created_at' => Carbon::instance(fake()->dateTimeBetween('-6 months', '-1 hour')),
        ];
    }

    public function created(): static
    {
        return $this->state(fn () => ['event' => TicketActivityEvent::Created]);
    }

    /** The contract Story 38 documents as "the system acted". */
    public function bySystem(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    public function statusChange(string $from, string $to): static
    {
        return $this->state(fn () => [
            'event' => TicketActivityEvent::StatusChanged,
            'field' => 'status',
            'old_value' => $from,
            'new_value' => $to,
        ]);
    }

    public function assignment(User $assignee): static
    {
        return $this->state(fn () => [
            'event' => TicketActivityEvent::Assigned,
            'field' => 'assigned_to',
            'new_value' => (string) $assignee->getKey(),
            'meta' => ['to_name' => $assignee->name],
        ]);
    }

    public function escalation(string $reason): static
    {
        return $this->state(fn () => [
            'event' => TicketActivityEvent::Escalated,
            'meta' => ['reason' => $reason],
        ]);
    }

    public function at(CarbonInterface $when): static
    {
        return $this->state(fn () => ['created_at' => $when]);
    }
}
