<?php

namespace Tests\Feature\Notifications;

use App\Events\TicketCreated;
use App\Listeners\SendTicketCreatedConfirmation;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Services\ActivityRecorder;
use App\Services\TicketReferenceGenerator;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\StatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class TicketCreatedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
    }

    private function createTicketViaApi(array $overrides = []): TestResponse
    {
        $agent = User::factory()->agent()->create();
        $categoryId = Category::query()->value('id');
        $payload = array_replace_recursive([
            'requester' => ['name' => 'Dana Requester', 'email' => 'dana@example.test'],
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'category_id' => $categoryId,
        ], $overrides);

        return $this->actingAs($agent)->postJson(route('tickets.store'), $payload);
    }

    private function ticketWithRequester(string $email, string $description): Ticket
    {
        $requester = Requester::create(['name' => 'No Address', 'email' => $email]);

        return DB::transaction(function () use ($requester, $description): Ticket {
            $ticket = new Ticket([
                'subject' => 'Ticket for a requester without an address',
                'description' => $description,
                'category_id' => Category::query()->value('id'),
            ]);
            $ticket->requester_id = $requester->getKey();
            $ticket->priority_id = Priority::query()->where('is_default', true)->value('id');
            $ticket->status_id = Status::query()->where('is_default', true)->value('id');
            $ticket->created_by = User::factory()->agent()->create()->getKey();
            $ticket->reference = app(TicketReferenceGenerator::class)->next();
            $ticket->save();

            return $ticket;
        });
    }

    public function test_creating_a_ticket_notifies_the_requester(): void
    {
        Notification::fake();

        $response = $this->createTicketViaApi();
        $response->assertCreated();

        $requester = Requester::where('email', 'dana@example.test')->sole();
        Notification::assertSentTo($requester, TicketCreatedNotification::class);
        Notification::assertSentTimes(TicketCreatedNotification::class, 1);
    }

    /**
     * No fakes: this is the test that proves AC1 under Story 43's database
     * queue driver. It would pass wrongly under QUEUE_CONNECTION=sync.
     */
    public function test_the_confirmation_is_queued_rather_than_sent_synchronously(): void
    {
        $this->createTicketViaApi()->assertCreated();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());

        $this->artisan('queue:work', ['--once' => true]);

        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_the_message_is_addressed_to_the_requester_and_nobody_else(): void
    {
        $agent = User::factory()->agent()->create(['email' => 'agent@example.test']);
        $categoryId = Category::query()->value('id');
        $this->actingAs($agent)->postJson(route('tickets.store'), [
            'requester' => ['name' => 'Dana Requester', 'email' => 'dana@example.test'],
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'category_id' => $categoryId,
        ])->assertCreated();

        $this->artisan('queue:work', ['--once' => true]);

        $sent = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertSame(['dana@example.test'], array_map(fn ($a) => $a->getAddress(), $sent->getTo()));
        foreach ([$sent->getTo(), $sent->getCc(), $sent->getBcc(), $sent->getReplyTo()] as $addresses) {
            foreach ($addresses as $address) {
                $this->assertNotSame('agent@example.test', $address->getAddress());
            }
        }
    }

    public function test_an_existing_requester_is_notified_for_each_ticket(): void
    {
        Notification::fake();

        $this->createTicketViaApi()->assertCreated();
        $this->createTicketViaApi()->assertCreated();

        $this->assertSame(1, Requester::count());
        Notification::assertSentTimes(TicketCreatedNotification::class, 2);
    }

    /**
     * The API cannot produce a blank requester email — requesters.email is
     * NOT NULL and StoreTicketRequest requires a valid address. The guard
     * still exists because NOT NULL does not forbid the empty string, which a
     * seeder or importer can write directly.
     */
    public function test_a_requester_with_a_blank_email_queues_nothing_and_logs(): void
    {
        Notification::fake();
        Log::spy();
        $ticket = $this->ticketWithRequester('', 'no address');

        TicketCreated::dispatch($ticket->getKey());

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context) => $context['reference'] === $ticket->reference
        );
    }

    public function test_a_failed_creation_dispatches_nothing(): void
    {
        $this->withoutExceptionHandling();
        Event::fake([TicketCreated::class]);
        $this->app->bind(ActivityRecorder::class, fn () => new class extends ActivityRecorder
        {
            public function record(int $ticketId, $event, array $attributes = []): void
            {
                throw new RuntimeException('forced failure');
            }
        });

        $this->expectException(RuntimeException::class);
        $this->createTicketViaApi();

        $this->assertSame(0, Ticket::count());
        Event::assertNotDispatched(TicketCreated::class);
    }

    public function test_the_listener_is_discovered(): void
    {
        Event::fake();

        Event::assertListening(TicketCreated::class, SendTicketCreatedConfirmation::class);
    }

    public function test_a_deleted_ticket_is_skipped(): void
    {
        Notification::fake();
        Log::spy();
        $ticket = $this->ticketWithRequester('dana@example.test', 'body');
        $ticket->delete();

        TicketCreated::dispatch($ticket->getKey());

        Notification::assertNothingSent();
        Log::shouldHaveReceived('warning')->once();
    }
}
