<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Events\TicketAssigned;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CreateTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson('/api/v1/tickets', $this->payload())->assertUnauthorized();
    }

    public function test_admin_is_refused(): void
    {
        $this->asAdmin()->postJson('/api/v1/tickets', $this->payload())->assertForbidden();
    }

    public function test_agent_is_refused(): void
    {
        $this->asAgent()->postJson('/api/v1/tickets', $this->payload())->assertForbidden();
    }

    public function test_a_valid_request_creates_a_ticket_and_exactly_one_created_row(): void
    {
        $response = $this->asEndUser()->postJson('/api/v1/tickets', $this->payload())->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertSame(1, TicketActivity::where('ticket_id', $ticket->getKey())->count());

        $activity = TicketActivity::where('ticket_id', $ticket->getKey())->sole();
        $this->assertSame(TicketActivityEvent::Created, $activity->event);
        $this->assertSame($ticket->reference, $activity->meta['reference']);
    }

    public function test_the_requester_is_derived_from_the_caller_and_reused(): void
    {
        $endUser = User::factory()->endUser()->create(['name' => 'Dana Requester', 'email' => 'dana@example.test']);

        $this->withToken($this->tokenFor($endUser))->postJson('/api/v1/tickets', $this->payload())->assertCreated();
        $this->withToken($this->tokenFor($endUser))->postJson('/api/v1/tickets', $this->payload(['subject' => 'Second one']))->assertCreated();

        $this->assertSame(1, Requester::where('email', 'dana@example.test')->count());
        $this->assertSame(2, Ticket::count());
        $ticket = Ticket::query()->first();
        $this->assertSame($endUser->getKey(), $ticket->created_by);
    }

    public function test_a_requester_object_is_prohibited(): void
    {
        $this->asEndUser()->postJson('/api/v1/tickets', $this->payload(['requester' => ['name' => 'X', 'email' => 'x@example.test']]))
            ->assertUnprocessable()->assertJsonValidationErrors('requester');
    }

    public function test_default_priority_and_status_apply_when_omitted(): void
    {
        $response = $this->asEndUser()->postJson('/api/v1/tickets', $this->payload())->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($ticket->priority->is_default);
        $this->assertTrue($ticket->status->is_default);
    }

    public function test_an_explicit_priority_is_honoured(): void
    {
        $priority = Priority::query()->where('is_default', false)->firstOrFail();

        $response = $this->asEndUser()->postJson('/api/v1/tickets', $this->payload([
            'priority_id' => $priority->getKey(),
        ]))->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertSame($priority->getKey(), $ticket->priority_id);
        $this->assertTrue($ticket->status->is_default);
    }

    public function test_an_active_agent_can_be_assigned_at_creation(): void
    {
        Event::fake([TicketAssigned::class]);
        $agent = User::factory()->agent()->create();

        $response = $this->asEndUser()->postJson('/api/v1/tickets', $this->payload(['assigned_to' => $agent->getKey()]))->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertSame($agent->getKey(), $ticket->assigned_to);
        $this->assertSame(2, TicketActivity::where('ticket_id', $ticket->getKey())->count());
        $assigned = TicketActivity::where('ticket_id', $ticket->getKey())->where('event', TicketActivityEvent::Assigned)->sole();
        $this->assertNull($assigned->old_value);
        $this->assertSame((string) $agent->getKey(), $assigned->new_value);
        Event::assertDispatched(TicketAssigned::class, fn ($event) => $event->ticketId === $ticket->getKey() && $event->assigneeId === $agent->getKey());
    }

    public function test_assigning_to_an_admin_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asEndUser()->postJson('/api/v1/tickets', $this->payload(['assigned_to' => $admin->getKey()]))
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_assigning_to_an_inactive_agent_is_rejected(): void
    {
        $agent = User::factory()->agent()->inactive()->create();

        $this->asEndUser()->postJson('/api/v1/tickets', $this->payload(['assigned_to' => $agent->getKey()]))
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_a_validation_failure_writes_no_ticket_and_no_activity(): void
    {
        $this->asEndUser()->postJson('/api/v1/tickets', $this->payload(['subject' => '']))->assertUnprocessable();

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketActivity::count());
    }

    public function test_a_missing_category_id_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['category_id']);

        $this->asEndUser()->postJson('/api/v1/tickets', $payload)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_an_inactive_category_is_rejected(): void
    {
        $inactive = Category::factory()->inactive()->create();

        $this->asEndUser()->postJson('/api/v1/tickets', $this->payload(['category_id' => $inactive->getKey()]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'category_id' => Category::query()->value('id'),
        ], $overrides);
    }

    private function asEndUser(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->endUser()->create()));
    }

    private function asAgent(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->agent()->create()));
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->admin()->create()));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
