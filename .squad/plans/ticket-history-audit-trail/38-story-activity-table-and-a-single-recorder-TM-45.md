# Story 38 — Activity table and a single recorder (Story: TM-45)

## Prerequisites

- **Most of this story's schema already exists, and none of it is tested.** `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php`, `backend/app/Models/TicketActivity.php`, `backend/app/Enums/TicketActivityEvent.php` and `backend/app/Services/ActivityRecorder.php` are all on disk, written by TM-21/TM-22 as scaffolding for ticket creation. **`grep -rln "ticket_activities\|TicketActivity\|ActivityRecorder" backend/tests/` returns nothing** — the audit trail that is supposed to be *"complete by construction rather than by good intentions"* has **no test at all**. Closing that is the bulk of this story.
- **AC3's second clause conflicts with eight planned stories, and is not delivered.** `backend/app/Services/` contains `ActivityRecorder.php` and `TicketReferenceGenerator.php`; **there is no `TicketService`**, and today's two callers are `TicketController::store()` (**line 44**) and `CategoryController::reassignTickets()` (**line 91**). Stories 26, 27, 29, 32, 34, 35 and 37 are all planned with the recorder called from a controller. **Read the decision section before writing anything** — the clause is deliberately not implemented, and that is raised rather than hidden.
- **Story 33 (TM-39) and Story 34 (TM-40) — PLANNED, NOT IMPLEMENTED, and each adds a relation this story should own.** Story 33's task 5 adds `TicketActivity::user()`; Story 34's task 4 adds `Ticket::activities()`. **Both belong to the model this story owns.** Task 5 adds them here; **if either has already landed, skip that half and do not add a second.**
- **Story 39 (TM-46, the timeline) does not exist and is not planned.** AC5's *"the UI renders it as such"* has no consumer: there is no timeline component and no `GET /tickets/{ticket}/activities`. This story delivers and documents the **data** half of that criterion; see the decision section.
- **Story 40 (TM-48, append-only) is a separate story and this one does not start it.** Blocking updates and deletes on the model is E7-S4's second criterion. Task 7's single-writer test is a **tripwire**, not the structural guarantee TM-48 adds.
- **Measured baseline, 2026-08-26, from `backend/`.** `composer test` → **101 tests, 289 assertions, 98 passing, 3 failing**; `./vendor/bin/pint --test` exits `0`. **This story fixes one of the three:**
  1. `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` — `429` vs `422`. **TM-14 owns it.**
  2. `Authorization\RouteAuthorizationTest::test_every_api_route_is_classified` — `ACCESS` has no `tickets.store` key. **Story 32 (TM-38) adopts it.**
  3. `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` — **this story fixes it**, because its root cause is the same guard AC6 is about. Re-confirmed in isolation while planning: `php artisan test --filter=test_calling_outside_a_transaction_throws` → *"Failed asserting that exception of type LogicException is thrown."* **`RefreshDatabase` holds an open transaction, so `DB::transactionLevel()` is `1` inside every test that uses it, and the guard can never fire.** The identical flaw makes `ActivityRecorder`'s guard — AC6's entire mechanism — untestable. Task 4 fixes both.
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No new composer or npm dependency, no migration, no endpoint, no frontend file.**

---

## What already exists — verify it, do not rebuild it

| Acceptance criterion | Status | Evidence |
|---|---|---|
| AC1 — the eight columns | **Done**, untested | `…_create_ticket_activities_table.php:12–20`: `id`, `ticket_id` (cascade), `user_id` **nullable** (`nullOnDelete`), `event` `varchar(50)`, `field` `varchar(50)` nullable, `old_value`/`new_value` `text` nullable, `meta` `json` nullable, `created_at` `useCurrent()`. |
| AC2 — a backed enum | **Done**, unenforced | `TicketActivityEvent: string` with `Created` and `CategoryChanged`; **no raw event string appears anywhere in `backend/app/`** — verified by grep while planning. |
| AC3 — `ActivityRecorder` is the only writer | **True today**, unenforced | The only write is `TicketActivity::insert($chunk)` at `ActivityRecorder.php:37`. |
| AC3 — `TicketService` is its only caller | **NOT delivered.** | No such class; see the decision below. |
| AC4 — composite `(ticket_id, created_at)` | **Done**, untested | `ticket_activities_timeline_index` at migration **line 21**. Already measured as load-bearing: Story 37's `NOT EXISTS` uses it (`type=ref`, `Not exists`). |
| AC5 — null `user_id` means the system | **Data half done**, UI half has no consumer | Column is nullable; nothing sets or reads it as "system" yet. |
| AC6 — same transaction | **Guard exists, cannot fire in tests** | `ActivityRecorder.php:26–28` throws outside a transaction; baseline failure 3 proves the guard is untestable under `RefreshDatabase`. |

**Nothing in the migration changes. There is no migration in this story.** What it adds is the enforcement, the tests, two relations, one bug fix and the documentation those six criteria have been missing since TM-21.

---

## Story Goal

The audit trail stops being a table nobody tests and becomes an invariant the suite defends.

1. Every column, the index, the enum and the nullable actor are **asserted**, not assumed.
2. `ActivityRecorder::recordMany()` stops corrupting `meta` when a caller omits a key — the footgun four planned stories currently warn about.
3. The "must be inside a transaction" guard becomes **testable**, which fixes a red test the suite has carried since TM-21.
4. `Ticket::activities()` and `TicketActivity::user()` land once, here, rather than twice in later stories.
5. Two tripwire tests defend AC2 and AC3's first clause; the second clause is **recorded as not delivered**, with the reason.

**Not in scope, and each belongs to a named story.** **No endpoint** — `GET /api/v1/tickets/{ticket}/activities` is **TM-46**. **No timeline, no prose rendering, no icons, no system-entry styling** — all TM-46. **No internal notes** (TM-47). **No append-only enforcement** — blocking updates and deletes on the model, and the test that no route can mutate an activity, are **TM-48**. **No timeline pagination or filtering** (TM-49). **No new event cases** — Stories 26, 32, 34, 35 and 37 each append their own; this story adds none. **No `TicketActivityFactory`** — TM-59 owns factories. **No migration, no index change, no frontend file.**

---

## Decision — `TicketService` is not built, and that is raised rather than hidden

AC3 reads: *"ActivityRecorder is the only class that inserts into ticket_activities, and TicketService is its only caller."* **The first clause is delivered and enforced. The second is not, deliberately.**

- **There is no `TicketService` and eight planned stories assume there is not.** `TicketController` and `CategoryController` call the recorder directly today, and Stories 26 (assign), 27 (claim), 29 (reassign), 32 (status), 34 (reopen), 35 (escalate) and 37 (stale) are each planned with the recorder called from a controller method or a console command. Introducing a service layer now means **rewriting all of them**, most of which are not yet implemented, and re-planning the ones that are.
- **The clause names an implementation, not an outcome.** The story's own "so that" is *"the audit trail is complete by construction rather than by good intentions."* What makes it complete by construction is **one writer** — which is true, and which task 7 puts a tripwire under. Where the *caller* lives changes nothing about completeness: a controller that forgets to record is exactly as incomplete as a service that forgets.
- **One of the callers can never be a `TicketService` anyway.** `CategoryController::reassignTickets()` records a bulk `category_changed` during a **category** delete, and Story 37's `tickets:flag-stale` records from a **console command**. A rule that "TicketService is its only caller" would be false the moment either lands, so enforcing it would mean either exempting them or inventing a service they do not belong in.

**This is a deviation from a written acceptance criterion and must be surfaced, not absorbed.** Put it in the PR description in these words, and raise it with whoever owns the backlog:

> TM-45's AC3 asks for `ActivityRecorder` to be called only from a `TicketService`. We implemented and test-enforced the single-**writer** rule, which is what makes the trail complete. We did not introduce `TicketService`: eight planned stories call the recorder from controllers and one from a console command, so the clause would require a refactor nobody has scoped and would still be false for the bulk category-change and stale-flag paths. **If a service layer is wanted, it is its own story and it should cover every ticket mutation, not only the recording.**

**Do not quietly implement half a `TicketService`** to satisfy the wording.

## Decision — AC5's UI half is documented, not built

There is no timeline. `GET /api/v1/tickets/{ticket}/activities` and every component that would render an activity are **TM-46** (E7-S2), unplanned.

This story delivers AC5's data half in full and **writes the contract down where TM-46 will read it**: `user_id` is nullable, `null` means the system acted, and no consumer may substitute a name for it. Task 6 adds it to `docs/erd.md`; tests 8 and 9 pin the column and the relation's null return.

**This is the same split Stories 29 and 33 made** for their own forward-facing criteria, and it is the third time in this backlog that a storage story has been written before its reader. TM-46 renders it; this story guarantees it is there and legible.

## Decision — `recordMany()` gets `record()`'s defaults, because the footgun is already being routed around

`record()` fills five defaults (`ActivityRecorder.php:15–18`); `recordMany()` requires all five and reads them unguarded (**32–34**). A caller omitting `meta` hands `json_encode(null)` to the insert and stores the **literal string `"null"`** in a `json` column, with a PHP warning and no exception.

**Story 26's plan already warns about it in prose** — *"`recordMany()` requires all five and will write the literal string `"null"` into `meta` if you omit one. **Call `record()`.**"* — and Story 37's plan repeats the warning. **Two planned stories documenting a landmine is the signal to remove the landmine.**

Task 3 moves the defaults into `recordMany()` and makes `record()` a thin wrapper. **No existing caller breaks**: `CategoryController::reassignTickets()` (**91–95**) passes all five, and passing all five still wins because `+` preserves supplied keys. **Delete the warning from Stories 26 and 37's plans? No — leave those files alone**; they are read-only, and their warning becomes harmlessly obsolete.

## Decision — AC2 is enforced by the **type**, not by grepping for strings

The instinct is a test that scans `backend/app/` for quoted event literals. **It produces false positives and would fail on correct code:** Story 34 adds `TicketActivityEvent::Reopened = 'reopened'` **and** `Status::SLUG_REOPENED = 'reopened'`. The same string is a legitimate status slug and an event value, and a scanner cannot tell them apart.

The real guarantee is already structural and just needs asserting: **`record()` and `recordMany()` take `TicketActivityEvent`, not `string`.** A raw event string cannot reach the table through the only writer, because the signature rejects it. Task 7's test asserts that by reflection — precise, no false positives, and it fails loudly if someone widens the parameter to `string|TicketActivityEvent` for convenience.

A second test asserts every distinct `event` value **in the database** round-trips through `TicketActivityEvent::tryFrom()`, which catches a raw string that arrived by some path the signature does not cover.

---

## Context — Read These Files First

1. `backend/app/Services/ActivityRecorder.php` — **the whole file, 40 lines.** `record()` at **13–19** and its five defaults; `recordMany()` at **21–39**; the transaction guard at **26–28**; the 500-row chunking at **36**; `TicketActivity::insert($chunk)` at **37**, the project's only write to this table. Task 3 rewrites **13–35**; **lines 36–37 do not change.**
2. `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php` — **11–22.** Every column AC1 names, and the composite index at **21**. **This file is not edited.**
3. `backend/app/Models/TicketActivity.php` — **17 lines.** `#[Fillable]` at **9**, `UPDATED_AT = null` at **12** (there is no `updated_at` column, by design — the trail is append-only), `casts()` at **14–17**. Task 5 adds one relation.
4. `backend/app/Models/Ticket.php` — seven `BelongsTo` relations at **23–56** and **no `HasMany`**. Task 5 adds the first.
5. `backend/app/Enums/TicketActivityEvent.php` — 14 lines. **Note `values()` at 10–13 has no `@return list<string>` docblock**, unlike `UserRole::values()` (`app/Enums/UserRole.php:11`) and `StatusBucket::values()` (`app/Enums/StatusBucket.php:11`). Task 5 adds it — one line, and the only change this story makes to the enum.
6. `backend/app/Http/Controllers/Api/V1/CategoryController.php:86–97` — `reassignTickets()`, the only `recordMany()` caller. **Read it to confirm task 3 breaks nothing**: it passes all five keys.
7. `backend/app/Http/Controllers/Api/V1/TicketController.php:44` — the only `record()` caller, passing a two-key subset. **This is the call that proves the defaults work.**
8. `backend/tests/Feature/Database/TicketReferenceTest.php` — **`test_calling_outside_a_transaction_throws` at 38–42, and the `RefreshDatabase` trait at 14.** Task 4 moves that one test out; the other four stay.
9. `backend/app/Services/TicketReferenceGenerator.php:12–14` — the sibling guard, identical in shape and identically untestable.
10. `backend/tests/Feature/Database/RequestersTableSchemaTest.php` — the schema-assertion style tests 1–4 follow: `Schema::hasColumns()`, and `expectException(QueryException::class)` to prove a constraint.
11. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **the precedent for a structural test in this suite.** It reflects over the route table rather than exercising behaviour; task 7's two tests are the same idea applied to the recorder.
12. `docs/erd.md` — the entity blocks at **15–55**, the relationship lines at **56–62**, the table-notes table at **67–76** and the closing notes at **77–79**. **`ticket_activities` appears in none of them** — the audit table is missing from the ERD entirely. Task 6 fixes that.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `recordMany()` called without `meta` | PHP warning; `meta` stored as the string `"null"` | `meta` defaults to `[]`, stored as `[]` |
| `recordMany()` called without `user_id` | PHP warning; `user_id` null by accident | `user_id` defaults to `null` **by contract** |
| Either method called outside a transaction | `LogicException` — but untestable | **Unchanged behaviour**, now actually asserted |
| A raw string passed as the event | Impossible — the signature rejects it | **Unchanged**, now asserted by reflection |
| `user_id` null | Allowed, undocumented | **Documented**: the actor was the system |
| Reading a ticket's activities | No relation exists | `$ticket->activities` |
| Reading an activity's actor | No relation exists | `$activity->user`, **null for the system** |
| Anything other than `ActivityRecorder` inserting | Nothing stops it | A tripwire test fails the build |
| `ticket_activities` in the ERD | **Absent** | Documented as an entity, with the null-actor rule |

---

## Backend Tasks

### 1 — Fix `recordMany()`'s defaults

**File: `backend/app/Services/ActivityRecorder.php`**

Replace **13–35** with:

```php
    /** @param array<string, mixed> $attributes */
    public function record(int $ticketId, TicketActivityEvent $event, array $attributes = []): void
    {
        $this->recordMany([$ticketId], $event, $attributes);
    }

    /**
     * @param  list<int>  $ticketIds
     * @param  array<string, mixed>  $attributes
     */
    public function recordMany(array $ticketIds, TicketActivityEvent $event, array $attributes = []): void
    {
        if ($ticketIds === []) {
            return;
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('ActivityRecorder must be called inside a database transaction.');
        }
        // Defaults live here, not in record(): a caller that omitted `meta`
        // used to hand json_encode(null) to the insert and store the literal
        // string "null" in a json column, with a warning and no exception.
        // `+` preserves supplied keys, so passing all five still wins.
        $attributes += ['user_id' => null, 'field' => null, 'old_value' => null, 'new_value' => null, 'meta' => []];
        $createdAt = now();
        $rows = array_map(fn (int $ticketId): array => [
            'ticket_id' => $ticketId, 'event' => $event->value, 'created_at' => $createdAt,
            'user_id' => $attributes['user_id'], 'field' => $attributes['field'],
            'old_value' => $attributes['old_value'], 'new_value' => $attributes['new_value'],
            'meta' => json_encode($attributes['meta'], JSON_UNESCAPED_UNICODE),
        ], $ticketIds);
        foreach (array_chunk($rows, 500) as $chunk) {
            TicketActivity::insert($chunk);
        }
    }
```

Five things not to re-derive:

- **The empty-array early return moves above the transaction guard.** `recordMany([], …)` outside a transaction is a no-op either way, and returning first means Story 37's chunk loop cannot throw on an empty final chunk.
- **`$attributes += [...]`, not `array_merge`.** `+` keeps the caller's keys; `array_merge` would let the defaults win.
- **`meta` defaults to `[]`, not `null`.** `json_encode([])` is `[]`, which reads back through the `array` cast as an empty array. `null` would round-trip as `null` and force every consumer to guard.
- **Lines 36–37 are untouched** — the 500-row chunking and the single `TicketActivity::insert()` are the parts of this class that were already right.
- **`record()` becomes a one-line delegate.** Its old two-step (`$attributes + defaults` then call `recordMany`) is now redundant; **do not leave both.**

### 2 — Make the transaction guard testable, and fix the red test

The guard cannot fire inside a test that uses `RefreshDatabase`, because that trait opens a transaction for the duration of the test — which is why baseline failure 3 has been red since TM-21. **Neither guard touches the database before throwing**, so a test class **without** the trait exercises them correctly.

**Create file: `backend/tests/Feature/Database/TransactionGuardTest.php`**

```php
<?php

namespace Tests\Feature\Database;

use App\Enums\TicketActivityEvent;
use App\Services\ActivityRecorder;
use App\Services\TicketReferenceGenerator;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Deliberately WITHOUT RefreshDatabase. That trait wraps each test in a
 * transaction, so DB::transactionLevel() is 1 and neither guard can fire --
 * which is exactly why TicketReferenceTest::test_calling_outside_a_transaction_throws
 * was red from TM-21 until TM-45. Neither service touches the database before
 * throwing, so no trait is needed here. Do not add one.
 */
class TransactionGuardTest extends TestCase
{
    public function test_the_recorder_refuses_to_write_outside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ActivityRecorder must be called inside a database transaction.');
        app(ActivityRecorder::class)->record(1, TicketActivityEvent::Created);
    }

    public function test_the_reference_generator_refuses_to_run_outside_a_transaction(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->expectException(LogicException::class);
        app(TicketReferenceGenerator::class)->next(2026);
    }
}
```

**File: `backend/tests/Feature/Database/TicketReferenceTest.php`**

**Delete `test_calling_outside_a_transaction_throws` (38–42).** It is replaced by the second test above. Leave the other four and the `RefreshDatabase` trait exactly as they are — they need the database. **Drop the now-unused `LogicException` import** if nothing else in the file uses it.

**This takes the suite from 3 failures to 2.** Say so in the PR description, and name TM-21 as where the test came from.

### 3 — The two relations

**File: `backend/app/Models/Ticket.php`**

Add after `escalatedBy()` (**ends at 56**), with `use Illuminate\Database\Eloquent\Relations\HasMany;`:

```php
    /** @return HasMany<TicketActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(TicketActivity::class);
    }
```

**File: `backend/app/Models/TicketActivity.php`**

Add after `casts()` (**ends at 17**), with `use Illuminate\Database\Eloquent\Relations\BelongsTo;`:

```php
    /**
     * Null means the system acted -- a scheduled command or a cascade, not a
     * person. Every consumer must render that as "System" and must never
     * substitute a name. See docs/erd.md.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
```

Docblock shape from `Requester::tickets()` (`backend/app/Models/Requester.php:36–40`).

**File: `backend/app/Enums/TicketActivityEvent.php`**

Add the missing docblock above `values()` (**10**), matching `UserRole.php:11`:

```php
    /** @return list<string> */
```

**Check first:** `grep -n "function activities" backend/app/Models/Ticket.php` and `grep -n "function user" backend/app/Models/TicketActivity.php`. **Stories 33 and 34 each add one of these; if either has landed, skip that half.** Record in the PR which ones this story added.

### 4 — Document the table

**File: `docs/erd.md`**

`ticket_activities` is **absent from the diagram, the relationships and the table notes.** Add the entity after the `ticket_sequences` line (**55**):

```
    ticket_activities { bigint id PK bigint ticket_id FK bigint user_id FK "null = system" string event string field text old_value text new_value json meta timestamp created_at }
```

two relationship lines after **62**:

```
    tickets ||--o{ ticket_activities : ticket_id
    users ||--o{ ticket_activities : user_id
```

one table-notes row after the `ticket_sequences` row (**75**):

| Table | Purpose | Owning story |
|-------|---------|--------------|
| `ticket_activities` | Append-only audit trail. One row per change, written only by `ActivityRecorder`, inside the same transaction as the change. | TM-45 |

and two closing notes after **79**:

```
`ticket_activities` has no `updated_at`: rows are never modified. A null
`user_id` means the system acted — a scheduled command or a cascading change —
and every consumer renders it as "System" rather than substituting a name.

The composite index `(ticket_id, created_at)` serves the timeline read and the
`NOT EXISTS` staleness check; neither has any other index on this table.
```

---

## Test Plan

**This story creates `backend/tests/Feature/Activity/`, the first tests this table has ever had.** Two of them (7 and 12) are structural, following `RouteAuthorizationTest`'s precedent of asserting shape rather than behaviour.

### `backend/tests/Feature/Database/TicketActivitiesTableSchemaTest.php` (new; `RefreshDatabase`)

Style from `RequestersTableSchemaTest`.

1. `test_it_has_every_column_the_story_names` — **AC1.** `Schema::hasColumns('ticket_activities', ['ticket_id', 'user_id', 'event', 'field', 'old_value', 'new_value', 'meta', 'created_at'])`.
2. `test_it_has_no_updated_at` — `Schema::hasColumn(…, 'updated_at')` is **false**. The trail is append-only by shape; `TicketActivity::UPDATED_AT` being null is the model half.
3. `test_the_timeline_index_exists` — **AC4.** `Schema::getIndexes('ticket_activities')` contains `ticket_activities_timeline_index` over exactly `['ticket_id', 'created_at']`, **in that order**. A reversed composite would pass a naive name check and be useless for the timeline read.
4. `test_user_id_is_nullable_and_ticket_id_is_not` — **AC5's column half.** Insert a row with a null `user_id` → succeeds. Insert one with a null `ticket_id` → `QueryException`.
5. `test_deleting_a_user_nulls_the_actor_and_keeps_the_row` — insert with a real `user_id`, delete the user, assert the row **survives** with `user_id` null. Proves `nullOnDelete()` and that history outlives its author.
6. `test_deleting_a_ticket_cascades` — hard-delete a ticket (`forceDelete`), assert its rows are gone. Proves `cascadeOnDelete()`. **Soft-deleting must not remove them** — assert that too; it is TM-48's third criterion and this is the schema that has to support it.

### `backend/tests/Feature/Activity/ActivityRecorderTest.php` (new; `RefreshDatabase`)

Every test wraps its call in `DB::transaction(...)`, because the guard is real.

7. `test_the_event_parameter_is_typed_as_the_enum` — **AC2, by reflection.** `(new ReflectionMethod(ActivityRecorder::class, 'record'))->getParameters()[1]->getType()` is `TicketActivityEvent`; same for `recordMany`. **This is the guarantee that a raw event string cannot reach the table** — widen either signature to `string` and this fails. Assert the name exactly, so a union type fails too.
8. `test_every_stored_event_is_a_known_enum_case` — record one row per `TicketActivityEvent::cases()`, then read every distinct `event` back and assert `TicketActivityEvent::tryFrom()` is non-null for each. Catches a raw string arriving by a path the signature does not cover.
9. `test_record_fills_the_defaults_for_a_partial_caller` — **the `TicketController.php:44` shape**: pass only `user_id` and `meta`. Assert `field`, `old_value` and `new_value` are **null** and `meta` is the array passed.
10. `test_record_many_no_longer_corrupts_meta_when_meta_is_omitted` — **the bug fix.** Call `recordMany` with only `user_id`; assert the stored `meta` is `[]` **and specifically not the string `"null"`**. Read the raw column with `DB::table(...)->value('meta')` as well as through the cast — the cast would hide it. **Revert task 1 and confirm this fails.**
11. `test_record_many_still_honours_every_supplied_key` — **the `CategoryController.php:91` shape**: pass all five; assert all five round-trip. The regression that proves the defaults do not overwrite.
12. `test_it_writes_one_row_per_ticket_id` — three ids in one call → three rows, identical `event`, `created_at` and `meta`, distinct `ticket_id`.
13. `test_an_empty_id_list_writes_nothing_and_does_not_throw` — `recordMany([], …)` **outside** a transaction returns cleanly. Pins the early-return ordering from task 1.
14. `test_it_chunks_beyond_five_hundred` — 501 ids in one call → 501 rows. Slow; mark it.
15. `test_a_null_user_id_is_stored_as_the_system_actor` — **AC5.** Record with `user_id` null, load through `TicketActivity::with('user')`, assert `$activity->user` is **null** and `$activity->user_id` is null. **The contract TM-46 renders as "System".**
16. `test_meta_round_trips_unicode_and_markup_verbatim` — Arabic and `<script>alert(1)</script>` in `meta`; byte-identical on read. `JSON_UNESCAPED_UNICODE` (**line 34**) and utf8mb4. **Escaping belongs at render**, which is TM-46's and TM-64's job.
17. `test_a_rolled_back_change_takes_its_activity_row_with_it` — **AC6, the whole point.** Inside a transaction, change a ticket **and** record, then throw; assert the ticket is unchanged **and** no row exists. The two can never disagree.
18. `test_ticket_activities_relation_returns_the_trail_in_insertion_order` — `$ticket->activities` after three records → three rows. Pins task 3's relation.

### `backend/tests/Feature/Database/TransactionGuardTest.php` (new, **no `RefreshDatabase`** — task 2)

19. `test_the_recorder_refuses_to_write_outside_a_transaction` — **AC6's guard, asserted for the first time.**
20. `test_the_reference_generator_refuses_to_run_outside_a_transaction` — **replaces the deleted red test.**

### `backend/tests/Feature/Activity/SingleWriterTest.php` (new)

21. `test_only_the_recorder_writes_to_ticket_activities` — **AC3's first clause, as a tripwire.** Scan every `.php` under `app_path()`, excluding `app/Services/ActivityRecorder.php`, for these exact write idioms:
    ```php
    ['TicketActivity::insert', 'TicketActivity::create', 'TicketActivity::insertOrIgnore',
     'TicketActivity::upsert', 'new TicketActivity', "DB::table('ticket_activities')", 'DB::table("ticket_activities")']
    ```
    Assert none is found, failing with the offending file and idiom. **Reads are deliberately allowed** — `TicketActivity::query()` is not on the list, because Story 33's `TicketResource` and TM-46's timeline both read this table legitimately.
    **Write it as a tripwire and say so in a comment: it is a heuristic over source text, not a proof.** The structural guarantee — the model refusing updates and deletes — is **TM-48**, and this test is what keeps the rule honest until then.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
2. **Confirm the starting point:** `php artisan test --filter=test_calling_outside_a_transaction_throws` → **fails** with *"Failed asserting that exception of type LogicException is thrown."* That is the red test this story removes the cause of.
3. **Confirm nothing tested this table before:** `grep -rln "ticket_activities\|TicketActivity\|ActivityRecorder" backend/tests/` → **no output**. After this story it lists four files.
4. **Backend formats:** `./vendor/bin/pint --test` → exit `0`.
5. **Backend tests:** `composer test`. Expect **+21 tests** and **2 failures, not 3** — `PasswordThrottleTest` and `RouteAuthorizationTest` only.
6. **Prove test 10 earns its place:** revert task 1's `$attributes += [...]` line, re-run `--filter=test_record_many_no_longer_corrupts_meta_when_meta_is_omitted`, confirm it **fails** with the raw column holding `null` as a string, restore.
7. **Prove test 7 earns its place:** widen `recordMany`'s `$event` parameter to `string|TicketActivityEvent`, re-run `--filter=test_the_event_parameter_is_typed_as_the_enum`, confirm it **fails**, restore.
8. **Prove test 21 earns its place:** add `TicketActivity::create([...])` to any controller, re-run `--filter=test_only_the_recorder_writes_to_ticket_activities`, confirm it **fails and names the file**, restore.
9. **Prove the new guard test is not accidentally passing:** add `use RefreshDatabase;` to `TransactionGuardTest`, re-run it, confirm **both** tests fail — which is precisely why the trait is absent and why the old test was red. Remove it again.
10. **Prove test 17 earns its place:** it is AC6's only real assertion. Temporarily move the `record()` call outside the `DB::transaction` closure in `TicketController::store()`, run `--filter=TicketCreate`, and confirm the recorder's `LogicException` surfaces rather than a silent orphan row. Restore.
11. **By hand.** `php artisan serve`, file a ticket through `POST /api/v1/tickets`:
    - `SELECT ticket_id, user_id, event, field, old_value, new_value, meta, created_at FROM ticket_activities ORDER BY id DESC LIMIT 1;` → one `created` row, `user_id` the caller, `field`/`old_value`/`new_value` **NULL**, `meta` a real JSON object, **not the string `"null"`**.
    - `php artisan tinker --execute="dump(App\Models\Ticket::query()->latest('id')->first()->activities->count());"` → at least 1.
    - Delete a category holding tickets → one `category_changed` row per ticket, all five fields populated.
12. **Regression:** confirm `git status` shows **no migration**, no file under `frontend/`, and no change to `routes/api.php`, any policy or any seeder. The only application files touched are `ActivityRecorder.php`, `Ticket.php`, `TicketActivity.php`, `TicketActivityEvent.php` and `docs/erd.md`.

---

## Done Criteria

- [ ] Every column AC1 names, the `(ticket_id, created_at)` composite **in that order**, the nullable actor and the absent `updated_at` are all **asserted by tests** — the table had none before this story.
- [ ] `recordMany()` applies the same five defaults as `record()`, so an omitted `meta` stores `[]` and **never the string `"null"`** — proven by a test that reads the raw column and fails when the fix is reverted.
- [ ] `record()` is a one-line delegate; no caller changed; `CategoryController`'s five-key call still round-trips every field.
- [ ] The empty-id early return precedes the transaction guard, so `recordMany([], …)` is a safe no-op anywhere.
- [ ] The "must be inside a transaction" guard is **asserted for the first time**, in a class deliberately **without `RefreshDatabase`**, with a comment saying why — and adding the trait makes both tests fail.
- [ ] `TicketReferenceTest::test_calling_outside_a_transaction_throws` is **deleted and replaced**, taking the suite from **3 failures to 2**, with TM-21 named as its origin in the PR.
- [ ] A rolled-back change takes its activity row with it — AC6 asserted, not assumed.
- [ ] `record`/`recordMany` are proven by **reflection** to take `TicketActivityEvent` and not `string`, and every stored `event` round-trips through `tryFrom()`. **No source-text scan for event literals** — it would false-positive on `Status::SLUG_REOPENED`.
- [ ] A tripwire test fails the build if anything outside `ActivityRecorder` writes to `ticket_activities`, **while allowing reads**, with a comment that it is a heuristic and TM-48 owns the structural guarantee.
- [ ] **`TicketService` was NOT built**, and the PR description carries the written deviation and the reason — eight planned stories call the recorder from controllers, one from a console command, and the clause names an implementation rather than the outcome. **Raised with the backlog owner, not absorbed.**
- [ ] `Ticket::activities()` and `TicketActivity::user()` exist **once** (added here, or already by Stories 33/34 — never twice), and the PR records which.
- [ ] `TicketActivityEvent::values()` carries the `@return list<string>` docblock its two sibling enums already have.
- [ ] `user_id` null is documented as "the system acted", with the rule that no consumer substitutes a name — in `docs/erd.md` and in the relation's docblock, ready for TM-46.
- [ ] `docs/erd.md` gains the `ticket_activities` entity, both relationships, a table-notes row and the two closing notes. **It was missing from the ERD entirely.**
- [ ] `meta` round-trips Arabic and `<script>` byte-identically; nothing sanitises on the way in.
- [ ] **No migration, no index change, no endpoint, no frontend file, no new event case, no new dependency.**
- [ ] `pint --test` clean; **+21 backend tests**; exactly **2** pre-existing failures remain, both named and neither in a file this story touched.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 39 (TM-46, ticket timeline).**
