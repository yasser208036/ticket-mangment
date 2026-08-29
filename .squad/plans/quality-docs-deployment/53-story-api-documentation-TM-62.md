# Story 53 — API documentation (Story: TM-62)

## Prerequisites

- None. Independent of Stories 50–52. Documentation and one new backend test file only.
- **`docs/api-contract.md` is not a placeholder — it is 293 lines, written incrementally by earlier stories.** Read it before writing anything; this story extends it, it does not start it. Same for `docs/erd.md` (81 lines, a working Mermaid diagram, not the 3-line stub referenced by older planning notes).

---

## What already exists — audit before you write

| AC | Status | Evidence |
|---|---|---|
| **AC1** — every endpoint documented (method, path, params, body, response) | **20 of 29 routes documented; 8 missing entirely; 2 have a table row but no detail section.** | See the route table below. |
| **AC2** — auth + role per endpoint | **Done for the 20 documented rows.** The summary table's `Auth` column already states bearer/admin/none per row (`docs/api-contract.md:32–54`). New rows need the same column filled in, nothing more. | `docs/api-contract.md:18–28` (`## Authorization` section) |
| **AC3** — 422 and 403 documented | **Done for the endpoints that have a detail section** (escalate, notes, activities, login, logout, me, password, workload — each names its 422/403/401 cases explicitly). **Missing wherever AC1 is missing** — no section, no error cases. | e.g. `docs/api-contract.md:105–111` (escalate's 422 cases) |
| **AC4** — ERD + ticket lifecycle overview | **ERD exists but is admittedly incomplete; no lifecycle overview file exists at all.** `docs/erd.md:76` documents `ticket_activities` in the table-notes row but its own text says *"Not yet drawn in the diagram above — pre-existing gap from TM-45, not backfilled by this story."* `find docs -iname "*lifecycle*"` → no output. | `docs/erd.md:13–63` (diagram), `:76` (the admission) |
| **AC5** — generated or verified in CI | **Nothing verifies it.** `grep -rl "api-contract" backend/tests/` → no output. `ci.yml`'s frontend/backend jobs never touch `docs/`. | — |

**The honest shape of this story: document 8 missing endpoints in full, upgrade 2 existing table-only rows to full sections, draw `ticket_activities` into the ERD, write a new ticket-lifecycle overview, and add a CI-enforced completeness test.**

---

## Decision — "generated or verified" means verified by a feature test, not a generated OpenAPI spec

This repo has no OpenAPI/Swagger tooling (`grep -rn "scribe\|l5-swagger\|openapi" backend/composer.json` → no output), and every existing doc in `docs/` is hand-authored Markdown, not generated. Introducing a generator now would mean re-deriving all 293 existing lines of `api-contract.md` through a new toolchain mid-story — out of proportion to what AC5 actually needs. **The verification path**: a new backend feature test parses `docs/api-contract.md`'s endpoint table and asserts every route in `Route::getRoutes()` under `api/v1` has a matching `(method, path)` row, and that every documented row still resolves to a real route. This is the same shape as `RouteAuthorizationTest::test_every_api_route_is_classified` / `test_every_classified_route_exists` (`backend/tests/Feature/Authorization/RouteAuthorizationTest.php:19–34`) — the established precedent in this codebase for "a list in a file must stay in sync with the router." It runs inside `composer test`, which CI already runs (`ci.yml:60`) — **no new CI step is needed**, only the new test file.

## Decision — the missing routes, exactly, and why the count is 8 and not 9

Cross-checking `backend/routes/api.php:1–66` (29 named routes) against `docs/api-contract.md`'s summary table (**32–54**, 20 rows):

| Missing entirely (no table row) | Owning story |
|---|---|
| `GET /api/v1/priorities` (`priorities.index`) | TM-19 |
| `GET /api/v1/statuses` (`statuses.index`) | TM-19 |
| `GET /api/v1/tickets/stats` (`tickets.stats`) | TM-29 |
| `PATCH /api/v1/tickets/{ticket}` (`tickets.update`) | TM-27 |
| `DELETE /api/v1/tickets/{ticket}` (`tickets.destroy`) | TM-28 |
| `POST /api/v1/tickets/{ticket}/assign` (`tickets.assign`) | TM-31 |
| `POST /api/v1/tickets/{ticket}/claim` (`tickets.claim`) | TM-32 |
| `POST /api/v1/tickets/{ticket}/status` (`tickets.status`) | TM-38 |

**`POST /api/v1/tickets` and `GET /api/v1/tickets/{ticket}` already have table rows** (`docs/api-contract.md:44,46`) — the doc's own footnote at **228–230** calls out that these two lack a *detail section*, which is different from "missing." Task 2 upgrades both from a bare row to a full section; task 1 adds the 8 genuinely missing rows. `PriorityController`/`StatusController`'s owning story is TM-19, confirmed by `grep -rn "PriorityController" .squad/plans/` → `categories-priorities-statuses/16-story-cache-master-data-in-a-pinia-store-TM-19.md:200` (`Create file:`).

## Decision — `ticket_activities` is drawn into the ERD, closing the gap its own footnote names

`docs/erd.md:76` already documents `ticket_activities`'s columns in the table-notes row and states plainly that the entity is missing from the Mermaid diagram above it. Task 3 adds the entity block and its one relationship (`tickets ||--o{ ticket_activities : ticket_id`) to the diagram at `docs/erd.md:13–63`, and removes the "not yet drawn" clause from the table-notes row once it is.

## Decision — the ticket-lifecycle overview is a new file, cross-referencing the ERD and API contract rather than re-deriving their facts

`docs/ticket-lifecycle.md` is new. It states the 7 statuses and their legal/illegal transitions (from `StatusTransitionSeeder::EDGES`, already the single source of truth per the backend test suite plan), the timestamp rules (`TicketTimestamps`), and the escalation rules (priority bump, ceiling, reassignment) — **in prose, linking to `docs/erd.md` for the schema and `docs/api-contract.md` for the request/response shapes**, not repeating either. This matches the existing docs' own cross-referencing style (`docs/api-contract.md:228` already links back to owning stories rather than re-explaining them).

---

## Context — Read These Files First

1. `docs/api-contract.md` — **293 lines, all of it.** The summary table (**32–54**) is where task 1's 8 rows are inserted, in route-declaration order to match `routes/api.php`. Each existing `### METHOD /path` section (**56–293**) is the template for task 2's new sections — note the shape: one paragraph on auth/params, a bullet or paragraph on the write/side-effects, then explicit 422/403/404 cases with the exact field names they land under.
2. `docs/erd.md` — **81 lines, all of it.** The Mermaid block (**13–63**); `ticket_activities`'s columns already spelled out in the table-notes row (**76**) — task 3 copies that column list into the diagram rather than re-deriving it.
3. `backend/routes/api.php` — **67 lines, all of it.** The definitive route list; every name and method task 1/2 documents comes from here verbatim.
4. `backend/app/Http/Controllers/Api/V1/TicketController.php` — **347 lines.** `stats()` **40–45**; `update()` **284–301** (per-field activity write, the no-op early return at **290–292**); `destroy()` **303–313**; `assign()` **207–217** and `changeAssignee()` **219–232** (same-assignee 0-row branch **225–227**); `claim()` **249–259** and `claimTicket()` **261–272** (the two 409 shapes, **274–282**); `changeStatus()` **95–114**.
5. `backend/app/Http/Requests/Api/V1/UpdateTicketRequest.php` — **31 lines.** `EDITABLE` **11** (four fields); **fifteen `prohibited` keys, 25–28** — every one is a documented 422 case.
6. `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php` — **33 lines, all of it.** `assigned_to` present+nullable+`Rule::exists` scoped to active agents (**20**); messages (**25–31**).
7. `backend/app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php` — **27 lines, all of it.** The conditional `resolution`/`reason` trap (**21–24**).
8. `backend/app/Services/TicketWorkflow.php` — **43 lines, all of it.** `reject()` **39–42** — every illegal transition is **422 on `status_id`, never 403**; document this explicitly, it is the sharpest trap in the whole story.
9. `backend/app/Policies/TicketPolicy.php` — **70 lines.** `assign`/`delete` admin-only (**30–38**), `claim` agent-only (**40–43**), `update`/`changeStatus` open to all staff (**25–28**, **45–48**).
10. `backend/app/Http/Controllers/Api/V1/PriorityController.php` and `StatusController.php` — **13 lines each.** No policy call, no parameters — `auth:sanctum`+`active` only, same as `categories.index`.
11. `backend/app/Services/TicketStats.php` — **31 lines, all of it.** `for()`'s full return shape (**11–24**) — `scope`, `total`, `unassigned`, `escalated`, `mine_open`, `by_status[]`, `by_priority[]`.
12. `backend/app/Http/Resources/V1/TicketResource.php` — **35 lines, all of it.** The full field list task 2's `PATCH`/`DELETE`/`assign`/`claim`/`status` sections all point back to rather than re-listing — `can` (**29**) is present only on `tickets.show`, not on these mutation responses.
13. `backend/database/seeders/StatusTransitionSeeder.php:12–17` — the 14-edge `EDGES` constant, the source of truth for `docs/ticket-lifecycle.md`'s transition table.
14. `backend/app/Services/TicketTimestamps.php` — **28 lines, all of it** — the source for the lifecycle doc's timestamp rules.
15. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php:17–34` — the `ACCESS` map and its two completeness tests (`test_every_api_route_is_classified`, `test_every_classified_route_exists`) — the precedent task 5's new test copies the shape of.
16. `backend/phpunit.xml` — unedited by this story; confirms task 5's new test runs under `composer test` automatically (any file under `tests/Feature/`).

---

## Implementation tasks

### 1 — Add the 8 missing rows to the summary table

**File: `docs/api-contract.md`**, inside the table at **32–54**, in `routes/api.php` declaration order:

```markdown
| `GET` | `/api/v1/priorities` | Every priority, ordered by level. | bearer | TM-19 |
| `GET` | `/api/v1/statuses` | Every status, ordered by sort order. | bearer | TM-19 |
| `GET` | `/api/v1/tickets/stats` | Dashboard counts by status and priority; scoped to the caller unless admin. | bearer | TM-29 |
| `PATCH` | `/api/v1/tickets/{ticket}` | Edit subject, description, category, or priority. | bearer (TicketPolicy) | TM-27 |
| `DELETE` | `/api/v1/tickets/{ticket}` | Soft delete a ticket. | admin bearer (TicketPolicy) | TM-28 |
| `POST` | `/api/v1/tickets/{ticket}/assign` | Assign or unassign a ticket to an active agent. | admin bearer (TicketPolicy) | TM-31 |
| `POST` | `/api/v1/tickets/{ticket}/claim` | Self-claim an unassigned ticket. | bearer, agent only (TicketPolicy) | TM-32 |
| `POST` | `/api/v1/tickets/{ticket}/status` | Move a ticket through the workflow. | bearer (TicketPolicy) | TM-38 |
```

### 2 — Write detail sections for all 10 (8 new + 2 upgraded)

**File: `docs/api-contract.md`** — append new `### METHOD /path` sections after the existing ones, matching the prose style of `### POST /api/v1/tickets/{ticket}/escalate` (**88–114**). Content per section, drawn from the files in Context:

- **`### GET /api/v1/priorities`** and **`### GET /api/v1/statuses`** — one short section, or combine as `### GET /api/v1/priorities and /api/v1/statuses` since both are identical in shape: bearer required, any active staff, no parameters, returns `{"data": [...]}` unpaginated, ordered (`Priority::ordered()`/`Status::ordered()`). List each resource's fields (priority: `id,name,slug,level,color,is_default`; status: `id,name,slug,bucket,color,is_default,is_terminal,sort_order`).
- **`### GET /api/v1/tickets/stats`** — bearer, any active staff; no parameters. Document the full response shape from `TicketStats::for()` (**11–24**): `scope` (`"own"` or `"all"`), `total`, `unassigned`, `escalated` (count where `escalation_level > 0`), `mine_open` (the caller's own open count regardless of scope), `by_status[]` and `by_priority[]` each zero-filled and ordered.
- **`### POST /api/v1/tickets`** (upgrade the existing row to a section) — body shape from `StoreTicketRequest` (already summarized at `docs/api-contract.md:44`'s row); document `requester` object (matched-or-created by email), required `subject`/`description`/`category_id`, optional `priority_id`/`status_id` defaulting via `TicketController::defaultKey()` (**338–346**); 422 on missing/invalid `category_id`; 201 with the ticket detail shape (no `can`, since `TicketResource:29` gates `can` on `tickets.show` only).
- **`### GET /api/v1/tickets/{ticket}`** (upgrade) — bearer, any active staff (`TicketPolicy::view` always true); 404 for unknown or soft-deleted; 200 with the **full** `TicketResource` shape including `can` (list all seven keys from **line 29**) and `escalation_reason` (present here only).
- **`### PATCH /api/v1/tickets/{ticket}`** — bearer, open to all staff; body: `subject`/`description`/`category_id`/`priority_id`, all `sometimes`; document the **fifteen `prohibited` keys** (`UpdateTicketRequest.php:25–28`) each as its own 422 case; a request that changes nothing still returns 200 with the ticket unchanged (`TicketController.php:290–292`); 404 for unknown ticket.
- **`### DELETE /api/v1/tickets/{ticket}`** — **admin-only bearer**, 403 for an agent; soft-deletes, 204 on success; 404 for unknown ticket; the activity trail survives the delete (link to `tickets/{ticket}/activities`'s section for that guarantee rather than re-explaining it).
- **`### POST /api/v1/tickets/{ticket}/assign`** — **admin-only bearer**, 403 for an agent; body `{"assigned_to": <id>|null, "reason": "<optional, max 500>"}`; `assigned_to` is **required present**, not merely optional-when-absent — omitting the key is 422; must be an **active agent** id or `null`; 200 with the updated ticket; re-assigning to the current assignee is still 200 (0 activity rows written, not an error — link to the backend test suite's coverage rather than re-deriving it here).
- **`### POST /api/v1/tickets/{ticket}/claim`** — **agent-only bearer**, 403 for an admin (`TicketPolicy::claim`, **40–43**); no body; 200 with the ticket assigned to the caller on success; **two distinct 409 shapes** on conflict — a named one (`{"message": "<name> already claimed this ticket.", "assignee": {...}}`) when someone else already holds it, and a generic one (`{"message": "This ticket's assignment changed while you were claiming it. Reload and try again."}`) when it was unassigned mid-flight (`TicketController.php:274–282`).
- **`### POST /api/v1/tickets/{ticket}/status`** — bearer, open to all staff at the policy layer; body `{"status_id": <id>, "resolution"?: "<10–5000 chars, required only when target is Resolved, prohibited otherwise>", "reason"?: "<same shape, required only for Reopened>"}`. **State explicitly, in bold: every illegal transition returns 422 under `errors.status_id`, never 403** — `TicketWorkflow::reject()` throws a `ValidationException`, and `TicketPolicy::changeStatus` is a pure pass-through with no per-transition logic. Document the one admin-only edge (`resolved → closed`) as a 422 for an agent, not a 403, for the same reason. Link to the new `docs/ticket-lifecycle.md` for the full transition table rather than repeating all 14 edges here.

### 3 — Draw `ticket_activities` into the ERD

**File: `docs/erd.md`** — inside the Mermaid block (**13–63**), after the `ticket_sequences` entity (**55**), add:

```
    ticket_activities {
        bigint id PK
        bigint ticket_id FK
        bigint user_id FK "nullable -- null means the system acted"
        string event
        string field "nullable"
        string old_value "nullable"
        string new_value "nullable"
        json meta
        timestamp created_at "no updated_at -- append-only"
    }
```

and after the existing relationship lines (**56–62**), add:

```
    tickets ||--o{ ticket_activities : ticket_id
```

**File: `docs/erd.md:76`** — remove the sentence *"Not yet drawn in the diagram above — pre-existing gap from TM-45, not backfilled by this story."* from the `ticket_activities` table-notes row; the rest of that row (the append-only enforcement description) stays as written.

### 4 — Create `docs/ticket-lifecycle.md`

**Create file: `docs/ticket-lifecycle.md`**

Sections, in this order:
1. **Statuses** — the 7 seeded statuses (`new`, `open`, `in-progress`, `pending`, `resolved`, `closed`, `reopened`), each with its `bucket` and `is_terminal` value, from `StatusSeeder::STATUSES`.
2. **Legal transitions** — a table of all 14 edges from `StatusTransitionSeeder::EDGES` (`backend/database/seeders/StatusTransitionSeeder.php:12–17`), with the one admin-only edge (`resolved → closed`) called out. State plainly: **every transition not in this table is illegal and returns 422**, generated as the complement, not hand-listed (28 illegal pairs).
3. **Timestamps** — `first_responded_at` set once on the first move off `new`, never overwritten; `resolved_at`/`closed_at` set on their respective targets and **both cleared** on any non-terminal transition including `closed → reopened` (`TicketTimestamps.php:15–19`).
4. **Escalation** — one level per call, priority raised by exactly one step and capped at Urgent, reassignment to the least-loaded active admin unless already held by one, blocked on a terminal ticket (422) or with no active admin (422). Link to `docs/api-contract.md`'s escalate section for the exact request/response shape rather than repeating it.
5. **Activity logging** — one sentence per mutation path naming its `TicketActivityEvent` case, linking to `docs/api-contract.md`'s `/tickets/{ticket}/activities` section for the row shape.

Cross-link `docs/erd.md` (schema) and `docs/api-contract.md` (request/response) from the top of the file; do not restate either.

### 5 — CI-enforced completeness test

**Create file: `backend/tests/Feature/Documentation/ApiContractCoverageTest.php`**

```php
<?php

namespace Tests\Feature\Documentation;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiContractCoverageTest extends TestCase
{
    public function test_every_api_route_has_a_documented_row(): void
    {
        $documented = $this->documentedRoutes();
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }
            $this->assertContains(
                $route->methods()[0].' /'.$route->uri(),
                $documented,
                "No docs/api-contract.md row for {$route->methods()[0]} /{$route->uri()}",
            );
        }
    }

    public function test_every_documented_row_is_a_real_route(): void
    {
        $live = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1'))
            ->map(fn ($route) => $route->methods()[0].' /'.$route->uri())
            ->all();
        foreach ($this->documentedRoutes() as $row) {
            $this->assertContains($row, $live, "docs/api-contract.md documents {$row}, which no longer exists");
        }
    }

    /** @return list<string> "METHOD /path" pairs, e.g. "GET /api/v1/health" */
    private function documentedRoutes(): array
    {
        $rows = [];
        foreach (file(base_path('../docs/api-contract.md')) as $line) {
            if (preg_match('/^\|\s*`(\w+)`\s*\|\s*`([^`]+)`\s*\|/', $line, $matches)) {
                $rows[] = "{$matches[1]} {$matches[2]}";
            }
        }

        return $rows;
    }
}
```

`base_path('../docs/api-contract.md')` resolves to the repo-root `docs/` directory since `base_path()` is `backend/`. Laravel's `$route->uri()` never carries a leading slash; the comparison prepends one to match the doc's `` `/api/v1/...` `` cell format exactly.

### No changes to `backend/routes/api.php`, any controller, policy, or model.

This story documents existing behaviour; it does not change any.

---

## Edge Cases & Failure Modes

- **The doc's path cells use Laravel's route-parameter syntax verbatim** (`{ticket}`, `{category}`, `{user}`) — the parser in task 5 must not attempt to resolve these against a live model; it is a string comparison against `$route->uri()`, which also carries the literal `{ticket}` token.
- **A route with more than one HTTP method** (there are none currently, but `Route::match()` would produce one) — `$route->methods()[0]` picks the first; if a future route adds `HEAD` alongside `GET`, Laravel appends `HEAD` automatically and `methods()[0]` still returns `GET` first — confirmed by Laravel's route-compilation order, but worth a comment in the test if it ever surprises someone.
- **Trailing whitespace or a missing closing backtick in a new table row** silently drops that row from `documentedRoutes()`, and `test_every_api_route_has_a_documented_row` would then fail for the right reason (route not found) rather than the wrong one — no special-casing needed, the regex is strict on purpose.
- **`GET /api/v1/tickets/{ticket}` and `POST /api/v1/tickets` already had rows before this story** — task 5's test would have passed even before task 1/2, since row-presence is all it checks. **It does not verify that a detail section exists** — table-row completeness and prose completeness are different guarantees, and this story only automates the first. Say so in the PR so nobody assumes the test enforces AC1's full requirement by itself.
- **`resolved → closed` is documented as a 422, not a 403, for an agent.** Getting this backwards in the new `tickets.status` section would contradict `backend/tests/Feature/Tickets/TicketWorkflowTest.php` (once Story 51 lands it) and mislead a frontend implementer into writing a 403 handler that never fires.

---

## Test Plan

**Files created:** `docs/ticket-lifecycle.md`; `backend/tests/Feature/Documentation/ApiContractCoverageTest.php`.

**Files edited:** `docs/api-contract.md` (8 new table rows, 10 new/upgraded detail sections, the stale footnote at **228–230** removed once both its named gaps are closed); `docs/erd.md` (one new entity block, one new relationship line, the "not yet drawn" sentence removed from **76**).

**Order:**
1. Task 1 (table rows) first — cheapest, and task 5's first test can be run against it immediately for a fast feedback loop even before the prose sections exist.
2. Task 2 (detail sections) second — the bulk of the writing.
3. Task 3 (ERD) and task 4 (lifecycle doc) in either order — independent of each other and of tasks 1–2.
4. Task 5 (test) last, once every row from task 1 is in place — `test_every_api_route_has_a_documented_row` is meant to pass on the first run, not drive the writing.

---

## Verification Steps

1. **Confirm the gap, before writing.** `php artisan route:list --path=api/v1 --json | jq length` → 29. Count table rows in `docs/api-contract.md` (`grep -c '^| \`' docs/api-contract.md`) → 20, before task 1.
2. **Task 1:** re-run the same `grep -c` → 28.
3. **Task 5, proven both ways.** `php artisan test --filter=ApiContractCoverageTest` → **fails**, naming the 8 not-yet-documented routes, if run before task 1. After task 1: passes. Temporarily comment out one table row, re-run, confirm it fails naming that exact route — restore.
4. **Task 5's reverse direction.** Temporarily add a row for a route that does not exist (e.g. `` | `GET` | `/api/v1/nonexistent` | ... `` ), confirm `test_every_documented_row_is_a_real_route` fails naming it — remove.
5. **Full backend suite:** `composer test` from `backend/` → exits 0, the new test included.
6. **`./vendor/bin/pint --test`** → clean (the new test file is the only PHP file this story adds).
7. **Manual read-through:** every one of the 10 new/upgraded sections states its auth requirement, its request body, its response shape, and at least one documented error case — spot-check against `## Done Criteria` below.
8. **ERD renders:** open `docs/erd.md` in a Mermaid-capable viewer (or paste the block into the Mermaid Live Editor) and confirm `ticket_activities` appears connected to `tickets` with no syntax error.
9. **CI:** push the branch; confirm the backend job's `composer test` step includes and passes `ApiContractCoverageTest`.
10. **Regression:** `git status` shows changes only under `docs/` and the one new test file; no route, controller, policy, model, or request file changes.

---

## Done Criteria

- [ ] **AC1**: all 29 `api/v1` routes have a summary-table row; the 8 missing ones (`priorities.index`, `statuses.index`, `tickets.stats`, `tickets.update`, `tickets.destroy`, `tickets.assign`, `tickets.claim`, `tickets.status`) and the 2 previously row-only ones (`tickets.store`, `tickets.show`) all have a full detail section stating method, path, parameters, request body, and response shape.
- [ ] **AC2**: every new/upgraded section states its auth requirement and role restriction (admin-only for `destroy`/`assign`, agent-only for `claim`, open-staff for the rest), matching `TicketPolicy`.
- [ ] **AC3**: every new/upgraded section documents its 422 cases by field name and its 403 case where one exists; `tickets.status`'s illegal-transition case is documented as **422 under `status_id`, explicitly not 403**.
- [ ] **AC4**: `docs/erd.md`'s Mermaid diagram includes `ticket_activities` and its relationship to `tickets`, and the "not yet drawn" admission is removed; `docs/ticket-lifecycle.md` exists and covers statuses, transitions, timestamps, escalation, and activity logging, cross-linking rather than duplicating the ERD and API contract.
- [ ] **AC5**: `ApiContractCoverageTest` runs inside `composer test` (no new CI step needed) and asserts completeness in both directions — every route documented, every documented row real — proven to fail correctly in both directions during verification.
- [ ] No application source file changes — only `docs/*.md` and the one new test file.
- [ ] `pint --test` clean; `composer test` exits 0 with the new test included; the PR quotes the measured route/row counts (29 routes, 29 rows after this story).

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 54 (TM-63, production build and deployment runbook).**
