# Story 30 — Agent workload overview (Story: TM-35)

## Prerequisites

- **Story 25 (TM-29) is the pattern this story copies, and it is not implemented.** It creates `backend/app/Services/TicketStats.php` (the aggregate-in-a-service shape), `frontend/src/stores/stats.ts` (the store shape), and the zero-fill and `(int)`-cast rules that task 1 reuses. **Read [`../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md`](../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md) task 1 before writing a line.** It is not a hard blocker — nothing here imports its code — but writing this story first means inventing a second convention for the same job.
- **Story 20 (TM-24) is a hard blocker for AC3.** Every agent row links to `{ name: 'tickets', query: { assignee: '<id>', status: '<ids>' } }`, which needs its `assigned_to` filter, its `tickets` route and its `lib/ticketQuery.ts` URL vocabulary. **Without it the links go nowhere.** Its plan also records the trap: a link written with the **API** parameter name (`assigned_to`) silently produces an unfiltered list, because `fromQuery` ignores unknown keys.
- **Stories 26 (TM-31) and 29 (TM-34) are not blockers but they define this screen's purpose.** TM-31's rule — an assignee must be an **active agent** — is why AC4 exists at all: deactivating someone does not clear their tickets (measured during Story 26's planning), so somebody has to see the leftovers. TM-34 is the endpoint an admin uses to act on what this screen shows. **Neither is edited here.**
- **Story 28 (TM-33) is not a blocker.** This story does **not** use its `masterData.openStatusIds` computed — see task 1's `open_status_ids` note for why.
- **`frontend/src/api/users.ts:11` already declares `tickets_count?: number` on `AdminUser`, which no endpoint fills.** It was left there for this story. **Leave it alone anyway** — this story returns its own payload shape and does not touch `GET /admin/users`. Delete-or-fill is a separate cleanup; note it in the PR description.
- **No new composer or npm dependency, no migration, and no new index.** The EXPLAIN below shows why.
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An admin sees, on one screen, who is carrying what — and who is carrying work they can no longer be given.

1. **`GET /api/v1/admin/workload`** returns open ticket counts **per person, split by priority**, from **one** grouped aggregate query.
2. The screen **highlights** who is well above and well below the team average.
3. **Every row links** to the ticket list filtered to that assignee's open tickets.
4. **Inactive people holding open tickets are surfaced** so their work can be reassigned.

**Not in scope.** **No reassignment happens here** — the rows link out, and TM-34's endpoint does the moving. No bulk "reassign all of this person's tickets" action: no criterion asks for it, and it would need a new endpoint, a confirmation flow and its own activity semantics. No time-series, no "closed this week", no SLA, no first-response metrics, no caching — all four are out of scope for the same reasons Story 25 lists. No workload figures for an **agent**; this is an admin screen. No change to `GET /api/v1/tickets/stats`, and **no `/tickets/workload`** — see the route decision.

---

## Decision — `/api/v1/admin/workload`, inside the admin group

Story 25 put its aggregate at `GET /api/v1/tickets/stats` and had to add a route-ordering rule plus a dedicated test, because `/tickets/{ticket}` shadows any literal sibling. **This endpoint goes under the existing `admin` prefix instead**, and gets three concrete things for free:

- **No shadowing.** `/api/v1/admin/workload` sits beside `/api/v1/admin/users` with no `{param}` sibling, so there is no ordering trap and no ordering test.
- **No new policy method.** The `admin` middleware (`bootstrap/app.php:24`) returns `403` for agents before the controller runs, and `RouteAuthorizationTest`'s `test_agent_refused_by_admin_routes` and `test_admin_routes_have_three_middlewares` both pick it up automatically from one `ACCESS` entry.
- **It is honest about the resource.** The payload is a list of *people* with counts attached, not a ticket aggregate. `/admin/users` is already the staff directory; this is a view over it.

**Do not add it to `TicketController` and do not classify it `admin-policy`.** Policy gating buys nothing here: there is no per-record decision to make, and `UserPolicy::viewAny()` would just re-express what the middleware already enforces.

## Decision — the band is computed server-side, and the cohort is active agents only

AC2 says *"the screen highlights agents well above and well below the team average"*, which needs a definition of "well". Story 25's plan states the principle to follow: **"The API decides; the view reports."**

**The rule, decided here:**

- **`average_open`** = mean open-ticket total across the **active-agent cohort** — every user with `role = agent` and `is_active = true`, **including those with zero tickets**.
- **`band`** = `max(1, ceil(average_open * 0.25))`.
- **`load`** = `'high'` when `open_total > average_open + band`, `'low'` when `open_total < average_open - band`, otherwise `'normal'`.

Four things that rule settles, each measured below:

- **Zero-load agents stay in the cohort.** An idle agent is exactly who you want to rebalance onto, so they must pull the average down. Measured: a cohort of `[4,0,1,3,5,4,12]` gives a mean of `4.14`, a band of `2`, and labels the `12` **high** and the `0` and `1` **low** — which is the actionable reading.
- **The floor of `1` stops small teams flapping.** Without it, a mean of `2` gives a band of `0.5` and a single ticket flips a label.
- **`load` is `null` for anyone outside the cohort.** An inactive agent labelled `low` is noise — their real signal is AC4's, not AC2's. Measured: the deactivated fixture came out `low`, which is technically true and useless.
- **The comparison uses the unrounded mean; `average_open` ships rounded to one decimal for display.** Measured mean `4.1428…` → band boundary `6.1428…`; a client re-deriving from a displayed `4.1` would draw the line at `6.1` and disagree on a total of `6.2`. **The API labels; the view never recomputes.**

## Decision — AC4 covers inactive holders **and** non-agent holders

AC4 says *"Inactive agents holding open tickets are surfaced"*. The payload carries **`needs_reassignment: true`** for any person holding at least one open ticket who **cannot be newly assigned one** — that is, `is_active = false` **or** `role != 'agent'`.

The second clause is a deliberate superset of AC4, and it is not speculative: Story 28's plan already identified the **demoted agent** — someone promoted to admin who still holds tickets — and TM-31's rule means they can never be assigned another. Measured: the admin fixture holding one open ticket appeared in the result set exactly like the inactive agent did. Surfacing one and hiding the other would leave stranded work invisible for no reason. **State the superset in the PR description** so the reviewer sees it was a choice.

---

## Context — Read These Files First

1. [`../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md`](../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md) — **task 1** for `TicketStats`: the class docblock's `Ticket::query()`-not-`DB::table()` rule, the fresh-builder-per-aggregate rule, `->ordered()` on master models, the zero-fill rule and the `(int)` casts. **Task 6** for the store shape. **Task 8**'s `openStatusIds` computed and the `stat-mine-open` card link, which task 7's row links mirror.
2. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — `index()` at **19–38**. Read `$this->authorize('viewAny', User::class)` (**21**) and `->orderBy('name')->orderBy('id')` (**34**): **this story reuses that ordering** so the workload table and the staff list agree. Story 26 adds a `role` filter here; **this story does not touch the file.**
3. `backend/app/Http/Controllers/Api/V1/StatusController.php` and `PriorityController.php` — **single-action invokable controllers**, registered as `Route::get('/statuses', StatusController::class)`. Task 2 follows that shape exactly.
4. `backend/app/Models/Priority.php` — `scopeOrdered()` at **19–23** orders by `level`; `level` is cast to `integer` (**16**). Seeded 1–4 as Low / Medium / High / Urgent (`PrioritySeeder.php:12–15`).
5. `backend/app/Models/Status.php` — `scopeOrdered()` and the `is_terminal` cast. Seeded: **five non-terminal** (`new`, `open`, `in-progress`, `pending`, `reopened`) and **two terminal** (`resolved`, `closed`) — `StatusSeeder.php:13–19`.
6. `backend/app/Models/User.php` — `scopeActive()` at **46–50**, `isAdmin()` at **41–44**, `role` cast to `UserRole` (**36**). Measured: `where('role', UserRole::Agent)` works with the enum case directly.
7. `backend/app/Http/Resources/V1/UserResource.php` — **11 lines, and it exposes `email`.** That is why ticket resources inline `['id','name']` instead (Story 18's constraint). **This endpoint is admin-only, so `UserResource` is safe here** — an admin already reads the same fields from `/admin/users`. Task 1's note says so.
8. `backend/routes/api.php` — the `admin` group opens at **line 46** with `->middleware('admin')->prefix('admin')->name('admin.')`. Task 3 adds one line inside it.
9. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `ACCESS` (**17**) and the three tests that consume the `admin` level: `test_agent_refused_by_admin_routes` (**36–42**), `test_admin_not_refused_by_admin_routes` (**68–74**), `test_admin_routes_have_three_middlewares` (**99–107**). **All three cover this route from one `ACCESS` entry, with no fixture** — it is a parameterless `GET`.
10. `backend/database/migrations/2026_08_26_084625_create_tickets_table.php` — **line 38: `$table->index(['assigned_to', 'status_id'])`** and **line 34: `$table->index('status_id')`**. The EXPLAIN below says which one MySQL actually picks.

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** on a seeded fixture of 9 people and 32 live open assigned tickets, plus a terminal one, a soft-deleted one and an unassigned one. Do not re-derive them.

- **AC1's aggregate is genuinely one query.** `Ticket::query()->whereNotNull('assigned_to')->whereIn('status_id', <open subquery>)->groupBy('assigned_to', 'priority_id')->selectRaw('assigned_to, priority_id, count(*) as total')->get()` returned **23 rows in 1 query** for 8 assignees.

- **`COUNT(*)` in a `GROUP BY` arrives as a PHP `int`, and so do `assigned_to` and `priority_id`.** Measured `{"assigned_to":2,"priority_id":1,"total":3}` with `gettype` `integer` on all three. **This is the opposite of Story 25's `SUM()` finding** (measured `'38'`, a *string*). So no cast is needed on these three — but **do not delete Story 25's casts by analogy**; they guard a different function.

- **`pluck()` with a two-column `groupBy` silently collapses the result and loses the priority split.** Measured: `->groupBy('assigned_to','priority_id')->pluck(DB::raw('count(*)'), 'assigned_to')` returned **8 keys instead of 23 rows** — one per assignee, each holding whichever priority's count landed last. **No error, no warning.** Story 25 uses exactly this `pluck` idiom for its single-column groupings, so it is the reflex to resist here. **Use `->get()` and build the nested map in PHP** (task 1).

- **`DB::table('tickets')` counts soft-deleted rows on this query shape too.** Measured **33 via `DB::table()` against 32 via `Ticket::query()`** on identical filters. Re-confirms Story 25's trap; TM-28's requirement that deleted tickets stay out of statistics applies here verbatim.

- **EXPLAIN: no new index is needed, and MySQL does not pick the composite one.**

  | table | type | possible_keys | key | rows | Extra |
  |---|---|---|---|---|---|
  | `statuses` | `ALL` | `PRIMARY` | *(none)* | 7 | Using where; Using temporary |
  | `tickets` | `ref` | `tickets_status_id_index`, `tickets_assigned_to_status_id_index` | **`tickets_status_id_index`** | 1 | Using where |

  MySQL drives from the 7-row `statuses` scan and looks tickets up by `status_id`; `tickets_assigned_to_status_id_index` is a *possible* key it declines, because the query has no `assigned_to` predicate to anchor on. `Using temporary` is expected for a two-column `GROUP BY`. **This story adds no index** — there is nothing a new one would serve that the existing two do not, and the 7-row driving scan is the same cost Story 25 measured and accepted for `mine_open`. *(Measured on a small fixture: this is evidence about the plan shape, not about behaviour at 100k tickets.)*

- **The subquery, not a join.** Kept for the reason Story 20 established and Story 25 repeated: `tickets` and `statuses` share `id`, `created_at` and `updated_at`, and an ambiguous-column error is a 500. The subquery has no shared namespace.

- **The people set is 2 queries and correctly includes all four shapes.** A `distinct()->pluck('assigned_to')` of open-ticket holders, then `User::query()->where(fn => (role=agent AND is_active) OR whereIn('id', $holders))->orderBy('name')->orderBy('id')`. Measured, the 9 rows were: 6 loaded active agents, **1 zero-load active agent**, **1 inactive agent holding 2 open tickets**, and **1 admin holding 1 open ticket**. All four cases AC1 and AC4 need, from one query.

- **The banding rule behaves as intended on a realistic spread.** Cohort `[4,0,1,3,5,4,12]` → mean `4.1428571…`, band `2`. Labels: `12` → **high**; `0` and `1` → **low**; `3`, `4`, `5` → **normal**. The inactive holder came out `low` on the same maths, **which is why task 1 returns `null` for non-cohort rows instead**.

---

## Backend Tasks

### 1 — `AgentWorkload`

**Create file: `backend/app/Services/AgentWorkload.php`**

Follow `TicketStats` (Story 25's task 1) in shape: one public method, aggregates only, no model hydration of tickets.

```php
<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Priority;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;

class AgentWorkload
{
    /**
     * Four queries: priorities, open-status ids, one grouped aggregate, one
     * people lookup. No Ticket model is hydrated.
     *
     * Every ticket query starts from `Ticket::query()` and NOT
     * `DB::table('tickets')`. Measured on this exact query shape: the raw
     * builder loses the SoftDeletes scope and counted 33 where Eloquent counted
     * 32, which silently breaks TM-28's requirement that deleted tickets stay
     * out of statistics.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $priorities = Priority::query()->ordered()->get(['id', 'name', 'slug', 'color', 'level']);
        $openStatusIds = Status::query()->where('is_terminal', false)->pluck('id');

        // AC1's grouped aggregate — one query. `->get()`, never `->pluck()`:
        // measured, pluck() with a two-column groupBy collapses 23 rows into 8
        // keys and loses the priority split with no error at all.
        $grouped = Ticket::query()
            ->whereNotNull('assigned_to')
            ->whereIn('status_id', $openStatusIds)
            ->groupBy('assigned_to', 'priority_id')
            ->selectRaw('assigned_to, priority_id, count(*) as total')
            ->get();

        // [userId => [priorityId => count]]. count(*), assigned_to and
        // priority_id all arrive as PHP ints on a GROUP BY — measured — unlike
        // SUM(), which Story 25 measured as a string. No casts needed here.
        $counts = [];
        $totals = [];
        foreach ($grouped as $row) {
            $counts[$row->assigned_to][$row->priority_id] = $row->total;
            $totals[$row->assigned_to] = ($totals[$row->assigned_to] ?? 0) + $row->total;
        }

        $people = User::query()
            ->where(function ($query) use ($totals): void {
                // Everyone who can be given work, plus everyone already holding
                // some — which is how an inactive agent (AC4) and a promoted
                // ex-agent reach the list at all.
                $query->where(fn ($inner) => $inner->where('role', UserRole::Agent)->where('is_active', true))
                    ->orWhereIn('id', array_keys($totals));
            })
            ->orderBy('name')->orderBy('id')
            ->get();

        // AC2's cohort: active agents only, INCLUDING those with zero tickets —
        // an idle agent is exactly who you rebalance onto, so they must pull the
        // mean down. An inactive person cannot receive work, so including them
        // would make the average describe a team that does not exist.
        $cohort = $people->filter(fn (User $user) => $user->is_active && $user->role === UserRole::Agent);
        $average = $cohort->isEmpty() ? null : $cohort->sum(fn (User $user) => $totals[$user->getKey()] ?? 0) / $cohort->count();
        $band = $average === null ? null : max(1, (int) ceil($average * 0.25));

        return [
            // Rounded for display only. The `load` labels below are computed
            // from the UNROUNDED mean — measured 4.1428… with a band boundary of
            // 6.1428… — so a client must never recompute them from this number.
            'average_open' => $average === null ? null : round($average, 1),
            'band' => $band,
            'priorities' => $priorities->map(fn (Priority $priority): array => [
                'id' => $priority->id, 'name' => $priority->name, 'slug' => $priority->slug,
                'color' => $priority->color, 'level' => $priority->level,
            ])->all(),
            // AC3's row links need the open-status ids to build a filter that
            // matches the number the admin just clicked. Carried in the payload
            // for the same reason Story 25 carries `is_terminal` in `by_status`:
            // the link must be right on first paint without awaiting master data.
            'open_status_ids' => $openStatusIds->all(),
            'agents' => $people->map(function (User $user) use ($counts, $totals, $priorities, $average, $band): array {
                $total = $totals[$user->getKey()] ?? 0;
                $inAverage = $user->is_active && $user->role === UserRole::Agent;

                return [
                    // UserResource exposes `email`, which is why ticket resources
                    // inline ['id','name'] instead. Safe here: the route is
                    // admin-only and an admin reads the same fields from
                    // /admin/users.
                    'user' => UserResource::make($user)->resolve(),
                    'open_total' => $total,
                    'in_average' => $inAverage,
                    // null, not 'normal', for anyone outside the cohort: measured,
                    // the banding maths labels a deactivated holder "low", which
                    // is true and useless. Their signal is needs_reassignment.
                    'load' => ! $inAverage || $average === null ? null
                        : ($total > $average + $band ? 'high' : ($total < $average - $band ? 'low' : 'normal')),
                    // AC4, plus the promoted-ex-agent case: holds open work and
                    // can never be assigned more of it (TM-31's active-agent rule).
                    'needs_reassignment' => $total > 0 && ! $inAverage,
                    // Zero-filled and in priority order. A GROUP BY emits no row
                    // for a priority with no tickets, and on a per-agent matrix a
                    // missing key is indistinguishable from a genuine zero.
                    'by_priority' => $priorities->map(fn (Priority $priority): array => [
                        'priority_id' => $priority->id,
                        'count' => $counts[$user->getKey()][$priority->id] ?? 0,
                    ])->all(),
                ];
            })->all(),
        ];
    }
}
```

Add `use App\Http\Resources\V1\UserResource;` to the imports.

- **`->orderBy('name')->orderBy('id')`** matches `UserController::index()` (**line 34**) so the two admin screens list people in the same order.
- **The priority metadata is top-level, not repeated per agent.** With N agents × 4 priorities, inlining `name`/`color`/`level` in every cell would quadruple the payload for no gain; the SPA renders columns from `priorities` and cells from `by_priority` by matching `priority_id`.
- **`by_priority` is `[{priority_id, count}]`, not a bare ordered array.** Both are the same length and the same order, but the explicit key means a client that reorders columns cannot silently mis-attribute a count.
- **Do not cache.** Same reasoning as Story 25: no story asks for it, and a stale workload screen is worse than a four-query one.

### 2 — The controller

**Create file: `backend/app/Http/Controllers/Api/V1/Admin/WorkloadController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentWorkload;
use Illuminate\Http\JsonResponse;

class WorkloadController extends Controller
{
    public function __invoke(AgentWorkload $workload): JsonResponse
    {
        return response()->json(['data' => $workload->overview()]);
    }
}
```

- **Single-action invokable**, matching `StatusController` and `PriorityController`.
- **No `$this->authorize(...)`.** The `admin` middleware on the route group already returns `403` for an agent, and `test_admin_routes_have_three_middlewares` proves it stays there. A policy call would be a second expression of the same rule.
- **A plain `response()->json(['data' => …])`, not a Resource class.** The payload is an aggregate, not a model; Story 25 does the same for `stats`.

### 3 — Route and classification

**File: `backend/routes/api.php`**

Inside the `admin` group (opens **line 46**), after the four `users` routes:

```php
Route::get('/workload', WorkloadController::class)->name('workload');
```

Add `use App\Http\Controllers\Api\V1\Admin\WorkloadController;` to the imports. The group's `->name('admin.')` prefix makes the full name **`admin.workload`**.

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

**One line**, to `ACCESS` (**line 17**):

```php
'admin.workload' => 'admin',
```

That is the whole test change. `test_every_api_route_is_classified` requires it; `test_agent_refused_by_admin_routes`, `test_admin_not_refused_by_admin_routes` and `test_admin_routes_have_three_middlewares` then all cover the route with **no fixture**, because it is a parameterless `GET` and `routesFor()` needs no id for it. **Contrast Story 26 and 27, which each had to add a real ticket fixture** — that saving is the third argument for the admin-group placement.

### 4 — Document the endpoint

**File: `docs/api-contract.md`**

Add a row after the `admin/users` rows:

```markdown
| `GET` | `/api/v1/admin/workload` | Open ticket counts per person, split by priority, with load bands. | admin bearer (middleware) | TM-35 |
```

And a subsection:

```markdown
### `GET /api/v1/admin/workload`

Admin-only, enforced by the `admin` middleware rather than a policy — there is no
per-record decision to make. No parameters. One grouped aggregate query plus
three lookups; nothing is cached.

**"Open" means `statuses.is_terminal = false`**, the same definition as
`/tickets/stats`'s `mine_open`. Soft-deleted tickets are excluded.

| Field | Notes |
|---|---|
| `average_open` | Mean open tickets across the **active-agent cohort**, rounded to 1 dp **for display only**. `null` when there are no active agents. |
| `band` | `max(1, ceil(average_open * 0.25))`. `null` when `average_open` is. |
| `priorities` | Ordered by `level`. The column headers; per-agent counts reference these by `priority_id`. |
| `open_status_ids` | The non-terminal status ids, so a client can build a row link that matches the counts without a second request. |
| `agents[].user` | Full user object including `email` — safe because the route is admin-only. |
| `agents[].open_total` | Their open tickets across all priorities. |
| `agents[].in_average` | Whether they are in the cohort: active **and** `role = agent`. |
| `agents[].load` | `high` / `normal` / `low`, or **`null`** when `in_average` is false. Computed from the **unrounded** mean — **do not recompute it client-side from `average_open`.** |
| `agents[].needs_reassignment` | `true` when they hold open tickets but cannot be newly assigned any — inactive, or not an agent. |
| `agents[].by_priority` | Zero-filled, in `priorities` order. |

**Who appears:** every active agent (**including those with zero tickets** — they
are the ones you rebalance onto, and they pull the average down), plus anyone
holding at least one open ticket. That second clause is what surfaces an
**inactive agent** who still holds work, and also a **former agent now an admin**
— both hold tickets they can never be assigned again, because assignment requires
an active agent (TM-31).

Rows are ordered by `name` then `id`, matching `GET /api/v1/admin/users`.
```

**File: `docs/erd.md`** — **no change.** No column, no index, no table.

---

## Frontend Tasks

### 5 — API module

**Create file: `frontend/src/api/workload.ts`**

A separate module, not an addition to `api/tickets.ts` — the payload is not a ticket. Mirrors Story 25's `api/stats.ts`.

```ts
import client from './client'
import type { AdminUser } from './users'

export type LoadBand = 'high' | 'normal' | 'low'
export interface WorkloadPriority { id: number; name: string; slug: string; color: string; level: number }
export interface WorkloadCell { priority_id: number; count: number }
export interface WorkloadRow { user: AdminUser; open_total: number; in_average: boolean; load: LoadBand | null; needs_reassignment: boolean; by_priority: WorkloadCell[] }
export interface Workload { average_open: number | null; band: number | null; priorities: WorkloadPriority[]; open_status_ids: number[]; agents: WorkloadRow[] }

export async function getWorkload(): Promise<Workload> { const { data } = await client.get<{ data: Workload }>('/admin/workload'); return data.data }
```

- **`user` is typed as `AdminUser`** (`api/users.ts:4–12`) — it is the same `UserResource` shape, so reuse the type rather than declaring a second one.
- **`load: LoadBand | null`.** The `null` is the type-level reminder that non-cohort rows have no band.

### 6 — Store

**Create file: `frontend/src/stores/workload.ts`**

Copy `stores/stats.ts` (Story 25's task 6) exactly: one `data`, one `error`, one `loading`, one `load()`, `errorMessage` from `api/errors.ts`, plus a `clear()`.

```ts
export const useWorkloadStore = defineStore('workload', () => {
  const data = ref<Workload | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)
  async function load(): Promise<void> { loading.value = true; error.value = null; try { data.value = await getWorkload() } catch (caughtError) { data.value = null; error.value = errorMessage(caughtError) } finally { loading.value = false } }
  function clear(): void { data.value = null; error.value = null }
  return { data, error, loading, load, clear }
})
```

**File: `frontend/src/stores/auth.ts`** — add `useWorkloadStore().clear()` to `clear()` (**47–53**), beside the master-data line and Story 28's stats line. One admin's workload must not survive into another session.

### 7 — The screen

**Create file: `frontend/src/views/AdminWorkloadView.vue`**

Three states plus content, matching the four-state pattern Stories 19, 22 and 25 use.

| Element | `data-testid` | Condition |
|---|---|---|
| Loading | `workload-loading` | `store.loading` |
| Error | `workload-error` | `!store.loading && store.error` |
| Content | `workload` | `!store.loading && store.data` |
| Average | `workload-average` | `store.data.average_open ?? '—'`, with the band shown as "± {{ band }}" |
| Row | `workload-row` | `v-for` over `store.data.agents`, `:key="row.user.id"` |
| Row total | `workload-row-total` | `row.open_total` |
| Row band class | — | `:class="{ 'load-high': row.load === 'high', 'load-low': row.load === 'low' }"` |
| Reassign flag | `workload-needs-reassignment` | `v-if="row.needs_reassignment"` — text **"Cannot be assigned new tickets"** |
| Empty | `workload-empty` | `v-if="!store.data.agents.length"` — **"No agents yet."** |

**AC3's link — the whole row is the link target for each priority cell and for the total:**

```ts
const rowTo = (userId: number) => ({
  name: 'tickets',
  query: { assignee: String(userId), status: store.data!.open_status_ids.join(',') },
})
```

- **`assignee`, not `assigned_to`.** Story 20's plan records the trap: the API parameter name is not the URL key, and `fromQuery` silently ignores unknown keys, so `?assigned_to=7` produces an **unfiltered** list. Test 20 asserts the resolved `href`.
- **The link carries the open statuses**, so the list the admin lands on shows the same number they clicked. Same shape as Story 25's `stat-mine-open` card link.
- **Column headers come from `store.data.priorities` in array order**, and cells match on `priority_id`. Do no sorting in the view — the API ordered by `level`.
- **Highlighting is CSS driven by `row.load`, and the view never recomputes a band.** Reading `average_open` and comparing would use the rounded figure and disagree with the API on edge values (measured).
- **`needs_reassignment` rows render in the same table**, flagged, **not** in a separate section — an admin comparing loads needs to see stranded work in context, and a second table would duplicate the priority columns. AC4 says "surfaced", and a flagged row in the main table is surfaced.

### 8 — Route and navigation

**File: `frontend/src/router/index.ts`**

Import `AdminWorkloadView` and add the route beside the other admin entries:

```ts
{ path: '/admin/workload', name: 'admin-workload', component: AdminWorkloadView, meta: { role: 'admin' } },
```

`meta: { role: 'admin' }` matches `/admin/users` and `/admin/categories`; `authGuard` (`guards.ts:36`) already redirects a non-admin to `{ name: 'forbidden' }`.

**File: `frontend/src/App.vue`**

Add a nav link beside `nav-categories` (**line 24**), with the same `v-if="auth.isAdmin"` and the same classes:

```html
<RouterLink v-if="auth.isAdmin" :to="{ name: 'admin-workload' }" class="hover:text-indigo-600" data-testid="nav-workload">Workload</RouterLink>
```

### 9 — Formatting

```bash
cd frontend && npx prettier --write src/api/workload.ts src/stores/workload.ts src/stores/auth.ts src/views/AdminWorkloadView.vue src/router/index.ts src/App.vue src/api/workload.spec.ts src/stores/workload.spec.ts src/views/AdminWorkloadView.spec.ts
```

**Format only what you touch**, the policy Stories 19, 20 and 25 set. Do not run `npm run format` across the repo to make CI green without asking.

---

## Edge Cases & Failure Modes

- **No tickets at all** → every active agent appears with `open_total: 0`, `average_open: 0`, `band: 1`, and every `load` is **`normal`** (`0 > 1` is false, `0 < -1` is false). Correct: nobody is out of line when nobody has work. Test 8.
- **No active agents at all** → `average_open` and `band` are **`null`**, and every row's `load` is `null`. You cannot be above an average that does not exist. Test 9.
- **One active agent** → the cohort mean equals their total, band is at least 1, so their `load` is `normal`. A one-person team is never imbalanced. Test 10.
- **An active agent with zero tickets** → in the cohort, pulls the mean down, and typically comes out **`low`** — measured, the `0` in `[4,0,1,3,5,4,12]` did. That is the actionable signal, not a bug. Test 4.
- **An inactive agent holding open tickets** → present, `in_average: false`, `load: **null**`, `needs_reassignment: true`. **AC4.** Test 5.
- **An inactive agent holding *no* open tickets** → **absent** from the list entirely. They cannot receive work and have none, so a row would be noise. Test 6.
- **An admin holding open tickets** (the promoted ex-agent) → present, `in_average: false`, `needs_reassignment: true`. Measured as reachable. The deliberate superset of AC4. Test 7.
- **A soft-deleted ticket** → excluded, because every query starts at `Ticket::query()`. Measured 32 vs 33 against `DB::table()`. Test 11 — **the only guard against that substitution.**
- **A ticket in a terminal status** → excluded by the `is_terminal = false` subquery. Test 12.
- **An unassigned ticket** → excluded by `whereNotNull('assigned_to')`; it is Story 27's territory, not a workload figure. Test 13.
- **A priority with no tickets for a given agent** → `count: 0`, present. A `GROUP BY` emits no row for it and a missing key would be indistinguishable from a genuine zero. Test 3.
- **A priority nobody uses at all** → still a column, from the master table. Same reason.
- **An agent whose name collides with another's** → `orderBy('name')->orderBy('id')` keeps the order stable across requests, so the table does not shuffle between reloads.
- **An agent caller** → `403` from the `admin` middleware, before the controller. The SPA never shows the nav link or reaches the route (`meta.role`), so this is only reachable by curl. Covered by the existing `test_agent_refused_by_admin_routes`.
- **An unauthenticated caller** → `401`. Same group, same guarantee.
- **A failed request** → `workload-error` renders and no table; the nav link still works. No retry button — no criterion asks for one and the browser reload is the retry.
- **`average_open` displayed as `4.1` while a row's total is `6.2`-adjacent** → impossible to get wrong, because the view renders `row.load` and never compares numbers. The measured boundary is `6.1428…`, not `6.1`.
- **A very large team** → the payload is O(agents × priorities) with 4 priorities, so 100 agents is 400 cells. No pagination, deliberately: AC2 is about comparing against a **team** average, and a paginated comparison is meaningless. **If a team ever outgrows one screen, that is the story that adds sorting, not this one.**

---

## Test Plan

### Backend — `backend/tests/Feature/Admin/WorkloadTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Uses Story 19's `TicketFactory` (see Story 26's task 8 if it is still missing). Build one shared fixture: 6 loaded active agents, 1 idle active agent, 1 inactive agent with 2 open tickets, 1 admin with 1 open ticket, plus a terminal ticket, a soft-deleted ticket and an unassigned ticket. Model the class on `RouteAuthorizationTest`'s `tokenFor()` helper (**117–120**).

1. `test_unauthenticated_request_is_rejected` — no token → `401`.
2. `test_agent_is_forbidden` — agent token → `403` with `This action is unauthorized.`
3. `test_counts_are_split_by_priority_and_zero_filled` — **AC1.** For one known agent, assert each of the four `by_priority` entries, **including a `0`**, and that `priorities` has all four in `level` order.
4. `test_idle_active_agent_appears_and_is_in_the_average` — `open_total: 0`, `in_average: true`, `load: 'low'` on the shared fixture.
5. `test_inactive_agent_holding_open_tickets_is_surfaced` — **AC4.** Present, `in_average: false`, **`load` is `null`**, `needs_reassignment: true`.
6. `test_inactive_agent_with_no_open_tickets_is_absent` — `assertJsonMissing` on their id.
7. `test_admin_holding_open_tickets_is_surfaced` — the deliberate superset: present, `needs_reassignment: true`, `in_average: false`.
8. `test_empty_queue_gives_a_zero_average_and_all_normal` — no tickets: `average_open` is `0`, `band` is `1`, every `load` is `'normal'`.
9. `test_no_active_agents_gives_a_null_average` — only an inactive agent holding tickets: `average_open` and `band` are **`null`**, and their `load` is `null`.
10. `test_single_active_agent_is_never_high_or_low` — one agent, 20 tickets: `load: 'normal'`.
11. `test_soft_deleted_tickets_are_excluded` — **the `DB::table()` guard.** Soft-delete one of a known agent's tickets and assert `open_total` and the matching `by_priority` cell each drop by one. **Swap the service to `DB::table('tickets')` and this must fail** — say so in the docblock.
12. `test_terminal_status_tickets_are_excluded` — move one to `resolved`; the count drops.
13. `test_unassigned_tickets_are_excluded` — `open_total` across all rows sums to less than the live ticket count, and no row's id is null.
14. `test_load_bands_match_the_documented_rule` — assert the exact measured spread: totals `[12, 5, 4, 4, 3, 1, 0]` give `average_open: 4.1`, `band: 2`, one `high`, three `normal`, two `low`. **The test that pins AC2's definition**; change the rule and it fails loudly.
15. `test_rows_are_ordered_by_name_then_id` — two agents with the same name; assert the lower id comes first.
16. `test_the_aggregate_is_one_query` — `DB::enableQueryLog()` around the request; assert **exactly 4** queries reach the database from the service and that the count does **not** grow when a seventh agent is added. **AC1's "grouped aggregate query" made testable.**
17. `test_open_status_ids_matches_the_seeded_non_terminal_statuses` — five ids, and none of them `resolved` or `closed`.
18. `test_user_block_carries_email` — admin-only, so `data.agents.0.user.email` is present. Documents that the leak constraint on ticket resources does not apply here.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

19. One `ACCESS` line. `test_every_api_route_is_classified`, `test_agent_refused_by_admin_routes`, `test_admin_not_refused_by_admin_routes` and `test_admin_routes_have_three_middlewares` then all cover it **with no new test and no fixture**. Confirm by running the class before and after and checking the assertion count rose.

### Frontend — `frontend/src/api/workload.spec.ts` (new)

20. `getWorkload()` calls `GET /admin/workload` and unwraps `data.data`.

### Frontend — `frontend/src/stores/workload.spec.ts` (new)

21. `load()` populates `data`; a rejection sets `error`, nulls `data`, and clears `loading`.
22. `clear()` nulls both, and `useAuthStore().clear()` clears the workload store — asserted through the auth store so the wiring is covered.

### Frontend — `frontend/src/views/AdminWorkloadView.spec.ts` (new)

23. Loading shows `workload-loading` and no table; an error shows `workload-error` and no table.
24. Rows render one per agent with the right `workload-row-total`, and the priority columns come from `priorities` in order.
25. **AC2.** A `high` row carries `load-high`, a `low` row carries `load-low`, and a `load: null` row carries **neither** — the assertion that catches a view that recomputes the band itself.
26. **AC3.** A row's link resolves to `/tickets?assignee=<id>&status=<open ids>`. **Assert `assignee`, not `assigned_to`** — the Story 20 trap.
27. **AC4.** `workload-needs-reassignment` renders on a flagged row and is absent on a normal one.
28. `workload-average` renders `average_open` and the band, and renders **"—"** when `average_open` is `null`.
29. `workload-empty` renders when `agents` is empty, and no table appears.

### Frontend — `frontend/src/App.spec.ts` (Story 28's file; extend, or new)

30. `nav-workload` renders for an admin and is **absent** for an agent.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
2. **Backend formats:** from `backend/`, `./vendor/bin/pint --test`.
3. **Backend tests:** `composer test`, then `php artisan test --filter='WorkloadTest|RouteAuthorizationTest'`. Expect **+18 backend tests**.
4. **Prove test 11 earns its place:** swap `Ticket::query()` for `DB::table('tickets')` in `AgentWorkload::overview()`, re-run `--filter=test_soft_deleted_tickets_are_excluded`, confirm it **fails**, restore.
5. **Prove the `pluck` ban earns its place:** replace the `->get()` aggregate with `->pluck(DB::raw('count(*)'), 'assigned_to')` and re-run `--filter=test_counts_are_split_by_priority_and_zero_filled`. It must fail with the priority split collapsed. Restore.
6. **Prove test 14 pins the rule:** change `0.25` to `0.5` and confirm test 14 fails on the band. Restore.
7. **Confirm the EXPLAIN, once:**
   ```bash
   php artisan tinker --execute="print_r(DB::select('explain select assigned_to, priority_id, count(*) as total from tickets where assigned_to is not null and status_id in (select id from statuses where is_terminal = 0) and deleted_at is null group by assigned_to, priority_id'));"
   ```
   Expect `statuses` as an `ALL` scan of 7 rows and `tickets` as `ref` on **`tickets_status_id_index`**, with `Using temporary`. **Add no index** on the strength of it.
8. **Backend by hand.** `php artisan serve`, admin token:
   - `GET …/admin/workload` → `200`. Check `average_open`, `band`, four `priorities` in `level` order, five `open_status_ids`.
   - Every active agent appears, **including one with no tickets**, and their `load` is `low` or `normal`, never `null`.
   - Deactivate an agent who holds open tickets (`PATCH …/admin/users/{id} -d '{"is_active":false}'`), re-request → they are **still listed**, with `in_average: false`, `load: null`, `needs_reassignment: true`. *(AC4.)*
   - Reassign their tickets away with TM-34's endpoint, re-request → they are now **absent**.
   - Soft-delete one of a known agent's open tickets → their `open_total` drops by one. Resolve another → drops again.
   - Confirm `data.agents.0.user.email` is present.
   - With an **agent** token → `403`. With no token → `401`.
9. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test` — **+11 tests** across three new spec files plus one extended. Then `npx prettier --check` over task 9's list.
10. **Frontend by hand:** `npm run dev`, as an **admin**:
    - **Workload** is in the nav; open it → a row per person, a column per priority, and the average with its band at the top.
    - The busiest agent's row is visibly highlighted as high; an idle agent's as low; a mid-load agent is unstyled. *(AC2.)*
    - Click a row → the ticket list opens with **that agent selected in the assignee filter** and only open statuses, and the row count matches the number you clicked. *(AC3.)* Check the URL reads `?assignee=<id>&status=…`, **not** `?assigned_to=`.
    - Deactivate an agent holding tickets on `/admin/users`, return → their row shows **"Cannot be assigned new tickets"** and no high/low styling. *(AC4.)*
    - Click that row, reassign one of their tickets with the assign modal, come back → the numbers moved.
    - As an **agent**: no Workload nav link, and `/admin/workload` redirects to `/forbidden`.
    - Stop `php artisan serve`, reload → `workload-error`, no table, nav still usable.
11. **Regression:** `/tickets/stats` and the dashboard are unchanged; `/admin/users` still lists and filters; `RouteAuthorizationTest` is green with exactly one new `ACCESS` entry and no new test method.

---

## Done Criteria

- [ ] `GET /api/v1/admin/workload` returns `200` for an admin, `403` for an agent and `401` unauthenticated, enforced by the **`admin` middleware** and not by a policy.
- [ ] The per-priority counts come from **one** grouped aggregate query, and the whole endpoint is **4** queries that do not grow with the number of agents.
- [ ] `by_priority` is **zero-filled** and in `level` order for every agent; `priorities` metadata is top-level, not repeated per cell.
- [ ] Soft-deleted, terminal-status and unassigned tickets are all excluded — the first proven by a test that fails when `Ticket::query()` becomes `DB::table('tickets')`.
- [ ] **`pluck()` appears nowhere in the aggregate.** Replacing `->get()` with a two-column `pluck` must break test 3.
- [ ] `average_open` is the mean across **active agents including zero-load ones**, is `null` when there are none, and ships rounded to 1 dp **for display only**.
- [ ] `load` is computed server-side from the **unrounded** mean with `band = max(1, ceil(mean * 0.25))`, and is **`null`** for anyone outside the cohort. Test 14 pins the exact spread.
- [ ] The view **never recomputes a band** — a `load: null` row carries neither highlight class.
- [ ] Every active agent appears, including with zero tickets; every holder of open tickets appears, including inactive agents and non-agents; an inactive person with no open tickets is **absent**.
- [ ] `needs_reassignment` is `true` for inactive holders **and** non-agent holders, and the superset beyond AC4's wording is recorded in the PR description.
- [ ] Rows are ordered by `name` then `id`, matching `GET /api/v1/admin/users`.
- [ ] Each row links to `/tickets?assignee=<id>&status=<open ids>` — **`assignee`, not `assigned_to`** — and the list it opens shows the number that was clicked.
- [ ] The `user` block carries `email`, with the admin-only justification recorded; ticket resources are unchanged.
- [ ] `/admin/workload` is behind `meta: { role: 'admin' }`, the nav link is admin-only, and `auth.clear()` clears the workload store.
- [ ] **One line** added to `RouteAuthorizationTest`'s `ACCESS`, and **no** new authorization test or fixture — the admin-group placement's payoff.
- [ ] **No new index, no migration, no `docs/erd.md` change**, and the EXPLAIN that justifies it is recorded.
- [ ] `docs/api-contract.md` documents every field, the definition of "open", who appears and why, the cohort rule, and the **do-not-recompute-`load`** warning.
- [ ] No caching, no pagination, no bulk reassign, no sorting controls, no new dependency.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+18 backend and +11 frontend tests** over the measured baseline.

**This is the last story in the assignment-workload feature. STOP HERE, report to the user, and confirm the epic before moving to the next feature folder.**
