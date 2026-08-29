# Story 31 — Transition table and workflow guard (Story: TM-37)

## Prerequisites

- **Story 13 (TM-16) — IMPLEMENTED, and it is the contract this story codes against.** Verified on disk: `backend/database/migrations/2026_08_26_073219_create_statuses_table.php` creates `statuses` with `slug`, `bucket`, `is_default`, `is_terminal` and `sort_order`, and `backend/database/seeders/StatusSeeder.php:12–20` seeds **exactly seven** rows — `new`, `open`, `in-progress`, `pending`, `resolved`, `closed`, `reopened`. Its plan already named this story: *"TM-37 builds `status_transitions` on the status ids and reads `is_terminal`"* ([`../categories-priorities-statuses/13-story-master-data-migrations-seeders-TM-16.md`](../categories-priorities-statuses/13-story-master-data-migrations-seeders-TM-16.md), lines 10 and 38). **The seven slugs are the graph's vertex set. Do not add an eighth status here.**
- **Story 17 (TM-21) — IMPLEMENTED.** `tickets.status_id` is a `RESTRICT` foreign key (`backend/database/migrations/2026_08_26_084625_create_tickets_table.php:22`) and `Ticket::status()` exists (`backend/app/Models/Ticket.php:38–41`). `resolved_at`, `closed_at` and `first_responded_at` are already columns (**30, 31, 29**) — **this story writes to none of them**; TM-39 does.
- **Story 22 (TM-26) — landing now.** `TicketPolicy::changeStatus()` already returns `true` for any staff member (`backend/app/Policies/TicketPolicy.php:40–43`) and `TicketResource` already emits `can.change_status` (`backend/app/Http/Resources/V1/TicketResource.php:28`). Its plan states the division of labour this story depends on: *"TM-38 gates the individual transitions through `TicketWorkflow::allowedTransitions`, which does not exist yet. This ability answers 'may you open the status control at all', not 'is this transition legal'"* ([`../ticket-creation-tracking/22-story-ticket-detail-page-TM-26.md`](../ticket-creation-tracking/22-story-ticket-detail-page-TM-26.md), line 39). **Do not touch `TicketPolicy`.**
- **Measured baseline, 2026-08-26, from `backend/`.** `composer test` → **101 tests, 289 assertions, 98 passing, 3 failing**. `./vendor/bin/pint --test` exits `0`. **None of the three failures belongs to this story, and none of them is in a file this story touches:**
  1. `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (`tests/Feature/Auth/PasswordThrottleTest.php:14`) — expects `422`, gets `429`. **TM-14 owns it.**
  2. `Tests\Feature\Authorization\RouteAuthorizationTest::test_every_api_route_is_classified` (`tests/Feature/Authorization/RouteAuthorizationTest.php:18`) — `ACCESS` has no `tickets.store` key. TM-22 added the route (`routes/api.php:44`) and not the classification. **TM-22 owns it.**
  3. `Tests\Feature\Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` (`tests/Feature/Database/TicketReferenceTest.php:38`) — `RefreshDatabase` already holds an open transaction, so `DB::transactionLevel()` is `1` and the guard at `TicketReferenceGenerator.php:12` cannot fire. **TM-21/TM-22 own it.** It is a warning for this story too: see the note in task 5.
  **Re-measure before you start**, and report the same three at the end. **This story adds no route, so it cannot make failure 2 better or worse.**
- **Docker up.** `docker compose ps` → `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all `healthy`; `mysql:8.4` on **3306** and **3307**.
- **No new composer package, no npm package, no route, and no file under `frontend/`.**

---

## Story Goal

The ticket lifecycle stops being folklore and becomes a table. One row per legal move; a service that reads it; nothing else in the app may decide what a legal move is.

1. `status_transitions` stores `from_status_id`, `to_status_id` and a nullable `required_role`, unique on the pair.
2. `StatusTransitionSeeder` seeds **14 edges** over the seven seeded statuses — the AC's `New → Open → In Progress → Resolved → Closed` spine, `Pending` reachable from every active state **and able to leave again**, and `Reopened` reachable from both terminal states.
3. `App\Services\TicketWorkflow::allowedTransitions(Ticket $ticket, User $user)` returns the `Status` models this user may move this ticket to right now, ordered for a dropdown.
4. `App\Services\TicketWorkflow::assertCanTransition(Ticket $ticket, Status $target, User $user)` throws a `422` whose message **names both ends of the attempted move**.
5. A seeded edge carrying `required_role = admin` is refused for agents and allowed for admins — with the refusal reading differently from "that move does not exist".

**Not in scope, and each belongs to a named story.** **No route, no controller method, no form request, and no `TicketController` change** — `POST /api/v1/tickets/{ticket}/status` is **TM-38**'s first criterion, and so is exposing `allowedTransitions` on the detail response and every line of SPA code. **No activity row is written here** — the `status_changed` row is TM-38's third criterion, and `TicketActivityEvent` is **not** modified. **No timestamp is stamped**: `resolved_at`, `closed_at` and `first_responded_at` are TM-39; clearing them on reopen is TM-40. **No resolution note and no reopen reason** (TM-39, TM-40). **No escalation** — `escalation_level`, `priorities.level` and the terminal-ticket `422` are TM-41, which reads `statuses.is_terminal` and not this table. **No admin UI for editing the graph** — the table is edited by seeder or by SQL; nothing in the backlog asks for a screen. **No caching layer.**

---

## Context — Read These Files First

1. `backend/database/seeders/StatusSeeder.php` — **the whole file, 33 lines.** `STATUSES` at **12–20** is the vertex set: read the `slug` of all seven rows, and note `resolved` and `closed` are the only `is_terminal => true` rows (**17–18**). The `run()` body at **22–32** is the shape task 3 copies: resolve inside `DB::transaction`, `updateOrCreate` keyed on the natural key. **Task 3 adds one thing this seeder does not do — pruning — for the reason in the decision section.**
2. `backend/database/migrations/2026_08_26_073219_create_statuses_table.php` — **15–27.** Note `->enum('bucket', StatusBucket::values())` at **19**: a PHP enum supplies the column domain. Task 1 does the same with `UserRole::values()`. Note also the `is_default_unique` virtual-column trick at **22–23** — task 1 needs **no** equivalent; a plain composite unique is enough.
3. `backend/app/Enums/UserRole.php` — 15 lines. `Admin = 'admin'`, `Agent = 'agent'`, and `values()` at **11–14**. This is `required_role`'s domain.
4. `backend/app/Models/Status.php` — **the `#[Fillable]` / `#[Hidden]` attribute idiom at 11–12**, `casts()` at **15–18**, and `scopeOrdered()` at **20–24** (`sort_order` then `name`). Task 5's ordering must agree with that scope so TM-38's dropdown and `GET /api/v1/statuses` list statuses in the same order. **Do not modify this file** — task 5 needs no relation on `Status`.
5. `backend/app/Models/Ticket.php` — `#[Fillable]` at **12**, `casts()` at **18–21**, `status()` at **38–41**. `status_id` is fillable; `reference` and `created_by` are not, which is why the test fixture in the test plan sets them by assignment.
6. `backend/app/Services/TicketReferenceGenerator.php` and `backend/app/Services/ActivityRecorder.php` — **the two existing services, and the shape of `App\Services`.** Neither is registered in `AppServiceProvider` (`backend/app/Providers/AppServiceProvider.php:15–18` is empty) — Laravel resolves the concrete class. `TicketWorkflow` is the third and is registered nowhere either. **Read `TicketReferenceGenerator.php:12–14`: both existing services guard on `DB::transactionLevel() === 0`. `TicketWorkflow` deliberately does not** — see task 5.
7. `backend/app/Http/Controllers/Api/V1/CategoryController.php:78–84` — `reassignmentRequired()`. **The precedent for a hand-built `422` in this project**, and the reason task 5 does *not* copy it: that method needs extra top-level keys (`ticket_count`, `reassign_to_options`), which is why it builds the envelope by hand. This story needs only a message under one key, so `ValidationException::withMessages()` is correct and shorter.
8. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php:36–37` — `Rule::exists('statuses', 'id')`. **This is why `assertCanTransition()` takes a `Status` model and not an id**: by the time TM-38's form request has run, an unknown id is already a `422`, and the workflow guard never has to answer "does this status exist".
9. `backend/bootstrap/app.php:29–32` — `shouldRenderJsonWhen`. Any exception on an `api/*` request renders as JSON, so a `ValidationException` thrown from a service reaches the client as Laravel's standard `{"message": …, "errors": {…}}` envelope with **no controller try/catch**.
10. `backend/phpunit.xml:7–14` and **27–42** — the suite split, and the comment explaining why the tests run against `tm-mysql-test` on **3307** and never SQLite. **Read it before deciding where AC6's "unit tests" go** — see the note at the top of the test plan.
11. `backend/tests/Feature/Database/RequestersTableSchemaTest.php` — **33 lines, the schema-test pattern.** `Schema::hasColumns()` at **17**, and the `expectException(QueryException::class)` constraint proof at **27–32**. Test group A copies both.
12. `backend/database/seeders/DatabaseSeeder.php:17–22` — the `$this->call([...])` list. Task 4 appends one entry; **order matters**, the new seeder resolves status ids.
13. `docs/erd.md` — the `statuses` block at **43–52**, the relationship lines at **56–62**, the table-notes table at **67–76**, and the closing notes at **77–79**. Task 6 adds to all four.
14. `docs/api-contract.md` — `## Authorization` ends at **line 28**; `## Endpoints` starts at **30**. Task 7 inserts a new section between them.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Any status change | Nothing validates it; `StoreTicketRequest:37` accepts any existing `status_id` at creation | Only a move present in `status_transitions` is legal, and only TM-38's endpoint will ask |
| A move not in the table | — | **`422`** under `errors.status_id`, message names **both** statuses |
| A move whose `required_role` is `admin`, made by an agent | — | **`422`**, a **different** message naming administrators |
| The same move made by an admin | — | Allowed |
| Moving a ticket to the status it already has | — | **`422`** — "This ticket is already Open." Not a silent no-op |
| Building a status dropdown | Every status is offered (`GET /api/v1/statuses`) | TM-38 offers only `allowedTransitions(...)` for the current user |
| Changing the lifecycle | Would need a code change | `UPDATE`/`INSERT`/`DELETE` on `status_transitions` — **no deploy** |
| Ticket creation | `status_id` defaults to `new` (`TicketController.php:40`) | **Unchanged.** Creation is an entry point, not a transition |

---

## Decision — the seeded graph is 14 edges, and Pending is not a trap

The AC gives a spine and two clauses. Spelled out over the seven seeded slugs, with every status guaranteed at least one way out:

| # | From | To | `required_role` | Why |
|---|---|---|---|---|
| 1 | `new` | `open` | — | The AC spine |
| 2 | `new` | `pending` | — | `new` is an active state |
| 3 | `open` | `in-progress` | — | The AC spine |
| 4 | `open` | `pending` | — | Active state |
| 5 | `in-progress` | `resolved` | — | The AC spine |
| 6 | `in-progress` | `pending` | — | Active state |
| 7 | `pending` | `open` | — | **The way back out** |
| 8 | `pending` | `in-progress` | — | **The way back out** |
| 9 | `resolved` | `closed` | **`admin`** | The AC spine, and the one role-gated edge — see the next decision |
| 10 | `resolved` | `reopened` | — | Reopened is reachable from a terminal state |
| 11 | `closed` | `reopened` | — | Reopened is reachable from a terminal state |
| 12 | `reopened` | `in-progress` | — | A reopened ticket rejoins the spine |
| 13 | `reopened` | `resolved` | — | It can be fixed again without a detour |
| 14 | `reopened` | `pending` | — | Reopened is an active state |

- **"The active states" reads as "the four non-terminal ones"** — `new`, `open`, `in-progress`, `reopened` — not as "`bucket = 'open'`". `pending` has its own bucket and is still on someone's plate; the same reading is already in force across E5 (see [`../assignment-workload/00-overview.md`](../assignment-workload/00-overview.md), *"'Open' means `statuses.is_terminal = false` everywhere in this epic, never `bucket = 'open'`"*). **`is_terminal` is the authority.**
- **Rows 7 and 8 are not in the AC and are not optional.** The AC says Pending is *reachable*; it says nothing about leaving. Seed only the inbound edges and a ticket parked on a customer becomes unresolvable forever, which is precisely the failure a server-enforced lifecycle is supposed to prevent. Test 9 (`test_no_status_is_a_dead_end`) pins the invariant so the next person cannot delete these two rows without a red suite.
- **`closed` has exactly one outgoing edge and that is correct.** A closed ticket can only be reopened. It is not a dead end, so test 9 still passes.
- **No `new → in-progress` shortcut, no `open → resolved` shortcut, no `closed → open`.** They are not in the AC, and each is one `INSERT` away for any team that wants it — which is the point of the table.

## Decision — `resolved → closed` is the admin-only edge

AC5 requires a `required_role = admin` edge to exist among the **seeded** rows, or it is untestable against real data. There are two candidates and only one survives the rest of the epic:

- **`closed → reopened` is disqualified.** TM-40 (E6-S4) is *"As an **agent**, I want to reopen a resolved or closed ticket"*. Gating that edge on admins would make the next story in the epic unimplementable.
- **`resolved → closed` is chosen.** Nothing in TM-39 (E6-S3) — which only requires that *"Transitioning into Closed stamps `closed_at`"* — says who may close. Agents resolve; an admin signs off. Closing is the one move nothing in the graph can undo except a reopen, so it is the natural place for a second pair of eyes.

**If the team disagrees, this is a one-row seeder change and zero lines of PHP** — which is the whole claim of this story, and test 23 is what proves it. Record the decision in the PR description so TM-39 does not re-litigate it.

## Decision — both refusals are `422`, never `403`

A rejected transition is `422` whether the edge is missing or role-gated. `403` was rejected because:

- `TicketPolicy::changeStatus()` already answered the authorization question — *may this user operate the status control at all* — and it says yes for all staff (`TicketPolicy.php:40–43`). Whether **this particular edge** is available is a property of the request body, not of the caller's access to the resource.
- TM-38's fifth criterion is *"A rejected transition surfaces the server message rather than a generic error."* A `403` renders as `This action is unauthorized.` through the same path `RouteAuthorizationTest.php:40` asserts, and the specific message would be lost.
- One status code means TM-38's SPA has **one** error path, reading `errors.status_id[0]`.

**The two messages must still differ**, or an agent who hits the admin-only edge is told the move does not exist when it does. Tests 17 and 18 assert each string.

## Decision — `RESTRICT` on both foreign keys, and no `CHECK` constraint

- **`restrictOnDelete()` on `from_status_id` and `to_status_id`**, matching `tickets.status_id` (`create_tickets_table.php:22`) rather than cascading. `statuses` has no delete endpoint, so cascade buys nothing today; `RESTRICT` means that when one is added, deleting a status fails loudly instead of silently amputating the lifecycle. Deleting a status becomes a two-step — remove its edges, then the row — which is correct for a table whose entire purpose is to be the specification. Test 4 pins it.
- **No `CHECK (from_status_id <> to_status_id)`.** TM-16 declined a `CHECK` for hex colours and put the rule in the application instead; follow the precedent. The seeder throws on a self-edge (task 3) and test 10 asserts none is seeded. **Do not add a raw `DB::statement` for a constraint the seeder already enforces.**
- **No extra index.** The composite unique `(from_status_id, to_status_id)` is the leftmost-prefix index for `where('from_status_id', …)`, which is the only query the service makes.

## Decision — no cache

`allowedTransitions()` runs two queries against a 14-row table on an already-authenticated request. Caching it would introduce a staleness window on exactly the data this story exists to make editable at runtime. **Add a cache when a profile says to, not before** — and if one is ever added, it must be invalidated by the seeder.

---

## Backend Tasks

### 1 — The migration

Generate it so the timestamp sorts after `2026_08_26_084626_create_ticket_activities_table.php`:

```bash
cd backend && php artisan make:migration create_status_transitions_table
```

**File: `backend/database/migrations/<timestamp>_create_status_transitions_table.php`**

```php
<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('status_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_status_id')->constrained('statuses')->restrictOnDelete();
            $table->foreignId('to_status_id')->constrained('statuses')->restrictOnDelete();
            // null means any signed-in staff member. Only 'admin' narrows it:
            // 'agent' is storable but semantically identical to null, because an
            // admin may make every move an agent may. See TicketWorkflow.
            $table->enum('required_role', UserRole::values())->nullable();
            $table->timestamps();
            // Also the lookup index: (from_status_id) is its leftmost prefix, and
            // `where from_status_id = ?` is the only query the service runs.
            $table->unique(['from_status_id', 'to_status_id'], 'status_transitions_edge_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_transitions');
    }
};
```

**`->constrained('statuses')` — the table name is mandatory here.** Laravel infers `from_statuses` from `from_status_id` and the migration would fail with errno 150. **Both** foreign keys need it.

### 2 — The model

**Create file: `backend/app/Models/StatusTransition.php`**

```php
<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['from_status_id', 'to_status_id', 'required_role'])]
class StatusTransition extends Model
{
    protected function casts(): array
    {
        return ['required_role' => UserRole::class];
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'to_status_id');
    }
}
```

The `#[Fillable]` attribute rather than `protected $fillable` is the project idiom — every model in `backend/app/Models/` uses it. **`Status` gets no `HasMany` in return**; nothing needs it, and the service queries `StatusTransition` directly.

### 3 — The seeder

**Create file: `backend/database/seeders/StatusTransitionSeeder.php`**

```php
<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Status;
use App\Models\StatusTransition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StatusTransitionSeeder extends Seeder
{
    /**
     * The default lifecycle, by status slug. `required_role` null means any
     * signed-in staff member. This array is the *default* graph, not a lock:
     * the table is editable at runtime and TicketWorkflow reads the table.
     */
    public const EDGES = [
        ['from' => 'new', 'to' => 'open', 'required_role' => null],
        ['from' => 'new', 'to' => 'pending', 'required_role' => null],
        ['from' => 'open', 'to' => 'in-progress', 'required_role' => null],
        ['from' => 'open', 'to' => 'pending', 'required_role' => null],
        ['from' => 'in-progress', 'to' => 'resolved', 'required_role' => null],
        ['from' => 'in-progress', 'to' => 'pending', 'required_role' => null],
        // Pending must be leavable or a ticket parked on a customer is stuck
        // forever. The story asks only that Pending be reachable; these two
        // rows are what stop "reachable" from meaning "terminal".
        ['from' => 'pending', 'to' => 'open', 'required_role' => null],
        ['from' => 'pending', 'to' => 'in-progress', 'required_role' => null],
        // The one role-gated edge: agents resolve, an admin signs off.
        ['from' => 'resolved', 'to' => 'closed', 'required_role' => UserRole::Admin],
        ['from' => 'resolved', 'to' => 'reopened', 'required_role' => null],
        ['from' => 'closed', 'to' => 'reopened', 'required_role' => null],
        ['from' => 'reopened', 'to' => 'in-progress', 'required_role' => null],
        ['from' => 'reopened', 'to' => 'resolved', 'required_role' => null],
        ['from' => 'reopened', 'to' => 'pending', 'required_role' => null],
    ];

    public function run(): void
    {
        $ids = Status::query()->pluck('id', 'slug');

        DB::transaction(function () use ($ids): void {
            $kept = [];
            foreach (self::EDGES as $edge) {
                if ($edge['from'] === $edge['to']) {
                    throw new RuntimeException("Self-transition seeded for '{$edge['from']}'; a status cannot move to itself.");
                }
                foreach ([$edge['from'], $edge['to']] as $slug) {
                    if (! $ids->has($slug)) {
                        throw new RuntimeException("Unknown status slug '{$slug}'. Run StatusSeeder before StatusTransitionSeeder.");
                    }
                }
                $kept[] = StatusTransition::updateOrCreate(
                    ['from_status_id' => $ids[$edge['from']], 'to_status_id' => $ids[$edge['to']]],
                    ['required_role' => $edge['required_role']],
                )->getKey();
            }
            // Prune, unlike StatusSeeder. Without this, removing an edge from
            // EDGES could never tighten a deployed lifecycle -- the illegal move
            // would survive every re-seed and the guard would keep allowing it.
            StatusTransition::query()->whereKeyNot($kept)->delete();
        });
    }
}
```

**Pruning is the one place this seeder departs from `StatusSeeder`, and the consequence must be said out loud in the PR:** `php artisan db:seed` restores the default graph **exactly**, so an edge added by hand in an environment is removed on the next seed run. That is the correct trade — the alternative is a seeder that can only ever loosen the lifecycle. Nothing runs `db:seed` on a live environment as part of a normal deploy; see **Migration / Rollback**.

`whereKeyNot($kept)` takes the array directly. **Do not** replace it with `whereNotIn('id', …)` plus a raw concat of the pair — the ids are already in hand.

### 4 — Wire it into `DatabaseSeeder`

**File: `backend/database/seeders/DatabaseSeeder.php`**

Append one line to the `$this->call([...])` list at **17–22**, **after** `StatusSeeder::class`:

```php
StatusTransitionSeeder::class,
```

**Order is load-bearing** — the new seeder resolves `statuses.id` by slug and throws a `RuntimeException` naming the missing slug if it runs first.

### 5 — The service

**Create file: `backend/app/Services/TicketWorkflow.php`**

```php
<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Status;
use App\Models\StatusTransition;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class TicketWorkflow
{
    /**
     * The statuses this user may move this ticket to right now, ordered the way
     * Status::scopeOrdered orders them so a dropdown matches GET /statuses.
     *
     * An empty collection is a legitimate answer, not an error: a status whose
     * every outgoing edge is role-gated beyond the caller is a dead end for them.
     *
     * @return Collection<int, Status>
     */
    public function allowedTransitions(Ticket $ticket, User $user): Collection
    {
        return $this->edgesFrom($ticket->status_id)
            ->filter(fn (StatusTransition $edge): bool => $this->roleSatisfied($edge, $user))
            ->map(fn (StatusTransition $edge): Status => $edge->toStatus)
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->values();
    }

    /**
     * @throws ValidationException 422 under `status_id`, naming both ends of the move.
     */
    public function assertCanTransition(Ticket $ticket, Status $target, User $user): void
    {
        $from = $ticket->status;

        if ($from->getKey() === $target->getKey()) {
            $this->reject("This ticket is already {$target->name}.");
        }

        $edge = $this->edgesFrom($ticket->status_id)->firstWhere('to_status_id', $target->getKey());

        if ($edge === null) {
            $this->reject("A ticket cannot move from {$from->name} to {$target->name}.");
        }

        if (! $this->roleSatisfied($edge, $user)) {
            $this->reject("Only an administrator can move a ticket from {$from->name} to {$target->name}.");
        }
    }

    /** @return Collection<int, StatusTransition> */
    private function edgesFrom(int $statusId): Collection
    {
        return StatusTransition::query()->where('from_status_id', $statusId)->with('toStatus')->get();
    }

    private function roleSatisfied(StatusTransition $edge, User $user): bool
    {
        // Only 'admin' narrows anything. A stored 'agent' behaves as null,
        // because an admin may make every move an agent may.
        return $edge->required_role !== UserRole::Admin || $user->isAdmin();
    }

    private function reject(string $message): never
    {
        // ValidationException, not a hand-built response: bootstrap/app.php:29-32
        // renders it as Laravel's standard 422 envelope for any api/* request,
        // and TM-38's SPA reads errors.status_id[0] from it.
        throw ValidationException::withMessages(['status_id' => [$message]]);
    }
}
```

Six things not to re-derive:

- **`$target` is a `Status` model, not an id.** TM-38's form request already proves existence with `Rule::exists('statuses', 'id')` (the pattern is at `StoreTicketRequest.php:37`). Taking an id here would duplicate that check and give the guard a second, worse `422` to emit.
- **No `DB::transactionLevel()` guard**, unlike the other two services in `app/Services/`. `TicketWorkflow` only reads; the transaction is TM-38's to open around *its* write. Adding the guard here would also make the service untestable outside a transaction — see baseline failure 3, which is exactly that mistake landing in a test.
- **Status names go into the message unquoted**, matching every existing user-facing string in the project (`CategoryController.php:81`, `StoreTicketRequest.php:45–46`). The seeded names are Title Case and read correctly bare. **Do not add curly quotes**; test assertions would drift from the source on the first retype.
- **Three distinct messages.** Same-status, missing edge, and role-gated edge each say something different. Collapsing the last two tells an agent a legal move does not exist.
- **`$ticket->status` lazy-loads if the caller did not eager-load it.** TM-38 should `->load('status')` before calling. Test 25 pins the query count so a future change cannot quietly turn this into an N+1 inside a loop.
- **Registered nowhere.** No binding in `AppServiceProvider`; Laravel resolves the concrete class, as it already does for `TicketReferenceGenerator` and `ActivityRecorder` (`TicketController.php:30`).

### 6 — Document the table in the ERD

**File: `docs/erd.md`**

Add the entity after the `statuses` block (which ends at **line 52**):

```
    status_transitions {
        bigint id PK
        bigint from_status_id FK
        bigint to_status_id FK
        enum required_role "admin | agent | null"
        timestamp created_at
        timestamp updated_at
    }
```

Add two relationship lines after **line 59** (`statuses ||--o{ tickets : status_id`):

```
    statuses ||--o{ status_transitions : from_status_id
    statuses ||--o{ status_transitions : to_status_id
```

Add one row to the table-notes table (**67–76**), after the `statuses` row:

| Table | Purpose | Owning story |
|-------|---------|--------------|
| `status_transitions` | The ticket lifecycle as data: one row per legal move, optionally role-gated. Seeded with 14 edges; editable at runtime. | TM-37 |

And one line to the closing notes after **line 79**:

```
`status_transitions` is unique on `(from_status_id, to_status_id)`; both foreign keys RESTRICT, so a status must lose its edges before it can be deleted.
```

### 7 — Document the workflow in the API contract

**File: `docs/api-contract.md`**

Insert a new section between `## Authorization` (ends at **28**) and `## Endpoints` (**30**):

```markdown
## Status workflow

The ticket lifecycle lives in the `status_transitions` table, not in code. A
status change is legal only when a row exists for the `(from, to)` pair, and a
row may carry `required_role = admin` to restrict the move. The default graph is
seeded by `StatusTransitionSeeder` and can be edited at runtime without a deploy.

| From | To | Restricted to |
|---|---|---|
| New | Open, Pending | — |
| Open | In Progress, Pending | — |
| In Progress | Resolved, Pending | — |
| Pending | Open, In Progress | — |
| Resolved | Closed | **admin** |
| Resolved | Reopened | — |
| Closed | Reopened | — |
| Reopened | In Progress, Resolved, Pending | — |

Every rejection is a `422` under `errors.status_id`, never a `403` — whether a
transition is available is a property of the request, and `TicketPolicy::changeStatus`
has already answered whether the caller may operate the control at all. Three
messages are possible: `A ticket cannot move from X to Y.` (no such edge),
`Only an administrator can move a ticket from X to Y.` (the edge is admin-only),
and `This ticket is already X.` (the target is the current status).

Creating a ticket is an entry point, not a transition: `POST /api/v1/tickets`
still accepts any existing `status_id` and defaults to New.

**The endpoint that consumes this arrives with TM-38** (`POST /api/v1/tickets/{ticket}/status`),
which also exposes the caller's legal moves on the ticket detail response.
```

**No row is added to the endpoints table** — this story adds no endpoint.

---

## Frontend Tasks

**No frontend changes required.** Not a deferral — there is nothing for the SPA to call. The status control, the dropdown fed by `allowedTransitions`, and rendering the server's rejection message are all **TM-38**, whose plan reads this one. `git status` showing any file under `frontend/` is a defect in this story.

---

## Edge Cases & Failure Modes

- **The graph table is empty (migration ran, seeder did not)** → `allowedTransitions()` returns an empty collection and `assertCanTransition()` rejects every move with "A ticket cannot move from X to Y." No crash, no exception other than the `422`. **This is the half-applied deploy state**; see Migration / Rollback. Test 22 covers the shape.
- **A status with no outgoing edge** → empty collection, and every attempted move from it is rejected. Not an error. The seeded graph has none (test 9), but a hand-edited table can.
- **An agent on `resolved`** → `allowedTransitions()` returns `[Reopened]` only; the admin-only `closed` edge is filtered out. An agent who posts `status_id` for Closed anyway gets the "Only an administrator…" `422` — the filter and the guard are separately tested (tests 14 and 18) because a UI-only filter is not enforcement.
- **A `required_role` of `'agent'`** → behaves exactly as `null`; an admin is still allowed. Storable because the column reuses `UserRole::values()`, seeded nowhere. Test 20 pins the semantics so nobody "fixes" it into an admin-excluding rule.
- **Target equals the current status** → `422` "This ticket is already Open." No self-edges are seeded and none may be (task 3 throws), so this branch is the only way that case is answered. **Deliberately not a `200` no-op**: TM-38 would then write a `status_changed` activity row recording a move that did not happen.
- **An unknown `status_id`** → never reaches this service. TM-38's form request returns `422` from `Rule::exists`. `assertCanTransition()` takes a `Status` model precisely so it has no unknown-id branch.
- **A duplicate edge insert** → MySQL `1062` on `status_transitions_edge_unique`. The seeder cannot trip it (`updateOrCreate` keyed on the pair); a hand-written `INSERT` will. Test 3.
- **The reverse of a seeded edge** → legal and independent. `open → pending` and `pending → open` are two rows; the unique is composite, not per-column. Test 5 exists because a per-column unique would pass every other test in this plan.
- **Deleting a status that has edges** → `1451` foreign-key error, not a silent cascade. There is no status delete endpoint today, so this is only reachable via SQL. Test 4.
- **A self-edge in `EDGES`** → `RuntimeException` from the seeder before any write, inside the transaction, so nothing is half-seeded. Test 10 asserts none is present.
- **An unknown slug in `EDGES`** → `RuntimeException` naming the slug and telling the reader to run `StatusSeeder` first. This is what a mis-ordered `DatabaseSeeder` looks like.
- **Re-running `db:seed`** → idempotent for the default graph (test 7), and **destructive for hand-added edges** (test 8). Stated in task 3 and in the PR description; not a defect.
- **A ticket whose status changed between the dropdown render and the submit** → the guard re-reads the table and the ticket, so a stale dropdown produces a truthful `422` rather than an illegal write. **The row lock that makes this race-free belongs to TM-38** — `assertCanTransition()` must be called inside TM-38's `DB::transaction` after `lockForUpdate()`, or two concurrent requests can both pass the guard. Recorded here because this story cannot enforce it.
- **A soft-deleted ticket** → not this story's concern; route-model binding in TM-38 excludes trashed rows. No `withTrashed()` anywhere in the service.
- **An inactive user** → never reaches the service; the `active` middleware returns `401` and revokes tokens first (`routes/api.php:32`).

---

## Test Plan

**AC6 says "unit tests"; they live under `tests/Feature/`, and that is not a compromise.** Every assertion in group C needs seeded `statuses` and `status_transitions` rows in real MySQL, and `phpunit.xml:7–14` maps `tests/Unit` to the suite that touches no database — the only two files there today are `ExampleTest` and `UserRoleTest`, both pure PHP. Putting DB-backed workflow tests in `tests/Unit` would either need SQLite (forbidden — `phpunit.xml:27–35`) or a mocked query builder, which would test the mock. **All three groups use `RefreshDatabase` against `tm-mysql-test` on 3307.**

Groups A and B follow `tests/Feature/Database/RequestersTableSchemaTest.php`. Group C creates `backend/tests/Feature/Workflow/`, a new directory.

### A — `backend/tests/Feature/Database/StatusTransitionsTableSchemaTest.php` (new)

1. `test_it_has_required_columns` — `Schema::hasColumns('status_transitions', ['from_status_id', 'to_status_id', 'required_role', 'created_at', 'updated_at'])`.
2. `test_required_role_is_a_real_enum` — `$this->seed()`, then `DB::table(...)->insert([... 'required_role' => 'wizard'])` → `QueryException`. **Proves the column is an ENUM and not a `varchar`**, the same guarantee `UsersTableSchemaTest` gives for `users.role`.
3. `test_an_edge_cannot_be_duplicated` — insert the same `(from, to)` pair twice → `QueryException` on `status_transitions_edge_unique`.
4. `test_a_status_with_edges_cannot_be_deleted` — `$this->seed()`, then `DB::table('statuses')->where('slug', 'reopened')->delete()` → `QueryException`. **`reopened` because no ticket references it, so the failure can only come from this table's foreign key** and not from `tickets.status_id`.
5. `test_the_reverse_edge_is_allowed` — assert both `open → pending` and `pending → open` exist after seeding. Guards against someone "simplifying" the composite unique into two single-column uniques.

### B — `backend/tests/Feature/Database/StatusTransitionSeederTest.php` (new)

6. `test_it_seeds_the_whole_graph` — `$this->seed()`; assert **exactly 14** rows, and assert the full `from-slug → to-slug` set matches `StatusTransitionSeeder::EDGES` by slug, not by id.
7. `test_it_is_idempotent` — seed, capture `id`s, run `StatusTransitionSeeder` again; still 14 rows and the **same ids** (proves `updateOrCreate`, not delete-and-recreate).
8. `test_it_prunes_an_edge_that_is_no_longer_in_the_graph` — seed, hand-insert `new → closed`, re-run the seeder, assert it is gone and the count is back to 14. **This is the test that documents the destructive half of task 3.**
9. `test_no_status_is_a_dead_end` — every one of the seven seeded statuses appears as some row's `from_status_id`. **Delete the two `pending →` rows from `EDGES` and confirm this fails** before you move on.
10. `test_no_self_transition_is_seeded` — no row where `from_status_id === to_status_id`.
11. `test_exactly_one_edge_requires_admin` — one row with `required_role = admin`, and it is `resolved → closed`. **If the team changes that decision, this is the test that changes with it.**
12. `test_every_status_except_new_is_reachable` — every seeded status except `new` appears as some row's `to_status_id`; `new` appears as none, because it is the creation entry point (`TicketController.php:40`).

### C — `backend/tests/Feature/Workflow/TicketWorkflowTest.php` (new)

`RefreshDatabase`, `$this->seed()` in `setUp()`. Two helpers:

```php
private static int $sequence = 0;

private function ticketAt(string $slug): Ticket
{
    $ticket = new Ticket([
        'subject' => "Fixture at {$slug}",
        'description' => 'Workflow fixture.',
        'category_id' => Category::query()->value('id'),
        'priority_id' => Priority::query()->where('is_default', true)->value('id'),
        'status_id' => Status::query()->where('slug', $slug)->firstOrFail()->getKey(),
    ]);
    $ticket->requester_id = Requester::firstOrCreate(['email' => 'fixture@example.test'], ['name' => 'Fixture'])->getKey();
    $ticket->created_by = User::query()->where('role', UserRole::Admin)->firstOrFail()->getKey();
    // char(15): 'TKT-2026-000001' is exactly 15. A static counter, not
    // fake()->unique(), for the reason Story 19's TicketFactory gives.
    $ticket->reference = sprintf('TKT-2026-%06d', ++self::$sequence);
    $ticket->save();

    return $ticket;
}

private function slugsFor(string $from, User $user): array
{
    return app(TicketWorkflow::class)->allowedTransitions($this->ticketAt($from), $user)->pluck('slug')->all();
}
```

**There is still no `TicketFactory`** — `backend/database/factories/` holds only `UserFactory.php`. Story 19 (TM-23) owns it and Story 26 (TM-31) may create it first. **If `TicketFactory` exists when you start, use it and delete `ticketAt()`; do not add a second variant.** `created_by` reads the seeded admin from `AdminUserSeeder`; agents come from `User::factory()->agent()->create()`.

13. `test_allowed_transitions_match_the_seeded_graph_for_an_agent` — a data provider over **all seven** slugs, asserting the exact expected slug list: `new` → `[open, pending]`; `open` → `[in-progress, pending]`; `in-progress` → `[pending, resolved]`; `pending` → `[open, in-progress]`; `resolved` → `[reopened]`; `closed` → `[reopened]`; `reopened` → `[in-progress, pending, resolved]`. **Expected order is `sort_order` from `StatusSeeder.php:13–19`, not the order the rows were inserted.**
14. `test_an_admin_sees_the_admin_only_edge` — from `resolved`, an admin gets `[resolved → closed, reopened]` ordered `[closed, reopened]`; an agent gets `[reopened]`. **AC5's first half.**
15. `test_a_legal_move_is_allowed_from_every_seeded_status` — provider of seven `(from, to)` pairs, one per status, all non-admin-gated; `assertCanTransition()` returns without throwing. **AC6's legal half.** Use `$this->expectNotToPerformAssertions()` or assert `true` after the call.
16. `test_an_illegal_move_is_rejected_from_every_seeded_status` — provider of seven `(from, to)` pairs: `new → resolved`, `open → closed`, `in-progress → closed`, `pending → resolved`, `resolved → open`, `closed → open`, `reopened → closed`. Each throws `ValidationException`. **AC6's illegal half.**
17. `test_the_rejection_names_the_attempted_move` — `resolved → open` as an admin; assert the message is exactly `A ticket cannot move from Resolved to Open.` **AC4.**
18. `test_an_admin_only_edge_is_refused_for_an_agent` — `resolved → closed` as an agent; assert exactly `Only an administrator can move a ticket from Resolved to Closed.` **AC5, and the assertion that stops the two messages being collapsed into one.**
19. `test_an_admin_only_edge_is_allowed_for_an_admin` — the same move as an admin does not throw.
20. `test_a_required_role_of_agent_does_not_exclude_an_admin` — set `required_role = 'agent'` on the `new → open` row; assert both an agent **and an admin** may make the move, and that `allowedTransitions()` offers it to both.
21. `test_moving_to_the_current_status_is_rejected` — `open → open`; assert exactly `This ticket is already Open.`
22. `test_a_status_with_no_outgoing_edges_offers_nothing` — delete the `closed → reopened` row; `allowedTransitions()` from `closed` is empty **and** `assertCanTransition(closed → reopened)` now throws.
23. `test_the_guard_reads_the_table_not_a_hard_coded_graph` — **the AC's "configurable without code changes", and the most valuable test in the file.** Delete the `new → open` row and assert that move is now rejected; insert `new → closed` and assert it is now allowed and appears in `allowedTransitions()`. **A hard-coded PHP graph passes tests 13–21 and fails only this one.**
24. `test_the_rejection_is_a_422_under_status_id` — catch the `ValidationException`; assert `$exception->status === 422` and `array_keys($exception->errors()) === ['status_id']`.
25. `test_allowed_transitions_costs_two_queries` — `DB::enableQueryLog()` around one `allowedTransitions()` call; assert **2** (the edges, then the eager-loaded `toStatus`). Pins the `with('toStatus')` so a future edit cannot turn the `map()` into an N+1.

### Not written here

**No HTTP feature test, and no change to `RouteAuthorizationTest`** — this story registers no route, so its `ACCESS` map is untouched and baseline failure 2 is unaffected. TM-38 adds `tickets.status` as `staff` and writes the endpoint tests.

---

## Migration / Rollback

- **Forward:** `php artisan migrate` creates one new table. Nothing existing is altered, no column is added to `tickets`, and no data is rewritten. **`php artisan db:seed --class=StatusTransitionSeeder` must run in the same deploy window.**
- **The half-applied state to fear is "migrated, not seeded."** The table exists and is empty, so once TM-38 ships **every** status change is rejected with "A ticket cannot move from X to Y." — a silent, total lockout of the workflow that no error log distinguishes from correct enforcement. Today, with no endpoint, the empty table is harmless; **from TM-38 onward the seeder is a required deploy step, not a convenience.** Say so in the deployment runbook when TM-38 lands.
- **Rollback:** `php artisan migrate:rollback --step=1` drops `status_transitions`. Safe **only while TM-38 is unshipped** — afterwards the endpoint would return a `500` on a missing table rather than a `422`. `down()` drops the table outright; both foreign keys go with it and no `statuses` row is touched.
- **Re-seeding prunes.** `db:seed` restores `EDGES` exactly and deletes any hand-added edge. Intentional (task 3), pinned by test 8, and the reason a production graph change belongs in `EDGES` and a deploy, not in a one-off `INSERT`.

---

## Verification Steps

1. **Services:** from the repo root, `docker compose ps` → `tm-mysql` and `tm-mysql-test` healthy on **3306** and **3307**.
2. **Backend migrates:** from `backend/`, `php artisan migrate`, then `php artisan migrate:fresh --seed`. Both clean.
3. **The seeder is idempotent:** `php artisan db:seed --class=StatusTransitionSeeder` twice in a row, then confirm the count is still 14:
   ```bash
   php artisan tinker --execute="dump(App\Models\StatusTransition::query()->count());"
   ```
4. **The graph is what the plan says:**
   ```bash
   php artisan tinker --execute="App\Models\StatusTransition::with(['fromStatus','toStatus'])->get()->each(fn(\$t) => print(\$t->fromStatus->slug.' -> '.\$t->toStatus->slug.' ['.(\$t->required_role?->value ?? '-').']'.PHP_EOL));"
   ```
   Expect the 14 rows of the decision table, with `admin` on `resolved -> closed` and nowhere else.
5. **The service answers by role:**
   ```bash
   php artisan tinker --execute="\$w = app(App\Services\TicketWorkflow::class); \$t = App\Models\Ticket::query()->firstOrFail(); \$t->status_id = App\Models\Status::where('slug','resolved')->value('id'); dump(\$w->allowedTransitions(\$t, App\Models\User::where('role','admin')->firstOrFail())->pluck('slug')->all(), \$w->allowedTransitions(\$t, App\Models\User::factory()->agent()->create())->pluck('slug')->all());"
   ```
   Expect `['closed','reopened']` then `['reopened']`. **Do not save that ticket.**
6. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
7. **Backend tests:** from `backend/`, `composer test`. Expect **+25 tests over the baseline (101 → 126)** and **exactly the same three pre-existing failures**, no more. Then the targeted run: `php artisan test --filter='TicketWorkflowTest|StatusTransitionSeederTest|StatusTransitionsTableSchemaTest'` → all green.
8. **Prove test 23 earns its place:** replace `edgesFrom()`'s query with a hard-coded array matching `EDGES`, re-run `--filter=test_the_guard_reads_the_table_not_a_hard_coded_graph`, confirm it **fails**, restore.
9. **Prove test 9 earns its place:** delete the two `pending →` rows from `StatusTransitionSeeder::EDGES`, re-run `--filter=test_no_status_is_a_dead_end`, confirm it **fails**, restore.
10. **Regression, by hand.** `php artisan serve`, and with any staff token:
    - `GET /api/v1/statuses` → still seven statuses in `sort_order`, unchanged shape.
    - `POST /api/v1/tickets` with a valid body → still `201`, still defaults to New. **The guard must not have leaked into creation.**
    - `GET /api/v1/tickets/{id}` → response identical to before this story; **no `allowed_transitions` key** (that is TM-38's).
    - `git status` → **no file under `frontend/` and no line in `routes/api.php` changed.**

---

## Done Criteria

- [ ] `status_transitions` exists with `from_status_id`, `to_status_id` and a **nullable ENUM** `required_role` over `UserRole::values()`, unique on `(from_status_id, to_status_id)`.
- [ ] Both foreign keys are `RESTRICT`, proven by a test that deletes a status and expects a `QueryException`.
- [ ] `StatusTransitionSeeder` seeds **exactly 14 edges** implementing `New → Open → In Progress → Resolved → Closed`, with `Pending` reachable from all four active states **and leavable**, and `Reopened` reachable from both terminal states.
- [ ] No status is a dead end and no self-transition exists — both asserted, not merely intended.
- [ ] The seeder is idempotent, **prunes edges no longer in `EDGES`**, and throws a named `RuntimeException` if it runs before `StatusSeeder`. It is wired into `DatabaseSeeder` **after** `StatusSeeder::class`.
- [ ] `TicketWorkflow::allowedTransitions(Ticket, User)` returns `Status` models ordered by `sort_order` then `name`, filtered by the caller's role, in **two** queries.
- [ ] `TicketWorkflow::assertCanTransition(Ticket, Status, User)` throws a `422` under `errors.status_id` naming **both** statuses, with a **different** message for an admin-only edge and a **third** for a same-status move.
- [ ] `resolved → closed` is the one admin-gated seeded edge; an agent is refused it and an admin is not — and `required_role = 'agent'` is proven to behave as `null`.
- [ ] The guard reads the table: deleting a seeded row makes that move illegal and inserting a new row makes one legal, **proven by a test that a hard-coded graph would fail**.
- [ ] Both a legal and an illegal move are covered for **every one of the seven seeded statuses**.
- [ ] **No route, no controller, no form request, no policy change, no `TicketActivityEvent` case, no timestamp write, no frontend file, no new dependency.**
- [ ] `docs/erd.md` carries the new entity, both relationships, a table-notes row and the RESTRICT note; `docs/api-contract.md` carries a `## Status workflow` section with the graph, the three messages and the `422`-not-`403` rule — and **no new endpoints-table row**.
- [ ] `./vendor/bin/pint --test` exits `0`; `composer test` reports **+25 tests** over the measured baseline and **the same three pre-existing failures**, none of them in a file this story touched.
- [ ] The PR description records the admin-only-edge decision, the pruning behaviour, and that the seeder becomes a required deploy step once TM-38 ships.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 32 (TM-38, change a ticket's status).**
