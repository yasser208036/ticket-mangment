<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketReferenceGenerator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/** @extends Factory<Ticket> */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        $createdAt = Carbon::instance(fake()->dateTimeBetween('-6 months', '-2 hours'));

        return [
            // Resolved after created_at so the reference year matches the ticket's own year.
            'reference' => fn (array $attributes): string => $this->allocateReference(
                Carbon::parse($attributes['created_at'])->year
            ),
            'subject' => Str::ucfirst(fake()->sentence(6)),
            'description' => fake()->paragraphs(2, true),
            'requester_id' => Requester::factory(),
            'category_id' => fn (): int => $this->requireKey(Category::query(), 'category'),
            'priority_id' => fn (): int => $this->requireDefaultKey(Priority::query(), 'priority'),
            'status_id' => fn (): int => $this->requireDefaultKey(Status::query(), 'status'),
            'created_by' => User::factory()->agent(),
            'assigned_to' => null,
            'escalation_level' => 0,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * Allocate from ticket_sequences, never a private counter: a factory-set reference
     * that bypassed the table collides with the first ticket filed through
     * POST /api/v1/tickets (measured: 1062 on tickets_reference_unique).
     *
     * This needs a live database connection, so Ticket::factory()->make() does too.
     * Every test in this suite has one by design — phpunit.xml forbids SQLite.
     */
    private function allocateReference(int $year): string
    {
        $generator = app(TicketReferenceGenerator::class);

        return DB::transactionLevel() > 0
            ? $generator->next($year)
            : DB::transaction(fn (): string => $generator->next($year));
    }

    /** @param Builder<Category> $query */
    private function requireKey(Builder $query, string $label): int
    {
        $key = $query->value('id');
        if ($key === null) {
            throw new LogicException("No {$label} exists. Run `php artisan db:seed` to restore master data.");
        }

        return (int) $key;
    }

    /** @param Builder<Priority|Status> $query */
    private function requireDefaultKey(Builder $query, string $label): int
    {
        $key = $query->where('is_default', true)->value('id');
        if ($key === null) {
            throw new LogicException("No default {$label} is configured. Run `php artisan db:seed` to restore master data.");
        }

        return (int) $key;
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => ['assigned_to' => $user->getKey()]);
    }

    public function unassigned(): static
    {
        return $this->state(fn () => ['assigned_to' => null]);
    }

    public function inStatus(string $slug): static
    {
        return $this->state(function () use ($slug): array {
            $id = Status::query()->where('slug', $slug)->value('id');
            if ($id === null) {
                throw new LogicException("Unknown status slug [{$slug}].");
            }

            return ['status_id' => $id];
        });
    }

    public function withPriority(string $slug): static
    {
        return $this->state(function () use ($slug): array {
            $id = Priority::query()->where('slug', $slug)->value('id');
            if ($id === null) {
                throw new LogicException("Unknown priority slug [{$slug}].");
            }

            return ['priority_id' => $id];
        });
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn () => ['category_id' => $category->getKey()]);
    }

    /**
     * Sets all four escalation columns together. escalation_level > 0 with a
     * null escalated_at is a state no code path produces, and the index
     * endpoint's `escalated` filter reads only the level.
     */
    public function escalated(int $level = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'escalation_level' => $level,
            'escalated_at' => Carbon::parse($attributes['created_at'])->addHours(fake()->numberBetween(1, 48)),
            'escalated_by' => User::factory()->admin(),
            'escalation_reason' => fake()->sentence(10),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(function (array $attributes): array {
            $resolvedAt = Carbon::parse($attributes['created_at'])->addHours(fake()->numberBetween(1, 96));

            return [
                'status_id' => Status::query()->where('slug', Status::SLUG_RESOLVED)->value('id'),
                'first_responded_at' => Carbon::parse($attributes['created_at'])->addHours(fake()->numberBetween(1, 4)),
                'resolved_at' => $resolvedAt,
            ];
        });
    }

    public function closed(): static
    {
        return $this->state(function (array $attributes): array {
            $resolvedAt = Carbon::parse($attributes['created_at'])->addHours(fake()->numberBetween(1, 96));
            $closedAt = $resolvedAt->clone()->addHours(fake()->numberBetween(1, 72));

            return [
                'status_id' => Status::query()->where('slug', Status::SLUG_CLOSED)->value('id'),
                'first_responded_at' => Carbon::parse($attributes['created_at'])->addHours(fake()->numberBetween(1, 4)),
                'resolved_at' => $resolvedAt,
                'closed_at' => $closedAt,
            ];
        });
    }

    /**
     * The reference reallocates for the new year automatically: `reference`
     * is a closure over $attributes, resolved after created_at is set.
     */
    public function createdAt(CarbonInterface $at): static
    {
        return $this->state(fn () => ['created_at' => $at, 'updated_at' => $at]);
    }

    /**
     * `save()` overwrites updated_at on any later write — a factory attribute
     * survives the initial insert, but this state does not protect a
     * subsequent ->save() call. Use Ticket::withoutTimestamps() there.
     */
    public function stale(int $hours = 72): static
    {
        return $this->state(fn () => ['updated_at' => now()->subHours($hours)]);
    }
}
