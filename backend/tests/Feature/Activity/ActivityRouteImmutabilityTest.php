<?php

namespace Tests\Feature\Activity;

use App\Enums\TicketActivityEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ActivityRouteImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_no_route_mutates_an_activity(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            if (! str_contains($route->uri(), 'activit') && ! str_contains($route->uri(), 'note')) {
                continue;
            }
            $mutating = array_intersect($route->methods(), ['PATCH', 'PUT', 'DELETE']);
            $this->assertEmpty($mutating, "Route {$route->uri()} carries a mutating verb: ".implode(',', $mutating));
        }
    }

    public function test_no_route_is_named_for_an_activity_mutation(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/activit(y|ies)\.(update|destroy|delete|edit)/', $name);
            $this->assertDoesNotMatchRegularExpression('/notes\.(update|destroy|delete)/', $name);
        }
    }

    public function test_every_mutating_verb_against_every_activity_path_is_refused(): void
    {
        $ticket = Ticket::factory()->create();
        DB::transaction(function () use ($ticket): void {
            app(ActivityRecorder::class)->record($ticket->getKey(), TicketActivityEvent::Created, ['meta' => ['reference' => $ticket->reference]]);
        });
        $activityId = DB::table('ticket_activities')->where('ticket_id', $ticket->id)->value('id');
        $before = (array) DB::table('ticket_activities')->where('id', $activityId)->first();
        $countBefore = DB::table('ticket_activities')->count();

        $token = User::factory()->admin()->create()->createToken('t')->plainTextToken;
        $paths = [
            "/api/v1/tickets/{$ticket->id}/activities",
            "/api/v1/tickets/{$ticket->id}/activities/{$activityId}",
            "/api/v1/tickets/{$ticket->id}/notes",
            "/api/v1/tickets/{$ticket->id}/notes/{$activityId}",
            "/api/v1/activities/{$activityId}",
        ];

        foreach ($paths as $path) {
            foreach (['patchJson', 'putJson', 'deleteJson'] as $verb) {
                $status = $this->withToken($token)->$verb($path)->status();
                $this->assertContains($status, [404, 405], "{$verb} {$path} returned {$status}");
            }
        }

        $this->assertSame($before, (array) DB::table('ticket_activities')->where('id', $activityId)->first());
        $this->assertSame($countBefore, DB::table('ticket_activities')->count());
    }

    public function test_the_only_activity_route_is_a_get(): void
    {
        $activityRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1') && str_contains($route->uri(), 'activities'));

        $this->assertCount(1, $activityRoutes);
        $this->assertEqualsCanonicalizing(['GET', 'HEAD'], $activityRoutes->first()->methods());
    }
}
