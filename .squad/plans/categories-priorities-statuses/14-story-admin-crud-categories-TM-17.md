# Story 14 — Admin CRUD for categories (Story: TM-17)

## Prerequisites

- **Story 13 (TM-16) must be _implemented_, not merely planned:** [`13-story-master-data-migrations-seeders-TM-16.md`](13-story-master-data-migrations-seeders-TM-16.md). This is a hard blocker — **nothing in this story compiles without it**. Verified on 2026-08-26: `backend/database/migrations/` holds four files and none is `create_categories_table`; `backend/app/Models/` holds only `User.php`. There is no `Category` model to bind a route to, no table to query, and no seeded row to edit.

  Confirm before starting: `php artisan db:table categories` prints the table, and `php artisan tinker --execute="echo App\Models\Category::count();"` prints `6`.
- **Stories 10 (TM-12) and 11 (TM-13) implemented** — [`../authentication-agent/10-story-admin-manages-agent-accounts-TM-12.md`](../authentication-agent/10-story-admin-manages-agent-accounts-TM-12.md) and [`../authentication-agent/11-story-role-based-authorization-via-policies-TM-13.md`](../authentication-agent/11-story-role-based-authorization-via-policies-TM-13.md). Their sources are present and are the templates this story copies on both sides of the stack: `UserController`/`UserPolicy`/`StoreUserRequest` on the backend, and `api/users.ts` → `stores/users.ts` → `AdminUsersView.vue` → `UserFormDialog.vue` on the frontend. **Read all seven before writing anything.**
- **TM-12's frontend specs are still outstanding**, as `../authentication-agent/00-overview.md` records (⚠️ *Sources landed, tests outstanding*). `src/api/users.ts`, `src/stores/users.ts`, `AdminUsersView.vue` and `UserFormDialog.vue` have **no `.spec.ts`**, and `src/api/errors.spec.ts` was never written. **This story does not backfill them** — TM-12 owns that — but it also **must not copy the omission**: every file task 9 onward creates ships with its spec.
- **Docker services running:** repo root — `docker compose up -d`; all three containers `healthy`.
- **Baselines to re-measure before you start**, because TM-16 lands between this plan and its execution:
  - Backend, in `backend/`: **90 tests / 89 passing** today, going to **129 / 128** once TM-16 lands. The one red test is `Tests\Feature\Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (expects `422`, receives `429`), which fails in isolation and belongs to **TM-14**. **Do not fix it here.**
  - Frontend, in `frontend/`: **24 tests across 6 files**, all passing.
- **This story is the first in the project to put a policy in front of a route that agents may also reach.** Everything before it was `public`, `self`, or behind the `admin` middleware. Read **Product rules** before task 1 — the authorization shape is genuinely different and it breaks an existing test if you miss it.

---

## Story Goal

Give admins a categories screen that creates, edits, reorders and deactivates ticket categories, and give agents read access to the same list so the rest of the app can render a category by name and colour.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | Full CRUD at `/api/v1/categories`, admin for writes, agents for reads | ❌ **Not met**, and not expressible yet. `backend/routes/api.php` has nine routes and none mentions categories. Note the path: **not** under the `/admin` prefix that every existing write endpoint uses — see Product rules. |
| 2 | Slug generated from the name and unique; name required and unique | ❌ **Not met.** TM-16 creates the unique indexes on `slug` and `name`; nothing generates a slug. `Str::slug()` has two measured failure modes this story has to handle. |
| 3 | Colour validated as hex and used consistently by the UI badges | ❌ **Not met.** TM-16 deliberately left validation to this story (`char(7)` column, no `CHECK` constraint). There is **no badge component** anywhere in `frontend/src/components/` — it holds one file, `UserFormDialog.vue`. |
| 4 | Categories screen with inline activate/deactivate and drag-free `sort_order` editing | ❌ **Not met.** No view, no store, no API module. `frontend/src/views/` holds five views and none is categories. |
| 5 | Deactivated categories vanish from the new-ticket dropdown but stay on existing tickets | ⚠️ **Only half of this is demonstrable in this story.** The new-ticket dropdown is **TM-22** and `tickets` does not exist until **TM-21**. This story ships the data and the components that make it true, and pins the contract; it cannot show the dropdown. |

Eight outcomes:

1. Five routes at `/api/v1/categories`, authorized by **`CategoryPolicy`** rather than the `admin` middleware, because reads and writes need different answers on the same path.
2. `slug` is derived from `name` on create, is **never regenerated on rename**, and a name that would produce a duplicate or an empty slug is refused with a **422 on `name`** rather than a 500 from MySQL.
3. `color` is validated as `#RRGGBB` and **normalised to uppercase** before storage, so the same colour never renders as two values.
4. The list endpoint returns **every** category, active and inactive, unpaginated — because a ticket carrying a deactivated category still has to render its name and colour.
5. `DELETE` soft-deletes a category. It ships **unguarded on purpose**, and the reason it is safe today is written down along with exactly what **TM-18** must add.
6. `RouteAuthorizationTest::ACCESS` gains a fourth access level and five entries, so the manifest keeps failing loudly when a future route forgets to classify itself.
7. An admin-only `/admin/categories` screen with an inline active toggle, a numeric `sort_order` field per row, and a create/edit dialog.
8. A reusable **`CategoryBadge`** component that renders a category's colour with readable contrast — the "consistently" in criterion 3 — plus the seam **TM-19** wires its `masterData` store into.

**Not in scope:** the delete-with-reassignment guard and its 422 (**TM-18**), the app-wide `masterData` Pinia cache and its lookup helpers (**TM-19**), the `tickets` table and every foreign key into `categories` (**TM-21**), the new-ticket form and its dropdown (**TM-22**), admin screens for priorities and statuses (**no story asks for them** — priorities and statuses are code-owned master data, see TM-16), backfilling TM-12's missing specs, and the `PasswordThrottleTest` failure (**TM-14**).

---

## Product rules

### The URL is `/api/v1/categories`, and that changes how authorization works

Every write endpoint in the project so far sits behind `Route::middleware('admin')->prefix('admin')` (`backend/routes/api.php:32-37`). Criterion 1 puts categories at **`/api/v1/categories`** with **reads open to agents and writes restricted to admins** — one path, two answers. The `admin` middleware cannot express that: it is all-or-nothing per route group.

So authorization comes from **`CategoryPolicy`** via `$this->authorize(...)` in the controller, which is exactly what `docs/api-contract.md:27-28` already promised ("Category and ticket policies arrive with their models in TM-17 and TM-22"). Three consequences:

- **`Category` gains `#[UsePolicy(CategoryPolicy::class)]`.** TM-16 deliberately shipped the model without it and left the attribute to this story.
- **An unknown category id returns `404`, not `403`, even for an agent** — and that is correct here. `SubstituteBindings` resolves `{category}` before the controller runs, so the 404 wins. The users endpoints behave the opposite way (`RouteAuthorizationTest:82-88` pins agent→403, admin→404) only because `bootstrap/app.php:26-27` hoists `EnsureUserIsAdmin` **above** `SubstituteBindings` in the priority list. That hoist exists to stop an agent probing which user ids exist. **Category existence is not a secret** — every agent can list all of them — so there is nothing to hide and no hoist to add.
- **`viewAny` and `view` return `true` unconditionally.** They are not "anyone can read"; the `auth:sanctum` and `active` middleware on the group already guarantee an authenticated, non-deactivated staff member. Writing `$user->isAdmin() || $user->isAgent()` would be a tautology with a maintenance cost.

There is **no `Gate::before`** anywhere in `app/` (verified), so admins get no implicit allow-all. Every policy method decides for itself.

### `RouteAuthorizationTest` is a manifest, and five new routes will fail it

`backend/tests/Feature/Authorization/RouteAuthorizationTest.php:15` declares a `private const ACCESS` mapping every `api/v1` route name to `public`, `self` or `admin`, and `test_every_api_route_is_classified` (**17–24**) asserts each registered route appears in it. **Adding a route without touching that constant turns the suite red**, which is the point of the test.

Categories need a **fourth level**, because the existing three carry behaviour the test enforces:

- `test_admin_routes_have_three_middlewares` (**72–80**) asserts every `admin`-classified route gathers the `admin` middleware. Category writes are admin-only but carry **no** `admin` middleware, so classifying them `admin` breaks this test for the wrong reason.
- `test_agent_refused_by_admin_routes` (**34–40**) expects `403` with `This action is unauthorized.` — which is what `AuthorizationException` produces from either the middleware **or** the policy, so that behaviour does carry over.

Task 7 therefore adds `staff` (any authenticated active user) and `admin-policy` (admin-only, enforced by a policy rather than by middleware), and adds tests for both. It also has to widen `routesFor()` (**95–98**), which builds each URI with `route($name, ['user' => 999999])` — a category route's parameter is `{category}`, and a missing required parameter throws `UrlGenerationException`. Passing both keys is enough; Laravel appends unused ones as a harmless query string, which is already what happens to `user` on `admin.users.index`.

### `Str::slug()` returns an empty string more often than you would guess

Measured against this application's Laravel 13.26.1 during planning:

| Name | `Str::slug()` |
|---|---|
| `Hardware` | `hardware` |
| `Account & Access` | `account-access` |
| `Café` | `cafe` |
| `الشبكة` | `alshbk` |
| `دعم فني` | `daam-fny` |
| `Ünïcödé` | `unicode` |
| `ß straße` | `ss-strasse` |
| `Hardware/Software` | `hardwaresoftware` |
| **`北京`** | **`` (empty)** |
| **`🌐`** | **`` (empty)** |
| **`!!!`** | **`` (empty)** |
| **`---`** | **`` (empty)** |

Two failure modes, both reachable by an admin typing a legal, unique name:

- **An empty slug.** CJK, emoji-only and punctuation-only names all transliterate to nothing. The first such category would store `slug = ''`; the second would hit `1062` on `categories_slug_unique` and surface as a **500**. This product explicitly supports non-Latin text — `docker-compose.yml:24-26` runs both databases on `utf8mb4` so subjects and descriptions accept Arabic and emoji — so this is not a theoretical input.
- **Two distinct names, one slug.** `Hardware/Software` and `HardwareSoftware` both slugify to `hardwaresoftware`; `name` being unique does not make `slug` unique.

Task 3 catches both **in validation**, as a `422` on `name`, so the admin gets a message they can act on instead of a stack trace. Note that Arabic is *not* one of the failure cases — `alshbk` is ugly but stable and unique, and a slug is an internal identifier, not a label.

### The slug is generated once and never regenerated

TM-16's seeders match on `slug`, and TM-37's workflow and TM-19's lookup helpers treat it as the stable identifier. Regenerating it on rename would silently break `CategorySeeder`'s idempotency: the renamed row would no longer match, and the next `db:seed` would create a duplicate.

So `slug` is **derived on create and immutable thereafter**. `UpdateCategoryRequest` marks it `prohibited` — a client that sends one gets an explicit 422 rather than having the field silently ignored — and the controller's `safe()->only([...])` never lists it, so the model's `#[Fillable]` (which *does* include `slug`, because `CategorySeeder`'s `firstOrCreate` needs it) cannot be reached through an HTTP payload. **Both guards are deliberate; keep both.**

### The list is not paginated, and that is a deliberate exception

`docs/api-contract.md:16` says list endpoints return paginated `data`/`links`/`meta`, and `UserController::index` does exactly that. **Categories do not**, for two reasons:

- **TM-19 needs the whole set in one request** to build "resolve an id to a name and colour" helpers. Against a paginated endpoint it would have to pass `per_page=100` and hope, or walk pages on every page load.
- The set is bounded by construction — an admin-curated list of classification labels, seeded with six. It is not a queue that grows with usage.

`CategoryResource::collection($categories)` on a plain Eloquent collection returns `{"data": [...]}` — the same wrapper, without the pagination envelope. Task 8 narrows the convention line in `docs/api-contract.md` so it says what it means: **unbounded** lists paginate; bounded master-data lists do not.

### The list includes deactivated categories, and that is what makes criterion 5 work

The obvious reading of "deactivated categories disappear from the dropdown" is a server-side filter. **That would break the other half of the same criterion.** A ticket that already carries a deactivated category still has to render its name and colour, so the client needs the deactivated rows in hand.

The split is therefore:

- `GET /api/v1/categories` returns **all** categories, ordered, each with `is_active`.
- **The dropdown filters client-side.** Task 11's store exposes an `activeCategories` computed for exactly that, and **TM-22's new-ticket form must read it** rather than the raw list.
- `?status=active|inactive` exists for the admin screen's own filter, not for the dropdown.

What this story can demonstrate of criterion 5: the endpoint returns both kinds, `activeCategories` excludes the deactivated ones, and `CategoryBadge` renders a deactivated category without special-casing it away. What it cannot: the dropdown itself, which is **TM-22**.

### Delete ships unguarded, and here is exactly why that is safe today

Criterion 1 says "full CRUD", so `DELETE` ships. **TM-18** owns the guard — 422 when tickets block the delete, plus reassignment — and it cannot be written yet: `grep -rn "category_id" backend/` returns nothing, because `tickets` arrives in **TM-21**. There is no row anywhere that can be orphaned.

So this story's `destroy()` is a policy check and `$category->delete()` (a soft delete, per TM-16's `SoftDeletes` trait), returning `204`. **TM-18 must wrap this method, not replace it.** If TM-21 lands before TM-18, there is a window in which deleting a category with tickets succeeds and orphans them — record that in the overview, and note that TM-21's `ON DELETE RESTRICT` on `tickets.category_id` does **not** close it, because a soft delete is an `UPDATE` and never trips a foreign key.

---

## Context — Read These Files First

1. [`13-story-master-data-migrations-seeders-TM-16.md`](13-story-master-data-migrations-seeders-TM-16.md) — the **Product rules** section and task 5. It defines `Category`'s `#[Fillable]` list, the `scopeActive`/`scopeOrdered` scopes this story's controller calls, and why `slug` and `name` are unique across soft-deleted rows. Task 1 adds the `#[UsePolicy]` attribute that plan deliberately left out.
2. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — all 93 lines. `index()` (**19–38**) is the filter-and-paginate template: note `$request->validate([...])` for query parameters and the `->when(...)` chain. `store()` (**40–48**) shows `new User($request->safe()->only([...]))` plus a non-fillable field set as a property (**44**) — task 5 does the same for `slug`. `update()` (**57–77**) shows `$this->authorize` before `fill`.
3. `backend/app/Policies/UserPolicy.php` — all 33 lines. Five methods, each a one-line role check. `CategoryPolicy` is the same file with different answers.
4. `backend/app/Http/Requests/Api/V1/Admin/StoreUserRequest.php` and `UpdateUserRequest.php` — 23 and 25 lines. Note the update request reads the bound model out of the route (**15–16**) to build `Rule::unique(...)->ignore($user)` (**20**); task 4 needs the identical trick for `name`.
5. `backend/app/Http/Resources/V1/UserResource.php` — all 21 lines. Flat array, `?->toIso8601String()` on the timestamp. `CategoryResource` matches it.
6. `backend/routes/api.php` — all 38 lines. The `['auth:sanctum', 'active']` group opens at **27**; the `admin` sub-group is **32–37**. Category routes go **inside the outer group and outside the inner one** — get this wrong and either agents are locked out or admins-only becomes everyone.
7. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — all 99 lines, and **the file most likely to be forgotten**. `const ACCESS` is line **15**; `routesFor()` is **95–98**; `test_admin_routes_have_three_middlewares` is **72–80**. Read the Product rules note above before editing.
8. `backend/bootstrap/app.php` — lines **21–28**. The alias block and the two `prependToPriorityList` calls that hoist `EnsureUserIsActive` and `EnsureUserIsAdmin` above `SubstituteBindings`. **This story adds nothing here** — understand why it does not need to.
9. `backend/app/Providers/AppServiceProvider.php` — all 29 lines. Two named rate limiters, `login` and `password`. **Categories are not throttled**; nothing here changes.
10. `frontend/src/api/users.ts` (55 lines) and `frontend/src/api/pagination.ts` (18 lines) — the API-module shape: an exported interface per payload, `client.get/post/patch`, and **`data.data` unwrapped** on single-resource calls (**44**, **54**). Task 10 copies it minus the pagination generic.
11. `frontend/src/api/errors.ts` — all 21 lines. `validationErrors()` (**5–10**) returns the 422 `errors` map; `errorMessage()` (**11–21**) has the friendly `429`/`403` strings. Task 13's dialog uses both; do not write a private copy.
12. `frontend/src/stores/users.ts` — all 77 lines. **The `latestRequest` counter (19, 30, 34, 39) is the pattern to copy** — it drops a stale response that resolves after a newer one. Note `create`/`update` (**55–62**) deliberately re-throw so the dialog can render the 422.
13. `frontend/src/views/AdminUsersView.vue` — all 80 lines. The `data-testid` convention (`users-search`, `users-table`, `users-row`, `users-empty`, `users-error`), the debounce `watch` (**10–17**), and the dialog mounted with `v-if` (**73–78**). Task 14 mirrors all of it with a `categories-` prefix.
14. `frontend/src/components/UserFormDialog.vue` — the props/emits contract (**8–9**), `reactive` form seeded from the optional prop (**13–19**), and the `submit()` catch that splits field errors from the form-level message (**33–36**).
15. `frontend/src/router/index.ts` — all 50 lines. The `RouteMeta` augmentation (**11–16**) and the `meta: { role: 'admin' }` route at **35–40**. `frontend/src/router/guards.ts:35` is the line that turns that meta into a redirect to `forbidden`.
16. `frontend/src/style.css` — lines **1–23**. The CSS custom properties (`--text`, `--text-h`, `--bg`, `--border`) **and the `prefers-color-scheme: dark` block**. A badge that hard-codes a text colour will be unreadable in one of the two themes; task 12 computes it instead.
17. `frontend/src/views/HealthView.spec.ts` — all 62 lines. The house test idiom: `vi.mock('../api/…')` at module scope, `mount(Component, { global: { plugins: [createPinia()] } })`, `flushPromises()`, and assertions through `data-testid`.
18. Grep before you write:
    - `grep -rn "categories" backend/routes backend/app frontend/src` — only TM-16's model, seeder and migration should answer.
    - `grep -rn "data-testid=\"categories" frontend/src` — must be empty before you start.

---

## Backend Tasks

### 1 — The policy, and the attribute TM-16 left out

**Create file: `backend/app/Policies/CategoryPolicy.php`**

```php
<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;

class CategoryPolicy
{
    /**
     * Reads are open to every signed-in staff member. The route group already
     * carries auth:sanctum and active, so reaching this method at all means an
     * authenticated, non-deactivated user — there is nothing left to check.
     * Agents need the list to render a ticket's category badge (TM-19, TM-22).
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Category $category): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Category $category): bool
    {
        return $user->isAdmin();
    }

    /**
     * Soft delete only. TM-18 adds the guard that refuses a delete which would
     * orphan tickets; until `tickets` exists (TM-21) there is nothing to guard.
     */
    public function delete(User $user, Category $category): bool
    {
        return $user->isAdmin();
    }
}
```

**File: `backend/app/Models/Category.php`** (created by TM-16) — add the import and the attribute above the class, matching `User.php:20`:

```php
use App\Policies\CategoryPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

#[Fillable([...])]          // unchanged, from TM-16
#[UsePolicy(CategoryPolicy::class)]
class Category extends Model
```

Change nothing else in that file. In particular **leave `slug` in `#[Fillable]`** — `CategorySeeder`'s `firstOrCreate(['slug' => …], $category)` needs it, and tasks 3–5 block the HTTP path to it twice over.

### 2 — The resource

**Create file: `backend/app/Http/Resources/V1/CategoryResource.php`**

```php
<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
```

`is_default_unique` is absent because TM-16 put it in `#[Hidden]` on `Priority` and `Status`; `Category` has no such column. `deleted_at` is **not** exposed — a soft-deleted category never reaches a response, and TM-18 can add it if its reassignment flow needs it.

### 3 — The create request

**Create file: `backend/app/Http/Requests/Api/V1/StoreCategoryRequest.php`**

Note the namespace: **`Api\V1`, not `Api\V1\Admin`** — these endpoints are not under the admin prefix.

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCategoryRequest extends FormRequest
{
    /**
     * Normalise the colour before validation so #ef4444 and #EF4444 are the
     * same value everywhere, and the regex below only has to accept one case
     * in storage. TM-16 seeds uppercase.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($color = $this->input('color'))) {
            $this->merge(['color' => strtoupper($color)]);
        }
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:categories,name', $this->slugRule()],
            // Derived from the name and immutable afterwards — the seeders and
            // TM-19's lookup helpers key on it. Prohibited rather than ignored
            // so a client that sends one is told, not silently overruled.
            'slug' => ['prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * The slug is generated, so its two failure modes have to be caught on the
     * field the admin actually typed. Measured on this Laravel version:
     * Str::slug() returns '' for CJK, emoji-only and punctuation-only names,
     * and 'Hardware/Software' and 'HardwareSoftware' both give
     * 'hardwaresoftware' — so a unique name does not imply a unique slug.
     * Without this rule both cases reach MySQL as a 1062 and surface as a 500.
     */
    protected function slugRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $slug = Str::slug((string) $value);

            if ($slug === '') {
                $fail('The name must contain at least one letter or number that can be used in a URL.');

                return;
            }

            // withTrashed(): MySQL enforces categories_slug_unique across
            // soft-deleted rows, so a deleted category still owns its slug.
            if (Category::withTrashed()->where('slug', $slug)->exists()) {
                $fail("Another category already uses the identifier \"{$slug}\".");
            }
        };
    }
}
```

Two notes on rules that look wrong but are not:

- **`unique:categories,name` needs no `withTrashed` equivalent.** Laravel's unique rule runs through `DatabasePresenceVerifier`, which queries the table with the plain query builder and applies no Eloquent global scope — so it already sees soft-deleted rows, exactly like the closure above. The two are consistent by accident of implementation; the closure spells it out because it uses the model.
- **`color` is `sometimes`, not `required`.** The column defaults to `#6B7280` (TM-16), so an omitted colour is a valid create. `max:65535` on `sort_order` is the ceiling of `unsignedSmallInteger`; without it, `70000` becomes a `QueryException` in strict mode instead of a 422.

`authorize()` is **not** overridden. `FormRequest::authorize()` defaults to `true`, and authorization is the controller's `$this->authorize(...)` call against the policy — same division as `StoreUserRequest`.

### 4 — The update request

**Create file: `backend/app/Http/Requests/Api/V1/UpdateCategoryRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($color = $this->input('color'))) {
            $this->merge(['color' => strtoupper($color)]);
        }
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        /** @var Category $category */
        $category = $this->route('category');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category)],
            // Immutable. Renaming a category must not move its slug: the
            // seeders match on it, so a regenerated slug would make the next
            // db:seed create a duplicate row.
            'slug' => ['prohibited'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
```

**There is no slug closure here** — the slug does not move, so a rename cannot collide with one. `Rule::unique(...)->ignore($category)` is what lets an admin PATCH a category without changing its name; without `ignore`, saving the form unchanged would 422 against the row being edited (the bug `UpdateUserRequest:20` already avoids).

### 5 — The controller

**Create file: `backend/app/Http/Controllers/Api/V1/CategoryController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreCategoryRequest;
use App\Http\Requests\Api\V1\UpdateCategoryRequest;
use App\Http\Resources\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class CategoryController extends Controller
{
    /**
     * Every category, active and inactive, unpaginated. Both halves are
     * deliberate: the set is bounded (an admin-curated list, seeded with six),
     * and a ticket carrying a deactivated category still has to render its
     * name and colour — so the client needs the inactive rows in hand and
     * filters the new-ticket dropdown itself (TM-19, TM-22).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Category::class);
        $filters = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $categories = Category::query()
            ->when(($filters['status'] ?? null) === 'active', fn ($query) => $query->active())
            ->when(($filters['status'] ?? null) === 'inactive', fn ($query) => $query->where('is_active', false))
            ->ordered()
            ->get();

        return CategoryResource::collection($categories);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $this->authorize('create', Category::class);
        $category = new Category($request->safe()->only(['name', 'description', 'color', 'is_active', 'sort_order']));
        // Set as a property, never through fill(): the request prohibits a
        // client-supplied slug, and only() keeps it out of the mass-assign
        // even though the model allows it for CategorySeeder's benefit.
        $category->slug = Str::slug($request->string('name')->value());
        $category->save();

        return CategoryResource::make($category)->response()->setStatusCode(201);
    }

    public function show(Category $category): JsonResponse
    {
        $this->authorize('view', $category);

        return CategoryResource::make($category)->response();
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);
        $category->fill($request->safe()->only(['name', 'description', 'color', 'is_active', 'sort_order']));
        $category->save();

        return CategoryResource::make($category)->response();
    }

    /**
     * Soft delete. TM-18 wraps this with the 422-and-reassignment guard once
     * `tickets` exists (TM-21) — until then nothing can be orphaned.
     */
    public function destroy(Category $category): Response
    {
        $this->authorize('delete', $category);
        $category->delete();

        return response()->noContent();
    }
}
```

`ordered()` and `active()` are TM-16's scopes; do not re-implement the ordering inline. **No `DB::transaction`** — unlike `UserController::update`, every write here is a single statement with no cross-row invariant to hold. TM-18's reassignment is the first category write that needs one.

### 6 — The routes

**File: `backend/routes/api.php`**

Add the import alongside the existing controller imports (alphabetical, so after `Auth\MeController` and before `HealthController`):

```php
use App\Http\Controllers\Api\V1\CategoryController;
```

Then add the five routes **inside** the `['auth:sanctum', 'active']` group opened at line 27 and **above** the `admin` sub-group at line 32:

```php
    // Reads are open to every active staff member; writes are admin-only via
    // CategoryPolicy. That mix is why these are not under the /admin prefix —
    // the `admin` middleware is all-or-nothing per group.
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
```

Write them out one per line rather than `Route::apiResource(...)`. The file lists every route explicitly (**28–36**), `apiResource` would also register a `categories.index`-shaped name set you then have to reconcile with the manifest, and the explicit form makes it obvious at a glance that `destroy` exists and `create`/`edit` do not.

**Do not add `/api/v1` to any path** — `bootstrap/app.php:19` sets `apiPrefix: 'api/v1'`.

### 7 — Extend the route-authorization manifest

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add the five names to `const ACCESS` (line **15**) using two new levels:

```php
private const ACCESS = [
    'health' => 'public', 'auth.login' => 'public',
    'auth.logout' => 'self', 'auth.me' => 'self', 'auth.password' => 'self',
    'admin.users.index' => 'admin', 'admin.users.store' => 'admin',
    'admin.users.show' => 'admin', 'admin.users.update' => 'admin',
    // Reads any active staff member may make.
    'categories.index' => 'staff', 'categories.show' => 'staff',
    // Admin-only, but enforced by CategoryPolicy rather than the `admin`
    // middleware — so these must NOT be classified 'admin', which
    // test_admin_routes_have_three_middlewares would then fail on.
    'categories.store' => 'admin-policy', 'categories.update' => 'admin-policy',
    'categories.destroy' => 'admin-policy',
];
```

Widen `routesFor()` (**95–98**) so it can build a category URI — `route()` throws `UrlGenerationException` on a missing required parameter, and unused keys become a harmless query string, which is already true of `user` on `admin.users.index`:

```php
'uri' => route($route->getName(), ['user' => 999999, 'category' => 999999], absolute: false),
```

Add three tests and extend one:

- `test_agent_refused_by_policy_admin_routes` — an agent token against every `admin-policy` route asserts `403` and `assertJsonPath('message', 'This action is unauthorized.')`. Same message as the middleware path, because both raise `AuthorizationException`.
- `test_agent_reaches_staff_routes` — an agent token gets `200` from `/api/v1/categories`, and `200` from `/api/v1/categories/{id}` for a real seeded category.
- `test_policy_admin_routes_have_no_admin_middleware` — the inverse of **72–80**: every `admin-policy` route gathers `auth:sanctum` and `active` but **not** `admin`. Without it, someone "fixing" the mixed authorization by dropping the group under `/admin` would pass every other test in this file.
- Extend `test_unauthenticated_non_public_refused` (**58–64**) with `$this->getJson('/api/v1/categories')->assertUnauthorized();`.

**Do not touch** `test_agent_refused_by_admin_routes`, `test_admin_not_refused_by_admin_routes` or `test_unknown_id_agent_forbidden_admin_not_found` — they describe the users endpoints and must keep passing verbatim.

### 8 — The contract

**File: `docs/api-contract.md`**

Add five rows to the endpoints table (**32–41**), after the `auth.password` row and before the `admin/users` block:

| `GET` | `/api/v1/categories` | Every category, active and inactive, ordered. Unpaginated. | bearer (CategoryPolicy) | TM-17 |
| `POST` | `/api/v1/categories` | Create a category; slug derived from the name. | admin bearer (CategoryPolicy) | TM-17 |
| `GET` | `/api/v1/categories/{category}` | Return one category. | bearer (CategoryPolicy) | TM-17 |
| `PATCH` | `/api/v1/categories/{category}` | Rename, recolour, reorder, activate or deactivate. | admin bearer (CategoryPolicy) | TM-17 |
| `DELETE` | `/api/v1/categories/{category}` | Soft delete. Unguarded until TM-18. | admin bearer (CategoryPolicy) | TM-17 |

Amend the **Conventions** bullet at line **16** so it states the rule this story follows rather than the one it breaks:

> - List endpoints whose result set grows with usage return paginated `data`, `links`, and `meta` envelopes. **Bounded master-data lists (categories) return `data` only** — the whole set is needed in one request to resolve ids to names and colours.

Amend the **Authorization** paragraph (**25–28**) to replace "Category and ticket policies arrive with their models in TM-17 and TM-22" with what `CategoryPolicy` actually does, and record the deliberate `404`-not-`403` difference:

> `CategoryPolicy` defines `viewAny`, `view`, `create`, `update`, and `delete`. Any active staff member may read categories; only admins may write. Because these routes carry no `admin` middleware, route-model binding resolves first, so an unknown category id returns `404` to agents and admins alike — category existence is not a secret, unlike a user id.

Add a `### /api/v1/categories` section documenting the field list, the `?status=active|inactive` filter, the `422` shapes (`name` taken, name yields an empty or duplicate slug, `color` not `#RRGGBB`, `slug` prohibited), and that `DELETE` returns `204`.

**Do not touch `docs/erd.md`** — TM-16 owns the `categories` row and this story adds no column.

---

## Frontend Tasks

### 9 — Readable badge text

**Create file: `frontend/src/lib/color.ts`** (new directory — `frontend/src/` currently has `api/`, `components/`, `router/`, `stores/`, `views/`)

```ts
const DARK = '#15171b'
const LIGHT = '#ffffff'

/**
 * Relative luminance per WCAG 2.1, used to pick text that stays readable on an
 * admin-chosen background. A badge cannot inherit --text: the swatch is the
 * same colour in both themes, so a token that flips with prefers-color-scheme
 * would be unreadable in one of them.
 */
export function relativeLuminance(hex: string): number {
  const channels = [1, 3, 5].map((start) => {
    const value = parseInt(hex.slice(start, start + 2), 16) / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

export function readableTextColor(hex: string): string {
  if (!/^#[0-9A-Fa-f]{6}$/.test(hex)) return DARK
  return relativeLuminance(hex) > 0.179 ? DARK : LIGHT
}
```

`0.179` is the standard crossover for a 4.5:1 contrast decision between black and white. The regex guard means a malformed colour degrades to dark text instead of throwing — the API validates the format, but the store also holds values that arrived before this story shipped.

### 10 — The categories API module

**Create file: `frontend/src/api/categories.ts`**

```ts
import client from './client'

export interface Category {
  id: number
  name: string
  slug: string
  description: string | null
  color: string
  is_active: boolean
  sort_order: number
  created_at: string
  updated_at: string
}

export interface CategoryListQuery {
  status?: 'active' | 'inactive'
}

export interface CreateCategoryPayload {
  name: string
  description?: string | null
  color?: string
  is_active?: boolean
  sort_order?: number
}

export type UpdateCategoryPayload = Partial<
  Pick<Category, 'name' | 'description' | 'color' | 'is_active' | 'sort_order'>
>

export async function listCategories(
  query: CategoryListQuery = {},
): Promise<Category[]> {
  const { data } = await client.get<{ data: Category[] }>('/categories', {
    params: query,
  })
  return data.data
}

export async function createCategory(
  payload: CreateCategoryPayload,
): Promise<Category> {
  const { data } = await client.post<{ data: Category }>('/categories', payload)
  return data.data
}

export async function updateCategory(
  id: number,
  payload: UpdateCategoryPayload,
): Promise<Category> {
  const { data } = await client.patch<{ data: Category }>(
    `/categories/${id}`,
    payload,
  )
  return data.data
}

export async function deleteCategory(id: number): Promise<void> {
  await client.delete(`/categories/${id}`)
}
```

`listCategories` returns `Category[]`, **not** `Paginated<Category>` — the endpoint has no `meta`. `slug` is on the interface but there is no way to send it; it is read-only by construction.

### 11 — The categories store

**Create file: `frontend/src/stores/categories.ts`**

Mirror `stores/users.ts`, including the `latestRequest` counter and the re-throwing `create`/`update`. Differences to get right:

```ts
const categories = ref<Category[]>([])
const status = ref<'active' | 'inactive' | ''>('')

/**
 * The set the new-ticket dropdown may offer. TM-22 must read this, not
 * `categories` — a deactivated category has to stay resolvable for tickets
 * that already carry it, which is why `load()` fetches every row.
 */
const activeCategories = computed(() => categories.value.filter((c) => c.is_active))

/** Resolve an id for a badge. TM-19's masterData store wraps this. */
const byId = computed(
  () => new Map(categories.value.map((category) => [category.id, category])),
)
```

- **No `meta`, no `page`, no `goToPage`.** The endpoint is unpaginated.
- `remove(id)` calls `deleteCategory` then `load()`, and re-throws like the others.
- `setActive(category, isActive)` is a thin wrapper over `update(category.id, { is_active: isActive })` so the row toggle in task 14 reads as one call.
- `saveSortOrder(category, sortOrder)` likewise, so the numeric field has a single entry point.

**This is the admin CRUD store, not the app-wide cache.** TM-19's `masterData` store is the read-only cache dropdowns and badges consume across the app, and TM-19's fourth acceptance criterion ("mutating master data in the admin screens refreshes the store without a page reload") is satisfied by TM-19 subscribing to this store. **Do not build the shared cache here**, and do not make this store a singleton read path for ticket screens.

### 12 — The badge

**Create file: `frontend/src/components/CategoryBadge.vue`**

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { readableTextColor } from '../lib/color'
import type { Category } from '../api/categories'
const props = defineProps<{ category: Pick<Category, 'name' | 'color' | 'is_active'> }>()
const textColor = computed(() => readableTextColor(props.category.color))
</script>
<template>
  <span
    class="badge"
    data-testid="category-badge"
    :style="{ backgroundColor: category.color, color: textColor }"
    :data-inactive="category.is_active ? undefined : 'true'"
    >{{ category.name }}</span
  >
</template>
```

`Pick<...>` rather than the full `Category` so **TM-22** can render a badge from a ticket's embedded category without constructing a whole record.

**A deactivated category still renders**, with `data-inactive` for styling (a reduced opacity in the scoped style block) — never by returning nothing. That is the "remain on existing tickets" half of criterion 5, and a test asserts it.

### 13 — The create/edit dialog

**Create file: `frontend/src/components/CategoryFormDialog.vue`**

Follow `UserFormDialog.vue`'s contract exactly: `defineProps<{ category?: Category }>()`, `defineEmits<{ saved: []; close: [] }>()`, a `reactive` form seeded from the prop, and a `submit()` that splits `validationErrors(error)` from `errorMessage(error)`.

Fields and testids: `category-form-name`, `category-form-description`, `category-form-color` (`type="color"` plus a text input bound to the same value, so an admin can paste `#EF4444`), `category-form-active`, `category-form-sort-order` (`type="number"`, `min="0"`, `max="65535"`).

Three things this dialog must do that the users dialog does not:

- **Render the `name` error prominently**, at `category-form-error-name`. The slug rules in task 3 surface there, and "Another category already uses the identifier …" is meaningless if it renders as a generic form-level message.
- **Show the derived slug read-only in edit mode**, at `category-form-slug`, with a note that it does not change on rename. There is no input for it.
- **Uppercase the colour before submitting** via the shared helper, so the optimistic UI and the server agree on `#EF4444`.

### 14 — The screen, the route and the nav link

**Create file: `frontend/src/views/AdminCategoriesView.vue`**

Mirror `AdminUsersView.vue`, with `categories-` testids: `categories-status` (the all/active/inactive select), `categories-new`, `categories-loading`, `categories-error`, `categories-table`, `categories-row`, `categories-empty`.

Per row: a `CategoryBadge`, the slug, the description, a `sort_order` **number input** committing on `change` (not on every keystroke) via `store.saveSortOrder`, an **activate/deactivate button** at `categories-toggle` calling `store.setActive`, an `Edit` button, and a `Delete` button at `categories-delete` behind a confirm step.

**No search box and no pagination controls** — six rows. **No drag-and-drop**: criterion 4 says *drag-free*, and no drag library is installed (`frontend/package.json` dependencies are `axios`, `pinia`, `vue`, `vue-router` only). Adding one would be a new dependency this story has no mandate for.

**File: `frontend/src/router/index.ts`** — import the view and add a route beside the users one (**35–40**), same shape:

```ts
{
  path: '/admin/categories',
  name: 'admin-categories',
  component: AdminCategoriesView,
  meta: { role: 'admin' },
},
```

`meta.role` is already handled by `guards.ts:35`; no guard change is needed. Note the SPA route is `/admin/categories` while the API path is `/api/v1/categories` — they are unrelated namespaces, and the screen is admin-only even though the endpoint is not.

**File: `frontend/src/App.vue`** — add a nav link inside the `v-if="auth.isAuthenticated"` header (**18–26**), visible only to admins:

```vue
<RouterLink
  v-if="auth.isAdmin"
  :to="{ name: 'admin-categories' }"
  data-testid="nav-categories"
  >Categories</RouterLink
>
```

`auth.isAdmin` is the same getter `guards.ts:35` uses. **There is currently no nav link to `admin-users` either** — do not add one; that gap is TM-12's, and adding it here would put an untested link in a file whose spec does not exist.

---

## Edge Cases & Failure Modes

- **A name that slugifies to nothing** — `北京`, `🌐`, `!!!`, `---`. Measured. Caught by `StoreCategoryRequest::slugRule()` as a `422` on `name` with the "at least one letter or number" message. Without it the first such row stores `slug = ''` and the second returns a 500.
- **Two distinct names, one slug** — `Hardware/Software` and `HardwareSoftware`. Measured. Same rule, different message, naming the conflicting identifier.
- **A soft-deleted category still owns its name and slug.** TM-16 measured that MySQL enforces both unique indexes across trashed rows. An admin who deletes "Billing" and recreates it gets a `422`, not a 500 — `Rule::unique` uses the raw query builder and `slugRule()` uses `withTrashed()`. **The 422 is correct but the message is unhelpful**; TM-18 should offer "restore instead" once its reassignment flow exists.
- **A client sends `slug`.** `prohibited` on both requests returns a `422` naming the field. Even if that rule were removed, `safe()->only([...])` in the controller drops it — two independent guards, on purpose.
- **A rename that would clash with the row being renamed.** `Rule::unique(...)->ignore($category)` allows a PATCH that resubmits the current name unchanged, which is what the edit dialog does every time it saves.
- **`sort_order` above 65535.** `max:65535` returns a `422`; without it the `unsignedSmallInteger` column raises a `QueryException` under `'strict' => true` (`backend/config/database.php:60`) and surfaces as a 500.
- **A lowercase colour.** `prepareForValidation()` uppercases before the regex runs, so `#ef4444` is accepted and stored as `#EF4444`. A colour with 3 digits (`#EEE`) or a name (`red`) is a `422`.
- **An agent attempts a write.** `CategoryPolicy::create/update/delete` returns false, `$this->authorize` raises `AuthorizationException`, and `bootstrap/app.php:29-33` renders it as JSON `403` with `This action is unauthorized.` — the same body agents already get from the `admin` middleware.
- **An unknown category id.** `404` for agents and admins alike, because `SubstituteBindings` runs before the policy on these routes. Deliberate — see Product rules. Pinned by a test so nobody "fixes" it into a 403.
- **A soft-deleted category id.** Also `404`: the `SoftDeletes` global scope hides it from route-model binding. TM-18 will need `withTrashed()` binding if its flow has to address a deleted row.
- **Deleting the last category.** Allowed. Nothing in the product requires at least one, and TM-22 has to handle an empty dropdown regardless — a seeded install that an admin empties is indistinguishable from one they never seeded.
- **Deleting a category that has tickets.** Impossible today (`tickets` does not exist), **and not blocked by TM-21's foreign key when it does** — a soft delete is an `UPDATE`, so `ON DELETE RESTRICT` never fires. If TM-21 lands before TM-18 there is a real window; recorded in the overview.
- **Two admins editing the same category.** Last write wins. No optimistic locking anywhere in the project, and no story asks for it; the fields are independent enough that a lost update means a re-typed colour, not corrupted data.
- **A stale list response.** The store's `latestRequest` counter drops a response that resolves after a newer one — the same bug `stores/users.ts:19,30,34,39` guards against, reachable here by toggling the status filter twice quickly.
- **A category named in Arabic.** `الشبكة` stores fine (utf8mb4) and slugifies to `alshbk` — ugly but stable and unique. The badge renders the **name**, never the slug, so nothing user-facing shows the transliteration.
- **A very light colour, e.g. `#FFFFF0`.** `readableTextColor` returns the dark token, so the badge stays readable. A hard-coded white would be invisible.

---

## Test Plan

### Backend

`composer test` from `backend/`. Baseline after TM-16: **129 tests, 128 passing**, the one red test being TM-14's `PasswordThrottleTest`. Every feature test uses `RefreshDatabase`; tests that need categories call `$this->seed(CategorySeeder::class)` rather than assuming seeded data.

1. **Create `backend/tests/Feature/Categories/CategoryReadTest.php`** — 6 tests.
   - `agent can list categories` — agent token, `GET /api/v1/categories`, `200`, `assertJsonCount(6, 'data')`.
   - `list has no pagination envelope` — the response has a `data` key and **no `meta` and no `links`**. Pins the deliberate exception to the contract.
   - `list includes deactivated categories` — deactivate one, assert it is still in `data`. **The assertion criterion 5 depends on.**
   - `list is ordered by sort_order then name` — assert the returned slugs match `Category::ordered()`.
   - `status filter narrows the list` — `?status=active` and `?status=inactive` return complementary sets; `?status=bogus` returns `422`.
   - `agent can show one category` — `200`, and the payload has `id`, `name`, `slug`, `description`, `color`, `is_active`, `sort_order`, `created_at`, `updated_at` and **no `deleted_at`**.

2. **Create `backend/tests/Feature/Categories/CategoryWriteAuthorizationTest.php`** — 5 tests.
   - `agent cannot create/update/delete` — three requests, each `403` with `This action is unauthorized.`
   - `admin can create/update/delete` — the same three, not `403`.
   - `unauthenticated is refused` — `401` on the list and on a create.
   - `deactivated admin is refused` — a `->inactive()` admin's token gets `401` from the `active` middleware, not `403`.
   - `unknown id returns 404 for agent and admin alike` — `GET /api/v1/categories/999999` is `404` for both. **The inverse of `RouteAuthorizationTest:82-88`**, and the test that stops someone "fixing" it into a 403.

3. **Create `backend/tests/Feature/Categories/CategoryStoreTest.php`** — 10 tests, admin token throughout.
   - `creates a category and derives the slug` — `201`, `slug` is `account-access` for the name `Account & Access`.
   - `defaults colour, active flag and sort order` — omit all three; the row comes back `#6B7280`, `true`, `0`.
   - `uppercases a lowercase colour` — send `#ef4444`, stored and returned as `#EF4444`.
   - `rejects a duplicate name` — `422` on `name`.
   - `rejects a name whose slug is already taken` — create `Hardware/Software`, then `HardwareSoftware`; `422` on `name` mentioning `hardwaresoftware`.
   - **`rejects a name that produces an empty slug`** — data provider over `北京`, `🌐`, `!!!` and `---`; each is `422` on `name`, and `Category::count()` is unchanged. Four assertions on the measured failure mode.
   - `rejects a name taken by a soft-deleted category` — delete `billing`, POST `Billing`; `422`, not a 500.
   - `rejects a malformed colour` — data provider over `red`, `#EEE`, `#GGGGGG`, `EF4444`; each `422`.
   - `rejects a client-supplied slug` — POST with `slug`, `422` naming the field.
   - `rejects sort_order above the column ceiling` — `70000` is `422`, not a `QueryException`.

4. **Create `backend/tests/Feature/Categories/CategoryUpdateTest.php`** — 7 tests.
   - `renames without moving the slug` — PATCH `name`, assert `slug` is unchanged. **The assertion that keeps `CategorySeeder` idempotent.**
   - `accepts the unchanged name` — PATCH the current name; `200`, proving `->ignore($category)` is present.
   - `deactivates and reactivates` — PATCH `is_active`, both ways.
   - `updates sort_order alone` — a partial PATCH leaves name, colour and description untouched.
   - `clears the description with null` — `description: null` stores null.
   - `rejects a name taken by another category` — `422`.
   - `rejects a client-supplied slug` — `422`.

5. **Create `backend/tests/Feature/Categories/CategoryDeleteTest.php`** — 3 tests.
   - `soft deletes` — `204`, `Category::count()` is 5, `Category::withTrashed()->count()` is 6, `deleted_at` is set.
   - `deleted category disappears from the list and from show` — `GET` list omits it; `GET /categories/{id}` is `404`.
   - `deleting twice returns 404` — the second `DELETE` cannot find the row.

6. **Edit `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`** — per task 7: five manifest entries, the widened `routesFor()`, one extended test and **3 new tests** (`test_agent_refused_by_policy_admin_routes`, `test_agent_reaches_staff_routes`, `test_policy_admin_routes_have_no_admin_middleware`).

7. **Create `backend/tests/Unit/Policies/CategoryPolicyTest.php`** — 3 tests. `viewAny`/`view` true for both roles; `create`/`update`/`delete` true for admin and false for agent; the policy is resolved for the model (`Gate::getPolicyFor(Category::class)` is a `CategoryPolicy`) — the last one is what catches a missing `#[UsePolicy]` attribute.

8. **No test asserts the seeded category names.** TM-16's `MasterDataSeederTest` owns that; repeating it here would make a rename a two-file edit.

Backend total added: **37 tests** (6 + 5 + 10 + 7 + 3 + 3 + 3), taking the suite from 129 to **166**.

### Frontend

`npm test` from `frontend/`. Baseline: **24 tests across 6 files**. Match `src/views/HealthView.spec.ts` — `vi.mock` at module scope, `mount(..., { global: { plugins: [createPinia()] } })`, `flushPromises()`, assertions through `data-testid`, and Prettier's single-quote/no-semicolon formatting.

9. **Create `frontend/src/lib/color.spec.ts`** — no mocking, pure functions. 5 tests: white returns the dark token; black returns the light token; `#6B7280` (the seeded default) returns the light token; a malformed value returns the dark token without throwing; lowercase and uppercase hex give the same answer.

10. **Create `frontend/src/api/categories.spec.ts`** — the adapter-swap technique from `src/api/client.spec.ts`. 6 tests: `listCategories` requests `/categories` and **unwraps `data.data` to an array**; it passes `status` as a param and omits it when absent; `createCategory` posts and unwraps; `updateCategory` `PATCH`es `/categories/{id}` and sends only the keys it was given; `deleteCategory` issues a `DELETE` and resolves to `undefined`.

11. **Create `frontend/src/stores/categories.spec.ts`** — `vi.mock('../api/categories')`, `setActivePinia(createPinia())` per test. 10 tests: `load` fills `categories`; `load` sets `error` and clears the list on failure; **`load` ignores a stale response** (resolve two calls out of order, assert the newer wins); `activeCategories` excludes deactivated rows; `byId` resolves an id to a record; `create`/`update`/`remove` each call the API and reload; `create` re-throws so the dialog can render the 422; `setActive` sends `{ is_active: … }` and nothing else.

12. **Create `frontend/src/components/CategoryBadge.spec.ts`** — 4 tests: renders the name; applies the category colour as `background-color`; picks readable text for a light and for a dark colour; **renders a deactivated category rather than hiding it**, and marks it `data-inactive`.

13. **Create `frontend/src/components/CategoryFormDialog.spec.ts`** — 8 tests: create mode submits name, colour, description, active and sort order; edit mode submits only changed fields; edit mode renders the slug read-only and has **no slug input**; a `422` on `name` renders at `category-form-error-name`; the empty-slug message renders verbatim from the server; a `403` renders the shared permission message; a lowercase pasted colour is uppercased before submit; `saved` and `close` are emitted.

14. **Create `frontend/src/views/AdminCategoriesView.spec.ts`** — 9 tests: a row per category with a badge; loading then table; `categories-error` on failure; the status select refetches; the `sort_order` input commits on `change` and not on `input`; the toggle calls `setActive` with the inverted flag; delete asks for confirmation before calling `remove`; the empty state renders; **no pagination controls are rendered** — the deferral pinned, so the story that adds paging has to change this test deliberately.

15. **Existing specs must pass untouched.** This story edits `router/index.ts` and `App.vue`; `src/router/guards.spec.ts` and `src/stores/auth.spec.ts` assert behaviour neither edit changes. If either goes red, the route meta or the `v-if` is wrong, not the test.

16. **TM-12's missing specs are not written here.** `src/api/users.spec.ts`, `src/stores/users.spec.ts`, `AdminUsersView.spec.ts`, `UserFormDialog.spec.ts` and `errors.spec.ts` stay absent; TM-12 owns them.

Frontend total added: **42 tests** (5 + 6 + 10 + 4 + 8 + 9), taking the suite from 24 to **66**.

**Combined: 79 new tests.**

---

## Verification Steps

Run in this order. The working directory is stated for every command.

1. **The blocker is real, not planned:** `backend/` — `php artisan db:table categories` prints the table and `php artisan tinker --execute="echo App\Models\Category::count();"` prints `6`. **If either fails, stop** — TM-16 is not implemented and nothing below can run.
2. **Services healthy:** repo root — `docker compose up -d && docker compose ps`; all three containers `healthy`.
3. **Routes registered:** `backend/` — `php artisan route:list --path=api/v1/categories` lists exactly five routes, each with `auth:sanctum` and `active` in its middleware column and **none** with `admin`.
4. **Reads are open, writes are not:** `backend/` — start `php artisan serve`, then with an **agent** token:

    ```bash
    curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $AGENT" http://localhost:8000/api/v1/categories
    curl -s -o /dev/null -w '%{http_code}\n' -X POST -H "Authorization: Bearer $AGENT" \
      -H 'Content-Type: application/json' -H 'Accept: application/json' \
      -d '{"name":"Nope"}' http://localhost:8000/api/v1/categories
    ```

    Expect `200` then `403`. A `403` on the first means the policy's `viewAny` is wrong; a `201` on the second means it is missing entirely.
5. **The list shape is what TM-19 will consume:** `backend/` — `curl -s -H "Authorization: Bearer $ADMIN" http://localhost:8000/api/v1/categories | head -c 400`. The body starts `{"data":[` and the response contains **no `"meta"` and no `"links"`**. If it does, `paginate()` crept in and TM-19 will have to walk pages.
6. **The slug is derived, and the two failure modes are caught:** `backend/` —

    ```bash
    curl -s -X POST -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' \
      -H 'Accept: application/json' -d '{"name":"Field Ops & Logistics"}' \
      http://localhost:8000/api/v1/categories | python3 -m json.tool
    ```

    `201`, with `"slug": "field-ops-logistics"`. Then confirm both refusals return `422` on `name`, not `500`:

    ```bash
    for NAME in '北京' '🌐' '!!!'; do
      curl -s -o /dev/null -w "$NAME -> %{http_code}\n" -X POST -H "Authorization: Bearer $ADMIN" \
        -H 'Content-Type: application/json' -H 'Accept: application/json' \
        -d "{\"name\":\"$NAME\"}" http://localhost:8000/api/v1/categories
    done
    ```

    All three print `422`. **A `500` here is the empty-slug bug** and means `slugRule()` is missing or not wired into `rules()`.
7. **Renaming does not move the slug:** `backend/` — PATCH a category's `name`, then confirm its `slug` is unchanged and the seeder still matches it:

    ```bash
    php artisan db:seed --class=Database\\Seeders\\CategorySeeder
    php artisan tinker --execute="echo App\Models\Category::count();"
    ```

    Still `7` (six seeded plus the one created in step 6), not `8`. **A count that grew means the slug moved** and `CategorySeeder` created a duplicate.
8. **Colour is normalised:** `backend/` — PATCH `{"color":"#ef4444"}` and confirm the response carries `#EF4444`. Then confirm `red`, `#EEE` and `EF4444` each return `422`.
9. **Delete is a soft delete:** `backend/` — `DELETE` a category, expect `204`; then `php artisan tinker --execute="echo App\Models\Category::count(), ' ', App\Models\Category::withTrashed()->count();"` shows the live count one lower and the trashed count unchanged. A second `DELETE` on the same id returns `404`.
10. **Backend tests:** `backend/` — `composer test` reports **166 tests** with **165 passing** and only TM-14's `PasswordThrottleTest` red. **A failure in `RouteAuthorizationTest` means task 7 was skipped** — that is the expected first failure if the manifest was not extended.
11. **Single class, for a fast loop:** `backend/` — `php artisan test --filter=CategoryStoreTest` exits `0`.
12. **Style:** `backend/` — `./vendor/bin/pint --test` exits `0` with no reformatting.
13. **Frontend type-checks:** `frontend/` — `npx vue-tsc -b` exits `0`. `tsconfig.app.json` resolves to `strict` with `noUnusedLocals`, so an unused import in a new `.vue` file fails the build, not just the lint.
14. **Frontend tests and lint:** `frontend/` — `npm test` reports **66 tests across 12 files**; `npm run lint` exits `0` with zero warnings (`--max-warnings 0`); `npm run format:check` exits `0`.
15. **The screen works against the real API:** repo root — `docker compose up -d`, `php artisan serve` in `backend/`, `npm run dev` in `frontend/`. Sign in as the seeded admin, open `http://localhost:5173/admin/categories`, and confirm: six rows with coloured badges; editing a `sort_order` and tabbing out reorders the list; the toggle flips a row to Inactive and it **stays visible**; creating "Field Ops & Logistics" shows the derived slug in the edit dialog; creating "Hardware" again shows the duplicate-name message under the name field.
16. **An agent cannot reach the screen:** sign in as a `->agent()` user and navigate to `/admin/categories` — the guard redirects to `/forbidden`. Then confirm the agent's session can still *read* categories by watching the network tab on a page that lists them, or with the curl in step 4.
17. **Regression — nothing outside the story moved:** repo root — `git status --short` shows no change to `docker-compose.yml`, `README.md`, `CLAUDE.md`, `docs/erd.md`, `backend/bootstrap/app.php`, `backend/.env.example`, `backend/app/Models/User.php`, or any `backend/database/migrations/` file. The only edited files are `backend/routes/api.php`, `backend/app/Models/Category.php`, `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`, `docs/api-contract.md`, `frontend/src/router/index.ts` and `frontend/src/App.vue`.

---

## Done Criteria

- [ ] Five routes exist at `/api/v1/categories` (`index`, `store`, `show`, `update`, `destroy`), inside the `['auth:sanctum','active']` group and **outside** the `admin` group, with no `/api/v1` written into any path.
- [ ] `CategoryPolicy` allows `viewAny`/`view` for both roles and restricts `create`/`update`/`delete` to admins; `Category` carries `#[UsePolicy(CategoryPolicy::class)]`, and a test asserts `Gate::getPolicyFor(Category::class)` resolves it.
- [ ] An agent gets `200` from the list and `403` from every write; a deactivated admin gets `401`; an unknown id returns **`404` to agents and admins alike**, pinned by a test.
- [ ] `GET /api/v1/categories` returns **every** category including deactivated ones, ordered by `sort_order` then `name`, wrapped in `data` with **no `meta` and no `links`**; `?status=active|inactive` narrows it and `?status=bogus` is a `422`.
- [ ] `slug` is derived with `Str::slug()` on create, is **never** regenerated on rename, and is rejected with a `422` when supplied by a client on either request.
- [ ] A name that produces an **empty** slug (`北京`, `🌐`, `!!!`, `---`) and a name that produces a **duplicate** slug (`Hardware/Software` vs `HardwareSoftware`) both return `422` on `name` — never a 500. A name or slug held by a **soft-deleted** category also returns `422`.
- [ ] `color` accepts only `#RRGGBB`, is **uppercased before storage**, and defaults to `#6B7280` when omitted. `sort_order` is capped at `65535` in validation, not by a `QueryException`.
- [ ] `DELETE` soft-deletes and returns `204`; the row vanishes from the list and from `show`, and a second delete is `404`. The plan's note that **TM-18 must wrap, not replace, `destroy()`** is recorded in the overview.
- [ ] `RouteAuthorizationTest::ACCESS` classifies all five new routes under the new `staff` and `admin-policy` levels, `routesFor()` builds category URIs, and three new tests plus the extended unauthenticated test pass. `test_admin_routes_have_three_middlewares` and `test_unknown_id_agent_forbidden_admin_not_found` pass **unchanged**.
- [ ] `frontend/src/api/categories.ts` returns `Category[]` (not `Paginated<Category>`), and `frontend/src/stores/categories.ts` mirrors `stores/users.ts` including the `latestRequest` stale-response counter and re-throwing writes, and exposes `activeCategories` and `byId`.
- [ ] `CategoryBadge.vue` renders any category with its colour and **readable** text in both themes, renders deactivated categories rather than hiding them, and takes a `Pick<Category, …>` so TM-22 can use it.
- [ ] `/admin/categories` is an admin-only route; the screen shows a badge per row, a `change`-committed `sort_order` number input, an inline activate/deactivate toggle, a create/edit dialog showing the read-only slug, and a confirmed delete. **No drag-and-drop and no new npm dependency** — `frontend/package.json` dependencies stay `axios`, `pinia`, `vue`, `vue-router`.
- [ ] `docs/api-contract.md` has the five endpoint rows, a `### /api/v1/categories` section, the corrected pagination convention, and the `CategoryPolicy` paragraph replacing the "arrives in TM-17" placeholder. `docs/erd.md` is **not** edited.
- [ ] `composer test` reports **166 tests / 165 passing** with only TM-14's `PasswordThrottleTest` red; `./vendor/bin/pint --test` exits `0`; `npm test` reports **66 tests**; `npm run lint`, `npm run format:check` and `npx vue-tsc -b` all exit `0`.
- [ ] The overview records the three hand-offs: **TM-18 wraps `destroy()`** (and that a soft delete never trips TM-21's `ON DELETE RESTRICT`), **TM-19 wraps this store rather than replacing it**, and **TM-22 reads `activeCategories`, not `categories`**, for the new-ticket dropdown.
- [ ] TM-12's missing frontend specs are still missing — this story neither wrote them nor copied the omission for its own files.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 15 (TM-18).**
