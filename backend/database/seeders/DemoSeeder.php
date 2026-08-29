<?php

namespace Database\Seeders;

use App\Enums\StatusBucket;
use App\Enums\TicketActivityEvent;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ~50 tickets across every status and priority, with a plausible activity
 * history per ticket. Local development only — see guardEnvironment().
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const ADMIN_NAMES = ['Ada Lovelace', 'Grace Hopper'];

    private const AGENT_NAMES = ['Alan Turing', 'Katherine Johnson', 'Margaret Hamilton', 'Dennis Ritchie', 'Barbara Liskov', 'Radia Perlman'];

    private const ESCALATION_REASONS = [
        'Requester is a VIP account and needs a faster response.',
        'Issue is blocking a production deployment.',
        'Repeated contact with no resolution after multiple days.',
        'Potential data-loss risk if not addressed promptly.',
    ];

    public function run(): void
    {
        $this->guardEnvironment();
        $this->guardEmptyTickets();
        $this->call([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);

        $admins = $this->seedAdmins();
        $agents = $this->seedAgents();
        $requesters = $this->seedRequesters();

        DB::transaction(fn () => $this->seedTickets($admins, $agents, $requesters));
    }

    /**
     * `db:seed --force` bypasses artisan's confirmation prompt entirely
     * (ConfirmableTrait::confirmToProceed()), so the prompt protects only an
     * interactive operator. This throw is the real guard.
     */
    private function guardEnvironment(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException(
                'DemoSeeder must never run in production. It is not registered in DatabaseSeeder, '
                .'and unlike artisan\'s confirmation prompt this check is not bypassed by --force.'
            );
        }
    }

    /**
     * withTrashed(), not exists(): tickets soft-delete, and a demo set someone
     * soft-deleted while exploring the UI must still block a re-seed. True
     * idempotency would mean inventing demo identifiers to match on; refusing
     * a second run is simpler and just as safe.
     */
    private function guardEmptyTickets(): void
    {
        if (Ticket::withTrashed()->exists()) {
            throw new RuntimeException('DemoSeeder found existing tickets. Run `php artisan migrate:fresh --seed` first, then seed the demo data.');
        }
    }

    /** @return Collection<int, User> */
    private function seedAdmins(): Collection
    {
        // Not AdminUserSeeder: its blank-password guard aborts whenever
        // ADMIN_PASSWORD is unset, which is exactly the test environment.
        $names = array_slice(self::ADMIN_NAMES, 0, max(1, (int) config('seeding.demo.admins')));

        return collect($names)->map(
            fn (string $name) => User::factory()->admin()->withName($name)->create([
                'password' => config('seeding.demo.password'),
            ])
        );
    }

    /** @return Collection<int, User> */
    private function seedAgents(): Collection
    {
        $count = max(1, (int) config('seeding.demo.agents'));
        $names = array_slice(self::AGENT_NAMES, 0, $count);

        return collect($names)->map(function (string $name, int $i) use ($count) {
            $factory = User::factory()->agent()->withName($name);
            // The last agent is inactive — proves the `active` middleware and
            // the admin deactivation toggle are real, and must never be an
            // assignee below.
            if ($i === $count - 1) {
                $factory = $factory->inactive();
            }

            return $factory->create(['password' => config('seeding.demo.password')]);
        });
    }

    /** @return Collection<int, Requester> */
    private function seedRequesters(): Collection
    {
        $count = max(1, (int) ceil(config('seeding.demo.tickets') / 2));
        $requesters = Requester::factory()->count($count)->create();
        $requesters->take(min(2, $count))->each(
            fn (Requester $requester) => $requester->update(['phone' => null, 'company' => null])
        );

        return $requesters;
    }

    /**
     * @param  Collection<int, User>  $admins
     * @param  Collection<int, User>  $agents
     * @param  Collection<int, Requester>  $requesters
     */
    private function seedTickets(Collection $admins, Collection $agents, Collection $requesters): void
    {
        $statuses = Status::query()->ordered()->get();
        $priorities = Priority::query()->ordered()->get();
        $categories = Category::query()->ordered()->get();
        $activeAgents = $agents->filter(fn (User $agent) => $agent->is_active)->values();

        $recorder = app(ActivityRecorder::class);
        $count = max(1, (int) config('seeding.demo.tickets'));
        $months = max(1, (int) config('seeding.demo.months'));
        $span = $months * 30;

        for ($i = 0; $i < $count; $i++) {
            $status = $statuses[$i % $statuses->count()];
            $priority = $priorities[$i % $priorities->count()];
            $category = $categories[$i % $categories->count()];
            $requester = $requesters[$i % $requesters->count()];
            $creator = $activeAgents[$i % $activeAgents->count()];

            $daysAgo = (int) round($span * (1 - $i / max(1, $count - 1)));
            $createdAt = now()->subDays($daysAgo)->subMinutes(fake()->numberBetween(0, 1439));

            $isEscalated = $i % 7 === 0 && $status->bucket !== StatusBucket::Done;

            $ticket = $this->buildTicket($status, $priority, $category, $requester, $creator, $activeAgents, $createdAt, $isEscalated, $admins);

            $this->recordHistory($recorder, $ticket, $creator, $status, $isEscalated, $admins);
        }
    }

    /**
     * @param  Collection<int, User>  $activeAgents
     * @param  Collection<int, User>  $admins
     */
    private function buildTicket(
        Status $status,
        Priority $priority,
        Category $category,
        Requester $requester,
        User $creator,
        Collection $activeAgents,
        CarbonInterface $createdAt,
        bool $isEscalated,
        Collection $admins,
    ): Ticket {
        $factory = Ticket::factory()
            ->createdAt($createdAt)
            ->state([
                'requester_id' => $requester->getKey(),
                'category_id' => $category->getKey(),
                'priority_id' => $priority->getKey(),
                'status_id' => $status->getKey(),
                'created_by' => $creator->getKey(),
                ...$this->timestampsFor($status, $createdAt),
            ]);

        // "new" tickets stay unassigned — that is the unassigned queue.
        $factory = $status->slug === Status::SLUG_NEW
            ? $factory->unassigned()
            : $factory->assignedTo($activeAgents[($status->getKey() + $createdAt->day) % $activeAgents->count()]);

        if ($isEscalated) {
            $level = fake()->numberBetween(1, 2);
            $factory = $factory->state([
                'escalation_level' => $level,
                'escalated_at' => $this->clamp($createdAt->clone()->addHours(fake()->numberBetween(1, 48))),
                'escalated_by' => $admins->random()->getKey(),
                'escalation_reason' => fake()->randomElement(self::ESCALATION_REASONS),
            ]);
        }

        return $factory->create();
    }

    /** @return array<string, mixed> */
    private function timestampsFor(Status $status, CarbonInterface $createdAt): array
    {
        $timestamps = $this->statusTimestamps($status, $createdAt);

        // updated_at reflects the ticket's own latest event, clamped to now(),
        // so sorting and the staleness window see something real.
        $latest = collect($timestamps)->filter()->push($createdAt)->max();

        return [...$timestamps, 'updated_at' => $this->clamp($latest)];
    }

    /**
     * Every derived timestamp is clamped to now(): a ticket near the end of
     * the spread can otherwise compute a first-response/resolution/escalation
     * offset that lands in the future, which no code path in the real product
     * ever produces.
     */
    private function clamp(CarbonInterface $at): CarbonInterface
    {
        return $at->isFuture() ? now() : $at;
    }

    /** @return array<string, mixed> */
    private function statusTimestamps(Status $status, CarbonInterface $createdAt): array
    {
        return match ($status->slug) {
            Status::SLUG_NEW => [],
            'open', 'in-progress', 'pending' => [
                'first_responded_at' => $this->clamp($createdAt->clone()->addHours(fake()->numberBetween(1, 8))),
            ],
            Status::SLUG_RESOLVED => [
                'first_responded_at' => $this->clamp($createdAt->clone()->addHours(fake()->numberBetween(1, 4))),
                'resolved_at' => $this->clamp($createdAt->clone()->addDays(fake()->numberBetween(1, 5))),
            ],
            Status::SLUG_CLOSED => (function () use ($createdAt): array {
                $resolvedAt = $this->clamp($createdAt->clone()->addDays(fake()->numberBetween(1, 5)));

                return [
                    'first_responded_at' => $this->clamp($createdAt->clone()->addHours(fake()->numberBetween(1, 4))),
                    'resolved_at' => $resolvedAt,
                    'closed_at' => $this->clamp($resolvedAt->clone()->addDays(fake()->numberBetween(1, 3))),
                ];
            })(),
            // Reopening clears resolved_at — a reopened ticket still reporting
            // a resolution date is the most visibly wrong row a demo could have.
            Status::SLUG_REOPENED => [
                'first_responded_at' => $this->clamp($createdAt->clone()->addHours(fake()->numberBetween(1, 4))),
            ],
            default => [],
        };
    }

    /** @param Collection<int, User> $admins */
    private function recordHistory(ActivityRecorder $recorder, Ticket $ticket, User $creator, Status $status, bool $isEscalated, Collection $admins): void
    {
        $this->recordAt($ticket->created_at, fn () => $recorder->record(
            $ticket->getKey(),
            TicketActivityEvent::Created,
            ['user_id' => $creator->getKey(), 'meta' => ['reference' => $ticket->reference]],
        ));

        if ($status->slug !== Status::SLUG_NEW) {
            $this->recordAt($ticket->first_responded_at ?? $ticket->created_at, fn () => $recorder->record(
                $ticket->getKey(),
                TicketActivityEvent::Assigned,
                ['user_id' => $creator->getKey(), 'field' => 'assigned_to', 'new_value' => (string) $ticket->assigned_to, 'meta' => ['to_name' => $ticket->assignee?->name]],
            ));
        }

        $this->recordStatusTrail($recorder, $ticket, $status, $creator);

        if ($isEscalated) {
            $this->recordAt($ticket->escalated_at, fn () => $recorder->record(
                $ticket->getKey(),
                TicketActivityEvent::Escalated,
                ['user_id' => $admins->random()->getKey(), 'meta' => ['reason' => $ticket->escalation_reason]],
            ));
        }
    }

    private function recordStatusTrail(ActivityRecorder $recorder, Ticket $ticket, Status $status, User $creator): void
    {
        $trail = match ($status->slug) {
            Status::SLUG_RESOLVED => [[Status::SLUG_RESOLVED, $ticket->resolved_at]],
            Status::SLUG_CLOSED => [[Status::SLUG_RESOLVED, $ticket->resolved_at], [Status::SLUG_CLOSED, $ticket->closed_at]],
            Status::SLUG_REOPENED => [
                [Status::SLUG_RESOLVED, $ticket->first_responded_at],
                [Status::SLUG_CLOSED, $ticket->first_responded_at],
                [Status::SLUG_REOPENED, $this->clamp($ticket->created_at->clone()->addHour())],
            ],
            'in-progress', 'pending' => [[$status->slug, $ticket->first_responded_at]],
            default => [],
        };

        foreach ($trail as [$slug, $when]) {
            $event = $slug === Status::SLUG_REOPENED ? TicketActivityEvent::Reopened : TicketActivityEvent::StatusChanged;
            $this->recordAt($when, fn () => $recorder->record(
                $ticket->getKey(),
                $event,
                ['user_id' => $creator->getKey(), 'field' => 'status', 'new_value' => $slug],
            ));
        }
    }

    /**
     * ActivityRecorder::record() stamps created_at with now(), so back-dated
     * history needs the clock frozen around the call. The reset MUST be in a
     * finally — a leaked test-now silently freezes every later now() in the
     * process and corrupts unrelated tests.
     */
    private function recordAt(?CarbonInterface $at, callable $write): void
    {
        try {
            Carbon::setTestNow($at ?? now());
            $write();
        } finally {
            Carbon::setTestNow();
        }
    }
}
