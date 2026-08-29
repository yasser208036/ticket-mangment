# Story 10 — Admin manages agent accounts (Story: TM-12)

## Prerequisites

**Stories 06 through 09 (TM-8, TM-9, TM-10, TM-11) all landed while this plan was being written.** They are prerequisites in the ordinary sense — every one of them is verified below by reading the working tree, so nothing here is a blocker, but re-confirm before starting because a plan written against a moving tree can go stale.

- **Story 06 (TM-8) — done.** `app/Enums/UserRole.php` has `Admin = 'admin'`, `Agent = 'agent'` and `values()`; the users migration (lines 21–22) has `->enum('role', UserRole::values())->default(UserRole::Agent)` and `->boolean('is_active')->default(true)`; `app/Models/User.php:14` is `#[Fillable(['name', 'email', 'password', 'is_active'])]` with **`role` correctly absent**, `casts()` maps `role`→`UserRole` and `is_active`→`boolean`, and `isAdmin()` is at 36–39; `UserFactory` has `admin()`, `agent()` and `inactive()`. **This story is the first consumer of all of it.**
- **Story 07 (TM-9) — done.** `app/Http/Resources/V1/UserResource.php` exists and returns exactly `id`, `name`, `email`, `role` (as `->value`), `is_active`, `created_at`. `routes/api.php:22-24` is `POST /auth/login` with `throttle:login`. `App\Http\Requests\Api\V1\` and `App\Http\Controllers\Api\V1\Auth\` both exist, so tasks 3–5 extend namespaces rather than inventing them.
- **Story 08 (TM-10) — done.** `routes/api.php:26-29` is the group this story nests inside:

    ```php
    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::post('/auth/logout', LogoutController::class)->name('auth.logout');
        Route::get('/auth/me', MeController::class)->name('auth.me');
    });
    ```

    `bootstrap/app.php:19-21` registers the first alias — `$middleware->alias(['active' => EnsureUserIsActive::class]);` — and task 2 adds a second key to that same array. `app/Http/Middleware/EnsureUserIsActive.php` is the middleware idiom to copy.
- **Story 09 (TM-11) — done.** `frontend/src/router/index.ts:17` routes `/admin/users` as `admin-users` with `meta: { role: 'admin' }`, `frontend/src/views/AdminUsersView.vue` is the placeholder task 12 replaces, and `frontend/src/stores/auth.ts` plus `frontend/src/api/auth.ts` are what tasks 9–13 build on. **`frontend/src/router/index.ts` needs no change in this story.**
- **Story 05 (TM-6) has landed, so there are four static gates, not one.** Verified live: `frontend/eslint.config.js` and `frontend/.prettierrc.json` (`{"semi": false, "singleQuote": true}`, default 80 columns) exist, `frontend/package.json` has `lint`, `lint:fix`, `format`, `format:check`, and `backend/composer.json` has `lint` (`vendor/bin/pint --test`) and `lint:fix`. **Every code sample in this plan is written to pass Prettier with those settings.** Finish with `npm run format && npm run lint && npm run typecheck && npm run test`.
- **Docker services running:** repo root — `docker compose up -d`, all three healthy. The suite migrates against `127.0.0.1:3307` (`backend/phpunit.xml:36-41`).
- **Measure both baselines before you start.** A platform outage blocked running the suites at planning time, so the counts in the Test Plan are derived from reading files: backend **23** from TM-8's three test files plus the pre-existing 6, and TM-9/TM-10 add their own on top; frontend **11** measured before TM-11 landed, plus TM-11's 42. **Run `composer test` and `npm run test` and use your real numbers** — the *new* test counts in this plan are what matter, not the totals.
- **No new dependency on either side.** `backend/composer.json`, `composer.lock`, `frontend/package.json` and `package-lock.json` must all be unchanged. If a task seems to need a package, re-read it.

---

## Story Goal

The first CRUD screen in the product, and the first place an administrator's mistake can lock everyone out of it. Four endpoints under `/api/v1/admin/users`, one admin-only middleware, and a Users screen that replaces TM-11's placeholder.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | Users list shows name, email, role, active state and **ticket count**, paginated and searchable | ⚠️ **Deliverable except the ticket count.** `ls backend/database/migrations/` returns four files and **none of them creates a `tickets` table** — that is `TM-21` (E4-S1), two epics away. Name, email, role, active state, pagination and search all ship here. See "The ticket count cannot ship yet" below for the exact diff TM-21 owes. |
| 2 | Admin can create a user, choosing the admin or agent role, with validation on a unique email | ❌ **Not met**, and the role half is a trap: `role` is deliberately **not** in `#[Fillable]` (`User.php:14`), so `User::create($request->validated())` silently drops it and every new account becomes an agent. `AdminUserSeeder.php:26-32` is the in-repo idiom for doing it correctly. |
| 3 | Admin can edit a user's name, email and role, and toggle `is_active` | ❌ **Not met.** `routes/api.php` has four routes — `health`, `auth.login`, `auth.logout`, `auth.me` — and no controller under `Api/V1/Admin/`. Note what is **absent** from criterion 3's list: the password. See "What an admin cannot do" below. |
| 4 | Deactivating rather than deleting is the default, so ticket history keeps a valid author | ❌ **Not met**, and this story satisfies it by giving deactivation no competitor: **there is no `destroy` endpoint at all.** |
| 5 | An admin cannot deactivate or demote their own account, preventing lockout | ❌ **Not met**, and "cannot demote themselves" is not quite the invariant that matters. See "The rule that needs a row lock" — the self-check alone leaves a two-request race that empties the admin role entirely. |

Eight outcomes:

1. `GET /api/v1/admin/users` returns a paginated, searchable, status-filterable list of `UserResource`, in the `data` / `links` / `meta` envelope Laravel produces for a resource collection.
2. `POST /api/v1/admin/users` creates a user with an explicitly-assigned role and returns `201`.
3. `PATCH /api/v1/admin/users/{user}` edits name, email and role and toggles `is_active`.
4. Every one of those endpoints answers **`403`** for an agent, enforced by middleware rather than by the SPA hiding a button.
5. Deactivating a user **revokes their tokens**, closing the door TM-9 and TM-10 both flagged and left open.
6. No administrator can deactivate or demote themselves, **or** the last active admin — so the system always has at least one way in.
7. The Users screen lists, searches, filters, paginates, creates and edits, with field-level validation errors attached to the right inputs.
8. `docs/api-contract.md` gains four endpoints and the project's first documented pagination envelope.

**Not in scope:** the ticket count column (**TM-21** — see below); policies and per-record authorization, which refine but do not replace this story's middleware (**TM-13**); a user's own password change (**TM-14**); an admin-initiated password reset and any "invite by email" flow (**no story owns either** — recorded in Edge Cases); hard-deleting a user (**no story owns it**, deliberately); the demo seeder and richer factories (**TM-59**); API-wide throttling and token expiry (**TM-64**); and a component library — the screen uses plain elements and the custom properties already in `frontend/src/style.css`.

---

## Product rules

### The ticket count cannot ship yet

Acceptance criterion 1 asks for a ticket count per user. There is no `tickets` table: `backend/database/migrations/` holds `create_users_table`, `create_cache_table`, `create_jobs_table` and `create_personal_access_tokens_table`, and `tools/jira/backlog.json` puts the tickets schema in **TM-21** (E4-S1), two epics later. `withCount('tickets')` cannot be written against a relation whose table does not exist, and creating a stub `tickets` table here would collide head-on with TM-21's real schema — which owns the readable reference column, the FULLTEXT index and the `ON DELETE RESTRICT` foreign keys `backend/phpunit.xml:27-35` already explains the suite exists to test.

So this story ships the other four columns and **TM-21 (or whichever story first adds the `tickets` table) owes exactly this**:

1. `app/Models/User.php` — a `tickets()` (or `assignedTickets()`) `HasMany` relation.
2. `app/Http/Controllers/Api/V1/Admin/UserController::index` — add `->withCount('tickets')` to the query built in task 5.
3. `app/Http/Resources/V1/UserResource::toArray` — add `'tickets_count' => $this->whenCounted('tickets')`. `whenCounted` is the right call, not a bare `$this->tickets_count`: the field then appears only on the list endpoint that asked for it and stays absent from the login and `/auth/me` responses, which must not change shape.
4. `frontend/src/api/users.ts` — add `tickets_count?: number` to `AdminUser`; `frontend/src/views/AdminUsersView.vue` — one `<th>` and one `<td>`.

**Do not** ship the column returning `0` for everyone. A column of zeros in an administrative list is worse than an absent column: it reads as data, and the first person to notice will have already made a staffing decision on it.

### Authorization before policies exist

TM-13 owns `UserPolicy`, and it comes after this story. These endpoints still have to be admin-only today, so this story uses the pattern the codebase already established one story earlier: **a route middleware with an alias**, exactly like TM-10's `EnsureUserIsActive` / `active`.

`App\Http\Middleware\EnsureUserIsAdmin`, aliased `admin`, applied as `->middleware('admin')` on a group nested inside TM-10's `['auth:sanctum', 'active']` group. Three layers, each answering a different question, in the only order that works:

| Middleware | Question | Failure |
|---|---|---|
| `auth:sanctum` | Is there a valid token? | `401` |
| `active` (TM-10) | Does the account still exist as a live account? | `401`, tokens revoked |
| `admin` (this story) | Is this an administrator? | **`403`** |

**`403`, not `401`** — the caller is authenticated and known, and `frontend/src/api/client.ts` reacts only to `401`. A `403` therefore surfaces as a rendered error inside the screen rather than as a forced sign-out, which is correct: an agent who somehow reaches an admin endpoint has a permissions problem, not a session problem.

The middleware throws `Illuminate\Auth\Access\AuthorizationException`. Verified in the framework rather than assumed: `Handler.php:766` maps it to `AccessDeniedHttpException` when it carries no explicit status, its default message is `'This action is unauthorized.'` (`AuthorizationException.php:33`), and it sits in `$internalDontReport` (`Handler.php:172`), so an agent probing admin endpoints does not fill `storage/logs` with stack traces. `bootstrap/app.php:22-24` renders it as JSON for `api/*`.

**TM-13 does not delete this middleware.** It adds per-record rules the coarse gate cannot express — a user viewing their own record, an agent editing their own name — and every one of those is a *widening* of access that has to be able to run. The middleware stays as the blanket answer for `/admin/*`; policies decide what happens inside the routes that are not blanket-admin.

### The rule that needs a row lock

Acceptance criterion 5 says an admin cannot deactivate or demote **their own** account. Take that literally and the invariant it is protecting still breaks.

Work through it. Admin A and admin B are the only two administrators. A deactivates B — allowed, B is not A. B, in the same instant, deactivates A — allowed, A is not B. Both requests validate against a database that still had two active admins when they read it. Both commit. **There are now zero active administrators**, every `/admin/*` route answers `403` to everyone, and the only way back in is `php artisan tinker` or re-running the seeder against a database that already has the row.

So the enforced rule is two rules, and the second is the one that actually holds the line:

1. You cannot deactivate or demote **yourself** — a clear, fast, well-worded refusal that covers the ordinary mistake.
2. You cannot deactivate or demote the **last active admin** — which is normally yourself, and is the check that survives concurrency.

Rule 2 needs `SELECT … FOR UPDATE` around the count-then-update, so **it cannot live in a `FormRequest`**: a validator runs and returns before the controller opens a transaction, and a count read outside the transaction is exactly the read that races. Task 5 puts both checks inside `DB::transaction()` with `lockForUpdate()`, and throws `ValidationException::withMessages()` so the response is still the `422` envelope with field keys the SPA form can attach to — `errors.is_active` for a deactivation, `errors.role` for a demotion.

This is a deliberate departure from "validation belongs in the FormRequest", and the reason is the lock, not taste. Task 4's `UpdateUserRequest` still owns everything that is genuinely a shape check: types, the enum, the unique email.

### Deactivate, never delete

There is **no `destroy` route** in this story, and that is what satisfies acceptance criterion 4 — deactivation is not "the default", it is the only option.

Adding one now would be a hole that closes itself later in the worst way. `backend/phpunit.xml:27-35` records that the planned schema uses `ON DELETE RESTRICT`, so the moment TM-21 adds `tickets.created_by` / `assigned_to`, a delete that works today starts throwing a driver-level `QueryException` — a `500`, from an endpoint an admin had learned to trust. Better to never offer it.

Nothing in `tools/jira/backlog.json` asks for a hard delete. If product ever does, it belongs in its own story with a "reassign or anonymise their tickets first" step, and that story can decide whether it is worth the foreign-key work.

### Revoke tokens on deactivation, but not on demotion

TM-10's overview assigned this story a specific debt: *"TM-12 must call `$user->tokens()->delete()` when an admin deactivates an account."* Task 5 pays it.

The reason it is still needed after TM-10 shipped `EnsureUserIsActive`: that middleware refuses a deactivated user's **next** request, which is the right backstop, but it leaves a live token sitting in `personal_access_tokens` in the meantime. Deleting it at the moment of deactivation means access ends when the admin clicks the button, not when the deactivated user happens to make another request. The two together are the belt and the braces, and TM-10's plan says so.

**Demotion does not revoke.** An admin demoted to agent keeps their token, and that is deliberate:

- TM-11's store re-fetches `GET /auth/me` on every page load and never caches the user, so the demoted person's own UI corrects itself on their next reload.
- TM-13's policies are server-side, so a demoted agent physically cannot perform an admin action regardless of what their current page is rendering.
- The only staleness is a menu item they cannot successfully use until they reload.

Signing someone out mid-task to fix a cosmetic menu entry is the wrong trade. **Revisit this if a later story starts caching abilities client-side** — that is the change that would make demotion a security event rather than a display one.

### What an admin cannot do: set someone else's password

Acceptance criterion 3 lists name, email, role and `is_active`. It does not list the password, and this story follows it exactly: `POST /admin/users` takes an initial password (a login has to have one), and `PATCH /admin/users/{user}` **has no `password` field at all**.

The consequence, stated plainly because it is a real gap somebody will hit: **an agent who forgets their password cannot be helped through the UI.** TM-14 is "Change my own password", which needs the old password, so it does not cover it either. No story in `tools/jira/backlog.json` owns admin-initiated password reset or email-based self-service reset — the `password_reset_tokens` table exists (created by the users migration) and nothing reads it. Until a story does, recovery is `php artisan tinker`. Do not quietly add a `password` field to the update endpoint to plug it; that is a story with its own decisions about notification and forced rotation.

The create endpoint validates with `Password::defaults()`. Verified rather than assumed: with no `Password::defaults(...)` callback registered anywhere — `AppServiceProvider::boot()` is an empty stub until TM-9 adds its rate limiter — `Password::default()` returns `Password::min(8)` (`Password.php:166-173`). So today it means "at least 8 characters" and nothing else. Use it anyway: it puts the policy in one place (`AppServiceProvider::boot()`) for **TM-64**'s hardening pass to tighten, and every future password endpoint inherits the change.

### The search box is a wildcard, and nobody escapes it

`whereLike` looks like it handles this. It does not. `Builder.php:1323-1336` adds the value as a plain binding, and `prepareWhereLikeBinding` — the only hook that could transform it — is implemented **on `SQLiteGrammar` alone**, so on MySQL the string reaches `LIKE` untouched.

Measured against this project's MySQL 8.4 container with four users in the table:

```
A) whereLike(name, "%")    SQL: select * from `users` where `name` like '%'
   matched 4 of 4
C) unescaped "%…%" wrap    SQL: select * from `users` where `name` like '%%%'
   matched 4 of 4
D) escaped via addcslashes SQL: select * from `users` where `name` like '%\%%'
   matched 0 of 4
```

This is **not** SQL injection — the binding is parameterised and there is no escape from it. It is a correctness bug with a plausible cover story: typing `%` into the search box returns every user, which looks like "search cleared" rather than "search broken", and `_` silently becomes a single-character wildcard so `a_b` matches `axb`. Task 5 escapes `%`, `_` and `\` with `addcslashes($term, '%_\\')` before wrapping, and the test plan asserts a literal-`%` search returns **nothing** rather than everything.

`LIKE` and not FULLTEXT: the users table is a staff list measured in tens of rows, and the FULLTEXT index `phpunit.xml` mentions belongs to ticket search (**TM-25**). Same reasoning applies to indexes — see task 1.

---

## Context — Read These Files First

1. `backend/database/seeders/AdminUserSeeder.php` — all 34 lines, and **read it before writing task 5**. Lines 26–32 are the in-repo answer to "how do I set a role that is not mass-assignable":

    ```php
    $admin->fill([...]);        // fillable attributes only
    $admin->role = UserRole::Admin;   // explicit, bypasses #[Fillable]
    $admin->save();
    ```

    The controller does the same thing for the same reason. Note also `blank($password)` guarding at 17–19 and the `firstOrNew` + early-return idempotence at 21–24 — neither is needed here, but the file is the tone to match.
2. `backend/app/Models/User.php` — all 40 lines. **Line 14, `#[Fillable(['name', 'email', 'password', 'is_active'])]` — `role` is absent on purpose**, and `tests/Feature/Models/UserRoleAndStateTest.php:33-37` is the test that keeps it that way. `isAdmin()` is 36–39 and is what task 2's middleware calls. `casts()` (26–34) maps `role` to `UserRole` and `is_active` to `boolean`, so both read back as PHP types. Task 1 adds a scope here and changes nothing else.
3. `backend/app/Enums/UserRole.php` — all 15 lines. `Admin = 'admin'`, `Agent = 'agent'`, `values(): list<string>`. **Tasks 3 and 4 use `Rule::enum(UserRole::class)`, not `Rule::in(UserRole::values())`** — TM-8's plan (line 154 of its file) recorded why: `Rule::enum` also rejects a valid-looking string with the wrong case, and `Rule::in` accepts `'ADMIN'`.
4. `backend/database/migrations/0001_01_01_000000_create_users_table.php` — lines 15–25. `email` is `unique()` (18), `role` is a real MySQL `enum('admin','agent')` defaulting to `agent` (21), `is_active` is a boolean defaulting to `true` (22). **No index on `role` or `is_active`** — TM-8's plan handed the decision to this story; task 1 records the measurement and declines it.
5. `backend/database/factories/UserFactory.php` — all 62 lines. `definition()` (25–36) defaults to `UserRole::Agent` and `is_active => true`; the states are `admin()` (48–51), `agent()` (53–56) and `inactive()` (58–61). Line 31's shared `Hash::make('password')` means **every factory user's password is `password`** — which is how the tests sign in.
6. `backend/routes/api.php` — all 29 lines. `health` (21) and `auth.login` (22–24) sit at the top level; **lines 26–29 are TM-10's `Route::middleware(['auth:sanctum', 'active'])->group(...)`, and task 6 nests inside it, never beside it.**
7. `backend/bootstrap/app.php` — all 26 lines. `apiPrefix: 'api/v1'` (17), so task 6 writes `/admin/users`, never `/api/v1/admin/users`. **Line 20 is `$middleware->alias(['active' => EnsureUserIsActive::class]);`** — task 2 adds a second key to that array and a second import. `getMiddlewareAliases()` is `array_merge(defaultAliases(), customAliases)` (`Foundation/Configuration/Middleware.php:793-796`), so the built-ins survive.
8. `backend/app/Http/Middleware/EnsureUserIsActive.php` (TM-10) — the middleware idiom to match in task 2: a `handle(Request, Closure): Response` with the Symfony `Response` return type, a docblock explaining *why* rather than *what*, and a throw rather than a hand-built response.
9. `backend/app/Http/Resources/V1/UserResource.php` (TM-9) — confirm the field list is `id`, `name`, `email`, `role` (as `->value`), `is_active`, `created_at`. **This story reuses it unchanged**; TM-9's plan designated it "the single definition of the user object" and said TM-12 should return collections of it. Task 5 does exactly that and adds no fields.
10. `backend/app/Http/Requests/Api/V1/LoginRequest.php` (TM-9) — the form-request idiom: no `authorize()` override, a `rules(): array` with a `@return array<string, list<string>|string>` docblock, and a comment on any rule whose *absence* is deliberate. Tasks 3 and 4 follow it.
11. `backend/app/Http/Controllers/Api/V1/HealthController.php` — all 55 lines, and `Auth/LoginController.php` (TM-9). Between them: `__invoke` for single actions, explicit return types, `response()->json([...], $status)`, and a private helper at the bottom when a branch repeats. Task 5 is the project's **first multi-action controller**, so it uses named methods instead of `__invoke` — everything else carries over.
12. The pagination envelope, read in the framework rather than guessed. `PaginatedResourceResponse.php:50-98` composes it and `LengthAwarePaginator::toArray()` (`Pagination/LengthAwarePaginator.php:206-223`) supplies the parts, so a `UserResource::collection($paginator)` response is exactly:
    - `data` — the resource array.
    - `links` — `first`, `last`, `prev`, `next` (`PaginatedResourceResponse.php:73-81`).
    - `meta` — `current_page`, `from`, `last_page`, `links`, `path`, `per_page`, `to`, `total` (everything from `toArray()` except `data` and the four `*_page_url` keys).

    **Note `meta.links`**: a nested array of `{url, label, active}` built for Blade's pagination component. It is noise in a JSON API, the SPA ignores it, and task 8 documents it as present-but-unused rather than pretending it is not there.
13. `backend/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php:1323-1336` — `whereLike`. Read it and confirm for yourself that nothing escapes the value: the only transform is `prepareWhereLikeBinding`, and `grep -rn prepareWhereLikeBinding vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/` returns **one** hit, on `SQLiteGrammar`. See "The search box is a wildcard" above.
14. `backend/tests/Feature/Models/UserRoleAndStateTest.php` (45 lines) and `tests/Feature/Database/UsersTableSchemaTest.php` (55 lines) — TM-8's tests, and the **current local test precedent**: `Tests\Feature\<Area>` namespaces, `use RefreshDatabase;`, `test_it_…()` / `test_<subject>_<expectation>()` names, a `private` helper at the top when attributes repeat (`UsersTableSchemaTest:15-18`), and assertions written to fail on the near-miss (`assertSame(UserRole::Admin, …)` rather than `instanceof`; `$user->is_active === true` rather than `assertTrue`). Match all of it.
15. `backend/tests/Feature/Auth/ProtectedRouteTest.php` (TM-10) — find the test that iterates the route table asserting every `auth.*` route except `auth.login` carries `auth:sanctum` and `active`. **Task 7 broadens it**, because the admin routes this story adds are not named `auth.*` and would slip straight past it.
16. `frontend/src/views/AdminUsersView.vue` (TM-11) — the placeholder, whose text names TM-12. Task 12 replaces it. `frontend/src/router/index.ts` already routes `/admin/users` as `admin-users` with `meta: { role: 'admin' }`; **task 12 changes no route.**
17. `frontend/src/stores/auth.ts` (TM-11) — read `isAdmin`, `user` and `clear()`. Task 11's store never re-checks the role (the guard and the server both already did) but task 13 compares `user.id` against the row being edited to disable the self-lockout controls before the request is made.
18. `frontend/src/api/auth.ts` (TM-11) — the API-module idiom, plus `isUnauthorized()`, which **task 9 moves** to a new shared module. Also the source of the `UserRole` type task 10 reuses rather than redeclaring.
19. `frontend/src/views/LoginView.vue` (TM-11) — its private `messageFor(caughtError)` helper. Task 9 deletes it in favour of the shared `errorMessage()`; TM-11's two `LoginView` specs covering the `422` message and the `429` message must keep passing **unchanged**, which is what proves the refactor was behaviour-preserving.
20. `frontend/src/views/HealthView.vue` and `frontend/src/stores/health.spec.ts` — the current view and spec idiom **after Prettier**: no semicolons, single quotes, one statement per line, `data-testid` on every asserted element. `frontend/.prettierrc.json` is `{"semi": false, "singleQuote": true}` with the default 80-column width. Anything you write gets reformatted by `npm run format`, so write it that way and save the diff.
21. `docs/api-contract.md` — the endpoint table and the per-endpoint detail sections, extended by TM-9 and TM-10. Task 8 follows the same format and adds the `## Conventions` sentence about the pagination envelope.

---

## Backend Tasks

### 1 — A scope, and a measured decision not to add an index

**File: `backend/app/Models/User.php`**

TM-8's plan deferred two things to "the story that first lists users". This is it.

Add the scope (and nothing else — `#[Fillable]`, `#[Hidden]`, `casts()` and `isAdmin()` are TM-8's):

```php
use Illuminate\Database\Eloquent\Builder;
```

```php
    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
```

**No index on `role` or `is_active`.** TM-8's plan asked this story to add one "if the plan shows a real cost", and it does not: both columns hold two distinct values, so MySQL's optimiser will table-scan whatever you build, and the table is a staff list — tens of rows, not millions. An unused index still costs every insert and update. **Revisit when `users` passes a few thousand rows**, which for a ticket system's staff table means never; the story that adds an index should paste an `EXPLAIN` showing the scan it removes.

### 2 — The admin middleware

**Create file: `backend/app/Http/Middleware/EnsureUserIsAdmin.php`**

`app/Http/Middleware/` is created by TM-10; if it is missing, TM-10 has not landed.

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate the /admin/* routes on the admin role.
 *
 * 403 rather than 401, and the difference is not pedantry: the caller has a
 * valid token and a live account, so signing them out would be a lie — and
 * frontend/src/api/client.ts reacts only to 401, so a 403 shows up as an error
 * inside the screen instead of a forced trip to the login form.
 *
 * TM-13 adds policies on top of this rather than replacing it: policies express
 * per-record rules that WIDEN access (a user reading their own record), which a
 * blanket gate cannot, so they have to be able to run.
 */
class EnsureUserIsAdmin
{
    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws AuthorizationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Never used alone: auth:sanctum populates user(), and TM-10's `active`
        // has already refused a deactivated one. Task 6 always lists all three.
        if (! $request->user()?->isAdmin()) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
```

- **`?->isAdmin()`** so the middleware is harmless if it is ever attached without `auth:sanctum`, matching TM-10's `$user &&` guard for the same reason.
- **`throw`, not `abort(403)`.** `AuthorizationException` carries the message `'This action is unauthorized.'` (`AuthorizationException.php:33`), renders through `Handler.php:766`, and is in `$internalDontReport` (`Handler.php:172`) so probing does not fill the log. `abort(403)` gives a bare Symfony message and no such exemption.

**File: `backend/bootstrap/app.php`** — add one entry to the `alias([...])` array TM-10 created, and the import:

```php
use App\Http\Middleware\EnsureUserIsAdmin;
```

```php
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureUserIsAdmin::class,
        ]);
```

An unregistered alias makes the router treat `admin` as a class name: `BindingResolutionException — Target class [admin] does not exist`, a `500` on the first request. Loud, and the message names the alias.

### 3 — The create request

**Create file: `backend/app/Http/Requests/Api/V1/Admin/StoreUserRequest.php`**

A new `Admin/` subdirectory under TM-9's `Requests/Api/V1/`.

```php
<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            // Password::defaults() resolves to Password::min(8) today, because
            // nothing calls Password::defaults(...) to configure it. Used anyway
            // so TM-64 can tighten the policy in AppServiceProvider::boot() and
            // every password endpoint follows without being edited.
            'password' => ['required', 'string', Password::defaults()],
            // Rule::enum, not Rule::in(UserRole::values()): the latter accepts
            // 'ADMIN', which then fails at the database instead of at the edge.
            'role' => ['required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
```

- **`role` is `required`, not defaulted.** The column defaults to `agent` (`create_users_table.php:21`) precisely so a forgotten role cannot become an admin, but an *administrator creating an account on purpose* should have to say which kind. Acceptance criterion 2 says "choosing the admin or agent role" — make them choose.
- **No `password_confirmation`.** An admin typing a colleague's initial password is not protecting themselves from a typo they will never notice; the account holder changes it via TM-14. Adding `confirmed` here means a second field in task 13's form for no benefit.
- **`is_active` is `sometimes`** so the column default (`true`) applies when it is omitted, and an admin can still create a pre-deactivated account.

### 4 — The update request

**Create file: `backend/app/Http/Requests/Api/V1/Admin/UpdateUserRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // ->ignore($user) or saving a user without changing their email
            // fails uniqueness against their own row.
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user),
            ],
            'role' => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
```

- **`sometimes` + `required` on every field**, so `PATCH` semantics hold — an omitted key is left alone, but a key present and empty is an error rather than a silent blank-out.
- **No `password`.** See "What an admin cannot do" above. Adding it here is a different story.
- **The self-lockout rules are deliberately absent from this file.** They need a row lock a validator cannot hold — see task 5 and "The rule that needs a row lock".

### 5 — The controller

**Create file: `backend/app/Http/Controllers/Api/V1/Admin/UserController.php`**

The project's first multi-action controller and first `Admin/` controller namespace.

```php
<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreUserRequest;
use App\Http\Requests\Api\V1\Admin\UpdateUserRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Administrative CRUD for staff accounts — minus the D.
 *
 * There is no destroy action on purpose: ticket history has to keep a valid
 * author, and once TM-21 adds tickets with ON DELETE RESTRICT a delete that
 * worked today would start throwing a driver error. Deactivation is the only
 * removal, so it cannot be the road less travelled.
 */
class UserController extends Controller
{
    /**
     * @return AnonymousResourceCollection<UserResource>
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:active,inactive'],
            // Capped, or one caller asking for per_page=1000000 turns an
            // administrative list into a way to exhaust memory.
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when(
                filled($validated['search'] ?? null),
                function ($query) use ($validated) {
                    // % and _ are LIKE wildcards and whereLike does NOT escape
                    // them on MySQL — prepareWhereLikeBinding is implemented on
                    // SQLiteGrammar only. Measured: searching for "%" matched
                    // 4 of 4 rows. Not injection; just a search box that
                    // silently means "everything".
                    $term = '%'.addcslashes($validated['search'], '%_\\').'%';

                    $query->where(fn ($q) => $q
                        ->whereLike('name', $term)
                        ->orWhereLike('email', $term));
                }
            )
            ->when(
                ($validated['status'] ?? null) === 'active',
                fn ($query) => $query->active()
            )
            ->when(
                ($validated['status'] ?? null) === 'inactive',
                fn ($query) => $query->where('is_active', false)
            )
            // Deterministic, or page 2 can repeat a row from page 1: MySQL gives
            // no ordering guarantee without an ORDER BY, and LIMIT/OFFSET
            // pagination on an unordered result is how rows go missing.
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        // TM-21 adds ->withCount('tickets') above and one whenCounted() line to
        // UserResource; the tickets table does not exist yet.
        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'password', 'is_active']));

        // Explicit, exactly as AdminUserSeeder.php:31 does it. `role` is not in
        // #[Fillable], so passing it through the constructor or ->fill() is
        // silently dropped and every new account comes out an agent.
        $user->role = $request->enum('role', UserRole::class);
        $user->save();

        return UserResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user): JsonResponse
    {
        return UserResource::make($user)->response();
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $deactivating = $request->has('is_active') && $request->boolean('is_active') === false;
        $demoting = $request->enum('role', UserRole::class) === UserRole::Agent
            && $user->isAdmin();

        DB::transaction(function () use ($request, $user, $deactivating, $demoting) {
            if ($deactivating || $demoting) {
                $this->guardAgainstLockout($request, $user, $deactivating);
            }

            $user->fill($request->safe()->only(['name', 'email', 'is_active']));

            if ($request->has('role')) {
                $user->role = $request->enum('role', UserRole::class);
            }

            $user->save();

            // TM-10's `active` middleware already refuses a deactivated user's
            // NEXT request; deleting the token ends access at the moment the
            // admin clicks the button rather than whenever they happen to call
            // the API again. Demotion deliberately does NOT revoke — see the
            // plan's "Revoke tokens on deactivation, but not on demotion".
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        return UserResource::make($user->refresh())->response();
    }

    /**
     * Two rules, and the second is the one that survives concurrency.
     *
     * Self-check: the ordinary mistake, with a message that says so.
     * Last-admin check: two admins deactivating each other in the same instant
     * both pass the self-check and both pass an unlocked count, leaving zero
     * administrators and no way back in short of artisan tinker. Hence the
     * lockForUpdate, and hence this living in the controller rather than in
     * UpdateUserRequest — a validator returns before any transaction opens.
     *
     * @throws ValidationException
     */
    private function guardAgainstLockout(Request $request, User $user, bool $deactivating): void
    {
        $field = $deactivating ? 'is_active' : 'role';

        if ($user->is($request->user())) {
            throw ValidationException::withMessages([
                $field => $deactivating
                    ? 'You cannot deactivate your own account.'
                    : 'You cannot change your own role.',
            ]);
        }

        $remainingAdmins = User::query()
            ->where('role', UserRole::Admin)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->count();

        if ($remainingAdmins === 0) {
            throw ValidationException::withMessages([
                $field => 'This is the last active administrator. Promote someone else first.',
            ]);
        }
    }
}
```

Six details that are not stylistic:

- **`$request->validate()` inline in `index`, not a FormRequest.** Three optional query parameters with no cross-field logic; a fifth file to hold them would be ceremony. Tasks 3 and 4 exist because create and update have real rules and are referenced from two places.
- **`->orderBy('name')->orderBy('id')`.** The second key is not redundant: two users named "Ali" would otherwise order arbitrarily between pages, and `LIMIT`/`OFFSET` over an unstable sort drops and duplicates rows.
- **`->withQueryString()`** so `links.next` keeps `search` and `status`. Without it, paging past page 1 silently drops the filter and the SPA shows unfiltered results under a filtered heading.
- **`$request->safe()->only([...])`** everywhere, never `$request->validated()` wholesale into `fill()`. It documents at the call site which attributes are allowed to move by mass assignment, and it means adding a rule to a FormRequest can never widen what gets written.
- **`$user->is($request->user())`** rather than comparing ids — it compares the key *and* the model class, and reads as the sentence it is.
- **`$deactivating` is computed from `$request->has('is_active')` before the fill**, because after `fill()` the model's value and the request's agree and the distinction between "set false" and "not mentioned" is gone.

### 6 — The routes

**File: `backend/routes/api.php`**

Add the import and nest a group **inside** TM-10's authenticated group:

```php
use App\Http\Controllers\Api\V1\Admin\UserController;
```

```php
    // Everything here additionally requires the admin role. Nested inside the
    // authenticated group rather than beside it, so `admin` can never be the
    // only thing standing between a caller and a staff list.
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        // No destroy route. Deactivation is the only removal — see the
        // controller docblock.
    });
```

- **`Route::apiResource` is not used**, because it would generate a `destroy` route. `->except(['destroy'])` would work and then read as "we removed delete", which invites someone to put it back; four explicit lines read as "these are the four things you can do".
- **`PATCH`, not `PUT`.** Task 4's rules are all `sometimes`, which is partial-update semantics; `PUT` promises replacement and this endpoint does not do that.
- **`{user}` is an implicit binding**, so a missing id is a `404` before the controller runs — and because `bootstrap/app.php:22-24` renders JSON for `api/*`, it is a JSON `404`, not an HTML error page.
- **`->name('admin.')` on the group** gives `admin.users.index` and friends, which task 7's test and every backend test here address by name.

### 7 — Broaden TM-10's route-guard test

**File: `backend/tests/Feature/Auth/ProtectedRouteTest.php`**

TM-10 added a test asserting every route named `auth.*` except `auth.login` carries `auth:sanctum` and `active`. The routes this story adds are named `admin.users.*` and would sail straight past it — and so would every route TM-17, TM-22 and TM-23 add.

Rewrite that one test to invert the check: iterate the whole route table and assert that every route **except a named allow-list of public ones** carries both middlewares.

```php
    public function test_every_route_except_the_public_ones_requires_an_active_session(): void
    {
        $public = ['health', 'auth.login'];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            // Laravel's own /up probe and the fallback have no name.
            if ($name === null || in_array($name, $public, true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $this->assertContains('auth:sanctum', $middleware, "Route [{$name}] is not authenticated.");
            $this->assertContains('active', $middleware, "Route [{$name}] does not check is_active.");
        }
    }
```

Then add a second test in the same file for the narrower claim:

```php
    public function test_every_admin_route_requires_the_admin_role(): void
    {
        $adminRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), 'admin.'));

        $this->assertGreaterThan(0, $adminRoutes->count());

        foreach ($adminRoutes as $route) {
            $this->assertContains('admin', $route->gatherMiddleware(), "Route [{$route->getName()}] is not admin-gated.");
        }
    }
```

`assertGreaterThan(0, …)` matters: without it, a rename of the `admin.` prefix would empty the collection and the loop would pass by iterating nothing.

**Do not** delete TM-10's original assertion by rewriting the file from scratch — edit the one method and add the one method, leaving the rest of the class as TM-10 wrote it.

### 8 — The contract

**File: `docs/api-contract.md`**

Add one `## Conventions` sentence, four table rows, and four detail sections in the format TM-3 established and TM-9/TM-10 extended.

Convention sentence (after the `user`-envelope sentence TM-11's work relies on):

````markdown
- List endpoints return Laravel's paginated resource envelope: `data`, `links`
  (`first`, `last`, `prev`, `next`) and `meta` (`current_page`, `from`,
  `last_page`, `links`, `path`, `per_page`, `to`, `total`). `meta.links` is the
  page-number array Blade's paginator renders; JSON clients ignore it.
````

Table rows:

````markdown
| `GET` | `/api/v1/admin/users` | Paginated, searchable staff list. | bearer + admin | TM-12 |
| `POST` | `/api/v1/admin/users` | Create a staff account with an explicit role. | bearer + admin | TM-12 |
| `GET` | `/api/v1/admin/users/{user}` | One staff account. | bearer + admin | TM-12 |
| `PATCH` | `/api/v1/admin/users/{user}` | Edit name, email, role; toggle `is_active`. | bearer + admin | TM-12 |
````

The detail sections must state, field for field: the three `index` query parameters and that `per_page` is capped at **100** (default 15); that `search` matches `name` or `email` and that `%` and `_` are treated as **literal characters, not wildcards**; that `store` requires `role` and returns **`201`**; that `update` is partial and has **no `password` field**; that deactivating **revokes the user's tokens** while changing their role does not; that both self-lockout refusals are **`422`** with the message on `errors.is_active` or `errors.role`; that an agent gets **`403`** with `This action is unauthorized.`; and that **there is no `DELETE`**, with one sentence saying why.

Add a short note that **`tickets_count` is not in the response yet** and names TM-21, so nobody reading the contract concludes the field was forgotten.

Do **not** touch `docs/erd.md` — this story adds no column. (TM-8 opened it with the `users` entity; TM-16 and TM-21 add the rest.)

---

## Frontend Tasks

### 9 — Shared pagination and error modules

**Create file: `frontend/src/api/pagination.ts`**

The first of many list endpoints. One generic, reused by TM-17, TM-23, TM-24 and TM-35 rather than re-declared per screen.

```ts
/** Laravel's paginated resource envelope. See docs/api-contract.md. */
export interface Paginated<T> {
  data: T[]
  links: {
    first: string | null
    last: string | null
    prev: string | null
    next: string | null
  }
  meta: {
    current_page: number
    from: number | null
    last_page: number
    path: string
    per_page: number
    to: number | null
    total: number
  }
}
```

`meta.links` is deliberately **not** typed: it exists in the payload (`PaginatedResourceResponse.php:73-81` puts it there via `LengthAwarePaginator::toArray()`) but it is Blade's page-number array and nothing in this SPA reads it. Typing it would invite someone to.

`from` and `to` are `number | null` because both are `null` on an empty result — the case a "showing X to Y of Z" label gets wrong first.

**Create file: `frontend/src/api/errors.ts`**

```ts
import axios from 'axios'

/** A 401 means the token is dead; anything else means we cannot tell. */
export function isUnauthorized(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 401
}

/**
 * Laravel's 422 envelope, keyed by field. Empty object for anything that is
 * not a validation failure, so a caller can bind it to a form unconditionally.
 */
export function validationErrors(error: unknown): Record<string, string[]> {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return {}

  const data = error.response.data as
    | { errors?: Record<string, string[]> }
    | undefined

  return data?.errors ?? {}
}

/** The server's own message where there is one, and a readable fallback. */
export function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string } | undefined

    if (error.response?.status === 429) {
      return 'Too many attempts. Try again in a minute.'
    }
    if (error.response?.status === 403) {
      return 'You do not have permission to do that.'
    }
    if (data?.message) return data.message
  }

  return 'The API is unreachable.'
}
```

Note the ordering inside `errorMessage`: **429 and 403 are checked before `data.message`**, because the server's own text for those (`Too Many Attempts.`, `This action is unauthorized.`) is not a sentence to show a person. Everything else — notably the `422` message TM-9 crafted — passes through verbatim.

Three edits follow, and they are the whole reason this task exists rather than being folded into task 10:

1. **`frontend/src/api/auth.ts`** — delete `isUnauthorized` and its `axios` import. It now lives in `errors.ts`, and this story is the second consumer.
2. **`frontend/src/stores/auth.ts`** — change the `isUnauthorized` import to `from '../api/errors'`.
3. **`frontend/src/views/LoginView.vue`** — delete the private `messageFor` helper (currently lines 29–38) and its `axios` import (line 2); call `errorMessage(caughtError)` at line 23 instead. **TM-11's two specs covering the `422` message and the `429` message must pass unchanged** — that is the check that the refactor preserved behaviour, so do not touch them. Note `errorMessage` adds a `403` branch `messageFor` did not have; `LoginView` never sees a `403`, so nothing there changes.

`frontend/src/stores/auth.spec.ts` needs **no edit**: its mock is `vi.mock('../api/auth', async (loadOriginal) => ({ ...(await loadOriginal()), login: vi.fn(), logout: vi.fn(), me: vi.fn() }))`, which names only the three functions it stubs — `isUnauthorized` was never in the factory, so moving it out of `api/auth.ts` changes nothing there. The store's new `../api/errors` import is unmocked, so the real `isUnauthorized` runs, which is what those tests want. **All of TM-11's assertions in that file must still pass untouched.**

### 10 — The users API module

**Create file: `frontend/src/api/users.ts`**

```ts
import client from './client'
import type { Paginated } from './pagination'
import type { UserRole } from './auth'

export interface AdminUser {
  id: number
  name: string
  email: string
  role: UserRole
  is_active: boolean
  created_at: string
  // Added by TM-21, once a tickets table exists to count.
  tickets_count?: number
}

export interface UserListQuery {
  search?: string
  status?: 'active' | 'inactive'
  page?: number
  per_page?: number
}

export interface CreateUserPayload {
  name: string
  email: string
  password: string
  role: UserRole
  is_active?: boolean
}

/** PATCH is partial: only the keys present are changed. No password field. */
export type UpdateUserPayload = Partial<
  Pick<AdminUser, 'name' | 'email' | 'role' | 'is_active'>
>

export async function listUsers(
  query: UserListQuery = {},
): Promise<Paginated<AdminUser>> {
  const { data } = await client.get<Paginated<AdminUser>>('/admin/users', {
    params: query,
  })
  return data
}

export async function createUser(
  payload: CreateUserPayload,
): Promise<AdminUser> {
  const { data } = await client.post<{ data: AdminUser }>(
    '/admin/users',
    payload,
  )
  return data.data
}

export async function updateUser(
  id: number,
  payload: UpdateUserPayload,
): Promise<AdminUser> {
  const { data } = await client.patch<{ data: AdminUser }>(
    `/admin/users/${id}`,
    payload,
  )
  return data.data
}
```

- **`UserRole` is imported from `./auth`, not redeclared.** One definition of the two roles on the client side; a third role added to the backend enum then has exactly one place to land.
- **`data.data` on create and update** — a single `UserResource` returned via `->response()` carries the default `data` wrapper, while the paginated collection carries `data` as its array. The envelope difference is real and this is where it gets absorbed, so no component sees it.
- **No `deleteUser`.** There is no endpoint.
- **`params: query`** and no manual query-string building: axios drops `undefined` values, so an unset filter is simply absent.

### 11 — The users store

**Create file: `frontend/src/stores/users.ts`**

Follows `src/stores/health.ts`'s shape: setup syntax, `ref` state, async actions, `try/catch/finally`.

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import { createUser, listUsers, updateUser } from '../api/users'
import type {
  AdminUser,
  CreateUserPayload,
  UpdateUserPayload,
} from '../api/users'
import type { Paginated } from '../api/pagination'

export const useUsersStore = defineStore('users', () => {
  const users = ref<AdminUser[]>([])
  const meta = ref<Paginated<AdminUser>['meta'] | null>(null)
  const search = ref('')
  const status = ref<'active' | 'inactive' | ''>('')
  const page = ref(1)
  const loading = ref(false)
  const error = ref<string | null>(null)

  // Not state anyone renders, and per-Pinia-instance because this scope is —
  // the same placement TM-11's hydration promise uses, for the same reason:
  // setActivePinia(createPinia()) resets it, so tests stay isolated.
  let latestRequest = 0

  async function load(): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null

    try {
      const response = await listUsers({
        search: search.value || undefined,
        status: status.value || undefined,
        page: page.value,
      })

      // A slow response for "ali" must not overwrite a fast one for "alice".
      // Typing into a debounced search box makes this ordinary, not exotic.
      if (request !== latestRequest) return

      users.value = response.data
      meta.value = response.meta
    } catch (caughtError) {
      if (request !== latestRequest) return
      error.value = errorMessage(caughtError)
      users.value = []
      meta.value = null
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }

  /** Any filter change resets to page 1 — page 7 of a new filter is nothing. */
  async function applyFilters(next: {
    search?: string
    status?: 'active' | 'inactive' | ''
  }): Promise<void> {
    if (next.search !== undefined) search.value = next.search
    if (next.status !== undefined) status.value = next.status
    page.value = 1
    await load()
  }

  async function goToPage(target: number): Promise<void> {
    page.value = target
    await load()
  }

  /**
   * Create and update deliberately do NOT catch: the form needs the error to
   * pull field-level messages out of, and swallowing it here would leave the
   * dialog looking successful. The list error ref is for the list.
   */
  async function create(payload: CreateUserPayload): Promise<void> {
    await createUser(payload)
    await load()
  }

  async function update(id: number, payload: UpdateUserPayload): Promise<void> {
    await updateUser(id, payload)
    await load()
  }

  return {
    users,
    meta,
    search,
    status,
    page,
    loading,
    error,
    load,
    applyFilters,
    goToPage,
    create,
    update,
  }
})
```

- **`latestRequest` discards stale responses.** With a debounced search box this is not a theoretical race: three keystrokes fire three requests and the network decides the order. Without the counter the list can settle on the results for a prefix the user has already deleted.
- **`load()` reloads after create and update** rather than splicing the row in locally. The list is sorted by name and paginated server-side, so a locally-inserted row lands in the wrong place and makes `meta.total` a lie. One extra request buys correctness.
- **`create` and `update` re-throw.** Task 13 needs the `AxiosError` to feed `validationErrors()`.

### 12 — The Users screen

**File: `frontend/src/views/AdminUsersView.vue`** — replace TM-11's placeholder.

Structure, in order: an `<h1>`, a filter row (search input + status select), a "New user" button, the table, and pagination controls. Requirements rather than a full listing, because the markup is long and the constraints are what matter:

- **Search is debounced by 300 ms**, hand-rolled with `setTimeout` and a cleared handle — `@vueuse/core` is not installed and must not be added. Combined with the store's request counter, a fast typist produces one settled list.
- **Every asserted element carries a `data-testid`**, matching `HealthView.vue`: `users-search`, `users-status`, `users-new`, `users-table`, `users-row` (per row), `users-empty`, `users-error`, `users-loading`, `users-prev`, `users-next`, `users-count`.
- **The table columns are Name, Email, Role, Active, and an Edit action.** No ticket count — see "The ticket count cannot ship yet"; add the `<th>`/`<td>` in the story that adds the field, not before.
- **Role and active state render as words, not raw values.** `admin` / `agent` are acceptable as-is; `is_active` renders "Active" / "Inactive", never `true` / `false`.
- **The empty state distinguishes "no users match" from "no users".** With a search term active, say so and offer to clear it — an admin who cannot tell a filtered empty list from an empty database will assume the data is gone.
- **`users-count` renders `meta`'s numbers defensively.** `from` and `to` are `null` on an empty page, so `Showing null to null of 0` is one careless template away.
- **Prev/next are `disabled` at the ends**, driven by `meta.current_page` against `1` and `meta.last_page` — not by whether `links.prev` is null, so the disabled state does not depend on an envelope key the store does not expose.
- **The screen never checks the role.** TM-11's guard and task 2's middleware both already did; a third check here would be the one that gets out of sync.
- **`onMounted(() => void users.load())`**, matching `HealthView.vue:7-9`.

### 13 — The create/edit dialog

**Create file: `frontend/src/components/UserFormDialog.vue`**

`src/components/` exists but is empty — TM-4 removed `HelloWorld.vue` and nothing has replaced it. This is its first real occupant.

One component for both modes, because the fields are the same bar one:

- **Props:** `user?: AdminUser` (absent = create mode). **Emits:** `saved`, `close`.
- **Create mode shows a password field; edit mode does not** — there is no `password` on `PATCH`. The field is not merely disabled in edit mode; it is absent, so nobody can be confused about whether leaving it blank changes anything.
- **Submit sends only what changed in edit mode**, building an `UpdateUserPayload` by diffing against the `user` prop. Sending unchanged values works — the backend's `Rule::unique(...)->ignore($user)` covers the email case specifically — but a minimal patch means an unrelated concurrent edit by another admin is not silently reverted.
- **Field errors come from `validationErrors(caughtError)`** and render under the matching input, keyed by the backend's field names: `name`, `email`, `password`, `role`, `is_active`. The two self-lockout refusals arrive on `errors.is_active` and `errors.role`, so they land under the right control with no special handling — **that is why task 5 throws `ValidationException` instead of `abort(403)`**.
- **Non-validation failures render `errorMessage(caughtError)`** as a single form-level message.
- **The self-lockout controls are disabled up front for your own row**: when `user.id === auth.user?.id`, the role select and the active toggle are `disabled` with a short explanation next to them. This is a courtesy, not the enforcement — the server refuses regardless, and the spec asserts both the disabled control *and* that a `422` still renders correctly if it is bypassed.
- **`data-testid`s:** `user-form`, `user-form-name`, `user-form-email`, `user-form-password`, `user-form-role`, `user-form-active`, `user-form-submit`, `user-form-cancel`, `user-form-error`, and `user-form-error-<field>` for each field message.
- **A plain element, not a native `<dialog>`.** `jsdom@29` does not implement `showModal()`, so a `<dialog>` would need a mock in every spec that mounts the component. A positioned `<div>` with the panel styling from `style.css` costs nothing and tests directly.

---

## Edge Cases & Failure Modes

- **`role` silently dropped on create.** `User::create($request->validated())` or `new User([... 'role' => ...])` discards `role` without error, because it is not in `#[Fillable]` (`User.php:14`) and nothing calls `Model::preventSilentlyDiscardingAttributes()` — `AppServiceProvider` holds only TM-9's rate limiter. Every new account comes out an **agent**, the endpoint returns `201`, and the response body shows the correct role only if you re-read it from the response rather than the input. Task 5 assigns it explicitly, `AdminUserSeeder.php:31` is the precedent, and the test plan asserts a created admin is an admin **after a reload from the database**.
- **`Rule::in(UserRole::values())` instead of `Rule::enum`.** `'ADMIN'` passes validation and then hits a MySQL `enum('admin','agent')` column, which under `config/database.php`'s `'strict' => true` throws `SQLSTATE[01000]: Data truncated for column 'role'` — a `500` from a request that should have been a `422`. TM-8's plan flagged this for this story specifically.
- **Two admins deactivating each other simultaneously.** Both pass the self-check, both read "two active admins" from an unlocked count, both commit: **zero administrators**, every `/admin/*` route `403` for everyone, recovery only via `php artisan tinker` or a re-seed. This is the whole reason the last-admin check exists and the reason it holds `lockForUpdate()` inside a transaction. A reviewer who moves it into `UpdateUserRequest` for tidiness reopens it, because a validator cannot hold a lock across the write.
- **Demoting the last admin, as distinct from deactivating them.** Same hole, different column — which is why `guardAgainstLockout` runs for both and keys its message on whichever field triggered it.
- **`is_active` sent as the string `"false"`.** `$request->boolean('is_active')` coerces `"false"`, `"0"`, `0` and `""` to `false`, so the JSON-string case a hand-built form or a curl `-d` produces is handled — but `'is_active' => ['boolean']` in task 4 rejects the string `"maybe"` before it gets there. Reading `$request->input('is_active')` directly instead would make `"false"` truthy and deactivation silently a no-op.
- **Deactivating without revoking tokens.** The user keeps working until their next request meets TM-10's `active` middleware. Not a hole — that middleware is the backstop — but it means "deactivated" and "cut off" differ by however long the user idles. TM-10's overview assigned the `tokens()->delete()` to this story; the test asserting the token count drops to `0` is what keeps it.
- **Revoking on demotion.** Deliberately not done. Signing an administrator out mid-task because their menu will show one stale item is the wrong trade, and TM-11 re-fetches `/auth/me` on every page load so the staleness ends at their next reload. **Revisit if a story starts caching abilities client-side.**
- **Pagination without an `ORDER BY`.** MySQL guarantees no row order, so `LIMIT 15 OFFSET 15` over an unordered result can repeat a row from page 1 and skip another entirely. `orderBy('name')->orderBy('id')` — both keys — is what makes paging stable; the single `name` key is not enough once two people share a name.
- **Filters lost on page 2.** Without `->withQueryString()`, `links.next` drops `search` and `status`, so following it returns the unfiltered list under a heading that still says filtered. The store builds its own params so it would survive, but the contract documents those links and someone will use them.
- **`per_page` uncapped.** `?per_page=1000000` serialises the whole table into one response. Task 5 caps it at 100 and defaults to 15; the cap belongs in validation, not in a comment.
- **A literal `%` or `_` in the search box.** Measured on this project's MySQL 8.4: searching `%` matched **4 of 4** rows because `whereLike` passes the value through unescaped on MySQL (`prepareWhereLikeBinding` exists only on `SQLiteGrammar`). Reads as "search cleared", not "search broken". `addcslashes($term, '%_\\')` fixes it, and the test asserts a `%` search returns **nothing**.
- **Uniqueness on update without `->ignore()`.** Saving a user with their email unchanged fails with "email has already been taken" against their own row — a confusing `422` on a form the admin did not touch the email field of.
- **Unicode names and emails.** Both MySQL containers run `utf8mb4` / `utf8mb4_unicode_ci` (`docker-compose.yml`), so Arabic names sort and match case-insensitively, and `max:255` counts **characters**, not bytes, because Laravel's `max` on a string uses `mb_strlen`. A 255-character Arabic name fits the `VARCHAR(255)` column. Worth one test so a later "optimisation" to `latin1` fails loudly.
- **`403` mistaken for a session problem.** `frontend/src/api/client.ts` reacts to `401` only, so a `403` propagates to the caller and renders inside the screen — correct, and the reason task 2 does not use `401`. If a later story makes the interceptor react to `403`, an agent who lands on an admin screen gets signed out instead of told no.
- **The admin route group placed beside TM-10's instead of inside it.** `Route::middleware('admin')` alone gives a route that checks the role and never checks the token — and `$request->user()` is `null`, so `?->isAdmin()` is `false` and it returns `403` to *everyone*, which looks like working security. Task 7's broadened test is what catches it.
- **`meta.from` / `meta.to` on an empty page.** Both are `null` (`LengthAwarePaginator::firstItem()` returns `null` with no items), so a naive "Showing {from} to {to} of {total}" renders `Showing null to null of 0`. Task 12 handles the empty case separately.
- **A stale search response overwriting a newer one.** Three keystrokes, three requests, and the network decides the order. The store's `latestRequest` counter discards anything that is not the newest; without it the list can settle on results for a prefix the user already deleted. The same counter must gate the `catch` and `finally` blocks, or a stale failure clears a good list.
- **`per_page` changed while on a high page.** Not exposed in the UI, and the store always resets to page 1 on a filter change — but `?page=99` typed by hand returns an empty `data` with `meta.last_page` well below it. Task 12's empty state covers the render; nothing crashes.
- **No way to reset a forgotten password.** Real gap, stated in Product rules, owned by no story. `password_reset_tokens` exists and nothing reads it. Recovery today is `php artisan tinker`. Do not plug it by adding `password` to `UpdateUserRequest`.
- **`npm run lint` after adding a spec.** `tsconfig.app.json` sets `noUnusedLocals` and `noUnusedParameters`, so an unused import in a spec fails `npm run typecheck` and `npm run build` while `vitest` still passes. And Prettier is now a gate: `npm run format:check` fails on formatting alone. Run `npm run format && npm run lint && npm run typecheck && npm run test`.

---

## Test Plan

### Backend

`composer test` from `backend/`, against `tm-mysql-test` on 3307. Baseline entering this story: 23 measured by reading TM-8's three test files, plus TM-9's 16 and TM-10's 19 — **58**. Confirm with a real run before starting. New tests go in `tests/Feature/Admin/` (namespace `Tests\Feature\Admin`), matching `tests/Feature/Models/UserRoleAndStateTest.php`'s conventions.

Every test signs in with a real token, the way TM-10's plan requires — `Sanctum::actingAs()` is fine here because nothing in this story touches `currentAccessToken()`, but a shared helper keeps it uniform:

```php
    private function actingAsAdmin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }
```

1. **Create `backend/tests/Feature/Admin/UserIndexTest.php`** — `RefreshDatabase`. Eleven tests:
   - `test_it_lists_users_in_the_paginated_envelope` — `assertJsonStructure(['data' => [['id', 'name', 'email', 'role', 'is_active', 'created_at']], 'links' => ['first', 'last', 'prev', 'next'], 'meta' => ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total']])`. Pins the contract task 8 documents.
   - `test_it_paginates_at_fifteen_by_default` — create 20 users, assert `data` has 15 and `meta.total` is 21 (20 plus the acting admin).
   - `test_it_honours_per_page` — `?per_page=5` gives 5 rows.
   - `test_it_caps_per_page_at_one_hundred` — `?per_page=1000` is a `422` on `per_page`, **not** a slow success.
   - `test_it_searches_name_and_email` — three users; a term matching one name and a term matching one email each return exactly that user.
   - `test_it_treats_percent_as_a_literal_character` — a user named `Ali`, then `?search=%`. **`data` is empty.** The measured bug, pinned; without `addcslashes` this returns every user.
   - `test_it_treats_underscore_as_a_literal_character` — users `axb` and `a_b`; `?search=a_b` returns only `a_b`.
   - `test_it_filters_by_status` — `?status=active` excludes an `->inactive()` user; `?status=inactive` returns only them; omitting it returns both.
   - `test_it_rejects_an_unknown_status` — `?status=archived` is a `422`.
   - `test_it_orders_by_name_then_id` — two users both named `Ali`, assert the ids come back ascending. Catches the missing second sort key that makes pagination unstable.
   - `test_it_keeps_filters_in_the_pagination_links` — with `?search=ali&per_page=1` and two matches, `links.next` contains `search=ali`.

2. **Create `backend/tests/Feature/Admin/UserStoreTest.php`** — `RefreshDatabase`. Ten tests:
   - `test_it_creates_an_agent` — `201`, `assertJsonPath('data.role', 'agent')`, and the row exists.
   - `test_it_creates_an_admin_with_the_role_actually_persisted` — create with `role=admin`, then **reload from the database** and assert `User::find($id)->role === UserRole::Admin`. Asserting only the response body would pass even if the role were dropped, because the resource renders the in-memory model. **This is the test that catches the `#[Fillable]` trap.**
   - `test_it_hashes_the_password` — `Hash::check('supplied-password', $user->password)` and `$user->password !== 'supplied-password'`.
   - `test_it_requires_name_email_password_and_role` — `postJson([])` → `422` naming all four.
   - `test_it_rejects_a_duplicate_email` — `422` on `email`.
   - `test_it_rejects_a_short_password` — a 7-character password is a `422`, pinning `Password::defaults()`'s current `min(8)`.
   - `test_it_rejects_an_unknown_role` — `role=manager` → `422`. And `role=ADMIN` → **`422`**, not a `500`: the assertion that justifies `Rule::enum` over `Rule::in`.
   - `test_it_defaults_new_users_to_active` — omit `is_active`; the row is active.
   - `test_it_can_create_a_deactivated_user` — `is_active=false` is honoured.
   - `test_it_accepts_a_unicode_name` — an Arabic name round-trips byte-identically through `utf8mb4`.

3. **Create `backend/tests/Feature/Admin/UserUpdateTest.php`** — `RefreshDatabase`. Ten tests:
   - `test_it_updates_the_name` / `test_it_updates_the_email` — `200`, row changed.
   - `test_it_leaves_omitted_fields_alone` — patch only `name`; email and role unchanged. Pins `sometimes`.
   - `test_it_rejects_an_empty_name` — `name=''` → `422`. Pins `sometimes` + `required` together.
   - `test_it_allows_saving_an_unchanged_email` — patch with the user's own email → `200`. Fails without `Rule::unique(...)->ignore($user)`.
   - `test_it_rejects_another_users_email` — `422`.
   - `test_it_promotes_an_agent_to_admin_with_the_role_persisted` — reload from the database, as in the store test.
   - `test_it_revokes_tokens_when_deactivating` — give the target a real token via `createToken`, patch `is_active=false`, assert `$target->tokens()->count()` is **`0`**. TM-10's assigned debt.
   - `test_it_keeps_tokens_when_demoting` — demote an admin who is not the actor; their token count is **unchanged**. Pins the deliberate asymmetry so a later "consistency" change has to argue with a test.
   - `test_it_returns_404_for_an_unknown_user` — `PATCH /admin/users/999999` → `404` as JSON.

4. **Create `backend/tests/Feature/Admin/UserLockoutTest.php`** — `RefreshDatabase`. Seven tests:
   - `test_an_admin_cannot_deactivate_themselves` — `422` with the message on `errors.is_active`, and the row is **still active** afterwards.
   - `test_an_admin_cannot_demote_themselves` — `422` on `errors.role`, role unchanged.
   - `test_an_admin_can_edit_their_own_name_and_email` — the guard must not block harmless self-edits.
   - `test_the_last_active_admin_cannot_be_deactivated` — a second admin who is **not** the actor, with the actor deactivated first so only one active admin remains; patch → `422` on `errors.is_active`.
   - `test_the_last_active_admin_cannot_be_demoted` — same setup, `errors.role`.
   - `test_a_second_admin_can_be_deactivated_when_a_third_remains` — the negative control. Without it, a guard that refused every deactivation would pass the four tests above.
   - `test_deactivating_an_agent_is_never_blocked` — the lockout guard must only fire on admins.

5. **Create `backend/tests/Feature/Admin/AdminAuthorizationTest.php`** — `RefreshDatabase`. Six tests, one per route plus the shape of the refusal:
   - `test_an_agent_gets_403_from_every_admin_route` — loop `index`, `store`, `show`, `update` as an `->agent()` user; every one is `403` with `assertJsonPath('message', 'This action is unauthorized.')`.
   - `test_an_unauthenticated_caller_gets_401` — same loop, no token; every one is `401`. Proves the group is nested, not parallel.
   - `test_a_deactivated_admin_gets_401` — an `->admin()->inactive()` user with a real token: `401`, because TM-10's `active` runs before `admin`. Pins the middleware order.
   - `test_an_agent_cannot_create_an_admin` — the escalation attempt, stated as itself: `403`, and `User::count()` unchanged.
   - `test_there_is_no_delete_route` — `deleteJson(route('admin.users.show', $user))` returns **`405`**, and the user still exists. Pins acceptance criterion 4 against a future `apiResource` refactor.
   - `test_the_admin_routes_are_versioned_under_api_v1` — `assertSame('/api/v1/admin/users', route('admin.users.index', absolute: false))`.

6. **Edit `backend/tests/Feature/Auth/ProtectedRouteTest.php`** — task 7. One test rewritten to cover the whole route table, one test added for the `admin` middleware. Net **+1** test; every other method in the file is left as TM-10 wrote it.

7. **No unit tests for the FormRequests or the middleware in isolation.** All three are fully exercised through HTTP above, and a test asserting `rules()` returns a particular array pins the plan rather than the behaviour — the same call TM-9's plan made.

Backend total added: **45 tests** (11 + 10 + 10 + 7 + 6 + 1), taking the suite from 58 to **103**.

### Frontend

`npm run test` from `frontend/`. Baseline measured today: **11 tests across 3 files**, plus TM-11's 42 → **53**. Match `src/stores/health.spec.ts` and `src/views/HealthView.spec.ts` **as Prettier now formats them** — one statement per line, no semicolons, single quotes.

8. **Create `frontend/src/api/errors.spec.ts`** — no mocking; build `AxiosError`s directly the way `src/api/client.spec.ts:19-30` does. Nine tests:
   - `isUnauthorized` is true for `401`, false for `403`, `500`, a plain `Error`, and `null`.
   - `validationErrors` returns the `errors` object for a `422`; `{}` for a `422` with no `errors` key, for a `500`, and for a non-Axios error.
   - `errorMessage` returns the server `message` for a `422`; the friendly text for `429` and `403` **even when the server sent a message**; and the unreachable fallback for a network error.

9. **Create `frontend/src/api/users.spec.ts`** — the adapter-swap technique. Six tests:
   - `listUsers` requests `/admin/users` and passes `search`, `status` and `page` as params.
   - `listUsers` omits absent filters from the query string.
   - `listUsers` returns the envelope unchanged.
   - `createUser` posts to `/admin/users` and **unwraps `data.data`**.
   - `updateUser` sends `PATCH` to `/admin/users/{id}` and unwraps `data.data`.
   - `updateUser` sends only the keys it was given.

10. **Create `frontend/src/stores/users.spec.ts`** — `vi.mock('../api/users')`, `setActivePinia(createPinia())` per test. Eleven tests:
    - `load` fills `users` and `meta`.
    - `load` sets `error` and clears the list on failure.
    - `load` omits empty filters from the query.
    - `applyFilters` resets `page` to 1.
    - `goToPage` sends the requested page.
    - `load` ignores a stale response — resolve two calls out of order and assert the list holds the **newer** result. The request-counter test; without the counter it holds the older one.
    - `load` ignores a stale failure — a slow rejection must not clear a list a newer success filled.
    - `create` calls the API and reloads.
    - `create` re-throws so the form can read the error.
    - `update` calls the API and reloads.
    - `update` re-throws.

11. **Create `frontend/src/views/AdminUsersView.spec.ts`** — `vi.mock('../api/users')`, mount with a real Pinia. Nine tests:
    - renders a row per user with name, email, role and a readable active state ("Active" / "Inactive", never `true`).
    - shows the loading state, then the table.
    - renders `users-error` when the request fails.
    - debounces the search box — three `setValue` calls inside the window produce **one** `listUsers` call, driven by `vi.useFakeTimers()`.
    - resets to page 1 when the search changes.
    - disables `users-prev` on the first page and `users-next` on the last.
    - renders the count from `meta` without printing `null` on an empty page.
    - distinguishes "no users match your search" from "no users yet".
    - renders no ticket-count column. The deferral, pinned — so the story that adds the field has to add a test with it and this one is updated deliberately rather than by accident.

12. **Create `frontend/src/components/UserFormDialog.spec.ts`** — nine tests:
    - create mode shows a password field; edit mode does not render one at all.
    - create mode submits name, email, password and role.
    - edit mode submits **only changed** fields.
    - a `422` renders the message under the matching input — assert `user-form-error-email` specifically.
    - a self-lockout `422` on `errors.is_active` renders under the active toggle.
    - a `403` renders the form-level permission message via `errorMessage`.
    - a network error renders the unreachable fallback.
    - the role select and active toggle are `disabled` when the row is the signed-in user's own.
    - `saved` is emitted on success and `close` on cancel.

13. **TM-11's `src/views/LoginView.spec.ts` must pass unchanged.** Task 9 replaces `LoginView`'s private `messageFor` with the shared `errorMessage`, and those two specs — the `422` message and the `429` message — are the proof the refactor preserved behaviour. If either needs editing, the refactor changed something; fix `errorMessage`, not the test.

14. **`src/stores/auth.spec.ts` needs no edit and no new tests.** Its mock factory spreads `loadOriginal()` and names only `login`, `logout` and `me`, so `isUnauthorized` moving to `../api/errors` is invisible to it. All of TM-11's assertions in that file must still pass untouched — if any of them break, task 9's move changed behaviour rather than location.

Frontend total added: **44 tests** (9 + 6 + 11 + 9 + 9), taking the suite from 53 to **97**.

**Combined: 89 new tests.**

---

## Verification Steps

Run in this order. The working directory is stated for every command.

1. **Prerequisites are real:** `backend/` — `php artisan route:list --path=auth --columns=method,uri,name,middleware` lists `auth.login`, `auth.logout` and `auth.me`, and `grep -c HasApiTokens app/Models/User.php` prints `2`. `frontend/` — `ls src/stores/auth.ts src/views/AdminUsersView.vue` succeeds. **If any of that fails, stop** — TM-9, TM-10 or TM-11 has not landed.
2. **Record the real baseline:** `backend/` — `composer test`, and write the number down. `frontend/` — `npm run test`, same. The counts in this plan's Test Plan were derived by reading files during a platform outage; use your measured numbers.
3. **Services healthy:** repo root — `docker compose up -d && docker compose ps`; all three healthy. `backend/` — `php artisan migrate:fresh --seed`, then `php artisan serve`. `frontend/` — `npm run dev`.
4. **Routes are wired and gated:** `backend/` — `php artisan config:clear && php artisan route:clear`, then:

    ```bash
    php artisan route:list --path=admin --columns=method,uri,name,middleware
    ```

    Exactly four rows — `GET`, `POST` on `api/v1/admin/users` and `GET`, `PATCH` on `api/v1/admin/users/{user}` — each listing **`auth:sanctum`**, **`active`** and **`admin`**. **No `DELETE` row.** A missing `active` means the group was nested wrongly; a missing `admin` means the alias or the group middleware is wrong.
5. **Sign in as the admin and list:** `backend/` —

    ```bash
    TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d '{"email":"admin@ticket-management.test","password":"password"}' \
      | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')

    curl -s http://localhost:8000/api/v1/admin/users \
      -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" | python3 -m json.tool
    ```

    Top-level keys are exactly `data`, `links`, `meta`. `meta.per_page` is `15`. The body contains no `$2y$`.
6. **Create an agent, and check the role actually landed:** `backend/` —

    ```bash
    curl -s -X POST http://localhost:8000/api/v1/admin/users \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
      -d '{"name":"Agent Smith","email":"smith@ticket-management.test","password":"password","role":"agent"}' \
      -w '\nstatus=%{http_code}\n'

    php artisan tinker --execute="\$u = App\Models\User::where('email','smith@ticket-management.test')->sole(); echo \$u->role->value, PHP_EOL;"
    ```

    `status=201`, and tinker prints **`agent`**. Repeat with `"role":"admin"` for a second address and confirm tinker prints **`admin`** — this is the check that the `#[Fillable]` trap was avoided, and the response body alone cannot prove it.
7. **Wrong-case role is a 422, not a 500:** send `"role":"ADMIN"`. **`422`**. A `500` means `Rule::in` was used instead of `Rule::enum`.
8. **The literal `%` search:** `curl -s '…/admin/users?search=%25' …` (URL-encoded `%`). `meta.total` is **`0`**. If it equals the full user count, `addcslashes` was dropped.
9. **`per_page` is capped:** `?per_page=1000` returns **`422`** on `per_page`.
10. **Deactivation revokes tokens:** create an agent, log in **as that agent** to mint a token, then as the admin `PATCH` them to `is_active=false`, then:

    ```bash
    php artisan tinker --execute="echo App\Models\User::where('email','smith@ticket-management.test')->sole()->tokens()->count(), PHP_EOL;"
    ```

    prints **`0`**. Replay the agent's token against `/api/v1/auth/me`: **`401`**.
11. **Demotion does not revoke:** promote a second account to admin, log in as them to mint a token, then demote them to `agent` from the first admin's session. Their token count is **`1`** and `/auth/me` still returns `200` — with `role` now `agent`.
12. **Self-lockout is refused:** as the admin, `PATCH` your own id with `{"is_active":false}` → **`422`**, `errors.is_active` present, and `php artisan tinker --execute="echo (int) App\Models\User::sole()->is_active;"`-style check confirms you are still active. Repeat with `{"role":"agent"}` → `422` on `errors.role`.
13. **Last-admin is refused:** with exactly two admins, deactivate the *other* one → `200`. Now try to deactivate the remaining one from a session belonging to... you cannot, which is the point: verify instead by promoting a third admin, deactivating admins two and three, then attempting to deactivate admin one from admin one's session → **`422`**. Restore with `php artisan migrate:fresh --seed`.
14. **An agent is refused:** log in as the agent and call all four endpoints. Every one is **`403`** with `{"message":"This action is unauthorized."}`. Confirm `DELETE /api/v1/admin/users/1` as the **admin** returns **`405`**.
15. **Backend gates:** `backend/` — `composer test` exits `0` with **45 new tests**, and every pre-existing count unchanged. `composer lint` exits `0`.
16. **The screen, in a browser:** as the admin, visit **http://localhost:5173/admin/users**. The table lists users with Name, Email, Role and Active. Type `%` into search — the empty state says no users match and offers to clear, and the Network tab shows **one** request, not one per keystroke. Create a user through the dialog; the list reloads and includes them. Edit that user's name; it updates. Open your **own** row — the role select and active toggle are disabled with an explanation.
17. **A validation error lands on the right field:** in the create dialog, enter an email that already exists. The message renders under the email input, not as a form-level banner.
18. **An agent cannot reach the screen:** sign in as the agent and visit `/admin/users`. TM-11's guard sends you to **`/forbidden`** — you never see a `403`, because the SPA never issues the request.
19. **Frontend gates:** `frontend/` — `npm run format && npm run lint && npm run typecheck && npm run test`, all exiting `0`, with **44 new tests** and TM-11's `LoginView.spec.ts` and `auth.spec.ts` assertions unchanged. Then `npm run format:check` exits `0` on its own.
20. **Contract matches the code:** read `docs/api-contract.md`'s four new sections against the real responses from steps 5–14, field for field, including the `tickets_count` deferral note.
21. **Regression:** repo root — `git status --short` shows no change to `docker-compose.yml`, `README.md`, `CLAUDE.md`, `docs/erd.md`, `backend/composer.json`, `backend/composer.lock`, `backend/phpunit.xml`, `backend/config/*`, `frontend/package.json`, `frontend/package-lock.json`, `frontend/vite.config.ts`, `frontend/tsconfig*.json` or `frontend/src/router/index.ts`.

---

## Done Criteria

- [ ] `GET /api/v1/admin/users` returns `UserResource::collection` in the `data` / `links` / `meta` envelope, paginated at **15** by default with `per_page` **capped at 100** (a higher value is a `422`, not a slow success), ordered by `name` then `id`, and `->withQueryString()` keeps filters in `links.next`.
- [ ] Search matches `name` or `email` and treats `%` and `_` as **literal characters** — a `?search=%` request returns `meta.total` of `0`, pinned by a test.
- [ ] `?status=active|inactive` filters via a new `User::scopeActive()`; an unknown value is a `422`. **No index was added to `role` or `is_active`**, and the plan's reason is recorded rather than silently ignored.
- [ ] `POST /api/v1/admin/users` returns **`201`**, requires `role`, validates a unique email and `Password::defaults()`, and **assigns `role` explicitly** the way `AdminUserSeeder.php:31` does. A test reloads the created user **from the database** and asserts an admin is an admin.
- [ ] `role` validation uses `Rule::enum(UserRole::class)`, so `"ADMIN"` is a **`422`** and never reaches MySQL as a `500`.
- [ ] `PATCH /api/v1/admin/users/{user}` is partial (`sometimes` + `required`), uses `Rule::unique('users','email')->ignore($user)` so an unchanged email saves, and has **no `password` field**.
- [ ] Deactivating a user calls `$user->tokens()->delete()` — TM-10's assigned debt — and a test asserts the count drops to `0` and the token then `401`s. **Demoting does not revoke**, pinned by its own test.
- [ ] `App\Http\Middleware\EnsureUserIsAdmin` exists, is registered as the `admin` alias alongside TM-10's `active`, throws `AuthorizationException`, and gates a group **nested inside** `['auth:sanctum', 'active']`. An agent gets **`403`** with `This action is unauthorized.`; an unauthenticated caller gets `401`; a deactivated admin gets `401`.
- [ ] **There is no `DELETE` route** — `DELETE /api/v1/admin/users/{user}` is a `405`, pinned by a test, and the controller docblock says why.
- [ ] An admin cannot deactivate or demote **themselves** (`422` on `errors.is_active` / `errors.role`) **or the last active admin**, enforced inside `DB::transaction()` with `lockForUpdate()` in the controller — not in the FormRequest, because a validator cannot hold the lock. A negative-control test proves a second admin *can* be deactivated when a third remains.
- [ ] `tests/Feature/Auth/ProtectedRouteTest.php`'s route-table test now covers **every** named route against an explicit public allow-list (`health`, `auth.login`) instead of only `auth.*`, plus a second test asserting every `admin.*` route carries `admin` — with `assertGreaterThan(0, …)` so a renamed prefix cannot make it pass vacuously.
- [ ] `frontend/src/api/pagination.ts` exports a reusable `Paginated<T>`; `frontend/src/api/errors.ts` exports `isUnauthorized`, `validationErrors` and `errorMessage`, with `429` and `403` mapped to readable text **before** the server's own message is used.
- [ ] `isUnauthorized` was **moved** out of `src/api/auth.ts`, `src/stores/auth.ts`'s import updated, and `LoginView.vue`'s private `messageFor` (currently at lines 29–38) deleted in favour of `errorMessage` — with **TM-11's `LoginView.spec.ts` and `auth.spec.ts` passing unchanged** as the proof it was behaviour-preserving. `auth.spec.ts`'s mock factory needs no edit; it spreads `loadOriginal()` and never named `isUnauthorized`.
- [ ] `frontend/src/stores/users.ts` discards stale responses via a per-Pinia-instance request counter (tested by resolving two calls out of order), resets to page 1 on any filter change, reloads after create and update rather than splicing locally, and **re-throws** from `create`/`update` so the form can read field errors.
- [ ] `AdminUsersView.vue` replaces TM-11's placeholder with a searchable, filterable, paginated table (Name, Email, Role, Active, Edit), debounces search by 300 ms with a hand-rolled timer, disables prev/next at the ends from `meta.current_page` and `meta.last_page`, renders no `null` in its count on an empty page, and distinguishes "no matches" from "no users". `frontend/src/router/index.ts` is **unchanged**.
- [ ] `UserFormDialog.vue` handles both modes — password field present on create and **absent** on edit — sends only changed fields on edit, renders `422` messages under the matching inputs including the self-lockout ones, and disables the role select and active toggle for the signed-in user's own row **as a courtesy, with the server still refusing**.
- [ ] `composer test` and `npm run test` both exit `0` with **45** and **44** new tests; `composer lint`, `npm run lint`, `npm run typecheck` and `npm run format:check` all exit `0`.
- [ ] `docs/api-contract.md` documents all four endpoints, the pagination envelope as a `## Conventions` entry (including `meta.links` as present-but-unused), and a note that **`tickets_count` is deferred to TM-21**.
- [ ] **Acceptance criterion 1's ticket count is explicitly not shipped**, the plan's four-step diff for TM-21 is recorded in `00-overview.md`, and no column of zeros was added in its place.
- [ ] No new dependency on either side; `backend/config/*`, `phpunit.xml`, `docs/erd.md` and `CLAUDE.md` untouched.
- [ ] `00-overview.md` updated with this story, the ticket-count deferral, the lockout-race finding, and the note that **TM-13 refines this story's middleware rather than replacing it**.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 11 (TM-13).**
