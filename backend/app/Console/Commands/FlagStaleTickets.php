<?php

namespace App\Console\Commands;

use App\Enums\TicketActivityEvent;
use App\Events\TicketsFlaggedStale;
use App\Models\Ticket;
use App\Services\ActivityRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlagStaleTickets extends Command
{
    protected $signature = 'tickets:flag-stale {--dry-run : Report what would be flagged and change nothing}';

    protected $description = 'Flag non-terminal tickets with no change for longer than the configured threshold';

    public function handle(ActivityRecorder $recorder): int
    {
        $hours = (int) config('tickets.stale_after_hours');

        if ($hours < 1) {
            $this->error("tickets.stale_after_hours must be at least 1; got {$hours}. Check TICKETS_STALE_AFTER_HOURS.");

            return self::FAILURE;
        }

        $cutoff = now()->subHours($hours);
        $dryRun = (bool) $this->option('dry-run');
        $flagged = [];

        $this->staleQuery($cutoff)->chunkById(500, function ($tickets) use (&$flagged, $dryRun, $recorder, $hours): void {
            if ($dryRun) {
                $flagged = [...$flagged, ...$tickets->modelKeys()];

                return;
            }

            // One transaction per chunk, not one for the whole run: a long run
            // must not hold a transaction open across it, and a partial run is
            // safe precisely because the command is idempotent -- re-running
            // finishes the job.
            DB::transaction(function () use ($tickets, $recorder, $hours): void {
                $recorder->recordMany($tickets->modelKeys(), TicketActivityEvent::Stale, [
                    'user_id' => null, 'field' => null, 'old_value' => null, 'new_value' => null,
                    // Uniform across the chunk, because recordMany writes one
                    // meta to every row. Per-ticket idle age is derivable from
                    // tickets.updated_at, so nothing is lost.
                    'meta' => ['threshold_hours' => $hours],
                ]);
            });

            $flagged = [...$flagged, ...$tickets->modelKeys()];
        });

        return $this->report($flagged, $hours, $dryRun);
    }

    /** @return Builder<Ticket> */
    private function staleQuery(Carbon $cutoff): Builder
    {
        return Ticket::query()
            ->with('status')
            // "Open" is is_terminal = false, never bucket = 'open'.
            ->whereRelation('status', 'is_terminal', false)
            ->where('updated_at', '<', $cutoff)
            // Not a column: a ticket flagged, then worked on, then idle again
            // SHOULD be flagged a second time, and this expresses that
            // without a flag anyone has to remember to clear.
            ->whereDoesntHave('activities', fn (Builder $query) => $query
                ->where('event', TicketActivityEvent::Stale)
                ->whereColumn('ticket_activities.created_at', '>=', 'tickets.updated_at'));
    }

    /** @param list<int> $flagged */
    private function report(array $flagged, int $hours, bool $dryRun): int
    {
        if ($flagged === []) {
            $this->info("No stale tickets. Nothing has been idle for {$hours} hours.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->printDryRunTable($flagged);
            $this->info('Dry run: would flag '.count($flagged).' ticket(s). Nothing was written.');

            return self::SUCCESS;
        }

        $this->info('Flagged '.count($flagged).' ticket(s) as stale.');

        if (config('tickets.stale_notify')) {
            // After the chunk transactions have committed, so no listener can
            // ever reference uncommitted data. One event for the whole run,
            // so a future listener can send one digest rather than N mails.
            // NOTE: no listener exists yet.
            TicketsFlaggedStale::dispatch($flagged, $hours);
        }

        return self::SUCCESS;
    }

    /** @param list<int> $flagged */
    private function printDryRunTable(array $flagged): void
    {
        $this->table(
            ['Reference', 'Subject', 'Status', 'Hours idle'],
            Ticket::query()->with('status')->whereKey($flagged)->orderBy('id')->get()
                ->map(fn (Ticket $ticket): array => [
                    $ticket->reference,
                    Str::limit($ticket->subject, 60),
                    $ticket->status->name,
                    (int) $ticket->updated_at->diffInHours(now()),
                ])->all(),
        );
    }
}
