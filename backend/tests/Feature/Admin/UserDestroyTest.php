<?php

namespace Tests\Feature\Admin;

use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\PrioritySeeder;
use Database\Seeders\StatusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * DELETE /admin/users/{user}. tickets.created_by is ON DELETE RESTRICT, so the
 * interesting cases are all about where a departing user's tickets go.
 */
class UserDestroyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Master data only — the bare seed() would add an admin and change
        // every last-active-administrator count in this file.
        $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
    }

    public function test_it_deletes_a_user_with_no_tickets(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        $this->deleteJson($this->url($agent))->assertNoContent();
        $this->assertNull(User::find($agent->getKey()));
    }

    public function test_it_revokes_tokens_before_deleting(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        $agent->createToken('phone');
        $this->deleteJson($this->url($agent))->assertNoContent();
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $agent->getKey())->count());
    }

    public function test_it_refuses_to_delete_a_user_who_has_tickets(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        Ticket::factory()->create(['created_by' => $agent->getKey()]);
        $response = $this->deleteJson($this->url($agent))->assertUnprocessable();
        $response->assertJsonValidationErrors('reassign_to');
        $this->assertSame(1, $response->json('ticket_count'));
        $this->assertNotEmpty($response->json('reassign_to_options'));
        $this->assertNotNull(User::find($agent->getKey()));
    }

    public function test_it_counts_soft_deleted_tickets(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        Ticket::factory()->create(['created_by' => $agent->getKey()])->delete();
        // Without withTrashed() this returns 204 and then MySQL refuses with
        // errno 1451 — a 500 from an endpoint whose tests all passed.
        $this->deleteJson($this->url($agent))->assertUnprocessable()->assertJsonValidationErrors('reassign_to');
    }

    public function test_it_reassigns_authored_and_assigned_tickets(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        $heir = User::factory()->agent()->create();
        $authored = Ticket::factory()->create(['created_by' => $agent->getKey()]);
        $assigned = Ticket::factory()->create(['assigned_to' => $agent->getKey()]);
        $both = Ticket::factory()->create(['created_by' => $agent->getKey(), 'assigned_to' => $agent->getKey()]);
        $this->deleteJson($this->url($agent), ['reassign_to' => $heir->getKey()])->assertNoContent();
        $this->assertNull(User::find($agent->getKey()));
        $this->assertSame($heir->getKey(), $authored->refresh()->created_by);
        $this->assertSame($heir->getKey(), $assigned->refresh()->assigned_to);
        $this->assertSame($heir->getKey(), $both->refresh()->created_by);
        $this->assertSame($heir->getKey(), $both->refresh()->assigned_to);
    }

    public function test_it_records_the_reassignment_on_the_activity_trail(): void
    {
        $admin = $this->actingAsAdmin();
        $agent = User::factory()->agent()->create(['name' => 'Departing Agent']);
        $heir = User::factory()->agent()->create();
        $authored = Ticket::factory()->create(['created_by' => $agent->getKey()]);
        $assigned = Ticket::factory()->create(['assigned_to' => $agent->getKey()]);
        $this->deleteJson($this->url($agent), ['reassign_to' => $heir->getKey()])->assertNoContent();
        $assignedRow = DB::table('ticket_activities')->where('ticket_id', $assigned->getKey())->where('event', 'assigned')->sole();
        $this->assertSame($admin->getKey(), (int) $assignedRow->user_id);
        $this->assertSame('assigned_to', $assignedRow->field);
        $this->assertSame((string) $heir->getKey(), $assignedRow->new_value);
        $meta = json_decode($assignedRow->meta, true);
        $this->assertSame('user_deleted', $meta['reason']);
        $this->assertSame('Departing Agent', $meta['from_name']);
        $authoredRow = DB::table('ticket_activities')->where('ticket_id', $authored->getKey())->where('event', 'updated')->sole();
        $this->assertSame('created_by', $authoredRow->field);
        $this->assertSame((string) $agent->getKey(), $authoredRow->old_value);
    }

    public function test_it_rejects_reassigning_to_an_inactive_user(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        Ticket::factory()->create(['created_by' => $agent->getKey()]);
        $inactive = User::factory()->agent()->inactive()->create();
        $this->deleteJson($this->url($agent), ['reassign_to' => $inactive->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reassign_to.0', 'That user does not exist or is deactivated.');
        $this->assertNotNull(User::find($agent->getKey()));
    }

    public function test_it_rejects_reassigning_to_the_user_being_deleted(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->agent()->create();
        Ticket::factory()->create(['created_by' => $agent->getKey()]);
        $this->deleteJson($this->url($agent), ['reassign_to' => $agent->getKey()])
            ->assertUnprocessable()
            ->assertJsonPath('errors.reassign_to.0', 'Tickets cannot be reassigned to the account being deleted.');
        $this->assertNotNull(User::find($agent->getKey()));
    }

    public function test_an_admin_cannot_delete_themselves(): void
    {
        $admin = $this->actingAsAdmin();
        $this->deleteJson($this->url($admin))->assertForbidden();
        $this->assertNotNull(User::find($admin->getKey()));
    }

    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        $target = User::factory()->admin()->create();
        $actor = $this->actingAsAdmin();
        // The interleaving the guard exists for: the actor's own row stopped
        // being active after their session authenticated. See UserLockoutTest.
        DB::table('users')->where('id', $actor->getKey())->update(['is_active' => false]);
        $this->deleteJson($this->url($target))
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', 'This is the last active administrator. Promote someone else first.');
        $this->assertNotNull(User::find($target->getKey()));
    }

    public function test_a_second_admin_can_be_deleted_when_a_third_remains(): void
    {
        $this->actingAsAdmin();
        User::factory()->admin()->create();
        $target = User::factory()->admin()->create();
        $this->deleteJson($this->url($target))->assertNoContent();
        $this->assertNull(User::find($target->getKey()));
    }

    public function test_an_agent_cannot_delete_anyone(): void
    {
        Sanctum::actingAs(User::factory()->agent()->create());
        $target = User::factory()->agent()->create();
        $before = User::query()->count();
        $this->deleteJson($this->url($target))->assertForbidden()->assertJsonPath('message', 'This action is unauthorized.');
        $this->assertSame($before, User::query()->count());
    }

    public function test_it_returns_404_for_an_unknown_user(): void
    {
        $this->actingAsAdmin();
        $this->deleteJson('/api/v1/admin/users/999999')->assertNotFound();
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function url(User $user): string
    {
        return route('admin.users.destroy', $user, absolute: false);
    }
}
