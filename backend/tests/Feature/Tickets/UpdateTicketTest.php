<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketActivityEvent;
use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UpdateTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $ticket = Ticket::factory()->create();
        $this->patchJson($this->url($ticket), ['subject' => 'New subject'])->assertUnauthorized();
    }

    public function test_changing_one_field_writes_one_row(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Original subject']);

        $this->asAgent()->patchJson($this->url($ticket), ['subject' => 'Updated subject'])->assertOk();

        $rows = TicketActivity::where('ticket_id', $ticket->getKey())->get();
        $this->assertCount(1, $rows);
        $this->assertSame(TicketActivityEvent::Updated, $rows->first()->event);
        $this->assertSame('subject', $rows->first()->field);
        $this->assertSame('Original subject', $rows->first()->old_value);
        $this->assertSame('Updated subject', $rows->first()->new_value);
    }

    public function test_changing_three_fields_writes_three_distinct_rows(): void
    {
        $ticket = Ticket::factory()->create();
        $category = Category::factory()->create();
        $priority = Priority::query()->where('is_default', false)->firstOrFail();

        $this->asAgent()->patchJson($this->url($ticket), [
            'subject' => 'A different subject entirely',
            'category_id' => $category->getKey(),
            'priority_id' => $priority->getKey(),
        ])->assertOk();

        $rows = TicketActivity::where('ticket_id', $ticket->getKey())->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['category_id', 'priority_id', 'subject'], $rows->pluck('field')->sort()->values()->all());
        foreach ($rows as $row) {
            $this->assertSame(TicketActivityEvent::Updated, $row->event);
        }
    }

    public function test_changing_nothing_writes_no_row_and_leaves_updated_at_untouched(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Unchanged subject']);
        $updatedAtBefore = $ticket->updated_at;

        $this->asAgent()->patchJson($this->url($ticket), ['subject' => 'Unchanged subject'])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->count());
        $this->assertTrue($ticket->fresh()->updated_at->equalTo($updatedAtBefore));
    }

    /** @return list<array{string, mixed}> */
    public static function prohibitedFieldsProvider(): array
    {
        return [
            'status_id' => ['status_id', 1],
            'assigned_to' => ['assigned_to', 1],
            'requester_id' => ['requester_id', 1],
            'reference' => ['reference', 'TKT-9999-000001'],
            'created_by' => ['created_by', 1],
            'escalation_level' => ['escalation_level', 1],
            'escalated_at' => ['escalated_at', '2026-01-01T00:00:00Z'],
            'escalated_by' => ['escalated_by', 1],
            'escalation_reason' => ['escalation_reason', 'irrelevant'],
            'first_responded_at' => ['first_responded_at', '2026-01-01T00:00:00Z'],
            'resolved_at' => ['resolved_at', '2026-01-01T00:00:00Z'],
            'closed_at' => ['closed_at', '2026-01-01T00:00:00Z'],
        ];
    }

    #[DataProvider('prohibitedFieldsProvider')]
    public function test_a_prohibited_field_is_rejected(string $field, mixed $value): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAgent()->patchJson($this->url($ticket), [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    private function url(Ticket $ticket): string
    {
        return "/api/v1/tickets/{$ticket->id}";
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
