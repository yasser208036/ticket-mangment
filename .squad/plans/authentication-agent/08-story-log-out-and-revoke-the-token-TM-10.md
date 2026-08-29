# Story 08 — Log out and revoke the token (Story: TM-10)

## Prerequisites

- **Story 07 (TM-9) must be _implemented_, not merely planned:** [`07-story-log-in-and-receive-an-api-token-TM-9.md`](07-story-log-in-and-receive-an-api-token-TM-9.md). Hard blocker, and confirmed not done today: `backend/routes/api.php` is still 18 lines with exactly one route (`GET /health`, line 18), `backend/app/Http/` contains only `Controllers/`, and **`app/Models/User.php:18` is still `use HasFactory, Notifiable;`** — no `HasApiTokens`. Three consequences:
  1. **There is no way to obtain a token**, so every test in this story would have nothing to log out with.
  2. `App\Http\Resources\V1\UserResource` does not exist, and task 2 returns it.
  3. `App\Http\Controllers\Api\V1\Auth\` does not exist; TM-9 creates that directory.

  Confirm before starting: `php artisan route:list --path=auth` shows `POST api/v1/auth/login … auth.login`, and `grep -n HasApiTokens backend/app/Models/User.php` returns a hit.
- **Story 06 (TM-8) must be implemented too** — transitively via TM-9, but it matters directly here: task 3 reads `is_active` and the tests need `User::factory()->inactive()`. Confirm with `php artisan db:table users` listing **`role`** and **`is_active`**.
- **Docker services running:** repo root — `docker compose up -d`. Verified healthy at planning time: `tm-mysql` (3306), `tm-mysql-test` (3307), `tm-mailpit` all report `Up (healthy)`. The suite migrates against `127.0.0.1:3307` (`backend/phpunit.xml:36-41`).
- **Regression baseline, measured today:** `composer test` from `backend/` passes — **6 tests, 25 assertions** (`HealthTest` 3, `HealthDegradedTest` 2, `Unit\ExampleTest` 1), and `./vendor/bin/pint --test` exits `0`. With TM-8 (+21) and TM-9 (+16) landed the baseline for this story is **43 tests**.
- **No new dependency.** `laravel/sanctum v4.3.3` is installed and `personal_access_tokens` already has its migration (`database/migrations/2026_08_25_075421_create_personal_access_tokens_table.php`, 33 lines). `backend/composer.json` and `backend/composer.lock` must be unchanged, and **no migration is added**.

---

## Story Goal

The other half of TM-9. TM-9 opened the login door and left a note saying the existing-token door was still open; this story closes it. Two authenticated endpoints — `POST /api/v1/auth/logout` and `GET /api/v1/auth/me` — and the first `auth:sanctum` route group in the project, which every later story copies.

Audit of the three acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | `POST /api/v1/auth/logout` deletes the current access token and returns `204` | ❌ **Not met.** `routes/api.php` has one route, and there is no `Auth/` directory under `app/Http/Controllers/Api/V1/`. The interesting part is not the controller — it is that the canonical way to test this **passes without deleting anything**. See "The test that revokes nothing" below. |
| 2 | Reusing the revoked token afterwards returns `401` | ✅ **Free, once criterion 1 is real** — and worth a test precisely because it is free. `Guard::__invoke()` calls `PersonalAccessToken::findToken()` (`vendor/laravel/sanctum/src/Guard.php:43`), which returns `null` for a deleted row; `isValidAccessToken(null)` returns `false` (`Guard.php:121-124`) and the guard returns `void`, so `Authenticate::authenticate()` throws (`vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php:87,99-106`). The `401` body is `{"message":"Unauthenticated."}` because `bootstrap/app.php:22-24` renders JSON for `api/*` — **without that, a caller sending no `Accept` header would get an empty-bodied `401`** (`Handler.php:851-852`). |
| 3 | `GET /api/v1/auth/me` returns the authenticated user for a valid token | ❌ **Not met.** No protected route exists — TM-9 deliberately added none. This story creates the first one, so it also decides the response envelope and the middleware stack every later protected route inherits. |

Five outcomes:

1. `POST /api/v1/auth/logout` returns **`204`** with an empty body and deletes **exactly one** row from `personal_access_tokens` — the token that authenticated the request, not the user's other sessions.
2. The same token replayed against any protected route returns **`401`**.
3. `GET /api/v1/auth/me` returns `{ "user": … }` rendered by `UserResource`, the same object shape TM-9's login response nests under the same key.
4. A user whose `is_active` is `false` cannot use a token that was issued before they were deactivated — the second of the "two doors" TM-9's plan named and left open.
5. `docs/api-contract.md` gains its third and fourth endpoints and its first `Auth: bearer token` rows.

**Not in scope:** the SPA's logout button, auth store and route guards (**TM-11** — this story touches no file under `frontend/`); admin CRUD for agents, including the deactivation screen that makes task 3 reachable through the UI (**TM-12**); policies and `403`s (**TM-13**); password change and the "log out everywhere after a password change" behaviour that usually comes with it (**TM-14**); token expiry, token prefixes, pruning and API-wide throttling (**TM-64**); and a "log out of every device" endpoint, which no story in `tools/jira/backlog.json` asks for — **do not** add one speculatively.

---

## Product rules

### The test that revokes nothing

The obvious way to test a logout endpoint is Sanctum's own testing helper:

```php
Sanctum::actingAs($user);

$this->postJson('/api/v1/auth/logout')->assertNoContent();   // passes. revokes nothing.
```

`Sanctum::actingAs()` does not create a token. It attaches a **Mockery mock** to the user (`vendor/laravel/sanctum/src/Sanctum.php:72`):

```php
$token = Mockery::mock(self::personalAccessTokenModel())->shouldIgnoreMissing(false);
```

`shouldIgnoreMissing(false)` sets `_mockery_ignoreMissing = true` **and** `_mockery_defaultReturnValue = false` (`vendor/mockery/mockery/library/Mockery/Mock.php:349-355`). Any un-stubbed call therefore falls through to `Mock.php:1091-1101` and returns `false` instead of throwing. Measured during planning against the installed tree:

```
class            : Laravel\Sanctum\PersonalAccessToken
instanceof PAT   : yes
delete() returned: false      <-- no exception, no database write
id attribute     : false
```

So `$request->user()->currentAccessToken()->delete()` under `Sanctum::actingAs()` is a **silent no-op that returns a `204`**. Acceptance criterion 1 has two halves — "deletes the current access token" and "returns 204" — and this test proves only the second. Criterion 2 is worse off: there is no token to replay, so the `401` can never be observed.

**Every test in this story authenticates with a real token**, obtained from `$user->createToken('spa')->plainTextToken` and sent with `withToken(...)`. `Sanctum::actingAs()` is banned in `tests/Feature/Auth/LogoutTest.php`; the Test Plan pins the ban with a row-count assertion that the mock cannot satisfy. It stays useful for stories that only need *an* authenticated user (TM-12, TM-13) and never touch the token itself.

### Revoke one token, not all of them

TM-9 chose multi-device deliberately — `test_it_does_not_revoke_existing_tokens` asserts that logging in twice leaves two rows. Logout has to respect that: signing out on a phone must not sign out a laptop.

```php
$request->user()->currentAccessToken()->delete();   // one row
$request->user()->tokens()->delete();               // WRONG here — every device
```

`tokens()` is the `morphMany` on `HasApiTokens` (`vendor/laravel/sanctum/src/HasApiTokens.php:25-28`); `currentAccessToken()` returns the single `PersonalAccessToken` the guard resolved for *this* request (`HasApiTokens.php:94-97`, set from `Guard.php:50-52`). The Test Plan asserts the surviving row count, not just the deleted one, so a later "simplification" to `tokens()->delete()` fails a test rather than silently signing everyone out.

The one place `tokens()->delete()` **is** correct is task 3, where the account itself is being cut off rather than one session.

### `/auth/me` and the second door

TM-8's overview recorded the hand-off and TM-9's plan repeated it: *"`is_active` is enforced nowhere"* and *"a user deactivated after signing in keeps a working token indefinitely."* TM-9 closed the login door. This story creates the **first authenticated routes in the project**, which makes it the first story that can close the other one — and the last story that can close it cheaply, because TM-11 through TM-14 will all copy whatever route group this story writes.

`config/sanctum.php:53` sets `'expiration' => null`, so nothing expires the token on its own. Without task 3, `GET /auth/me` returns `200` and a full user object to someone an administrator has just disabled, and acceptance criterion 3 — read literally — would enshrine that.

**Task 3 is therefore a deliberate widening of TM-10's stated scope**, and the only one in this plan. It is one middleware class, one alias, and three tests. If it is dropped, the fallback is not "nothing": TM-12 must then both delete tokens on deactivation **and** carry the middleware itself, and that has to be written into the overview at the same time the task is dropped.

The middleware answers **`401`**, not `403`, and revokes the account's tokens on the way out:

- `401` is honest once the tokens are gone — the caller genuinely has no valid credential any more.
- `403` would leave the SPA holding a dead token with no handler: `frontend/src/api/client.ts:28` acts on `401` only, and on `401` it calls `clearToken()` and the unauthorized handler. A `403` would show an error screen and keep the token in `localStorage`.
- Revoking on the way out means deactivation bites **once**, on the next request, instead of on every request forever.

**TM-12 must still call `$user->tokens()->delete()` when an admin deactivates an account.** Task 3 is the backstop, not the replacement: the middleware cuts access on the deactivated user's *next* request, while TM-12's delete cuts it at the moment the admin clicks the button.

### The response envelope for `/auth/me`

`GET /auth/me` returns:

```json
{ "user": { "id": 1, "name": "…", "email": "…", "role": "admin", "is_active": true, "created_at": "…" } }
```

Not `UserResource::make($user)` returned directly, which would wrap it as `{"data": {…}}`. TM-9's login response nests the same object under `user`, so the SPA parses **one** shape from both endpoints and TM-11's Pinia store needs a single type. Task 2 therefore calls `->toArray($request)` exactly the way TM-9's `LoginController` does.

The cost, stated so nobody treats it as an accident: **`data` remains the wrapper for resource *collections*** — TM-12's paginated user list will return `{"data": […], "links": …, "meta": …}`, because that is what `ResourceCollection` produces and rewriting pagination metadata to match an auth envelope would be worse. The convention is "auth endpoints name their payload; resource endpoints use `data`", and task 6 writes that sentence into `docs/api-contract.md`. **Do not** set `JsonResource::$wrap = null` globally to paper over it — TM-9's plan already rules that out for the same reason.

---

## Context — Read These Files First

1. `backend/routes/api.php` — all 18 lines **as TM-9 leaves them**. The header comment (6–14) states the contract: *"Authentication is a Sanctum bearer token; there is no session."* Task 5 adds the first `Route::middleware(...)->group(...)` in the file; keep the comment style, which explains *why* a route exists rather than restating its path.
2. `backend/bootstrap/app.php` — all 25 lines. Three regions matter:
   - **line 16, `apiPrefix: 'api/v1'`** — task 5 writes `/auth/logout`, never `/api/v1/auth/logout`.
   - **lines 18–20, `withMiddleware`, currently an empty `//` stub** — task 4 makes the first edit this project has ever made there.
   - **lines 21–24, `shouldRenderJsonWhen`** — this is what turns the `401` into `{"message":"Unauthenticated."}` instead of an empty body. Confirm by reading `vendor/laravel/framework/src/Illuminate/Foundation/Exceptions/Handler.php:843-856`: JSON gets `response()->json(['message' => …], 401)` (line 846); non-JSON with no redirect gets `response()->noContent(401)` (line 852).
3. `backend/vendor/laravel/sanctum/src/Guard.php:30-62` — the whole `__invoke`. Read it in this order:
   - **lines 32–38** — the configured guards (`config('sanctum.guard')` is `['web']`, `backend/config/sanctum.php:40`) are tried **first**, and a user found that way gets a **`TransientToken`**, not a `PersonalAccessToken`. See the `TransientToken` edge case below.
   - **lines 40–61** — the bearer path this project always takes, because `EnsureFrontendRequestsAreStateful` is never applied.
   - **line 43 with `PersonalAccessToken::findToken()`** (`vendor/laravel/sanctum/src/PersonalAccessToken.php:58-69`) — a deleted row yields `null`, which is the whole mechanism behind acceptance criterion 2.
   - **lines 56–58, `updateLastUsedAt()`** — the guard **writes** to the token row on every authenticated request, including the logout request that is about to delete it.
4. `backend/vendor/laravel/sanctum/src/HasApiTokens.php` — `tokens()` (25–28), `createToken()` (60–72), `currentAccessToken()` (94–97), `withAccessToken()` (105–110). Note that `$accessToken` is a plain protected property with no default, so `currentAccessToken()` returns `null` when nothing set it.
5. `backend/vendor/laravel/sanctum/src/Sanctum.php:70-93` and `backend/vendor/laravel/sanctum/src/TransientToken.php` (all 30 lines). Read both before writing a single test. `actingAs` builds the mock described in "The test that revokes nothing"; `TransientToken` implements only `can()` and `cant()` — **it has no `delete()`**.
6. `backend/vendor/mockery/mockery/library/Mockery/Mock.php:349-355` and `1091-1101` — the two regions that make `shouldIgnoreMissing(false)` return `false` from an un-stubbed `delete()` rather than throwing. This is the mechanism behind the measurement above; read it if the measurement looks wrong.
7. `backend/app/Http/Controllers/Api/V1/HealthController.php` — all 55 lines. The controller idiom to match: `__invoke()` for a single action, an explicit return type, and a class docblock that explains *why* the endpoint behaves as it does. **Note the return type is `JsonResponse` — task 1 must not copy it.** `response()->noContent()` returns `Illuminate\Http\Response` (`vendor/laravel/framework/src/Illuminate/Routing/ResponseFactory.php:71-74`), so a `: JsonResponse` signature is a `TypeError` at runtime.
8. `backend/app/Http/Controllers/Api/V1/Auth/LoginController.php` — **created by TM-9.** Read its `__invoke` end to end. Task 2 reproduces its `'user' => UserResource::make($user)->toArray($request)` line exactly; a divergence here is a contract break the SPA finds, not a style difference.
9. `backend/app/Http/Resources/V1/UserResource.php` — **created by TM-9.** Confirm the field list is `id`, `name`, `email`, `role`, `is_active`, `created_at`. Task 2 adds no fields; task 6 documents the same list.
10. `backend/app/Models/User.php` — all 32 lines. **This story does not edit it.** Confirm TM-9 added `HasApiTokens` to line 18 and TM-8 added `is_active` to the `#[Fillable]` attribute (line 13) and a `boolean` cast in `casts()` (25–31). Task 3 reads `is_active` as a bool; if the cast is missing it will read the string `"0"`, which is truthy.
11. `backend/app/Http/Middleware/` — **does not exist.** `find backend/app -type f` returns four files today: `Http/Controllers/Api/V1/HealthController.php`, `Http/Controllers/Controller.php`, `Models/User.php`, `Providers/AppServiceProvider.php`. Task 3 creates the directory and the project's first middleware.
12. `backend/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php` — `alias()` at **398–403**, and `getMiddlewareAliases()` at **793–796**, which is `array_merge($this->defaultAliases(), $this->customAliases)`. **Registering a custom alias does not drop the built-in ones** — `auth` (line 806) and `throttle` keep working. Read this before task 4 so nobody re-lists the defaults defensively.
13. `backend/vendor/laravel/framework/src/Illuminate/Auth/AuthenticationException.php:38-44` — `__construct($message = 'Unauthenticated.', array $guards = [], $redirectTo = null)`. Task 3 throws it with no arguments. It is dispatched by `Handler.php:718` (`$e instanceof AuthenticationException => $this->unauthenticated($request, $e)`) and it is in `$internalDontReport` (`Handler.php:170-171`), so a deactivated user hitting the API does **not** fill `storage/logs` with stack traces.
14. `backend/tests/Feature/HealthTest.php` (39 lines) and `HealthDegradedTest.php` (37 lines) — the **local test precedent**: `Tests\Feature` namespace, `use RefreshDatabase;`, `test_it_…()` snake-case names, chained `assertJsonPath` / `assertJsonStructure` / `assertJsonMissingPath`, `config()->set(...)` for per-test variation, and a `private` helper at the bottom when a fixture repeats (`HealthDegradedTest:32-37`). Match all of it. TM-9 adds `tests/Feature/Auth/`; this story's files go in the same directory and namespace.
15. `backend/vendor/laravel/framework/src/Illuminate/Testing/Concerns/AssertsStatusCodes.php:45-52` — `assertNoContent($status = 204)` asserts the status **and** `assertEmpty($this->getContent())`. Both `response()->noContent()` and `response()->json(null, 204)` satisfy it — verified by preparing each against a request during planning, both produce status `204`, an empty body and no `Content-Type`. Task 1 uses `noContent()` because it says what it means.
16. `backend/phpunit.xml` — **line 25, `CACHE_STORE=array`** (throttle state cannot leak between tests) and **36–41**, the `tm-mysql-test` connection on port 3307 with `ticket_user` / `secret`. The comment at 27–35 explains why the suite never uses SQLite; this story adds no schema, so it is context rather than a constraint.
17. `frontend/src/api/client.ts` — all 34 lines, created by TM-4. **Line 5** is `const LOGIN_PATH = '/auth/login'` and **line 28** is `if (error.response?.status === 401 && error.config?.url !== LOGIN_PATH)` → `clearToken()` + `onUnauthorized()`. Read it before choosing task 3's status code, and note it is the reason `401` beats `403` there. `frontend/src/api/token.ts:19-25` is `clearToken()`. **This story changes neither file** — TM-11 owns the SPA side.
18. `docs/api-contract.md` — all 35 lines. The endpoint table is 17–19 (`| Method | Path | Purpose | Auth | Owning story |`) and the per-endpoint detail starts at 21 with a `### GET /api/v1/health` heading. TM-9 adds a `POST /api/v1/auth/login` row and section; task 6 follows in the same format. The `## Conventions` block (7–13) is where the envelope sentence goes.
19. Grep for `currentAccessToken` across `backend/app/` and `backend/tests/` when you are done. **Exactly one hit** should remain — task 1's controller. A second hit in a test means that test is inspecting the guard's plumbing instead of the endpoint's behaviour.

---

## Backend Tasks

**No frontend changes.** `frontend/src/api/client.ts` already handles a `401` by clearing the token and redirecting; the logout button, the auth store and the guards are **TM-11**. `git status --short` must list no path under `frontend/` when this story is done.

### 1 — The logout controller

**Create file: `backend/app/Http/Controllers/Api/V1/Auth/LogoutController.php`**

The `Auth/` directory is created by TM-9; if it is missing, TM-9 has not landed — stop and re-read the Prerequisites.

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Revoke the token that authenticated this request.
 *
 * One token, not all of them: TM-9 chose multi-device deliberately, so signing
 * out on a phone must leave the laptop signed in. Sanctum stores only the
 * SHA-256 of a token, so "revoke" can only mean deleting the row — there is
 * nothing to mark as spent.
 */
class LogoutController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // The guard resolved this instance from the bearer token on the way in
        // (Guard.php:50-52). $user->tokens()->delete() would sign out every
        // device; ->currentAccessToken() is exactly this session.
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
```

Four details that are not stylistic:

- **`Illuminate\Http\Response`, not `JsonResponse`.** `response()->noContent()` returns the former (`ResponseFactory.php:71-74`). Copying `HealthController`'s `: JsonResponse` gives *"Return value must be of type Illuminate\Http\JsonResponse, Illuminate\Http\Response returned"* — a `500` on the happy path, which no validation test will catch.
- **No null check on `currentAccessToken()`.** On this route it cannot be null: `auth:sanctum` has already run, and the only two ways to reach the controller both set an access token — the bearer path (`Guard.php:50-52`) and the session path (`Guard.php:34-36`, unreachable here). A defensive `?->` would convert the `TransientToken` misconfiguration described in Edge Cases into a silent `204` that revokes nothing, which is the exact failure this story exists to prevent. **Let it fatal.**
- **No `204` literal.** `noContent()` already defaults to `204`.
- **No response body, not even `{"message": "Logged out"}`.** `204` forbids one, and `assertNoContent()` asserts the body is empty (`AssertsStatusCodes.php:45-52`).

### 2 — The `me` controller

**Create file: `backend/app/Http/Controllers/Api/V1/Auth/MeController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated user, for a client that has a token but no user object —
 * a reloaded SPA tab, most of the time.
 *
 * Nested under "user" rather than returned as a bare resource, so the payload
 * is byte-identical to the "user" key of the login response and the SPA parses
 * one shape from both endpoints.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'user' => UserResource::make($request->user())->toArray($request),
        ]);
    }
}
```

- **`->toArray($request)`, matching `LoginController`.** Returning `UserResource::make(...)` directly would wrap the payload as `{"data": …}` — a second shape for the same object. See "The response envelope" above.
- **No `is_active` branch here.** Task 3's middleware makes it unreachable, and duplicating the check in the controller means two places to forget.
- **This endpoint does not refresh the token, extend anything, or return a new one.** It is a read. `Guard::updateLastUsedAt()` (`Guard.php:56-58`) already stamps `last_used_at` on the way in, which is the only side effect there should be.

### 3 — The active-account middleware

**Create file: `backend/app/Http/Middleware/EnsureUserIsActive.php`**

`app/Http/Middleware/` does not exist; create it. This is the project's first middleware. Read "The second door" above before writing it — the `401`-and-revoke choice is the point, not an implementation detail.

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a token that outlived its account.
 *
 * TM-9 stopped a deactivated user from logging IN, but tokens never expire
 * (config/sanctum.php:53) so one issued before the deactivation kept working.
 * This closes that door on the user's next request rather than on their next
 * login — which, for someone who never logs out, would be never.
 *
 * The tokens are deleted on the way out so the refusal costs one request
 * instead of recurring forever, and the answer is 401 rather than 403 because
 * once they are gone the caller genuinely holds no credential: the SPA's
 * interceptor (frontend/src/api/client.ts:28) then clears storage and returns
 * the user to the login screen, where TM-9 gives them the generic message.
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Every device, not just this one — the account is cut off, not
            // this session. Contrast LogoutController, which deletes exactly
            // one row on purpose.
            $user->tokens()->delete();

            throw new AuthenticationException;
        }

        return $next($request);
    }
}
```

- **`$user &&` guards the null case** so the middleware is harmless if it is ever attached to a route without `auth:sanctum`. It is not a substitute for `auth:sanctum` and must never be used alone — task 5 always pairs them.
- **`throw new AuthenticationException`, no arguments.** The default message is `'Unauthenticated.'` (`AuthenticationException.php:38`), which makes a deactivated account indistinguishable from a revoked token — the same reasoning that gave TM-9 one message for three login failures. `Handler.php:718` renders it as `401`, and `Handler.php:170-171` keeps it out of the error log.
- **`Symfony\Component\HttpFoundation\Response`** is the correct return type for middleware — `Illuminate\Http\Response` is narrower than what `$next` can return.
- **Do not** re-implement this as a check inside `MeController`. It is on the route group so that TM-11, TM-12, TM-13 and TM-14 inherit it by adding routes to that group and doing nothing else.

### 4 — Register the alias

**File: `backend/app/Providers/../../bootstrap/app.php`** — that is `backend/bootstrap/app.php`.

Fill in the empty `withMiddleware` stub at lines 18–20:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        // 'auth', 'throttle' and the rest survive this: getMiddlewareAliases()
        // merges custom aliases over the defaults rather than replacing them
        // (Foundation/Configuration/Middleware.php:793-796).
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
        ]);
    })
```

Add the import at the top of the file, next to the four existing ones:

```php
use App\Http\Middleware\EnsureUserIsActive;
```

The alias is `active` and not `is_active` or `ensure.active` because task 5 reads as `->middleware(['auth:sanctum', 'active'])`, which is a sentence. **The string must match task 5 exactly** — an unregistered alias is treated as a class name and throws `BindingResolutionException` (*"Target class [active] does not exist"*), a `500` on the first request rather than a route that quietly skips the check.

### 5 — The routes

**File: `backend/routes/api.php`**

Add two imports and the project's first authenticated group. `apiPrefix` is already `api/v1` (`bootstrap/app.php:16`), so the paths are `/auth/logout` and `/auth/me`:

```php
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\MeController;
```

```php
// Everything past this point needs a bearer token AND a live account. The
// second half is not redundant: tokens never expire (config/sanctum.php:53),
// so without 'active' a token issued before an account was deactivated keeps
// working forever. Add protected routes to this group, not beside it.
Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    // Revokes only the token that made this request, so other devices stay
    // signed in. Not throttled: it is authenticated, and the worst a caller
    // can do by repeating it is 401 themselves.
    Route::post('/auth/logout', LogoutController::class)->name('auth.logout');

    // Lets a client that has a token but no user object — a reloaded SPA tab —
    // recover the user without asking for the password again.
    Route::get('/auth/me', MeController::class)->name('auth.me');
});
```

- **A group, not two `->middleware()` calls.** The group is the artefact this story is really delivering: TM-11's guarded routes, TM-12's admin endpoints and TM-13's policy-checked actions all belong inside it. Two independent routes invite the fifth one to be added outside.
- **`POST` for logout, `GET` for `me`.** Logout changes server state, so it must not be a `GET` — a prefetching browser or a link-scanning proxy would sign users out.
- **Order matters within the array.** `auth:sanctum` first: `active` reads `$request->user()`, which is only populated once the guard has run.
- **`->name('auth.logout')` and `->name('auth.me')`** so tests assert the resolved path the way `HealthTest:37` does.
- **No `throttle`.** Both routes are authenticated; API-wide throttling is **TM-64**. Do not attach the `login` limiter TM-9 registered — its budget is per IP and would let one noisy tab lock out an office.

### 6 — The contract

**File: `docs/api-contract.md`**

Add one sentence to `## Conventions` (after line 11), two rows to the endpoint table, and two detail sections after TM-9's login section, in the established format.

Convention sentence:

````markdown
- Authentication endpoints name their payload (`user`); resource endpoints and
  collections use Laravel's default `data` wrapper. The login and `me`
  responses therefore share one `user` shape, while TM-12's paginated user list
  will be `{ "data": [...], "links": ..., "meta": ... }`.
````

Table rows:

````markdown
| `POST` | `/api/v1/auth/logout` | Revoke the token that made the request. Other devices stay signed in. | bearer | TM-10 |
| `GET` | `/api/v1/auth/me` | The authenticated user, for a client holding a token but no user object. | bearer | TM-10 |
````

Detail sections:

````markdown
### `POST /api/v1/auth/logout`

Requires `Authorization: Bearer <token>`. Deletes **that one token** and nothing
else — a user signed in on two devices stays signed in on the other.

**`204` response** — empty body, no `Content-Type`.

**Failures**

| Status | When | Body |
|---|---|---|
| `401` | Missing, malformed, unknown or already-revoked token | `{"message": "Unauthenticated."}` |
| `401` | The account has been deactivated | `{"message": "Unauthenticated."}` — identical, and the user's remaining tokens are revoked as a side effect. |

Logging out twice returns `204` then `401`: the second call presents a token
that no longer exists.

### `GET /api/v1/auth/me`

Requires `Authorization: Bearer <token>`. Returns the same object the login
response nests under `user`.

**`200` response**

| Field | Type | Notes |
|---|---|---|
| `user.id` | int | |
| `user.name` | string | |
| `user.email` | string | |
| `user.role` | string | `admin` or `agent` — see `App\Enums\UserRole`. |
| `user.is_active` | bool | Always `true` here; a deactivated account cannot reach a `200`. |
| `user.created_at` | string | ISO-8601. |

**Failures** — as `POST /api/v1/auth/logout` above.

Neither endpoint is rate limited. Both are authenticated, and API-wide
throttling is TM-64.
````

Do **not** touch `docs/erd.md` (no schema change) or `CLAUDE.md` (TM-6 is editing it this sprint; the stale "the Laravel side has a health endpoint" line is already assigned there).

---

## Edge Cases & Failure Modes

- **`Sanctum::actingAs()` in a logout test.** The single most likely way to ship this story broken. The mock's `delete()` returns `false` and writes nothing (`Sanctum.php:72`, `Mockery/Mock.php:1091-1101`; measured — see Product rules), so the test asserts `204` on an endpoint that revokes nothing, and criterion 2 becomes untestable because no real token exists. Every test in `LogoutTest` uses `createToken()` + `withToken()`, and Test Plan 1's row-count assertions are what make the mock fail rather than pass.
- **`currentAccessToken()` returning a `TransientToken`.** `Guard.php:32-38` tries the `web` session guard **before** the bearer token, and a user found that way gets a `TransientToken`, which implements only `can()` and `cant()` (`TransientToken.php`, all 30 lines). `->delete()` on it is `Error: Call to undefined method Laravel\Sanctum\TransientToken::delete()` — a `500`. Unreachable in this project because `EnsureFrontendRequestsAreStateful` is never applied in `bootstrap/app.php`, and it must stay that way: **applying Sanctum's stateful middleware to make cookie auth work would break logout**, which is the concrete reason `routes/api.php:12` says there is no session. If TM-11 ever proposes cookie-based auth, this is the line item it has to answer for.
- **`currentAccessToken()` returning `null`.** Happens under Laravel's own `$this->actingAs($user, 'sanctum')` (as opposed to `Sanctum::actingAs()`), which sets the user on the guard without calling `withAccessToken()` — `HasApiTokens::$accessToken` has no default, so `currentAccessToken()` is `null` and `->delete()` fatals with *"Call to a member function delete() on null"*. Another reason the tests use a real token. Deliberately not guarded against in task 1.
- **Logging out twice.** First call `204`, second `401`. The guard cannot find the deleted row (`PersonalAccessToken::findToken()`, `PersonalAccessToken.php:58-69`), so `auth:sanctum` rejects before the controller runs. This is correct and is criterion 2 restated; a client that treats the second `401` as an error rather than "already signed out" is a TM-11 problem, and `client.ts:28` already does the right thing with it.
- **`tokens()->delete()` instead of `currentAccessToken()->delete()` in task 1.** Every device signs out. Both endpoints still return the right status, both a naive happy-path test and the `401` replay test still pass, and the only thing that catches it is Test Plan 1's assertion that the *other* token survives.
- **`is_active` read as a string.** If TM-8's `boolean` cast is missing from `User::casts()`, MySQL returns `"0"` for false, `! "0"` is `true` in PHP so the middleware would still work — but `UserResource` would emit `"is_active": "0"` instead of `false` and TM-11's store would treat it as truthy. Confirm the cast exists (Context item 10) rather than relying on the coincidence.
- **The `active` alias not registered.** `->middleware(['auth:sanctum', 'active'])` with no alias makes the router treat `active` as a class name: `BindingResolutionException — Target class [active] does not exist`, a `500` on the first request to either route. Loud, and the message names the alias.
- **`route:cache` holding a stale middleware list.** The alias lives in `bootstrap/app.php`, but the resolved middleware array is baked into the route cache. If `active` behaves as though it is absent after a deploy, run `php artisan route:clear`. Same failure mode TM-9 recorded for `throttle:login`.
- **A `204` with a body.** `response()->json(['message' => 'Logged out'], 204)` passes `assertStatus(204)` and fails `assertNoContent()`, because Symfony strips the body during `prepare()` but only after the response object has been built — verified during planning: both `noContent()` and `json(null, 204)` end up with an empty body and no `Content-Type`. Use `noContent()`; do not invent a payload for a status code that forbids one.
- **A deactivated user calling `POST /auth/logout`.** The `active` middleware fires first and deletes **all** their tokens, then answers `401`. The user is more logged out than they asked to be, which is the correct outcome, but note the ordering consequence: their logout request never reaches `LogoutController`. A test asserting "deactivated user gets 401 from logout AND has zero tokens left" pins it.
- **`updateLastUsedAt` writing to a row that is about to be deleted.** `Guard.php:56-58` stamps `last_used_at` on the way in, then task 1 deletes the row — three queries for a logout instead of two. Harmless, but it explains a `DB::listen` trace that looks like an extra write, and it means `last_used_at` is never a reliable "when did they log out" signal. Do not add `Sanctum::$trackLastUsedAt = false` to "optimise" it; `/auth/me` and every future protected route depend on that column being current.
- **An expired token.** Currently impossible — `config/sanctum.php:53` is `'expiration' => null` and `personal_access_tokens.expires_at` is written as `null` by `createToken()` with no third argument (`HasApiTokens.php:60-69`). When **TM-64** sets an expiry, `Guard::isValidAccessToken()` (`Guard.php:121-137`) starts rejecting on both `created_at` age and `expires_at`, and both surface as the same `401` these endpoints already return — no change needed here, which is the point of leaving the config alone.
- **Unicode and length in the token itself.** Not a concern: `generateTokenString()` is `Str::random(40)` plus a CRC (`HasApiTokens.php:79-87`) and the column is `string(64)` holding a SHA-256 hex digest, never the token. A garbage `Authorization` header fails `isValidBearerToken()` (`Guard.php:100-113`) and produces the same `401` as a revoked one — no `500`, no leak about which part was wrong.
- **CORS.** `backend/config/cors.php` `paths` is `['api/*', 'sanctum/csrf-cookie']`, which already covers both new routes, and `supports_credentials` is `false` — correct for bearer tokens and it must stay false. No change. A failing preflight points at TM-4's Vite proxy, not at these routes.
- **Static analysis on `$request->user()->currentAccessToken()`.** `Request::user()` is typed `Authenticatable|null`, so a static analyser would flag both the null and the missing method. TM-6 adds Pint only — **no PHPStan or Larastan is planned anywhere in `.squad/plans/`** — so nothing will fail on it today. Do not add a `/** @var User */` annotation dance to satisfy a tool this project does not run.

---

## Test Plan

`composer test` from `backend/`, against `tm-mysql-test` on 3307. Baseline entering this story: **43 tests** (6 today + TM-8's 21 + TM-9's 16). New tests go in `tests/Feature/Auth/` (namespace `Tests\Feature\Auth`), the directory TM-9 creates. Match `tests/Feature/HealthTest.php` exactly: `test_it_…()` names, `use RefreshDatabase;`, chained `assertJson*`, and a `private` helper at the bottom when a fixture repeats.

Every test in files 1 and 3 obtains a **real** token:

```php
private function tokenFor(User $user): string
{
    return $user->createToken('spa')->plainTextToken;
}
```

**`Sanctum::actingAs()` must not appear in any file this story adds.** Grep for it in Verification step 9.

1. **Create `backend/tests/Feature/Auth/LogoutTest.php`** — `RefreshDatabase`. Criteria 1 and 2. Seven tests:
   - `test_it_returns_204_and_an_empty_body` — `withToken($this->tokenFor($user))->postJson(route('auth.logout', absolute: false))->assertNoContent()`. `assertNoContent()` covers the status and the empty body in one call (`AssertsStatusCodes.php:45-52`).
   - `test_it_deletes_the_token_that_made_the_request` — assert `$user->tokens()->count()` is `1` before and **`0`** after. This is the assertion `Sanctum::actingAs()` cannot pass, and the reason the helper above exists.
   - `test_it_leaves_the_users_other_tokens_alone` — issue two tokens, log out with the first, then assert `$user->tokens()->count()` is **`1`** and that the surviving row's `id` is the second token's. Pins TM-9's multi-device decision against a future `tokens()->delete()`.
   - `test_the_revoked_token_no_longer_authenticates` — criterion 2, stated directly. Log out, then replay the same token against `route('auth.me')` and `assertUnauthorized()` plus `assertJsonPath('message', 'Unauthenticated.')`.
   - `test_logging_out_twice_returns_401_the_second_time` — the same token, two `postJson` calls: `204` then `401`.
   - `test_it_rejects_a_request_with_no_token` — `postJson(route('auth.logout', absolute: false))->assertUnauthorized()`, and `PersonalAccessToken::count()` is unchanged. Proves the group's middleware is attached rather than assumed.
   - `test_it_rejects_a_garbage_bearer_token` — `withToken('99|not-a-real-token')` and `withToken('nonsense')` both `401`, not `500`. Covers `Guard::isValidBearerToken()` (`Guard.php:100-113`).

2. **Create `backend/tests/Feature/Auth/MeTest.php`** — `RefreshDatabase`. Criterion 3. Five tests:
   - `test_it_returns_the_authenticated_user` — `assertOk()`, `assertJsonPath('user.id', $user->id)`, `assertJsonPath('user.email', $user->email)`, and `assertJsonStructure(['user' => ['id', 'name', 'email', 'role', 'is_active', 'created_at']])`.
   - `test_it_returns_the_role_as_a_string` — a `->admin()` user gives `assertJsonPath('user.role', 'admin')`, not a serialised enum object. Guards the `->value` in `UserResource`.
   - `test_the_payload_matches_the_login_responses_user_object` — the contract test. Log in through `route('auth.login')`, call `route('auth.me')` with the returned token, and `assertSame($login->json('user'), $me->json('user'))`. If a later story adds a field to one and not the other, this fails.
   - `test_the_response_never_contains_the_password` — `assertJsonMissingPath('user.password')` **and** `assertStringNotContainsString($user->fresh()->password, $response->getContent())`.
   - `test_it_rejects_a_request_with_no_token` — `getJson(route('auth.me', absolute: false))->assertUnauthorized()`.

3. **Create `backend/tests/Feature/Auth/ActiveAccountTest.php`** — `RefreshDatabase`. Task 3's middleware, and the "second door" this story closes. Four tests:
   - `test_a_token_stops_working_when_the_account_is_deactivated` — issue a token, confirm `route('auth.me')` returns `200`, then `$user->update(['is_active' => false])`, then the **same** token returns `401` with `assertJsonPath('message', 'Unauthenticated.')`. The `200`-then-`401` pair is what proves the middleware and not just a broken fixture.
   - `test_deactivation_revokes_every_token_on_the_next_request` — issue **two** tokens, deactivate, make one request with either: `401`, and `$user->tokens()->count()` is **`0`**.
   - `test_a_deactivated_user_cannot_log_out` — `postJson(route('auth.logout'))` returns `401`, not `204`. Pins the middleware order in task 5.
   - `test_an_active_user_is_unaffected` — the negative control. An `->admin()` and an `->agent()` user both reach `200` on `route('auth.me')`, so a middleware that rejected everyone would fail here rather than pass three tests by accident.

4. **Create `backend/tests/Feature/Auth/ProtectedRouteTest.php`** — `RefreshDatabase`. The route group itself, which is the durable artefact. Three tests:
   - `test_the_routes_are_versioned_under_api_v1` — `assertSame('/api/v1/auth/logout', route('auth.logout', absolute: false))` and the same for `auth.me`; `postJson('/api/auth/logout')->assertNotFound()`.
   - `test_the_methods_are_pinned` — `getJson(route('auth.logout', absolute: false))` and `postJson(route('auth.me', absolute: false))` both return **`405`**. Catches a refactor to `Route::any` or a resource controller, and pins that logout can never be triggered by a `GET`.
   - `test_every_route_in_the_group_requires_both_middlewares` — iterate `Route::getRoutes()`, and for each route whose `getName()` starts with `auth.` and is not `auth.login`, assert its `gatherMiddleware()` contains **both** `auth:sanctum` and `active`. This is the test that makes TM-11 through TM-14 inherit the check: a new protected route added outside the group fails here rather than shipping unguarded.

5. **No unit test for `EnsureUserIsActive` in isolation.** Constructing a `Request` with a user attached and a `Closure` for `$next` tests the plan rather than the behaviour; file 3 exercises it through the stack it actually runs in.

6. **No test asserting the `TransientToken` fatal.** It is unreachable without applying `EnsureFrontendRequestsAreStateful`, and a test that applies it to prove a `500` would be asserting a misconfiguration nobody is going to make. It is documented in Edge Cases instead, where a future cookie-auth proposal will find it.

7. **Regression.** `HealthTest` (3), `HealthDegradedTest` (2) and `Unit\ExampleTest` (1) are untouched — this story adds no config the health endpoint reads. TM-8's 21 and TM-9's 16 keep passing: task 4 edits `bootstrap/app.php` only by **adding** an alias (`Middleware.php:793-796` merges rather than replaces, so `throttle:login` on TM-9's route is unaffected), and no file TM-9 created is modified.

Expected total added: **19 tests**, taking the suite from 43 to **62**.

---

## Verification Steps

Run in this order. The working directory is stated for every command.

1. **Prerequisites are real:** `backend/` — `php artisan route:list --path=auth` shows `POST api/v1/auth/login`, and `grep -c HasApiTokens app/Models/User.php` prints `2` (import + trait). **If either fails, stop** — TM-9 has not landed and nothing below can work.
2. **Services healthy:** repo root — `docker compose up -d && docker compose ps`; all three `healthy`.
3. **Backend builds:** `backend/` — `php artisan config:clear && php artisan route:clear`, then:

    ```bash
    php artisan route:list --path=auth --columns=method,uri,name,middleware
    ```

    Three rows. `auth.logout` and `auth.me` must both list **`auth:sanctum`** and **`active`**; `auth.login` must still list `throttle:login`. A missing `active` means task 4's alias or task 5's array is wrong; a missing `throttle:login` means task 4 replaced the default aliases instead of merging.
4. **Migrate and seed:** `backend/` — `php artisan migrate:fresh --seed`, then `php artisan serve`.
5. **Log in, then use the token:** `backend/` —

    ```bash
    TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d '{"email":"admin@ticket-management.test","password":"password"}' \
      | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')

    curl -si http://localhost:8000/api/v1/auth/me -H 'Accept: application/json' \
      -H "Authorization: Bearer $TOKEN"
    ```

    `HTTP/1.1 200 OK` and a body of exactly `{"user":{…}}` — one top-level key, **not** `data`. `role` is `admin`, `is_active` is `true`, and the body contains no `$2y$`.
6. **Logout returns a real 204:** `backend/` —

    ```bash
    curl -si -X POST http://localhost:8000/api/v1/auth/logout \
      -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
    ```

    `HTTP/1.1 204 No Content`, **no `Content-Type` header and zero bytes of body**. Then confirm the row is gone rather than trusting the status:

    ```bash
    php artisan tinker --execute="echo Laravel\Sanctum\PersonalAccessToken::count();"
    ```

    must print `0`.
7. **The revoked token is dead:** `backend/` — repeat step 5's `curl` with the same `$TOKEN`. `HTTP/1.1 401 Unauthorized` and `{"message":"Unauthenticated."}`. Repeat step 6's `curl` too: also `401`, not `204`.
8. **Only one device signs out:** `backend/` — log in twice into `$A` and `$B`, log out with `$A`, then confirm `$B` still returns `200` from `/auth/me` and `PersonalAccessToken::count()` is `1`. This is the check that a `tokens()->delete()` slip in task 1 fails.
9. **Deactivation bites on the next request:** `backend/` — log in, then:

    ```bash
    php artisan tinker --execute="App\Models\User::sole()->update(['is_active' => false]);"
    ```

    Call `/auth/me` with the token issued *before* that: `401`, and `php artisan tinker --execute="echo Laravel\Sanctum\PersonalAccessToken::count();"` prints `0`. Restore with `php artisan migrate:fresh --seed`.
10. **No mocked tokens:** repo root — `grep -rn "actingAs" backend/tests/Feature/Auth/` returns **nothing** from the four files this story adds. A hit means a test is proving `204` without proving revocation.
11. **Backend tests:** `backend/` — `composer test` exits `0` with **19 new tests** across `LogoutTest`, `MeTest`, `ActiveAccountTest` and `ProtectedRouteTest`, and every pre-existing count unchanged.
12. **Single class, for a fast loop:** `backend/` — `php artisan test --filter=ActiveAccountTest` exits `0`.
13. **Style:** `backend/` — `./vendor/bin/pint --test` exits `0`. It passes today over the 29 PHP files under `app/`, `config/`, `database/`, `routes/` and `tests/`.
14. **Contract matches the code:** read `docs/api-contract.md`'s two new sections against the real responses from steps 5–7, field for field, including the `204`'s absent `Content-Type` and both `401` rows.
15. **Regression:** repo root — `git status --short` lists **no path under `frontend/`**, and no change to `docker-compose.yml`, `README.md`, `CLAUDE.md`, `docs/erd.md`, `backend/composer.json`, `backend/composer.lock`, `backend/phpunit.xml`, `backend/config/auth.php` or `backend/config/sanctum.php`. `expiration` is still `null` — token expiry is TM-64's.

---

## Done Criteria

- [ ] `POST /api/v1/auth/logout` exists as `auth.logout`, dispatches `LogoutController::__invoke`, returns **`204`** with an empty body, and deletes **exactly one** row from `personal_access_tokens` — verified by a test that asserts a second token survives.
- [ ] `LogoutController::__invoke` returns `Illuminate\Http\Response` (not `JsonResponse`), calls `currentAccessToken()->delete()` (not `tokens()->delete()`), and has no defensive null check.
- [ ] Replaying a revoked token returns **`401`** with `{"message":"Unauthenticated."}` on both new routes; logging out twice gives `204` then `401`; a garbage bearer token gives `401`, never `500`.
- [ ] `GET /api/v1/auth/me` exists as `auth.me` and returns **`200`** with `{"user": …}` — one top-level `user` key, **not** `data` — rendered by `UserResource` with `role` as its string value. A test asserts the payload is `assertSame`-identical to the login response's `user` object.
- [ ] `App\Http\Middleware\EnsureUserIsActive` exists, is registered as the `active` alias in `bootstrap/app.php`'s `withMiddleware`, and is applied with `auth:sanctum` — in that order — to the route group. `route:list` shows both middlewares on both new routes, and `throttle:login` still on `auth.login`.
- [ ] A token issued before an account was deactivated returns **`401`** on its next use and the account's **remaining tokens are deleted** (`$user->tokens()->count() === 0`). An active admin and an active agent both still reach `200`.
- [ ] Both new routes live inside a single `Route::middleware(['auth:sanctum', 'active'])->group(...)`, and a test iterates the route table asserting every `auth.*` route except `auth.login` carries both middlewares.
- [ ] **`Sanctum::actingAs()` appears in none of the four new test files** — `grep -rn "actingAs" backend/tests/Feature/Auth/` is empty. Every test authenticates with a real `createToken()` token.
- [ ] `composer test` exits `0` with **19 new tests** across `tests/Feature/Auth/{LogoutTest,MeTest,ActiveAccountTest,ProtectedRouteTest}.php`, matching `HealthTest`'s conventions; `./vendor/bin/pint --test` still exits `0`.
- [ ] `docs/api-contract.md` documents both endpoints in TM-3's format, including the `401` rows, the empty `204` body, and the `## Conventions` sentence distinguishing the `user` envelope from the `data` wrapper.
- [ ] No migration was added, `backend/composer.json` and `composer.lock` are unchanged, `config/sanctum.php` still has `'expiration' => null`, and `config/auth.php` was not edited.
- [ ] No file under `frontend/` changed — TM-11 owns the logout button, the auth store and the guards.
- [ ] `00-overview.md` records this story, the `Sanctum::actingAs()` finding, and the fact that **task 3 widened TM-10's stated scope**, so the TM-12 and TM-13 planners know the `is_active` check already exists and that TM-12 still owes `$user->tokens()->delete()` on deactivation.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 09 (TM-11).**
