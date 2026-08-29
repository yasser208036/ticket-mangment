# Story 11 — Role-based authorization via policies (Story: TM-13)

## Prerequisites

**Read this section before anything else. The working tree is not where `00-overview.md` says it is.**

- **Stories 06–10 (TM-8 … TM-12) are all present in application sources.** Verified by reading the tree today: `app/Enums/UserRole.php`, `app/Models/User.php` with `#[Fillable]` / `casts()` / `isAdmin()` / `scopeActive()`, `app/Http/Controllers/Api/V1/Auth/{Login,Logout,Me}Controller.php`, `app/Http/Controllers/Api/V1/Admin/UserController.php`, `app/Http/Requests/Api/V1/Admin/{Store,Update}UserRequest.php`, `app/Http/Middleware/{EnsureUserIsActive,EnsureUserIsAdmin}.php`, `app/Http/Resources/V1/UserResource.php`, and the nested route group at `routes/api.php:26-36`.
- **But Story 10 (TM-12) shipped its sources without its tests, and this story has to work around that.** Measured:
  - `backend/tests/Feature/Admin/` **does not exist**. None of TM-12's five planned test files were written.
  - `backend/tests/Feature/Auth/ProtectedRouteTest.php` is still TM-10's 36-line version — its route-table test at lines 26–36 still filters on `str_starts_with($route->getName(), 'auth.')`, so **the four `admin.users.*` routes are not covered by any test at all.** TM-12's task 7 (broaden it) was not applied.
  - `docs/api-contract.md` is 66 lines. The four `admin/users` rows are in the endpoint table (lines 26–29), but the per-endpoint detail sections TM-12's task 8 specified were never added — the file's last section is `### GET /api/v1/health` at line 52.
  - `frontend/` has `src/api/{errors,pagination,users}.ts`, `src/stores/users.ts`, `src/views/AdminUsersView.vue` and `src/components/UserFormDialog.vue`, and **no spec for any of them**.
- **Measured baselines, taken today with the containers healthy.** Do not trust a plan's arithmetic over your own run:
  - `backend/` — `php artisan test` → **62 passed, 151 assertions, 1.8s**.
  - `frontend/` — `npm run test` → **23 passed across 5 files**.
  - `docker compose ps` → `tm-mysql`, `tm-mysql-test`, `tm-mailpit` all **healthy**. `PDO::getAvailableDrivers()` returns `['mysql', 'pgsql']`, so TM-8's `pdo_mysql` blocker is gone.
- **This story absorbs exactly one piece of TM-12's test debt and no more.** TM-12's test plan item 5 (`tests/Feature/Admin/AdminAuthorizationTest.php`) and its task 7 (broadening `ProtectedRouteTest`) are *this story's* acceptance criterion 3. Writing both would produce two files asserting the same thing against the same four routes. **Do not create `tests/Feature/Admin/AdminAuthorizationTest.php`, and do not edit `ProtectedRouteTest.php`** — task 8 below supersedes both, and `00-overview.md` records the transfer. TM-12's other four test files (`UserIndexTest`, `UserStoreTest`, `UserUpdateTest`, `UserLockoutTest`) and its four contract sections stay TM-12's debt and are **out of scope here**.
- **No coordination needed with another owner.** `CategoryPolicy` and `TicketPolicy` cannot be written yet; see the first Product rule for what is handed to TM-17 and TM-22 instead.

---

## Story Goal

Move the "who may do what" decision out of a route prefix and into a policy class, so that the answer is the same whichever route asks and a forgotten check fails a test rather than opening a hole.

1. **`App\Policies\UserPolicy` is the single statement of who may act on a staff account** — `viewAny`, `view`, `create`, `update`, `delete` — bound to `App\Models\User` and reading the `UserRole` enum through `User::isAdmin()`, never a string literal.
2. **Every controller action under `App\Http\Controllers\Api\V1` either calls the policy or is recorded as deliberately public/self-scoped**, with the record living in a test rather than a comment.
3. **An agent calling any admin endpoint gets `403`** — including `GET /api/v1/admin/users/999999`, which today returns **`404`**. That is a real, measured ordering bug and closing it is part of this story, not a nicety.
4. **A route-manifest test fails when a new endpoint is added without classifying who may call it.** This is what makes acceptance criterion 2 hold for TM-17, TM-22 and everything after, instead of being true only on the day it was written.

**Not in scope.** `CategoryPolicy` and `TicketPolicy` (no `categories` or `tickets` table exists — see Product rules). TM-14's `PATCH /auth/password`. A `DELETE /admin/users/{user}` route. Any frontend change (task 9 states the evidence). TM-12's four unwritten CRUD test files.

---

## Product rules (from story)

### Two of the three policies named in the story have no model to attach to

Acceptance criterion 1 asks for `UserPolicy`, `CategoryPolicy` and `TicketPolicy`. Only one of them can be written today.

`backend/database/migrations/` holds `0001_01_01_000000_create_users_table.php`, `create_cache_table`, `create_jobs_table` and `create_personal_access_tokens_table` — no `categories`, no `tickets`. `app/Models/` holds `User.php` and nothing else. `tools/jira/backlog.json` puts the master-data schema in **TM-16** (E3-S1) and the tickets schema in **TM-21** (E4-S1), both **sprint 2**; this story is sprint 1.

A policy class with no model is not a head start, it is a decoy:

- `Gate::getPolicyFor()` resolves policies **from the model** — either the `#[UsePolicy]` attribute (`vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:692-702`) or a name guessed from the model's namespace (`Gate.php:711-730`). With no `App\Models\Category`, nothing can ever reach `App\Policies\CategoryPolicy`.
- Its methods cannot take the parameter they exist for. `view(User $user, Category $category)` does not compile against a missing class, so the file would have to be written as `view(User $user)` — a **different signature** from the one TM-17 needs, which means TM-17 rewrites the class rather than filling it in.
- Nothing can test it beyond `new CategoryPolicy` and calling a method that takes no model, which asserts that `true` is `true`.

So this story ships `UserPolicy` for real and hands the other two forward with the exact code to write. **TM-17 (admin CRUD for categories) owes:**

1. `app/Policies/CategoryPolicy.php` — `viewAny`, `view`, `create`, `update`, `delete`, same shape as task 2's `UserPolicy`. Categories are read by agents (they classify tickets) and written by admins, so `viewAny` and `view` return `true` for any live account and the other three return `$user->isAdmin()`.
2. `app/Models/Category.php` — `#[UsePolicy(CategoryPolicy::class)]`, exactly as task 3 does for `User`.
3. `$this->authorize(...)` as the first statement of every action in the category controller, exactly as task 4 does.
4. One line added to `RouteAuthorizationTest::ACCESS` per new route. **Task 8's completeness test fails until this is done** — that is the whole point of it.

**TM-22 (create a ticket) owes the same four steps for `TicketPolicy`**, with the difference that tickets are per-record: an agent may `view` and `update` a ticket assigned to them, and TM-31's assignment column is what the policy reads. That belongs in TM-22's plan, not guessed at here.

**Do not ship empty policy classes to make the acceptance criterion look satisfied.** It is the same mistake TM-12's plan refused when it declined to ship a `tickets_count` column of zeros: a file named `CategoryPolicy.php` reads as "categories are protected", and the first person to notice will already have shipped an open endpoint next to it.

### The ordering bug this acceptance criterion actually exposes

Acceptance criterion 3 — *"an agent calling an admin-only endpoint receives 403"* — is **false today for one of the four endpoints**, and taking the criterion literally is what surfaces it.

Measured against a live `php artisan serve`, with a real agent token and a real admin token:

```
AGENT  GET    /api/v1/admin/users            403  This action is unauthorized.
AGENT  GET    /api/v1/admin/users/3          403  This action is unauthorized.
AGENT  PATCH  /api/v1/admin/users/3          403  This action is unauthorized.
AGENT  GET    /api/v1/admin/users/999999     404  No query results for model [App\Models\User] 999999
ADMIN  GET    /api/v1/admin/users/999999     404  No query results for model [App\Models\User] 999999
```

An agent gets **`404` for an id that does not exist** and **`403` for one that does**. That difference is a user-id enumeration oracle, readable by any agent with a valid token, over a table of staff accounts.

The cause is middleware ordering, and it is not a bug in this project's code. `SubstituteBindings` is a member of the framework's `api` group (`vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:495-499`), and route middleware run after group middleware unless the priority list hoists them. `Illuminate\Foundation\Http\Kernel::$middlewarePriority` (`Foundation/Http/Kernel.php:103-115`) lists `AuthenticatesRequests` at line 109 and `SubstituteBindings` at line 113 — which is exactly why an **unauthenticated** caller hitting `/admin/users/999999` correctly gets `401` and not `404`. `EnsureUserIsActive` and `EnsureUserIsAdmin` are custom classes and are **not in that list**, so they sort after the binding and never get asked.

Note what else is in the list: `Illuminate\Auth\Middleware\Authorize` — the `can` alias — sits at line 114, deliberately **after** `SubstituteBindings`, because `can:view,user` needs the model resolved. Our two middlewares need the opposite, because they answer a question that has nothing to do with which record was addressed.

Task 6 hoists both above `SubstituteBindings`. **Do not "fix" this by making the policy return `Response::denyAsNotFound()`** — `Illuminate\Auth\Access\Response::denyAsNotFound()` exists (`Auth/Access/Response.php:96`) and would close the oracle from the other side by turning every refusal into a `404`, but it does so by making acceptance criterion 3 false on purpose. `403` is the required answer; the ordering is what has to change.

### `Gate::before` for admins is the trap, not the shortcut

The idiomatic-looking one-liner is `Gate::before(fn (User $user) => $user->isAdmin() ? true : null)` in `AppServiceProvider::boot()`: admins can do everything, every policy gets shorter.

**Do not add it.** Two concrete consequences:

- It makes `UserPolicy::delete()` return `true` for admins. `Gate::before` short-circuits `raw()` before the policy method is consulted, so the "deactivate, never delete" rule TM-12 spent a Product-rules section establishing would be silently reversed by a line in a service provider, in a file no one reads when reviewing a policy. Task 2's `delete` returning `false` is load-bearing.
- Every future policy's admin branch becomes invisible. `TicketPolicy::update` would read as "the assignee may update" with the admin case living two directories away, and the first person to write a per-record admin exception (an admin who may *not* reopen a closed ticket, say) has to find and unpick a global rule.

Five explicit `$user->isAdmin()` returns are three more lines than the `before` hook and they are three lines a reviewer can read in the file the rule belongs to.

### Policies do not replace `EnsureUserIsAdmin`

TM-12's plan already committed to this (`10-story-…-TM-12.md`, "Authorization before policies exist"), and the middleware's own docblock says so at `app/Http/Middleware/EnsureUserIsAdmin.php:12-21`. Confirming it from the other side, now that the policies are real:

| Layer | Question | Failure | Scope |
|---|---|---|---|
| `auth:sanctum` | Is there a valid token? | `401` | every non-public route |
| `active` | Is the account still live? | `401`, tokens revoked | every non-public route |
| `admin` | Is this an administrator? | `403` | the `/admin/*` prefix, as a blanket |
| `UserPolicy` | May *this* user do *this* to *that* record? | `403` | every action that touches a `User` |

The middleware stays because it is the **backstop for a forgotten `authorize()` call**. Delete it and a new action added to `Admin\UserController` without a policy call is an open admin endpoint that every existing test still passes. Keep it and that same mistake is a `403` for agents — wrong for the wrong reason, but not a hole.

The cost is that on the four `/admin/users` routes the policy is currently **redundant**: the middleware refuses the agent before the controller body runs, so `UserPolicy::viewAny`'s `false` branch is never reached over HTTP. That is stated plainly rather than hidden, and it is why task 8's `403` tests are *middleware* tests in practice while task 7's policy tests are what actually pin the rules. Both are needed; neither substitutes for the other.

### `delete` denies everyone, and that is the rule not a placeholder

TM-12 established "deactivate, never delete": there is no `destroy` route, and `backend/phpunit.xml:27-35` records that the planned schema uses `ON DELETE RESTRICT`, so a delete that works today starts throwing a driver-level `QueryException` the moment TM-21 adds `tickets.created_by`.

`UserPolicy::delete()` therefore returns `false` for everyone — admins included, and admins on their own record included. Acceptance criterion 1 asks the policy to *define* who may delete; the definition is "nobody", and it is a decision with a reason, not an unimplemented method.

The value is in what happens next. Someone will eventually add `Route::delete('/users/{user}', …)` and `destroy()`. With `delete` returning `false` and task 4's `$this->authorize('delete', $user)` in the new action, their first test run is a `403` they have to come here and change deliberately — which is the conversation about reassigning or anonymising a departing agent's tickets that TM-12 said belongs in its own story.

### `authorizeResource()` cannot be used in this skeleton — measured

The obvious way to authorize a four-action controller is one line in the constructor: `$this->authorizeResource(User::class, 'user')`. It maps `index`→`viewAny`, `show`→`view`, `store`→`create`, `update`→`update` (`Foundation/Auth/Access/AuthorizesRequests.php:112-125`) and would satisfy acceptance criterion 2 in a single statement.

It throws. `authorizeResource()` is implemented by registering controller middleware — `$this->middleware("can:{$ability},{$modelName}")` at `AuthorizesRequests.php:87-105` — and the Laravel 13 slim skeleton's base controller has no `middleware()` method. Verified, not assumed:

```
php artisan tinker --execute="echo var_export(method_exists(App\Http\Controllers\Api\V1\Admin\UserController::class, 'middleware'), true);"
=> false
```

`app/Http/Controllers/Controller.php` is eight lines and extends nothing (`Controller.php:5-8`); controller middleware in Laravel 11+ comes from implementing `Illuminate\Routing\Controller\HasMiddleware` with a `static middleware()` method, which `authorizeResource` does not use. Calling it produces `Error: Call to undefined method App\Http\Controllers\Api\V1\Admin\UserController::middleware()` — a `500` on the first request to the controller, not a startup failure.

So task 4 writes four explicit `$this->authorize(...)` calls. That is more typing and it is also more honest: the ability each action needs is visible in the action.

### Authorization runs after validation, and the one place that matters

Route middleware → route model binding → **FormRequest validation** → controller body. `$this->authorize()` at the top of a controller action therefore runs **after** the FormRequest has validated the payload.

On the four routes this story touches, that is harmless: `admin` has already refused the agent with a `403` before `StoreUserRequest` is constructed, so nothing leaks. Confirmed above — `AGENT POST /api/v1/admin/users` with an empty body returns `403`, not the `422` naming `name`, `email`, `password` and `role`.

It stops being harmless the moment an authorization-bearing route sits **outside** the `admin` prefix — which is TM-14, next in this feature, with `PATCH /auth/password`. There, a caller who fails the policy would receive a `422` describing the password rules before ever being told no.

**The rule for later stories, recorded here because this is the story that owns authorization:** an endpoint behind the `admin` middleware puts `$this->authorize()` first in the controller action. An endpoint *not* behind it puts the policy call in the FormRequest's `authorize()` method, which runs before `rules()`. Do not mix the two on one route — one place per endpoint, and task 8's manifest is where you record which.

---

## Context — Read These Files First

1. `backend/app/Models/User.php` — all 48 lines, and **line 38-41 is the method the whole policy is built on**:

    ```php
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
    ```

    That is the enum comparison acceptance criterion 4 asks for, with a name. `casts()` (28–36) maps `role` to `UserRole::class`, so `$user->role` is **always** a `UserRole` instance and never a string — there is no code path where a raw-string comparison would even work. `#[Fillable]` is line 16 and `#[Hidden]` line 17: **task 3 adds a third class attribute next to them**, which is why the attribute form is the right one here.
2. `backend/app/Enums/UserRole.php` — all 15 lines. `Admin = 'admin'` (7), `Agent = 'agent'` (8), `values()` (11–14). Task 2's policy does **not** import this file; it calls `isAdmin()`. See the note under task 2 for why that still satisfies acceptance criterion 4.
3. `backend/app/Http/Middleware/EnsureUserIsAdmin.php` — all 21 lines. Line 15 is `if (! $request->user()?->isAdmin())` and lines 12–21 are the docblock that predicted this story: *"TM-13 adds policies on top of this rather than replacing it."* Read it before task 6 — task 6 changes **when** this class runs, not what it does, and the file itself is untouched.
4. `backend/app/Http/Middleware/EnsureUserIsActive.php` — all 26 lines. Note lines 40–43: it revokes every token as a side effect of refusing. Task 6 hoists this class too, and that side effect is the reason — today a deactivated user hitting an unknown id gets a `404` and **keeps their tokens**, because this middleware never runs.
5. `backend/bootstrap/app.php` — all 30 lines. `apiPrefix: 'api/v1'` (18). Lines 20–25 are the `withMiddleware` closure; lines 21–24 are the `alias([...])` array with `active` and `admin`. **Task 6 adds two statements inside this closure and two imports at the top.**
6. `backend/routes/api.php` — all 36 lines. `health` (23) and `auth.login` (24–26) are top-level; lines 28–36 are the `['auth:sanctum', 'active']` group with the `admin`-gated sub-group at 30–35. **Read the four route names — `admin.users.index`, `admin.users.store`, `admin.users.show`, `admin.users.update` — task 8's manifest keys on them exactly.** No route changes in this story.
7. `backend/app/Http/Controllers/Controller.php` — all 8 lines. `abstract class Controller` with a `//` body, extending nothing. Task 1 adds one trait here. Verified today: `method_exists(App\Http\Controllers\Controller::class, 'authorize')` is **`false`**, so `$this->authorize()` anywhere in the codebase is a fatal `Error` until task 1 lands. Do task 1 first.
8. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — all 88 lines, and know the shape before editing: `index` 19–37 (note `$request->validate([...])` at **line 21**, which task 4's `authorize` call goes *above*), `store` 39–46, `show` 48–51, `update` 53–72, and the private `guardAgainstLockout` 74–87. **Line 77 is `$user->is($request->user())`** — the same `Model::is()` idiom task 2 uses, which is why task 2 uses it rather than inventing a comparison.
9. `backend/app/Http/Controllers/Api/V1/Auth/MeController.php` (all 18 lines) and `LogoutController.php` (all 17 lines) — both are `__invoke(Request $request)` acting on `$request->user()` and nothing else. Read them, then read task 5, which explains why neither gets a policy call and pins that decision in the manifest instead of a comment.
10. `backend/vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php:653-702` — `getPolicyFor()` and `getPolicyFromAttribute()`. Confirm for yourself that the `#[UsePolicy]` attribute is checked at 667–671, **before** the name guessing at 673–677, and that `getPolicyFromAttribute` (692–702) reflects for `Illuminate\Database\Eloquent\Attributes\UsePolicy`. Then read `guessPolicyName` (711–730) and satisfy yourself that `App\Models\User` would resolve `App\Policies\UserPolicy` anyway — task 3 explains why the attribute is still worth writing.
11. `backend/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:103-115` — `$middlewarePriority`, eleven entries. `AuthenticatesRequests` at 109, `SubstituteBindings` at 113, `Auth\Middleware\Authorize` at 114. **This is the list task 6 inserts into**, and the gap between 109 and 113 is the whole explanation for why unauthenticated callers get `401` on an unknown id while agents get `404`.
12. `backend/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:405-443` — `priority()`, `prependToPriorityList($before, $prepend)` and `appendToPriorityList()`. Note the argument order: **`$before` first, the new class second**, and that `prependPriority` is keyed by the *new* class (line 427), so two calls naming the same anchor both survive. `ApplicationBuilder.php:312-317` applies them via `Kernel::addToMiddlewarePriorityBefore`, which splices at the anchor's current index (`Kernel.php:485-510`) — so calling it for `active` and then `admin` yields `active, admin, SubstituteBindings`, in that order.
13. `backend/vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php:2175-2181` — `is()`. **`getKey() === $model->getKey()`, and two unsaved models both return `null`.** Task 7's tests must use `->create()`, not `->make()`, or `view($agentA, $agentB)` returns `true` and the suite certifies a hole. This is written up again in Edge Cases because it is the single most likely way to get this story wrong.
14. `backend/vendor/laravel/framework/src/Illuminate/Foundation/Console/stubs/policy.stub` — the framework's own policy shape: one method per ability, `bool` return, a one-line docblock each, and **`return false;` as the default body**. Task 2 matches the layout. Deny-by-default is the framework's opinion too, and it is why a *misspelled* policy method is invisible to a `403` test — see task 7's ability-name test.
15. `backend/tests/Feature/Auth/ActiveAccountTest.php` — all 54 lines, and **the test idiom to copy**. `use RefreshDatabase;`, a `private function tokenFor(User $user): string` helper at the bottom returning `$user->createToken('spa')->plainTextToken`, and `$this->withToken($token)->getJson(route('auth.me', absolute: false))`. Note lines 19–21: after changing a user's state mid-test it calls **`Auth::forgetGuards()`** before the next request, because the resolved guard caches the user.
16. `backend/tests/Feature/Auth/ProtectedRouteTest.php` — all 36 lines. Lines 28–35 iterate `Route::getRoutes()` and call `$route->gatherMiddleware()`; task 8 uses the same two APIs over the whole table instead of the `auth.` prefix. **This file is not edited** — see Prerequisites.
17. `backend/tests/Feature/Models/UserRoleAndStateTest.php` (45 lines) and `tests/Unit/Enums/UserRoleTest.php` (30 lines) — the naming and namespace convention: `Tests\Feature\<Area>` for anything that touches the database, `Tests\Unit\<Area>` for anything that does not, `test_it_…()` / `test_<subject>_<expectation>()`, and assertions written to fail on the near miss (`assertSame(UserRole::Admin, …)`, not `assertInstanceOf`).
18. **Grep before you write tests:** `grep -rn "Sanctum::actingAs" backend/tests/` returns **nothing**. Every existing test mints a real token and sends it as a bearer header. TM-12's plan suggested `Sanctum::actingAs()`; the repo did not adopt it. Follow the repo — tasks 7 and 8 use `createToken` + `withToken`, which also means the `active` middleware and the token guard are exercised rather than bypassed.
19. `backend/phpunit.xml:27-43` — the suite runs against `tm-mysql-test` on **3307**, and the comment at 27–35 says why. Nothing in this story changes it; the reminder is here because `RefreshDatabase` in a new `tests/Feature/Policies/` directory will fail confusingly if the container is down.
20. `docs/api-contract.md` — 66 lines. `## Conventions` is 7–16, `## Endpoints` and its table 18–29, then per-endpoint sections at 31, 38, 45, 52. **Task 10 inserts a new `## Authorization` section between line 16 and line 18.**
21. `frontend/src/api/errors.ts` — all 21 lines, and the evidence behind task 9's "no frontend changes required": lines 16–17 already map a `403` to `'You do not have permission to do that.'`, and `frontend/src/api/client.ts:28-34` reacts **only** to `401`, so a `403` propagates to the caller instead of forcing a sign-out. `frontend/src/stores/users.ts:35` funnels it into `error.value` via `errorMessage`.

---

## Backend Tasks

### 1 — Make `$this->authorize()` exist

**File: `backend/app/Http/Controllers/Controller.php`**

Eight lines become nine. This is the first task because every later `$this->authorize()` is a fatal `Error` without it.

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    use AuthorizesRequests;
}
```

- **The trait, not the facade.** `Gate::authorize('viewAny', User::class)` works identically (`Auth/Access/Gate.php:390`) and needs no base-class change, but it puts a facade import in every controller and reads as a call to a global. `AuthorizesRequests::authorize()` (`AuthorizesRequests.php:21-26`) delegates to the same gate. One line in the abstract base and every controller in the project — including TM-17's and TM-22's — inherits it.
- **Do not also add `authorizeResource()` to a constructor.** It is in the same trait and it throws in this skeleton; see the Product rule for the measurement.
- Nothing else in the file changes. It stays `abstract` and it still extends nothing.

### 2 — The policy

**Create file: `backend/app/Policies/UserPolicy.php`**

`app/Policies/` does not exist yet — this story creates it.

```php
<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may act on a staff account.
 *
 * Every branch reads User::isAdmin() (User.php:38-41), which is the UserRole
 * enum comparison with a name — there is no string literal in this file, and
 * casts() means $user->role is a UserRole instance on every code path.
 *
 * These rules are deliberately narrower than the /admin/* middleware in three
 * places (view allows self; update refuses self; delete refuses everyone), and
 * each of those is the durable statement of a rule the route prefix cannot say.
 */
class UserPolicy
{
    /**
     * Determine whether the user can list staff accounts.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can read a staff account.
     *
     * Admins read anyone; anybody may read themselves. The self branch is not
     * reachable through /admin/users/{user} — the `admin` middleware answers
     * first — and it is written anyway because it is the rule, and because
     * TM-14's PATCH /auth/password is the first non-admin route to need it.
     */
    public function view(User $user, User $target): bool
    {
        return $user->isAdmin() || $user->is($target);
    }

    /**
     * Determine whether the user can create staff accounts.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can edit a staff account.
     *
     * Admins only, including on their own record. There is no self-service
     * name/email endpoint — /auth/me is read-only — so "an agent may edit
     * themselves" would be a rule with no route, and TM-14 covers the one
     * thing an agent may change about themselves with its own confirmation
     * flow rather than through this ability.
     *
     * The self-lockout and last-admin rules are NOT here: they need
     * lockForUpdate() inside a transaction and live in
     * UserController::guardAgainstLockout (UserController.php:74-87). A policy
     * cannot hold a row lock across the write, and TM-12's plan spells out why.
     */
    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete a staff account.
     *
     * Nobody, ever. Accounts are deactivated (is_active = false), not deleted:
     * phpunit.xml:27-35 records that the schema uses ON DELETE RESTRICT, so
     * once TM-21 adds tickets.created_by a delete becomes a driver-level 500
     * from an endpoint an admin had learned to trust.
     *
     * This is a decision, not a stub. There is no DELETE route today, and the
     * story that adds one has to change this line on purpose — which is the
     * conversation about reassigning a departing agent's tickets first.
     */
    public function delete(User $user, User $target): bool
    {
        return false;
    }
}
```

- **`isAdmin()` rather than `$user->role === UserRole::Admin`.** Acceptance criterion 4 forbids comparing raw strings; `isAdmin()` *is* the enum comparison (`User.php:40`), it is pinned by `UserRoleAndStateTest.php:27-31`, and it is already what `EnsureUserIsAdmin.php:15` calls. Two spellings of "is an admin" is how they drift. The criterion is checkable as written: **`grep -rn "'admin'\|'agent'" backend/app/Policies/` must return nothing**, and it is in Done Criteria as exactly that.
- **`$user->is($target)`** is `UserController.php:77`'s idiom. Read `Model.php:2175-2181` first: it compares `getKey()`, and **two unsaved models both have `null` keys**, so this returns `true` for two different `->make()`d users. That is a test hazard, not a production one — over HTTP both models come from the database — and task 7 handles it by using `->create()`. An `$user->exists &&` guard is deliberately *not* added: it would be dead code on every real code path and it would diverge from line 77.
- **`delete()`'s `$target` is unused and stays in the signature.** `Gate` passes the model to any ability invoked with an instance; drop the parameter and `Gate::authorize('delete', $user)` throws an `ArgumentCountError`.
- **Five methods, not seven.** The framework stub also offers `restore` and `forceDelete`; `User` has no `SoftDeletes` trait, so both would be unreachable. TM-21's `Ticket` does use soft deletes and `TicketPolicy` will need them.
- **No `HandlesAuthorization` trait.** `Illuminate\Auth\Access\HandlesAuthorization` only supplies `allow()`/`deny()`/`denyWithStatus()`/`denyAsNotFound()` helpers for returning a `Response` instead of a `bool`. Nothing here needs a custom message or status: a plain `false` becomes `Response::deny()` with a `null` message, and `AuthorizationException` defaults that to **`'This action is unauthorized.'`** (`Auth/Access/AuthorizationException.php:33`) — byte-identical to what `EnsureUserIsAdmin` already produces, so tests can assert one string for both layers.

### 3 — Bind the policy to the model

**File: `backend/app/Models/User.php`**

Add the import and a third class attribute. The attribute goes **after** `#[Hidden]` (line 17) so the two mass-assignment declarations stay adjacent:

```php
use App\Policies\UserPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
```

```php
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
#[UsePolicy(UserPolicy::class)]
class User extends Authenticatable
```

Nothing else in the file changes — not `casts()`, not `isAdmin()`, not `scopeActive()`.

- **This binding is not strictly required, and write it anyway.** `Gate::guessPolicyName()` (`Gate.php:711-730`) maps a model in an `App\Models\` namespace to `App\Policies\<Model>Policy`, so `App\Policies\UserPolicy` would be found with no attribute at all. The attribute is worth three lines because (a) `User.php` already declares `#[Fillable]` and `#[Hidden]`, so this is the file's established way of saying things about itself, (b) `getPolicyFromAttribute` is consulted **before** the guesser (`Gate.php:667-671`), so a later move of either class keeps working, and (c) it is greppable — `grep -rn UsePolicy app/Models/` answers "which models are protected?" in one line, which is the question a reviewer of TM-17 and TM-22 will be asking.
- **Not `Gate::policy(User::class, UserPolicy::class)` in `AppServiceProvider::boot()`.** That works too and it puts the fact in a file that currently holds one rate limiter (`AppServiceProvider.php:25`). Registration lists rot; a class attribute cannot get out of sync with the class it is on.
- **Verify immediately, before task 4.** `php artisan tinker --execute="echo get_class(Illuminate\Support\Facades\Gate::getPolicyFor(App\Models\User::class));"` prints `App\Policies\UserPolicy`. Today, measured, the same command prints nothing because `getPolicyFor` returns `NULL`. If it still prints nothing after this task, the attribute is on the wrong class or the import is missing, and every task-4 call will `403` for everyone.

### 4 — Authorize every action in the admin controller

**File: `backend/app/Http/Controllers/Api/V1/Admin/UserController.php`**

One statement per action, as the **first** statement of the method body. Four edits, no other change to the file.

`index` (currently 19–37) — the call goes **above** the existing `$request->validate([...])` at line 21:

```php
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validate([
```

`store` (39–46):

```php
    public function store(StoreUserRequest $request): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = new User($request->safe()->only(['name', 'email', 'password', 'is_active']));
```

`show` (48–51):

```php
    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return UserResource::make($user)->response();
    }
```

`update` (53–72):

```php
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $deactivating = $request->has('is_active') && ! $request->boolean('is_active');
```

- **`User::class` for `viewAny` and `create`, the instance for `view` and `update`.** Abilities with no record take the class string; `Gate` resolves the policy from it either way (`Gate.php:653-661` accepts an object or a string). Passing `$user` to `viewAny` would call `viewAny(User $user, User $target)` and throw an `ArgumentCountError`.
- **First statement, not after validation.** On these four routes it makes no observable difference — `admin` has already returned `403` — but the ordering rule has to be uniform or the exception becomes the habit. See the Product rule for the FormRequest form TM-14 needs.
- **`guardAgainstLockout` (74–87) is untouched.** It stays a `ValidationException` inside `DB::transaction()` with `lockForUpdate()`. It is not a policy question: it is a concurrency invariant that needs a row lock, and TM-12's plan works through the two-admins-deactivating-each-other race that makes the lock load-bearing. Moving it into `UserPolicy::update` reopens that race, because a policy runs and returns before the transaction opens.
- **The `use App\Models\User;` import is already at line 10.** No new imports.

### 5 — The auth controllers get no policy call, and the reason is recorded in a test

**No change to `backend/app/Http/Controllers/Api/V1/Auth/MeController.php`, `LogoutController.php`, `LoginController.php`, or `backend/app/Http/Controllers/Api/V1/HealthController.php`.**

Acceptance criterion 2 says every controller action authorizes before acting. Work through what that means for each:

- **`HealthController`** — unauthenticated by design (`routes/api.php:23`), and TM-3 made it a deployment probe. There is nothing to authorize.
- **`LoginController`** — the caller has no identity yet. `LoginController.php:21-28` is the authorization: wrong email, wrong password and inactive account all reject with the same `422`.
- **`MeController`** — `MeController.php:15` is `UserResource::make($request->user())`. The subject *is* the caller. `$this->authorize('view', $request->user())` would evaluate `$user->is($user)`, which is `true` on every possible input. A check that cannot fail is worse than no check: the next reader sees a policy call and assumes a rule is being enforced.
- **`LogoutController`** — `LogoutController.php:11` deletes `$request->user()->currentAccessToken()`. Same argument, and the token is the caller's own by construction — Sanctum resolved it from the header on this request.

**So the record lives in task 8's manifest, not in a comment.** Each of these four routes is classified `public` or `self` in `RouteAuthorizationTest::ACCESS`, and the `self` entries carry a positive test that an *agent* reaches them — which is what proves the classification is a decision rather than an omission. If a later story makes `/auth/me` able to render somebody else's record, the manifest entry is the line that has to change.

### 6 — Stop route model binding pre-empting the refusal

**File: `backend/bootstrap/app.php`**

This is the fix for the measured `404`-vs-`403` oracle. Add two imports:

```php
use Illuminate\Routing\Middleware\SubstituteBindings;
```

(`EnsureUserIsActive` and `EnsureUserIsAdmin` are already imported at lines 3–4.)

Then two statements inside the existing `withMiddleware` closure, after the `alias([...])` array:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Route middleware run after the api group's SubstituteBindings
        // (Configuration/Middleware.php:495-499) unless the priority list
        // hoists them, and Kernel::$middlewarePriority:103-115 lists only the
        // framework's own. Measured consequence: an agent asking for
        // /admin/users/999999 got 404 while /admin/users/3 got 403 — a
        // staff-id oracle readable by anyone with a token. auth:sanctum is in
        // that list at line 109 for exactly this reason; these two belong
        // beside it. `active` first so a deactivated caller still gets 401 and
        // still has their tokens revoked.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsActive::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureUserIsAdmin::class);
    })
```

- **Argument order is `($before, $prepend)`** — the anchor first, the class being moved second (`Configuration/Middleware.php:425-431`). Reversed, it silently registers `SubstituteBindings` as needing to run before `EnsureUserIsActive`, which is already true, and the bug survives.
- **Two calls, not one, and in this order.** `prependPriority` is keyed by the *new* class (`Middleware.php:427`), so both entries persist; `ApplicationBuilder.php:312-317` replays them in insertion order through `Kernel::addToMiddlewarePriorityBefore`, which splices at the anchor's **current** index (`Kernel.php:485-510`). Registering `active` then `admin` therefore yields `… auth:sanctum … active, admin, SubstituteBindings, can`. Reverse the two lines and a deactivated admin asking for an unknown id gets `403` instead of `401` and keeps their tokens.
- **`priority()` is not called and must not be.** `Middleware::priority()` (405–415) **replaces** the whole list; `ApplicationBuilder.php:302-304` only calls `setMiddlewarePriority` when it is non-empty, and the prepends at 312–317 are applied to the framework default either way. Writing out an eleven-entry list here to insert two items means silently pinning the other nine against every future Laravel release.
- **`php artisan optimize:clear` after this change.** A cached bootstrap or route file will keep serving the old ordering and you will conclude the fix does not work.

### 7 — Policy tests

**Create file: `backend/tests/Feature/Policies/UserPolicyTest.php`**

`Tests\Feature\Policies`, not `Tests\Unit\Policies` — `$user->is($target)` needs real primary keys, which needs `RefreshDatabase`, and the repo puts anything database-backed under `Feature` (`tests/Feature/Models/UserRoleAndStateTest.php`).

Fourteen tests. The full list is in the Test Plan; the two that are easy to get wrong:

```php
    public function test_an_agent_cannot_view_another_agent(): void
    {
        // ->create(), never ->make(): Model::is() compares getKey(), and two
        // unsaved users both have a null key, so ->make() would make this
        // assertion pass by making the policy grant self-access to a stranger.
        $agent = User::factory()->agent()->create();
        $other = User::factory()->agent()->create();

        $this->assertFalse((new UserPolicy)->view($agent, $other));
    }

    public function test_the_gate_allows_the_abilities_the_controller_asks_for(): void
    {
        // Deny-by-default means a misspelled policy method is invisible to
        // every "an agent is refused" test — everything 403s and the suite is
        // green. These are the assertions that catch it, so they name the
        // ability strings exactly as task 4 writes them.
        $admin = User::factory()->admin()->create();
        $target = User::factory()->agent()->create();
        $gate = Gate::forUser($admin);

        $this->assertTrue($gate->allows('viewAny', User::class));
        $this->assertTrue($gate->allows('create', User::class));
        $this->assertTrue($gate->allows('view', $target));
        $this->assertTrue($gate->allows('update', $target));
        $this->assertTrue($gate->denies('delete', $target));
    }
```

### 8 — The route manifest, and the `403` per endpoint

**Create file: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

This file is acceptance criteria 2 and 3, and it is what makes them stay true. It supersedes TM-12's unwritten `tests/Feature/Admin/AdminAuthorizationTest.php` and its task 7 — **do not write either.**

```php
<?php

namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every route under api/v1 and the access level it must enforce.
     *
     *   public — unauthenticated by design.
     *   self   — any live account; the record is always the caller's own.
     *   admin  — an agent must be refused with 403.
     *
     * A new endpoint that is not listed here fails
     * test_every_api_route_is_classified, and that failure is the feature: it
     * makes the story adding the endpoint say, in one line, who may call it.
     * TM-17 and TM-22 each add rows here.
     */
    private const ACCESS = [
        'health' => 'public',
        'auth.login' => 'public',
        'auth.logout' => 'self',
        'auth.me' => 'self',
        'admin.users.index' => 'admin',
        'admin.users.store' => 'admin',
        'admin.users.show' => 'admin',
        'admin.users.update' => 'admin',
    ];
```

Helpers at the bottom of the class, matching `ActiveAccountTest.php:50-53`:

```php
    private function tokenFor(User $user): string
    {
        return $user->createToken('spa')->plainTextToken;
    }

    /** @return list<array{name: string, method: string, uri: string}> */
    private function routesFor(string $level, ?User $target = null): array
    {
        $routes = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ((self::ACCESS[$name] ?? null) !== $level) {
                continue;
            }

            $routes[] = [
                'name' => $name,
                'method' => $route->methods()[0],
                'uri' => route($name, ['user' => $target?->getKey() ?? 1], absolute: false),
            ];
        }

        return $routes;
    }
```

Ten tests. Points that decide whether they are worth having:

- **`route($name, ['user' => …])` ignores the extra parameter on routes that do not take one**, so one helper builds every URI. Verify that when you first run it: if `route('auth.me', ['user' => 1])` appends `?user=1`, switch to only passing the parameter when `$route->parameterNames()` contains `user`.
- **The negative controls are not optional.** A blanket-deny bug — a typo'd policy method, `Gate::before` returning `false`, the `admin` alias pointing at the wrong class — passes every "an agent gets 403" assertion. `test_an_admin_is_not_refused_by_any_admin_route` and `test_an_agent_reaches_every_self_route` are what fail.
- **The admin control asserts "not 403", not "200".** `POST /admin/users` with no body is a legitimate `422` for an admin, and `PATCH` with no body is a `200`. Asserting a specific status per route means encoding TM-12's validation rules in this file, which is not this file's job.

    ```php
    $this->assertNotSame(403, $response->status(), "admin was refused by {$route['name']}");
    ```
- **Every assertion carries the route name in its message.** Acceptance criterion 3 asks for a feature test per endpoint; a loop delivers that only if a failure says *which* endpoint. `assertSame(403, $response->status(), "route {$route['name']} did not refuse an agent")`.
- **`test_an_unknown_id_is_403_for_an_agent_and_404_for_an_admin`** is task 6's proof, and it is the one test in this story that fails today:

    ```php
    public function test_an_unknown_id_is_403_for_an_agent_and_404_for_an_admin(): void
    {
        $agent = User::factory()->agent()->create();
        $admin = User::factory()->admin()->create();

        $this->withToken($this->tokenFor($agent))
            ->getJson('/api/v1/admin/users/999999')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');

        $this->withToken($this->tokenFor($admin))
            ->getJson('/api/v1/admin/users/999999')
            ->assertNotFound();
    }
    ```

    The admin half is the control: it proves task 6 hoisted the *authorization* middleware without also hiding genuine `404`s from people entitled to see them.
- **`Auth::forgetGuards()` between two tokens in one test**, as `ActiveAccountTest.php:20` does. Without it the second `withToken` call is answered by the guard's cached user and the assertion tests nothing.

### 9 — No frontend changes required

**`No frontend changes required.`** Stated with the evidence, because "authorization" sounds like it should touch the SPA:

- **A `403` already renders correctly.** `frontend/src/api/errors.ts:16-17` maps it to `'You do not have permission to do that.'`, and `frontend/src/api/client.ts:28-34` reacts only to `401`, so a `403` propagates to the caller rather than forcing a sign-out. `frontend/src/stores/users.ts:33-37` puts it in `error.value`.
- **An agent never issues the request.** `frontend/src/router/guards.ts:35` sends a non-admin visiting a `meta: { role: 'admin' }` route to `/forbidden`, and `frontend/src/router/index.ts:29-34` is the only route carrying that meta.
- **The `404` this story fixes was never reachable from the SPA.** `AdminUsersView.vue` only ever requests ids that came back from `GET /admin/users`.

Do not add an abilities payload to `/auth/me`. TM-12's plan committed to *not* revoking tokens on demotion precisely because nothing caches abilities client-side; the day the SPA starts caching them, demotion becomes a security event and that trade needs its own story.

### 10 — The contract

**File: `docs/api-contract.md`**

Insert a new section between `## Conventions` (ends line 16) and `## Endpoints` (line 18). Keep the existing 80-column prose style.

```markdown
## Authorization

Four layers answer four different questions, in this order. The order is set in
`backend/bootstrap/app.php` — `active` and `admin` are hoisted above route model
binding, so an unknown id never pre-empts a refusal.

| Layer | Question | Failure |
|---|---|---|
| `auth:sanctum` | Is there a valid bearer token? | `401` `Unauthenticated.` |
| `active` | Is the account still live? | `401`, and every token revoked |
| `admin` | Is the caller an administrator? | `403` `This action is unauthorized.` |
| Policy | May this caller do this to this record? | `403` `This action is unauthorized.` |

- `401` means "sign in again"; `403` means "you are known and the answer is no".
  The SPA's axios interceptor reacts only to `401`, so a `403` renders inside
  the screen instead of forcing a sign-out.
- `App\Policies\UserPolicy` defines `viewAny`, `view`, `create`, `update` and
  `delete` for staff accounts. Admins may list, read, create and edit; anybody
  may read their own record; **nobody may delete** — accounts are deactivated
  with `is_active = false`.
- A `404` from `/api/v1/admin/users/{user}` means the id does not exist and the
  caller was entitled to be told so. An agent gets `403` for every id, present
  or absent.
- `CategoryPolicy` and `TicketPolicy` do not exist yet; TM-17 and TM-22 add them
  with the models they protect.
```

Also change the `Auth` column for the four `admin/users` rows (lines 26–29) from `admin bearer` to **`admin bearer (UserPolicy)`**, so the table points at the enforcement rather than implying a prefix is the rule.

**Do not add the four per-endpoint `### ` sections for `/admin/users`.** They are TM-12's task 8 and TM-12 owes them; writing them here means guessing at pagination and validation behaviour this story never exercised.

---

## Edge Cases & Failure Modes

- **`->make()` in a policy test grants self-access to a stranger.** `Model::is()` (`Model.php:2175-2181`) compares `getKey()`, and two unsaved models both return `null`, so `view($agentA, $agentB)` is **`true`** for `->make()`d users. The suite goes green certifying the opposite of the rule. Task 7 uses `->create()` everywhere and one test exists solely to pin this. It is not a production path — `$request->user()` and the route-bound target are both persisted — which is exactly why only a test can catch it.
- **A misspelled policy method is invisible.** `Gate` denies by default: no matching method means `raw()` returns null, `inspect()` wraps it as `Response::deny()` (`Gate.php:402`), and the caller gets `403`. So `viewany()` instead of `viewAny()` makes every "an agent is refused" assertion pass while locking admins out too. Verified today, before any policy existed: `Gate::forUser($agent)->authorize('viewAny', User::class)` threw `AuthorizationException` with message `'This action is unauthorized.'` and `hasStatus()` `false`. The **allow-path** assertions in task 7 and the admin negative control in task 8 are the only things that fail.
- **`$this->authorize()` before task 1 lands.** `method_exists(App\Http\Controllers\Controller::class, 'authorize')` is `false` today, so a task-4 call without task 1 is `Error: Call to undefined method` — a `500`, not a `403`. Do task 1 first, and if you see that error you skipped it.
- **`authorizeResource()` in the controller constructor.** Fatals with `Call to undefined method …::middleware()`, because `AuthorizesRequests.php:87-105` registers controller middleware and the slim skeleton's base controller has none. Measured. The four explicit calls are not verbosity for its own sake.
- **`Gate::before` added later "to tidy up the admin branches".** It short-circuits before the policy method runs, so `delete` starts returning `true` for admins and the "deactivate, never delete" rule reverses with no diff in `app/Policies/`. Task 7's `test_an_admin_cannot_delete_a_user` is the tripwire.
- **The two `prependToPriorityList` calls in the wrong order.** Register `admin` before `active` and a deactivated admin hitting an unknown id gets `403` instead of `401` — and, worse, `EnsureUserIsActive.php:40-43` never runs, so their tokens are not revoked. Task 8's `test_a_deactivated_admin_gets_401_not_403` fails.
- **`priority()` used instead of `prependToPriorityList()`.** It replaces the framework's eleven-entry list (`Middleware.php:405-415`) with whatever you wrote, pinning `EncryptCookies`, `StartSession` and the rest against every future release. Symptom: nothing at all today, then session or throttle middleware silently misordering after a `composer update`.
- **A cached bootstrap after task 6.** `php artisan optimize` or a stale `bootstrap/cache/` keeps serving the old middleware order, so the `404` persists and the fix looks broken. `php artisan optimize:clear` before verifying.
- **Route model binding with a non-numeric id.** `/api/v1/admin/users/abc` — `User::resolveRouteBinding` queries `id = 'abc'`, MySQL 8 coerces it to `0`, no row matches, `404`. After task 6 an agent gets `403` for that too, which is correct and worth one assertion rather than a separate discussion.
- **`route($name, ['user' => 1])` on a route with no `{user}`.** Laravel appends unknown parameters as a query string, so `auth.me` could come out as `/api/v1/auth/me?user=1`. Harmless for the assertions in task 8 (the route still matches), but it makes a failure message confusing. If you see it, gate the parameter on `$route->parameterNames()`.
- **A new route added with no name.** `self::ACCESS[$name] ?? null` on an unnamed route yields `null`, which is not a level, so `routesFor()` skips it and the completeness test's "every api/v1 route is classified" assertion fails on the empty name. That is the right outcome — every endpoint in this project is named, `routes/api.php:23-35` — but the failure message should say "route with no name at api/v1/…" rather than "'' is not classified".
- **A renamed route leaving a stale manifest entry.** `admin.users.show` renamed to `admin.users.read` would leave the old key matching nothing, and every loop that filters on it would iterate zero routes and pass vacuously. `test_every_classified_route_exists` asserts the reverse direction, and every loop asserts `assertNotEmpty` on what it collected.
- **`RefreshDatabase` with the containers down.** A new `tests/Feature/Policies/` directory fails with a PDO connection error that names port 3307, not a policy problem. `docker compose ps` first (`phpunit.xml:27-43`).
- **Two tokens in one test without `Auth::forgetGuards()`.** The resolved guard caches its user, so the second `withToken()` is answered as the first user and an "agent is refused" assertion silently tests the admin. `ActiveAccountTest.php:20` is the precedent.
- **`APP_DEBUG=true` fattens the `403` body.** The measured responses carried `exception`, `file` and a trace alongside `message`. Assert with `assertJsonPath('message', …)`, never `assertExactJson`, or the suite passes locally and fails in CI where debug is off.
- **An agent who is promoted mid-session.** Their existing token keeps working and the policy re-reads `$user->role` from the database on every request, so they gain admin access on their next call with no re-login. Deliberate, and the mirror of TM-12's decision not to revoke on demotion.
- **An admin deactivating themselves through a route that does not exist yet.** `UserPolicy::update` allows an admin to edit their own record, and `guardAgainstLockout` (`UserController.php:74-87`) is what refuses the dangerous subset. The policy is not the place for it: the rule needs `lockForUpdate()` across the write, and a policy returns before the transaction opens.

---

## Test Plan

`composer test` from `backend/`, against `tm-mysql-test` on 3307. **Measured baseline today: 62 tests, 151 assertions.** Re-run it before you start and use your own number.

### Unit / policy

1. **Create `backend/tests/Feature/Policies/UserPolicyTest.php`** — namespace `Tests\Feature\Policies`, `use RefreshDatabase;`. Instantiate the policy directly (`new UserPolicy`) except where noted. **Every user is `->create()`d, never `->make()`d** — see Edge Cases. Fourteen tests:
   - `test_the_gate_resolves_the_policy_from_the_model_attribute` — `assertInstanceOf(UserPolicy::class, Gate::getPolicyFor(User::class))`. Fails if task 3's attribute is missing or misspelled, which would otherwise present as "everything is 403".
   - `test_an_admin_can_view_any_user` / `test_an_agent_cannot_view_any_user` — `viewAny`.
   - `test_an_admin_can_view_another_user` — `view($admin, $agent)`.
   - `test_an_agent_can_view_their_own_record` — `view($agent, $agent->fresh())`. Use a re-read instance, not the same object, so the assertion goes through `getKey()` rather than object identity.
   - `test_an_agent_cannot_view_another_agent` — **the `Model::is()` trap.** Two distinct persisted agents.
   - `test_an_admin_can_create_users` / `test_an_agent_cannot_create_users`.
   - `test_an_admin_can_update_another_user` — including a second admin as the target, so "admins may edit admins" is stated.
   - `test_an_agent_cannot_update_another_user`.
   - `test_an_agent_cannot_update_their_own_record` — the deliberate narrowing. Without it, someone reads `view`'s self branch and adds one to `update` for symmetry.
   - `test_nobody_can_delete_a_user` — three assertions: admin on an agent, admin on themselves, agent on themselves. All `false`.
   - `test_a_deactivated_admin_still_passes_the_policy` — `viewAny($admin->fill(['is_active' => false]))` is **`true`**. The policy answers "what may this role do", not "is this account live"; `EnsureUserIsActive` owns the second question and returns `401` for it. Pins the separation so nobody adds an `is_active` clause to five policy methods.
   - `test_the_gate_allows_the_abilities_the_controller_asks_for` — the five ability **strings** task 4 passes, through `Gate::forUser()`. The misspelling catcher; see task 7 for the body.

### Feature / HTTP

2. **Create `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`** — namespace `Tests\Feature\Authorization`, `use RefreshDatabase;`, real tokens via `createToken` + `withToken` (**not** `Sanctum::actingAs` — `grep -rn "Sanctum::actingAs" tests/` returns nothing today). Ten tests:
   - `test_every_api_route_is_classified` — iterate `Route::getRoutes()`, keep routes whose `uri()` starts with `api/v1`, assert each has a non-empty name that is a key in `ACCESS`. `assertNotEmpty` on the collected set, and a failure message naming the unclassified URI. **This is the test TM-17 and TM-22 will hit.**
   - `test_every_classified_route_exists` — the reverse direction, so a rename cannot leave a stale key making every other loop iterate nothing.
   - `test_an_agent_is_refused_by_every_admin_route` — four endpoints, `403` each with `assertJsonPath('message', 'This action is unauthorized.')` and the route name in the assertion message. **Acceptance criterion 3.**
   - `test_an_admin_is_not_refused_by_any_admin_route` — the negative control, `assertNotSame(403, …)`.
   - `test_an_agent_reaches_every_self_route` — `auth.me` is `200`, `auth.logout` is `204`. Proves the `self` classification is a decision, and that no policy call crept into task 5's four controllers.
   - `test_an_unauthenticated_caller_is_refused_by_every_non_public_route` — `401` for every `self` and `admin` route, no token. Proves the admin group is nested inside `['auth:sanctum', 'active']` rather than beside it.
   - `test_every_public_route_needs_no_token` — `/health` is `200`; `POST /auth/login` with no body is `422`, **not** `401`.
   - `test_every_admin_route_carries_all_three_middlewares` — `gatherMiddleware()` contains `auth:sanctum`, `active` and `admin`, using `ProtectedRouteTest.php:28-35`'s API over the whole table.
   - `test_an_unknown_id_is_403_for_an_agent_and_404_for_an_admin` — **task 6's proof, and the one test here that fails against today's tree.** Add `/api/v1/admin/users/abc` as a third assertion for the non-numeric case.
   - `test_a_deactivated_admin_gets_401_not_403` — an `->admin()->inactive()` user with a real token gets `401` on `/admin/users` **and** on `/admin/users/999999`, and `$user->tokens()->count()` is `0` afterwards. Pins the hoist order from task 6 and the revocation side effect at `EnsureUserIsActive.php:40-43`.

3. **No test asserting a policy method's source text, and no test asserting `rules()` returns a particular array.** Acceptance criterion 4 is checked by `grep -rn "'admin'\|'agent'" app/Policies/` returning nothing — a one-line check in Done Criteria and in CI's `composer lint` step if you want it enforced — not by a test that pins the implementation.

4. **`tests/Feature/Auth/ProtectedRouteTest.php` is not edited and every one of its three tests must still pass.** Its route-table test at 26–35 stays scoped to `auth.*`; test 2.8 above covers the admin routes. If a `ProtectedRouteTest` assertion breaks, task 6 changed something about the `auth.*` routes that it should not have.

5. **All 62 existing tests must pass unchanged.** Task 4 adds authorization to actions no test currently reaches, and task 6 changes middleware ordering for every route in the application — that second one is the reason to re-run the whole suite rather than the new files.

**Backend total added: 24 tests** (14 + 10), taking the suite from **62** to **86**.

### Frontend

6. **No frontend tests added, and all 23 must pass unchanged.** Task 9 changes no frontend file. Run `npm run test` anyway as a regression check on the contract: `src/api/errors.ts`'s `403` mapping is what makes this story's refusals legible, and `src/router/guards.spec.ts` covers the `/forbidden` redirect that keeps an agent from ever issuing the request.

---

## Migration / Rollback

No schema change, no data change, no new dependency. Two behavioural changes are worth naming for a rollback:

- **Task 6 reorders middleware for every route in the application.** If something unrelated misbehaves after it — throttling, session, cookie handling — remove the two `prependToPriorityList` calls and `php artisan optimize:clear`; the app returns to today's behaviour, including the `404` oracle. Nothing else in this story depends on the ordering except `test_an_unknown_id_is_403_for_an_agent_and_404_for_an_admin` and `test_a_deactivated_admin_gets_401_not_403`.
- **A half-applied state to avoid: task 4 without task 1.** Every admin endpoint returns `500` (`Call to undefined method …::authorize()`), not `403`. Task 4 without **task 3** is worse and quieter: the policy exists, nothing binds it, `Gate` denies by default, and **admins get `403` from every admin endpoint** — the SPA renders "You do not have permission to do that." to the only person who does. Land tasks 1, 2 and 3 together, run the tinker check in task 3, then task 4.

---

## Verification Steps

Run in this order. The working directory is stated for every command.

1. **Prerequisites are real:** repo root — `docker compose ps` shows `tm-mysql`, `tm-mysql-test`, `tm-mailpit` **healthy**. `backend/` — `ls app/Http/Controllers/Api/V1/Admin/UserController.php app/Http/Middleware/EnsureUserIsAdmin.php` succeeds, and `php -r 'print_r(PDO::getAvailableDrivers());'` includes `mysql`. **If `app/Policies/` already exists, stop and read it** — someone started this story.
2. **Record the real baseline:** `backend/` — `php artisan test`; expect **62 passed**. `frontend/` — `npm run test`; expect **23 passed**. Write both numbers down.
3. **Reproduce the bug before fixing it.** `backend/` — `php artisan serve` in one shell, then in another:

    ```bash
    php artisan tinker --execute="\$a = App\Models\User::factory()->agent()->create(['email'=>'probe@x.test']); echo \$a->createToken('probe')->plainTextToken, PHP_EOL;"
    AGENT='<paste the token>'
    curl -s -o /dev/null -w 'known id:   %{http_code}\n' -H 'Accept: application/json' -H "Authorization: Bearer $AGENT" http://localhost:8000/api/v1/admin/users/1
    curl -s -o /dev/null -w 'unknown id: %{http_code}\n' -H 'Accept: application/json' -H "Authorization: Bearer $AGENT" http://localhost:8000/api/v1/admin/users/999999
    ```

    Expect **`403`** then **`404`**. That is the oracle. Clean up the probe user afterwards: `php artisan tinker --execute="App\Models\User::where('email','probe@x.test')->get()->each(fn (\$u) => tap(\$u->tokens())->delete() && \$u->forceDelete());"`
4. **Tasks 1–3, then prove the binding before writing a single `authorize()` call:** `backend/` —

    ```bash
    php artisan optimize:clear
    php artisan tinker --execute="echo get_class(Illuminate\Support\Facades\Gate::getPolicyFor(App\Models\User::class)), PHP_EOL;"
    ```

    Prints **`App\Policies\UserPolicy`**. Before task 3 the same command prints nothing, because `getPolicyFor` returns `NULL` — that is the measured "before".
5. **The policy answers correctly in isolation:** `backend/` —

    ```bash
    php artisan tinker --execute="
    \$g = Illuminate\Support\Facades\Gate::forUser(App\Models\User::factory()->agent()->make(['id'=>1]));
    var_dump(\$g->allows('viewAny', App\Models\User::class), \$g->allows('create', App\Models\User::class));
    \$a = Illuminate\Support\Facades\Gate::forUser(App\Models\User::factory()->admin()->make(['id'=>2]));
    var_dump(\$a->allows('viewAny', App\Models\User::class), \$a->denies('delete', App\Models\User::factory()->agent()->make(['id'=>3])));
    "
    ```

    Four `bool(true)`s except the first two, which are `bool(false)` and `bool(false)`. **If the admin's `viewAny` is `false`, you have a misspelled method name** — deny-by-default hides it everywhere else.
6. **Task 4 is complete, not partial:** `backend/` — `grep -c 'this->authorize' app/Http/Controllers/Api/V1/Admin/UserController.php` prints **`4`**, and `grep -n 'authorize' app/Http/Controllers/Api/V1/Admin/UserController.php` shows the calls at the top of `index`, `store`, `show` and `update` — above `$request->validate` in `index`.
7. **Task 6 landed and the oracle is closed:** `backend/` — `php artisan optimize:clear`, restart `php artisan serve`, then repeat step 3's two curls with a fresh agent token. **Both are now `403`.** Then with an **admin** token, `/admin/users/999999` is still **`404`** — if it is `403`, the hoist swallowed a legitimate not-found and admins can no longer tell a missing account from a forbidden one.
8. **An agent is refused everywhere, one endpoint at a time:** with the agent token, `GET /admin/users`, `POST /admin/users` (empty body), `GET /admin/users/1`, `PATCH /admin/users/1` (empty body). Every one is **`403`** with `"message": "This action is unauthorized."`. The `POST` in particular must **not** be a `422` — a `422` naming `name`, `email`, `password` and `role` means the FormRequest ran for an unauthorized caller.
9. **An admin still works:** log in as the seeded admin (`admin@ticket-management.test` / `password`) and call all four. `GET /admin/users` is `200` with `data`/`links`/`meta`; `POST` with no body is `422`; `GET /admin/users/1` is `200`. **None is `403`** — that is the check that catches an unbound policy, which would otherwise look like working security.
10. **There is still no delete route:** `curl -X DELETE` on `/api/v1/admin/users/1` as the **admin** returns **`405`**, and the user still exists.
11. **A deactivated admin is `401`, not `403`:** create a second admin, mint their token, deactivate them from the first admin's session, then replay their token against `/admin/users` and `/admin/users/999999`. Both **`401`**, and `php artisan tinker --execute="echo App\Models\User::where('email','<theirs>')->sole()->tokens()->count(), PHP_EOL;"` prints **`0`**. Restore with `php artisan migrate:fresh --seed`.
12. **Acceptance criterion 4, as a command:** `backend/` — `grep -rn "'admin'\|'agent'" app/Policies/` returns **nothing**, and `grep -rn 'isAdmin' app/Policies/UserPolicy.php` returns **four** hits (`viewAny`, `view`, `create`, `update`).
13. **`authorizeResource` was not used:** `backend/` — `grep -rn authorizeResource app/` returns nothing. If it is there, the controller `500`s on its first request.
14. **Backend gates:** `backend/` — `composer test` exits `0` with **86 tests** (62 + 24) and every pre-existing test still passing. `composer lint` (`vendor/bin/pint --test`) exits `0`.
15. **Frontend regression:** `frontend/` — `npm run format:check && npm run lint && npx vue-tsc -b && npm run test`, all exiting `0` with **23 tests** and **no file changed**.
16. **The screen still behaves:** repo root — `docker compose up -d`; `backend/` — `php artisan migrate:fresh --seed && php artisan serve`; `frontend/` — `npm run dev`. As the admin, http://localhost:5173/admin/users lists users and the create/edit dialog still saves. As an agent, the same URL redirects to **`/forbidden`** — the SPA never issues the request, which is the point of acceptance criterion 2 being about the server.
17. **Contract matches the code:** read `docs/api-contract.md`'s new `## Authorization` section against the statuses measured in steps 7–11, line by line, including the `404`-means-entitled sentence.
18. **Regression:** repo root — `git status --short` shows changes confined to `backend/app/Http/Controllers/Controller.php`, `backend/app/Http/Controllers/Api/V1/Admin/UserController.php`, `backend/app/Models/User.php`, `backend/app/Policies/UserPolicy.php`, `backend/bootstrap/app.php`, `backend/tests/Feature/Policies/`, `backend/tests/Feature/Authorization/`, `docs/api-contract.md`, and this feature's `.squad/plans/` files. **Nothing under `frontend/`, nothing in `routes/api.php`, `phpunit.xml`, `composer.json`, `composer.lock`, `database/`, `config/`, `docker-compose.yml`, `CLAUDE.md` or `docs/erd.md`.**

---

## Done Criteria

- [ ] `backend/app/Policies/UserPolicy.php` defines `viewAny`, `view`, `create`, `update` and `delete` with `bool` returns, and **contains no string literal `'admin'` or `'agent'`** — `grep -rn "'admin'\|'agent'" app/Policies/` returns nothing, and all four allow-branches call `User::isAdmin()` (`User.php:38-41`), which is the `UserRole` enum comparison with a name. **Acceptance criterion 4.**
- [ ] `view` allows an admin **or the target themselves**; `update` allows admins **only**, including on their own record; `delete` returns **`false` for everyone**, with the docblock recording that accounts are deactivated rather than deleted and that `ON DELETE RESTRICT` (`phpunit.xml:27-35`) is why. **Acceptance criterion 1**, for the one model that exists.
- [ ] `app/Models/User.php` carries `#[UsePolicy(UserPolicy::class)]` beside `#[Fillable]` and `#[Hidden]`, and `Gate::getPolicyFor(User::class)` returns a `UserPolicy` instance — pinned by a test, because an unbound policy denies **admins** and looks like working security.
- [ ] `app/Http/Controllers/Controller.php` uses `Illuminate\Foundation\Auth\Access\AuthorizesRequests`, and **`authorizeResource()` appears nowhere** — verified to fatal in this skeleton (`method_exists(UserController::class, 'middleware')` is `false`; `AuthorizesRequests.php:87-105`).
- [ ] All four actions in `Admin\UserController` call `$this->authorize(...)` as their **first statement** — `viewAny`/`create` with `User::class`, `view`/`update` with the bound instance — with `index`'s call above the `$request->validate([...])` at line 21. `grep -c 'this->authorize'` prints `4`. **Acceptance criterion 2.**
- [ ] `guardAgainstLockout` (`UserController.php:74-87`) is **unchanged** and still inside `DB::transaction()` with `lockForUpdate()`. The self-lockout and last-admin rules did not move into the policy, because a policy returns before the transaction opens and cannot hold the lock.
- [ ] `MeController`, `LogoutController`, `LoginController` and `HealthController` are **unchanged**, and the reason each needs no policy call is recorded as a `public` or `self` row in `RouteAuthorizationTest::ACCESS` with a positive test behind it — not as a comment.
- [ ] `bootstrap/app.php` hoists `EnsureUserIsActive` and then `EnsureUserIsAdmin` above `SubstituteBindings` via two `prependToPriorityList` calls, **in that order**, and does **not** call `priority()`. `routes/api.php` is untouched.
- [ ] **`GET /api/v1/admin/users/999999` as an agent returns `403`, not `404`** — measured at `404` before this story — while the same URL as an **admin** still returns `404`. A non-numeric id (`/admin/users/abc`) is also `403` for an agent. **Acceptance criterion 3, for the case that was actually broken.**
- [ ] A deactivated admin gets **`401`** (not `403`) from `/admin/users` **and** from `/admin/users/999999`, and their tokens are revoked — pinning both the hoist order and `EnsureUserIsActive.php:40-43`'s side effect.
- [ ] `tests/Feature/Authorization/RouteAuthorizationTest.php` exists with a `private const ACCESS` manifest covering all eight `api/v1` routes, and **fails when a route is added without a row** — plus the reverse assertion so a rename cannot leave a stale key making the loops pass vacuously. Every loop asserts `assertNotEmpty` and names the route in its failure message.
- [ ] The negative controls are present and passing: an **admin** is never `403` on an admin route, and an **agent** reaches every `self` route (`/auth/me` `200`, `/auth/logout` `204`). Without them a misspelled policy method, a bad alias or a `Gate::before` mistake passes every refusal test.
- [ ] `tests/Feature/Policies/UserPolicyTest.php` exists with 14 tests, all users `->create()`d — **never `->make()`d**, because `Model::is()` (`Model.php:2175-2181`) treats two unsaved models as the same record and would certify the opposite of the rule. One test asserts `Gate::forUser($admin)->allows(...)` for the five ability **strings** task 4 passes, which is the only thing that catches a typo under deny-by-default.
- [ ] One test asserts a **deactivated admin still passes the policy** — the policy answers "what may this role do", `EnsureUserIsActive` answers "is this account live", and neither takes the other's job.
- [ ] `tests/Feature/Auth/ProtectedRouteTest.php` is **not edited** and its three tests still pass; `tests/Feature/Admin/AdminAuthorizationTest.php` was **not created**. TM-12's task 7 and its test-plan item 5 are superseded here, and `00-overview.md` records the transfer.
- [ ] **No `Gate::before` hook anywhere.** `grep -rn 'Gate::before' app/` returns nothing; `AppServiceProvider::boot()` still holds only TM-9's rate limiter.
- [ ] `CategoryPolicy` and `TicketPolicy` were **not** shipped as empty classes, and the four steps each owes are recorded in this plan's first Product rule and in `00-overview.md` against **TM-17** and **TM-22** — the same discipline TM-12 applied when it refused to ship a `tickets_count` column of zeros.
- [ ] `docs/api-contract.md` gains an `## Authorization` section between `## Conventions` and `## Endpoints` documenting the four layers, `401` vs `403` vs `404`, `UserPolicy`'s five abilities, and the fact that the other two policies do not exist yet. The four `admin/users` rows say `admin bearer (UserPolicy)`. **TM-12's four per-endpoint sections are still absent and remain TM-12's debt.**
- [ ] `composer test` exits `0` with **86** tests (62 + 24) and no pre-existing test modified. `composer lint` exits `0`.
- [ ] **No frontend file changed**, and `npm run format:check`, `npm run lint`, `npx vue-tsc -b` and `npm run test` (23 tests) all exit `0`. No abilities payload was added to `/auth/me`.
- [ ] No new dependency on either side; `routes/api.php`, `phpunit.xml`, `composer.json`, `composer.lock`, `database/`, `config/`, `docker-compose.yml`, `docs/erd.md` and `CLAUDE.md` untouched.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 12 (TM-14).**
