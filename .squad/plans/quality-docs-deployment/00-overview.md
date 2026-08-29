# quality-docs-deployment — plan overview

Entry point for the **quality-docs-deployment** feature. Stories execute in order by their `NN` prefix. `NN` continues the global sequence that runs through [`../foundation-environment/00-overview.md`](../foundation-environment/00-overview.md), [`../authentication-agent/00-overview.md`](../authentication-agent/00-overview.md), [`../categories-priorities-statuses/00-overview.md`](../categories-priorities-statuses/00-overview.md), [`../ticket-creation-tracking/00-overview.md`](../ticket-creation-tracking/00-overview.md), [`../assignment-workload/00-overview.md`](../assignment-workload/00-overview.md), [`../status-workflow-escalation/00-overview.md`](../status-workflow-escalation/00-overview.md), [`../ticket-history-audit-trail/00-overview.md`](../ticket-history-audit-trail/00-overview.md) and [`../email-notifications/00-overview.md`](../email-notifications/00-overview.md).

Jira epic **E9 — Quality, Docs & Deployment**: *"Make the system demonstrable, tested, documented and safely deployable."* Six stories, TM-59 through TM-64, **all sprint 5**. **All six are planned** (Stories 50–55).

## Stories

| NN | File | Title | Tracker id | Depends on | Status |
|----|------|-------|------------|------------|--------|
| 50 | [`50-story-factories-and-a-demo-seeder-TM-59.md`](50-story-factories-and-a-demo-seeder-TM-59.md) | Factories and a demo seeder | TM-59 | Story 35 (TM-41) — **hard gate for AC3's escalation history**, supplies `TicketActivityEvent::Escalated`; **blocks Story 51 outright** — see the note below | 📝 Planned — 2026-08-27 |
| 51 | [`51-story-backend-feature-test-suite-TM-60.md`](51-story-backend-feature-test-suite-TM-60.md) | Backend feature test suite | TM-60 | **Story 50 — hard gate, re-measured 2026-08-28, still open**: its factory fix is what stops every ticket test 500ing. Story 35 (TM-41) **has now shipped** — AC4 is done and only needs auditing | 📝 Re-planned — 2026-08-28 |
| 52 | [`52-story-frontend-component-tests-TM-61.md`](52-story-frontend-component-tests-TM-61.md) | Frontend component tests | TM-61 | None — frontend-only, independent of Stories 50/51 | 📝 Planned — 2026-08-28 |
| 53 | [`53-story-api-documentation-TM-62.md`](53-story-api-documentation-TM-62.md) | API documentation | TM-62 | None — docs-only, independent of Stories 50–52 | 📝 Planned — 2026-08-28 |
| 54 | [`54-story-production-build-and-deployment-runbook-TM-63.md`](54-story-production-build-and-deployment-runbook-TM-63.md) | Production build and deployment runbook | TM-63 | None — docs-only, independent of Stories 50–53 | 📝 Planned — 2026-08-28 |
| 55 | [`55-story-security-hardening-pass-TM-64.md`](55-story-security-hardening-pass-TM-64.md) | Security hardening pass | TM-64 | None — independent of Stories 50–54; the "write" rate limiter should land before Story 51's tests are executed, since Story 51 adds heavy write-endpoint coverage (see the note below) | 📝 Planned — 2026-08-28 |

## Dependency notes

### Story 50 — factories and the demo seeder

- **Story 50 is a fix-and-complete, not a greenfield build, and whoever executes it should resist rebuilding what already exists.** `backend/database/factories/` already holds **`UserFactory.php`, `RequesterFactory.php` and `TicketFactory.php`** — the E4 stories landed two of AC1's five factories ahead of schedule. **`grep -rln "Ticket::factory\|Requester::factory\|TicketFactory" backend/tests/` returns nothing**: both arrived with **no test at all**, which is why the defect below has gone unnoticed.

- **The single most consequential line in this epic: `TicketFactory` invents ticket references, and it breaks the create endpoint.** `TicketFactory.php:16` declares `private static int $sequence = 0;` and **line 21** builds `sprintf('TKT-%d-%06d', now()->year, ++self::$sequence)` — **`ticket_sequences` is never touched.** Measured 2026-08-27:
  ```
  Ticket::factory()->count(3)->create()   =>  TKT-2026-000001 … 000003
  SELECT * FROM ticket_sequences          =>  [] (empty)
  TicketReferenceGenerator::next()        =>  TKT-2026-000001
  => UniqueConstraintViolationException: 1062 Duplicate entry 'TKT-2026-000001'
                                         for key 'tickets.tickets_reference_unique'
  ```
  **`POST /api/v1/tickets` 500s on the first ticket filed after any factory-seeded run.** The fix allocates through `TicketReferenceGenerator`, opening a transaction when not already inside one — measured at **26 ms for 50 allocations outside a transaction, 28 ms inside**, so there is no performance argument for a private counter. The price: **`Ticket::factory()->make()` now needs a live database connection.** Every test here has one by design (`phpunit.xml:27–42` forbids SQLite) and `tests/Unit/` currently calls no factory, but a future unit test will.

- **Three further defects in `TicketFactory`, all measured, all fixed by Story 50.** (a) **No `created_at` anywhere in `definition()`** — every factory ticket is dated `now()`, so AC4 is not merely unmet but contradicted. (b) `category_id` at **line 25** is `fn () => Category::query()->value('id')` with no fallback: on an unseeded database it yields `1048 Column 'category_id' cannot be null`, a message naming the symptom and hiding the cause, where `TicketController::defaultKey()` throws one that says *"Run `php artisan db:seed`"*. (c) The factory's `'TKT-%d-%06d'` diverges from the generator's `'TKT-%04d-%06d'` (`TicketReferenceGenerator.php:27`) — identical for a four-digit year, a silent trap otherwise. **A fourth, recorded not fixed:** `self::$sequence` is static per process, and it was observed persisting across test methods in one run. `--parallel` is not configured, but if it ever is, two processes both start at `0`.

- **`HasFactory` is needed on two models, not six.** Measured: `grep -rn "HasFactory" backend/app/Models/` hits `Requester.php:13`, `Ticket.php:17` and `User.php:24` only. **`Category` and `TicketActivity` still throw `BadMethodCallException`**, as do `Priority` and `Status` — deliberately, and permanently.

- **No `PriorityFactory` and no `StatusFactory`, permanently.** AC1 names five factories and omits these two, correctly: both tables carry a unique index over a generated column (`priorities_single_default_unique`, `statuses_single_default_unique`) permitting exactly one default row, `priorities.level` is `UNIQUE` too, and the four priorities and seven statuses **are** the product rather than sample data. TM-16 warned about it by name four times — measured: `DB::table('priorities')->insert([… 'is_default_unique' => 1])` raises `ERROR 3105`, reaching the SPA as a 500, not a validation error. Story 50 ships a tripwire asserting both models still lack `HasFactory`.

- **`db:seed --force` does not respect artisan's production prompt.** `ConfirmableTrait.php:26–29` returns `true` before any prompt when `--force` is passed, and `--force` is what every deploy script uses. So `php artisan db:seed --force --class=DemoSeeder` on a production host would run silently. **The guard is a `RuntimeException` as the first statement of `DemoSeeder::run()`**, blocking only `production` so `local` and `testing` stay testable. **The test for it has a trap:** `config()->set('app.env', 'production')` leaves `app()->environment()` at `testing` (measured), so the obvious version passes **without ever executing the guard**. `app()->detectEnvironment(fn () => 'production')` works.

- **`DemoSeeder` refuses a non-empty `tickets` table rather than pretending to be idempotent**, via `Ticket::withTrashed()->exists()` — `DELETE /api/v1/tickets/{ticket}` soft-deletes, so a dataset someone soft-deleted while exploring TM-28 must still block a re-seed.

- **AC2's coverage is arithmetic, not luck.** Round-robin (`$i % 7`, `$i % 4`) over 50 tickets gives **7 distinct statuses, 4 distinct priorities, 28 distinct pairs** (measured). An `inRandomOrder()` version passes locally and fails in CI about one run in a hundred, reading as flakiness rather than a missing guarantee.

- **⚠ Drift since Story 50 was written, 2026-08-27 — the plan is stale on one point and it is in the story's favour.** Story 50's plan records that `TicketActivityEvent` was missing `Assigned`, `StatusChanged` **and** `Escalated`. Stories 26, 27, 32 and 34 have since landed, and the enum now holds **nine** cases: `Created`, `CategoryChanged`, `Updated`, `Deleted`, `Assigned`, `Claimed`, `Unassigned`, `StatusChanged`, `Reopened`. **Only `Escalated` is still absent**, so Story 50's task 2 has less to do than its plan says, and Story 35 (TM-41) is its one remaining enum gate. Story 38 (TM-45) has also landed — `Ticket::activities()` exists (`Ticket.php:59–62`) and `ActivityRecorder` now applies its defaults in `recordMany()` (**29–32**) — so Story 50's soft gate is discharged and its test 16 can assert through the relation. **The plan file is not edited for this; the drift is recorded here.**

### Story 51 — the backend feature test suite

- **⚠ Re-planned 2026-08-28 — the plan file was rewritten, not just annotated, because the ground moved.** Story 35 (TM-41, escalation) shipped on 2026-08-27 with its own 20-test `TicketEscalateTest.php`, so **AC4 is now done** and the previous "undeliverable, raise as a deviation" call is withdrawn. Both test bugs the first version diagnosed (`PasswordThrottleTest`, `TicketReferenceTest`) are **already fixed independently**, and not via the `TransactionGuardTest` class the first version specified — the repo found a better fix (commit-and-reopen the `RefreshDatabase` transaction) and the new plan copies that idiom instead. A **new** regression appeared that the first version never saw: `TimelineFilterTest.php` (from TM-49) has 4 failing tests, all diagnosed by probe in the current plan file — two Sanctum guard-cache bugs, one wrong JSON-path assertion, and one real one-line product inconsistency in `TicketActivityController.php`'s pagination links. **The current plan file is the one to execute; this note exists so nobody re-derives the old AC4 conclusion from memory.**

- **Story 50 is a hard gate for Story 51, and the failure is measured rather than predicted.** The canonical shape of every ticket test in Story 51 — a factory fixture, then exercise the endpoint — **already returns 500**:
  ```
  $this->seed(); Ticket::factory()->create();
  POST /api/v1/tickets  =>  500  ("1062 Duplicate entry 'TKT-2026-000001'")
  ```
  **Do not start Story 51 before Story 50 lands.** Roughly every class in its tasks 3 and 4 would fail for a reason unrelated to what it asserts.

- **Two of TM-60's six criteria are already satisfied, and rebuilding them is the main waste to avoid.** **AC1** (authentication) is covered by **40 tests** across `tests/Feature/Auth/` — login, validation, logout, both throttles, and the inactive-user case twice over. **AC2** (agent 403 on every admin-only endpoint) is covered by `Authorization/RouteAuthorizationTest`'s 13 tests, and in a **stronger** form than the criterion asks for: `test_agent_refused_by_admin_routes` and `test_agent_refused_by_policy_admin_routes` iterate the `ACCESS` map (**line 17**) rather than a hand-written list, and `test_every_api_route_is_classified` (**19–26**) fails the build when a route is added without a classification. Story 51 audits and closes both; it rewrites neither.

- **AC6 is currently false, and it gates the other five.** `.github/workflows/ci.yml:16–31` already runs **MySQL 8.4 on port 3307** matching `phpunit.xml:36–41`, so the infrastructure half has been done since TM-6 — **but `composer test` exits 1**, so the backend job is red. Measured baseline 2026-08-27: **110 tests, 335 assertions, 108 passing, 2 failing**; `pint --test` clean. A suite that does not pass proves nothing about the rules inside it, which is why Story 51 does AC6 first.

- **Both red tests are test bugs, both were diagnosed by probe, and neither needs an application change.** This corrects two stale attributions in earlier plans.
  1. **`PasswordThrottleTest` is not TM-14's bug.** Earlier plans record it as one, which reads as a defect in the password feature. The limiter is correct — `AppServiceProvider.php:26–27` keys by user id, and the router's priority list puts `AuthenticatesRequests` (index **6**) ahead of `ThrottleRequests` (**7**), so the user *is* resolved first. Measured: the first user gets `422,422,422,422,422,422,429` — exactly right — and the **second** user then gets **429 on their first attempt**, because **Sanctum caches the first resolved user for the lifetime of the test method**, so the limiter keeps keying on user 1. Proven both ways: without `Auth::forgetGuards()` the second user gets 429; with it, 422. **The fix is one line, and `RouteAuthorizationTest:132` already uses that idiom.** Do not "fix" the limiter to satisfy a broken test.
  2. **`TicketReferenceTest::test_calling_outside_a_transaction_throws` is Story 38's undelivered task.** `RefreshDatabase` (**line 14**) holds an open transaction, so `DB::transactionLevel()` is 1 and the guard at `TicketReferenceGenerator.php:12–14` can never fire. Story 38's plan owned the fix (its tasks 2 and 4) and its **code landed while its tests did not** — `grep -rl ActivityRecorder backend/tests/` returns nothing. Story 51 delivers it: move that one test to a class **without** the trait, and add the sibling assertion for `ActivityRecorder`'s identical guard, **which has never been asserted at all**.

- **The suite depends on an ambient `.env` value nothing declares.** Measured: `config()->set('seeding.admin.password', null)` then `$this->seed()` → `RuntimeException: ADMIN_PASSWORD is empty`. `AdminUserSeeder.php:17–19` aborts on a blank password, `phpunit.xml` never sets one, and **`RouteAuthorizationTest:48` and `:57` call `$this->seed()` with no argument**. They pass only because the developer's `.env` and CI's `cp .env.example .env` both happen to carry it. Story 51 adds `ADMIN_PASSWORD` to `phpunit.xml` — **the only production file it touches** — with a value deliberately distinct from `UserFactory`'s literal `'password'`.

- **AC3's matrix is generated from `StatusTransitionSeeder::EDGES`, never restated, and the reason is the seeder's own pruning.** `StatusTransitionSeeder.php:25` is `whereKeyNot($keptIds)->delete()`: the constant is authoritative and the table is a projection of it, so a test asserting a hand-listed edge would keep passing after that edge was removed from the constant — assertion and data wrong together. Computed while planning: **7 statuses, 14 legal edges, 28 illegal pairs**, every status has at least one of each (checked, not assumed), and there is exactly **one** admin-only edge, `resolved → closed`. Data providers give full coverage where a hand-written test gets one case per status.

- **Every illegal transition is a 422, not a 403.** `TicketWorkflow::reject()` (**39–42**) throws `ValidationException` on the **`status_id`** key. A test written for 403 fails against correct code.

- **The workflow tests' sharpest trap is `ChangeTicketStatusRequest:19–25`.** `resolution` is `required|min:10` when the target slug is `resolved` and **`prohibited` otherwise**; `reason` likewise for `reopened`. A legal-edge test posting a bare `status_id` to `resolved` gets **422 on `resolution`** and reads it as "the transition was refused" — a green-looking test asserting nothing.

- **AC5's completeness is enforced by the enum, not a checklist.** Seven mutation paths write activity rows, and **ten branches** across them produce all nine enum cases — including **three that must write zero rows**: `update()` with no changes (`TicketController:196–198`), re-assigning to the current assignee (`:131–133`), and a lost claim race (`:170–174`). The zero-row branches are the ones a hand-written suite omits. A tripwire over `TicketActivityEvent::cases()` against a `PRODUCERS` map, modelled on `RouteAuthorizationTest:19–26`, **fails the day a case is added without a test** — which is also how AC4 stays visible.

- **AC4 is undeliverable and is raised, not absorbed.** *"Escalation tests assert the level increment, the priority bump and its ceiling, and the activity row"* — all four describe code that does not exist. There is **no `Escalated` enum case**, no `escalate()` controller method and no route; `escalation_level` is **read** in two places (`TicketController:58`, `TicketStats:26`) and **written in none**. What exists is forward scaffolding from two earlier stories: `TicketPolicy::escalate()` (**50–53**) and `TicketResource`'s `can.escalate` flag (**line 29**) — permissions for an unimplemented feature. **Story 35 (TM-41) owns it.** Story 51 writes **no `markTestSkipped` placeholder** — a skipped test is a green build that reads as coverage — and recommends AC4 move to TM-41's definition of done.

- **One branch Story 51 leaves uncovered, named rather than hidden: `GET /tickets?q=`.** `docs/erd.md:77` — *"FULLTEXT index updates at commit; invisible inside open transaction."* A test that creates tickets and full-text-searches them under `RefreshDatabase` **finds nothing**, and the failure reads as a broken query. Covering it needs a class without the trait and explicit cleanup; that is TM-25's or a follow-up's.

- **`tokenFor()` must be copied, not hoisted.** `RouteAuthorizationTest:138–141` declares it `private`. A `protected` helper on `Tests\TestCase` is a **fatal error at class-declaration time** against a subclass declaring it `private` (`../ticket-history-audit-trail/42-story-…-TM-49.md:135`). Five copies of three lines is correct here, and the PR says so.

### Story 52 — frontend component tests

- **None of the intake's three named components exist under those names.** "TicketTable" is the inline `<table>` in `TicketListView.vue` (its own `.spec.ts` already exists, with only 3 escalation-focused tests); "TicketFilters" is `TicketFilterBar.vue` (no spec file); "TicketForm" is the inline form in `NewTicketView.vue` (no spec file). Story 52 extends the first, creates the other two, and creates neither a `TicketTable.vue` nor a `TicketForm.vue` — that would be an unbudgeted refactor.

- **AC4 (the auth store) is already fully covered.** `stores/auth.spec.ts` has 6 tests spanning login, logout (including the failure path), and three rehydration scenarios (concurrent-caller dedup, 401 clears the token, network failure preserves it). Story 52 audits this, it does not add to it.

- **AC5 names a script (`npm run test:unit`) that has never existed in this repo.** `package.json` only has `"test": "vitest run"`, and CI calls `npm test`. Story 52 adds a real `test:unit` alias and repoints CI's Vitest step at it, rather than treating the AC's wording as a typo — both scripts run the same underlying command, so nothing about what CI executes changes, only its name.

- **Independent of Stories 50 and 51.** Frontend-only; no dependency either direction.

### Story 53 — API documentation

- **`docs/api-contract.md` is 293 lines, not a placeholder — 20 of 29 routes are already documented in full, written incrementally by the stories that shipped each endpoint.** The gap is 8 routes with no table row at all (`priorities.index`, `statuses.index`, `tickets.stats`, `tickets.update`, `tickets.destroy`, `tickets.assign`, `tickets.claim`, `tickets.status`) plus 2 that have a row but no prose section (`tickets.store`, `tickets.show` — a debt the doc already admits to at its own line 228–230).

- **AC5 ("generated or verified in CI") is met by a verification test, not a generator.** No OpenAPI/Scribe tooling exists in this repo; introducing one would mean re-deriving 293 existing lines through a new toolchain. The plan adds a feature test modelled on `RouteAuthorizationTest`'s completeness-tripwire pattern that parses the doc's table and diffs it against `Route::getRoutes()` in both directions — it runs inside `composer test`, which CI already calls, so no new CI step is needed.

- **The ERD (`docs/erd.md`, 81 lines) already knows it's missing `ticket_activities` from its own diagram** — the table-notes row says so explicitly. Story 53 closes that gap and writes the ticket-lifecycle overview AC4 also asks for, as a new file that cross-links the ERD and API contract rather than duplicating either.

- **Independent of Stories 50–52.** Docs and one new backend test file only; no application source change.

### Story 54 — production build and deployment runbook

- **No Dockerfile, no production `docker-compose`, no CD pipeline exists anywhere in the repository.** `docker-compose.yml`'s own header comment says it is local-only. Story 54 documents a traditional host deployment (PHP-FPM/web server + a statically-served SPA build) rather than inventing a containerized or cloud-specific target the codebase has not decided on.

- **`docs/deployment-runbook.md` is a genuine placeholder except for one section.** Only `## Scheduled tasks` has real content (verified against `routes/console.php:17` and it's still accurate) — everything else (`Environments`, `Deploy procedure`, `Rollback procedure`, `Post-deploy checks`) is a stub. Story 54 leaves the scheduler section byte-identical and writes everything else from scratch.

- **The frontend build was actually run during planning, not assumed.** `npm run build` from `frontend/` succeeds; output is `frontend/dist/` (confirmed no `build.outDir` override in `vite.config.ts`). Real bundle sizes are quoted in the plan.

- **`APP_VERSION` is not actually set by CI**, despite two comments in the codebase (`.env.example:6`, `config/app.php:24`) claiming it is. `grep -n "APP_VERSION" .github/workflows/ci.yml` returns nothing. The runbook documents the real manual step instead of repeating the stale comment.

- **The one real first-deploy risk: `ADMIN_PASSWORD`'s example value is the literal string `password`.** `AdminUserSeeder` refuses a *blank* password but not that specific one — a deploy that copies `.env.example` verbatim ships a guessable admin credential. This is the first, boldest item in the new first-deploy checklist.

- **Independent of Stories 50–53.** Docs-only; no application source change.

### Story 55 — security hardening pass

- **Five of the six ACs were already true on audit, 2026-08-28 — only rate limiting had a real gap.** Measured directly: CORS has an explicit allowlist with no wildcard (`config/cors.php:22–25`); all 8 app models already carry `#[Fillable]`; no `v-html` exists anywhere in the frontend; every raw-SQL call site in `app/` either uses `?` bindings or is a static string with no interpolated variable; and the entire git history is one commit with no secret ever committed. Story 55 does not rebuild any of these — it adds one tripwire test (or lint rule, or CI job) per AC so each stays true automatically, rather than only being true because an agent checked it once.

- **The real gap: only `auth.login` and `auth.password` are rate limited.** 14 other write routes (`auth.logout`, all of `categories.*`/`tickets.*`/`admin.users.*` except read endpoints) have no throttle at all. Story 55 adds one new named limiter (`write`, 60/min per user) and applies it to all 14.

- **The 60/min rate was chosen with Story 51 specifically in mind.** Verified: Laravel's test harness boots a fresh app (and therefore a fresh `CACHE_STORE=array` instance) per test method and per `#[DataProvider]` row, so rate-limit state cannot leak between Story 51's planned tests — including its 42-case `TicketWorkflowTest`. No existing or planned test comes close to 60 write calls in one method.

- **AC6's tooling choice is deliberate: `gitleaks`, the one new external dependency in this entire epic.** Every other TM-59–63 story avoided adding tooling; this one is specifically about the risk class that a maintained secret-scanner exists to catch, and a hand-rolled regex would only catch patterns its author thought of.

- **Independent of Stories 50–54**, though the `write` rate limiter should land before Story 51's write-heavy test suite is actually executed against a shared environment, to avoid the two stories' test runs interfering if ever run concurrently against the same cache.

### Across the epic

- **`docs/` is still three placeholders and two E9 stories own them.** `docs/deployment-runbook.md` is **24 lines** of headings (**TM-63**), `docs/api-contract.md` **127 lines** (**TM-62**), `docs/erd.md` **79 lines** with `ticket_activities` missing entirely — **Story 38 was to add it and did not**, so the ERD is still short an entity. **Neither Story 50 nor Story 51 edits any of them.**

- **A backlog gap E9 does not close: nothing owns a stale-ticket notification.** Carried forward from [`../email-notifications/00-overview.md`](../email-notifications/00-overview.md) — Story 37 dispatches `TicketsFlaggedStale` with no listener anywhere in the backlog, which is why `TICKETS_STALE_NOTIFY` defaults to `false`. TM-59 through TM-64 cover fixtures, backend tests, frontend tests, docs, deployment and hardening; **none of them fills it.** Either E8 gains a story or the flag stays off.

- **Story 38 landing its code but not its tests is a pattern worth watching.** `ActivityRecorder`'s defaults and `Ticket::activities()` are both present; its 21 planned tests, its `docs/erd.md` entry and its fix for the red `TicketReferenceTest` are all absent. **Story 51 picks up the red test and the recorder guard**; the ERD entry and the single-writer tripwire remain unclaimed. Whoever closes TM-45 should be told it is not done.
