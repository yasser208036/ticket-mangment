# Story 37 — Flag stale tickets on a schedule (Story: TM-43)

## Prerequisites

- **The env var already exists, and it fixes the command name and the unit.** `backend/.env.example` ends with:
  ```
  # A ticket with no activity for this many hours is flagged stale by
  # `php artisan tickets:flag-stale` (TM-43 — command not implemented yet).
  TICKETS_STALE_AFTER_HOURS=48
  ```
  **Nothing reads it** — `grep -rn "TICKETS_STALE" backend/app backend/config` returns nothing, and there is no `config/tickets.php`. So the threshold is **hours, not days**, the default is **48**, and the command is **`tickets:flag-stale`**. **Do not invent `TICKETS_STALE_AFTER_DAYS` or a different signature**; task 1 wires up the variable that is already documented, and task 8 removes the "not implemented yet" clause from that comment.
- **Story 34 (TM-40) — PLANNED, NOT IMPLEMENTED, and it owns the relation task 3 needs.** `backend/app/Models/Ticket.php` has **seven `BelongsTo` relations and no `HasMany`** (**23–56**); `activities()` is added by Story 34's task 4. **If it is absent when you start, add it verbatim from that plan** and record it in the PR so Story 34 does not add a second. See [`34-story-reopen-a-closed-ticket-TM-40.md`](34-story-reopen-a-closed-ticket-TM-40.md).
- **Story 29 (TM-34) — PLANNED, NOT IMPLEMENTED, and it creates `backend/app/Events/`.** That directory does not exist. Task 6's event follows its rules exactly: *"one event with scalar ids and no marker interfaces — no `ShouldQueue`, no `ShouldBroadcast`, no `SerializesModels`"* ([`../assignment-workload/00-overview.md`](../assignment-workload/00-overview.md)). **If Story 29 has not landed, this story creates the directory.**
- **Stories 31–36 (TM-37…TM-42) are irrelevant to this one.** This command reads `statuses.is_terminal` and `tickets.updated_at` and writes one activity row. It touches no workflow, no escalation and no frontend file. **It can ship independently of every other story in this epic.**
- **Measured today against `tm-mysql-test` (3307), 60 tickets with `updated_at` spread from 3 to 180 hours old, a fifth Closed and a seventh Resolved, five already carrying a `stale` row.** The exact query in task 3 matched **30** tickets — arithmetically correct — and `EXPLAIN` gave:
  | Table | type | key | rows | Extra |
  |---|---|---|---|---|
  | `statuses` | ALL | — | 7 | Using where; Using temporary; Using filesort |
  | `tickets` | **ref** | **`tickets_status_id_index`** | 1 | Using where |
  | `ticket_activities` | **ref** | **`ticket_activities_timeline_index`** | 1 | Using where; **Not exists** |
  **The existing indexes already serve this query and this story adds none.** The optimizer drives from the seven-row `statuses` table, uses TM-21's `status_id` index for the join and TM-45's composite `(ticket_id, created_at)` index for the `NOT EXISTS` — which is exactly what that composite was promised for.
- **A counter-intuitive second measurement, recorded so nobody "optimises" it later.** Adding `index(updated_at)` — the index **Story 20 (TM-24) will add for its `sort=updated_at`** — made this plan **worse**: the optimizer switched `tickets` to `type=ALL, rows=60` with a hash join. **60 rows is not a representative sample** and the effect may invert at scale, so this is recorded as an observation, not a rule. **Do not add, drop or reorder any index for this story**, and if the command becomes slow in production, re-measure against production-shaped data before touching anything.
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No new composer or npm dependency, no migration, no frontend file, no endpoint.**
- **Baseline, 2026-08-26:** `composer test` → **101 tests, 98 passing, 3 failing**; `pint --test` exits `0`. Re-measure whatever is merged when you start.

---

## Story Goal

A nightly command finds the tickets nobody has touched, writes an unattributed line into each one's history saying so, and refuses to say it twice.

1. `php artisan tickets:flag-stale` selects **non-terminal** tickets whose `updated_at` is older than `config('tickets.stale_after_hours')`.
2. Each one gets a `stale` activity row with **`user_id` null** — the system, not a person.
3. The threshold and whether the run notifies both come from config, backed by `TICKETS_STALE_AFTER_HOURS` and a new `TICKETS_STALE_NOTIFY`.
4. It is scheduled daily, is **idempotent**, and will not flag a ticket that already carries a `stale` row newer than its own `updated_at`.
5. `--dry-run` prints exactly what it would flag and writes nothing.

**Not in scope, and each belongs to a named story — or to nobody, which is said out loud below.** **No email, no mailable, no notification class.** `stale_notify` gates an **event dispatch**, and E8 owns every line of mail. **No escalation** — a stale ticket is flagged, not escalated; TM-41's endpoint is untouched and nothing here calls it. **No `stale` filter, badge, list column or dashboard count** — TM-42 shipped that vocabulary for *escalation* and nothing in the backlog asks for the same for staleness. **No un-stale event, no "acknowledge" action, no reassignment.** **No new index and no migration** — measured above. **No frontend file at all.** **No `--hours` override** — AC3 says the threshold is configuration driven, and a CLI override would make "what will tonight's run do?" ambiguous.

---

## Decision — "last activity" means `tickets.updated_at`

Not "the newest `ticket_activities` row".

- **TM-47 (E7-S3, internal notes) requires it.** Its fourth criterion is *"Adding a note does not change the ticket status or its `updated_at` semantics for staleness"* — a note must not reset the staleness clock. Defining staleness from the activity trail would make every note reset it, which is precisely what that criterion forbids.
- **`updated_at` is bumped by every real change to the ticket** — status, assignment, priority, category, escalation, edit — because all of them go through `$ticket->save()`. That is a defensible reading of "activity": the record changed.
- **It is one indexed column on the row already being selected**, rather than a `MAX()` over a second table per ticket.

**The tension, recorded rather than hidden:** an agent who spends a week investigating and writing notes without changing a field will see the ticket flagged. **If the team decides notes should count, the change is one `orWhereExists` in task 3's query and TM-47 is where that conversation belongs** — not a quiet redefinition here.

## Decision — idempotency is a query, not a column

"Will not re-flag a ticket already flagged" is enforced by `whereDoesntHave('activities', …)` on a `stale` row whose `created_at >= tickets.updated_at`. **No `stale_flagged_at` column and no migration.**

- **It gets the semantics right for free.** A ticket flagged on Monday, worked on Tuesday (bumping `updated_at`), then abandoned again *should* be flagged a second time — and it is, because the old `stale` row is now older than `updated_at`. A boolean column would have to be cleared by every write path in the app.
- **Writing the `stale` row does not bump `tickets.updated_at`** — it is a different table — so the ticket stays *eligible* while the `NOT EXISTS` blocks it. That asymmetry is what makes the rule stable.
- **It is measured to use `ticket_activities_timeline_index`** (`type=ref`, `Not exists`), the composite TM-45's fourth criterion added for timeline reads.

## Decision — `notify` dispatches one event per run, and **no story owns the listener**

`stale_notify` is config-driven per AC3. What it drives is a single `TicketsFlaggedStale` event carrying **all** the flagged ids and the threshold, dispatched **once**, **after** the last chunk commits.

- **One event for the run, not one per ticket.** A night that flags forty tickets should produce one digest, not forty messages, and forty events would make that decision for E8.
- **Dispatched after the transactions commit**, which is E8-S7's fourth criterion — *"Notifications dispatch after the database transaction commits, so no email references uncommitted data."*
- **Scalar ids, no marker interfaces**, following Story 29's rule for `app/Events/`.

**The gap, stated plainly: no story in E8 owns a stale-ticket notification.** E8-S1 through S7 cover queue infrastructure, assignment, creation confirmation, requester status changes, **escalation**, the shared layout and the queueing rules — **none of them mentions staleness.** So this event has no listener anywhere in the backlog. Consequences:

- **`TICKETS_STALE_NOTIFY` defaults to `false`**, because with no listener a `true` does nothing observable and a default of `true` would advertise a feature that does not exist.
- **Either E8 gains a story for it, or the flag stays off.** **Put that sentence in the PR description**; it is a backlog gap this story found, not one it should fill by writing an unqueued mailable that E8-S7 would then delete.

## Decision — `withoutOverlapping()`, daily at 02:00, and `onOneServer()` deferred

`Schedule::command('tickets:flag-stale')->dailyAt('02:00')->withoutOverlapping()`.

- **02:00** is after the working day in any single timezone the app runs in, and the flag is not time-critical to the minute.
- **`withoutOverlapping()`** guards a run that outlives its window on a large queue. It needs a cache lock; `.env.example` already sets `CACHE_STORE=database` and the `cache` table is migrated (`0001_01_01_000001_create_cache_table.php`), so it works out of the box.
- **`onOneServer()` is deliberately not added.** It requires a cache store shared between application servers, and this project has one host and no deployment topology decided — `docs/deployment-runbook.md` still reads *"staging: TBD, production: TBD"*. **Task 7 records it as a runbook decision for TM-63**, which is the story that fills that file in. Adding it now against a per-host cache would silently do nothing.
- **The command is idempotent anyway**, so a double run is harmless — which is why this is a note rather than a blocker.

---

## Context — Read These Files First

1. `backend/.env.example` — **the last three lines.** The variable, the unit, the command name and the comment task 8 edits.
2. `backend/config/seeding.php` — **9 lines, the only bespoke config file in the project.** A flat `return [...]` of `env()` calls with no nesting beyond one level. Task 1 matches it. Note `ADMIN_PASSWORD` has **no fallback** deliberately; this story's two values both do.
3. `backend/routes/console.php` — **7 lines**, holding only the stock `inspire` closure. **This is where the scheduler entry goes** in the slim skeleton; there is no `app/Console/Kernel.php`. Task 5 adds the first `Schedule::` call in the project.
4. `backend/app/Services/ActivityRecorder.php` — **`recordMany()` at 21–39** is what task 4 calls, not `record()`. It **requires all five attribute keys** and writes the literal string `"null"` into `meta` if one is omitted (**30–35**); it chunks inserts at **500** (**36**); and it throws `LogicException` outside a transaction (**26–28**). **`user_id => null` is AC2**, and `recordMany` supports it as long as the key is present.
5. `backend/app/Enums/TicketActivityEvent.php` — `Created`, `CategoryChanged` today, plus whatever Stories 32, 34 and 35 have appended. **Append only.**
6. `backend/app/Models/Ticket.php` — `SoftDeletes` at **16**, `status()` at **38–41**. **No `activities()` relation** — Story 34 adds it; see the prerequisites.
7. `backend/app/Models/Status.php:15–18` — `is_terminal` cast to `boolean`. Task 3 filters on it and **not** on `bucket`, following the rule E5's overview fixes product-wide.
8. `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php` — `user_id` **nullable** with `nullOnDelete()` (**14**), `event` `varchar(50)` (**15**), `meta` nullable `json` (**19**), `created_at` `useCurrent()` (**20**), and the composite index at **21**. **The whole of AC2 is already possible with this schema; nothing needs adding.**
9. `docs/deployment-runbook.md` — **24 lines, still a placeholder** with `_TBD_` environments. **TM-63 (E9-S5) owns filling it in.** Task 7 adds one section without touching the placeholders around it.
10. `backend/tests/Feature/Database/AdminUserSeederTest.php` — the closest precedent for testing a non-HTTP code path with `RefreshDatabase`. Task 9's tests follow its shape rather than inventing a console-test style.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `TICKETS_STALE_AFTER_HOURS` | Documented in `.env.example`, **read by nothing** | Read via `config('tickets.stale_after_hours')` |
| A non-terminal ticket idle 49h (threshold 48) | — | Flagged: one `stale` row, `user_id` **null** |
| A **Resolved** or **Closed** ticket, however old | — | **Never flagged** |
| A soft-deleted ticket | — | **Never flagged** |
| A ticket flagged last night, untouched since | — | **Not re-flagged** |
| A ticket flagged, then worked on, then idle again | — | **Flagged again** — the old row is older than `updated_at` |
| A ticket idle 47h | — | Not flagged |
| `--dry-run` | — | Prints the table and the count, **writes nothing, dispatches nothing** |
| Nothing to flag | — | Prints "No stale tickets." and dispatches **no** event |
| `TICKETS_STALE_NOTIFY=false` (default) | — | Rows written, **no event** |
| `TICKETS_STALE_NOTIFY=true` | — | One `TicketsFlaggedStale` event for the whole run — **and no listener exists** |
| Threshold `0` or negative | — | The command **fails** with a message and writes nothing |

---

## Backend Tasks

### 1 — The config file

**Create file: `backend/config/tickets.php`**

```php
<?php

return [
    // A ticket with no change for this many hours is flagged stale by
    // `php artisan tickets:flag-stale`. "No change" means tickets.updated_at:
    // an internal note deliberately does not reset it (TM-47).
    'stale_after_hours' => (int) env('TICKETS_STALE_AFTER_HOURS', 48),

    // Whether a run dispatches TicketsFlaggedStale. Off by default because no
    // listener exists yet -- no story in E8 owns a stale-ticket digest.
    'stale_notify' => (bool) env('TICKETS_STALE_NOTIFY', false),
];
```

**Both casts are load-bearing.** `env()` returns strings from a real `.env` file, and `(bool) "false"` is `true` — so the `stale_notify` cast has to happen where the value is read from a **boolean-aware** source. Laravel's `env()` already converts the literal strings `true`/`false`, which is why `(bool)` here is a belt-and-braces cast on an already-boolean value; **the string trap is why `env()` must never be called outside a config file.** Test 15 pins both types.

Follow `config/seeding.php`'s flat shape. **Do not nest** these under a `stale` key — two values do not need a level.

### 2 — The activity event

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append:

```php
    case Stale = 'stale';
```

`ticket_activities.event` is `varchar(50)`, so **no migration**. Append after whatever Stories 32, 34 and 35 have added; **do not reorder.**

### 3 — The query

**Create file: `backend/app/Console/Commands/FlagStaleTickets.php`**

Commands under `app/Console/Commands` are auto-registered in the slim skeleton; **there is no kernel to edit.**

```php
<?php

namespace App\Console\Commands;

use App\Enums\TicketActivityEvent;
use App\Events\TicketsFlaggedStale;
use App\Models\Ticket;
use App\Services\ActivityRecorder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
    private function staleQuery(\Illuminate\Support\Carbon $cutoff): Builder
    {
        return Ticket::query()
            ->with('status')
            // "Open" is is_terminal = false, never bucket = 'open' -- the rule
            // E5's overview fixes for the whole product.
            ->whereRelation('status', 'is_terminal', false)
            ->where('updated_at', '<', $cutoff)
            // AC4. Not a column: a ticket flagged, then worked on, then idle
            // again SHOULD be flagged a second time, and this expresses that
            // without a flag anyone has to remember to clear. Measured to use
            // ticket_activities_timeline_index (type=ref, Not exists).
            ->whereDoesntHave('activities', fn (Builder $query) => $query
                ->where('event', TicketActivityEvent::Stale)
                ->whereColumn('ticket_activities.created_at', '>=', 'tickets.updated_at'));
    }
}
```

Six things not to re-derive:

- **`chunkById`, not `chunk`.** `chunkById` pages on `id >`, so rows dropping out of the result set as they are flagged cannot shift a page boundary and skip a ticket. `chunk` with an offset would.
- **`whereRelation('status', 'is_terminal', false)`**, not a manual join. Measured: the optimizer drives from `statuses` (7 rows) and reaches `tickets` through `tickets_status_id_index`.
- **`->with('status')`** because `--dry-run`'s table prints the status name. It costs one query per chunk and nothing in the non-dry path.
- **`whereColumn('ticket_activities.created_at', '>=', 'tickets.updated_at')` — both sides fully qualified.** `tickets` and `ticket_activities` both have `created_at`; an unqualified column here is MySQL error 1052, the same trap Story 20 documents for its priority join.
- **The threshold guard returns `FAILURE` and writes nothing.** A misconfigured `TICKETS_STALE_AFTER_HOURS=0` would otherwise flag the entire open queue on the first scheduled run.
- **`Ticket::query()`, never `DB::table('tickets')`** — the raw builder loses the soft-delete scope, measured by Story 25 as counting trashed rows. Test 6 pins it.

### 4 — The report

Add to `FlagStaleTickets`:

```php
    /** @param list<int> $flagged */
    private function report(array $flagged, int $hours, bool $dryRun): int
    {
        if ($flagged === []) {
            $this->info("No stale tickets. Nothing has been idle for {$hours} hours.");

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['Reference', 'Subject', 'Status', 'Hours idle'],
                Ticket::query()->with('status')->whereKey($flagged)->orderBy('id')->get()
                    ->map(fn (Ticket $ticket): array => [
                        $ticket->reference,
                        \Illuminate\Support\Str::limit($ticket->subject, 60),
                        $ticket->status->name,
                        (int) $ticket->updated_at->diffInHours(now()),
                    ])->all(),
            );
            $this->info('Dry run: would flag '.count($flagged).' ticket(s). Nothing was written.');

            return self::SUCCESS;
        }

        $this->info('Flagged '.count($flagged).' ticket(s) as stale.');

        if (config('tickets.stale_notify')) {
            // After the chunk transactions have committed -- E8-S7's rule that
            // notifications never reference uncommitted data. One event for the
            // whole run, so a listener can send one digest rather than N mails.
            // NOTE: no listener exists. No E8 story owns a stale digest.
            TicketsFlaggedStale::dispatch($flagged, $hours);
        }

        return self::SUCCESS;
    }
```

- **`--dry-run` re-queries by key rather than holding models across chunks**, so a run over a large queue does not hold every matched model in memory — only its ids.
- **No event on a dry run and none on an empty run.** A digest of nothing is noise, and a dry run that dispatched would violate *"changes nothing"* in spirit if not in the database.
- **`Str::limit($subject, 60)`** so a long subject does not wrap the console table into unreadability.

### 5 — The event

**Create file: `backend/app/Events/TicketsFlaggedStale.php`**

If `backend/app/Events/` does not exist, this story creates it. Follow Story 29's rules exactly.

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * One event per run, carrying scalar ids. No ShouldQueue, no ShouldBroadcast,
 * no SerializesModels -- adding any of them changes the event's behaviour the
 * moment TM-51 starts a worker.
 *
 * There is no listener. No story in E8 owns a stale-ticket digest; until one
 * exists, TICKETS_STALE_NOTIFY stays false.
 */
class TicketsFlaggedStale
{
    use Dispatchable;

    /** @param list<int> $ticketIds */
    public function __construct(public readonly array $ticketIds, public readonly int $thresholdHours) {}
}
```

### 6 — The schedule

**File: `backend/routes/console.php`**

Add below the `inspire` closure, with `use Illuminate\Support\Facades\Schedule;`:

```php
// Idempotent by construction (see FlagStaleTickets), so a missed or doubled run
// is harmless. withoutOverlapping guards a run that outlives its window on a
// large queue; it uses the cache lock, and CACHE_STORE=database is already set.
// onOneServer() is deliberately absent -- it needs a cache shared between app
// servers and this project has no deployment topology decided yet. See
// docs/deployment-runbook.md.
Schedule::command('tickets:flag-stale')->dailyAt('02:00')->withoutOverlapping();
```

**This is the project's first `Schedule::` call.** The scheduler itself only runs when the host cron invokes `php artisan schedule:run` every minute — **which is task 7's job to document, and without it this entry does nothing.**

### 7 — The runbook and the environment file

**File: `docs/deployment-runbook.md`**

Add a section after `## Post-deploy checks` (**ends at 24**). **Leave the `_TBD_` placeholders alone** — TM-63 fills them.

```markdown
## Scheduled tasks

The application scheduler runs one command. It is inert unless the host invokes
Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

| Command | When | Purpose |
|---|---|---|
| `tickets:flag-stale` | daily, 02:00 | Writes a `stale` activity row on every non-terminal ticket idle longer than `TICKETS_STALE_AFTER_HOURS`. |

`tickets:flag-stale` is idempotent — a missed run costs nothing and a doubled run
writes nothing twice. Run it with `--dry-run` to see what it would flag.

**`onOneServer()` is not set.** If the app is ever deployed to more than one
host, add it to the schedule entry **and** point `CACHE_STORE` at a store shared
between them, or every host will run the command. Both are decisions for this
runbook, not for the command.
```

**File: `backend/.env.example`**

Rewrite the trailing block:

```
# A ticket with no change for this many hours is flagged stale by the scheduled
# `php artisan tickets:flag-stale`. "No change" is tickets.updated_at; an
# internal note deliberately does not reset it.
TICKETS_STALE_AFTER_HOURS=48

# Whether a stale run dispatches TicketsFlaggedStale. Off because no listener
# exists yet — no story in E8 owns a stale-ticket digest.
TICKETS_STALE_NOTIFY=false
```

**Remove "(TM-43 — command not implemented yet)"** — it is implemented as of this story.

---

## Edge Cases & Failure Modes

- **A Resolved or Closed ticket idle for a year** → never flagged. `whereRelation('status', 'is_terminal', false)`. Test 3.
- **A Pending ticket idle past the threshold** → **flagged.** `pending` is `is_terminal = false` (`StatusSeeder.php:16`), and a ticket parked on a customer for two months is exactly what AC's *"nothing is quietly forgotten"* means. **Not a bug** — recorded because "waiting on the customer" reads like an excuse from staleness and is deliberately not one.
- **A soft-deleted ticket** → never flagged; `Ticket::query()` carries the `deleted_at is null` scope. **Test 6 is what catches a rewrite to `DB::table('tickets')`**, the trap Story 25 measured.
- **A ticket exactly at the threshold** → `updated_at < $cutoff` is strict, so a ticket idle *exactly* 48 hours is **not** flagged; at 48 hours and one second it is. Test 4 asserts both sides of the boundary.
- **A ticket flagged last night, untouched** → not re-flagged: its `stale` row is newer than `updated_at`. Test 7.
- **A ticket flagged, then worked on, then idle again** → **flagged again**, because the bump to `updated_at` makes the old row older than it. Test 8. **This is the behaviour a boolean column would get wrong.**
- **Two `stale` rows on one ticket** → legitimate, and the trail reads correctly: two separate periods of neglect. Nothing dedupes across runs beyond the `updated_at` rule.
- **`TICKETS_STALE_AFTER_HOURS=0` or negative or non-numeric** → `(int)` makes a non-numeric string `0`, the guard catches `< 1`, the command prints the offending value and returns `FAILURE`. **Nothing is written.** Test 13. Without this a typo flags the entire open queue on the first scheduled run.
- **`TICKETS_STALE_NOTIFY` as the string `"false"` in a real `.env`** → Laravel's `env()` converts the literal `false` to a boolean before `config()` sees it. **The trap is calling `env()` outside a config file**, where no such conversion is guaranteed — which is why both values are read only through `config()`. Test 15 asserts the runtime types.
- **`--dry-run`** → no activity row, no event, no `updated_at` change anywhere, exit `0`. Test 9 asserts the row count before and after are equal.
- **`--dry-run` with nothing to flag** → the "No stale tickets." line, not an empty table.
- **A run with nothing to flag and `stale_notify=true`** → **no event.** Test 12.
- **A run flagging more than 500 tickets** → multiple chunks, one transaction each, one insert per 500 rows inside `recordMany` (`ActivityRecorder.php:36`), and **one** event at the end carrying every id. Test 10 uses 501 fixtures on purpose.
- **A failure part-way through a large run** → earlier chunks are committed, later ones are not, and **re-running finishes the job** because the committed tickets now fail the `NOT EXISTS`. **That is the whole reason for per-chunk transactions.** Test 11 forces it by throwing from a bound `ActivityRecorder` on the second chunk.
- **`recordMany` called outside a transaction** → `LogicException` (`ActivityRecorder.php:26–28`). The `DB::transaction` wrapper is not optional; test 11 covers the path that proves it.
- **Two scheduled runs overlapping** → `withoutOverlapping()` skips the second. Even without it the result is correct, since the second run's query excludes what the first committed.
- **The scheduler entry with no host cron** → the command never runs and **nothing reports that**. This is the single most likely production failure of this story, and the only mitigation is task 7's runbook section. **Say so in the PR.**
- **A stale ticket that is also escalated** → both are true and independent. Nothing here reads or writes `escalation_level`, and a stale flag does not escalate. Test 14 asserts the escalation columns are untouched.
- **The `activities()` relation missing** (Story 34 not landed) → `whereDoesntHave('activities', …)` throws `BadMethodCallException` at runtime, not at compile time. **Check `grep -n "function activities" backend/app/Models/Ticket.php` before you start.**

---

## Test Plan

### Backend — `backend/tests/Feature/Console/FlagStaleTicketsTest.php` (new; `RefreshDatabase` + `$this->seed()`)

`tests/Feature/Console/` does not exist; this story creates it. Fixtures use Story 19's `TicketFactory` if it exists, otherwise `Model::create` with the seeded master data — **there is still no `TicketFactory`** (TM-59). Set `updated_at` explicitly with `Ticket::withoutTimestamps(fn () => …)` or a direct `DB::table('tickets')->update(['updated_at' => …])`, because `save()` overwrites it. Drive the command with `$this->artisan('tickets:flag-stale')`.

1. `test_it_flags_a_stale_non_terminal_ticket` — **AC1.** One `stale` row on a ticket idle 49h with a 48h threshold; the command exits `0`.
2. `test_the_row_is_attributed_to_no_user` — **AC2.** `user_id` is **null**, `event` is `stale`, `field` / `old_value` / `new_value` are null, and `meta.threshold_hours` is `48`. **Assert `user_id` is literally null, not the string `"null"`** — `ActivityRecorder::recordMany` writes that string when a key is omitted.
3. `test_terminal_tickets_are_never_flagged` — a Resolved and a Closed ticket, both idle 500h → no rows. **AC1's "non-terminal".**
4. `test_the_threshold_boundary_is_strict` — one ticket at exactly 48h and one at 48h + 1min → **only the second** is flagged.
5. `test_a_fresh_ticket_is_not_flagged` — idle 1h → nothing.
6. `test_soft_deleted_tickets_are_never_flagged` — soft-delete a ticket idle 500h → no row. **The `DB::table()` guard.**
7. `test_it_does_not_re_flag_an_already_flagged_ticket` — **AC4.** Run twice; exactly **one** row, and the second run reports zero.
8. `test_a_ticket_touched_after_flagging_is_flagged_again` — flag, bump `updated_at` to 49h ago (newer than the `stale` row is not enough — set the row's `created_at` to before it), run again → **two** rows. **The test that a boolean column would fail.**
9. `test_dry_run_changes_nothing` — **AC5.** Capture `ticket_activities` count before and after `--dry-run`; equal. Assert the output contains the reference and `'would flag 1 ticket(s)'`, and that **no event was dispatched** (`Event::fake()`).
10. `test_it_chunks_beyond_five_hundred` — 501 stale tickets → 501 rows, and **one** `TicketsFlaggedStale` carrying 501 ids. Slow but the only proof the chunk boundary is right; mark it as such in a comment.
11. `test_a_failure_part_way_leaves_earlier_chunks_committed` — bind an `ActivityRecorder` that throws on its second call, with 501 fixtures. Assert the exception surfaces, **500 rows exist**, and a second (clean) run flags exactly the remaining one. **This is the test that justifies per-chunk transactions**; make the whole run one transaction and confirm it fails.
12. `test_it_dispatches_one_event_only_when_notify_is_on_and_something_was_flagged` — `Event::fake()`, three cases: notify off → none; notify on with matches → **exactly one** with the right ids and `thresholdHours`; notify on with **no** matches → none.
13. `test_a_non_positive_threshold_fails_and_writes_nothing` — `config(['tickets.stale_after_hours' => 0])` → exit code **1**, no rows, and the message names the env var. Repeat with `-5`.
14. `test_flagging_touches_no_ticket_column` — capture `updated_at`, `status_id`, `escalation_level`, `escalated_at` before and after. **All unchanged** — including `updated_at`, which is what keeps the idempotency rule stable.
15. `test_the_config_values_have_the_right_types` — `is_int(config('tickets.stale_after_hours'))` and `is_bool(config('tickets.stale_notify'))`.

### Backend — `backend/tests/Feature/Console/ScheduleTest.php` (new)

16. `test_the_command_is_scheduled_daily` — resolve `Illuminate\Console\Scheduling\Schedule`, find the event whose `command` contains `tickets:flag-stale`, and assert its `expression` is `0 2 * * *`. **AC4's "registered in the scheduler"**, asserted rather than eyeballed — a scheduler entry is otherwise the easiest thing in the codebase to delete without a test noticing.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
2. **The relation exists:** `grep -n "function activities" backend/app/Models/Ticket.php`. **If it is absent, Story 34 has not landed** — add it verbatim from that plan first and note it in the PR.
3. **Backend formats:** `./vendor/bin/pint --test` → exit `0`.
4. **Backend tests:** `composer test`. Expect **+16 tests** and **no new failures**.
5. **Prove per-chunk transactions earn their place:** wrap the whole `chunkById` loop in a single `DB::transaction`, re-run `--filter=test_a_failure_part_way_leaves_earlier_chunks_committed`, confirm it **fails**, restore.
6. **Prove the idempotency clause earns its place:** delete the `whereDoesntHave` block, re-run `--filter=test_it_does_not_re_flag_an_already_flagged_ticket`, confirm it **fails** with two rows, restore.
7. **Prove the soft-delete scope earns its place:** change `Ticket::query()` to `DB::table('tickets')` (and adapt the relation clauses), re-run `--filter=test_soft_deleted_tickets_are_never_flagged`, confirm it **fails**, restore.
8. **By hand,** against the dev database:
   - `php artisan tickets:flag-stale --dry-run` on a fresh database → **"No stale tickets."**
   - Age a few open tickets: `UPDATE tickets SET updated_at = NOW() - INTERVAL 5 DAY WHERE id IN (…);`
   - `php artisan tickets:flag-stale --dry-run` → a table of those references with their hours idle, and *"would flag N ticket(s). Nothing was written."* Then `SELECT count(*) FROM ticket_activities WHERE event='stale';` → **0**.
   - `php artisan tickets:flag-stale` → *"Flagged N ticket(s)"*; the same count query → **N**; `SELECT user_id, meta FROM ticket_activities WHERE event='stale' LIMIT 1;` → `user_id` **NULL**, `meta` carrying `threshold_hours`.
   - Run it again → *"No stale tickets."* and the count is **still N**. **AC4.**
   - `UPDATE tickets SET updated_at = NOW() WHERE id = <one of them>;` then age it again and re-run → that ticket gains a **second** row.
   - Resolve one of the aged tickets and re-run → it is **not** flagged.
   - `TICKETS_STALE_AFTER_HOURS=0 php artisan tickets:flag-stale` → the error, exit code **1**, nothing written. Check with `echo $?`.
9. **Prove the schedule is registered:** `php artisan schedule:list` → one entry, `0 2 * * *`, `tickets:flag-stale`. Then `php artisan schedule:test` and pick it, to run it through the scheduler rather than directly.
10. **Regression:** confirm `git status` shows **no migration**, **no file under `frontend/`**, and no change to `routes/api.php`, `TicketController.php`, `TicketPolicy.php` or any seeder. Then run `php artisan test --filter=TicketReferenceTest` to confirm the `ActivityRecorder` transaction guard still behaves as it did.

---

## Done Criteria

- [ ] `php artisan tickets:flag-stale` exists with the **exact** name and unit `.env.example` already documented — **hours**, `TICKETS_STALE_AFTER_HOURS`, default 48 — and that file no longer says "not implemented yet".
- [ ] It flags **non-terminal** tickets only, excludes soft-deleted ones, and uses `Ticket::query()` rather than `DB::table()` — proven by a test that fails when the builder is swapped.
- [ ] The threshold boundary is strict: exactly at the threshold is **not** flagged.
- [ ] Each flagged ticket receives **one** `stale` activity row with `user_id` **null** — literally null, not `"null"` — and `meta.threshold_hours`.
- [ ] Both the threshold and `stale_notify` are read through `config('tickets.*')`; **`env()` is not called outside `config/tickets.php`**; and the two values are an `int` and a `bool` at runtime.
- [ ] A non-positive or non-numeric threshold **fails with exit code 1 and writes nothing**.
- [ ] The command is **idempotent**: running it twice writes one row, and it re-flags only after the ticket's `updated_at` moves — expressed as a query with **no new column and no migration**, proven by a test that fails when the clause is removed.
- [ ] Flagging changes **no** column on `tickets` — including `updated_at`, which is what keeps the idempotency rule stable.
- [ ] `--dry-run` prints the references, subjects, statuses and hours idle, reports the count, and writes and dispatches **nothing** — asserted by comparing row counts and with `Event::fake()`.
- [ ] Work is chunked at 500 with **one transaction per chunk**, so a failure part-way leaves earlier chunks committed and a re-run finishes the job — proven by a test that fails when the run is made one transaction.
- [ ] `TicketsFlaggedStale` is dispatched **once per run**, only when `stale_notify` is true **and** something was flagged, **after** the chunk transactions commit, carrying scalar ids and no marker interfaces.
- [ ] **The PR records that no story in E8 owns a stale-ticket digest**, that the event therefore has no listener, and that `TICKETS_STALE_NOTIFY` defaults to `false` for that reason.
- [ ] The command is registered in `routes/console.php` at `0 2 * * *` with `withoutOverlapping()`, **asserted by a test** and visible in `php artisan schedule:list`.
- [ ] `docs/deployment-runbook.md` carries the `schedule:run` cron line, the command table, and the `onOneServer()` decision — with its `_TBD_` placeholders untouched for TM-63.
- [ ] **No index was added, dropped or reordered**, and the PR records the measured plan (`statuses` → `tickets_status_id_index` → `ticket_activities_timeline_index`) and the counter-intuitive finding about `index(updated_at)`.
- [ ] "Last activity" is documented as `tickets.updated_at`, with TM-47's criterion cited as the reason and the note-counting alternative named as TM-47's conversation.
- [ ] `pint --test` clean; **+16 backend tests**; no new failures; no migration, no endpoint, no frontend file, no dependency.

**This completes the `status-workflow-escalation` feature (Stories 31–37).** Two things leave it: **TM-47 must not let a note reset `tickets.updated_at`**, or this command's definition of staleness silently changes; and **E8 has no home for the stale digest** — either it gains one or `TICKETS_STALE_NOTIFY` stays off.
