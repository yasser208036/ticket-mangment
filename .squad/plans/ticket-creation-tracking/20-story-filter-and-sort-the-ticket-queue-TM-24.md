# Story 20 — Filter and sort the ticket queue (Story: TM-24)

## Prerequisites

- **Story 19 (TM-23) — PLANNED, NOT IMPLEMENTED. This story is hard-blocked on it.** Verified on disk while planning: `TicketController` has **no `index()` method**, `backend/routes/api.php` has **no `tickets.index` route**, `RouteAuthorizationTest::ACCESS` has **no `tickets.index` key**, and `TicketFactory`, `RequesterFactory`, `frontend/src/views/TicketListView.vue`, `frontend/src/lib/relativeTime.ts` and `frontend/src/components/ColorBadge.vue` **do not exist**. **Every backend and frontend file this story edits is a file Story 19 creates.** Read [`19-story-paginated-ticket-list-TM-23.md`](19-story-paginated-ticket-list-TM-23.md) before this plan — it is the spec for the code you are about to modify. **Do not start this story until `php artisan test --filter=TicketIndexTest` passes.**
- **Story 17 (TM-21) completed — implemented.** The five indexes this story leans on are `tickets_status_id_index`, `tickets_category_id_index`, `tickets_priority_id_index`, `tickets_created_at_index` and the composite `tickets_assigned_to_status_id_index` (`backend/database/migrations/2026_08_26_084625_create_tickets_table.php:34–38`). Task 1 adds the two Story 17 did not anticipate.
- **Stories 13 (TM-16) and 16 (TM-19) completed — implemented.** Master data is seeded and `useMasterDataStore` already caches categories, priorities and statuses, so the filter dropdowns need **no new endpoint**.
- **No new composer or npm dependency.**
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

**One coordination point.** This story and **TM-25 (search)** both add query parameters to `TicketController::index()` and to `stores/tickets.ts`. TM-24 lands first and owns the `IndexTicketRequest` form request; **TM-25 adds `q` to that class rather than creating a second one.** Task 3's sort whitelist is also the thing TM-25 extends when it adds relevance ordering.

---

## Story Goal

An agent narrows the queue to the tickets they must act on, and can bookmark the result.

1. `GET /api/v1/tickets` accepts `status_id`, `priority_id`, `category_id`, `assigned_to` and `escalated`, and **all of them compose on one request**.
2. `sort` accepts exactly `created_at`, `updated_at` and `priority` (priority *level*, not id), with `direction` `asc` or `desc`.
3. Any other `sort` value is a **`422`**. The column name never comes from user input, so the parameter cannot carry SQL.
4. The SPA reflects every active filter in the URL query string, so a filtered view is bookmarkable and shareable, and restores from a pasted URL.
5. A **Clear all** control resets every filter, the sort and the page in one click.

**Not in scope.** Full-text search (`q`) is **TM-25** — do not add it. Saved or default filter sets, per-agent "my queue" presets, and filtering by `bucket`, requester, or date range are not in any current story; do not invent them. This story adds **no** new endpoint: see the assignee-dropdown constraint in task 11.

---

## Context — Read These Files First

1. [`19-story-paginated-ticket-list-TM-23.md`](19-story-paginated-ticket-list-TM-23.md) — **read tasks 2, 8, 9 and 12 in full.** Task 2 defines the `index()` you extend (its `per_page` rule, its six eager loads, its `orderByDesc('created_at')->orderByDesc('id')` default and its `withQueryString()`). Task 9 defines the `stores/tickets.ts` shape — `items`, `meta`, `page`, `perPage`, `loading`, `error`, the `latestRequest` race guard, `load()`, `goToPage()`, `setPerPage()`. Task 12 defines `TicketListView.vue` and its `data-testid` contract. **This story's edits are described as diffs against those tasks.**
2. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php` — **the form-request precedent, 48 lines.** `authorize()` via `Gate::allows` (**12–15**), `prepareForValidation()` using `$this->merge()` (**17–22**), `rules()` returning arrays with `Rule::exists(...)` (**25–41**), and `messages()` overriding specific keys (**43–47**). Task 2 follows this shape exactly.
3. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — `index()` at **19–38** is the *inline* `$request->validate()` idiom. Task 2 **departs from it** and says why. Note the `->when(filled(...), …)` conditional-filter style at **28–33** — task 3 reuses that idiom.
4. `backend/app/Models/Priority.php` — `level` is cast to integer (**line 16**) and `scopeOrdered()` orders by it (**20–23**). `level` is a `unsignedTinyInteger` with a **unique** index (`2026_08_26_073218_create_priorities_table.php:18`), which is why the join in the measurements is strictly 1:1.
5. `backend/database/seeders/PrioritySeeder.php` — **lines 11–16.** Levels are `low=1, medium=2, high=3, urgent=4`. They are seeded in id order, so in a fresh database `level` *happens* to correlate with `id` — **an accident of seeding, not a guarantee.** `updateOrCreate` keys on `slug` (**line 25**), so a priority added later gets a high id and an arbitrary level. **Never sort by `priority_id` as a proxy for level.**
6. `backend/database/seeders/StatusSeeder.php` — **lines 12–20.** Seven statuses across three buckets. `is_terminal` is true only for `resolved` and `closed`. Useful when writing fixtures; **`bucket` is not a filter in this story.**
7. `backend/app/Policies/UserPolicy.php` — **`viewAny()` at 9–12 returns `$user->isAdmin()`.** This is the single most consequential file for the frontend: **an agent cannot enumerate staff**, so the assignee dropdown cannot be a plain user list. Task 11 works within that.
8. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `routesFor()` (**121–124**) resolves route URIs with no query string, so `tickets.index` stays classified `staff` and **needs no change in this story**. Story 19 adds that key; confirm it is there before you run the suite.
9. `frontend/src/views/LoginView.vue` — **the only query-string precedent in the project, and it only reads.** `useRoute()` (**line 9**), `route.query.redirect` passed through `safeRedirect` (**line 21**). Nothing in the SPA *writes* the query string yet; task 12 is the first.
10. `frontend/src/router/guards.spec.ts` — **the router-test precedent.** `createAppRouter(createMemoryHistory())` (**line 34**) and `router.resolve('/admin/users')` (**37**). Task 17's URL-sync tests use exactly this.
11. `frontend/src/stores/masterData.ts` — `categories`, `priorities`, `statuses` plus the `activeCategories` computed (**line 15**) and `statusById` / `priorityById` lookups (**13–14**). The status and priority filter dropdowns read this store; **do not fetch master data in the view.**
12. `frontend/src/stores/users.ts` — `load()` (**20–41**) and its `Paginated` handling. Task 11 calls this **only for admins**, and only with `per_page: 100`.
13. `frontend/src/api/users.ts` — `UserListQuery` (**13–18**) already carries `per_page`, so no change is needed there.

---

## Measured facts that decide these tasks

Measured during planning against **`mysql:8.4` (`tm-mysql-test`, 3307)** on 60 tickets spread across 4 priorities, 7 statuses, several categories, mixed assignees and mixed escalation, through Laravel's query log and `EXPLAIN`. Do not re-derive them.

- **Sort by priority level: use a correlated subquery, not a join.** Both work and both cost **8 queries** with the six eager loads, and both return `total=60` correctly — the join does **not** duplicate rows, because `tickets.priority_id` is `NOT NULL` against a unique `priorities.id`, making it strictly 1:1. The subquery wins on two measured points. **(a)** The join drags itself into the paginator's count query — measured `select count(*) … inner join priorities …` — while the subquery's count stays `select count(*) from tickets where deleted_at is null`. **(b)** Far more important: with the join in place, **an unqualified column in a `where` clause dies with `SQLSTATE[23000] … 1052 Column 'created_at' in where clause is ambiguous`** (measured). `tickets` and `priorities` share `id`, `created_at` and `updated_at`; TM-25's search clauses and TM-29's date windows would all hit this, and the failure is a 500, not a 422. The subquery has no shared namespace and needs no `select('tickets.*')` guard. *(An unqualified `id` in `orderBy` alongside the join happens **not** to error, because `ORDER BY` resolves against the select list first — do not take that as a reason to trust the join.)*

- **`sort=updated_at` is a full table scan plus filesort today, and one index fixes it.** Measured with no index: `type=ALL, key=NULL, rows=60, Extra=Using where; Using filesort`. With `index(updated_at)` added: `type=index, key=…, Extra=Using where; Backward index scan` — **the filesort disappears**, matching `created_at`'s profile exactly (`created_at` already has `tickets_created_at_index` and shows `Backward index scan`). AC2 makes `updated_at` a first-class sort, so **task 1 adds the index.**

- **An `escalation_level` index *is* used, contrary to the low-cardinality assumption.** Measured with the index present: `escalation_level > 0` → `type=range, rows=20` of 60; `escalation_level = 0` → `type=ref, rows=40` of 60. The optimizer picked it for both. Recorded honestly: **60 rows is not a representative sample**, and the `= 0` case selecting 40/60 rows is the kind of ratio a real optimizer may reject at scale. The `> 0` case is the operationally important one — escalated tickets are a small minority in production — and it is the selective one. **Task 1 adds the index; do not add one for `status_id`, `priority_id` or `category_id`**, which already have theirs.

- **Multi-value filters use the existing single-column indexes.** Measured `status_id IN (1,2,3)` → `type=range, key=tickets_status_id_index, rows=25`. No composite index is needed for filter combinations.

- **The assignee filters need no new index, and confirm Story 17's composite choice.** Measured: `assigned_to = ? AND status_id IN (…)` → `type=range, key=tickets_assigned_to_status_id_index`; and **`assigned_to IS NULL` also uses it** → `type=ref, rows=20`. The leftmost prefix serves `IS NULL` as well as equality.

- **The query count stays 8 with every filter and the priority sort applied at once** — measured on a non-empty filtered result (`total=4`): status + priority + category + assignee + escalated + subquery sort = **8 queries**.

- **An empty result set issues exactly 1 query.** Measured: when `count(*)` returns 0, `paginate()` **skips the page query and every eager load**. So Story 19's constant-8 guarantee is conditional on a non-empty page, and **any query-count test in this story must assert against a filter combination that matches rows.** A filtered test that accidentally matches nothing will "pass" at 1 query and prove nothing.

- **An agent cannot populate a staff dropdown.** `UserPolicy::viewAny()` returns `$user->isAdmin()` and `admin.users.index` sits behind the `admin` middleware group, so `GET /api/v1/admin/users` is a **403** for every agent. This is a product constraint, not a bug, and task 11 designs around it rather than adding an endpoint.

---

## Baselines — read this before you start

**These are Story 19's projected end state, not measured reality.** Story 19 is not implemented, so its numbers are a target, not a baseline. **Re-measure before you begin** and use what you actually observe:

```bash
cd backend  && php artisan test 2>&1 | tail -5
cd frontend && npm test 2>&1 | tail -5
```

- Story 19 is expected to leave the backend at **119 tests / 116 passing** and the frontend at **42 tests across 10 files**.
- **Two failures are expected to still be red and are out of scope for this story**: `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (TM-14, `422` vs `429`) and `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` (TM-21, inert under `RefreshDatabase`).
- **If `RouteAuthorizationTest` is red, Story 19 is not finished — stop and finish it first.** Story 19 owns adding `tickets.index` and `tickets.store` to `ACCESS`; this story assumes both are there.
- `npm run format:check` will still fail on files Story 19 did not touch (Stories 16/18/19 debt). Same policy as Story 19: **format only what you touch**, listed in task 15.
- **Expected end state: 119 → 141 backend tests (138 passing) and 42 → 62 frontend tests across 12 files.**

---

## Backend Tasks

### 1 — Index the two columns this story sorts and filters on

**Create file:** `backend/database/migrations/<timestamp>_add_filter_indexes_to_tickets_table.php`

Generate it with `php artisan make:migration add_filter_indexes_to_tickets_table` so the timestamp sorts after `2026_08_26_084626_create_ticket_activities_table.php`.

```php
public function up(): void
{
    Schema::table('tickets', function (Blueprint $table) {
        // `sort=updated_at` is a full scan + filesort without this; with it,
        // MySQL does a Backward index scan and drops the filesort entirely.
        $table->index('updated_at');
        // Measured as used for `escalation_level > 0` (type=range), which is
        // the selective direction — escalated tickets are a small minority.
        $table->index('escalation_level');
    });
}

public function down(): void
{
    Schema::table('tickets', function (Blueprint $table) {
        $table->dropIndex(['updated_at']);
        $table->dropIndex(['escalation_level']);
    });
}
```

**Do not touch `create_tickets_table`.** Story 17's migration has shipped; editing it would leave every existing database without these indexes. **Do not add indexes for `status_id`, `priority_id` or `category_id`** — Story 17 already declared them (**lines 34–36**), and a duplicate index is dead weight MySQL still maintains on every write.

### 2 — `IndexTicketRequest`

**Create file:** `backend/app/Http/Requests/Api/V1/IndexTicketRequest.php`

**This departs from the inline `$request->validate()` in `UserController::index()`, on purpose.** Eight parameters, three of which need scalar→array normalisation, plus the sort whitelist that AC3 turns into a security requirement — that does not belong inline in a controller. Follow `StoreTicketRequest`'s shape (`authorize`, `prepareForValidation`, `rules`, `messages`).

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IndexTicketRequest extends FormRequest
{
    /**
     * The ONLY sortable columns. Keys are what a caller may send; values are
     * the column this maps to. AC3 is satisfied structurally: the value is
     * never derived from input, so no caller string reaches SQL. `priority`
     * is resolved through `priorities.level` by the controller, because a
     * ticket's `priority_id` says nothing about its level.
     */
    public const SORTS = ['created_at' => 'created_at', 'updated_at' => 'updated_at', 'priority' => 'level'];

    public function authorize(): bool
    {
        return Gate::allows('viewAny', Ticket::class);
    }

    /**
     * `?status_id=3` and `?status_id[]=3&status_id[]=4` must both work: a
     * single-select filter should not need array syntax in a shareable URL.
     */
    protected function prepareForValidation(): void
    {
        foreach (['status_id', 'priority_id', 'category_id'] as $key) {
            if ($this->has($key) && ! is_array($this->input($key))) {
                $this->merge([$key => Arr::wrap($this->input($key))]);
            }
        }
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'status_id' => ['sometimes', 'array', 'max:20'],
            'status_id.*' => ['integer', Rule::exists('statuses', 'id')],
            'priority_id' => ['sometimes', 'array', 'max:20'],
            'priority_id.*' => ['integer', Rule::exists('priorities', 'id')],
            'category_id' => ['sometimes', 'array', 'max:50'],
            'category_id.*' => ['integer', Rule::exists('categories', 'id')->whereNull('deleted_at')],
            // `me` and `unassigned` are the only two an agent can actually use;
            // a numeric id is accepted for admins, who can enumerate staff.
            'assigned_to' => ['sometimes', 'string'],
            'escalated' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(array_keys(self::SORTS))],
            'direction' => ['sometimes', Rule::in(['asc', 'desc'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'sort.in' => 'Tickets can only be sorted by created_at, updated_at or priority.',
            'direction.in' => 'Sort direction must be asc or desc.',
        ];
    }

    /** Resolved assignee filter: `null` (no filter), `'unassigned'`, or a user id. */
    public function assigneeFilter(): string|int|null
    {
        $value = $this->input('assigned_to');
        if ($value === null || $value === '') {
            return null;
        }
        if ($value === 'unassigned') {
            return 'unassigned';
        }

        return $value === 'me' ? (int) $this->user()->getKey() : (int) $value;
    }
}
```

Four things that are load-bearing:

- **`per_page` moves here from the inline rule Story 19 put in `index()`.** Keep the rule identical — `min:1`, `max:100`, default 15 applied in the controller. Story 19's `per_page` tests must keep passing unchanged.
- **`assigned_to` is validated as `string`, not `integer`**, because `me` and `unassigned` are legal values. The numeric branch is coerced in `assigneeFilter()`. A non-existent numeric id therefore returns an **empty list, not a 422** — see Edge Cases; that is the deliberate trade for keeping the sentinel values.
- **`max:20` / `max:50` on the arrays** caps `whereIn` size. Without it, `?status_id[]=…` repeated 10,000 times builds a 10,000-placeholder query.
- **`category_id` uses `whereNull('deleted_at')` but *not* `where('is_active', true)`**, unlike `StoreTicketRequest.php:35`. You must be able to filter *by* a deactivated category to find the tickets still sitting in it — that is the whole point of TM-17's fifth criterion.

### 3 — Extend `TicketController::index()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`** — the `index()` Story 19 creates.

Swap the signature to `IndexTicketRequest` and drop the inline `$request->validate([...])` and the `$this->authorize(...)` call (the form request's `authorize()` now covers it, exactly as `store()` relies on `StoreTicketRequest`).

```php
public function index(IndexTicketRequest $request): AnonymousResourceCollection
{
    $sort = $request->string('sort', 'created_at')->value();
    $direction = $request->string('direction', 'desc')->value();
    $assignee = $request->assigneeFilter();

    $tickets = Ticket::query()
        ->with(['requester', 'category', 'priority', 'status', 'assignee', 'creator'])
        ->when($request->has('status_id'), fn ($query) => $query->whereIn('status_id', $request->input('status_id')))
        ->when($request->has('priority_id'), fn ($query) => $query->whereIn('priority_id', $request->input('priority_id')))
        ->when($request->has('category_id'), fn ($query) => $query->whereIn('category_id', $request->input('category_id')))
        ->when($assignee === 'unassigned', fn ($query) => $query->whereNull('assigned_to'))
        ->when(is_int($assignee), fn ($query) => $query->where('assigned_to', $assignee))
        ->when($request->has('escalated'), fn ($query) => $request->boolean('escalated')
            ? $query->where('escalation_level', '>', 0)
            : $query->where('escalation_level', 0))
        ->tap(fn ($query) => $this->applySort($query, $sort, $direction))
        ->orderByDesc('id')
        ->paginate($request->integer('per_page', 15))->withQueryString();

    return TicketResource::collection($tickets);
}

/**
 * `priority` orders by `priorities.level` through a correlated subquery
 * rather than a join. A join works and costs the same 8 queries, but it
 * pulls itself into the paginator's count query and — measured — makes any
 * unqualified column in a later `where` fail with MySQL error 1052
 * ("Column 'created_at' in where clause is ambiguous"), because `tickets`
 * and `priorities` share `id`, `created_at` and `updated_at`. TM-25 adds
 * search clauses to this same builder; keep the namespaces separate.
 *
 * @param  Builder<Ticket>  $query
 */
private function applySort(Builder $query, string $sort, string $direction): void
{
    if ($sort === 'priority') {
        $query->orderBy(Priority::query()->select('level')->whereColumn('priorities.id', 'tickets.priority_id'), $direction);

        return;
    }

    $query->orderBy(IndexTicketRequest::SORTS[$sort], $direction);
}
```

- **`IndexTicketRequest::SORTS[$sort]` is safe because `$sort` already passed `Rule::in(array_keys(self::SORTS))`.** Never interpolate `$request->input('sort')` into `orderBy` — that is precisely what AC3 forbids.
- **Keep `->orderByDesc('id')` as the final tiebreak on every sort path.** Story 19 measured `created_at` at second precision with total ties; `updated_at` has the same precision, and `priorities.level` has only **four** distinct values across the whole table, so ties there are the norm rather than the exception. Without the tiebreak, paging a priority-sorted queue is undefined.
- **Do not add `->when(filled(...))` around the arrays** — use `has()`. An explicitly empty `?status_id[]=` should match nothing rather than silently disable the filter; `filled()` would turn a deliberate empty selection into "show everything".
- Add `use App\Http\Requests\Api\V1\IndexTicketRequest;`, `use App\Models\Priority;` (already imported at **line 9** for `store()`) and `use Illuminate\Database\Eloquent\Builder;` (already imported at **line 15**). Drop `use Illuminate\Http\Request;` if Story 19's `index()` was the only user.

### 4 — Document the parameters

**File: `docs/api-contract.md`**

Replace Story 19's `GET /api/v1/tickets` row's purpose text and add a subsection after the endpoint table:

```markdown
### `GET /api/v1/tickets`

Paginated ticket queue. Every parameter is optional and all of them compose on
one request. Omits `description`; see TM-23.

| Parameter | Type | Notes |
|---|---|---|
| `status_id` | int or int[] | `?status_id=3` or `?status_id[]=3&status_id[]=4`. Max 20. Must exist. |
| `priority_id` | int or int[] | Max 20. Must exist. |
| `category_id` | int or int[] | Max 50. Soft-deleted categories are rejected; **deactivated ones are allowed**. |
| `assigned_to` | `me`, `unassigned`, or int | A numeric id nobody holds returns an empty page, not a `422`. |
| `escalated` | bool | `true` → `escalation_level > 0`; `false` → `= 0`. |
| `sort` | enum | `created_at` (default), `updated_at`, `priority`. Anything else is `422`. |
| `direction` | enum | `asc`, `desc` (default). |
| `per_page` | int | 1–100, default 15. |

`priority` sorts by the priority's **level**, not its id. `id` descending is the
final tiebreak on every sort. Agents cannot enumerate staff
(`UserPolicy::viewAny` is admin-only), so `me` and `unassigned` are the assignee
filters available to them.
```

**Leave `docs/erd.md` alone** — task 1 adds indexes, not columns.

---

## Frontend Tasks

### 5 — Filter and sort types

**File: `frontend/src/api/tickets.ts`** — extend the `TicketListQuery` Story 19 creates.

```ts
export type TicketSort = 'created_at' | 'updated_at' | 'priority'
export type TicketDirection = 'asc' | 'desc'
export type AssigneeFilter = 'me' | 'unassigned' | number

export interface TicketListQuery {
  page?: number
  per_page?: number
  status_id?: number[]
  priority_id?: number[]
  category_id?: number[]
  assigned_to?: AssigneeFilter
  escalated?: boolean
  sort?: TicketSort
  direction?: TicketDirection
}
```

`listTickets()` needs **no change** — axios serialises `status_id: [3, 4]` to `status_id[]=3&status_id[]=4`, which is exactly what `prepareForValidation()` accepts. **Do not add a `paramsSerializer`.**

### 6 — Filter state in the store

**File: `frontend/src/stores/tickets.ts`** — extend Story 19's task 9 store. Keep `creating`/`create` and the `latestRequest` guard untouched.

```ts
const statusIds = ref<number[]>([])
const priorityIds = ref<number[]>([])
const categoryIds = ref<number[]>([])
const assignedTo = ref<AssigneeFilter | ''>('')
const escalated = ref<boolean | null>(null)
const sort = ref<TicketSort>('created_at')
const direction = ref<TicketDirection>('desc')

const activeFilterCount = computed(
  () =>
    (statusIds.value.length ? 1 : 0) +
    (priorityIds.value.length ? 1 : 0) +
    (categoryIds.value.length ? 1 : 0) +
    (assignedTo.value === '' ? 0 : 1) +
    (escalated.value === null ? 0 : 1),
)
```

`load()` builds its query from that state — **omit every inactive filter rather than sending an empty value**:

```ts
const response = await listTickets({
  page: page.value,
  per_page: perPage.value,
  sort: sort.value,
  direction: direction.value,
  ...(statusIds.value.length ? { status_id: statusIds.value } : {}),
  ...(priorityIds.value.length ? { priority_id: priorityIds.value } : {}),
  ...(categoryIds.value.length ? { category_id: categoryIds.value } : {}),
  ...(assignedTo.value === '' ? {} : { assigned_to: assignedTo.value }),
  ...(escalated.value === null ? {} : { escalated: escalated.value }),
})
```

Add three actions:

```ts
/** Any filter change resets to page 1 — page 7 of an unfiltered queue rarely exists in a filtered one. */
async function applyFilters(): Promise<void> {
  page.value = 1
  await load()
}

async function setSort(next: TicketSort, nextDirection: TicketDirection): Promise<void> {
  sort.value = next
  direction.value = nextDirection
  page.value = 1
  await load()
}

/** AC5. Resets filters, sort AND page — everything the URL carries. */
async function clearAll(): Promise<void> {
  statusIds.value = []
  priorityIds.value = []
  categoryIds.value = []
  assignedTo.value = ''
  escalated.value = null
  sort.value = 'created_at'
  direction.value = 'desc'
  page.value = 1
  await load()
}
```

- **`escalated` is `boolean | null`, not `boolean`.** Three states: unset, escalated-only, not-escalated-only. A plain boolean cannot express "don't filter".
- **`assignedTo` uses `''` for unset, not `null`** — `null` is too easily confused with the `'unassigned'` filter, which is a *filter*, not the absence of one.
- **Export everything the URL needs to read and write**, including `activeFilterCount`, `sort`, `direction`, `applyFilters`, `setSort` and `clearAll`.

### 7 — URL serialisation helper

**Create file:** `frontend/src/lib/ticketQuery.ts`

Pure functions, no store or router import — that is what makes them cheap to test.

```ts
import type {
  AssigneeFilter,
  TicketDirection,
  TicketSort,
} from '../api/tickets'
import type { LocationQuery, LocationQueryRaw } from 'vue-router'

export interface TicketQueryState {
  statusIds: number[]
  priorityIds: number[]
  categoryIds: number[]
  assignedTo: AssigneeFilter | ''
  escalated: boolean | null
  sort: TicketSort
  direction: TicketDirection
  page: number
}

const SORTS: TicketSort[] = ['created_at', 'updated_at', 'priority']
const DIRECTIONS: TicketDirection[] = ['asc', 'desc']

/** Only non-default values land in the URL, so an unfiltered queue stays a bare `/tickets`. */
export function toQuery(state: TicketQueryState): LocationQueryRaw
/** Ignores anything unparseable — a hand-edited URL must never throw or 422. */
export function fromQuery(query: LocationQuery): TicketQueryState
```

`toQuery` emits `status`, `priority`, `category` (comma-joined ids), `assignee`, `escalated`, `sort`, `direction`, `page` — omitting each when it is at its default. `fromQuery` is the exact inverse and **must be total**: a bad id becomes no filter, `sort=nonsense` falls back to `created_at`, `page=abc` becomes 1.

- **Short URL keys (`status`, not `status_id[]`)** — the URL is a human-shareable artefact and `?status=1,2&sort=priority` is legible. The API param names stay as the backend defines them; this is a separate namespace and the mapping lives only here.
- **`fromQuery` never validates against master data** — it cannot, the store may not be loaded yet. An id that does not exist reaches the API and comes back `422`, surfaced through the existing error state. That is acceptable and tested.

### 8 — Filter bar component

**Create file:** `frontend/src/components/TicketFilterBar.vue`

Props: none. It reads `useTicketsStore()` and `useMasterDataStore()` directly, and emits nothing — it mutates store state and calls `applyFilters()`. Required test ids:

| Control | `data-testid` | Source |
|---|---|---|
| Status multi-select | `filter-status` | `masterData.statuses` |
| Priority multi-select | `filter-priority` | `masterData.priorities` |
| Category multi-select | `filter-category` | `masterData.categories` — **all of them, not `activeCategories`** |
| Assignee select | `filter-assignee` | task 11 |
| Escalation select | `filter-escalated` | three options: Any / Escalated / Not escalated |
| Sort select | `filter-sort` | `created_at`, `updated_at`, `priority` |
| Direction toggle | `filter-direction` | `asc` / `desc` |
| Clear all button | `filter-clear` | task 6's `clearAll()` |
| Active count | `filter-active-count` | `activeFilterCount`, rendered only when `> 0` |

- **The category filter lists every category, including deactivated ones** — the opposite of `NewTicketView.vue:26`, which uses `activeCategories` because you must not *file* into a dead category. You must still be able to *find* what is already in one.
- **`filter-clear` is always visible** (AC5 says "a visible clear-all control"), but `:disabled="activeFilterCount === 0 && sort === 'created_at' && direction === 'desc' && page === 1"`.
- Use `<select multiple>` for the three multi-selects with `v-model.number`. **No custom dropdown widget and no new dependency.**

### 9 — Wire the view

**File: `frontend/src/views/TicketListView.vue`** — Story 19's task 12 view.

1. Render `<TicketFilterBar />` above the table.
2. Replace `onMounted(() => void store.load())` with: hydrate store state from `route.query` via `fromQuery`, **then** load.
3. Watch the store's filter/sort/page state and `router.replace({ query: toQuery(...) })` on change — **`replace`, not `push`**, so filtering does not fill the back button with every intermediate selection.
4. Watch `route.query` for back/forward navigation, and reload when it differs from current store state.

```ts
const route = useRoute()
const router = useRouter()
const store = useTicketsStore()

onMounted(async () => {
  Object.assign(store, fromQuery(route.query))
  await store.load()
})

// Guard against the feedback loop: writing the query triggers the route watcher,
// which would otherwise write it again.
let syncing = false
watch(
  () => toQuery(currentState()),
  async (query) => {
    if (syncing) return
    syncing = true
    await router.replace({ query })
    syncing = false
  },
)
```

**The `syncing` flag is mandatory.** Without it, `router.replace` → route watcher → store write → query watcher → `router.replace` is an infinite loop, and Vue Router's duplicate-navigation rejection is *not* a reliable stop because `toQuery` produces a new object identity each time.

### 10 — Sortable column headers

**File: `frontend/src/views/TicketListView.vue`**

Make the **Priority** and **Age** headers buttons that call `store.setSort(...)`, toggling direction when the column is already active. Test ids `sort-priority` and `sort-created_at`, plus `aria-sort` set to `ascending` / `descending` / `none`. The `updated_at` sort stays reachable from the filter bar's `filter-sort` select — there is no "Updated" column in Story 19's table and this story does not add one.

### 11 — The assignee dropdown, within what an agent may see

**File: `frontend/src/components/TicketFilterBar.vue`**

`UserPolicy::viewAny()` is admin-only, so **an agent calling `/admin/users` gets a 403**. The dropdown is therefore role-dependent:

```ts
const auth = useAuthStore()
const users = useUsersStore()

// Agents get the two sentinels; only admins can enumerate staff.
onMounted(() => {
  if (auth.isAdmin) void users.load()
})
```

Options are always `Anyone` (`''`), `Me` (`'me'`), `Unassigned` (`'unassigned'`), and — **only when `auth.isAdmin`** — one per `users.users`. Set `users.perPage = 100` before that first `load()` so a 30-agent org is not truncated at 15.

**Do not add a staff-list endpoint for agents in this story.** TM-31 (assignment) genuinely needs one — an agent must be able to assign a ticket to a named colleague — so **that story should own introducing `GET /api/v1/staff` or relaxing `UserPolicy::viewAny`**, and this filter should be revisited then. Note it in the PR description.

### 12 — Nothing to do

**File: `frontend/src/router/index.ts`** — **no change.** Story 19 registers `/tickets`; query parameters need no route definition, and adding `props: route => route.query` would fight the store.

### 13 — Formatting

```bash
cd frontend && npx prettier --write src/api/tickets.ts src/stores/tickets.ts src/lib/ticketQuery.ts src/components/TicketFilterBar.vue src/views/TicketListView.vue src/lib/ticketQuery.spec.ts src/components/TicketFilterBar.spec.ts src/stores/tickets.spec.ts src/views/TicketListView.spec.ts
```

Same policy as Story 19: **format only what you touch.** The unrelated files still failing `format:check` are Stories 16/18/19 debt; `cd frontend && npm run format` clears them all in one pass, but **ask before putting unreviewed files in this diff.**

---

## Edge Cases & Failure Modes

- **`sort=id`, `sort=tickets.id`, `sort=created_at;DROP TABLE tickets`, `sort=` (empty)** — every one is a `422` from `Rule::in(array_keys(self::SORTS))` in `IndexTicketRequest::rules()`. AC3 holds *structurally*: the column string comes from the `SORTS` constant, never from input.
- **`direction=DESC` (uppercase) is a `422`.** `Rule::in(['asc','desc'])` is case-sensitive. `toQuery` only ever emits lowercase, so this only bites a hand-written URL; the message names the two legal values.
- **`assigned_to=99999` (a user nobody is) returns an empty page, not a `422`.** Deliberate: `assigned_to` must accept the strings `me` and `unassigned`, so it cannot carry `exists:users,id`. Enforced by `assigneeFilter()`; asserted in test 12.
- **`assigned_to=me` for a user with zero assigned tickets** → empty page and the `tickets-empty` state, not an error.
- **`?status_id[]=` (explicitly empty array)** matches nothing, because task 3 keys off `has()` rather than `filled()`. An empty selection is a real selection.
- **A filter matching zero rows issues exactly 1 query** and skips all six eager loads (measured). Correct, but it means a query-count test written against a too-narrow filter proves nothing — test 8 uses a combination that matches rows.
- **A soft-deleted category id in `category_id`** is a `422` from `Rule::exists(...)->whereNull('deleted_at')`. A **deactivated** (but not deleted) category is accepted on purpose — TM-17's fifth criterion.
- **20+ status ids or 50+ category ids** → `422` from the `max:` rules, before a giant `whereIn` is built.
- **Priority-sorted paging ties heavily.** Only **four** distinct `level` values exist across the whole table, so `orderByDesc('id')` is doing nearly all the ordering work on pages 2+. Removing it would make a priority-sorted queue non-deterministic — far more visibly than for `created_at`.
- **A hand-edited or truncated URL must never throw.** `fromQuery` is total: `?status=abc,4` yields `[4]`, `?sort=colour` falls back to `created_at`, `?page=-3` becomes 1. Nothing in the view calls `Number()` on a query value without guarding `Number.isNaN`.
- **`?page=99` with filters** → `200` with an empty `data` (Story 19 measured this for the unfiltered case; filters do not change it). `applyFilters()` resetting `page` to 1 is what stops a user getting stranded there when they narrow a filter.
- **The URL-sync feedback loop** is the most likely way to hang this page. `router.replace` fires the route watcher, which writes store state, which fires the query watcher. The `syncing` flag in task 9 is the guard; a `JSON.stringify` comparison alone is not enough because `toQuery` returns a fresh object each call.
- **Back/forward navigation** must re-filter, not just rewrite the URL. The route watcher reloads when the incoming query differs from current store state — otherwise the browser's back button changes the address bar and nothing else.
- **An agent opening a URL an admin shared with `?assignee=7`** sends `assigned_to=7`, which is valid and returns that person's tickets. The agent simply cannot *pick* id 7 from their dropdown. That asymmetry is intended, not a bug.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketFilterTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Story 19 creates `tests/Feature/Tickets/` and `TicketFactory`. Use `TicketFactory::assignedTo()` for assignee fixtures.

1. `test_filters_by_a_single_status` — `?status_id=<id>` returns only that status.
2. `test_filters_by_multiple_statuses` — `?status_id[]=a&status_id[]=b` returns the union; a third status is absent.
3. `test_filters_by_priority_and_category` — each alone.
4. `test_all_filters_compose_on_one_request` — **AC1.** status + priority + category + `assigned_to` + `escalated` together; assert the one ticket that satisfies all five is returned and `meta.total` is 1.
5. `test_filters_by_assigned_to_me` — `?assigned_to=me` returns the caller's tickets only.
6. `test_filters_by_unassigned` — `?assigned_to=unassigned` returns only `assigned_to IS NULL` rows.
7. `test_filters_by_explicit_user_id` — `?assigned_to=<other agent id>`.
8. `test_query_count_is_constant_with_every_filter_and_sort` — **the AC1 performance guard.** All five filters plus `sort=priority`, on a fixture that **matches at least two rows**; assert **8** queries. Assert the same count at `per_page=5` and `per_page=40`. Note in a comment that an empty match would collapse to 1 query and prove nothing.
9. `test_escalated_true_and_false` — `?escalated=1` returns only `escalation_level > 0`; `?escalated=0` only `= 0`; omitted returns both.
10. `test_soft_deleted_category_id_is_rejected` — `422` on `category_id`.
11. `test_deactivated_category_id_is_accepted` — `200`, and the ticket in the deactivated category is returned.
12. `test_unknown_assignee_id_returns_an_empty_page` — `?assigned_to=99999` → `200`, `assertJsonCount(0, 'data')`, **not** `422`.
13. `test_empty_status_array_matches_nothing` — `?status_id[]=` → `200` with zero rows.
14. `test_oversized_filter_array_is_rejected` — 21 status ids → `422`.
15. `test_nonexistent_status_id_is_rejected` — `?status_id=99999` → `422` on `status_id.0`.

### Backend — `backend/tests/Feature/Tickets/TicketSortTest.php` (new)

16. `test_defaults_to_newest_first` — no params; matches Story 19's default. **Guards against this story changing it.**
17. `test_sorts_by_created_at_ascending_and_descending`.
18. `test_sorts_by_updated_at_ascending_and_descending` — `touch()` tickets in a known order.
19. `test_sorts_by_priority_level_not_priority_id` — **the important one.** Create priorities whose `level` order is the **inverse** of their `id` order, then assert `sort=priority&direction=desc` returns the highest *level* first. A join-or-subquery on `priority_id` would pass a naive test and fail this one.
20. `test_priority_sort_ties_break_by_id_descending` — several tickets sharing one priority; assert descending ids. Only four levels exist, so this is the common case.
21. `test_rejects_a_non_whitelisted_sort_column` — **AC3.** Parameterised over `id`, `tickets.id`, `reference`, `created_at;DROP TABLE tickets`, `(select 1)`, `''` → all `422` naming `sort`, and assert the message is the whitelist message from `messages()`.
22. `test_rejects_an_invalid_direction` — `direction=sideways` and `direction=DESC` → `422`.
23. `test_sort_survives_pagination` — `sort=priority` at `per_page=5`, page through everything, assert ids are distinct and levels are monotonic across page boundaries.
24. `test_next_link_preserves_filters_and_sort` — `?status_id[]=1&sort=updated_at&direction=asc&per_page=5`; assert `links.next` carries all four. Proves `withQueryString()` still applies.

### Backend — `backend/tests/Feature/Database/TicketFilterIndexTest.php` (new)

25. `test_updated_at_and_escalation_level_are_indexed` — assert both index names exist on `tickets` via `SHOW INDEX FROM tickets` (or `Schema::getIndexes('tickets')`). Cheap regression guard for task 1's migration; follow `RequestersTableSchemaTest`'s style.

### Frontend

26. **`frontend/src/lib/ticketQuery.spec.ts`** (new) — the highest-value spec here, all pure. `toQuery` omits defaults (unfiltered state → `{}`); emits comma-joined ids; round-trips through `fromQuery`. `fromQuery` is total: `?status=abc,4` → `[4]`; `?sort=colour` → `created_at`; `?page=-3` → `1`; `?escalated=false` → `false`; `{}` → all defaults. **8 tests.**
27. **`frontend/src/stores/tickets.spec.ts`** (extend Story 19's) — `applyFilters()` resets `page` to 1; inactive filters are **absent** from the `listTickets` argument (assert with `expect.not.objectContaining`); active ones are present; `setSort` sets both fields and resets the page; `clearAll()` restores every default and reloads; `activeFilterCount` counts each active group once. **6 tests.**
28. **`frontend/src/components/TicketFilterBar.spec.ts`** (new) — renders options from a stubbed `masterData`; the category select lists a **deactivated** category; the assignee select shows only Anyone/Me/Unassigned for an agent and named staff for an admin (**the `UserPolicy` constraint, asserted in the UI**); `filter-clear` is disabled with no filters and enabled with one; changing a select calls `applyFilters`. **6 tests.**
29. **`frontend/src/views/TicketListView.spec.ts`** (extend Story 19's) — mount at `/tickets?status=1&sort=priority&direction=asc` via `createAppRouter(createMemoryHistory())` and assert the store hydrated from it; changing a filter rewrites the query with `replace` (spy on it) and does **not** loop (assert `replace` is called once per change); `sort-priority` header toggles direction and sets `aria-sort`; `filter-clear` empties the query string back to `/tickets`. **4 tests.**

**No existing test is deleted or weakened.** Story 19's `TicketIndexTest` must still pass **unchanged** — including its default-ordering and `per_page` tests, which is what proves this story is additive.

---

## Migration / Rollback

Task 1 is the only schema change: two non-unique indexes added to a populated table.

- **Forward:** `php artisan migrate`. On MySQL 8 both are online `ALTER TABLE … ADD INDEX` operations — no table rebuild, no lock on reads or writes.
- **Rollback:** `php artisan migrate:rollback --step=1` drops both. **No application code depends on their existence** — without them `sort=updated_at` and `?escalated=1` fall back to a filesort and a full scan, which is a performance regression, not a failure. So a rollback is safe to do independently of a code rollback, in either order.
- **Half-applied state:** the migration adds both indexes in one closure, so a failure part-way leaves `updated_at` indexed and `escalation_level` not. Re-running after a fix fails with `Duplicate key name 'tickets_updated_at_index'`. If that happens, drop the one that landed and re-run: `ALTER TABLE tickets DROP INDEX tickets_updated_at_index;`.
- **Timing:** on a table of a few million tickets this is minutes of I/O. Nothing else in the story depends on it completing, so it can ship ahead of the code.

---

## Verification Steps

1. **Story 19 is actually done.** From `backend/`: `php artisan test --filter=TicketIndexTest` passes, and `php artisan route:list --name=tickets` shows both `tickets.index` and `tickets.store`. **If not, stop.**
2. **Migrate:** `php artisan migrate`, then `php artisan db:table tickets` — confirm `tickets_updated_at_index` and `tickets_escalation_level_index`.
3. **Backend formats and passes:** `./vendor/bin/pint --test`, then `composer test`. Expect **141 tests / 138 passing**, with only TM-14's and TM-21's known failures red.
4. **The new classes alone:** `php artisan test --filter='TicketFilterTest|TicketSortTest|TicketFilterIndexTest'`.
5. **Prove the priority sort is not sorting by id.** Temporarily change `applySort()` to `$query->orderBy('priority_id', $direction)` and re-run `--filter=test_sorts_by_priority_level_not_priority_id`. It must **fail**. Restore the subquery. Note that a fresh seed makes `level` and `id` correlate (`PrioritySeeder.php:12–15`), which is exactly why that test builds an inverted fixture.
6. **Prove the sort whitelist is doing the work.** Temporarily replace `IndexTicketRequest::SORTS[$sort]` with `$request->input('sort')` and re-run `--filter=test_rejects_a_non_whitelisted_sort_column`. It must **still pass**, because `Rule::in` rejects first — which is the point: there are two independent guards. Then also drop the `sort` rule and confirm the test fails. Restore both.
7. **Confirm the join alternative really is worse**, so nobody "simplifies" it back later:
   ```bash
   php artisan tinker --execute="DB::listen(fn(\$q) => print(\$q->sql.PHP_EOL)); \
     App\Models\Ticket::query()->join('priorities','priorities.id','=','tickets.priority_id')->where('created_at','>','2000-01-01')->count();"
   ```
   Expect **`1052 Column 'created_at' in where clause is ambiguous`**.
8. **Backend by hand**, with a token:
   - `…/tickets?status_id[]=1&status_id[]=2&priority_id=4&escalated=1&sort=priority&direction=desc` → `200`, filters composed.
   - `…/tickets?sort=id` → `422` with the whitelist message.
   - `…/tickets?assigned_to=me` and `…?assigned_to=unassigned` → both `200`.
   - `…/tickets?assigned_to=99999` → `200` with `"data": []`.
   - `…/tickets?status_id[]=1&sort=updated_at&per_page=5` → `links.next` carries all three.
9. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npx prettier --check` on task 13's file list, then `npm test` — **62 tests across 12 files**.
10. **Frontend by hand:** `npm run dev`, sign in, go to `/tickets`.
    - Pick a status and a priority → the URL becomes `/tickets?status=…&priority=…` and the table narrows.
    - **Copy that URL into a new tab** → the same filtered view, with the controls pre-selected. *(AC4.)*
    - Click **Clear all** → the URL returns to a bare `/tickets` and every control resets. *(AC5.)*
    - Click the **Priority** header twice → `aria-sort` flips and the order reverses.
    - Press **Back** → the previous filter set returns **and the table reloads**, not just the address bar.
    - Filter down to zero results → the empty state shows, not an error.
11. **Watch for the sync loop.** With the network tab open, change one filter and confirm **exactly one** request to `/api/v1/tickets`. More than one means the `syncing` guard in task 9 is wrong.
12. **Regression:** as an **agent** (not admin), open `/tickets` and confirm the assignee dropdown shows only Anyone/Me/Unassigned and **no 403 appears in the console**. Then file a ticket from `/tickets/new` to confirm `stores/tickets.ts` still creates.

---

## Done Criteria

- [ ] `status_id`, `priority_id`, `category_id`, `assigned_to` and `escalated` all filter, singly and **all five together on one request**.
- [ ] `status_id`, `priority_id` and `category_id` accept both `?status_id=3` and `?status_id[]=3&status_id[]=4`.
- [ ] `sort` accepts `created_at`, `updated_at` and `priority`; `direction` accepts `asc` and `desc`; the default is `created_at` desc, unchanged from Story 19.
- [ ] `sort=priority` orders by the priority's **level**, proven by a fixture whose level order is the inverse of its id order.
- [ ] Every non-whitelisted `sort` value returns `422`, including `id`, `tickets.id` and a string containing SQL.
- [ ] `id` descending remains the final tiebreak on every sort path, and a priority-sorted queue pages without repeats.
- [ ] The endpoint still issues **8 queries** with all five filters and `sort=priority` applied to a non-empty result.
- [ ] `tickets.updated_at` and `tickets.escalation_level` are indexed, in a new migration that leaves `create_tickets_table` untouched.
- [ ] A soft-deleted `category_id` is `422`; a **deactivated** one is `200`.
- [ ] `assigned_to` accepts `me` and `unassigned`; an unknown numeric id returns an empty page rather than `422`.
- [ ] Active filters, sort and page appear in the SPA's URL, and pasting that URL reproduces the view with controls pre-selected.
- [ ] Back/forward navigation re-filters the table, not just the address bar, and changing one filter issues exactly one API request.
- [ ] A visible **Clear all** resets every filter, the sort and the page, returning the URL to a bare `/tickets`.
- [ ] The assignee dropdown shows Anyone/Me/Unassigned to an agent and adds named staff for an admin, with **no 403** in the agent's console.
- [ ] `docs/api-contract.md` documents all eight parameters and the agent/admin asymmetry.
- [ ] Story 19's `TicketIndexTest` passes **unchanged**.
- [ ] Backend **141 / 138 passing**; frontend **62 tests across 12 files**; `pint --test`, `lint` and `typecheck` clean.
- [ ] No new composer or npm dependency; no new endpoint; no `q` parameter.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 21 (TM-25, search tickets).**
