<?php

namespace Tests\Feature\Database;

use App\Enums\UserRole;
use App\Models\Status;
use App\Models\StatusTransition;
use Database\Seeders\StatusTransitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusTransitionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_whole_graph(): void
    {
        $this->seed();
        $this->assertSame(14, StatusTransition::query()->count());

        $bySlug = StatusTransition::with(['fromStatus', 'toStatus'])->get()
            ->map(fn (StatusTransition $edge) => [$edge->fromStatus->slug, $edge->toStatus->slug])
            ->all();
        $expected = array_map(fn (array $edge) => [$edge[0], $edge[1]], StatusTransitionSeeder::EDGES);
        $this->assertEqualsCanonicalizing($expected, $bySlug);
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed();
        $idsBefore = StatusTransition::query()->orderBy('id')->pluck('id');
        $this->seed(StatusTransitionSeeder::class);
        $idsAfter = StatusTransition::query()->orderBy('id')->pluck('id');

        $this->assertSame(14, StatusTransition::query()->count());
        $this->assertTrue($idsBefore->diff($idsAfter)->isEmpty());
    }

    public function test_it_prunes_an_edge_that_is_no_longer_in_the_graph(): void
    {
        $this->seed();
        $ids = Status::query()->pluck('id', 'slug');
        StatusTransition::query()->create(['from_status_id' => $ids['new'], 'to_status_id' => $ids['closed']]);
        $this->assertSame(15, StatusTransition::query()->count());

        $this->seed(StatusTransitionSeeder::class);

        $this->assertSame(14, StatusTransition::query()->count());
        $this->assertDatabaseMissing('status_transitions', ['from_status_id' => $ids['new'], 'to_status_id' => $ids['closed']]);
    }

    public function test_no_status_is_a_dead_end(): void
    {
        $this->seed();
        $slugs = Status::query()->pluck('slug');
        $fromSlugs = StatusTransition::with('fromStatus')->get()->pluck('fromStatus.slug')->unique();

        foreach ($slugs as $slug) {
            $this->assertTrue($fromSlugs->contains($slug), "'{$slug}' has no outgoing edge.");
        }
    }

    public function test_no_self_transition_is_seeded(): void
    {
        $this->seed();
        $selfEdges = StatusTransition::query()->whereColumn('from_status_id', 'to_status_id')->count();
        $this->assertSame(0, $selfEdges);
    }

    public function test_exactly_one_edge_requires_admin(): void
    {
        $this->seed();
        $adminEdges = StatusTransition::with(['fromStatus', 'toStatus'])->where('required_role', UserRole::Admin)->get();

        $this->assertCount(1, $adminEdges);
        $this->assertSame('resolved', $adminEdges->first()->fromStatus->slug);
        $this->assertSame('closed', $adminEdges->first()->toStatus->slug);
    }

    public function test_every_status_except_new_is_reachable(): void
    {
        $this->seed();
        $toSlugs = StatusTransition::with('toStatus')->get()->pluck('toStatus.slug')->unique();

        foreach (Status::query()->pluck('slug') as $slug) {
            if ($slug === 'new') {
                $this->assertFalse($toSlugs->contains('new'), "'new' should not be reachable; it is a creation entry point.");

                continue;
            }
            $this->assertTrue($toSlugs->contains($slug), "'{$slug}' is never a target of any edge.");
        }
    }
}
