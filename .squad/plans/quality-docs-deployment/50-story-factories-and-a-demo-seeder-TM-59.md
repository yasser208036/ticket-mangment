# Story 50 — Factories and a demo seeder (Story: TM-59)

## Prerequisites

- **This story is a fix-and-complete, not a greenfield build, and the fix is the point.** `backend/database/factories/` already holds **`UserFactory.php`, `RequesterFactory.php` and `TicketFactory.php`**, landed by the E4 stories as scaffolding. **`grep -rln "Ticket::factory\|Requester::factory\|TicketFactory" backend/tests/` returns nothing** — two of the three factories have **no test at all**, and one of them carries a defect that breaks the create endpoint. Two of AC1's five factories are therefore already "done" on paper. **Read `## What already exists — and the live bug in it` before writing a line.**
- **`TicketFactory` allocates ticket references from a private static counter, and it is a live bug.** `TicketFactory.php:16` declares `private static int $sequence = 0;` and **line 21** builds `sprintf('TKT-%d-%06d', now()->year, ++self::$sequence)`. Measured 2026-08-27 against `tm-mysql-test`:
  ```
  Ticket::factory()->count(3)->create()  =>  TKT-2026-000001, TKT-2026-000002, TKT-2026-000003
  SELECT * FROM ticket_sequences         =>  [] (empty)
  DB::transaction(fn () => app(TicketReferenceGenerator::class)->next())  =>  TKT-2026-000001
  Ticket::factory()->create(['reference' => 'TKT-2026-000001'])
    => UniqueConstraintViolationException: SQLSTATE[23000] 1062
       Duplicate entry 'TKT-2026-000001' for key 'tickets.tickets_reference_unique'
  ```
  **`POST /api/v1/tickets` 500s on the first ticket filed after any factory-seeded run.** Task 3 is the fix; test 6 is the regression; verification step 5 reproduces it deliberately.
- **`Requester` and `Ticket` already use `HasFactory`; `Category` and `TicketActivity` do not.** Measured: `grep -rn "HasFactory" backend/app/Models/` hits `Requester.php:13`, `Ticket.php:17`, `User.php:24` — and nothing else. `Category::factory()`, `TicketActivity::factory()`, `Priority::factory()` and `Status::factory()` all throw `BadMethodCallException`. Task 1 adds the trait to **two** models, not six.
- **`AC1`'s `users` clause is already satisfied and must not be rebuilt.** `UserFactory.php` (**63 lines**) defines `definition()` at **26–37** (`role => UserRole::Agent` at **34**, `is_active => true` at **35**, `static::$password` memoised at **32**) and the four states Story 06 built for TM-9…TM-14: `unverified()` **42–47**, `admin()` **49–52**, `agent()` **54–57**, `inactive()` **59–62**. This story adds **one** state and changes nothing else.
- **Stories 26, 32, 33 and 35 are hard gates for AC3, and the enum still lacks all three cases they own.** `app/Enums/TicketActivityEvent.php` now holds **four** cases — `Created`, `CategoryChanged`, `Updated`, `Deleted` (lines **7–10**), the last two added by TM-27 and TM-28. **`Assigned`, `StatusChanged` and `Escalated` are all still absent**, and AC3 needs every one. They are gates by sequence (26, 32, 33, 35 all precede 50). See the decision for the exact fallback and for why *resolved* is **not** an event case.
- **Story 38 (TM-45) is a soft gate.** It adds `Ticket::activities()` and `TicketActivity::user()`; measured today, **neither exists** — `grep -n "activities\|function ticket\|function user" backend/app/Models/Ticket.php backend/app/Models/TicketActivity.php` returns nothing. `TicketActivityFactory` needs neither (it sets `ticket_id` directly); only **test 14** does. This story adds neither relation.
- **Measured baseline, 2026-08-27, from `backend/`.** `composer test` → **109 tests, 317 assertions, 106 passing, 3 failing**; `./vendor/bin/pint --test` → `{"tool":"pint","result":"passed"}`. **None of the three is in a file this story touches:**
  1. `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` — `429` vs `422`. **TM-14 owns it.**
  2. `Authorization\RouteAuthorizationTest::test_agent_refused_by_policy_admin_routes` — `404` vs `403`. **TM-13 owns it.** *(Note: this is a **different** RouteAuthorizationTest failure than the one Story 38's baseline recorded — `test_every_api_route_is_classified` now passes, and `test_agent_refused_by_policy_admin_routes` has taken its place.)*
  3. `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` — **Story 38 fixes it**; `RefreshDatabase` holds an open transaction, so the guard can never fire.
- **Docker up**, `tm-mysql` healthy on **3306**, `tm-mysql-test` on **3307**, `tm-mailpit` on 1025/8025. **No new composer or npm dependency, no migration, no endpoint, no route, no policy, no frontend file.**

---

## What already exists — and the live bug in it

| Acceptance criterion | Status | Evidence |
|---|---|---|
| AC1 — factory for **users** | **Done.** Do not rebuild. | `UserFactory.php:26–62`, four states, used throughout `tests/Feature/Auth/`. |
| AC1 — factory for **requesters** | **Exists, untested, thin.** | `RequesterFactory.php` (**20 lines**): four attributes, **no states**. No test references it. |
| AC1 — factory for **tickets** | **Exists, untested, and broken.** | `TicketFactory.php` (**37 lines**): one state (`assignedTo`, **33–36**), **no `created_at`**, and the reference counter at **16/21**. No test references it. |
| AC1 — factory for **categories** | **Missing.** | `Category::factory()` → `BadMethodCallException`. |
| AC1 — factory for **activities** | **Missing.** | `TicketActivity::factory()` → `BadMethodCallException`. |
| AC2 — demo seeder | **Missing.** | `database/seeders/` holds `AdminUserSeeder`, `CategorySeeder`, `DatabaseSeeder`, `PrioritySeeder`, `StatusSeeder`. No demo class. |
| AC3 — plausible activity histories | **Blocked.** | `TicketActivityEvent` has no `Assigned`, `StatusChanged` or `Escalated` case. |
| AC4 — dates spread over recent months | **Actively wrong.** | `TicketFactory::definition()` sets **no `created_at`**, so every factory ticket is dated `now()`. Date filters and age columns have nothing to show. |
| AC5 — separate from production, never in production | **Missing.** | Nothing to guard yet — and `--force` would bypass artisan's prompt if there were. See the decision. |

**Three defects in `TicketFactory` beyond the reference counter, all measured:**

1. **No `created_at`.** Every factory ticket is dated `now()`. AC4 is not merely unmet, it is contradicted.
2. **`category_id` resolves to `null` when `categories` is empty**, producing a failure that names the wrong cause:
   ```
   Ticket::factory()->create()  with 0 categories
   => QueryException: SQLSTATE[23000] 1048 Column 'category_id' cannot be null
   ```
   `line 25` is `fn () => Category::query()->value('id')` with no fallback and no error. `priority_id`/`status_id` (**26–27**) have the same shape and the same hole, while `TicketController::defaultKey()` (**139–147**) throws a message that actually tells you to run `db:seed`.
3. **The reference format diverges from the generator's.** The factory writes `'TKT-%d-%06d'` (**line 21**); `TicketReferenceGenerator.php:27` writes `'TKT-%04d-%06d'`. Identical for a four-digit year, and a silent trap for anyone who ever passes a different one.

**A fourth, recorded but not fixed here:** `self::$sequence` is static **per process**. `php artisan test --parallel` is not configured in this repo (`composer test` is `artisan test`), but if it ever is, two processes both start at `0`. The fix in task 3 removes the failure mode entirely rather than papering over it.

---

## Story Goal

A developer clones the repo, runs two commands, and looks at a queue that behaves like a real one — fifty tickets of varying age, every status and every priority represented, some assigned, some escalated, some resolved, each with a history that matches its state. The two factories that already shipped stop being a trap, and the endpoints that landed in E4 keep working on a seeded database.

1. **`TicketFactory` stops inventing references** and allocates from `ticket_sequences`, so `POST /api/v1/tickets` survives a seeded database. **This is the story's most consequential change.**
2. **Five factories** — `UserFactory` (one state added), `RequesterFactory` (states added), `TicketFactory` (fixed and filled out), `CategoryFactory` and `TicketActivityFactory` (new) — with the states the queue, filter, assignment, escalation and timeline stories will ask for.
3. **`HasFactory` on `Category` and `TicketActivity`**, so `Model::factory()` stops throwing.
4. **`DemoSeeder`** — 2 admins, 6 agents (one inactive), the six seeded categories, **50 tickets covering all 7 statuses and all 4 priorities**, dates spread across six months, and a per-ticket activity history consistent with the ticket's own state.
5. **`DemoSeeder` can never run in production**, enforced by a throw in the seeder rather than by the artisan confirmation prompt — because `--force` bypasses that prompt entirely, and that is measured below.
6. **`DatabaseSeeder` is unchanged**: it still seeds only the admin and the master data, and a test proves running it creates **zero** tickets.

**Not in scope, and each belongs to a named story.** **No `PriorityFactory` and no `StatusFactory`** — AC1 does not list them and both tables carry a unique index over a generated column; see the decision. **No backend feature-test suite** — TM-60 owns coverage; this story ships only the tests that defend its own factories and seeder, and it does **not** backfill tests for `index`, `stats`, `update` or `destroy`. **No frontend component tests** (TM-61), **no API documentation** (TM-62), **no deployment runbook** (TM-63), **no security hardening** (TM-64). **No new `TicketActivityEvent` case** unless the gate stories have not landed — see the decision. **No `Ticket::activities()` / `TicketActivity::user()`** (Story 38). **No fix for any of the three baseline failures.** **No migration, no schema change, no endpoint, no route, no policy, no `docs/erd.md` change, no frontend file.**

---

## What the backlog has already decided

Four contracts are written into plan files this story may not edit. All four re-verified against the code on 2026-08-27.

| Contract | Where it was written | Verified state |
|---|---|---|
| **`is_default_unique` must never enter a factory `definition()`.** MySQL answers `ERROR 3105`; it reaches the SPA as a 500, not a validation error. | `categories-priorities-statuses/00-overview.md:20`, `13-story-…-TM-16.md:81`, `:564`, `:603` — *"The most likely future offender is TM-59's factories."* | **Confirmed by probe.** `DB::table('priorities')->insert([… 'is_default_unique' => 1])` → `QueryException: SQLSTATE[HY000]: General error: 3105 The value specified for generated column 'is_default_unique' in table 'priorities' is not allowed.` |
| **`reference` and `created_by` are not fillable, and that does not break a factory.** | `ticket-creation-tracking/00-overview.md:41`, `17-story-…-TM-21.md:114–118` | **Confirmed.** `Ticket.php:13`'s `#[Fillable]` excludes both, and `vendor/…/Factories/Factory.php:523–532` wraps `newModel()` in `Model::unguarded()` at **525**. `TicketFactory` already relies on this today. |
| **`HasFactory` is TM-59's to add**, which is why Story 13 left it off `Category`, `Priority` and `Status`. | `categories-priorities-statuses/13-story-…-TM-16.md:376`, `:751`; `00-overview.md:27` | **Still true for `Category` and `TicketActivity`.** `Requester` and `Ticket` got theirs from E4 ahead of schedule. |
| **The `makeTicket()` duplication is four private copies, and hoisting it is a fatal error.** PHP forbids reducing an inherited method's visibility. | `ticket-history-audit-trail/00-overview.md:89`, `42-story-…-TM-49.md:135`, `:769` | **Not reachable.** `grep -rn "function makeTicket\|function ticketAt" backend/tests/` → **no output**; Stories 31, 39, 40, 41, 42 have not landed. Task 8 handles both worlds. |

---

## Decision — `TicketFactory` allocates `reference` through `TicketReferenceGenerator`

`tickets.reference` is `char(15) NOT NULL UNIQUE` (`…create_tickets_table.php:16`) and `TicketController::store()` fills it from `TicketReferenceGenerator::next()` (**line 128**), which allocates from `ticket_sequences`.

**The factory's private counter never touches that table**, so the two allocators hand out the same strings. Measured, and quoted in full in the prerequisites: **`1062 Duplicate entry 'TKT-2026-000001'`**. That is a broken create endpoint on every seeded database, not a demo-data blemish — and because no test uses `TicketFactory`, nothing in CI notices.

Replace `TicketFactory.php:16` and **21** with:

```php
'reference' => fn (array $attributes): string => $this->allocateReference(
    Carbon::parse($attributes['created_at'])->year
),
```

```php
/**
 * Allocate from ticket_sequences, never a private counter: a factory-set reference
 * that bypassed the table collides with the first ticket filed through
 * POST /api/v1/tickets (measured: 1062 on tickets_reference_unique).
 *
 * This needs a live database connection, so Ticket::factory()->make() does too.
 * Every test in this suite has one by design — phpunit.xml:27–42 forbids SQLite.
 */
private function allocateReference(int $year): string
{
    $generator = app(TicketReferenceGenerator::class);

    return DB::transactionLevel() > 0
        ? $generator->next($year)
        : DB::transaction(fn (): string => $generator->next($year));
}
```

`next()` throws outside a transaction (`TicketReferenceGenerator.php:12–14`), hence the branch. **Measured cost, and why there is no argument for cheating:** 50 allocations each in their own transaction → **26 ms**; 50 inside one transaction → **28 ms**. Under `RefreshDatabase` the suite is always at `transactionLevel() >= 1`, so tests take the first branch.

**The year comes from the ticket's own `created_at`, not `now()`.** A ticket dated November 2025 carrying a `TKT-2026-…` reference is exactly the detail that makes a demo look fake, and `next()` already accepts `?int $year`. This is why `reference` is a closure over `$attributes` — `created_at` must be resolved first.

**Two consequences to state in the PR.** `Ticket::factory()->make()` now needs a connection (docblocked above; no `tests/Unit` test calls it today — `tests/Unit/` holds only `Enums/UserRoleTest.php`, `Services/TicketSearchTest.php` and `ExampleTest.php`). And references acquire gaps, because `make()` burns a sequence number it never persists — **harmless; uniqueness is the constraint, not contiguity.**

## Decision — no `PriorityFactory`, no `StatusFactory`

AC1 names five factories: *users, requesters, categories, tickets and activities*. Priorities and statuses are absent, and that reading is correct:

- **Both tables carry a unique index over a generated column.** `priorities_single_default_unique` and `statuses_single_default_unique` (`…create_priorities_table.php:21–22`, `…create_statuses_table.php:22–23`) permit exactly one `is_default = 1` row, and `priorities.level` is `UNIQUE` too. A `definition()` returning `is_default => fake()->boolean()` would fail intermittently.
- **They are reference data, not domain data.** `PrioritySeeder::PRIORITIES` (**11–16**) and `StatusSeeder::STATUSES` (**12–20**) are fixed constant arrays; the four priorities and seven statuses **are** the product. A factory inventing an eighth status would produce a demo whose workflow does not exist.
- **Every plan that needed them said the seeders are the fixture** — `16-story-…-TM-19.md:646`: *"there is no `PriorityFactory` or `StatusFactory` (TM-59 owns those)"*. This story makes that permanent, with test 2 as the tripwire.

`DemoSeeder` therefore calls `CategorySeeder`, `PrioritySeeder` and `StatusSeeder` itself — all three are idempotent (`firstOrCreate` / `updateOrCreate`).

## Decision — *resolved* is not an activity event, and the three cases AC3 needs belong to earlier stories

AC3: *"plausible activity histories, including some assigned, some escalated and some resolved."* Three words, three different answers.

- **`assigned` → `TicketActivityEvent::Assigned`, owned by Story 26 (TM-31).**
- **`escalated` → `TicketActivityEvent::Escalated`, owned by Story 35 (TM-41).**
- **`resolved` → NOT an event case.** Story 33 (TM-39) makes resolution a transition into the `resolved` status; Story 32 (TM-38) records transitions as `StatusChanged = 'status_changed'` with `field = 'status'` and `new_value` the slug. **A `Resolved` case would be a second source of truth for one fact** and would show resolution twice in TM-46's timeline. The demo history records `status_changed` with `new_value => 'resolved'`.

**Raw strings are not a workaround, and it is measured.** `ticket_activities.event` is `varchar(50)`, so MySQL accepts anything — but the model casts it (`TicketActivity.php:16`):

```
DB::table('ticket_activities')->insert([... 'event' => 'assigned'])   -- accepted by MySQL
TicketActivity::query()->where('event', 'assigned')->first()->event
=> ValueError: "assigned" is not a valid backing value for enum App\Enums\TicketActivityEvent
```

**A demo database seeded with raw strings throws the moment anything reads a ticket** — including `GET /api/v1/tickets`, which now exists (`routes/api.php:44`).

**Stories 26, 32, 33 and 35 all precede this one (26, 32, 33, 35 < 50), so they are hard gates and this story adds no case.** Task 2 begins by checking:

```bash
grep -n "case Assigned\|case StatusChanged\|case Escalated" backend/app/Enums/TicketActivityEvent.php
```

**Measured today: no output — all three are missing**, because none of those stories has landed. If that is still true at execution time, add **only** the missing ones, appended after `Deleted` (**line 10**), with **exactly** these strings — the ones those plans already specify:

```php
case Assigned = 'assigned';            // Story 26 / TM-31
case StatusChanged = 'status_changed'; // Story 32 / TM-38
case Escalated = 'escalated';          // Story 35 / TM-41
```

**Add cases, never rename them, never invent one.** Story 38's plan puts it in those words at line 54: *"the string is what is already in the `event` column."* Record in the PR which cases this story added, so the gate story finds them present — its own plan already tells it to check first (`35-story-…-TM-41.md:216`). **Do not add the `@return list<string>` docblock to `values()`** (**12–15**); Story 38 owns that line.

## Decision — the production guard lives in the seeder, because `--force` bypasses artisan's prompt

AC5: *"never runs in production."* The instinct is that `db:seed` already handles this. **It does not, and the code path is short enough to quote.** `SeedCommand.php:61` calls `confirmToProceed()`, and `ConfirmableTrait.php:26–29` reads:

```php
if ($shouldConfirm) {
    if ($this->hasOption('force') && $this->option('force')) {
        return true;
    }
```

`$shouldConfirm` is `environment() === 'production'` (**line 53**). So `php artisan db:seed --force --class=DemoSeeder` on a production host **runs, silently, with no prompt** — and `--force` is what every deploy script passes, because `composer setup` already runs `migrate --force` (`composer.json` `setup`). **The prompt protects an interactive operator and nobody else.**

The guard is the first statement in `DemoSeeder::run()`, and it **throws**:

```php
if (app()->isProduction()) {
    throw new RuntimeException(
        'DemoSeeder must never run in production. It is not registered in DatabaseSeeder, '
        .'and unlike artisan\'s confirmation prompt this check is not bypassed by --force.'
    );
}
```

**It blocks only `production`.** `local` and `testing` must both work or the seeder is untestable — `phpunit.xml:21` sets `APP_ENV=testing`, measured as `app()->environment() === 'testing'`.

**The test has one trap, and it is measured.** `config()->set('app.env', 'production')` does **not** change `app()->environment()` — the probe returned `testing` afterwards — so the obvious test **passes without ever executing the guard**. Both of these work:

```
app()->instance('env', 'production')             => environment() === 'production'
app()->detectEnvironment(fn () => 'production')  => environment() === 'production'
```

Test 20 uses `detectEnvironment()` and restores it in `tearDown`. **Do not write the `config()` version.**

## Decision — `DemoSeeder` refuses a non-empty `tickets` table instead of being idempotent

Running it twice would produce 100 tickets and 12 agents, which is not idempotency. True idempotency means matching demo rows on some stable marker, which means inventing demo identifiers and carrying them forever.

So it aborts:

```php
if (Ticket::withTrashed()->exists()) {
    throw new RuntimeException('DemoSeeder found existing tickets. Run `php artisan migrate:fresh --seed` first, then seed the demo data.');
}
```

**`withTrashed()` matters** — `tickets` soft-deletes (`Ticket.php:17`), and `DELETE /api/v1/tickets/{ticket}` now exists (`routes/api.php:49`), so a demo dataset someone soft-deleted while exploring TM-28 must still block a re-seed.

This also closes a latent collision: `requesters.email` is `UNIQUE` and `RequesterFactory.php:15` draws from `fake()->unique()->safeEmail()`, whose uniqueness state is **per-`Generator`, per-process**. Measured: two identically seeded generators produced different sequences, so a second `db:seed` in a fresh process is *unlikely* to collide rather than *unable* to. The abort makes it impossible.

---

## Context — Read These Files First

1. `backend/database/factories/TicketFactory.php` — **the whole file, 37 lines, and the reason this story exists.** `private static int $sequence = 0;` at **16** and the `sprintf('TKT-%d-%06d', now()->year, ++self::$sequence)` at **21** are the bug. Note also: **no `created_at` anywhere** (AC4), `category_id` at **25** with no fallback, `priority_id`/`status_id` at **26–27** duplicating `TicketController::defaultKey()` without its error, `created_by` an **agent** at **28**, `assigned_to` null at **29**, and one state at **33–36**. Task 3 rewrites **16–31** and keeps `assignedTo()`.
2. `backend/app/Services/TicketReferenceGenerator.php` — **28 lines, all of it.** The transaction guard at **12–14** (why the factory opens one), the `LAST_INSERT_ID` upsert at **17–21**, the 999999 ceiling at **23–25**, and the `'TKT-%04d-%06d'` format at **27** — measured **exactly 15 characters**, which is why `reference` is `char(15)` and why 16 characters fails with `1406 Data too long`. **Compare line 27 with `TicketFactory.php:21` and note the format divergence.**
3. `backend/app/Http/Controllers/Api/V1/TicketController.php:116–147` — **`store()` and the shape the factory must stay consistent with.** `DB::transaction` at **120**, `Requester::firstOrCreate` on email at **122**, defaults at **125–126**, `reference` assigned at **128**, the `Created` activity at **130** with `meta => ['reference' => …]` (**the demo history copies this shape**), and `defaultKey()` at **139–147** — whose *"Run `php artisan db:seed` to restore master data"* message is what task 3's `defaultOr()` mirrors.
4. `backend/database/factories/RequesterFactory.php` — **20 lines, four attributes, no states.** `fake()->unique()->safeEmail()` at **15** is required (`requesters.email` is `UNIQUE`; measured clean over 200 draws). `fake()->optional()` at **16–17** defaults to 50 % null. Task 3 adds states and tightens the probabilities; **`email` is not touched.**
5. `backend/database/factories/UserFactory.php` — **63 lines; the house style for every factory here.** `definition()` **26–37**, `unverified()` **42–47**, `admin()` **49–52**, `agent()` **54–57**, `inactive()` **59–62**. Task 3 appends **one** state after **62**. **Do not touch `definition()` or the four existing states** — every test in `tests/Feature/Auth/` depends on them.
6. `backend/app/Models/Ticket.php` — **58 lines.** `use HasFactory, SoftDeletes;` at **17** (already present), `#[Fillable]` at **13** — `reference` and `created_by` **absent**, which the contracts table explains. `casts()` at **19–22**. Seven `BelongsTo` at **24–57**. **No `HasMany`** — Story 38 adds it, not this story.
7. `backend/database/migrations/2026_08_26_084625_create_tickets_table.php` — **14–40, every line.** The invariant list task 5 must satisfy: `reference` `char(15)` unique (**16**); four `NOT NULL restrictOnDelete` FKs (**19–22**); `assigned_to` **nullable** `nullOnDelete` (**23**); `created_by` **NOT NULL** (**24**); `escalation_level` default 0 plus three nullable escalation columns (**25–28**); `first_responded_at`/`resolved_at`/`closed_at` (**29–31**); `softDeletes()` (**33**); `fullText(['subject','description'])` (**39**).
8. `backend/app/Models/TicketActivity.php` — **18 lines.** `#[Fillable]` at **9**, `UPDATED_AT = null` at **12**, `casts()` at **14–17** (`event` → `TicketActivityEvent`, `meta` → `array`). Task 1 adds the trait; **no relation.** The migration's `created_at` is `useCurrent()` (`…create_ticket_activities_table.php:20`) — measured: `create()` with no `created_at` stores `now()`, and an explicit value round-trips exactly.
9. `backend/app/Enums/TicketActivityEvent.php` — **16 lines, four cases at 7–10.** `Updated` and `Deleted` arrived with TM-27/TM-28. **`Assigned`, `StatusChanged` and `Escalated` are absent** — task 2's grep, and the decision above.
10. `backend/app/Services/ActivityRecorder.php` — **40 lines.** `record()` **13–19** with its five defaults, `recordMany()` **21–39**, the transaction guard **26–28**, `now()` at **29** (**why task 6 needs `Carbon::setTestNow()`**), and `TicketActivity::insert($chunk)` at **37**, the project's only write to that table.
11. `backend/database/seeders/DatabaseSeeder.php` — **23 lines.** `use WithoutModelEvents;` at **10**, the four-class `call()` at **17–22**. **This file is not edited** — `13-story-…-TM-16.md:499`: *"`TM-59`'s demo seeder will be a separate class that never runs in production."*
12. `backend/database/seeders/AdminUserSeeder.php` — **34 lines.** The `blank($password)` throw at **17–19** is why `$this->seed()` with no argument explodes in this suite, and why `DemoSeeder` creates its own admins instead of calling this one.
13. `backend/database/seeders/CategorySeeder.php:10–17` — the six-entry `CATEGORIES` constant; **these six are the demo taxonomy.** `StatusSeeder.php:12–20` gives the seven slugs with their `bucket` and `is_terminal` flags — the ordering task 5 walks — and `PrioritySeeder.php:11–16` the four priorities with `level`.
14. `backend/tests/Feature/Database/AdminUserSeederTest.php` — **37 lines.** The precedent for every seeder test: `RefreshDatabase` at **15**, and the `config()->set('seeding.admin', …)` in `setUp()` at **17–21** that test 18 needs in order to run `DatabaseSeeder` at all.
15. `backend/tests/Feature/Database/RequestersTableSchemaTest.php` — the schema-assertion style (`Schema::hasColumns`, `expectException(QueryException::class)`) tests 15–17 follow.
16. `backend/config/seeding.php` — **9 lines**, `admin` block only. Task 4 adds a `demo` block beside it. `backend/.env.example`'s last block (`TICKETS_STALE_AFTER_HOURS`, with the `ADMIN_*` comment above it) is where the matching variables go.
17. `backend/routes/api.php:44–49` — `tickets.index`, `tickets.stats`, `tickets.store`, `tickets.show`, `tickets.update`, `tickets.destroy`. **The demo data has six live consumers**, which is what makes verification step 12 possible and what makes the `ValueError` in the enum decision a real outage rather than a theoretical one.
18. `README.md:53–65` — the `migrate:fresh --seed` block and the full-reset block. Task 9 adds the demo command **after line 65**, not inside `composer setup`'s description.
19. `vendor/laravel/framework/src/Illuminate/Console/ConfirmableTrait.php:20–55` — **read before writing the guard.** The `--force` short-circuit at **26–29** and `environment() === 'production'` at **53** are the entire reason task 6's guard exists.
20. `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Factories/Factory.php:523–532` — `makeInstance()` and `Model::unguarded()` at **525**: the proof a factory may write `reference` and `created_by`.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| A factory ticket's `reference` | `TKT-{year}-{private static counter}`; `ticket_sequences` untouched | Allocated from `ticket_sequences`, year from the ticket's `created_at` |
| First ticket filed via `POST /api/v1/tickets` after a factory run | **500 — `1062 Duplicate entry`** | **201** |
| A factory ticket's `created_at` | Always `now()` | Spread over `DEMO_MONTHS`; overridable |
| `Ticket::factory()->create()` with no categories | `1048 Column 'category_id' cannot be null` | Throws naming `php artisan db:seed` |
| `Ticket::factory()->make()` | Works with no database | **Needs a connection** — documented, and the price of the fix |
| `Category::factory()`, `TicketActivity::factory()` | `BadMethodCallException` | Return factories |
| `Priority::factory()`, `Status::factory()` | `BadMethodCallException` | **Unchanged — still throws, deliberately.** |
| `php artisan db:seed` | admin + 6 categories + 4 priorities + 7 statuses | **Unchanged.** Zero tickets, zero requesters, zero demo users. |
| `php artisan db:seed --class=DemoSeeder` (local) | class does not exist | 2 admins, 6 agents, 50 tickets, ~120 activities |
| `db:seed --force --class=DemoSeeder` (production) | class does not exist | **`RuntimeException`** |
| `db:seed --class=DemoSeeder` twice | n/a | **Second run throws** and names `migrate:fresh --seed` |
| A demo ticket in a `done`-bucket status | n/a | `resolved_at` set; `closed_at` only for `closed` |
| A demo `reopened` ticket | n/a | `resolved_at` **cleared** |
| A demo ticket with `escalation_level > 0` | n/a | `escalated_at`, `escalated_by`, `escalation_reason` all non-null |
| A demo ticket's activity rows | n/a | First row is `created`; every `created_at` ≥ the ticket's |
| Demo seeding sending 50 emails | n/a | **Impossible.** The seeder writes models directly; E8 dispatches from controllers. `WithoutModelEvents` covers a future observer. |

---

## Backend Tasks

### 1 — `HasFactory` on the two models that still lack it

**Files:** `backend/app/Models/Category.php`, `backend/app/Models/TicketActivity.php`

Match `User.php:23–24` — import, `@use` docblock, trait. `Category` already `use SoftDeletes;`, so combine alphabetically:

```php
/** @use HasFactory<CategoryFactory> */
use HasFactory, SoftDeletes;
```

**Do not add the trait to `Priority` or `Status`** (see the decision). **`Requester.php:13` and `Ticket.php:17` already have it** — do not add a second.

### 2 — Confirm the three `TicketActivityEvent` cases

```bash
grep -n "case Assigned\|case StatusChanged\|case Escalated" backend/app/Enums/TicketActivityEvent.php
```

**Measured today: no output.** Add only the missing ones, after `Deleted` (**line 10**), with the exact strings in the decision. **No `Resolved` case, no reordering, no renaming, no docblock on `values()`.** Record the grep result in the PR.

### 3 — Fix two factories, extend one, create two

#### `File: backend/database/factories/TicketFactory.php` — the fix

Replace **16–31**. Keep `assignedTo()` (**33–36**) as-is.

```php
public function definition(): array
{
    $createdAt = Carbon::instance(fake()->dateTimeBetween('-6 months', '-2 hours'));

    return [
        // Resolved after created_at so the reference year matches the ticket's own year.
        'reference' => fn (array $attributes): string => $this->allocateReference(
            Carbon::parse($attributes['created_at'])->year
        ),
        'subject' => Str::ucfirst(fake()->sentence(6)),
        'description' => fake()->paragraphs(2, true),
        'requester_id' => Requester::factory(),
        'category_id' => fn (): int => $this->requireKey(Category::query(), 'category'),
        'priority_id' => fn (): int => $this->requireDefaultKey(Priority::query(), 'priority'),
        'status_id' => fn (): int => $this->requireDefaultKey(Status::query(), 'status'),
        'created_by' => User::factory()->agent(),
        'assigned_to' => null,
        'escalation_level' => 0,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];
}
```

- **`allocateReference()`** exactly as the decision specifies, with that docblock.
- **`requireKey()` / `requireDefaultKey()`** replace the silent `->value('id')` at **25–27**. Both throw `LogicException("No {$label} exists. Run `php artisan db:seed` to restore master data.")`, mirroring `TicketController::defaultKey():139–147`. **The measured failure they replace is `1048 Column 'category_id' cannot be null`** — a message that names the symptom and hides the cause.
- **`created_at` set explicitly survives `save()`** — measured: set `2026-04-27 12:11:00`, stored identically. `Model::updateTimestamps()` skips `CREATED_AT` when already dirty. **AC4 rests on this.**
- **`assigned_to` stays `null`** — TM-32's unassigned queue needs unassigned to be the *unstated* default, the way `UserFactory` makes `agent` unstated.
- **`created_by` stays an agent**, matching what shipped; `escalated()` uses an admin for `escalated_by`.

States — `assignedTo()` already exists; add:

| State | Sets | For |
|---|---|---|
| `unassigned()` | `assigned_to => null` | TM-32 |
| `inStatus(string $slug)` | `status_id` from the slug; **throws** if unknown | TM-38, TM-40 |
| `withPriority(string $slug)` | `priority_id` from the slug | TM-24 |
| `inCategory(Category $category)` | `category_id` | TM-18, TM-24 |
| `escalated(int $level = 1)` | `escalation_level`, `escalated_at`, `escalated_by` (an **admin**), `escalation_reason` — **all four together** | TM-41, TM-42 |
| `resolved()` | status → `resolved`, `resolved_at`, `first_responded_at` | TM-39 |
| `closed()` | status → `closed`, `resolved_at` **and** `closed_at` | TM-40 |
| `createdAt(CarbonInterface $at)` | `created_at`, `updated_at` — the reference reallocates for that year **automatically**, because it is a closure over `$attributes` | TM-43, TM-29 |
| `stale(int $hours = 72)` | `updated_at` in the past **without** touching `created_at` | TM-43 |

**`escalated()` sets all four columns or none.** `escalation_level > 0` with a null `escalated_at` is a state no code path produces, and `TicketController::index()`'s `escalated` filter (**line 51**) reads only the level — so a half-set row shows up in the filter with no date.

**`stale()` needs a docblock warning.** `37-story-…-TM-43.md:422` records that `save()` overwrites `updated_at`; a factory attribute survives the insert, but a later `->save()` clobbers it. Point at `Ticket::withoutTimestamps()`.

#### `File: backend/database/factories/RequesterFactory.php` — extend

Keep `definition()`'s four keys; tighten the two `optional()` calls (**16–17**) from the implicit 50 % to explicit weights, and add one state:

```php
'phone' => fake()->optional(0.6)->e164PhoneNumber(),
'company' => fake()->optional(0.7)->company(),
```

```php
public function withoutContactDetails(): static
{
    return $this->state(fn (array $attributes): array => ['phone' => null, 'company' => null]);
}
```

**`email` at line 15 is not touched** — `fake()->unique()` is load-bearing.

#### `Create file: backend/database/factories/CategoryFactory.php`

```php
public function definition(): array
{
    $name = fake()->unique()->words(2, true);

    return [
        'name' => Str::title($name),
        'slug' => Str::slug($name),
        'description' => fake()->optional(0.8)->sentence(),
        'color' => fake()->hexColor(),
        'is_active' => true,
        'sort_order' => 0,
    ];
}

public function inactive(): static { … 'is_active' => false … }
public function ordered(int $sortOrder): static { … 'sort_order' => $sortOrder … }
```

**`name` and `slug` are both `UNIQUE`** (`…create_categories_table.php:16–17`) and must derive from the same words, or a retry produces a mismatched pair. **`color` is `char(7)`**: `fake()->hexColor()` returns exactly `#rrggbb`, and a longer value fails `1406` under `STRICT_TRANS_TABLES` (measured `sql_mode`). **`is_active` is hardcoded `true`, not `fake()->boolean()`** — `Category::scopeActive` (**26–29**) filters on it and `TicketController::index()` joins through it, so a randomly deactivating factory would make TM-19's and TM-24's tests flaky.

#### `Create file: backend/database/factories/TicketActivityFactory.php`

```php
public function definition(): array
{
    return [
        'ticket_id' => Ticket::factory(),
        'user_id' => User::factory(),
        'event' => TicketActivityEvent::Created,
        'field' => null,
        'old_value' => null,
        'new_value' => null,
        'meta' => [],
        'created_at' => Carbon::instance(fake()->dateTimeBetween('-6 months', '-1 hour')),
    ];
}
```

States: `created()`, `bySystem()` (`user_id => null` — the contract Story 38 documents), `statusChange(string $from, string $to)` (`field => 'status'`, `old_value`, `new_value`), `assignment(User $assignee)`, `escalation(string $reason)`, `at(CarbonInterface $when)`.

- **`meta` defaults to `[]`, never `null`.** `ActivityRecorder`'s `json_encode(null)` footgun is Story 38's to fix; a factory defaulting to `[]` cannot reach it.
- **Every state that names an `event` uses a `TicketActivityEvent` case, never a string** — measured `ValueError` on read otherwise.

#### `File: backend/database/factories/UserFactory.php` — one state

Append after `inactive()` (**line 62**):

```php
public function withName(string $name): static
{
    return $this->state(fn (array $attributes): array => [
        'name' => $name,
        'email' => Str::slug($name).'@'.config('seeding.demo.email_domain'),
    ]);
}
```

`DemoSeeder` uses it so demo logins are typeable (`ada.lovelace@demo.test`) instead of Faker noise. **`definition()` (26–37) and the four existing states are untouched.**

### 4 — Configure the demo dataset

**File: `backend/config/seeding.php`** — add beside the `admin` block (**4–8**):

```php
'demo' => [
    'email_domain' => env('DEMO_EMAIL_DOMAIN', 'demo.test'),
    'password' => env('DEMO_PASSWORD', 'password'),
    'admins' => (int) env('DEMO_ADMINS', 2),
    'agents' => (int) env('DEMO_AGENTS', 6),
    'tickets' => (int) env('DEMO_TICKETS', 50),
    'months' => (int) env('DEMO_MONTHS', 6),
],
```

**File: `backend/.env.example`** — after the `TICKETS_STALE_AFTER_HOURS` block:

```
# Demo dataset created by `php artisan db:seed --class=DemoSeeder` (TM-59).
# Never runs in production — the seeder throws, and --force does not bypass it.
DEMO_EMAIL_DOMAIN=demo.test
DEMO_PASSWORD=password
DEMO_ADMINS=2
DEMO_AGENTS=6
DEMO_TICKETS=50
DEMO_MONTHS=6
```

`'tickets' => 50` is *"roughly fifty"* made explicit; configurability is what lets tests 19–21 run 14 tickets and stay fast.

### 5 — `Create file: backend/database/seeders/DemoSeeder.php`

```php
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->guardEnvironment();     // production => RuntimeException
        $this->guardEmptyTickets();    // existing tickets => RuntimeException
        $this->call([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);

        $admins = $this->seedAdmins();
        $agents = $this->seedAgents();
        $requesters = $this->seedRequesters();

        DB::transaction(fn () => $this->seedTickets($admins, $agents, $requesters));
    }
}
```

**`seedTickets()` runs inside one transaction** for two reasons: `TicketReferenceGenerator` and `ActivityRecorder` both require one, and `docs/erd.md:77` — *"FULLTEXT index updates at commit; invisible inside open transaction"* — means one commit is one index build.

**`seedAdmins()`** — `config('seeding.demo.admins')` users via `UserFactory->admin()->withName(...)` from a fixed name list, password `config('seeding.demo.password')`. **Does not call `AdminUserSeeder`**: its `blank($password)` throw (**17–19**) would abort the demo seed whenever `ADMIN_PASSWORD` is unset, which is exactly the test environment.

**`seedAgents()`** — `config('seeding.demo.agents')` agents from a fixed name list. **The last is `->inactive()`.** An inactive agent is what proves the `active` middleware and TM-12's toggle are real, and it must never be an assignee.

**`seedRequesters()`** — `RequesterFactory` × `ceil(tickets / 2)`, so some requesters own more than one ticket. Two get `->withoutContactDetails()`, so a null `phone`/`company` renders on the detail page.

**`seedTickets()`** — the core:

```php
$statuses   = Status::ordered()->get();               // 7 rows, StatusSeeder order
$priorities = Priority::ordered()->get();             // 4 rows, ascending level
$categories = Category::query()->ordered()->get();    // the 6 seeded rows

$status   = $statuses[$i % $statuses->count()];
$priority = $priorities[$i % $priorities->count()];
$category = $categories[$i % $categories->count()];
```

**Round-robin, not random, and the arithmetic is the guarantee.** Measured for 50 / 7 / 4: **7 distinct statuses, 4 distinct priorities, 28 distinct pairs.** AC2's *"across every status and priority"* becomes a property of the loop. An `inRandomOrder()` version passes locally and fails in CI roughly one run in a hundred, reading as a flaky test rather than a missing guarantee.

**Dates (AC4)** — deterministic spread with jitter, so `DEMO_MONTHS` is the real span:

```php
$span = config('seeding.demo.months') * 30;                      // days
$daysAgo = (int) round($span * (1 - $i / max(1, $count - 1)));   // $span … 0
$createdAt = now()->subDays($daysAgo)->subMinutes(fake()->numberBetween(0, 1439));
```

Ticket 0 is ~6 months old, the last is hours old, every month between has tickets. **`fake()->dateTimeBetween()` alone is wrong here** — over 50 draws it leaves visible gaps, and AC4 asks for date filters and age columns to be *meaningful*. Pass `$createdAt` through `TicketFactory::createdAt()` so the reference year follows.

**Per-ticket state, derived from the status — the table the seeder encodes:**

| Status slug | `assigned_to` | `first_responded_at` | `resolved_at` | `closed_at` | Activities after `created` |
|---|---|---|---|---|---|
| `new` | **null** | null | null | null | — |
| `open` | agent | +2–8 h | null | null | `assigned` |
| `in-progress` | agent | +1–4 h | null | null | `assigned`, → `in-progress` |
| `pending` | agent | +1–6 h | null | null | `assigned`, → `pending` |
| `resolved` | agent | +1–4 h | +1–5 d | null | `assigned`, → `resolved` |
| `closed` | agent | +1–4 h | +1–5 d | resolved +1–3 d | `assigned`, → `resolved`, → `closed` |
| `reopened` | agent | +1–4 h | **null** | null | `assigned`, → `resolved`, → `closed`, → `reopened` |

- **`new` tickets stay unassigned** — that is TM-32's queue, and `GET /tickets?assignee=unassigned` (`TicketController.php:49`) already reads it.
- **`reopened` clears `resolved_at`.** A reopened ticket still reporting a resolution date is the single most wrong-looking row the dataset could contain, and TM-40 is explicit that reopening clears it.
- **Assignees come from active agents only.** `assigned_to` is `nullOnDelete`, not restricted, so nothing at the schema level stops an inactive assignee — the seeder is the guard.
- **Escalation: every 7th ticket not in a `done`-bucket status** gets `escalation_level => fake()->numberBetween(1, 2)`, `escalated_at` between `created_at` and now, `escalated_by` an **admin**, and one of a fixed list of reasons. Escalating a resolved ticket is incoherent; `Status::bucket === StatusBucket::Done` is the test.

Every timestamp is clamped: `first_responded_at ≤ resolved_at ≤ closed_at`, all `≥ created_at`, all `≤ now()`. Set `updated_at` to the ticket's own latest event, so `GET /tickets?sort=updated_at` and TM-43's staleness window both see something real.

### 6 — Activity histories go through `ActivityRecorder`, not `TicketActivityFactory`

**The one place `DemoSeeder` deliberately does not use a factory.** Story 38's AC3 makes `ActivityRecorder` the only writer to `ticket_activities`, and its task 21 ships a tripwire scanning `app/` for other write idioms.

- The tripwire scans `app_path()`, so a seeder writing directly would not trip it — **but the rule is the rule**, and a demo dataset built by a path production never uses proves nothing.
- `record()` is already inside the transaction `seedTickets()` opens, satisfying the guard at `ActivityRecorder.php:26–28`.
- The `created` row copies `TicketController.php:130` exactly: `['user_id' => $creatorId, 'meta' => ['reference' => $ticket->reference]]`.

**`ActivityRecorder::record()` stamps `created_at` with `now()`** (**line 29**), so back-dated history needs `Carbon::setTestNow()` around each call:

```php
try {
    Carbon::setTestNow($occurredAt);
    $recorder->record($ticket->getKey(), $event, [...]);
} finally {
    Carbon::setTestNow();
}
```

**The reset must be in a `finally`.** A leaked test-now freezes every later `now()` in the process — silently — and inside a test run it corrupts unrelated tests. `TicketActivityFactory` exists for tests, which is what AC1 asks of it; the seeder uses the production writer.

### 7 — No changes to `DatabaseSeeder.php`

Stated as a task so it is a decision, not an omission. `DemoSeeder` is **not** added to the `call()` array at **17–22**, and test 18 proves it behaviourally.

### 8 — Collapse the `makeTicket()` / `ticketAt()` helpers if any exist

```bash
grep -rn "function makeTicket\|function ticketAt" backend/tests/
```

**Measured today: no output** — none of Stories 31, 39, 40, 41, 42 has landed. This task is a no-op the PR records. If hits appear, replace each helper's **body** with a `TicketFactory` call and delete the helper.

**Read `ticket-history-audit-trail/42-story-…-TM-49.md:135` first and check the visibility keyword on every hit.** Those helpers are `private` on purpose: a `protected` method on `Tests\TestCase` is a **fatal error at class-declaration time** against a subclass declaring it `private`, with an error that looks nothing like its cause. Replacing bodies is safe; hoisting is not.

### 9 — Document the demo walkthrough

**File: `README.md`** — after **line 65**, a short subsection:

```bash
php artisan migrate:fresh --seed                    # schema + admin + master data
php artisan db:seed --class=DemoSeeder              # ~50 demo tickets
```

State three things: it is **local only** and throws in production; it **refuses to run twice**; the demo logins are `config('seeding.demo.email_domain')` with `DEMO_PASSWORD`. **Do not touch `docs/deployment-runbook.md`** (24 lines, **TM-63** owns it), `docs/api-contract.md` (127 lines, **TM-62**) or `docs/erd.md` (79 lines — `ticket_activities` is missing from it, and **Story 38** adds it).

### No frontend changes required.

The demo data is visible through the six ticket routes already registered at `routes/api.php:44–49`. **No `frontend/` file is touched.**

---

## Edge Cases & Failure Modes

- **The live reference collision.** Trigger: any factory setting `reference` without going through `ticket_sequences` — **the code on disk today**. Behaviour: the next `POST /api/v1/tickets` 500s with `1062 Duplicate entry … for key 'tickets.tickets_reference_unique'` (**measured**). Fixed by `allocateReference()`; test 6 and test 28 are the regressions. **Nothing in CI catches it today because no test uses `TicketFactory`.**
- **`Ticket::factory()->make()` with no database.** `definition()` allocates from `ticket_sequences`, so `make()` needs a connection. Every test here has one (`phpunit.xml:27–42` forbids SQLite) and `tests/Unit/` currently calls no factory — but a future unit test will. Documented in `allocateReference()`'s docblock; **not** worked around, because the alternative is the collision above.
- **Master data absent.** Trigger: `Ticket::factory()->create()` on a migrated-but-unseeded database. **Current behaviour: `1048 Column 'category_id' cannot be null`** (measured) — names the symptom, hides the cause. New behaviour: `LogicException` naming `php artisan db:seed`, matching `TicketController::defaultKey():142–144`. `DemoSeeder` avoids it entirely by calling the three master seeders itself; all three are idempotent, so calling them when the data exists is a no-op.
- **A raw event string.** `ticket_activities.event` is `varchar(50)`, so MySQL accepts `'assigned'` — and `TicketActivity::first()->event` then throws `ValueError: "assigned" is not a valid backing value` (**measured**). With `GET /api/v1/tickets` live (`routes/api.php:44`), that is a **500 on the list endpoint**, not a quiet data smell. Prevented by task 2's grep and by every factory state taking a `TicketActivityEvent`. Test 13.
- **`is_default_unique` in a factory `definition()`.** `ERROR 3105`, surfacing as a 500 (**measured**). Prevented structurally: no `PriorityFactory`, no `StatusFactory`. Test 17 pins that both models still lack `HasFactory`, so a future contributor has to read the decision first.
- **`config()->set('app.env', 'production')` does not change the environment.** Measured: `app()->environment()` still returns `testing`. A guard test written that way **passes without executing the guard** — a green test over an unprotected production path, the worst available outcome. Test 20 uses `app()->detectEnvironment(fn () => 'production')` and restores it in `tearDown`.
- **`db:seed --force` in production.** `ConfirmableTrait.php:26–29` returns `true` before any prompt. Behaviour: `DemoSeeder::run()` throws as its first statement. Test 20; verification step 11 runs it **with `--force`**, because without the flag you are only testing the prompt.
- **A second demo run.** 100 tickets, 12 agents, and a possible `1062` on `requesters.email` (Faker's `unique()` is per-process). Behaviour: `RuntimeException` naming `migrate:fresh --seed`. **`Ticket::withTrashed()->exists()`**, not `Ticket::exists()` — `DELETE /tickets/{ticket}` soft-deletes, so a soft-deleted demo set must still block. Test 21.
- **`Carbon::setTestNow()` left set.** Trigger: an exception between `setTestNow($occurredAt)` and the reset. Behaviour: every later `now()` in the process frozen in the past — silent, and in a test run it corrupts unrelated tests. Prevented by `try`/`finally`. Test 24 asserts `Carbon::hasTestNow()` is `false` after the seeder returns.
- **An inactive agent as assignee.** Nothing in the schema forbids it (`assigned_to` is `nullOnDelete`), and the `active` middleware would then reject the person holding 8 tickets. Prevented in `seedTickets()`, which draws only from active agents. Test 26.
- **A `reopened` ticket with a non-null `resolved_at`.** Incoherent, and the most visible wrong row in a demo. Prevented by the status table in task 5. Test 23.
- **`escalation_level > 0` with a null `escalated_at`.** No code path produces it, and `TicketController::index()`'s `escalated` filter (**line 51**) reads only the level — so the row appears in the filter with no date to show. `escalated()` sets all four together. Test 23.
- **A 16-character reference.** `char(15)` under `STRICT_TRANS_TABLES` (measured `sql_mode`) → `QueryException: SQLSTATE[22001] … 1406 Data too long for column 'reference'`. `'TKT-%04d-%06d'` is exactly 15; `TicketReferenceGenerator.php:23–25` caps the sequence at 999999. **The factory's current `'TKT-%d-%06d'` diverges from that format** and task 3 removes it.
- **The FULLTEXT index inside the transaction.** `docs/erd.md:77`: *"FULLTEXT index updates at commit; invisible inside open transaction."* A test that seeds and then full-text-searches **inside the same `RefreshDatabase` transaction finds nothing**, and the failure reads as a broken query rather than a visibility rule. `TicketSearch` and `GET /tickets?q=` are live, so **TM-60 will hit this**; recorded here, not fixed. Measured for scale: 50 bulk ticket inserts commit in **80 ms**.
- **Faker `unique()` exhaustion.** Throws `OverflowException` after 10 000 retries. Measured: 200 `safeEmail()` draws gave 200 distinct values. At `DEMO_TICKETS=50` (25 requesters) there is no risk; a contributor setting `DEMO_TICKETS=100000` will find out, and the config comment does not pretend otherwise.
- **`--parallel` and the static counter.** Not configured here (`composer test` is `artisan test`), but two processes both start `self::$sequence` at `0`. Task 3 deletes the static entirely rather than papering over it — recorded so nobody reintroduces one when adding parallel testing.
- **A future model observer emailing 50 requesters.** `use WithoutModelEvents;` mutes Eloquent model events, matching `DatabaseSeeder.php:10`. E8's notifications dispatch from controllers, which the seeder never enters, so **no mail is produced today** — the trait is what keeps that true after TM-52…TM-57. Test 27.

---

## Test Plan

**This story creates `backend/tests/Feature/Database/FactoriesTest.php` and `DemoSeederTest.php`** — the first tests `RequesterFactory` and `TicketFactory` have ever had. Style from `RequestersTableSchemaTest` (schema assertions) and `AdminUserSeederTest` (seeder setup, `RefreshDatabase` at line 15).

Both classes use `RefreshDatabase`; both seed `CategorySeeder`, `PrioritySeeder` and `StatusSeeder` **explicitly** in `setUp()` — **`$this->seed()` with no argument throws in this suite**, because `AdminUserSeeder` aborts on an empty `ADMIN_PASSWORD` (**17–19**) and `phpunit.xml` never sets one.

`DemoSeederTest::setUp()` also sets `config(['seeding.demo.tickets' => 14, 'seeding.demo.agents' => 4, 'seeding.demo.admins' => 2])`. **14, not 50** — `14 = 2 × 7`, so full status coverage still holds and the class runs in about a second. Tests 22, 23 and 25 override back to 50.

### `backend/tests/Feature/Database/FactoriesTest.php` (new)

1. `test_every_named_model_has_a_factory` — **AC1.** `User`, `Requester`, `Category`, `Ticket`, `TicketActivity` each return a `Factory`. **`Category` and `TicketActivity` throw `BadMethodCallException` before task 1.**
2. `test_priority_and_status_have_no_factory` — the deliberate omission. Both throw `BadMethodCallException`. **Comment it with the `ERROR 3105` reason**, so it reads as a decision rather than a gap.
3. `test_requester_factory_produces_unique_persistable_contacts` — 25 requesters in one call; 25 distinct emails, no exception. `withoutContactDetails()` gives null `phone` and `company`.
4. `test_category_factory_keeps_name_and_slug_in_step` — 10 categories; `Str::slug($name) === $slug` for every one, all `is_active` true, every `color` exactly **7** characters.
5. `test_ticket_factory_fills_every_not_null_column` — one ticket; `reference`, `requester_id`, `category_id`, `priority_id`, `status_id`, `created_by` non-null, `escalation_level` `0`, `assigned_to` **null**.
6. `test_ticket_factory_references_come_from_the_sequence_table` — **the collision regression, and the most important test in this story.** Create 5 tickets; assert `ticket_sequences` has a row whose `next_number` equals the allocations (it is **empty** today). Then `DB::transaction(fn () => app(TicketReferenceGenerator::class)->next())` and assert the result is **not** among the five **and inserts cleanly**. **Against the code on disk this fails with `1062`.**
7. `test_ticket_factory_reference_year_matches_created_at` — `createdAt(now()->subYear())`; the reference starts `TKT-` + that year. Guards the detail that makes the dataset believable, and the reason `reference` is a closure over `$attributes`.
8. `test_ticket_factory_spreads_created_at_into_the_past` — 20 tickets; **at least 15 distinct `created_at` values** and the oldest more than 30 days ago. **Against the code on disk this fails**: `definition()` sets no `created_at`, so all 20 are `now()`. AC4's regression at the factory level.
9. `test_ticket_factory_honours_an_explicit_created_at` — a fixed past instant round-trips exactly. Pins the `Model::updateTimestamps()` behaviour AC4 depends on.
10. `test_ticket_factory_works_inside_and_outside_a_transaction` — one `create()` at top level, one inside `DB::transaction(...)`; both succeed with distinct references. Covers both `allocateReference()` branches.
11. `test_ticket_factory_names_the_missing_master_data` — delete all categories, then `Ticket::factory()->create()` → `LogicException` whose message contains `db:seed`. **Against the code on disk this fails with `1048 Column 'category_id' cannot be null`.**
12. `test_escalated_state_sets_all_four_columns` — `escalation_level >= 1` **and** `escalated_at`, `escalated_by`, `escalation_reason` all non-null, `escalated_at >= created_at`.
13. `test_resolved_and_closed_states_set_their_timestamps` — `resolved()` → `resolved_at` non-null, `closed_at` **null**, slug `resolved`. `closed()` → both non-null with `closed_at >= resolved_at`, slug `closed`.
14. `test_in_status_rejects_an_unknown_slug` — `inStatus('nope')` throws, naming the slug. A typo silently falling back to the default status would make TM-38's tests assert against the wrong row.
15. `test_ticket_activity_factory_only_ever_writes_known_events` — one activity per `TicketActivityEvent::cases()` plus each named state; read every distinct `event` back with `DB::table(...)` and assert `TicketActivityEvent::tryFrom()` is non-null, **and that reading through the model does not throw**. The `ValueError` regression.
16. `test_ticket_activity_factory_attaches_to_a_ticket` — `TicketActivity::factory()->for($ticket)` stores the right `ticket_id`. **If Story 38 has landed**, also assert `$ticket->activities` has one row; otherwise query `TicketActivity::query()->where('ticket_id', …)` and **say which in the PR**.
17. `test_ticket_activity_factory_defaults_meta_to_an_array_not_null` — read the raw column with `DB::table(...)->value('meta')` **as well as** through the cast; it is `[]` and specifically **not** the string `"null"`. The cast hides the difference, which is why the raw read is there.
18. `test_by_system_state_leaves_the_actor_null` — `bySystem()` → `user_id` null, row persists. The contract Story 38 documents as "the system acted".
19. `test_the_generated_columns_are_still_unreachable` — `Schema::hasColumn('priorities', 'is_default_unique')` is true, and `DB::table('priorities')->insert([… 'is_default_unique' => 1])` raises `QueryException`. **The tripwire for TM-16's warning** — it fails the day someone adds a `PriorityFactory` touching it.

### `backend/tests/Feature/Database/DemoSeederTest.php` (new)

20. `test_the_production_seeder_creates_no_demo_data` — **AC5's "separate from" half.** `config()->set('seeding.admin', […, 'password' => 'x'])` (the `AdminUserSeederTest:17–21` shape), run `DatabaseSeeder`, assert **0** tickets, **0** requesters, **0** activities and exactly **1** user. Behavioural, not a source scan — it fails if anyone adds `DemoSeeder::class` to that `call()` array.
21. `test_it_creates_the_configured_people` — 2 admins, 4 agents, **exactly one inactive**, every demo email on `config('seeding.demo.email_domain')`, and `Hash::check(config('seeding.demo.password'), …)` on one of them. A demo you cannot log into is not a demo.
22. `test_it_refuses_to_run_in_production` — **AC5's "never runs" half.** `app()->detectEnvironment(fn () => 'production')`, expect `RuntimeException`, assert **0** tickets, restore in `tearDown`. **Do not use `config()->set('app.env', …)`** — measured not to change `app()->environment()`, so that version passes over an unprotected path.
23. `test_it_refuses_a_second_run` — seed, seed again → `RuntimeException` naming `migrate:fresh`. Then soft-delete every ticket and seed again → **still throws**, proving `withTrashed()`.
24. `test_fifty_tickets_cover_every_status_and_every_priority` — **AC2, at the real count.** `DEMO_TICKETS=50`; `Ticket::count()` is 50, `distinct status_id` is **7**, `distinct priority_id` is **4**, `distinct category_id` is **6**, and at least one ticket has `assigned_to` null and at least one non-null.
25. `test_every_ticket_is_internally_consistent` — **AC3's plausibility, as an invariant sweep over all 50.** For every ticket:
    - status bucket `done` ⟺ `resolved_at` non-null, **except** slug `reopened`, which has `resolved_at` **null**;
    - `closed_at` non-null ⟺ slug is `closed`, and then `closed_at >= resolved_at`;
    - `escalation_level > 0` ⟺ `escalated_at`, `escalated_by`, `escalation_reason` all non-null;
    - `first_responded_at`, `resolved_at`, `closed_at`, `escalated_at` each **≥ `created_at`** and **≤ `now()`**;
    - slug `new` ⟹ `assigned_to` null;
    - at least one ticket has `escalation_level > 0`, and **no escalated ticket sits in a `done` bucket**.
    Assert with a message naming the offending `reference`, or the failure is unreadable.
26. `test_activity_histories_are_ordered_and_typed` — **AC3.** Every ticket has ≥ 1 activity; every ticket's **first** activity is `Created`; every activity `created_at` ≥ its ticket's and ≤ `now()`; the dataset contains at least one `assigned`, at least one `escalated`, and at least one `status_changed` with `new_value = 'resolved'`. Also assert **`Carbon::hasTestNow()` is `false`** after the seeder returns — the leak.
27. `test_created_dates_span_the_configured_months` — **AC4.** With `DEMO_MONTHS=6` and 50 tickets: oldest `created_at` **more than 150 days** ago, newest **less than 2 days** ago, and each of the **6** calendar months in the window holds at least one ticket. A `dateTimeBetween` implementation with a bad seed fails the third clause — the one AC4 actually asks for.
28. `test_no_assignee_is_inactive` — every non-null `assigned_to` resolves to a user with `is_active` true. The one invariant nothing in the schema enforces.
29. `test_seeding_sends_no_mail_and_queues_no_job` — **0** messages on the `array` transport (`phpunit.xml:44`) and **0** rows in `jobs`. Cheap now, and the tripwire that catches TM-52…TM-57 emailing 50 requesters on every demo seed.
30. `test_the_api_still_works_on_a_seeded_database` — **the end-to-end proof test 6 is about, and it exercises endpoints that only exist because E4 landed.** Seed, then as a demo admin's token: `POST /api/v1/tickets` → **201** with a reference distinct from all 50; `GET /api/v1/tickets` → **200** (this is where a raw event string or a `ValueError` would surface); `GET /api/v1/tickets/stats` → **200**. **Run this one first.**

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql` healthy on **3306**, `tm-mysql-test` on **3307**.
2. **Confirm the starting point, from `backend/`:**
   - `ls database/factories/` → `RequesterFactory.php  TicketFactory.php  UserFactory.php`.
   - `grep -rln "Ticket::factory\|Requester::factory\|TicketFactory" tests/` → **no output.** Two factories, zero tests.
   - `grep -n "static int \$sequence" database/factories/TicketFactory.php` → **line 16.** The bug.
   - `grep -n "case Assigned\|case StatusChanged\|case Escalated" app/Enums/TicketActivityEvent.php` → **no output.**
   - `php artisan tinker --execute="App\Models\Category::factory();"` → **`BadMethodCallException`**.
3. **Backend formats:** `./vendor/bin/pint --test` → exit `0`.
4. **Backend tests:** `composer test`. Expect **+30 tests** (109 → 139) and **3 failures, not 4** — the same three from the baseline, none in a file this story touched.
5. **Prove the bug was real, before fixing it.** Write test 6 first and run `php artisan test --filter=test_ticket_factory_references_come_from_the_sequence_table` **against the unmodified `TicketFactory`** → it **fails with `1062 Duplicate entry`**. Do the same with test 8 (all `created_at` equal) and test 11 (`1048 Column 'category_id' cannot be null`). **Paste all three failures into the PR** — they are the evidence this story was a fix, not a refactor.
6. **Prove test 22 earns its place.** Rewrite it as `config()->set('app.env', 'production')` and confirm it **passes** (the false green); then confirm the `detectEnvironment` version **fails** when the guard is commented out. Restore both.
7. **Prove test 15 earns its place.** Change one `TicketActivityFactory` state to a raw `'assigned'` string, run `--filter=test_ticket_activity_factory_only_ever_writes_known_events`, confirm the `ValueError`. Restore.
8. **Prove test 20 earns its place.** Add `DemoSeeder::class` to `DatabaseSeeder`'s `call()` array, run `--filter=test_the_production_seeder_creates_no_demo_data`, confirm it **fails**. Restore.
9. **Backend seeds, by hand, from `backend/`** — the acceptance walkthrough:
   ```bash
   php artisan migrate:fresh --seed
   php artisan db:seed --class=DemoSeeder
   ```
   Then, against `tm-mysql` on **3306**:
   - `SELECT COUNT(*) FROM tickets;` → **50**.
   - `SELECT s.slug, COUNT(*) FROM tickets t JOIN statuses s ON s.id=t.status_id GROUP BY s.slug ORDER BY s.sort_order;` → **7 rows**, none zero.
   - `SELECT p.slug, COUNT(*) FROM tickets t JOIN priorities p ON p.id=t.priority_id GROUP BY p.slug ORDER BY p.level;` → **4 rows**, none zero.
   - `SELECT MIN(created_at), MAX(created_at), COUNT(DISTINCT DATE_FORMAT(created_at,'%Y-%m')) FROM tickets;` → span **≈ 6 months**, distinct months **6**.
   - `SELECT reference, resolved_at FROM tickets t JOIN statuses s ON s.id=t.status_id WHERE s.slug='reopened';` → `resolved_at` **NULL** on every row.
   - `SELECT COUNT(*) FROM tickets WHERE escalation_level > 0 AND (escalated_at IS NULL OR escalated_by IS NULL OR escalation_reason IS NULL);` → **0**.
   - `SELECT event, COUNT(*) FROM ticket_activities GROUP BY event;` → `created` **50**, plus `assigned`, `status_changed` and `escalated`.
   - `SELECT COUNT(*) FROM ticket_activities a JOIN tickets t ON t.id=a.ticket_id WHERE a.created_at < t.created_at;` → **0**.
   - `SELECT * FROM ticket_sequences;` → a row for each year the dataset spans, `next_number` summing to ≥ 50. **It is empty today** — that is the bug, visible in one query.
10. **Prove the seeder is re-run safe:** `php artisan db:seed --class=DemoSeeder` again → **`RuntimeException`** naming `migrate:fresh --seed`, and `SELECT COUNT(*) FROM tickets;` still **50**.
11. **Prove the production guard, for real:** `APP_ENV=production php artisan db:seed --force --class=DemoSeeder` → **`RuntimeException`**, no rows written. **Run it with `--force`** — without the flag you are only testing artisan's prompt, which is the thing that does not protect you.
12. **Regression, by hand, against the live API.** `php artisan serve`, then with an admin token:
    - `POST /api/v1/tickets` → **201**, `reference` not one of the 50. **This is the request that 500s today.**
    - `GET /api/v1/tickets?per_page=50` → **200**, 50 records, no `ValueError`.
    - `GET /api/v1/tickets?escalated=1` → only tickets with a non-null `escalated_at`.
    - `GET /api/v1/tickets?assignee=unassigned` → only `new` tickets.
    - `GET /api/v1/tickets?sort=created_at&direction=asc` → oldest first, ~6 months back.
    - `GET /api/v1/tickets/stats` → **200**.
13. **Frontend, unchanged but worth looking at:** `cd frontend && npm run dev`, open `/tickets/1`. **No frontend file is edited** — this step is for the PR screenshot.
14. **Regression:** `git status` shows **no migration**, nothing under `frontend/`, and no change to `routes/api.php`, `bootstrap/app.php`, `DatabaseSeeder.php`, `docs/`, any controller or any policy. Files touched: `Category.php`, `TicketActivity.php`, `UserFactory.php`, `RequesterFactory.php`, `TicketFactory.php`, two new factories, `DemoSeeder.php`, `config/seeding.php`, `.env.example`, `README.md`, two new test files — and `TicketActivityEvent.php` **only** for the missing cases task 2's grep names.

---

## Done Criteria

- [ ] **`TicketFactory` no longer invents references.** The `private static int $sequence` at line 16 is **gone**, allocation goes through `TicketReferenceGenerator` against `ticket_sequences`, and the year comes from the ticket's own `created_at`. A test proves the first API-filed ticket after a seeded run succeeds, **and the same test fails with `1062` against the code that shipped before this story** — pasted into the PR.
- [ ] **`TicketFactory` sets `created_at`**, spread into the past and overridable, so AC4 has something to be true about. The regression fails against the previous code with all timestamps equal.
- [ ] **`TicketFactory` names missing master data** instead of surfacing `1048 Column 'category_id' cannot be null`, mirroring `TicketController::defaultKey()`'s message.
- [ ] `User`, `Requester`, `Category`, `Ticket` and `TicketActivity` all answer `::factory()`; **`Priority` and `Status` still throw**, with a test and a comment recording the `ERROR 3105` generated column as the reason.
- [ ] `HasFactory` added to exactly **two** models (`Category`, `TicketActivity`) with the `@use` docblock matching `User.php:23–24`; **`Requester.php:13` and `Ticket.php:17` keep the one they already have**, and no model gets a duplicate.
- [ ] `UserFactory::definition()` and its four existing states are **untouched** — every `tests/Feature/Auth/` test depends on them — and exactly one state (`withName`) is appended. `RequesterFactory`'s `email` line is untouched.
- [ ] `TicketFactory` ships the nine new states named in task 3 alongside the existing `assignedTo()`; `escalated()` sets **all four** escalation columns together; `resolved()`/`closed()` set their timestamps in order; `inStatus()` **throws** on an unknown slug rather than falling back.
- [ ] `TicketActivityFactory` never produces a raw event string, defaults `meta` to `[]` **and not the string `"null"`** (proven against the raw column), and offers `bySystem()` leaving `user_id` null.
- [ ] `DemoSeeder` creates 2 admins, 6 agents with **exactly one inactive**, requesters with some missing contact details, and **50 tickets covering all 7 statuses, all 4 priorities and all 6 categories** — coverage guaranteed by a round-robin, **not a random draw**.
- [ ] Ticket state is internally consistent across the whole dataset: `done` ⟺ `resolved_at` **except `reopened`**, `closed_at` only when closed, escalation columns all-or-nothing, every derived timestamp between `created_at` and `now()`, `new` tickets unassigned, and **no escalated ticket in a `done` bucket**.
- [ ] Every ticket has a history whose first row is `created`, whose rows are never earlier than the ticket, and which contains `assigned`, `escalated` and a `status_changed` to `resolved` somewhere in the dataset — written through **`ActivityRecorder`**, not the factory, with `Carbon::setTestNow()` reset in a `finally` and asserted unset afterwards.
- [ ] `created_at` spans the configured months with **at least one ticket in each of the six** — the clause a naive `dateTimeBetween` fails.
- [ ] **`DemoSeeder` throws in production**, tested through `app()->detectEnvironment()` and **not** `config()->set('app.env', …)`, which is measured not to change the environment. Verified by hand with **`--force`**, because `ConfirmableTrait.php:26–29` makes `--force` bypass artisan's prompt entirely.
- [ ] **`DemoSeeder` is not in `DatabaseSeeder`**, proven by running `DatabaseSeeder` and finding zero tickets, zero requesters and one user. `DatabaseSeeder.php` is byte-identical.
- [ ] A second `DemoSeeder` run **throws** and names `migrate:fresh --seed`; soft-deleted tickets still count, via `withTrashed()`.
- [ ] Seeding sends **no mail** and queues **no job**.
- [ ] `POST /tickets`, `GET /tickets`, the `escalated` and `assignee=unassigned` filters, `sort=created_at` and `GET /tickets/stats` all work against the seeded database, checked by test and by hand.
- [ ] The demo dataset is configurable via `config/seeding.php`'s `demo` block with every variable documented in `backend/.env.example`, and `README.md` documents the two-command walkthrough after **line 65** — **`docs/erd.md`, `docs/api-contract.md` and `docs/deployment-runbook.md` are untouched.**
- [ ] Task 2's grep result is in the PR: which `TicketActivityEvent` cases this story had to add — **with the exact strings from Stories 26, 32 and 35, and no `Resolved` case**, because resolution is a `status_changed`.
- [ ] Task 8's grep result is in the PR: **no `makeTicket()` / `ticketAt()` helper exists today**, so none was collapsed. If any appeared, its **body** was replaced and it stayed `private` — a `protected` parent method is a fatal error.
- [ ] **No migration, no schema change, no endpoint, no route, no policy, no frontend file, no new dependency**, and **none of the three baseline failures touched.**
- [ ] `pint --test` clean; **+30 backend tests (109 → 139)**; exactly **3** pre-existing failures remain, all three named and none in a file this story touched.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 51 (TM-60, backend feature test suite).**
