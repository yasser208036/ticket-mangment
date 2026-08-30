<?php

namespace Tests\Feature\Tickets;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketIndexSortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_sorts_by_most_recently_escalated_first(): void
    {
        $oldest = Ticket::factory()->create(['escalated_at' => now()->subDays(3)]);
        $middle = Ticket::factory()->create(['escalated_at' => now()->subDay()]);
        $newest = Ticket::factory()->create(['escalated_at' => now()]);
        $neverA = Ticket::factory()->create(['escalated_at' => null]);
        $neverB = Ticket::factory()->create(['escalated_at' => null]);

        $ids = $this->asAgent()->getJson('/api/v1/tickets?sort=escalated_at&direction=desc&per_page=100')
            ->assertOk()->json('data.*.id');

        $order = array_flip($ids);
        $this->assertTrue($order[$newest->id] < $order[$middle->id]);
        $this->assertTrue($order[$middle->id] < $order[$oldest->id]);
        $this->assertTrue($order[$oldest->id] < $order[$neverA->id]);
        $this->assertTrue($order[$oldest->id] < $order[$neverB->id]);
    }

    public function test_the_sort_does_not_filter(): void
    {
        Ticket::factory()->count(3)->create(['escalated_at' => null]);
        $unsortedTotal = $this->asAgent()->getJson('/api/v1/tickets')->json('meta.total');
        $this->asAgent()->getJson('/api/v1/tickets?sort=escalated_at&direction=desc')
            ->assertOk()->assertJsonPath('meta.total', $unsortedTotal);
    }

    public function test_ascending_puts_never_escalated_first(): void
    {
        $escalated = Ticket::factory()->create(['escalated_at' => now()]);
        $never = Ticket::factory()->create(['escalated_at' => null]);

        $ids = $this->asAgent()->getJson('/api/v1/tickets?sort=escalated_at&direction=asc&per_page=100')
            ->assertOk()->json('data.*.id');
        $order = array_flip($ids);
        $this->assertTrue($order[$never->id] < $order[$escalated->id]);
    }

    public function test_escalated_filter_and_escalated_sort_compose(): void
    {
        $escalated = Ticket::factory()->create(['escalation_level' => 1, 'escalated_at' => now()]);
        Ticket::factory()->create(['escalation_level' => 0, 'escalated_at' => null]);

        $this->asAgent()->getJson('/api/v1/tickets?escalated=1&sort=escalated_at&direction=desc')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $escalated->id);
    }

    public function test_the_whitelist_message_names_the_new_sort(): void
    {
        $this->asAgent()->getJson('/api/v1/tickets?sort=id')
            ->assertUnprocessable()
            ->assertJsonFragment(['sort' => ['Tickets can only be sorted by created_at, updated_at, priority, relevance or escalated_at.']]);
    }

    public function test_the_escalated_filter_still_works(): void
    {
        Ticket::factory()->create(['escalation_level' => 1]);
        Ticket::factory()->create(['escalation_level' => 0]);

        $this->asAgent()->getJson('/api/v1/tickets?escalated=1')->assertOk()->assertJsonCount(1, 'data');
        $this->asAgent()->getJson('/api/v1/tickets?escalated=0')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * The SPA (DashboardView's escalated card, TicketFilterBar's escalation
     * select) sends the JS-boolean spelling, not '1'/'0'. Laravel's `boolean`
     * rule rejects the literal strings 'true'/'false' by default, so this
     * filter 422'd from the UI even though the '1'/'0' test above always
     * passed.
     */
    public function test_the_escalated_filter_accepts_the_javascript_boolean_spelling(): void
    {
        Ticket::factory()->create(['escalation_level' => 1]);
        Ticket::factory()->create(['escalation_level' => 0]);

        $this->asAgent()->getJson('/api/v1/tickets?escalated=true')->assertOk()->assertJsonCount(1, 'data');
        $this->asAgent()->getJson('/api/v1/tickets?escalated=false')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_invalid_escalated_value_is_still_rejected(): void
    {
        $this->asAgent()->getJson('/api/v1/tickets?escalated=maybe')
            ->assertUnprocessable()
            ->assertJsonPath('errors.escalated.0', 'The escalated field must be true or false.');
    }

    private function asAgent(): static
    {
        return $this->withToken(User::factory()->agent()->create()->createToken('test')->plainTextToken);
    }
}
