<?php

namespace Tests\Feature\Categories;

use App\Enums\TicketActivityEvent;
use App\Models\Category;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_deleting_a_category_holding_tickets_requires_a_reassignment_target(): void
    {
        $category = Category::factory()->create();
        Ticket::factory()->count(3)->inCategory($category)->create();

        $this->asAdmin()->deleteJson($this->url($category))
            ->assertUnprocessable()
            ->assertJsonPath('ticket_count', 3);

        $this->assertDatabaseHas('categories', ['id' => $category->getKey(), 'deleted_at' => null]);
    }

    public function test_deleting_with_a_reassignment_target_moves_every_ticket_including_soft_deleted_ones(): void
    {
        $from = Category::factory()->create();
        $to = Category::factory()->create();
        $kept = Ticket::factory()->count(2)->inCategory($from)->create();
        $trashed = Ticket::factory()->inCategory($from)->create();
        $trashed->delete();

        $this->asAdmin()->deleteJson($this->url($from), ['reassign_to' => $to->getKey()])->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $from->getKey()]);
        foreach ([...$kept->all(), $trashed] as $ticket) {
            $this->assertSame($to->getKey(), $ticket->fresh()->category_id);
        }

        $rows = TicketActivity::where('field', 'category_id')->get();
        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertSame(TicketActivityEvent::CategoryChanged, $row->event);
            $this->assertSame((string) $from->getKey(), $row->old_value);
            $this->assertSame((string) $to->getKey(), $row->new_value);
            $this->assertSame('category_deleted', $row->meta['reason']);
        }
    }

    public function test_deleting_an_empty_category_writes_no_activity(): void
    {
        $category = Category::factory()->create();

        $this->asAdmin()->deleteJson($this->url($category))->assertNoContent();

        $this->assertSoftDeleted('categories', ['id' => $category->getKey()]);
        $this->assertSame(0, TicketActivity::where('field', 'category_id')->count());
    }

    public function test_an_agent_is_forbidden(): void
    {
        $category = Category::factory()->create();

        $this->asAgent()->deleteJson($this->url($category))->assertForbidden();
        $this->assertDatabaseHas('categories', ['id' => $category->getKey(), 'deleted_at' => null]);
    }

    private function url(Category $category): string
    {
        return "/api/v1/categories/{$category->id}";
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
