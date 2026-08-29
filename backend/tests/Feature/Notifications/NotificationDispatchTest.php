<?php

namespace Tests\Feature\Notifications;

use App\Events\TicketAssigned;
use App\Events\TicketCreated;
use App\Events\TicketEscalated;
use App\Events\TicketStatusChanged;
use App\Models\Category;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\Concerns\HasRetryPolicy;
use App\Notifications\TicketAssignedNotification;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketEscalatedNotification;
use App\Notifications\TicketStatusChangedNotification;
use App\Services\ActivityRecorder;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\StatusSeeder;
use Database\Seeders\StatusTransitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Cross-cutting suite for TM-57's retry policy, in the shape Story 48's
 * MailLayoutTest established: one fixture, one table of events, every rule
 * asserted across all four. A row missing from events() is a notification
 * with no queueing, recipient or after-commit guarantee.
 */
class NotificationDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class, StatusTransitionSeeder::class]);
    }

    private function statusId(string $slug): int
    {
        return Status::query()->where('slug', $slug)->firstOrFail()->getKey();
    }

    /**
     * @return array<string, array{action: callable(): void, notification: class-string, recipients: callable(): array, jobs: int}>
     */
    private function events(): array
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $assignee = User::factory()->agent()->create();
        $requester = Requester::factory()->create();

        // Explicit, out-of-range references: TicketReferenceGenerator (used by
        // the real POST /tickets endpoint below) and TicketFactory's own
        // counter both start each test at "TKT-2026-000001" independently, so
        // fixture tickets built here must not collide with the one the
        // 'created' action is about to insert for real.
        $assignedTicket = Ticket::factory()->create(['reference' => 'TKT-9999-001', 'status_id' => $this->statusId('open')]);
        $statusTicket = Ticket::factory()->create(['reference' => 'TKT-9999-002', 'status_id' => $this->statusId('open')]);
        $escalationAdmins = User::factory()->admin()->count(3)->create();
        $escalatedTicket = Ticket::factory()->create(['reference' => 'TKT-9999-003', 'status_id' => $this->statusId('open')]);

        return [
            'assigned' => [
                'action' => fn () => $this->actingAs($admin)->postJson(
                    route('tickets.assign', $assignedTicket),
                    ['assigned_to' => $assignee->getKey()],
                )->assertOk(),
                'notification' => TicketAssignedNotification::class,
                'recipients' => fn () => [$assignee],
                'jobs' => 1,
            ],
            'created' => [
                'action' => fn () => $this->actingAs($agent)->postJson(route('tickets.store'), [
                    'requester' => ['name' => $requester->name, 'email' => $requester->email],
                    'subject' => 'Dispatch test ticket',
                    'description' => 'Body for the dispatch test.',
                    'category_id' => Category::query()->value('id'),
                ])->assertCreated(),
                'notification' => TicketCreatedNotification::class,
                'recipients' => fn () => [Requester::where('email', $requester->email)->sole()],
                'jobs' => 1,
            ],
            'status' => [
                'action' => fn () => $this->actingAs($agent)->postJson(
                    route('tickets.status', $statusTicket),
                    ['status_id' => $this->statusId('in-progress')],
                )->assertOk(),
                'notification' => TicketStatusChangedNotification::class,
                'recipients' => fn () => [$statusTicket->requester()->first()],
                'jobs' => 1,
            ],
            'escalated' => [
                'action' => fn () => $this->actingAs($agent)->postJson(
                    route('tickets.escalate', $escalatedTicket),
                    ['reason' => 'Dispatch test escalation reason.'],
                )->assertOk(),
                'notification' => TicketEscalatedNotification::class,
                // Every active admin, which also includes $admin from the
                // 'assigned' row above -- there is only one pool of admins.
                'recipients' => fn () => [...$escalationAdmins->all(), $admin],
                'jobs' => 4,
            ],
        ];
    }

    public function test_every_event_queues_and_sends_nothing_during_the_request(): void
    {
        foreach ($this->events() as $key => $event) {
            DB::table('jobs')->delete();
            app('mailer')->getSymfonyTransport()->flush();

            ($event['action'])();

            $this->assertSame($event['jobs'], DB::table('jobs')->count(), $key);
            $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages(), $key);
        }
    }

    public function test_every_event_reaches_exactly_its_intended_recipients(): void
    {
        Notification::fake();
        $control = User::factory()->agent()->create();

        foreach ($this->events() as $key => $event) {
            ($event['action'])();

            foreach (($event['recipients'])() as $recipient) {
                Notification::assertSentTo($recipient, $event['notification']);
            }
            Notification::assertSentTimes($event['notification'], $event['jobs']);
            Notification::assertNotSentTo($control, $event['notification']);
        }
    }

    public function test_a_rolled_back_action_queues_nothing(): void
    {
        $this->app->bind(ActivityRecorder::class, fn () => new class extends ActivityRecorder
        {
            public function record(int $ticketId, $event, array $attributes = []): void
            {
                throw new RuntimeException('forced failure');
            }
        });

        $agent = User::factory()->agent()->create();

        $this->actingAs($agent)->postJson(route('tickets.store'), [
            'requester' => ['name' => 'Rollback Test', 'email' => 'rollback@example.test'],
            'subject' => 'Rollback test',
            'description' => 'Body.',
            'category_id' => Category::query()->value('id'),
        ])->assertServerError();

        $this->assertDatabaseMissing('requesters', ['email' => 'rollback@example.test']);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    /**
     * RefreshDatabase holds a transaction open for the whole test, so the
     * dispatch-time level is compared against a baseline captured here rather
     * than asserted === 0 -- asserting zero would fail for the wrong reason.
     */
    public function test_every_event_dispatches_after_its_transaction_closes(): void
    {
        $baseline = DB::transactionLevel();
        $levels = [];

        Event::listen(TicketAssigned::class, function () use (&$levels): void {
            $levels['assigned'] = DB::transactionLevel();
        });
        Event::listen(TicketCreated::class, function () use (&$levels): void {
            $levels['created'] = DB::transactionLevel();
        });
        Event::listen(TicketStatusChanged::class, function () use (&$levels): void {
            $levels['status'] = DB::transactionLevel();
        });
        Event::listen(TicketEscalated::class, function () use (&$levels): void {
            $levels['escalated'] = DB::transactionLevel();
        });

        foreach ($this->events() as $key => $event) {
            ($event['action'])();
        }

        foreach (['assigned', 'created', 'status', 'escalated'] as $key) {
            $this->assertArrayHasKey($key, $levels, $key);
            $this->assertSame($baseline, $levels[$key], $key);
        }
    }

    /**
     * 127.0.0.1 is in MailSafety's safe_smtp_hosts, so this transport is
     * permitted; nothing listens on port 1, so it fails the way a real relay
     * outage would, without touching the guard or opening a real socket.
     */
    private function useDeadTransport(): void
    {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp.host', '127.0.0.1');
        config()->set('mail.mailers.smtp.port', 1);
    }

    public function test_a_dead_mail_transport_does_not_fail_the_action(): void
    {
        $this->useDeadTransport();

        foreach ($this->events() as $key => $event) {
            ($event['action'])();
        }

        $this->assertTrue(true);
    }

    public function test_the_configured_tries_reaches_the_payload(): void
    {
        config()->set('notifications.retry.tries', 5);
        $event = $this->events()['assigned'];
        ($event['action'])();

        $payload = json_decode(DB::table('jobs')->first()->payload);
        $this->assertSame(5, $payload->maxTries);
    }

    public function test_the_configured_backoff_reaches_the_payload_as_a_comma_string(): void
    {
        config()->set('notifications.retry.backoff', [30, 90, 180]);
        $event = $this->events()['assigned'];
        ($event['action'])();

        $payload = json_decode(DB::table('jobs')->first()->payload);
        $this->assertSame('30,90,180', $payload->backoff);
    }

    public function test_the_configured_timeout_reaches_the_payload(): void
    {
        config()->set('notifications.retry.timeout', 45);
        $event = $this->events()['assigned'];
        ($event['action'])();

        $payload = json_decode(DB::table('jobs')->first()->payload);
        $this->assertSame(45, $payload->timeout);
    }

    /** A one-line test that turns a footgun into a red suite. */
    public function test_the_timeout_stays_below_the_queue_retry_after(): void
    {
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            (int) config('notifications.retry.timeout'),
        );
    }

    public function test_an_exhausted_job_lands_in_failed_jobs_with_a_logged_context(): void
    {
        config()->set('notifications.retry.tries', 1);
        $this->useDeadTransport();
        Log::spy();

        $event = $this->events()['assigned'];
        ($event['action'])();
        $this->artisan('queue:work', ['--once' => true]);

        $failed = DB::table('failed_jobs')->get();
        $this->assertCount(1, $failed);
        $this->assertNotEmpty($failed[0]->exception);

        // No exact call count: the framework's own default exception reporter
        // also logs at "error" level when a queued job throws. This asserts
        // at least one of those calls is HasRetryPolicy::failed()'s own,
        // proven by its distinctive message and context.
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context) => $message === 'Notification permanently failed.'
                && $context['notification'] === TicketAssignedNotification::class
                && array_key_exists('ticket_id', $context)
                && array_key_exists('reference', $context)
        );
    }

    public function test_the_failure_log_carries_no_email_address(): void
    {
        config()->set('notifications.retry.tries', 1);
        $this->useDeadTransport();
        Log::spy();

        $event = $this->events()['assigned'];
        ($event['action'])();
        $this->artisan('queue:work', ['--once' => true]);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            if ($message !== 'Notification permanently failed.') {
                return false;
            }
            $encoded = json_encode($context);

            return ! str_contains((string) $encoded, '@');
        });
    }

    /**
     * A guard against a well-meaning future edit that would silently zero out
     * a dozen `DB::table('jobs')->count()` assertions across Stories 44-47 --
     * see this plan's first decision for the traced reason.
     */
    public function test_after_commit_is_disabled_on_the_database_connection(): void
    {
        $this->assertFalse(config('queue.connections.database.after_commit'));
    }

    public function test_every_notification_uses_the_retry_policy_trait(): void
    {
        foreach (Finder::create()->files()->in(app_path('Notifications'))->name('*.php')->depth(0) as $file) {
            $class = 'App\\Notifications\\'.$file->getFilenameWithoutExtension();
            $reflection = new ReflectionClass($class);

            $this->assertContains(
                HasRetryPolicy::class,
                $reflection->getTraitNames(),
                "{$class} does not use HasRetryPolicy",
            );
            $this->assertTrue(
                $reflection->hasMethod('failureContext'),
                "{$class} does not declare failureContext()",
            );
        }
    }
}
