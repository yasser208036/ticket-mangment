<?php

namespace Tests\Feature\Workflow;

use App\Enums\UserRole;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** @return array<string, array{string, string[]}> */
    public static function agentTransitionsProvider(): array
    {
        return [
            'new' => ['new', ['open', 'pending']],
            'open' => ['open', ['in-progress', 'pending']],
            'in-progress' => ['in-progress', ['pending', 'resolved']],
            'pending' => ['pending', ['open', 'in-progress']],
            'resolved' => ['resolved', ['reopened']],
            'closed' => ['closed', ['reopened']],
            'reopened' => ['reopened', ['in-progress', 'pending', 'resolved']],
        ];
    }

    #[DataProvider('agentTransitionsProvider')]
    public function test_allowed_transitions_match_the_seeded_graph_for_an_agent(string $fromSlug, array $expectedSlugs): void
    {
        $ticket = $this->ticketAt($fromSlug);
        $agent = User::factory()->agent()->create();

        $slugs = app(TicketWorkflow::class)->allowedTransitions($ticket, $agent)->pluck('slug')->all();

        $this->assertSame($expectedSlugs, $slugs);
    }

    public function test_an_admin_sees_the_admin_only_edge(): void
    {
        $ticket = $this->ticketAt('resolved');
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $workflow = app(TicketWorkflow::class);

        $this->assertSame(['closed', 'reopened'], $workflow->allowedTransitions($ticket, $admin)->pluck('slug')->all());
        $this->assertSame(['reopened'], $workflow->allowedTransitions($ticket, $agent)->pluck('slug')->all());
    }

    /** @return array<string, array{string, string}> */
    public static function legalMoveProvider(): array
    {
        return [
            'new -> open' => ['new', 'open'],
            'open -> in-progress' => ['open', 'in-progress'],
            'in-progress -> resolved' => ['in-progress', 'resolved'],
            'pending -> open' => ['pending', 'open'],
            'resolved -> reopened' => ['resolved', 'reopened'],
            'closed -> reopened' => ['closed', 'reopened'],
            'reopened -> in-progress' => ['reopened', 'in-progress'],
        ];
    }

    #[DataProvider('legalMoveProvider')]
    public function test_a_legal_move_is_allowed_from_every_seeded_status(string $fromSlug, string $toSlug): void
    {
        $ticket = $this->ticketAt($fromSlug);
        $target = Status::query()->where('slug', $toSlug)->firstOrFail();
        $admin = User::factory()->admin()->create();

        app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $admin);
        $this->expectNotToPerformAssertions();
    }

    /** @return array<string, array{string, string}> */
    public static function illegalMoveProvider(): array
    {
        return [
            'new -> resolved' => ['new', 'resolved'],
            'open -> closed' => ['open', 'closed'],
            'in-progress -> closed' => ['in-progress', 'closed'],
            'pending -> resolved' => ['pending', 'resolved'],
            'resolved -> open' => ['resolved', 'open'],
            'closed -> open' => ['closed', 'open'],
            'reopened -> closed' => ['reopened', 'closed'],
        ];
    }

    #[DataProvider('illegalMoveProvider')]
    public function test_an_illegal_move_is_rejected_from_every_seeded_status(string $fromSlug, string $toSlug): void
    {
        $ticket = $this->ticketAt($fromSlug);
        $target = Status::query()->where('slug', $toSlug)->firstOrFail();
        $admin = User::factory()->admin()->create();

        $this->expectException(ValidationException::class);
        app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $admin);
    }

    public function test_the_rejection_names_the_attempted_move(): void
    {
        $ticket = $this->ticketAt('resolved');
        $target = Status::query()->where('slug', 'open')->firstOrFail();
        $admin = User::factory()->admin()->create();

        try {
            app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $admin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame('A ticket cannot move from Resolved to Open.', $exception->errors()['status_id'][0]);
        }
    }

    public function test_an_admin_only_edge_is_refused_for_an_agent(): void
    {
        $ticket = $this->ticketAt('resolved');
        $target = Status::query()->where('slug', 'closed')->firstOrFail();
        $agent = User::factory()->agent()->create();

        try {
            app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $agent);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame('Only an administrator can move a ticket from Resolved to Closed.', $exception->errors()['status_id'][0]);
        }
    }

    public function test_an_admin_only_edge_is_allowed_for_an_admin(): void
    {
        $ticket = $this->ticketAt('resolved');
        $target = Status::query()->where('slug', 'closed')->firstOrFail();
        $admin = User::factory()->admin()->create();

        app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $admin);
        $this->expectNotToPerformAssertions();
    }

    public function test_a_required_role_of_agent_does_not_exclude_an_admin(): void
    {
        $ids = Status::query()->pluck('id', 'slug');
        StatusTransition::query()->where('from_status_id', $ids['new'])->where('to_status_id', $ids['open'])->update(['required_role' => UserRole::Agent]);

        $target = Status::query()->where('slug', 'open')->firstOrFail();
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->agent()->create();
        $workflow = app(TicketWorkflow::class);

        $workflow->assertCanTransition($this->ticketAt('new'), $target, $admin);
        $workflow->assertCanTransition($this->ticketAt('new'), $target, $agent);
        $this->assertContains('open', $workflow->allowedTransitions($this->ticketAt('new'), $admin)->pluck('slug')->all());
        $this->assertContains('open', $workflow->allowedTransitions($this->ticketAt('new'), $agent)->pluck('slug')->all());
    }

    public function test_moving_to_the_current_status_is_rejected(): void
    {
        $ticket = $this->ticketAt('open');
        $target = Status::query()->where('slug', 'open')->firstOrFail();
        $admin = User::factory()->admin()->create();

        try {
            app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $admin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame('This ticket is already Open.', $exception->errors()['status_id'][0]);
        }
    }

    public function test_a_status_with_no_outgoing_edges_offers_nothing(): void
    {
        $ids = Status::query()->pluck('id', 'slug');
        StatusTransition::query()->where('from_status_id', $ids['closed'])->where('to_status_id', $ids['reopened'])->delete();

        $ticket = $this->ticketAt('closed');
        $target = Status::query()->where('slug', 'reopened')->firstOrFail();
        $admin = User::factory()->admin()->create();
        $workflow = app(TicketWorkflow::class);

        $this->assertSame([], $workflow->allowedTransitions($ticket, $admin)->pluck('slug')->all());
        $this->expectException(ValidationException::class);
        $workflow->assertCanTransition($ticket, $target, $admin);
    }

    public function test_the_guard_reads_the_table_not_a_hard_coded_graph(): void
    {
        $ids = Status::query()->pluck('id', 'slug');
        $admin = User::factory()->admin()->create();
        $workflow = app(TicketWorkflow::class);

        StatusTransition::query()->where('from_status_id', $ids['new'])->where('to_status_id', $ids['open'])->delete();
        $this->expectException(ValidationException::class);
        $workflow->assertCanTransition($this->ticketAt('new'), Status::query()->where('slug', 'open')->firstOrFail(), $admin);
    }

    public function test_the_guard_allows_a_hand_inserted_edge(): void
    {
        $ids = Status::query()->pluck('id', 'slug');
        StatusTransition::query()->create(['from_status_id' => $ids['new'], 'to_status_id' => $ids['closed']]);

        $admin = User::factory()->admin()->create();
        $ticket = $this->ticketAt('new');
        $target = Status::query()->where('slug', 'closed')->firstOrFail();
        $workflow = app(TicketWorkflow::class);

        $workflow->assertCanTransition($ticket, $target, $admin);
        $this->assertContains('closed', $workflow->allowedTransitions($this->ticketAt('new'), $admin)->pluck('slug')->all());
    }

    public function test_the_rejection_is_a_422_under_status_id(): void
    {
        $ticket = $this->ticketAt('open');
        $target = Status::query()->where('slug', 'open')->firstOrFail();
        $admin = User::factory()->admin()->create();

        try {
            app(TicketWorkflow::class)->assertCanTransition($ticket, $target, $admin);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame(['status_id'], array_keys($exception->errors()));
        }
    }

    public function test_allowed_transitions_costs_two_queries(): void
    {
        $ticket = $this->ticketAt('new')->load('status');
        $admin = User::factory()->admin()->create();

        DB::enableQueryLog();
        app(TicketWorkflow::class)->allowedTransitions($ticket, $admin);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(2, $count);
    }

    private function ticketAt(string $slug): Ticket
    {
        return Ticket::factory()->create(['status_id' => Status::query()->where('slug', $slug)->firstOrFail()->getKey()]);
    }
}
