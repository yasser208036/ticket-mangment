<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_a_valid_request_creates_a_ticket_and_exactly_one_created_row(): void
    {
        $response = $this->asAgent()->postJson('/api/v1/tickets', $this->payload())->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertSame(1, TicketActivity::where('ticket_id', $ticket->getKey())->count());

        $activity = TicketActivity::where('ticket_id', $ticket->getKey())->sole();
        $this->assertSame(TicketActivityEvent::Created, $activity->event);
        $this->assertSame($ticket->reference, $activity->meta['reference']);
    }

    public function test_a_repeated_requester_email_reuses_the_same_requester(): void
    {
        $this->asAgent()->postJson('/api/v1/tickets', $this->payload(['requester' => ['name' => 'First Name', 'email' => 'same@example.test']]))->assertCreated();
        $this->asAgent()->postJson('/api/v1/tickets', $this->payload(['requester' => ['name' => 'Second Name', 'email' => 'same@example.test']]))->assertCreated();

        $this->assertSame(1, Requester::where('email', 'same@example.test')->count());
        $this->assertSame(2, Ticket::count());
    }

    public function test_default_priority_and_status_apply_when_omitted(): void
    {
        $response = $this->asAgent()->postJson('/api/v1/tickets', $this->payload())->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertTrue($ticket->priority->is_default);
        $this->assertTrue($ticket->status->is_default);
    }

    public function test_an_explicit_priority_and_status_are_honoured(): void
    {
        $priority = Priority::query()->where('is_default', false)->firstOrFail();
        $status = Status::query()->where('is_default', false)->firstOrFail();

        $response = $this->asAgent()->postJson('/api/v1/tickets', $this->payload([
            'priority_id' => $priority->getKey(),
            'status_id' => $status->getKey(),
        ]))->assertCreated();

        $ticket = Ticket::query()->findOrFail($response->json('data.id'));
        $this->assertSame($priority->getKey(), $ticket->priority_id);
        $this->assertSame($status->getKey(), $ticket->status_id);
    }

    public function test_a_validation_failure_writes_no_ticket_and_no_activity(): void
    {
        $this->asAgent()->postJson('/api/v1/tickets', $this->payload(['subject' => '']))->assertUnprocessable();

        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, TicketActivity::count());
    }

    public function test_a_missing_category_id_is_rejected(): void
    {
        $payload = $this->payload();
        unset($payload['category_id']);

        $this->asAgent()->postJson('/api/v1/tickets', $payload)->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    public function test_an_inactive_category_is_rejected(): void
    {
        $inactive = Category::factory()->inactive()->create();

        $this->asAgent()->postJson('/api/v1/tickets', $this->payload(['category_id' => $inactive->getKey()]))
            ->assertUnprocessable()->assertJsonValidationErrors('category_id');
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'requester' => ['name' => 'Dana Requester', 'email' => 'dana@example.test'],
            'subject' => 'Printer offline',
            'description' => 'The office printer will not turn on.',
            'category_id' => Category::query()->value('id'),
        ], $overrides);
    }

    private function asAgent(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->agent()->create()));
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }
}
