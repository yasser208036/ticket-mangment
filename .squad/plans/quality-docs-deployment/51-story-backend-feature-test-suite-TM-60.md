# Story 51 — Backend feature test suite (Story: TM-60)

## Prerequisites

- **This plan supersedes the 2026-08-27 version. Re-measured 2026-08-28, and the ground has moved.** Escalation (Story 35 / TM-41) landed on 2026-08-27 — `TicketController::escalate()` (**116–173**), the `Escalated` enum case, and `tests/Feature/Tickets/TicketEscalateTest.php` (**20 tests**) all exist now. **AC4 is no longer undeliverable — it is done and needs auditing, not building or raising as a deviation.** Both test bugs the previous version diagnosed (`PasswordThrottleTest`, `TicketReferenceTest`) are **already fixed**, independently of this plan, and not by the mechanism the previous version specified. **A new regression has appeared that the previous version never saw**: `tests/Feature/Activity/TimelineFilterTest.php` has 4 failing tests, diagnosed below. **Read `## What already exists — audit before you write` first.**
- **Story 50 (TM-59) is still a hard gate, and the collision is re-measured today, unchanged.** `TicketFactory.php:16` still allocates `reference` from a private static counter, never touching `ticket_sequences`. Re-verified 2026-08-28 by running a factory-created ticket followed by a real `POST /tickets` in an isolated process (the exact scenario every new test in task 3 needs):
  ```
  $this->seed(); Ticket::factory()->create(); POST /api/v1/tickets =>
  500 SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry
  'TKT-2026-000001' for key 'tickets.tickets_reference_unique'
  ```
  **Do not start task 3 or task 5 of this story before Story 50 lands.** `database/factories/` still holds only `RequesterFactory.php`, `TicketFactory.php`, `UserFactory.php` — no `CategoryFactory`, no `TicketActivityFactory`.
- **Measured baseline, 2026-08-28, from `backend/`, services up (`docker compose ps` — all three healthy).** `composer test` → **227 tests, 833 assertions, 223 passing, 4 failing**, exit **1**. All four failures are in one class, `Tests\Feature\Activity\TimelineFilterTest`, diagnosed by probe while planning (see the decision section) — **all four are test bugs in a story this one does not own (TM-49), and AC6 inherits the obligation to fix them for the same reason the previous version's plan inherited two different ones.** `./vendor/bin/pint --test` → clean.
- **Docker up**, `tm-mysql` healthy on **3306**, `tm-mysql-test` on **3307**. **No new composer or npm dependency, no migration, no new endpoint, no new route, no new policy.** Two existing production files are touched, both justified under AC6 below: `phpunit.xml` and `TicketActivityController.php` (one line each).

---

## What already exists — audit before you write

| AC | Status | Evidence |
|---|---|---|
| **AC1** — login, logout, rate limiting, inactive user | **Done.** Do not rebuild. | `Auth/LoginTest`, `LoginValidationTest`, `LogoutTest`, `LoginThrottleTest`, `PasswordThrottleTest` (**now green**), `ActiveAccountTest`, `MeTest`, `PasswordTest`, `ProtectedRouteTest`. |
| **AC2** — agent gets 403 on every admin-only endpoint | **Done, still map-driven.** | `Authorization/RouteAuthorizationTest` (17 tests). `ACCESS` (**line 17**) now lists **29** routes including `tickets.escalate` and `tickets.notes`, both classified `staff`. `test_every_api_route_is_classified` (**19–26**) still fails the build on an unclassified route. |
| **AC3** — legal and illegal transition for each seeded status | **No coverage, unchanged.** | No file touches `TicketWorkflow`, `StatusTransitionSeeder`, or `TicketTimestamps`. |
| **AC4** — escalation level, priority bump, ceiling, activity row | **Done — audit, do not rebuild.** `TicketEscalateTest.php` (**20 tests**) already covers the level increment (`test_the_escalated_row_captures_the_reason_and_the_level`, `test_a_second_escalation_increments_and_overwrites`), the priority bump (`test_escalation_raises_the_priority_one_level`), its ceiling (`test_urgent_does_not_overflow`), the activity row, the admin-routing rule, and atomicity. | `app/Enums/TicketActivityEvent.php:11` has `Escalated`; `TicketController::escalate()` **116–173**; route **`routes/api.php:55`**. |
| **AC5** — every mutation path writes exactly the expected rows | **Partial. 3 of 12 event cases already covered by other stories' tests; 9 have no test at all.** | See the path table below. |
| **AC6** — runs against MySQL in CI, passes from a clean database | **False, for a different reason than 2026-08-27.** | `composer test` → **4 failing**, all in `TimelineFilterTest`, none of them the two failures the previous plan version diagnosed (both now fixed). CI infra (`ci.yml:16–31`, MySQL 8.4 on 3307) is unchanged and correct. |

**The honest shape of this story, re-cut: audit and close AC1, AC2 and AC4; build AC3 and the remaining 9/12 of AC5; fix four newly-discovered red tests (not the two from before) to make AC6 true.**

---

## Decision — AC6's blockers are new: two Sanctum guard-cache bugs, one assertion-path bug, one real one-line product inconsistency

`TimelineFilterTest.php` (`tests/Feature/Activity/`) has 20 tests; 4 fail. All four were reproduced and root-caused by probe.

### 1–2. Two are the same guard-cache bug this repo already has a fix for

`test_the_query_count_does_not_grow_with_the_trail` (**line 120**) and `test_the_filter_does_not_widen_access` (**line 177**) each make two or more HTTP calls with different auth state in one test method, without `Auth::forgetGuards()` between them. Probed by adding a diagnostic listener:

```
first  authenticated call:  7 queries (token lookup, user lookup, last_used_at update, + 4 data queries)
second authenticated call:  4 queries (Sanctum's guard is already resolved and cached — no re-lookup)
```

This is the identical mechanism `PasswordThrottleTest` and `RouteAuthorizationTest:132/134` already work around: the Sanctum guard caches the first resolved user for the lifetime of the test method. `test_the_query_count_does_not_grow_with_the_trail` compares a "small" request's query count against a "large" request's and gets 7 vs 4 — a real difference, but caused by guard caching, not data size. `test_the_filter_does_not_widen_access` authenticates as an agent, then makes an **unauthenticated** call expecting 401 and gets 200, because the cached guard still resolves the previous user.

**Fix: add `Auth::forgetGuards();` before every call after the first identity change in both methods**, exactly the idiom already in this codebase. No product change.

### 3. One is a wrong JSON-path assertion

`test_an_unknown_or_malformed_event_filter_is_rejected` (**line 55**) asserts `assertJsonPath('errors.events.0', 'Unknown activity event type: not_an_event.')`. Probed directly:

```
POST .../activities?events[]=not_an_event  =>  422
{"errors":{"events.0":["Unknown activity event type: not_an_event."]}}
```

Laravel's validator keys an array-item error as the **literal string** `"events.0"`, not a nested `events: {0: ...}` structure. `assertJsonPath`'s dot-notation traversal looks for `errors -> events -> 0` and finds nothing, so the assertion sees `null`. **Fix: assert the flat key directly** — `$response->assertJsonPath('errors.events\.0', ...)` (escaping the dot) or `$this->assertSame([...], $response->json('errors')['events.0'])`. No product change; `TicketActivityController.php:25`'s custom message is correct as written.

### 4. One is a real product inconsistency, and this story fixes it — a second production file, beyond `phpunit.xml`

`test_a_repeated_event_value_changes_nothing` (**line 109**) asserts that `?events[]=created` and `?events[]=created&events[]=created` produce an identical response. Probed:

```
once:  links.first = ".../activities?events%5B0%5D=created&page=1"
twice: links.first = ".../activities?events%5B0%5D=created&events%5B1%5D=created&page=1"
```

`TicketActivityController.php:27` dedupes into `$events` for the actual filter, but **line 39**'s `->withQueryString()` echoes the **raw, undeduped** request query into the pagination links. The filtered *data* is already identical either way (both requests match the same rows) — only the echoed links differ. **This is a real, if minor, correctness gap**: the link a client is handed does not reflect the filter that was actually applied. The fix is one line and touches nothing else:

```php
$events = array_values(array_unique($filters['events'] ?? []));
if (isset($filters['events'])) {
    $request->query->set('events', $events);
}
```

placed immediately after **line 27**, before the query executes. This mutates only the in-memory `Request` used to build the pagination links; it does not touch validation, filtering, or any other route. **This is the second and last production file this story touches** — justified by the same reasoning the previous plan version used for its own two fixes: AC6 says the suite passes, and a suite that does not pass proves nothing about the rules it contains, regardless of which earlier story introduced the defect.

**Do not weaken the assertion instead.** Relaxing `test_a_repeated_event_value_changes_nothing` to skip comparing `links` would hide a real (if small) API inconsistency permanently. The one-line fix costs less than that would.

---

## Decision — the transaction-guard fix the previous plan version specified was never needed; the repo already found a better one

The previous plan version called for a new `TransactionGuardTest` class without `RefreshDatabase`, because `RefreshDatabase` holds an open transaction for the whole test and `DB::transactionLevel()` is never 0 inside it. **That diagnosis was right, but the fix actually shipped is different and better**: `TicketReferenceTest::test_calling_outside_a_transaction_throws` (**lines 38–52**) now does this, inside a class that still uses `RefreshDatabase`:

```php
DB::commit();
try {
    $this->expectException(LogicException::class);
    app(TicketReferenceGenerator::class)->next(2026);
} finally {
    DB::beginTransaction();
}
```

It commits `RefreshDatabase`'s wrapper transaction (making "outside a transaction" real), asserts, then reopens one so `RefreshDatabase`'s own rollback at `tearDown()` still has something to close. **This test passes today** — confirmed by running it in isolation. `ActivityRecorder`'s identical guard (`ActivityRecorder.php:26–28`) has still never been asserted — `grep -rl ActivityRecorder tests/` finds only `TimelineFilterTest.php`, which calls `recordMany()` successfully but never tests its guard. Task 1 adds one test using the same proven idiom, in a new file, rather than a new class shape nobody asked for.

---

## Decision — AC3's matrix is still generated from `StatusTransitionSeeder::EDGES`, unchanged from before

`StatusTransitionSeeder::EDGES` (**lines 12–17**) is byte-for-byte the same 14 triples as the previous plan version measured. The matrix, the "generate, don't hand-list" reasoning, and the "one admin-only edge" fact (`resolved → closed`) all still hold exactly as written before. Nothing here changed; task 3 below carries it forward unedited.

## Decision — AC5's completeness tripwire now covers 12 event cases through 9 producing classes, 3 of them already shipped by other stories

`TicketActivityEvent::cases()` (`app/Enums/TicketActivityEvent.php:7–18`) now has **12** cases, not 9 — `NoteAdded` (TM-47, `TicketNoteController`) and `Stale` (TM-43, `FlagStaleTickets` console command) landed since the previous plan version, alongside `Escalated`. **Both already have dedicated, substantial test coverage**: `tests/Feature/Activity/TicketNotesTest.php` (**13 tests**) and `tests/Feature/Console/FlagStaleTicketsTest.php` (**15 tests**). The tripwire's `PRODUCERS` map (task 6) credits both rather than re-testing them.

| Event | Producer | Rows | Test class |
|---|---|---|---|
| `created` | `TicketController::store()` **:329** | exactly 1 | **New:** `CreateTicketTest` |
| `updated` | `TicketController::update()` **:296** (loop); **:290–292** no-op | 1 per changed field; **0** if nothing changed | **New:** `UpdateTicketTest` |
| `deleted` | `TicketController::destroy()` **:309** | exactly 1 | **New:** `DeleteTicketTest` |
| `assigned` | `TicketController::changeAssignee()` **:236**; **:225–227** same-assignee | exactly 1; **0** if unchanged | **New:** `AssignTicketTest` |
| `unassigned` | same, target `null` | exactly 1 | **New:** `AssignTicketTest` |
| `claimed` | `TicketController::claimTicket()` **:269**; **:264–268** lost race | exactly 1; **0** + 409 on conflict | **New:** `ClaimTicketTest` |
| `status_changed` | `TicketController::changeStatus()` **:108** | exactly 1 | **New:** `TicketWorkflowTest` |
| `reopened` | same, target slug `reopened` | exactly 1 | **New:** `TicketWorkflowTest` |
| `category_changed` | `CategoryController::reassignTickets()` **:91** | 1 per ticket, soft-deleted included (**:89** `withTrashed()`) | **New:** `DeleteCategoryTest` |
| `escalated` | `TicketController::escalate()` **:147**; companion `assigned` at **:160** | exactly 1 (+1 if reassigned) | **Existing:** `TicketEscalateTest` (audit only) |
| `note_added` | `TicketNoteController::__invoke()` **:21** | exactly 1 | **Existing:** `TicketNotesTest` (audit only) |
| `stale` | `FlagStaleTickets::handle()` **:47** | 1 per flagged ticket per run | **Existing:** `FlagStaleTicketsTest` (audit only) |

**Nine test classes back the tripwire; six are new work, three are already shipped.** The zero-row branches (no-op update, same-assignee, lost claim race) remain the ones a hand-written suite tends to omit, exactly as before.

## Decision — `phpunit.xml` still needs `ADMIN_PASSWORD`; nothing has changed here

Re-measured 2026-08-28: `backend/.env:66` still carries `ADMIN_PASSWORD=password`, `phpunit.xml` still sets no such env, and `AdminUserSeeder.php:17–19` still throws `RuntimeException: ADMIN_PASSWORD is empty` on a blank value. `RouteAuthorizationTest:48` and other `$this->seed()` call sites still depend on the ambient `.env`. This task is unchanged from the previous plan version; it simply has not been done yet.

---

## Context — Read These Files First

1. `backend/tests/Feature/Activity/TimelineFilterTest.php` — **232 lines.** `test_an_unknown_or_malformed_event_filter_is_rejected` (**55–70**), `test_a_repeated_event_value_changes_nothing` (**109–118**), `test_the_query_count_does_not_grow_with_the_trail` (**120–136**), `test_the_filter_does_not_widen_access` (**177–186**) — the four to fix. The `fill()` helper (**189–205**) and `asAgent()` (**223–226**) are the pattern every new Tickets/Categories test class should copy.
2. `backend/app/Http/Controllers/Api/V1/TicketActivityController.php` — **69 lines.** `$events` computed at **line 27**; `->withQueryString()` at **line 39** is where task 2's one-line normalization goes.
3. `backend/tests/Feature/Database/TicketReferenceTest.php` — **57 lines.** `test_calling_outside_a_transaction_throws` (**38–52**) is the `DB::commit()` / `DB::beginTransaction()` idiom task 1's new file copies for `ActivityRecorder`.
4. `backend/app/Services/ActivityRecorder.php` — **45 lines.** The guard at **26–28**, identical in shape to `TicketReferenceGenerator.php:12–14`, and never asserted.
5. `backend/app/Http/Controllers/Api/V1/TicketController.php` — **347 lines; read every mutation path.** `changeStatus()` **95–114** (`lockForUpdate` at **100**, `TicketTimestamps` at **105**, `Reopened`-vs-`StatusChanged` at **107–108**); `escalate()` **116–173** (already tested — read for the `escalatedBy` eager-load pattern every other method also uses); `assign()` **207–217** and `changeAssignee()` **219–232** (same-assignee zero-row at **225–227**); `claim()` **249–259** and `claimTicket()` **261–272** (lost-race branch **264–268**, three-way return); `update()` **284–301** (per-field loop **295–297**, no-op return **290–292**); `destroy()` **303–313**; `store()` **315–335**.
6. `backend/app/Services/TicketWorkflow.php` — **43 lines, all of it.** `assertCanTransition()` **20–32**; `allowedTransitions()` **15–17** filters by role; `reject()` **39–42** throws `ValidationException` on `status_id` — every illegal transition is **422, not 403**.
7. `backend/database/seeders/StatusTransitionSeeder.php:12–17` — the `EDGES` constant, unchanged from the previous plan version. Fourteen triples, one admin-only (`resolved → closed`).
8. `backend/app/Services/TicketTimestamps.php` — **28 lines, all of it.** First move off `new` sets `first_responded_at` once (**12–14**); non-terminal target clears `resolved_at`/`closed_at` (**15–19**) — the `closed → reopened` rule AC3 needs; `resolved_at`/`closed_at` set at **21–26**.
9. `backend/app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php` — **27 lines.** **The trap is at 21–24**: `resolution` is `required|min:10` only when the target slug is `resolved`, **`prohibited` otherwise**; `reason` likewise for `reopened`.
10. `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php` — **33 lines.** `assigned_to` **present** and nullable (**20**), `Rule::exists` requires role `agent` and `is_active` true.
11. `backend/app/Http/Requests/Api/V1/UpdateTicketRequest.php` — **31 lines.** `EDITABLE` at **11** (four fields); **fifteen `prohibited` keys at 25–28**.
12. `backend/app/Policies/TicketPolicy.php` — **70 lines.** `delete`/`assign` admin-only (**30–38**), `claim` agent-only (**40–43**), `escalate` (**50–58**) is now a real, tested gate — open to any authenticated staff, terminal-ticket rejection lives in the controller for the 422/403 split.
13. `backend/app/Http/Controllers/Api/V1/CategoryController.php:86–97` — `reassignTickets()`, the only `recordMany()` caller reached over HTTP besides `FlagStaleTickets`. `withTrashed()` at **89**.
14. `backend/app/Enums/TicketActivityEvent.php` — **21 lines, all of it.** Twelve cases (**7–18**); task 6's tripwire iterates `cases()`.
15. `backend/tests/Feature/Tickets/TicketEscalateTest.php` — **322 lines, the model for `Tickets/` test style going forward** — its own `private tokenFor()`/`asAgent()`/`asAdmin()` (**296–316**), `Auth::forgetGuards()` between differently-authenticated calls throughout. **Audited, not edited.**
16. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **147 lines.** `ACCESS` at **17** now includes `tickets.escalate` and `tickets.notes`; `test_every_api_route_is_classified` (**19–26**) is the completeness precedent task 6 follows.
17. `backend/phpunit.xml:20–49` — the `<env>` block task 4 extends after `CACHE_STORE` (**25**).
18. `.github/workflows/ci.yml:13–60` — the backend job, unchanged since the previous version: MySQL 8.4 on **3307**, `cp .env.example .env` at **54**, `pint --test` at **57**, `composer test` at **60**.
19. `backend/tests/Feature/Database/AdminUserSeederTest.php:17–21` — the `config()->set('seeding.admin', …)` override that must still win after task 4's `phpunit.xml` change.
20. [`50-story-factories-and-a-demo-seeder-TM-59.md`](50-story-factories-and-a-demo-seeder-TM-59.md) — **the gate.** Its factory fix is what stops task 5's and task 7's ticket tests from 500ing.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `composer test` | **Exits 1** — 4 failures, all in `TimelineFilterTest` | Exits **0** |
| `TimelineFilterTest`'s two guard-cache tests | Fail on cached Sanctum auth | Pass via `Auth::forgetGuards()` between identity changes |
| `TimelineFilterTest`'s JSON-path assertion | Asserts a nested path that does not exist | Asserts the literal `events.0` key |
| Duplicate `events[]` query values | Filtered data is correct; pagination **links** echo the raw duplicate | Links echo the deduped list too |
| `ActivityRecorder`'s transaction guard | **Never asserted** | Asserted, using the proven `DB::commit()`/`beginTransaction()` idiom |
| `$this->seed()` with no ambient `.env` | `RuntimeException` | Works — `phpunit.xml` supplies `ADMIN_PASSWORD` |
| An illegal status transition | Untested | **422 on `status_id`**, ticket unchanged, **zero** activity rows |
| `resolved → closed` as an agent | Untested | **422**; the same edge as an admin succeeds |
| `PATCH /tickets/{id}` changing 3 fields | Untested | **3** `Updated` rows, one per field |
| `PATCH /tickets/{id}` changing nothing | Untested | **0** rows |
| Re-assigning to the current assignee | Untested | **0** rows |
| Claiming an already-claimed ticket | Untested | **409**, **0** rows |
| Deleting a category holding N tickets | Untested | **N** `CategoryChanged` rows, soft-deleted included |
| A new `TicketActivityEvent` case with no test | Nothing notices | **Build fails** |
| Escalation | **Shipped, tested (TM-41)** | Audited by this story, not rebuilt |

---

## Implementation tasks

### 1 — Assert `ActivityRecorder`'s transaction guard

**Create file: `backend/tests/Feature/Database/ActivityRecorderGuardTest.php`**

```php
<?php

namespace Tests\Feature\Database;

use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class ActivityRecorderGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_calling_outside_a_transaction_throws(): void
    {
        // Same idiom as TicketReferenceTest::test_calling_outside_a_transaction_throws:
        // RefreshDatabase holds an open transaction for the whole test, so
        // DB::transactionLevel() is never 0 unless we commit it first and
        // reopen one before tearDown() tries to roll it back.
        DB::commit();
        try {
            $this->expectException(LogicException::class);
            app(ActivityRecorder::class)->recordMany([1], \App\Enums\TicketActivityEvent::Created);
        } finally {
            DB::beginTransaction();
        }
    }
}
```

Confirm with `php artisan test --filter=ActivityRecorderGuardTest` → passes.

### 2 — Fix `TimelineFilterTest`'s four failures (product line + three test fixes)

**File: `backend/app/Http/Controllers/Api/V1/TicketActivityController.php`** — after line 27:

```php
$events = array_values(array_unique($filters['events'] ?? []));
if (isset($filters['events'])) {
    // withQueryString() below otherwise echoes the raw, undeduped query
    // into the pagination links even though the filter above is deduped —
    // the data matches either way, but the link a client is handed should
    // reflect the filter that was actually applied.
    $request->query->set('events', $events);
}
```

**File: `backend/tests/Feature/Activity/TimelineFilterTest.php`**
- Add `use Illuminate\Support\Facades\Auth;`.
- `test_the_query_count_does_not_grow_with_the_trail` (**120–136**): add `Auth::forgetGuards();` immediately before the `$largeQueries = …` line and before each subsequent `countQueries(...)` call that reuses `$token`.
- `test_the_filter_does_not_widen_access` (**177–186**): add `Auth::forgetGuards();` between the `asAgent()` call and the plain `getJson()` call, and again before the final `asAgent()` call.
- `test_an_unknown_or_malformed_event_filter_is_rejected` (**line 62**): replace `assertJsonPath('errors.events.0', …)` with `$this->assertSame(['Unknown activity event type: not_an_event.'], $response->json('errors')['events.0'])` (capture the response into a variable first).
- `test_a_repeated_event_value_changes_nothing` needs **no test edit** — task 2's product line is the whole fix; confirm it passes once the controller line lands.

### 3 — Ticket mutation and activity tests (AC5)

**Create directory: `backend/tests/Feature/Tickets/`** already exists (holds `TicketEscalateTest.php`, `TicketIndexSortTest.php`) — add five sibling classes. Each `RefreshDatabase`, each `$this->seed()` in `setUp()`, each `Ticket::factory()` — **no `makeTicket()` helper**; each class declares its **own `private tokenFor()`/`asAgent()`/`asAdmin()`**, copying `TicketEscalateTest.php:296–320`'s shape rather than sharing one on `Tests\TestCase` (a `protected` copy there is a fatal error against this file's `private` declaration).

- **`CreateTicketTest.php`** — 201 shape, exactly one `Created` row with `meta.reference` matching; `Requester::firstOrCreate` reuse on a repeated email; default `priority_id`/`status_id` applied when omitted (`TicketController::defaultKey()` **:338–346**); validation failures write no ticket and no activity; missing `category_id` is 422 (`StoreTicketRequest.php:31`).
- **`UpdateTicketTest.php`** — change 1 field → 1 row; change 3 → 3 rows with distinct `field`/`old_value`/`new_value`; change **nothing** → **0** rows, `updated_at` untouched; one test per `prohibited` key (`UpdateTicketRequest.php:25–28`) asserting 422.
- **`DeleteTicketTest.php`** — admin gets 204, ticket soft-deletes, **one** `Deleted` row with `meta.reference`/`meta.subject`; agent gets 403 and nothing is written; the activity row survives the soft delete.
- **`AssignTicketTest.php`** — admin assigns → 1 `Assigned` row with `meta.from_name`/`meta.to_name`; unassign (`null`) → 1 `Unassigned` row; re-assigning to the current assignee → **0** rows; assigning to an admin, an inactive agent, or omitting `assigned_to` → 422 each; an agent → 403.
- **`ClaimTicketTest.php`** — an agent claims an unassigned ticket → 1 `Claimed` row; claiming one already held → **409** naming the holder, **0** rows; claiming one they already hold → 200, **0** new rows; an admin → 403 (`TicketPolicy.php:40–43`).

### 4 — Workflow tests (AC3)

**Create file: `backend/tests/Feature/Tickets/TicketWorkflowTest.php`**

Both matrices generated from `StatusTransitionSeeder::EDGES` (**12–17**) and `StatusSeeder::STATUSES` (**12–20**), never hand-written — identical approach to the previous plan version, unchanged because `EDGES` has not changed:

```php
/** @return list<array{string, string, ?UserRole}> */
public static function legalEdges(): array
{
    return StatusTransitionSeeder::EDGES;
}

/** @return list<array{string, string}> */
public static function illegalPairs(): array
{
    $legal = array_map(fn (array $edge) => $edge[0].'>'.$edge[1], StatusTransitionSeeder::EDGES);
    $slugs = array_column(StatusSeeder::STATUSES, 'slug');
    $pairs = [];
    foreach ($slugs as $from) {
        foreach ($slugs as $to) {
            if ($from !== $to && ! in_array($from.'>'.$to, $legal, true)) {
                $pairs[] = [$from, $to];
            }
        }
    }

    return $pairs;
}
```

**14 legal cases, 28 illegal pairs.** Use `#[DataProvider]`. Tests:
1. Every legal edge succeeds — 200, `status_id` changed, exactly one activity row. Supply `resolution` when the target is `resolved`, `reason` when it is `reopened` (`ChangeTicketStatusRequest.php:23–24`), or the request 422s on the wrong field.
2. Every illegal pair is refused — **422 on `status_id`**, ticket unchanged, **zero** activity rows.
3. `resolved → closed` as an agent → 422; the same request as an admin → 200.
4. A transition to the current status → 422 with the "already" message.
5. Every seeded status has at least one legal and one illegal target (a completeness meta-test).
6. `allowedTransitions()` is role-filtered — from `resolved`, an agent sees `reopened` only, an admin sees `closed` and `reopened`.
7. `TicketTimestamps`: first move off `new` sets `first_responded_at`; a second move does not overwrite it; `→ resolved`/`→ closed` set their timestamps; `closed → reopened` clears both and the event is `Reopened`, not `StatusChanged`.

### 5 — Bulk activity path

**Create file: `backend/tests/Feature/Categories/DeleteCategoryTest.php`** (directory does not exist)

Deleting a category holding 3 tickets, one soft-deleted, writes **3** `CategoryChanged` rows (`CategoryController.php:89` uses `withTrashed()`), each with `old_value`/`new_value`/`meta.reason === 'category_deleted'`. Deleting with tickets remaining and no `reassign_to` → 422 from `reassignmentRequired()` (**:78–84**). Deleting an empty category → 204, **0** rows.

### 6 — The completeness tripwire

**Create file: `backend/tests/Feature/Activity/ActivityCoverageTest.php`**

```php
private const PRODUCERS = [
    'created' => 'Tests\Feature\Tickets\CreateTicketTest',
    'category_changed' => 'Tests\Feature\Categories\DeleteCategoryTest',
    'note_added' => 'Tests\Feature\Activity\TicketNotesTest',
    'updated' => 'Tests\Feature\Tickets\UpdateTicketTest',
    'deleted' => 'Tests\Feature\Tickets\DeleteTicketTest',
    'assigned' => 'Tests\Feature\Tickets\AssignTicketTest',
    'claimed' => 'Tests\Feature\Tickets\ClaimTicketTest',
    'unassigned' => 'Tests\Feature\Tickets\AssignTicketTest',
    'status_changed' => 'Tests\Feature\Tickets\TicketWorkflowTest',
    'reopened' => 'Tests\Feature\Tickets\TicketWorkflowTest',
    'escalated' => 'Tests\Feature\Tickets\TicketEscalateTest',
    'stale' => 'Tests\Feature\Console\FlagStaleTicketsTest',
];
```

Three tests, modelled on `RouteAuthorizationTest.php:19–26`:
1. `test_every_event_case_has_a_producing_test` — every `TicketActivityEvent::cases()` value is a `PRODUCERS` key. **Fails the day a 13th case is added with no entry.**
2. `test_every_named_producer_class_exists` — each value resolves via `class_exists()`.
3. `test_every_stored_event_round_trips_through_the_enum` — after the full suite's paths have run once, every distinct `event` value in `ticket_activities` satisfies `TicketActivityEvent::tryFrom()`.

### 7 — No changes to `TicketEscalateTest`, `TicketNotesTest`, or `FlagStaleTicketsTest`

All three are audited, not edited or rewritten — the PR names them as the evidence AC4's audit is closed and that two of AC5's twelve cases arrived pre-covered by TM-43 and TM-47.

### No frontend changes required.

`frontend/` is untouched. TM-61 owns component tests; `ci.yml:62–88` already runs the frontend job.

---

## Edge Cases & Failure Modes

- **Story 50 has not landed.** Any test that factory-creates a ticket then calls a ticket-mutating endpoint gets **500, `1062 Duplicate entry`** — reproduced 2026-08-28 in isolation. **Stop and land Story 50 before task 3 or task 5.**
- **A workflow test posting a bare `status_id` to `resolved` or `reopened`.** `ChangeTicketStatusRequest.php:23–24` makes `resolution`/`reason` conditionally `required`/`prohibited` — a careless test reads the resulting 422 as "the transition was refused" when it is really a validation miss.
- **Asserting 403 for an illegal transition.** `TicketWorkflow::reject()` (**39–42**) throws `ValidationException` — it is **422 with a `status_id` key**, never 403.
- **Sanctum's guard cached across requests in one test method.** The root cause of 2 of the 4 currently-red tests, and the thing to check first in any new test that authenticates as more than one identity: `Auth::forgetGuards()` between them, as `RouteAuthorizationTest.php:132/134` and `TicketEscalateTest.php` (throughout) already do.
- **`RefreshDatabase` makes `DB::transactionLevel()` guards untestable unless you commit and reopen.** Task 1's file uses the proven `DB::commit()` / `DB::beginTransaction()` idiom from `TicketReferenceTest.php:38–52` — do not introduce a second class shape (no `RefreshDatabase`) for this; the existing idiom already works inside it.
- **A blank `ADMIN_PASSWORD`.** `$this->seed()` → `RuntimeException` until task 4's `phpunit.xml` entry lands.
- **The FULLTEXT index is invisible inside an open transaction.** `docs/erd.md:77`. This story still writes no full-text search test for that reason — `GET /tickets?q=` stays uncovered, named here rather than hidden.
- **`update()` with no changes still returns 200.** `TicketController.php:290–292` returns before `save()` — assert **0** activity rows, not 204 or 422.
- **Re-assigning to the current assignee returns 200 and writes nothing.** `changeAssignee():225–227` returns `false`.
- **`claim()` has three outcomes.** `claimTicket():264–268` returns `null` (won or already yours), `false` (unassigned mid-flight → generic 409), or an int (named 409). The `false` branch needs `claimTicket()`'s conditions driven directly — it is unreachable over HTTP without a concurrent writer.
- **`CategoryChanged` counts soft-deleted tickets** (`CategoryController.php:89`, `withTrashed()`).
- **The `Deleted` activity row outlives its ticket** — `ticket_activities.ticket_id` cascades only on a hard delete.
- **`ADMIN_PASSWORD` colliding with `UserFactory`'s literal `'password'`** (`UserFactory.php:32`) — use a distinct value.
- **`escalation_level` is read in two places** (`TicketController.php:61`, presumably `TicketStats`) **but this is no longer untestable** — `TicketEscalateTest` already writes it; a new test asserting the read side is in scope if convenient, but not required by this story's ACs.
- **A new route added without a classification.** Already handled by `RouteAuthorizationTest.php:19–26`.

---

## Test Plan

**Files created:** `tests/Feature/Database/ActivityRecorderGuardTest.php`; `tests/Feature/Tickets/{CreateTicketTest,UpdateTicketTest,DeleteTicketTest,AssignTicketTest,ClaimTicketTest,TicketWorkflowTest}.php`; `tests/Feature/Categories/DeleteCategoryTest.php`; `tests/Feature/Activity/ActivityCoverageTest.php`. One new directory: `tests/Feature/Categories/`.

**Files edited:** `tests/Feature/Activity/TimelineFilterTest.php` (3 of its 4 failures fixed by test edits); `app/Http/Controllers/Api/V1/TicketActivityController.php` (1 line); `phpunit.xml` (1 `<env>`).

**Order, because each unblocks the next:**
1. **Task 1 and task 2 first.** Until `composer test` exits 0, no new test's result can be trusted.
2. **Task 4 (workflow) second** — no fixtures beyond a seeded database and one ticket.
3. **Task 3 (mutation paths) third** — needs Story 50's factory fix.
4. **Task 5, then task 6 last** — the tripwire's `PRODUCERS` map is only correct once every producing class exists.

---

## Verification Steps

1. **Services:** `docker compose ps` → all three healthy.
2. **Confirm the gate.** `grep -n "static int \\\$sequence" database/factories/TicketFactory.php` → still present. If Story 50 has landed and this is gone, skip the isolated-collision check in step 3.
3. **Confirm the starting point.** `composer test` → **227 tests, 223 passing, 4 failing, exit 1**, all four in `TimelineFilterTest`.
4. **Task 1.** `php artisan test --filter=ActivityRecorderGuardTest` → passes. Temporarily remove the `DB::commit()`/`beginTransaction()` pair and confirm it now errors with an *"open transaction"* PDO error instead of failing cleanly — restore.
5. **Task 2.** `php artisan test --filter=TimelineFilterTest` → all tests pass, including `test_a_repeated_event_value_changes_nothing` (proves the product line is the actual fix, not a coincidence). Then temporarily revert the `TicketActivityController.php` line and confirm only that one test fails again — restore.
6. **The suite goes green.** `composer test` → exit **0**.
7. **Task 4 earns its providers.** `php artisan test --filter=TicketWorkflowTest` → **42 data cases**. Remove one triple from `StatusTransitionSeeder::EDGES`, re-run, confirm 13/29 with no test edit, restore.
8. **Task 6, run twice.** `php artisan test --filter=ActivityCoverageTest` → passes. Add `case Fabricated = 'fabricated';` to `TicketActivityEvent`, re-run, confirm `test_every_event_case_has_a_producing_test` fails naming `fabricated` — remove it.
9. **Zero-row branches.** For each of the three, delete the guard and confirm the corresponding test fails: (a) `TicketController.php:290–292`, (b) `changeAssignee():225–227`, (c) `claimTicket():264` `$won === 0`. Restore each.
10. **Backend formats:** `./vendor/bin/pint --test` → exit 0.
11. **Full suite, measured.** `composer test` — quote the real numbers in the PR.
12. **Clean database.** `docker compose down -v && docker compose up -d --wait`, then `composer test` from `backend/` with no manual migration first. Exit 0.
13. **Clean environment.** `mv .env .env.bak && cp .env.example .env && php artisan key:generate && composer test` → exit 0, then restore `.env`.
14. **CI, for real.** Push the branch; confirm the **Backend - Pint + PHPUnit** job passes.
15. **Regression:** `git status` shows no migration, nothing under `frontend/`, and the only production files changed are `phpunit.xml` and `TicketActivityController.php`.

---

## Done Criteria

- [ ] **`composer test` exits 0 and the CI backend job is green** — verified on a wiped volume and a `.env` freshly copied from `.env.example`.
- [ ] `TimelineFilterTest`'s four failures are fixed: two via `Auth::forgetGuards()` between differently-authenticated calls, one via the corrected `errors.events.0` assertion, one via the `TicketActivityController.php` query-string normalization — proven both ways (revert, watch it fail, restore).
- [ ] `ActivityRecorder`'s transaction guard is asserted for the first time, using the `DB::commit()`/`beginTransaction()` idiom already proven in `TicketReferenceTest.php`.
- [ ] `phpunit.xml` sets `ADMIN_PASSWORD` to a value distinct from `UserFactory`'s `'password'`; `AdminUserSeederTest` still passes on its own override.
- [ ] **AC3 in full**, generated from `StatusTransitionSeeder::EDGES`/`StatusSeeder::STATUSES` — 14 legal edges, 28 illegal pairs, the one admin-only edge, current-status rejection, role-filtered `allowedTransitions()`, and `TicketTimestamps` all asserted.
- [ ] **AC5: the remaining 9 of 12 mutation-path event cases are tested** — `created`, `updated` (+ 0-row no-op), `deleted`, `assigned`/`unassigned` (+ 0-row same-assignee), `claimed` (+ 409 conflict), `status_changed`, `reopened`, `category_changed` (soft-deleted included). `escalated`, `note_added`, `stale` are audited against existing tests, not rebuilt.
- [ ] **A tripwire ties all 12 `TicketActivityEvent::cases()` to a named producing test class**, three of them crediting `TicketEscalateTest`, `TicketNotesTest`, and `FlagStaleTicketsTest` rather than duplicating their coverage.
- [ ] **AC4 is audited and closed, not built** — the PR names `TicketEscalateTest`'s 20 tests as the evidence, correcting the previous plan version's "undeliverable" call now that TM-41 has shipped.
- [ ] AC1 and AC2 are audited and closed, not rebuilt.
- [ ] No `makeTicket()`/shared `tokenFor()` helper is introduced; each new class declares its own, matching `TicketEscalateTest.php`'s pattern.
- [ ] `GET /tickets?q=` stays named as uncovered (FULLTEXT + open-transaction visibility), not silently skipped.
- [ ] **Only two production files are changed**: `phpunit.xml` and `TicketActivityController.php`, both justified above. No migration, no route, no other controller, no policy, no model, no request, no seeder, no factory, no enum case, no `docs/` change, no frontend file, no new dependency.
- [ ] `pint --test` clean; the PR quotes the measured test and assertion totals from a real run.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 52 (TM-61, frontend component tests).**
