<?php

namespace Tests\Feature\Tickets;

use App\Models\Category;
use App\Models\Priority;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketUpdateTest extends TestCase
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

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'x'])->assertUnauthorized();
    }

    public function test_agent_can_update_a_ticket_they_hold(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->assignedTo($agent)->create();

        $this->withToken($this->tokenFor($agent))
            ->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'Corrected subject'])
            ->assertOk()
            ->assertJsonPath('data.subject', 'Corrected subject');
    }

    public function test_agent_is_refused_on_a_ticket_they_do_not_hold(): void
    {
        $agent = User::factory()->agent()->create();
        $ticket = Ticket::factory()->unassigned()->create();

        $this->withToken($this->tokenFor($agent))
            ->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'x'])
            ->assertForbidden();
    }

    public function test_end_user_is_refused_even_on_their_own_ticket(): void
    {
        $endUser = User::factory()->endUser()->create();
        $ticket = Ticket::factory()->create(['created_by' => $endUser->getKey()]);

        $this->withToken($this->tokenFor($endUser))
            ->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'x'])
            ->assertForbidden();
    }

    public function test_validates_only_what_is_sent(): void
    {
        $ticket = Ticket::factory()->create(['description' => 'Original description']);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'New subject'])->assertOk();

        $this->assertSame('Original description', $ticket->fresh()->description);
    }

    public function test_writes_one_activity_row_per_changed_field(): void
    {
        $ticket = Ticket::factory()->create();
        $categories = Category::query()->pluck('id');

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", [
            'subject' => 'New subject',
            'description' => 'New description',
            'category_id' => $categories->first(fn (int $id) => $id !== $ticket->category_id),
        ])->assertOk();

        $rows = TicketActivity::where('ticket_id', $ticket->getKey())->where('event', 'updated')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(['subject', 'description', 'category_id'], $rows->pluck('field')->all());
    }

    public function test_activity_row_captures_old_and_new_values(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Old subject']);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'New subject'])->assertOk();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->where('field', 'subject')->sole();
        $this->assertSame('Old subject', $row->old_value);
        $this->assertSame('New subject', $row->new_value);
        $this->assertNotSame($row->old_value, $row->new_value);
    }

    public function test_activity_meta_records_the_edit_reason(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Old subject']);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'New subject'])->assertOk();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->where('field', 'subject')->sole();
        $this->assertSame('edited', $row->meta['reason']);
    }

    /**
     * Regression: category_id and priority_id are foreign keys, so old_value
     * and new_value are stringified ids. Without meta.from_name/to_name the
     * timeline (TicketActivityResource's from_label/to_label) shows raw
     * numbers instead of names -- reported against the live app.
     */
    public function test_a_category_change_records_human_readable_names(): void
    {
        $hardware = Category::query()->where('slug', 'hardware')->firstOrFail();
        $software = Category::query()->where('slug', 'software')->firstOrFail();
        $ticket = Ticket::factory()->create(['category_id' => $hardware->id]);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['category_id' => $software->id])->assertOk();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->where('field', 'category_id')->sole();
        $this->assertSame((string) $hardware->id, $row->old_value);
        $this->assertSame((string) $software->id, $row->new_value);
        $this->assertSame('Hardware', $row->meta['from_name']);
        $this->assertSame('Software', $row->meta['to_name']);
    }

    public function test_a_priority_change_records_human_readable_names(): void
    {
        $low = Priority::query()->where('slug', 'low')->firstOrFail();
        $urgent = Priority::query()->where('slug', 'urgent')->firstOrFail();
        $ticket = Ticket::factory()->create(['priority_id' => $low->id]);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['priority_id' => $urgent->id])->assertOk();

        $row = TicketActivity::where('ticket_id', $ticket->getKey())->where('field', 'priority_id')->sole();
        $this->assertSame('Low', $row->meta['from_name']);
        $this->assertSame('Urgent', $row->meta['to_name']);
    }

    public function test_unchanged_field_writes_no_activity_row(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Same subject']);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'Same subject'])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->where('event', 'updated')->count());
    }

    public function test_numeric_string_id_matching_current_value_writes_no_row(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['category_id' => (string) $ticket->category_id])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->where('event', 'updated')->count());
    }

    public function test_empty_body_is_a_no_op(): void
    {
        $ticket = Ticket::factory()->create();
        $updatedAt = $ticket->updated_at;

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", [])->assertOk();

        $this->assertSame(0, TicketActivity::where('ticket_id', $ticket->getKey())->where('event', 'updated')->count());
        $this->assertTrue($updatedAt->equalTo($ticket->fresh()->updated_at));
    }

    public function test_rejects_status_id_and_assigned_to(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['status_id' => 2])
            ->assertUnprocessable()->assertJsonValidationErrors('status_id');
        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['assigned_to' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('assigned_to');
    }

    public function test_a_prohibited_key_alongside_a_valid_one_writes_nothing(): void
    {
        $ticket = Ticket::factory()->create(['subject' => 'Original subject']);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'New subject', 'status_id' => 2])
            ->assertUnprocessable();

        $this->assertSame('Original subject', $ticket->fresh()->subject);
    }

    public function test_rejects_blank_editable_fields(): void
    {
        $ticket = Ticket::factory()->create();

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => ''])->assertUnprocessable();
        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['description' => ''])->assertUnprocessable();
    }

    public function test_rejects_a_deactivated_or_soft_deleted_category(): void
    {
        $ticket = Ticket::factory()->create();
        $deactivated = Category::factory()->create(['is_active' => false]);

        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['category_id' => $deactivated->id])
            ->assertUnprocessable();
    }

    public function test_missing_and_soft_deleted_tickets_return_404(): void
    {
        $this->asAdmin()->patchJson('/api/v1/tickets/999999', ['subject' => 'x'])->assertNotFound();

        $ticket = Ticket::factory()->create();
        $ticket->delete();
        $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['subject' => 'x'])->assertNotFound();
    }

    public function test_response_matches_the_show_shape(): void
    {
        $ticket = Ticket::factory()->create();
        $categories = Category::query()->pluck('id');
        $newCategory = $categories->first(fn (int $id) => $id !== $ticket->category_id);

        $response = $this->asAdmin()->patchJson("/api/v1/tickets/{$ticket->id}", ['category_id' => $newCategory])->assertOk();

        $response->assertJsonPath('data.category.id', $newCategory);
        $response->assertJsonStructure(['data' => ['requester', 'category', 'priority', 'status', 'creator', 'description']]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    private function asAdmin(): static
    {
        return $this->withToken($this->tokenFor(User::factory()->admin()->create()));
    }
}
