# Story 16 — Cache master data in a Pinia store (Story: TM-19)

## Prerequisites

- **Story 13 (TM-16) is implemented.** Verified 2026-08-26: `backend/app/Models/Category.php`, `Priority.php`, `Status.php` exist; `backend/database/seeders/` holds `CategorySeeder` (6 rows), `PrioritySeeder` (4 rows), `StatusSeeder` (7 rows); the three migrations are applied.

- **Story 14 (TM-17) sources are implemented.** `backend/app/Http/Controllers/Api/V1/CategoryController.php` serves five routes, and the SPA has `api/categories.ts`, `stores/categories.ts`, `components/CategoryBadge.vue`, `components/CategoryFormDialog.vue`, `views/AdminCategoriesView.vue`. **Its tests and two of its doc edits were never written** — see the note at the end of this section.

- **Two of the three lists this story caches have no API to call.** This is the blocker, and it is why a story labelled `frontend` opens with five backend tasks. Verified 2026-08-26:

  ```
  grep -rn "priorit\|statuses" backend/routes/ backend/app/Http/ docs/api-contract.md   → no matches
  php artisan route:list --path=api/v1                                                  → 10 routes, none for priorities or statuses
  ```

  `GET /api/v1/categories` exists (TM-17). `GET /api/v1/priorities` and `GET /api/v1/statuses` do not, and **no story in `tools/jira/backlog.json` creates them** — epic E3 is four stories (`E3-S1` migrations, `E3-S2` category CRUD, `E3-S3` the delete guard, `E3-S4` this one), and none of the other eight epics mentions a priorities or statuses endpoint. **TM-19 must ship them**, or acceptance criterion 1 is unreachable and **TM-22** (create a ticket) inherits the same wall.

- **Story 15 (TM-18) is independent of this one, in both directions.** It changes `CategoryController::destroy()`, `stores/categories.ts`'s `remove()` signature and `api/categories.ts`'s `deleteCategory()`; this story touches none of those. They can land in either order. If TM-18 lands first, task 10's mutation hook must also fire after its `remove(id, reassignTo?)` — the plan says where.

- **Docker services running:** repo root — `docker compose up -d`; all three containers `healthy`. The backend suite talks to `tm-mysql-test` on **3307** (`backend/phpunit.xml:36–42`).

- **Measured baselines, 2026-08-26.** Backend: `php artisan test` → **93 tests, 92 passing, 274 assertions**; the one red test is `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (expects `422`, gets `429`) — **TM-14's defect, out of scope, do not fix it and do not let it mask a new failure.** Frontend: `npm test` → **24 tests across 6 files**.

- **TM-17 landed sources without tests or docs, and this story does not backfill them.** There is no `backend/tests/Feature/Categories/` directory, and no `.spec.ts` for `api/categories.ts`, `stores/categories.ts`, `AdminCategoriesView.vue`, `CategoryFormDialog.vue` or `CategoryBadge.vue`. `docs/api-contract.md` still has no `### /api/v1/categories` section, and its Authorization paragraph (**27–28**) still reads "Category and ticket policies arrive with their models in TM-17 and TM-22" — which is now false. TM-17 owns all of that. **The one exception is that sentence**: task 5 rewrites it, because this story edits the same paragraph and leaving a statement that is provably wrong next to a new one is worse than the small overlap. Everything else stays TM-17's.

---

## Story Goal

One `masterData` store is the only thing in the SPA that knows what categories, priorities and statuses exist.

1. Two new read-only endpoints, `GET /api/v1/priorities` and `GET /api/v1/statuses`, open to any active staff member.
2. A `masterData` Pinia store that fetches all three lists **once**, on the first authenticated navigation, and never again unless something changes them.
3. Lookup helpers that turn an id into a name and a colour — in the exact prop shape `CategoryBadge.vue` already takes — with a graceful answer for an id that is not in the cache.
4. The cache is cleared on sign-out and refreshed when the admin screens change a category, both without a page reload.
5. A test that fails if any future component fetches master data behind the store's back.

**Not in scope:** admin CRUD for priorities or statuses (**no story asks for it**; TM-16 recorded that priorities and statuses are code-owned master data); badge components for priorities and statuses (**TM-24** and **TM-26** own the screens that would render them — see the note in Product rules); the new-ticket dropdown (**TM-22**); replacing `stores/categories.ts` (**it stays**, as TM-17's overview recorded); backfilling TM-17's tests; the `PasswordThrottleTest` failure (**TM-14**).

---

## Product rules (from story)

### Current behaviour

One master-data list is reachable and two are not.

| List | Endpoint today | SPA module today |
|---|---|---|
| Categories | `GET /api/v1/categories` (TM-17) | `api/categories.ts`, consumed by the admin CRUD store `stores/categories.ts` |
| Priorities | **none** | **none** |
| Statuses | **none** | **none** |

`stores/categories.ts` is an **admin CRUD store**: it holds a `status` filter (`'active' | 'inactive' | ''`), reloads on every mutation, and exists to serve `AdminCategoriesView`. It is not a cache and must not become one.

### New behaviour

`stores/masterData.ts` is added alongside it. The two have different jobs and both stay:

| | `stores/categories.ts` (TM-17) | `stores/masterData.ts` (this story) |
|---|---|---|
| Scope | The admin categories screen | The whole app |
| Contents | Categories only, filtered | All three lists, unfiltered |
| Loads when | `AdminCategoriesView` mounts, and on every filter change | First authenticated navigation, once |
| Writes | Yes — create, update, delete | **Never.** Read-only by construction |

### "First authenticated page load" means the router guard

`authGuard` (`frontend/src/router/guards.ts:27–37`) is the only place that knows a navigation is happening *and* that the user is authenticated — it already awaits `auth.hydrate()` at **31** before deciding. The prefetch goes at the end of that function, after the role check, immediately before `return true`.

**It is fired, not awaited.** Awaiting three requests before the first route renders is the opposite of "render instantly". Components that genuinely need the data awaiting `masterData.ensureLoaded()` themselves is the contract; nothing in the app needs it yet.

**Consequence: `ensureLoaded()` must never reject.** A rejected fire-and-forget promise is an unhandled rejection in the browser console and a failed test run in Vitest. `refresh()` uses `Promise.allSettled` and records failures in `error`; nothing propagates out.

### The cache must die with the session

Two accounts on one browser must not see each other's master data, and a deactivated agent's cache must not survive their forced sign-out. `useAuthStore().clear()` (`frontend/src/stores/auth.ts:46–51`) is the **only** complete choke point: `logout()` calls it at **42**, and `createUnauthorizedHandler` calls it at `guards.ts:42` when the API answers `401`. Resetting anywhere else leaves one of those two paths leaking.

`stores/auth.ts` importing `stores/masterData.ts` creates no cycle — `masterData` imports only from `api/`, which imports only `api/client.ts`.

### The refresh flows admin-store → masterData, not the other way round

TM-17's overview recorded this as "TM-19 wraps Story 14's store … satisfied by subscribing to the admin store". **This plan inverts that direction, deliberately.** Subscribing from `masterData` means calling `useCategoriesStore()` inside `masterData`'s setup, which instantiates the admin CRUD store for every agent on every page, including agents who can never open that screen — and it points the app-wide store at an admin-only one. The dependency runs the other way here: `stores/categories.ts` calls `masterData.refreshCategories()` after each successful mutation. Specific depends on general; the general store stays free of admin concerns.

That costs one extra `GET /categories` per admin mutation, because the admin store's own `load()` is filtered by `status` and cannot feed the unfiltered cache. An admin clicking Save is not a hot path, and the alternative — teaching the cache which filter the admin screen happens to be showing — is worse.

### The lookup helpers return `CategoryBadge`'s prop type, exactly

`CategoryBadge.vue:5–7` declares:

```ts
const props = defineProps<{
  category: Pick<Category, 'name' | 'color' | 'is_active'>
}>()
```

So `masterData.categoryBadge(id)` returns `{ name, color, is_active }` and `<CategoryBadge :category="masterData.categoryBadge(t.category_id)" />` type-checks with no adapter at the call site. It **never returns null**: an id that is not in the cache yields `{ name: 'Unknown', color: '#6B7280', is_active: false }`, which renders as a muted grey badge rather than crashing a ticket row. `#6B7280` is the same grey `categories.color` and `statuses.color` default to in TM-16's migrations.

`priorityBadge()` and `statusBadge()` return the same shape with `is_active: true`, because neither table has that column and neither can be deactivated.

Callers that need the whole record — `level` for sorting, `bucket` or `is_terminal` for workflow — use `priorityById(id)` / `statusById(id)`, which return `undefined` when absent.

### Criterion 2 is a contract, not a refactor — so it gets a test

"Every dropdown and badge in the app reads from this store, never from its own request." Audited on 2026-08-26: **there is nothing to convert.** The only `<select>` in the SPA is `AdminCategoriesView.vue:30–33`, whose three options are literals; `CategoryBadge.vue` takes a prop and issues no request; `stores/users.ts` fetches users, not master data. The first real dropdown is **TM-22**'s.

So this story satisfies criterion 2 the only way it can be satisfied today: by making the rule enforceable. Task 11 adds a spec that reads the source tree and fails if any file outside a two-entry allowlist imports `listCategories`, `listPriorities` or `listStatuses`. That is the same shape as `RouteAuthorizationTest`'s `ACCESS` manifest on the backend, and it is what will actually stop TM-22 and TM-24 from re-fetching.

**Priority and status badge components are not built here.** There is no screen to render them on until **TM-24** (the queue) and **TM-26** (ticket detail); building two components with no call site would be untested decoration. `CategoryBadge.vue` is left byte-identical.

---

## Context — Read These Files First

1. `backend/routes/api.php` — whole file, 45 lines. The `['auth:sanctum', 'active']` group opens at **29**; the category routes are **34–38**; the `admin` sub-group is **39–44**. The two new routes go between **38** and **39**. **Never write `/api/v1` into a path** — `bootstrap/app.php:19` sets `apiPrefix`.
2. `backend/app/Http/Controllers/Api/V1/HealthController.php` — **lines 19–30**. The repo's single-action controller idiom: `__invoke()`, no `$this->authorize()` where there is nothing to authorize.
3. `backend/app/Http/Resources/V1/UserResource.php` — whole file, 21 lines. Note **line 16**, `$this->role->value`: a backed enum is unwrapped in the resource, which is what `StatusResource` must do with `bucket`.
4. `backend/app/Models/Priority.php` — 24 lines. `#[Fillable]` (**10**), `#[Hidden(['is_default_unique'])]` (**11**), and `scopeOrdered()` (**20–23**), which orders by `level`.
5. `backend/app/Models/Status.php` — 25 lines. `#[Hidden]` (**12**), the `StatusBucket` cast (**17**), and `scopeOrdered()` (**21–24**), which orders by `sort_order` then `name`.
6. `backend/app/Enums/StatusBucket.php` — 16 lines. `open | pending | done`. The TypeScript union in task 6 must match `values()` exactly.
7. `backend/database/seeders/PrioritySeeder.php:11–16` and `StatusSeeder.php:12–20` — the 4 priorities and 7 statuses the tests assert against, with their levels, buckets, colours and sort orders. **These are the fixtures; do not invent different ones.**
8. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **line 16** (`ACCESS`), **52–56** (`test_agent_reaches_staff_routes`, which hard-codes `/api/v1/categories` and must be widened), **81–88** (`test_unauthenticated_non_public_refused`), **119–122** (`routesFor()` and its `$categoryId` parameter). `test_every_api_route_is_classified` (**18–25**) turns red the moment a route is registered without an `ACCESS` entry — **this is the first thing that will fail.**
9. `frontend/src/stores/auth.ts` — **line 11** and **24–35**: the single-flight `hydration` idiom `masterData.ensureLoaded()` copies. **46–51**: `clear()`, the reset hook.
10. `frontend/src/router/guards.ts` — **27–37**, `authGuard`. The prefetch goes immediately before `return true` at **36**.
11. `frontend/src/router/guards.spec.ts` — **11–16**, the `vi.mock('../api/auth', …)` partial-mock idiom. **This file will break** when the guard starts touching master data unless its mock list grows; task 8 says how.
12. `frontend/src/stores/health.ts` — whole file, 28 lines. The plainest `defineStore` setup-store in the repo.
13. `frontend/src/api/categories.ts` — **26–33**, `listCategories`. `api/priorities.ts` and `api/statuses.ts` copy this shape, including the `{ data: T[] }` unwrap.
14. `frontend/src/api/auth.spec.ts` — **9–30**. The `client.defaults.adapter` capture idiom the new api specs use. Note there is **no `@pinia/testing`** in `frontend/package.json`; store specs use `createPinia()` plus `vi.mock` on the api module, as `frontend/src/views/HealthView.spec.ts:9,19–20` does.
15. `frontend/src/components/CategoryBadge.vue` — **5–7**. The prop type the store's badge helpers must match. **This file is not edited.**
16. `frontend/src/stores/categories.ts` — **47–58**, `create()`, `update()` and `remove()`. Task 10 adds one line's worth of behaviour to all three.
17. `docs/api-contract.md` — **line 16** (bounded master-data lists return `data` only — already written, and the new endpoints fall under it), **27–28** (the stale Authorization sentence), **32–47** (the endpoints table).

---

## Backend Tasks

### 1 — The two resources

**Create file: `backend/app/Http/Resources/V1/PriorityResource.php`**

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriorityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'level' => $this->level,
            'color' => $this->color,
            'is_default' => $this->is_default,
        ];
    }
}
```

**Create file: `backend/app/Http/Resources/V1/StatusResource.php`**

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StatusResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            // Backed enum, unwrapped exactly as UserResource:16 does with role.
            'bucket' => $this->bucket->value,
            'color' => $this->color,
            'is_default' => $this->is_default,
            'is_terminal' => $this->is_terminal,
            'sort_order' => $this->sort_order,
        ];
    }
}
```

Both build the array explicitly, so `is_default_unique` cannot leak. `#[Hidden]` on the models already covers it; the explicit list is the second guard, and TM-16's overview is emphatic that writing to that column raises `ERROR 3105`.

`created_at` / `updated_at` are **omitted** on purpose. `CategoryResource` exposes them because the admin screen edits categories; nothing edits a priority or a status, and a cache does not need them.

### 2 — The two controllers

**Create file: `backend/app/Http/Controllers/Api/V1/PriorityController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\PriorityResource;
use App\Models\Priority;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Every priority, lowest level first. Read-only for every active staff
 * member: no story gives priorities an admin UI, so the seeder is the source
 * of truth and there is nothing here to authorize beyond being signed in.
 */
class PriorityController extends Controller
{
    public function __invoke(): AnonymousResourceCollection
    {
        return PriorityResource::collection(Priority::query()->ordered()->get());
    }
}
```

**Create file: `backend/app/Http/Controllers/Api/V1/StatusController.php`** — identical shape with `StatusResource`, `Status`, and the same doc comment adjusted.

Three deliberate omissions:

- **No policy, and no `$this->authorize()`.** `CategoryPolicy` exists because the same model has admin-only writes on the same path; these two have no writes at all. A `viewAny()` that returns `true` and nothing else would be noise, and `RouteAuthorizationTest` classifies both routes as `staff` either way.
- **No filters.** `CategoryController::index()` accepts `?status=active|inactive` because categories can be deactivated. Neither of these tables has an `is_active` column.
- **No pagination.** Four rows and seven rows. `docs/api-contract.md:16` already carves out bounded master-data lists.

### 3 — The routes

**File: `backend/routes/api.php`**

Add two imports alongside the existing controllers, alphabetically — `PriorityController` after `HealthController` (**9**), `StatusController` after it:

```php
use App\Http\Controllers\Api\V1\PriorityController;
use App\Http\Controllers\Api\V1\StatusController;
```

Add the routes **inside** the `['auth:sanctum', 'active']` group, after the category routes (**38**) and **above** the `admin` sub-group (**39**):

```php
    // Read-only master data. No admin variant exists: priorities and statuses
    // are code-owned (TM-16's seeders), so there is nothing to write.
    Route::get('/priorities', PriorityController::class)->name('priorities.index');
    Route::get('/statuses', StatusController::class)->name('statuses.index');
```

### 4 — Extend the route-authorization manifest

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add two entries to `ACCESS` (**16**) at the `staff` level, next to `categories.index` and `categories.show`:

```php
'priorities.index' => 'staff', 'statuses.index' => 'staff',
```

Then **widen `test_agent_reaches_staff_routes` (52–56)**, which today hard-codes one URL and would therefore pass without ever touching the new routes:

```php
public function test_agent_reaches_staff_routes(): void
{
    $token = $this->tokenFor(User::factory()->agent()->create());
    $category = Category::query()->create(['name' => 'Test', 'slug' => 'test']);
    foreach ($this->routesFor('staff', $category->id) as $route) {
        $this->withToken($token)->json($route['method'], $route['uri'])->assertOk();
    }
}
```

The real category is needed for `categories.show`; `routesFor()` already takes `$categoryId` (**119**) and defaults it to `999999`, so passing it is a two-character change at the call site.

Extend `test_unauthenticated_non_public_refused` (**81–88**) with two lines:

```php
$this->getJson('/api/v1/priorities')->assertUnauthorized();
$this->getJson('/api/v1/statuses')->assertUnauthorized();
```

**Do not touch** `test_agent_refused_by_admin_routes`, `test_agent_refused_by_policy_admin_routes`, `test_policy_admin_routes_have_no_admin_middleware`, `test_admin_routes_have_three_middlewares` or `test_unknown_id_agent_forbidden_admin_not_found` — none of them concerns `staff` routes, and all must keep passing verbatim.

### 5 — The contract

**File: `docs/api-contract.md`**

Add two rows to the endpoints table, after the five category rows (**39–43**) and before the `admin/users` block:

| `GET` | `/api/v1/priorities` | Every priority, lowest `level` first. Unpaginated, read-only. | bearer | TM-19 |
| `GET` | `/api/v1/statuses` | Every status, by `sort_order`. Unpaginated, read-only. | bearer | TM-19 |

Replace the stale sentence at **27–28** — "Category and ticket policies arrive with their models in TM-17 and TM-22" — with what is now true:

> `CategoryPolicy` defines `viewAny`, `view`, `create`, `update`, and `delete`. Any active staff member may read categories; only admins may write. Because those routes carry no `admin` middleware, route-model binding resolves first, so an unknown category id returns `404` to agents and admins alike. `/api/v1/priorities` and `/api/v1/statuses` have **no policy**: they are read-only and every active staff member may call them, so `auth:sanctum` and `active` are the whole authorization story. A ticket policy arrives with its model in TM-22.

Add two short sections after the categories rows, each listing the exact field set from task 1, stating the ordering (`level` ascending; `sort_order` then `name`), that the response is a `data`-wrapped array with no `links` or `meta`, that `is_default` marks exactly one row per table (TM-16's unique index over a generated column guarantees it), that `bucket` is one of `open`, `pending`, `done`, and that both return `401` without a bearer token.

**Do not touch `docs/erd.md`** — this story adds no column and no table.

---

## Frontend Tasks

### 6 — The two API modules

**Create file: `frontend/src/api/priorities.ts`**

```ts
import client from './client'
export interface Priority {
  id: number
  name: string
  slug: string
  level: number
  color: string
  is_default: boolean
}
export async function listPriorities(): Promise<Priority[]> {
  const { data } = await client.get<{ data: Priority[] }>('/priorities')
  return data.data
}
```

**Create file: `frontend/src/api/statuses.ts`**

```ts
import client from './client'
export type StatusBucket = 'open' | 'pending' | 'done'
export interface Status {
  id: number
  name: string
  slug: string
  bucket: StatusBucket
  color: string
  is_default: boolean
  is_terminal: boolean
  sort_order: number
}
export async function listStatuses(): Promise<Status[]> {
  const { data } = await client.get<{ data: Status[] }>('/statuses')
  return data.data
}
```

The `StatusBucket` union must stay identical to `backend/app/Enums/StatusBucket.php:7–9`. Neither module takes a query object — unlike `listCategories` (`api/categories.ts:26–33`), these endpoints accept no parameters.

### 7 — The store

**Create file: `frontend/src/stores/masterData.ts`**

```ts
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { errorMessage } from '../api/errors'
import { listCategories } from '../api/categories'
import { listPriorities } from '../api/priorities'
import { listStatuses } from '../api/statuses'
import type { Category } from '../api/categories'
import type { Priority } from '../api/priorities'
import type { Status } from '../api/statuses'

/** Exactly CategoryBadge.vue's prop type, so a lookup drops straight in. */
export interface BadgeLike {
  name: string
  color: string
  is_active: boolean
}

// The same grey categories.color and statuses.color default to in TM-16's
// migrations, so an id we cannot resolve renders muted instead of crashing.
const UNKNOWN: BadgeLike = {
  name: 'Unknown',
  color: '#6B7280',
  is_active: false,
}

export const useMasterDataStore = defineStore('masterData', () => {
  const categories = ref<Category[]>([])
  const priorities = ref<Priority[]>([])
  const statuses = ref<Status[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref<string | null>(null)
  let inFlight: Promise<void> | null = null

  const activeCategories = computed(() =>
    categories.value.filter((c) => c.is_active),
  )
  const categoryIndex = computed(
    () => new Map(categories.value.map((c) => [c.id, c])),
  )
  const priorityIndex = computed(
    () => new Map(priorities.value.map((p) => [p.id, p])),
  )
  const statusIndex = computed(
    () => new Map(statuses.value.map((s) => [s.id, s])),
  )
  const defaultPriority = computed(
    () => priorities.value.find((p) => p.is_default) ?? null,
  )
  const defaultStatus = computed(
    () => statuses.value.find((s) => s.is_default) ?? null,
  )

  function categoryById(id: number | null | undefined): Category | undefined {
    return id == null ? undefined : categoryIndex.value.get(id)
  }
  function priorityById(id: number | null | undefined): Priority | undefined {
    return id == null ? undefined : priorityIndex.value.get(id)
  }
  function statusById(id: number | null | undefined): Status | undefined {
    return id == null ? undefined : statusIndex.value.get(id)
  }

  function categoryBadge(id: number | null | undefined): BadgeLike {
    const found = categoryById(id)
    return found
      ? { name: found.name, color: found.color, is_active: found.is_active }
      : UNKNOWN
  }
  // Priorities and statuses have no is_active column and cannot be
  // deactivated, so the badge is never muted for them.
  function priorityBadge(id: number | null | undefined): BadgeLike {
    const found = priorityById(id)
    return found
      ? { name: found.name, color: found.color, is_active: true }
      : UNKNOWN
  }
  function statusBadge(id: number | null | undefined): BadgeLike {
    const found = statusById(id)
    return found
      ? { name: found.name, color: found.color, is_active: true }
      : UNKNOWN
  }

  /**
   * Fetch once. Concurrent callers share one in-flight promise, the same way
   * useAuthStore().hydrate() does. Never rejects — the router fires this
   * without awaiting it, and a rejected floating promise is an unhandled
   * rejection in the browser and a failed run in Vitest.
   */
  async function ensureLoaded(): Promise<void> {
    if (loaded.value) return
    await (inFlight ??= refresh().finally(() => {
      inFlight = null
    }))
  }

  async function refresh(): Promise<void> {
    loading.value = true
    error.value = null
    // allSettled, not all: one dead endpoint must not blank the other two.
    const results = await Promise.allSettled([
      listCategories(),
      listPriorities(),
      listStatuses(),
    ])
    if (results[0].status === 'fulfilled') categories.value = results[0].value
    if (results[1].status === 'fulfilled') priorities.value = results[1].value
    if (results[2].status === 'fulfilled') statuses.value = results[2].value
    const failed = results.find((r) => r.status === 'rejected')
    // loaded stays false on any failure, so the next navigation retries.
    if (failed) error.value = errorMessage(failed.reason)
    else loaded.value = true
    loading.value = false
  }

  /** Called by the admin categories store after every successful mutation. */
  async function refreshCategories(): Promise<void> {
    try {
      categories.value = await listCategories()
    } catch (caught) {
      error.value = errorMessage(caught)
    }
  }

  function reset(): void {
    categories.value = []
    priorities.value = []
    statuses.value = []
    loading.value = false
    loaded.value = false
    error.value = null
    inFlight = null
  }

  return {
    categories,
    priorities,
    statuses,
    loading,
    loaded,
    error,
    activeCategories,
    defaultPriority,
    defaultStatus,
    categoryById,
    priorityById,
    statusById,
    categoryBadge,
    priorityBadge,
    statusBadge,
    ensureLoaded,
    refresh,
    refreshCategories,
    reset,
  }
})
```

Two things that look optional and are not:

- **`loaded` flips only when all three succeed.** If it flipped on partial success, a `/priorities` outage during the first navigation would leave the app permanently priority-less until a reload.
- **`inFlight` is a closure variable, not a `ref`.** It is machinery, not state; `auth.ts:11` makes the same choice with `hydration`, and putting a promise in a `ref` makes it reactive for no reason.

**`activeCategories` here supersedes the one on `stores/categories.ts`.** TM-17's overview told TM-22 to read the admin store's copy; **TM-22 must read this one instead** — the admin store's list is filtered by whatever the admin screen last selected, and it is empty on every page that is not `/admin/categories`.

### 8 — Prefetch on the first authenticated navigation

**File: `frontend/src/router/guards.ts`**

Add the import next to the auth store import (**2**):

```ts
import { useMasterDataStore } from '../stores/masterData'
```

Then, in `authGuard`, replace the bare `return true` at **36** with:

```ts
  // Fired, not awaited: navigation must not wait on three requests, and
  // ensureLoaded() is contractually incapable of rejecting.
  void useMasterDataStore().ensureLoaded()
  return true
```

It goes **after** the `to.meta.role` check at **35**, so an agent bounced to `/forbidden` still gets the prefetch on the redirect's own guard pass, and an unauthenticated visitor never triggers a request.

**File: `frontend/src/router/guards.spec.ts`** — this file breaks without an edit. `authGuard` now reaches the network on the authenticated paths, and its only mock (**11–16**) covers `../api/auth`. Add three more:

```ts
vi.mock('../api/categories', () => ({ listCategories: vi.fn(() => Promise.resolve([])) }))
vi.mock('../api/priorities', () => ({ listPriorities: vi.fn(() => Promise.resolve([])) }))
vi.mock('../api/statuses', () => ({ listStatuses: vi.fn(() => Promise.resolve([])) }))
```

`../api/categories` needs a **full** factory rather than the partial `loadOriginal()` spread used for auth, because the guard only ever touches `listCategories` from it.

### 9 — Clear the cache with the session

**File: `frontend/src/stores/auth.ts`**

Add the import and one line to `clear()` (**46–51**):

```ts
  function clear(): void {
    token.value = null
    user.value = null
    hydration = null
    clearToken()
    // The only path both logout() and the 401 handler pass through, so this
    // is the only place a reset is provably complete.
    useMasterDataStore().reset()
  }
```

No cycle: `masterData` imports from `api/` only.

### 10 — Refresh the cache when the admin screen changes a category

**File: `frontend/src/stores/categories.ts`**

Add the import, then a private helper, and route all three mutations through it. `create()` (**47–50**), `update()` (**51–54**) and `remove()` (**55–58**) each end in `await load()` today; replace that call in all three:

```ts
  async function reload() {
    await load()
    // The admin list is filtered by `status`; the app-wide cache is not, so
    // it needs its own unfiltered request. One extra GET per admin mutation.
    await useMasterDataStore().refreshCategories()
  }
```

**If Story 15 (TM-18) has already landed**, `remove()` is `remove(id: number, reassignTo?: number)` — the same substitution applies, unchanged. If TM-18 lands afterwards, its plan's task 11 must keep calling `reload()` rather than reverting to `load()`.

**Do not** change `load()`, `applyFilters()`, `setActive()` or `saveSortOrder()`. `setActive` and `saveSortOrder` delegate to `update()` (**59–64**) and are covered transitively.

### 11 — Make criterion 2 enforceable

**Create file: `frontend/src/stores/masterData.contract.spec.ts`**

A manifest test, in the spirit of `RouteAuthorizationTest`'s `ACCESS`. It walks `src/` with `node:fs` (available in Vitest's jsdom environment) and fails if any file outside the allowlist imports a master-data list function.

```ts
const ALLOWED = new Set([
  'src/stores/masterData.ts', // the cache itself
  'src/stores/categories.ts', // TM-17's admin CRUD store: filtered reads and writes
])
```

Match on the import specifiers `../api/priorities`, `../api/statuses`, `./priorities`, `./statuses` and on the identifiers `listCategories`, `listPriorities`, `listStatuses`, scanning every `.ts` and `.vue` under `src/` except `*.spec.ts`. On failure the message must name the offending file and tell the reader to use `useMasterDataStore()` instead — a manifest test that only says "expected 3 to be 2" gets deleted by the next person who trips it.

---

## Edge Cases & Failure Modes

- **Two components mount at once and both call `ensureLoaded()`.** One request set. `inFlight` holds the shared promise (task 7), the same single-flight pattern as `auth.ts:24–35`. Without it, a page with three master-data consumers issues nine requests.

- **The prefetch is still running when navigation completes.** Expected, and why it is fired rather than awaited. Consumers await `ensureLoaded()` or read `loading`. `masterData.categories` is `[]` until it resolves, so a `v-for` renders nothing rather than throwing.

- **`/priorities` is down but the other two are fine.** `Promise.allSettled` (task 7) keeps the two successful lists, sets `error` from the failure, and leaves `loaded` false so the next navigation retries. `Promise.all` would have discarded all three.

- **Every request fails.** `error` is set, `loaded` stays false, `ensureLoaded()` **resolves** — it never rejects, so the `void` call in `guards.ts` produces no unhandled rejection and Vitest does not fail the run on it.

- **The user signs out and a different account signs in on the same browser.** `auth.clear()` calls `reset()` (task 9), so `loaded` returns to false and the next authenticated navigation refetches. Without it the second user sees the first user's categories, including any the first user's admin session created.

- **The API answers `401` mid-session** (token revoked, or the account deactivated by `EnsureUserIsActive`). `client.ts:25–37` calls the handler, `guards.ts:42` calls `auth.clear()`, and the cache is reset by the same one line. This is the path that would be missed if the reset lived in `logout()` instead.

- **An admin renames or deactivates a category on `/admin/categories`.** `reload()` (task 10) refreshes both the filtered admin list and the unfiltered cache, so a badge elsewhere in the app shows the new name without a page reload — criterion 4.

- **An admin deletes a category that a ticket still points at.** The cache loses the row, so `categoryById(id)` returns `undefined` and `categoryBadge(id)` returns the muted "Unknown" badge instead of throwing. After Story 15 (TM-18) this cannot happen without reassignment, but the fallback is what makes the two stories independent.

- **A ticket carries a **deactivated** category.** `categoryBadge()` returns it with `is_active: false`, and `CategoryBadge.vue:15,24–26` renders it at 55% opacity. This is TM-17 criterion 5's other half, and it is why the cache holds inactive categories and exposes `activeCategories` as a separate computed rather than filtering on load.

- **`categoryBadge(null)` or `categoryBadge(undefined)`.** Returns `UNKNOWN`. The `id == null` guard in each `*ById` (task 7) uses loose equality on purpose so both are covered by one check; `0` is a falsy id that must still be looked up, which `??` or `!id` would have swallowed.

- **`guards.spec.ts` runs without the new mocks.** Real axios requests inside jsdom, an unhandled rejection, and an intermittently red suite. Task 8's three `vi.mock` calls are mandatory, not defensive.

- **Someone adds a dropdown that calls `listCategories()` directly.** `masterData.contract.spec.ts` (task 11) fails by name. This is the only mechanical enforcement of criterion 2 that survives the stories that have not been written yet.

- **`test_every_api_route_is_classified` after task 3, before task 4.** Red, naming `api/v1/priorities`. Registering a route without an `ACCESS` entry is designed to fail; do the two tasks together.

- **An agent calls `/api/v1/priorities`.** `200`. Deliberate — agents pick a priority when creating a ticket (TM-22). There is nothing sensitive in the four rows.

- **An unauthenticated caller.** `401` from `auth:sanctum`, asserted by the two lines added to `test_unauthenticated_non_public_refused` (task 4).

---

## Test Plan

Backend from `backend/`, frontend from `frontend/`. Backend feature tests use `RefreshDatabase` against `tm-mysql-test` on **3307** — never SQLite. Fixtures are built with `Model::create([...])` or by running the TM-16 seeders; there is no `PriorityFactory` or `StatusFactory` (TM-59 owns those).

### Backend

1. **Create `backend/tests/Feature/MasterData/PriorityIndexTest.php`** — 6 tests. Seed with `PrioritySeeder`.
   - `test_agent_lists_all_priorities` — agent token, `200`, `assertJsonCount(4, 'data')`.
   - `test_priorities_are_ordered_by_level` — the `data` slugs are `low, medium, high, urgent` in that order.
   - `test_priority_shape` — each row has exactly `id, name, slug, level, color, is_default` via `assertJsonStructure`, and `assertJsonMissingPath('data.0.is_default_unique')`.
   - `test_exactly_one_priority_is_default` — one row with `is_default` true, and its slug is `medium` (`PrioritySeeder:13`).
   - `test_admin_also_lists_priorities` — `200`, not `403`.
   - `test_unauthenticated_is_rejected` — `401`.
2. **Create `backend/tests/Feature/MasterData/StatusIndexTest.php`** — 6 tests, same shape with `StatusSeeder`.
   - `assertJsonCount(7, 'data')`; order is `new, open, in-progress, pending, resolved, closed, reopened` (`StatusSeeder:13–19`); shape is exactly `id, name, slug, bucket, color, is_default, is_terminal, sort_order` with no `is_default_unique`; `bucket` is one of `open|pending|done` and `resolved`'s is `done`; `is_terminal` is true for `resolved` and `closed` and false for the other five; the default is `new`; unauthenticated is `401`.
3. **Edit `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`** per task 4. No new test methods — two `ACCESS` entries, a widened `test_agent_reaches_staff_routes`, and two lines in `test_unauthenticated_non_public_refused`. Assertion count rises; test count does not.
4. **Do not touch** `tests/Feature/Auth/PasswordThrottleTest.php`.

### Frontend

5. **Create `frontend/src/api/priorities.spec.ts`** and **`frontend/src/api/statuses.spec.ts`** — 2 tests each, using the `client.defaults.adapter` capture from `api/auth.spec.ts:9–30`: the request is a `GET` to `/priorities` (resp. `/statuses`) with no params, and the `{ data: [...] }` envelope is unwrapped to a bare array.
6. **Create `frontend/src/stores/masterData.spec.ts`** — the bulk of the story. `vi.mock` all three api modules; `setActivePinia(createPinia())` in `beforeEach`.
   - `loads all three lists on ensureLoaded`.
   - `fetches once for concurrent callers` — two un-awaited `ensureLoaded()` calls, then `flushPromises()`; each list function called exactly once.
   - `does not refetch once loaded` — `ensureLoaded()` twice sequentially; still one call each.
   - `refetches after a failure` — first attempt rejects, `loaded` is false, second attempt succeeds.
   - `keeps successful lists when one request fails` — `listPriorities` rejects; `categories` and `statuses` are populated, `priorities` is `[]`, `error` is set, `loaded` is false.
   - `never rejects` — all three reject; `await expect(store.ensureLoaded()).resolves.toBeUndefined()`.
   - `resolves an id to a name and colour` — `categoryBadge`, `priorityBadge`, `statusBadge` against seeded-shaped fixtures.
   - `returns the unknown badge for a missing id` — `{ name: 'Unknown', color: '#6B7280', is_active: false }` for an absent id, for `null` and for `undefined`.
   - `resolves id 0 rather than treating it as absent`.
   - `mutes a deactivated category` — `categoryBadge` returns `is_active: false` for one.
   - `exposes activeCategories` — inactive rows excluded from `activeCategories`, present in `categories`.
   - `exposes the default priority and status`.
   - `byId helpers return undefined for a missing id`.
   - `reset clears every list and the loaded flag`.
   - `refreshCategories replaces only the category list`.
7. **Create `frontend/src/stores/masterData.contract.spec.ts`** — 1 test, per task 11.
8. **Edit `frontend/src/router/guards.spec.ts`** — add the three mocks from task 8, then two tests:
   - `prefetches master data on an authenticated navigation` — after `authGuard` resolves `true` for `/`, each list function has been called once.
   - `does not prefetch for an unauthenticated navigation` — the guard returns the login redirect and no list function was called.
9. **Edit `frontend/src/stores/auth.spec.ts`** — 1 test: `clear() resets the master data cache`. Populate `masterData`, call `auth.clear()`, assert the lists are empty and `loaded` is false.
10. **Create `frontend/src/stores/categories.spec.ts`** — 3 tests, restricted to the mutation hook: `create`, `update` and `remove` each call `listCategories` twice (once for the filtered admin reload, once for the cache refresh) and leave `masterData.categories` holding the fresh rows. **The rest of this store is TM-17's untested surface and stays that way here.**

Expected totals: **backend 93 → 105 tests** (12 new; the one TM-14 failure still red), **frontend 6 → 12 spec files** and **24 → roughly 51 tests**.

---

## Verification Steps

1. **Services up:** repo root — `docker compose up -d && docker compose ps`; all three `healthy`.
2. **Backend routes:** `backend/` — `php artisan route:list --path=api/v1` prints **12** routes; `priorities.index` and `statuses.index` are present, both carrying `auth:sanctum` and `active` and **not** `admin`.
3. **Backend tests:** `backend/` — `composer test`. Expect **105 tests, 104 passing**, the single failure being `PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (`422` vs `429`). Any other failure is this story's.
4. **Backend formatting:** `backend/` — `./vendor/bin/pint --test` clean.
5. **Endpoints by hand:** `backend/` — `php artisan migrate:fresh --seed`, `php artisan serve`, then with an agent bearer token: `GET /api/v1/priorities` returns 4 rows ordered `low, medium, high, urgent` with no `is_default_unique`, and `GET /api/v1/statuses` returns 7 rows ordered `new … reopened`. Without a token both return `401`.
6. **Frontend typecheck:** `frontend/` — `npx vue-tsc -b` clean, then `npm run build` succeeds.
7. **Frontend tests:** `frontend/` — `npm test`. Expect **12 files** passing, including `masterData.contract.spec.ts`.
8. **The contract test actually bites:** `frontend/` — temporarily add `import { listPriorities } from '../api/priorities'` to `src/views/HealthView.vue`, run `npm test`, and confirm the contract spec fails **naming that file**. Revert.
9. **Frontend lint and format:** `frontend/` — `npm run lint` and `npm run format:check` clean.
10. **Prefetch happens once:** `frontend/` — `npm run dev`, sign in, open DevTools → Network filtered to `api/v1`. The first authenticated page load shows exactly one request each to `/categories`, `/priorities` and `/statuses`. Navigate to `/account/password` and back to `/` — **no further master-data requests**.
11. **Mutation refreshes the cache:** on `/admin/categories`, rename a category and Save. Network shows two `/categories` requests (the admin reload and the cache refresh) and no page reload.
12. **Sign-out clears it:** sign out, sign in as a different account, and confirm the three requests fire again on the first navigation.
13. **Docs:** `git diff docs/api-contract.md` shows the two endpoint rows, the two new sections, and the rewritten Authorization paragraph. `git diff docs/erd.md` is empty.

---

## Done Criteria

- [ ] `GET /api/v1/priorities` returns all 4 priorities ordered by `level`, and `GET /api/v1/statuses` returns all 7 statuses ordered by `sort_order` — both `data`-wrapped, unpaginated, read-only, and reachable by agents and admins alike.
- [ ] Neither response exposes `is_default_unique`; both are built from an explicit field list in a `JsonResource`.
- [ ] Both routes sit inside the `['auth:sanctum','active']` group, carry no `admin` middleware and no policy, return `401` unauthenticated, and are classified `staff` in `RouteAuthorizationTest`'s `ACCESS` manifest.
- [ ] `test_agent_reaches_staff_routes` loops `routesFor('staff', …)` instead of hard-coding one URL, so it actually exercises the two new routes.
- [ ] `stores/masterData.ts` loads all three lists on the first authenticated navigation, fired from `authGuard` without being awaited, and fetches **once** — concurrent callers share one in-flight promise and a second navigation issues no request.
- [ ] `ensureLoaded()` never rejects; a partial failure keeps the lists that succeeded, records `error`, and leaves `loaded` false so the next navigation retries.
- [ ] `categoryBadge`, `priorityBadge` and `statusBadge` return `{ name, color, is_active }` — `CategoryBadge.vue`'s exact prop type — and return the muted `Unknown` / `#6B7280` badge for an id that is absent, `null` or `undefined`, while still resolving id `0`.
- [ ] `categoryById`, `priorityById` and `statusById` return the full record or `undefined`; `activeCategories`, `defaultPriority` and `defaultStatus` are exposed.
- [ ] `useAuthStore().clear()` resets the cache, so both `logout()` and the `401` handler clear it and a second account on the same browser refetches.
- [ ] Creating, editing or deleting a category on `/admin/categories` refreshes the cache without a page reload, driven from `stores/categories.ts` — and `stores/categories.ts` still exists and still owns the admin screen.
- [ ] `masterData.contract.spec.ts` fails, naming the file, when any source outside `stores/masterData.ts` and `stores/categories.ts` imports `listCategories`, `listPriorities` or `listStatuses` — verified by adding a violation and removing it.
- [ ] `CategoryBadge.vue` is byte-identical, and no priority or status badge component was added.
- [ ] `composer test` is **105 tests, 104 passing**, the only failure being TM-14's `PasswordThrottleTest`; `npm test` is green across 12 files; `./vendor/bin/pint --test`, `npx vue-tsc -b`, `npm run lint` and `npm run format:check` are all clean.
- [ ] `docs/api-contract.md` documents both endpoints and no longer claims category and ticket policies are still to come; `docs/erd.md` is untouched.
- [ ] `frontend/package.json` has no new dependency.
- [ ] The overview records that **TM-22 and TM-24 read `masterData.activeCategories`, not the admin store's**, and that priority and status badge components belong to TM-24 and TM-26.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 17.**
