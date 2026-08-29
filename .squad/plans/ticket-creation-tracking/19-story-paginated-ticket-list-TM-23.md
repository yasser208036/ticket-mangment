# Story 19 — Paginated ticket list (Story: TM-23)

## Prerequisites

- **Story 17 (TM-21) completed — implemented.** `tickets` and `requesters` exist. Confirm with `php artisan db:table tickets`; the six columns this story eager-loads through are `requester_id`, `category_id`, `priority_id`, `status_id`, `assigned_to`, `created_by` (`backend/database/migrations/2026_08_26_084625_create_tickets_table.php:19–24`).
- **Story 18 (TM-22) completed — implemented, but it shipped without its tests and without its docs.** `TicketController`, `TicketPolicy`, `TicketResource`, `api/tickets.ts`, `stores/tickets.ts` and `NewTicketView.vue` all exist on disk. **Three consequences land on this story and are called out as tasks 5 and 13** — see [`18-story-create-a-ticket-TM-22.md`](18-story-create-a-ticket-TM-22.md).
- **Story 16 (TM-19) completed — implemented.** `authGuard` (`frontend/src/router/guards.ts:37`) calls `useMasterDataStore().ensureLoaded()` on every authenticated navigation, so the list view may read master data without loading it itself.
- **No new composer or npm dependency.** Everything this story needs is already installed.
- **Docker must be up before any backend command.** `docker compose ps` must show `tm-mysql-test` healthy on **3307**; `backend/phpunit.xml` points the suite there.

**Coordinate with nothing.** This story adds one route, one policy method, one controller method and one resource line on the backend; on the frontend it grows two existing files and adds four. It does not touch `NewTicketView.vue`, any migration, or any seeder.

---

## Story Goal

An agent opening the SPA gets the whole ticket queue as a paginated table they can page through, showing only the fields they triage on.

1. `GET /api/v1/tickets` returns Laravel's paginated `data` / `links` / `meta` envelope, newest ticket first.
2. Each row carries **reference**, **subject**, **requester**, **category**, **priority**, **status**, **assignee** and the timestamp the age is rendered from.
3. The endpoint issues a **constant 8 queries** regardless of page size.
4. `per_page` is a query parameter, defaulting to **15** and capped at **100**.
5. `/tickets` in the SPA renders the table with coloured status, priority and category badges and a relative age ("3 days ago"), and has visually distinct **loading**, **empty** and **error** states.

**Not in scope.** Filtering and sorting are **TM-24**, full-text search is **TM-25**, the detail page is **TM-26**. This story adds **no** `q`, `status`, `category` or `sort` parameter — adding them here would collide with TM-24's plan. `description` is deliberately **removed** from the list payload (task 3); the detail endpoint in TM-26 is where it comes back.

---

## Context — Read These Files First

1. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — **the precedent this story copies.** Read `index()` at **lines 19–38**: `authorize('viewAny', …)`, an inline `$request->validate([...])` with `'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']` (**line 25**), a compound `orderBy` (**line 34**), and `->paginate($filters['per_page'] ?? 15)->withQueryString()` (**line 35**). Match all four. Note the return type is `AnonymousResourceCollection`, not `JsonResponse`.
2. `backend/app/Http/Controllers/Api/V1/TicketController.php` — the file you extend. **55 lines**; `store()` is **23–43**, the private `defaultKey()` helper is **45–54**. `index()` goes **above** `store()`. Note **line 42**: `store()` already loads exactly the six relations this story needs.
3. `backend/app/Http/Resources/V1/TicketResource.php` — **29 lines, one class serving both endpoints.** `description` is **line 14**; `assignee` and `creator` are inline `whenLoaded` closures at **19–20**. Story 18's overview requires TM-23 to **vary the eager-load list rather than add a second resource class** — task 3 honours that with one conditional line.
4. `backend/app/Policies/TicketPolicy.php` — **13 lines, only `create()`.** `viewAny()` is missing; task 1 adds it.
5. `backend/routes/api.php` — **line 44** is `tickets.store`. The new route goes immediately above it, inside the `['auth:sanctum', 'active']` group (**line 32**, closing at **51**) and **outside** the `admin` group (**line 45**).
6. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **read the whole file, it is 125 lines.** `ACCESS` is a single-line const at **line 16**. `test_every_api_route_is_classified` (**18–25**) asserts every `api/v1` route name is a key in it, and **is red right now** because Story 18 never added `tickets.store`. `test_agent_reaches_staff_routes` (**52–58**) is three explicit assertions, **not** a loop. `routesFor()` (**121–124**) builds URIs via `route($name, ['user' => 999999, 'category' => $categoryId])`.
7. `backend/database/factories/UserFactory.php` — **the only factory in the project.** Read the `admin()` / `agent()` / `inactive()` states at **49–62** and copy that state idiom. There is no `TicketFactory`, `RequesterFactory`, `CategoryFactory`, `PriorityFactory` or `StatusFactory` — task 6 adds the first two and **deliberately not the other three**.
8. `frontend/src/stores/users.ts` — **the store precedent, 77 lines.** Read it end to end. `latestRequest` (**line 19**) and the three `if (request !== latestRequest) return` guards (**30, 34, 39**) are the out-of-order-response guard; `load()` (**20–41**), `goToPage()` (**51–54**). Task 9 reproduces this shape for tickets.
9. `frontend/src/views/AdminUsersView.vue` — **the view precedent, 80 lines.** The `users-loading` / `users-error` / `users-table` / `users-empty` four-state block is **39–56**, the `Showing … to … of …` counter is **57–60**, and the disabled-at-the-ends Previous/Next pair is **61–72**. Task 12 follows this structure with ticket test ids.
10. `frontend/src/api/pagination.ts` — **18 lines.** `Paginated<T>` already declares `data`, `links` and `meta` with the exact seven `meta` keys the API returns. **Reuse it; do not redeclare it.**
11. `frontend/src/components/CategoryBadge.vue` — the badge precedent. Props are `Pick<Category, 'name' | 'color' | 'is_active'>` (**5–7**), text colour comes from `readableTextColor` (**line 8**, `frontend/src/lib/color.ts:10–16`), and `data-inactive` dims a deactivated category (**24–26**). **Keep using this component for the category column** — TM-17's fifth criterion needs deactivated categories to still render on existing tickets. Task 11 adds a sibling for priority and status, which have no `is_active`.
12. `frontend/src/api/priorities.ts` and `frontend/src/api/statuses.ts` — both `Priority` and `Status` already carry `color: string`. Confirmed against `char('color', 7)` in both migrations (`2026_08_26_073218_create_priorities_table.php:19`, `2026_08_26_073219_create_statuses_table.php:20`). **No backend change is needed to colour the badges.**
13. `frontend/src/views/HealthView.spec.ts` — the component-test precedent. `vi.mock` of the api module (**line 9**), `mount(…, { global: { plugins: [createPinia()] } })` (**19–20**), `flushPromises()`, then `wrapper.get('[data-testid="…"]')`. Task 16 follows it exactly.
14. `frontend/src/api/errors.ts` — `errorMessage()` (**11–21**) already maps `403` and `429` and falls back to `'The API is unreachable.'`. The store uses it; **do not write new error-message strings.**

---

## Measured facts that decide these tasks

Everything below was measured on **`mysql:8.4` (`tm-mysql-test`, port 3307)** through Laravel's own query log during planning, with 40 tickets at the 16,000-character `description` cap. Do not re-derive them.

- **The eager-loaded query count is 8, and it is genuinely constant.** Measured at `per_page` 5, 15 and 40: **8 queries every time**. The eight are `count(*)`, the page itself, then one each for `requesters`, `categories`, `priorities`, `statuses` and **`users` twice** — `assignee` and `creator` are separate relations, so Eloquent issues two `select * from users where id in (…)` queries even when both resolve to the same row. That is correct and is not an N+1.

- **The count drops to 7 when no ticket on the page is assigned, and a naive test is flaky because of it.** Measured: a page of 15 rows all with `assigned_to = NULL` produced **7** queries — Eloquent **skips the relation query entirely** when every foreign key in the batch is null. So `assertCount(8, …)` passes or fails depending on whether the page happens to contain an assigned ticket. **Task 15's query-count test must assign every ticket it creates**, and it asserts *equality across two page sizes* as well as the literal 8. This is the single easiest way to write a test that passes today and fails next sprint.

- **`whenLoaded` already survives a null `assignee`; no defensive change is needed.** `ConditionallyLoadsAttributes::whenLoaded()` returns early (`if ($loadedValue === null) { return; }`) **before** invoking the closure, so the `$this->assignee->id` closure at `TicketResource.php:19` is never reached for an unassigned ticket. Measured end to end: an unassigned row serialises to `"assignee": null`. **Do not add a null check to line 19.**

- **`description` is 95% of the payload.** Measured on 40 rows at the cap: **677,891 bytes with `description`, 37,211 without** — an **18× reduction**. AC1 lists eight fields and `description` is not one of them; the table never renders it. Task 3 removes it from the index response.

- **`created_at` is `timestamp` precision 0, and ties are total, not theoretical.** Measured: `DATETIME_PRECISION = 0`, and 40 tickets inserted in one loop produced **1 distinct `created_at` value**. Ordering by `created_at` alone therefore leaves the row order **unspecified** under `LIMIT`/`OFFSET`, which is how a paginated list silently repeats one row and skips another. To be exact about what was and was not observed: paging 4×10 through those 40 fully-tied rows returned **40 unique ids** in this run, so instability was *not* reproduced — MySQL happened to be stable. **The `orderByDesc('id')` tiebreak in task 2 is required because the order is unspecified, not because a duplicate was seen.** Do not remove it on the grounds that a test passes without it.

- **A page past the end is `200` with an empty `data` array, not `404`.** Measured `page=99` against 40 rows: `count=0`, `total=40`, `current_page=99`. The SPA's empty state must therefore cover "you paged off the end" as well as "there are no tickets" — task 12.

- **`meta` carries an eighth key the frontend type does not declare.** The real response `meta` is `current_page, from, last_page, links, path, per_page, to, total` — `links` (the array of page links) is absent from `Paginated<T>['meta']` at `frontend/src/api/pagination.ts:9–17`. Extra runtime keys are ignored by TypeScript, so this breaks nothing. **Leave `pagination.ts` alone.**

- **Soft-deleted tickets are already excluded.** Both the count and the page query emit `where tickets.deleted_at is null` from `SoftDeletes` on the model (`backend/app/Models/Ticket.php:16`). No `whereNull` is needed; task 15 asserts it anyway, because TM-28 will touch this.

---

## Baselines — know these before you start

- **Backend: 101 tests, 98 passing, 288 assertions.** `php artisan test`. **Three are red on arrival**, and only two of them are somebody else's problem:
  1. `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` — expects `422`, gets `429`. **TM-14's. Out of scope.**
  2. `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` — `RefreshDatabase` holds an open transaction, so `DB::transactionLevel()` is never 0 and the guard cannot fire. **TM-21's. Out of scope.**
  3. `Authorization\RouteAuthorizationTest::test_every_api_route_is_classified` — *"Failed asserting that an array has the key 'tickets.store'"*. **This one is in your way**: you are adding `tickets.index` to the very same map, and you cannot verify your own change against a file that is already failing. **Task 5 closes it.**
- **Frontend: 24 tests across 6 files, all passing.** `npm test`. Unchanged since Story 17 — Story 18 added none.
- **`npm run lint` passes. `npm run format:check` FAILS on 11 files**, two of which you must edit: `src/api/tickets.ts` and `src/stores/tickets.ts`. The other nine are `src/api/categories.ts`, `src/api/priorities.ts`, `src/api/statuses.ts`, `src/App.vue`, `src/components/CategoryDeleteDialog.vue`, `src/stores/masterData.ts`, `src/views/AdminCategoriesView.vue`, `src/views/LoginView.vue`, `src/views/NewTicketView.vue`. **CI's Prettier step (`.github/workflows/ci.yml:82`) is red on `main` today** — this is pre-existing debt from Stories 16, 18 and 19, not something you broke. Task 14 is explicit about how far to go.
- **Expected end state: 101 → 119 backend tests (116 passing, the two out-of-scope failures still red), and 24 → 42 frontend tests across 10 files.**

---

## Backend Tasks

### 1 — Authorize listing

**File: `backend/app/Policies/TicketPolicy.php`**

Add `viewAny()` above `create()`. Every authenticated, active staff member sees the whole queue — there is no per-agent scoping in this product, and `create()` at **line 9** already returns `true` unconditionally for the same reason.

```php
public function viewAny(User $user): bool
{
    return true;
}
```

Leave `create()` exactly as it is.

### 2 — `TicketController::index()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Insert `index()` **above** `store()` (before **line 23**). Add `use Illuminate\Http\Request;` and `use Illuminate\Http\Resources\Json\AnonymousResourceCollection;` to the imports at **lines 15–19**, keeping them alphabetical.

```php
/**
 * The six eager loads are what keep this endpoint at a constant 8 queries
 * regardless of page size: count, page, and one per relation — `users` twice,
 * because `assignee` and `creator` are separate relations. Adding a relation
 * here costs exactly one query; loading one lazily in the resource costs one
 * per row. See TM-23's plan for the measurements.
 */
public function index(Request $request): AnonymousResourceCollection
{
    $this->authorize('viewAny', Ticket::class);
    $filters = $request->validate([
        'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
    ]);
    $tickets = Ticket::query()
        ->with(['requester', 'category', 'priority', 'status', 'assignee', 'creator'])
        // `created_at` is a second-precision timestamp, so bulk-created tickets tie.
        // The `id` tiebreak is what makes LIMIT/OFFSET paging deterministic.
        ->orderByDesc('created_at')->orderByDesc('id')
        ->paginate($filters['per_page'] ?? 15)->withQueryString();

    return TicketResource::collection($tickets);
}
```

Four constraints, all of them load-bearing:

- **`min:1`, `max:100`, default `15`** — identical to `UserController.php:25` and **:35**. AC3's "sane maximum" is 100.
- **`withQueryString()`** — without it `links.next` drops `per_page` and page 2 silently reverts to 15 rows.
- **Do not add `->withCount()`, a `q` parameter, or any filter.** Those are TM-24 and TM-25.
- **Do not use `simplePaginate()`** — it omits `meta.total` and `meta.last_page`, which task 12's counter and Next button both read.

### 3 — Drop `description` from the list payload

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Replace `description` on **line 14** so it is omitted for the index route only:

```php
'subject' => $this->subject,
// 40 rows at the 16,000-character cap serialise to 678 KB with `description`
// and 37 KB without it. The list never renders it; TM-26's detail page does.
// Inverted on purpose: a new route gets `description` unless it opts out.
'description' => $this->when(! $request->routeIs('tickets.index'), fn () => $this->description),
```

**Keep it one class.** Story 18's overview commits TM-23 and TM-26 to varying the eager-load list rather than adding a second resource, and `$this->when()` honours that in one line. **Do not create `TicketListResource`.** Leave **lines 19–20** untouched — the null-`assignee` path is already safe.

### 4 — Register the route

**File: `backend/routes/api.php`**

Immediately above **line 44** (`tickets.store`):

```php
Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
```

No new import — `TicketController` is imported at **line 11**. It must sit inside the `['auth:sanctum', 'active']` group (opens at **line 32**) and **not** inside the `admin` group at **line 45**: agents are the primary users of this list.

### 5 — Classify both ticket routes and unbreak the suite

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Two edits to `ACCESS` on **line 16**, appended to the end of the array:

```php
'tickets.index' => 'staff', 'tickets.store' => 'staff-write'
```

- **`tickets.index` is `staff`** — a `GET` with no body, so an agent genuinely gets `200` and it belongs with `categories.index`.
- **`tickets.store` is `staff-write`** — this is **TM-22's unpaid debt, and you are paying it because it blocks you.** Story 18's plan specified the level and never added it, which is why `test_every_api_route_is_classified` is red. No existing level fits: `staff` would drag `POST /api/v1/tickets` into an `assertOk()` expectation, and an empty body there is a `422`. `staff-write` means *an agent is not `403`*.

Then extend `test_agent_reaches_staff_routes` (**52–58**) with one line beside the three that are there:

```php
$this->withToken($token)->getJson('/api/v1/tickets')->assertOk();
```

And add a sibling test for the new level:

```php
public function test_agent_reaches_staff_write_routes(): void
{
    $token = $this->tokenFor(User::factory()->agent()->create());
    foreach ($this->routesFor('staff-write') as $route) {
        $this->assertNotSame(403, $this->withToken($token)->json($route['method'], $route['uri'])->status());
    }
}
```

**Do not convert `test_agent_reaches_staff_routes` into a loop over `routesFor('staff')`.** Story 18's plan already warned about this and it is still true: `routesFor()` (**121–124**) resolves `{category}` to `999999`, so `categories.show` would return `404` and the `assertOk()` would fail. That refactor remains **TM-19's** outstanding task.

### 6 — Two factories, and deliberately only two

**Create file: `backend/database/factories/RequesterFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Requester;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Requester>
 */
class RequesterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->e164PhoneNumber(),
            'company' => fake()->optional()->company(),
        ];
    }
}
```

`requesters.email` is unique (`RequestersTableSchemaTest` covers the column), so `unique()` on the email is mandatory — a factory run of 40 without it collides.

**Create file: `backend/database/factories/TicketFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Priority;
use App\Models\Requester;
use App\Models\Status;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 *
 * Requires master data: call `$this->seed()` before using this factory. The
 * `priorities` and `statuses` tables each carry a `is_default_unique` virtual
 * column with a unique index, so a second row claiming `is_default` fails with
 * MySQL `ERROR 3105` — which is exactly why there is no PriorityFactory or
 * StatusFactory and this factory reads the seeded rows instead.
 */
class TicketFactory extends Factory
{
    private static int $sequence = 0;

    public function definition(): array
    {
        return [
            'reference' => sprintf('TKT-%d-%06d', now()->year, ++self::$sequence),
            'subject' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'requester_id' => Requester::factory(),
            'category_id' => fn () => Category::query()->value('id'),
            'priority_id' => fn () => Priority::query()->where('is_default', true)->value('id'),
            'status_id' => fn () => Status::query()->where('is_default', true)->value('id'),
            'created_by' => User::factory()->agent(),
            'assigned_to' => null,
        ];
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => ['assigned_to' => $user->getKey()]);
    }
}
```

Three things worth not re-deriving:

- **`reference` and `created_by` are not in `#[Fillable]`** (`Ticket.php:12`), and that is fine: `Factory::makeInstance()` builds inside `Model::unguarded()`, so factories bypass `$fillable` entirely. Story 17 recorded the same fact.
- **The static counter, not `fake()->unique()`** — `reference` is `char(15) unique`, and `TKT-2026-000001` is exactly 15 characters. A faker unique on a formatted string is far easier to collide.
- **No `Category`/`Priority`/`Status` factory.** Their `name`, `slug` and `level` columns are unique and both tables reject a second default row at the storage layer. Seeding is the supported path and it works — `$this->seed()` then query, exactly as this factory does.

### 7 — Document both endpoints

**File: `docs/api-contract.md`**

Add two rows to the endpoint table after **line 47**. The `POST` row is TM-22's, missing because Story 18 skipped its docs task; leaving it out would make the table actively wrong next to the row you *are* adding.

```markdown
| `POST` | `/api/v1/tickets` | File a ticket; requester matched or created by email. | bearer (TicketPolicy) | TM-22 |
| `GET` | `/api/v1/tickets` | Paginated ticket queue, newest first. `per_page` 1–100, default 15. Omits `description`. | bearer (TicketPolicy) | TM-23 |
```

Also correct **lines 27–28**, which still promise the ticket policy as future work:

```markdown
`UserPolicy` defines `viewAny`, `view`, `create`, `update`, and `delete`.
Admins may list, read, create, and edit users; users may read themselves;
deletion is denied for everyone. `CategoryPolicy` (TM-17) and `TicketPolicy`
(TM-22, TM-23) authorize their own records. `TicketPolicy` allows every active
staff member to `viewAny` and `create`; per-record actions arrive with TM-26.
```

**Leave `docs/erd.md` alone** — this story adds no column.

---

## Frontend Tasks

### 8 — List types and the request

**File: `frontend/src/api/tickets.ts`**

The file is **9 lines of dense one-liners and Prettier already rejects it** (see baselines). Add the list API, then reformat the whole file in task 14.

```ts
import type { Paginated } from './pagination'

export type TicketListItem = Omit<Ticket, 'description'>

export interface TicketListQuery {
  page?: number
  per_page?: number
}

export async function listTickets(
  query: TicketListQuery = {},
): Promise<Paginated<TicketListItem>> {
  const { data } = await client.get<Paginated<TicketListItem>>('/tickets', {
    params: query,
  })
  return data
}
```

- **`Omit<Ticket, 'description'>`** is the type-level mirror of task 3. Do not widen `Ticket` or make `description` optional on it — TM-26 needs it required.
- **Return the whole envelope**, not `data.data`. `listUsers` (`frontend/src/api/users.ts:29–36`) does the same, because the store needs `meta`.
- **No `q` / `status` / `sort` on `TicketListQuery`.** TM-24 adds those.

### 9 — Grow the tickets store

**File: `frontend/src/stores/tickets.ts`**

The store is **9 lines** and currently holds only `creating` and `create`. **Keep both** — `NewTicketView.vue:13` calls `tickets.create`. Add list state alongside, mirroring `stores/users.ts` including its race guard.

```ts
const items = ref<TicketListItem[]>([])
const meta = ref<Paginated<TicketListItem>['meta'] | null>(null)
const page = ref(1)
const perPage = ref(15)
const loading = ref(false)
const error = ref<string | null>(null)
let latestRequest = 0

async function load(): Promise<void> {
  const request = ++latestRequest
  loading.value = true
  error.value = null
  try {
    const response = await listTickets({
      page: page.value,
      per_page: perPage.value,
    })
    if (request !== latestRequest) return
    items.value = response.data
    meta.value = response.meta
  } catch (caughtError) {
    if (request !== latestRequest) return
    error.value = errorMessage(caughtError)
    items.value = []
    meta.value = null
  } finally {
    if (request === latestRequest) loading.value = false
  }
}

async function goToPage(target: number): Promise<void> {
  page.value = target
  await load()
}

async function setPerPage(size: number): Promise<void> {
  perPage.value = size
  page.value = 1
  await load()
}
```

- **The `latestRequest` guard is not optional.** Clicking Next twice quickly otherwise lets the slower response overwrite the faster one. `stores/users.ts:19,30,34,39` is the pattern.
- **`setPerPage` resets `page` to 1** — page 4 of 15 does not exist at 100 per page, and per the measurements the API answers `200` with an empty array rather than an error, so the user would just see a blank table.
- **Name the array `items`, not `tickets`** — `useTicketsStore().tickets` reads badly and collides with the store's own name.
- Add `errorMessage` to the imports from `../api/errors`; **write no new error strings.**

### 10 — Relative age

**Create file: `frontend/src/lib/relativeTime.ts`**

```ts
const DIVISIONS: { amount: number; unit: Intl.RelativeTimeFormatUnit }[] = [
  { amount: 60, unit: 'second' },
  { amount: 60, unit: 'minute' },
  { amount: 24, unit: 'hour' },
  { amount: 7, unit: 'day' },
  { amount: 4.34524, unit: 'week' },
  { amount: 12, unit: 'month' },
  { amount: Number.POSITIVE_INFINITY, unit: 'year' },
]

/**
 * "3 days ago". `now` is injectable so the tests are not clock-dependent.
 * The API sends ISO-8601 `created_at`; the relative string is rendered here
 * rather than server-side so it stays correct without a refetch.
 */
export function relativeAge(iso: string, now: Date = new Date()): string {
  const parsed = new Date(iso)
  if (Number.isNaN(parsed.getTime())) return ''
  const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
  let delta = (parsed.getTime() - now.getTime()) / 1000
  for (const division of DIVISIONS) {
    if (Math.abs(delta) < division.amount)
      return formatter.format(Math.round(delta), division.unit)
    delta /= division.amount
  }
  return ''
}
```

**On AC1's "age" field.** The endpoint returns **`created_at`**, already present at `TicketResource.php:26`, and the age is derived here. A server-rendered `diffForHumans()` string would be **wrong in the browser the moment it arrives** — it freezes at response time, cannot re-render as the page sits open, and bakes the server's timezone and locale into a cached-by-nature payload. `created_at` is the machine-readable fact; "3 days ago" is a rendering of it. **AC1's field list is satisfied by `created_at`; AC4's "relative age such as 3 days ago" is satisfied here.** Do not add an `age` string to the resource.

The **`now` parameter is what makes this testable** — never call `relativeAge(iso)` without it in a spec.

### 11 — A badge for colour-only master data

**Create file: `frontend/src/components/ColorBadge.vue`**

`CategoryBadge.vue` cannot serve priority and status: its prop type demands `is_active`, which neither has. Same visual, narrower prop.

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { readableTextColor } from '../lib/color'
const props = defineProps<{ name: string; color: string }>()
const textColor = computed(() => readableTextColor(props.color))
</script>
<template>
  <span
    class="badge"
    data-testid="color-badge"
    :style="{ backgroundColor: color, color: textColor }"
    >{{ name }}</span
  >
</template>
<style scoped>
.badge {
  padding: 3px 8px;
  border-radius: 4px;
}
</style>
```

Reuse `readableTextColor` (`frontend/src/lib/color.ts:10–16`) — it already guarantees contrast against any seeded hex and falls back to dark text on a malformed value. **Do not reimplement the luminance maths, and do not touch `CategoryBadge.vue`.**

### 12 — The list view

**Create file: `frontend/src/views/TicketListView.vue`**

Follow `AdminUsersView.vue`'s four-state structure (**39–56**) and its pager (**61–72**). Eight columns, in AC1's order.

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import CategoryBadge from '../components/CategoryBadge.vue'
import ColorBadge from '../components/ColorBadge.vue'
import { relativeAge } from '../lib/relativeTime'
import { useTicketsStore } from '../stores/tickets'
const store = useTicketsStore()
const PAGE_SIZES = [15, 25, 50, 100]
onMounted(() => void store.load())
</script>
```

Required markup, with these exact `data-testid` values — task 16's spec and TM-24 both target them:

| Element | `data-testid` | Rendering rule |
|---|---|---|
| Loading indicator | `tickets-loading` | `v-if="store.loading"` |
| Error banner | `tickets-error` | `v-if="store.error"` — `{{ store.error }}` |
| Table | `tickets-table` | `v-if="!store.loading && store.items.length"` |
| Row | `tickets-row` | `v-for="ticket in store.items" :key="ticket.id"` |
| Empty state | `tickets-empty` | `v-if="!store.loading && !store.error && !store.items.length"` |
| Counter | `tickets-count` | `v-if="store.meta"` — `Showing {{ store.meta.from ?? 0 }} to {{ store.meta.to ?? 0 }} of {{ store.meta.total }}` |
| Previous | `tickets-prev` | `:disabled="!store.meta || store.meta.current_page <= 1"` |
| Next | `tickets-next` | `:disabled="!store.meta || store.meta.current_page >= store.meta.last_page"` |
| Page size | `tickets-per-page` | `<select>` over `PAGE_SIZES`, `@change` → `store.setPerPage` |
| Age cell | `tickets-age` | `{{ relativeAge(ticket.created_at) }}`, `:title="ticket.created_at"` |

Three rules the four states hang on:

- **All three states are mutually exclusive.** The empty state must test `!store.error` as well — on a failed request the store sets `items` to `[]`, so an empty-state check that only looks at `items.length` renders "No tickets found." *underneath* the error banner and tells the user the queue is empty when it is merely unreachable. `AdminUsersView.vue:54` has this bug; **do not copy it.**
- **The empty state's wording must cover paging off the end**, which the measurements show is a `200` with an empty array: `"No tickets on this page."` — not `"No tickets yet."`
- **Category uses `CategoryBadge`** (so a deactivated category still renders, dimmed, per TM-17's fifth criterion); **priority and status use `ColorBadge`**. Assignee renders `ticket.assignee?.name ?? 'Unassigned'` — the API sends `null`, verified.

### 13 — Route and navigation

**File: `frontend/src/router/index.ts`**

Import `TicketListView` alongside the others (**4–10**) and add the route **above** the `/tickets/new` entry at **line 49**:

```ts
{ path: '/tickets', name: 'tickets', component: TicketListView },
```

**No `meta.role`** — every authenticated agent lists tickets, and `authGuard` (`guards.ts:35–36`) already refuses unauthenticated users and only gates on `role` when it is set. Order matters for readability only; `/tickets` and `/tickets/new` are distinct static paths and do not shadow each other.

**File: `frontend/src/App.vue`**

Add a nav link **before** the New ticket link at **line 23**, matching its classes exactly:

```html
<RouterLink :to="{ name: 'tickets' }" class="hover:text-indigo-600" data-testid="nav-tickets">Tickets</RouterLink>
```

### 14 — Formatting

Run Prettier over **only the files this story creates or edits**:

```bash
cd frontend && npx prettier --write src/api/tickets.ts src/stores/tickets.ts src/lib/relativeTime.ts src/components/ColorBadge.vue src/views/TicketListView.vue src/router/index.ts src/App.vue src/api/tickets.spec.ts src/stores/tickets.spec.ts src/lib/relativeTime.spec.ts src/views/TicketListView.spec.ts
```

`src/api/tickets.ts`, `src/stores/tickets.ts` and `src/App.vue` are three of the 11 files already failing `format:check`, so this clears three of them.

**`npm run format:check` will still fail on the remaining eight** — `src/api/categories.ts`, `src/api/priorities.ts`, `src/api/statuses.ts`, `src/components/CategoryDeleteDialog.vue`, `src/stores/masterData.ts`, `src/views/AdminCategoriesView.vue`, `src/views/LoginView.vue`, `src/views/NewTicketView.vue`. That is Stories 16, 18 and 19's debt and **CI is already red on it before you start**. Do not silently absorb it into this story's diff; do not "fix" it by editing `.prettierrc.json` or adding a `.prettierignore`. If the user wants CI green in this PR, `cd frontend && npm run format` clears all eight in one mechanical, review-free pass — **ask first**, because it puts eight files nobody reviewed into a ticket-list diff.

---

## Edge Cases & Failure Modes

- **A page of entirely unassigned tickets issues 7 queries, not 8.** Eloquent skips a `belongsTo` batch whose keys are all null. Not a defect — but it makes `assertCount(8, …)` data-dependent, which is why the query-count test assigns every ticket and compares two page sizes. Enforced in `tests/Feature/Tickets/TicketIndexTest.php` (task 15).
- **`assignee` is `null` on almost every row.** Handled upstream of the resource by `whenLoaded`'s null guard (`ConditionallyLoadsAttributes::whenLoaded()`); rendered as `'Unassigned'` by task 12. `TicketResource.php:19` needs no change.
- **`per_page=0`, `per_page=101`, `per_page=abc`, `per_page=-5`** — all `422` from the `['sometimes','integer','min:1','max:100']` rule in `index()`. `per_page` absent → 15. **`per_page=100.0`** is accepted by `integer` and is intended.
- **`page` is not validated, by design.** `page=abc` is coerced to 1 by `LengthAwarePaginator`; `page=99` past the end is `200` with `data: []` (measured). Adding a `page` rule would turn a harmless bookmark into a `422`.
- **Paging off the end shows the empty state, not an error.** `tickets-empty` reads `"No tickets on this page."` (task 12) precisely because `total` can be 40 while `data` is empty.
- **A failed request must not read as an empty queue.** `load()`'s catch sets `items = []` **and** `error`; the empty state's `!store.error` guard (task 12) is what keeps "No tickets on this page." from appearing under the error banner.
- **Two fast Next clicks.** The `latestRequest` counter in `stores/tickets.ts` (task 9) drops the stale response. Without it the page-1 response can land after page-2's and the table contradicts the counter.
- **A deactivated category on an existing ticket still renders**, dimmed via `CategoryBadge`'s `data-inactive` (`CategoryBadge.vue:15,24–26`). TM-17's fifth criterion; the list must not filter these rows out.
- **A malformed or missing colour** falls back to dark text on whatever background — `readableTextColor` (`lib/color.ts:11`) regex-guards non-`#RRGGBB` input. Both migrations default the column to `#6B7280`, so this is defence in depth.
- **A malformed `created_at`** returns `''` from `relativeAge` rather than `"Invalid Date"`. The `title` attribute still carries the raw value.
- **Soft-deleted tickets never appear.** `SoftDeletes` on `Ticket` (`Ticket.php:16`) adds `deleted_at is null` to both the count and the page query — verified in the query log. Asserted anyway, because TM-28 will touch it.
- **Arabic and emoji subjects** render unchanged: both MySQL containers are `utf8mb4` and the response is JSON. No column this story reads is new.
- **`meta.links` exists at runtime but not in `Paginated<T>['meta']`.** Harmless — TypeScript ignores excess properties on a response cast. Do not "fix" `pagination.ts`.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketIndexTest.php` (new; `RefreshDatabase` + `$this->seed()`)

The directory `tests/Feature/Tickets/` does not exist yet. Model the class on `tests/Feature/Authorization/RouteAuthorizationTest.php`'s `tokenFor()` helper (**116–119**).

1. `test_unauthenticated_request_is_rejected` — no token → `401`.
2. `test_agent_can_list_tickets` — agent token → `200`, `assertJsonStructure(['data', 'links' => ['first','last','prev','next'], 'meta' => ['current_page','from','last_page','per_page','to','total']])`.
3. `test_row_exposes_every_triage_field` — one ticket → `assertJsonPath` for `data.0.reference`, `data.0.subject`, `data.0.requester.name`, `data.0.category.name`, `data.0.priority.name`, `data.0.status.name`, and asserts `data.0.created_at` is non-null. All eight AC1 fields.
4. `test_description_is_omitted_from_the_list` — `$response->assertJsonMissingPath('data.0.description')`.
5. `test_unassigned_ticket_serialises_assignee_as_null` — `assertJsonPath('data.0.assignee', null)`.
6. `test_assigned_ticket_exposes_assignee_id_and_name` — `TicketFactory::assignedTo($agent)`; asserts `data.0.assignee.id` / `.name` and **`assertJsonMissingPath('data.0.assignee.email')`** (nesting `UserResource` would leak the staff directory — Story 18's constraint).
7. `test_query_count_is_constant_across_page_sizes` — **the AC2 test.** Seed **40 tickets, every one assigned** (see the measured facts — an unassigned page drops to 7 and makes this flaky). Count via `DB::listen` around `per_page=5` and `per_page=40`; assert both equal **8** and equal each other.
8. `test_default_page_size_is_fifteen` — 20 tickets, no `per_page` → `assertJsonCount(15, 'data')` and `meta.per_page === 15`.
9. `test_per_page_is_respected` — `per_page=5` → `assertJsonCount(5, 'data')`, `meta.per_page === 5`.
10. `test_per_page_above_the_maximum_is_rejected` — `per_page=101` → `422` with `errors.per_page`.
11. `test_per_page_below_one_and_non_numeric_are_rejected` — `per_page=0` and `per_page=abc` → `422`.
12. `test_next_link_preserves_per_page` — `per_page=5` on 20 tickets; assert `links.next` contains `per_page=5`. Proves `withQueryString()`.
13. `test_newest_ticket_is_first` — three tickets with explicitly distinct `created_at`; assert `data.0.reference` is the newest.
14. `test_paging_is_stable_when_created_at_ties` — 30 tickets sharing one `created_at`; page through at `per_page=10` and assert **30 distinct ids** collected. This is the `orderByDesc('id')` regression guard.
15. `test_page_beyond_the_last_returns_an_empty_page` — `page=99` → `200`, `assertJsonCount(0, 'data')`, `meta.total` still correct.
16. `test_soft_deleted_tickets_are_excluded` — delete one of three; `assertJsonCount(2, 'data')` and `meta.total === 2`.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

17. `test_every_api_route_is_classified` and `test_every_classified_route_exists` — **both go green** once task 5 adds the two keys. `test_every_api_route_is_classified` is red before you start; confirm it is green after.
18. `test_agent_reaches_staff_routes` — gains the `/api/v1/tickets` assertion.
19. `test_agent_reaches_staff_write_routes` — **new**, per task 5.

### Frontend

20. **`frontend/src/api/tickets.spec.ts`** (new) — `vi.mock('./client')`. Three tests: `listTickets()` requests `/tickets`; it forwards `{ page, per_page }` as `params`; it returns the **whole envelope** (`data`, `links`, `meta`), not `data.data`. Follow `frontend/src/api/auth.spec.ts` for the client-mock shape.
21. **`frontend/src/lib/relativeTime.spec.ts`** (new) — always pass an explicit `now`. Five tests: 3 days → `"3 days ago"`; 2 hours → `"2 hours ago"`; 45 seconds → `"45 seconds ago"`; 5 months → `"5 months ago"`; `relativeAge('not-a-date', now)` → `''`.
22. **`frontend/src/stores/tickets.spec.ts`** (new) — `vi.mock('../api/tickets')`, `createPinia()` per test as in `stores/auth.spec.ts`. Six tests: `load()` populates `items` and `meta` and clears `loading`; a rejection sets `error` from `errorMessage` and clears `items` and `meta`; `goToPage(2)` sets `page` and refetches; `setPerPage(50)` sets `perPage` **and resets `page` to 1**; an out-of-order response is discarded (resolve the second call first, assert the first's payload never lands); `create` still works — the existing `creating` flag is untouched.
23. **`frontend/src/views/TicketListView.spec.ts`** (new) — mock `../api/tickets`, mount with `createPinia()`, `flushPromises()`. Six tests: loading shows `tickets-loading` and no table; a populated response renders one `tickets-row` per ticket plus `tickets-count`; category/priority/status render as badges and `tickets-age` shows a relative string; an empty `data` renders `tickets-empty` and no table; **a rejected request renders `tickets-error` and NOT `tickets-empty`** (the mutual-exclusivity guard); `tickets-prev` is disabled on page 1 and `tickets-next` is disabled when `current_page === last_page`.

**No existing test is deleted, and no existing assertion is weakened.** Do not backfill the missing suites Stories 16, 18 and 19 never wrote (`tests/Feature/MasterData/`, `tests/Feature/Categories/`, `stores/masterData.spec.ts`); they belong to their own stories.

---

## Verification Steps

1. **Services up:** `docker compose ps` — `tm-mysql`, `tm-mysql-test`, `tm-mailpit` all healthy. `tm-mysql-test` must be on **3307**.
2. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` — clean.
3. **Backend tests:** from `backend/`, `composer test`. Expect **119 tests, 116 passing**. The only failures allowed are the two out-of-scope ones — `PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (TM-14) and `TicketReferenceTest::test_calling_outside_a_transaction_throws` (TM-21). **`RouteAuthorizationTest` must be fully green**; it was not when you started.
4. **The new class alone:** `php artisan test --filter=TicketIndexTest` — 16 passing.
5. **Prove the query-count test actually bites.** Temporarily delete `'assignee'` and `'creator'` from the `->with([...])` list in `index()` and re-run `--filter=test_query_count_is_constant_across_page_sizes`. It must **fail**, reporting a per-page-dependent count in the dozens. Restore the two relations. A test that passes with the eager loads removed is asserting nothing.
6. **Prove the tie-break test bites.** Temporarily remove `->orderByDesc('id')` and re-run `--filter=test_paging_is_stable_when_created_at_ties`. Note honestly: per the measurements this **may still pass**, because MySQL returned a stable order on fully-tied rows during planning. Restore the tiebreak regardless — the order is unspecified, and this step is to see the risk, not to prove a bug.
7. **Backend by hand:** `php artisan serve`, then with a token from `POST /api/v1/auth/login`:
   - `curl -H "Authorization: Bearer <token>" 'http://localhost:8000/api/v1/tickets'` → `200`, 15 rows, `meta.per_page` 15, **no `description` key on any row**.
   - `…/tickets?per_page=101` → `422` naming `per_page`.
   - `…/tickets?per_page=5` → `links.next` contains `per_page=5`.
   - `…/tickets?page=99` → `200` with `"data": []`.
8. **Frontend lints and typechecks:** from `frontend/`, `npm run lint` then `npm run typecheck` — both clean. `npx prettier --check` on the eleven files listed in task 14 — clean.
9. **Frontend tests:** from `frontend/`, `npm test`. Expect **42 tests across 10 files**.
10. **Frontend by hand:** `npm run dev`, sign in, click **Tickets**. Confirm all five: rows with coloured status/priority/category badges and an age like "3 days ago"; the counter matching the row count; Previous disabled on page 1 and Next disabled on the last page; the page-size select reloading at 50 and returning you to page 1. Then stop `php artisan serve` and reload — **`tickets-error` appears and `tickets-empty` does not.** Finally, with the API up but zero tickets, confirm the empty state renders alone.
11. **Regression:** navigate to `/tickets/new` and file a ticket. It must still succeed — task 9 grows `stores/tickets.ts` without touching `creating` or `create`.

---

## Done Criteria

- [ ] `GET /api/v1/tickets` returns a paginated collection whose rows carry `reference`, `subject`, `requester`, `category`, `priority`, `status`, `assignee` and `created_at`.
- [ ] The endpoint issues **8 queries** at `per_page=5` and **8** at `per_page=40`, proven by a test that fails when the eager loads are removed (Verification step 5).
- [ ] `per_page` is honoured, defaults to **15**, and `0` / `101` / `abc` each return `422`; `links.next` preserves it.
- [ ] `description` is absent from every index row and still present in the `POST /api/v1/tickets` response.
- [ ] Rows are newest-first, and paging through 30 tickets that share one `created_at` yields 30 distinct ids.
- [ ] Soft-deleted tickets are excluded from `data` and from `meta.total`.
- [ ] `TicketPolicy::viewAny()` exists; an agent gets `200`, an unauthenticated caller `401`.
- [ ] `RouteAuthorizationTest` is **fully green**, with `tickets.index` classified `staff` and `tickets.store` classified `staff-write`.
- [ ] `RequesterFactory` and `TicketFactory` exist; no `Priority`, `Status` or `Category` factory was added.
- [ ] `/tickets` renders the table with coloured status, priority and category badges and a relative age; a deactivated category still renders, dimmed.
- [ ] Loading, empty and error states are mutually exclusive — a failed request shows `tickets-error` and **not** `tickets-empty`.
- [ ] Previous/Next are disabled at the ends, and the page-size select resets to page 1.
- [ ] `docs/api-contract.md` documents both `GET` and `POST /api/v1/tickets`, and no longer defers `TicketPolicy` to future work.
- [ ] Backend **119 tests / 116 passing**; frontend **42 tests across 10 files**; `pint --test`, `npm run lint` and `npm run typecheck` clean; the eleven files this story touched pass `prettier --check`.
- [ ] No new composer or npm dependency; no migration, seeder or `docs/erd.md` change.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 20 (TM-24, filter and sort the ticket queue).**
