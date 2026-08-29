# Story 07 — Log in and receive an API token (Story: TM-9)

## Prerequisites

- **Story 02 (TM-3) is implemented.** Verified live in the working tree, not assumed: `php -r 'echo implode(",", PDO::getAvailableDrivers());'` prints **`mysql,pgsql`**, the `@no_additional_args` token is gone from `backend/composer.json`, and `composer test` passes — **6 tests, 25 assertions**. That count is this story's regression baseline.
- **Story 06 (TM-8) must be _implemented_, not merely planned:** [`06-story-users-table-role-enum-seeded-admin-TM-8.md`](06-story-users-table-role-enum-seeded-admin-TM-8.md). Hard blocker, and confirmed not done: `grep -n "role\|is_active" backend/database/migrations/0001_01_01_000000_create_users_table.php` returns **nothing**, and `backend/app/Enums/` does not exist. Three consequences:
  1. **Acceptance criterion 3 is unimplementable** — there is no `is_active` column to check.
  2. `UserResource` (task 3) exposes `role`, which does not exist yet.
  3. The tests need `User::factory()->admin()` / `->inactive()`, which TM-8 adds.

  Confirm before starting: `php artisan db:table users` lists `role` and `is_active`, and `php artisan tinker --execute="echo App\Models\User::factory()->inactive()->make()->is_active ? 'y' : 'n';"` prints `n`.
- **Docker services running:** repo root — `docker compose up -d`, all three `healthy`. The suite migrates against `127.0.0.1:3307` (`backend/phpunit.xml:38`), and TM-3 added `DB_USERNAME=ticket_user` / `DB_PASSWORD=secret` there (lines 40–41).
- **Stories 04 (TM-5) and 05 (TM-6) are not blockers.** TM-6 will lint what this story writes; run `./vendor/bin/pint --test` yourself either way.
- **No new dependency.** `laravel/sanctum v4.3.3` and `laravel/framework v13.26.1` are installed (`composer.lock`), and `personal_access_tokens` already has a migration (`database/migrations/2026_08_25_075421_create_personal_access_tokens_table.php`). `composer.json` must not change.

---

## Story Goal

One endpoint — `POST /api/v1/auth/login` — that exchanges an email and password for a Sanctum bearer token, and refuses to tell an attacker anything else.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | `POST /api/v1/auth/login` validates credentials and returns a token plus the user object | ❌ **Not met.** `routes/api.php` has exactly one route, `GET /health` (line 18). There is no `app/Http/Requests/`, no `app/Http/Resources/`, and — the detail that would fail first — **`App\Models\User` does not use `Laravel\Sanctum\HasApiTokens`** (line 18 is `use HasFactory, Notifiable;`), so `$user->createToken()` is a fatal "call to undefined method". |
| 2 | Invalid credentials return `422` with a generic message that does not reveal whether the email exists | ❌ **Not met**, and harder than it reads — a generic *message* is not a generic *response*. See "The timing oracle" below: skipping `Hash::check` for an unknown email leaks the answer in **288 ms** of measured wall-clock. |
| 3 | A deactivated user (`is_active = false`) cannot log in even with a correct password | ❌ **Not met** and **not yet possible** — the column arrives with TM-8. |
| 4 | The login route is rate limited to a small number of attempts per minute per IP | ❌ **Not met.** `bootstrap/app.php:18-20` leaves `withMiddleware` empty, so `throttleApi()` was never called and **no route in this API is throttled at all**. No named rate limiter exists — `AppServiceProvider` is the stock empty stub. |
| 5 | Passwords are hashed with bcrypt and never appear in any API response | ✅ **Already true, verify only.** `config('hashing.driver')` resolves to `bcrypt` and a live `Hash::make()` produces `$2y$12$…` (cost from `BCRYPT_ROUNDS=12`, `.env.example:13`; tests use `4`, `phpunit.xml:23`). `User` carries `#[Hidden(['password', 'remember_token'])]` (line 14), and task 3's resource never lists `password` regardless. |

Five outcomes:

1. `POST /api/v1/auth/login` returns `200` with `{ token, token_type, user }`, where `token` is a Sanctum plain-text token the SPA can send as `Authorization: Bearer …`.
2. Every failure — unknown email, wrong password, deactivated account — returns the **same** `422` with the same message and in the **same amount of time**.
3. Six attempts in a minute from one IP return `429`.
4. `UserResource` becomes the one definition of "the user object" for the whole API, so `password` cannot leak from any endpoint that uses it.
5. `docs/api-contract.md` gains its second endpoint, in the format TM-3 established.

**Not in scope:** logout and token revocation (**TM-10**), any `auth:sanctum`-protected route including a `GET /auth/me` (**TM-10**/**TM-11** — this story adds no protected endpoint), SPA session persistence and route guards (**TM-11**), admin CRUD for agents (**TM-12**), policies and gates (**TM-13**), password change (**TM-14**), password reset by email (in no story — `password_reset_tokens` exists but nothing uses it), token expiry (see the note below), throttling the rest of the API (**TM-64**), and any change under `frontend/` — the login form is TM-9's consumer, not TM-9.

---

## Product rules

### The timing oracle — why a generic message is not enough

Acceptance criterion 2 asks that the response "does not reveal whether the email exists". The obvious implementation reveals it anyway:

```php
$user = User::where('email', $request->email)->first();

if (! $user || ! Hash::check($request->password, $user->password)) {   // <-- leaks
    throw ValidationException::withMessages(['email' => __('auth.failed')]);
}
```

`||` short-circuits, so an unknown email never reaches `Hash::check`. Measured on this machine at the configured cost:

```
bcrypt cost 12: Hash::check = 287.9 ms; skipping it = 0.000 ms
```

An attacker does not need the message — **288 ms** of difference enumerates every valid address in the system, and the rate limit in task 5 does not help because 5 probes per minute per IP is plenty for a list of candidate emails. So task 4 spends one bcrypt operation on the unknown-email path deliberately:

```php
if (! $user) {
    // Burn one bcrypt operation so a missing email costs the same as a wrong
    // password. Without this the 422 says nothing and the response time says
    // everything — measured at ~288 ms of difference at cost 12.
    Hash::make($request->string('password'));

    $this->fail();
}
```

`Hash::make` on the submitted password rather than a hard-coded hash constant: the cost then tracks `BCRYPT_ROUNDS` in whatever environment it runs, so it matches the real `Hash::check` it is standing in for — and the test suite (cost `4`) pays about a millisecond instead of the 288 ms a cost-12 literal would bake in.

### One message for three different failures

Unknown email, wrong password and deactivated account all return the identical body:

```json
{
  "message": "These credentials do not match our records.",
  "errors": { "email": ["These credentials do not match our records."] }
}
```

That string is `__('auth.failed')`. It resolves without publishing anything — the framework ships `vendor/laravel/framework/src/Illuminate/Translation/lang/en/auth.php:16` and this project has **no `lang/` directory**, so the bundled translation is what loads.

**Collapsing "deactivated" into the same message is a deliberate trade-off**, and it is the one product decision in this story worth revisiting later. A deactivated agent gets no explanation, which is poor UX; the alternative — "Your account has been deactivated" — confirms that the email exists and that a real person works here, which is exactly what criterion 2 forbids. Criterion 2 wins. If product later wants the friendlier message, the change is one branch in `LoginController` plus one test, and it should be made knowing what it gives away.

### Two doors, and this story closes only one

`config/sanctum.php:53` sets `'expiration' => null`, so **issued tokens never expire**. Combined with the hand-off recorded in TM-8's overview, that leaves a real gap this story does not close:

- **Login door — closed here.** A deactivated user cannot obtain a new token.
- **Existing-token door — still open.** A user deactivated *after* logging in keeps a working token indefinitely. Nothing revokes it, and this story adds no authenticated route on which to check.

**TM-12** must delete a user's tokens when an admin deactivates them (`$user->tokens()->delete()`), and **TM-11** must not treat a stored token as proof of an active account. Choosing a non-null `expiration` is **TM-64**'s call, because it changes how long the SPA can stay signed in and TM-11 owns that behaviour. Do not change it here.

---

## Context — Read These Files First

1. `backend/routes/api.php` — all 18 lines. The header comment (6–14) already states the contract this story implements: *"Authentication is a Sanctum bearer token; there is no session."* The single route is line 18. Task 6 adds to this file; keep the comment style.
2. `backend/bootstrap/app.php` — all 25 lines. **`apiPrefix: 'api/v1'` is line 16**, so task 6 writes `/auth/login`, never `/api/v1/auth/login`. `withMiddleware` (18–20) is **empty** — that is why nothing is throttled and why task 5 uses a route-level middleware rather than assuming a group default. Lines 22–24 render exceptions as JSON for `api/*`, which is what turns `ValidationException` into the 422 envelope above without any per-controller handling.
3. `backend/app/Models/User.php` — all 32 lines. **Line 18 is `use HasFactory, Notifiable;` — `HasApiTokens` is absent.** Task 1 adds it. Line 13's `#[Fillable]` and line 14's `#[Hidden]` are PHP attributes, not properties; TM-8 extends both. `casts()` (25–31) already maps `password` to `hashed`.
4. `backend/app/Http/Controllers/Api/V1/HealthController.php` — all 55 lines. The controller idiom to match: `__invoke()` for a single-action controller, a `JsonResponse` return type, `response()->json([...], $status)`, and a class docblock that explains *why* rather than *what*. Task 4 follows this shape.
5. `backend/app/Http/` — list it. There is **only** `Controllers/`. No `Requests/`, no `Resources/`, no `Middleware/`. Tasks 2 and 3 create the first two, so they set the namespace convention for every later story: `App\Http\Requests\Api\V1\…` and `App\Http\Resources\V1\…`, mirroring `App\Http\Controllers\Api\V1`.
6. `backend/app/Providers/AppServiceProvider.php` — all 24 lines, both methods empty stubs. Task 5 puts the rate limiter in `boot()`.
7. `backend/config/sanctum.php` — three lines matter. **`'guard' => ['web']` (line 40)**: Sanctum tries the `web` session guard first and falls through to the bearer token when it finds no session. Because `bootstrap/app.php` never applies `EnsureFrontendRequestsAreStateful`, no request is ever stateful, so the fall-through is the only path — that is the whole reason this project is a token client despite `SANCTUM_STATEFUL_DOMAINS` being set. **`'expiration' => null` (line 53)** — see "Two doors" above. **`'token_prefix' => env('SANCTUM_TOKEN_PREFIX', '')` (line 68)** is unset; setting it would let GitHub's secret scanning spot a leaked token, and belongs to TM-64.
8. `backend/config/auth.php` — `guards` (40–45) defines **only `web`**, with the `session` driver. **This is correct and needs no change.** `vendor/laravel/sanctum/src/SanctumServiceProvider.php:22-28` merges `auth.guards.sanctum` into the config at register time:

    ```php
    config([
        'auth.guards.sanctum' => array_merge([
            'driver' => 'sanctum',
            'provider' => null,
        ], config('auth.guards.sanctum', [])),
    ]);
    ```

    So `auth:sanctum` resolves for TM-10 and TM-11 without anyone editing `config/auth.php`. Do not "fix" the missing entry.
9. `backend/vendor/laravel/sanctum/src/HasApiTokens.php:60-72` — `createToken(string $name, array $abilities = ['*'], ?DateTimeInterface $expiresAt = null)`. It stores `hash('sha256', $plainTextToken)` and returns a `NewAccessToken` whose `plainTextToken` is `"{$token->getKey()}|{$plainTextToken}"`. **The plain text exists only in that return value** — it is never readable again, which is why task 4 must put it in the response and why no test can look it up afterwards.
10. `backend/tests/Feature/HealthTest.php` — all 39 lines, and `HealthDegradedTest.php` — all 37 lines. These are the **local test precedent**, added by TM-3: `Tests\Feature` namespace, `use RefreshDatabase;`, `test_it_…()` snake-case method names, `$this->getJson(...)` with chained `assertJsonPath` / `assertJsonStructure` / `assertJsonMissingPath`, and `config()->set(...)` to vary behaviour per test. Match all of it. Note `HealthDegradedTest` deliberately omits `RefreshDatabase` because it breaks the connection on purpose — a reminder that the trait is a choice, not a reflex.
11. `docs/api-contract.md` — all 35 lines, filled in by TM-3. The endpoint table is 17–19 and the per-endpoint detail section starts at 21 with a `### GET /api/v1/health` heading and a `| Field | Type | Notes |` table. Task 7 adds a row and a section in exactly that format.
12. `backend/database/migrations/2026_08_25_075421_create_personal_access_tokens_table.php` — all 33 lines. The table already exists; `token` is `string(64)->unique()` (line 18) holding the sha256, `abilities` is nullable text (19), `expires_at` is nullable and indexed (21). **No migration is needed in this story.**
13. Framework behaviours to confirm rather than trust:
    - `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php:84-89` — a string `$maxAttempts` is treated as a **named limiter** only when the limiter is registered; `resolveMaxAttempts()` otherwise throws `Illuminate\Routing\Exceptions\MissingRateLimiterException`. Forgetting task 5 is therefore a hard 500, not a silently-unthrottled route.
    - Same file, lines 244–256 and 297–315 — exhaustion throws `ThrottleRequestsException` with the message **`Too Many Attempts.`** and the headers `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `Retry-After` and `X-RateLimit-Reset`. The test plan asserts the status and one header.
    - `vendor/laravel/framework/src/Illuminate/Hashing/HashManager.php:109` — `config('hashing.driver', 'bcrypt')`. `config/hashing.php` is not published, so bcrypt is the driver by default; a live `Hash::info()` confirms `algoName => bcrypt`, `cost => 12`.

---

## Backend Tasks

**No frontend changes.** The login form, the token store and the redirect all belong to TM-9's consumers (TM-11 owns the guard, and TM-4 already created `src/views/LoginView.vue` as a placeholder with `LOGIN_PATH = '/auth/login'` in `src/api/client.ts`). **That constant matches the route this story creates — do not rename the route.**

### 1 — Make `User` able to issue tokens

**File: `backend/app/Models/User.php`**

Add the import and the trait. This is the smallest change in the story and the one whose absence fails first: without it, `$user->createToken()` is `Call to undefined method App\Models\User::createToken()`.

```php
use Laravel\Sanctum\HasApiTokens;
```

```php
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;
```

Alphabetical, matching the existing `HasFactory, Notifiable` order. Change nothing else in this file — `#[Fillable]`, `#[Hidden]` and `casts()` are TM-8's.

### 2 — The request

**Create file: `backend/app/Http/Requests/Api/V1/LoginRequest.php`**

`app/Http/Requests/` does not exist; create the directories. This is the project's first form request, so the namespace mirrors the controllers': `App\Http\Requests\Api\V1`.

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, list<string>|string>
     */
    public function rules(): array
    {
        return [
            // `email` and not `email:rfc,dns`: a DNS lookup on every login
            // attempt is a network round-trip an unauthenticated caller can
            // trigger, and the address only has to match a stored one.
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }
}
```

Three deliberate omissions:

- **No `authorize()` override.** `FormRequest::authorize()` returns `true` by default in Laravel 11+; an override that returns `true` is noise.
- **No `min:8` on `password`.** Validating the *length* of a submitted password on login tells the caller something about the password policy and turns a wrong password into a `422` that differs from the credentials `422`. Length rules belong on the endpoints that *set* a password — TM-12 and TM-14.
- **No `device_name`.** Sanctum's docs use one to name tokens; this SPA has exactly one client, so task 4 hard-codes the name. Adding an unvalidated caller-supplied string into `personal_access_tokens.name` for no consumer is a liability.

### 3 — The resource

**Create file: `backend/app/Http/Resources/V1/UserResource.php`**

`app/Http/Resources/` does not exist. This becomes the single definition of "the user object" for the whole API — TM-11 types its SPA store against it, and TM-12 returns collections of it.

```php
<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a user. `password` and `remember_token` are absent by
 * construction rather than by relying on the model's #[Hidden] attribute —
 * a resource that lists its fields cannot leak one by accident.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            // ->value, not the enum: JSON has no enum type, and pinning the
            // string here keeps the API contract independent of the PHP name.
            'role' => $this->role->value,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
```

`role` and `is_active` come from TM-8 — this file does not compile usefully without it, which is one of the three reasons TM-8 is a hard prerequisite. `email_verified_at` and `remember_token` are deliberately absent: nothing in this product uses either (TM-8's plan records why the columns stay).

**Do not** set `public static $wrap = null` or otherwise disable the `data` wrapper globally. Task 4 embeds the resource under an explicit `user` key by calling `->toArray($request)` through `UserResource::make($user)`, so the wrapper never applies and no global switch is needed — a global change would silently alter the shape of every future resource response.

### 4 — The controller

**Create file: `backend/app/Http/Controllers/Api/V1/Auth/LoginController.php`**

A new `Auth/` subdirectory under `Api/V1/`, so TM-10's `LogoutController` and TM-14's password controller have an obvious home.

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Resources\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Exchange an email and password for a Sanctum bearer token.
 *
 * Every rejection — unknown email, wrong password, deactivated account —
 * returns the same 422 with the same message AND takes the same amount of
 * time. The message alone is not enough: skipping the bcrypt comparison for
 * an address that does not exist leaks its absence in ~288 ms, which
 * enumerates the user list without reading a single response body.
 */
class LoginController extends Controller
{
    public function __invoke(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user) {
            // Deliberate cost, not a leftover: this equalises the response
            // time with the wrong-password path below. Hash::make rather than
            // a hard-coded hash so the work tracks BCRYPT_ROUNDS in whatever
            // environment this runs in — cost 4 in tests, cost 12 in dev.
            Hash::make($request->string('password'));

            $this->reject();
        }

        if (! Hash::check($request->string('password'), $user->password)) {
            $this->reject();
        }

        // Checked after the password on purpose: a caller who does not know
        // the password learns nothing about whether the account is disabled.
        if (! $user->is_active) {
            $this->reject();
        }

        // Existing tokens are left alone — signing in on a phone must not sign
        // you out on a laptop. Revoking the CURRENT token is TM-10.
        $token = $user->createToken('spa');

        return response()->json([
            // The only moment this string exists. Sanctum stores its sha256,
            // so a lost token cannot be recovered, only replaced.
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => UserResource::make($user)->toArray($request),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function reject(): never
    {
        throw ValidationException::withMessages([
            'email' => __('auth.failed'),
        ]);
    }
}
```

Five details that are not stylistic:

- **`Auth::attempt()` is not used.** It is built for the session guard: on success it *logs the user into the session*, which this project does not have (`routes/api.php:12`), and it gives no hook for the `is_active` check or the timing equalisation. `Hash::check` against a looked-up user is the honest primitive here.
- **`200`, not `201`.** A token record is created, but the resource the client asked for is a session, and every HTTP client in the world treats login as a `200`. `frontend/src/api/client.ts` does not special-case `201`.
- **`ValidationException` rather than `abort(422, …)`.** It produces the `{message, errors}` envelope the SPA already handles, and `bootstrap/app.php:22-24` renders it as JSON for `api/*` with no extra work. `abort()` would give a `{message}` with no `errors` key, so the login form would have nothing to attach to the email field.
- **`private function reject(): never`** — the `never` return type is what lets the three call sites read as guard clauses without a `return` after each.
- **`$request->string(...)`** returns a `Stringable`; `Hash::check` and the query builder both accept it. Do not `->toString()` it just to look conventional.

### 5 — The rate limiter

**File: `backend/app/Providers/AppServiceProvider.php`**

Fill in `boot()` (lines 20–23):

```php
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
```

```php
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login is the one unauthenticated endpoint that is worth guessing at,
        // so it gets its own limiter rather than the API-wide default (there
        // is none — bootstrap/app.php never calls throttleApi()). Five a
        // minute is enough for a person who forgot which password they used
        // and nowhere near enough for a dictionary.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
    }
```

Keyed on `$request->ip()` because that is what acceptance criterion 4 asks for. Note the cost, so nobody is surprised: five people behind one office NAT share the budget. Keying on `ip() . '|' . email` would fix that but lets an attacker spread a dictionary across addresses for free — TM-64 owns the trade-off if it becomes a real complaint.

A named limiter rather than an inline `throttle:5,1`: the policy and the reason live in one place, and TM-14 will want the same treatment for password change. **The name must match task 6's middleware string** — a mismatch throws `MissingRateLimiterException` (a 500), which is at least loud.

### 6 — The route

**File: `backend/routes/api.php`**

Add the import and the route. `apiPrefix` is already `api/v1`, so the path is `/auth/login`:

```php
use App\Http\Controllers\Api\V1\Auth\LoginController;
```

```php
// Exchange credentials for a bearer token. Unauthenticated by necessity and
// therefore throttled: see the 'login' limiter in AppServiceProvider.
Route::post('/auth/login', LoginController::class)
    ->middleware('throttle:login')
    ->name('auth.login');
```

`->name('auth.login')` so the tests can assert the resolved path the way `HealthTest:37` does for `health`. The `LOGIN_PATH` constant in `frontend/src/api/client.ts` is `'/auth/login'` — this route is what makes that guard match, so **do not** rename it to `/login` or `/auth/token`.

### 7 — The contract

**File: `docs/api-contract.md`**

Add a row to the endpoint table (after line 19) and a detail section after the health one, in TM-3's established format:

````markdown
| `POST` | `/api/v1/auth/login` | Exchange email + password for a Sanctum bearer token. | none (throttled) | TM-9 |
````

````markdown
### `POST /api/v1/auth/login`

Unauthenticated and rate limited to **5 requests per minute per IP** (`throttle:login`).

**Request**

| Field | Type | Rules |
|---|---|---|
| `email` | string | required, valid email, max 255 |
| `password` | string | required |

**`200` response**

| Field | Type | Notes |
|---|---|---|
| `token` | string | Sanctum plain-text token, `id|secret`. Send as `Authorization: Bearer <token>`. Returned **once** — only its SHA-256 is stored. |
| `token_type` | string | Always `Bearer`. |
| `user.id` | int | |
| `user.name` | string | |
| `user.email` | string | |
| `user.role` | string | `admin` or `agent` — see `App\Enums\UserRole`. |
| `user.is_active` | bool | Always `true` here; a deactivated user cannot reach a `200`. |
| `user.created_at` | string | ISO-8601. |

**Failures**

| Status | When | Body |
|---|---|---|
| `422` | Missing or malformed `email` / `password` | Laravel validation envelope naming the offending field. |
| `422` | Unknown email, wrong password, **or** a deactivated account | `errors.email` is `These credentials do not match our records.` — identical for all three, and returned after the same amount of work, so neither the body nor the response time reveals whether the address exists. |
| `429` | More than 5 attempts in a minute from one IP | `Too Many Attempts.` with `Retry-After` and `X-RateLimit-*` headers. |

Tokens do not expire (`config/sanctum.php` `expiration` is `null`), and a user
deactivated *after* signing in keeps a working token until it is revoked —
TM-12 revokes on deactivation, TM-10 revokes on logout.
````

Do **not** touch `docs/erd.md` (TM-8 owns the `users` row) or `CLAUDE.md` (TM-6 is editing it this sprint).

---

## Edge Cases & Failure Modes

- **`HasApiTokens` not added.** `$user->createToken('spa')` throws `Call to undefined method App\Models\User::createToken()` — a 500, on the success path only, so validation and throttling tests all pass and the story looks nearly done. Verified absent today (`app/Models/User.php:18`). Task 1 is one line and the first thing to check when a login test 500s.
- **Unknown email answered faster than a wrong password.** The measured leak: `Hash::check` costs **287.9 ms** at cost 12, skipping it costs `0.000 ms`. An attacker enumerates valid addresses on response time alone, and the 5/minute limit is irrelevant for a candidate list. The `Hash::make` on the missing-user path is load-bearing; a reviewer who deletes it as "a useless hash" reintroduces the oracle.
- **`is_active` checked before the password.** Then a caller who knows only the email learns whether the account is disabled, because the disabled path would answer in ~0 ms while a wrong password costs a full bcrypt round. Order in task 4 — password first, then `is_active` — is deliberate.
- **`throttle:login` without the limiter registered.** `ThrottleRequests::resolveMaxAttempts()` throws `Illuminate\Routing\Exceptions\MissingRateLimiterException` (`ThrottleRequests.php:84-89` and the `resolveMaxAttempts` body), so the route returns **500 on the very first request**. Loud, but the message names the limiter, not the route — check `AppServiceProvider::boot()` first.
- **Throttle state leaking between tests.** `phpunit.xml:25` sets `CACHE_STORE=array`, and the rate limiter is cache-backed, so each test starts with a clean budget. In development `CACHE_STORE=database` (`.env.example:46`) means a manual `429` persists for a minute — `php artisan cache:clear` resets it. A test that asserts `429` and then asserts `200` in the same method will fail; use separate tests or `RateLimiter::clear('…')`.
- **`Auth::attempt()` used instead of `Hash::check`.** It succeeds, and it also starts a session — on an API with `SESSION_DRIVER=database` that writes a session row per login attempt and returns a `Set-Cookie` the SPA neither wants nor sends back. It also offers no seam for `is_active` or for the timing equalisation.
- **The plain-text token read back later.** Impossible: `createToken()` stores `hash('sha256', $plainTextToken)` (`HasApiTokens.php:66`). A test that logs in and then queries `personal_access_tokens.token` expecting the response value will always fail. Assert the shape (`{id}|{40+ chars}`) and that it authenticates, not that it matches a stored column.
- **A deactivated user's existing token.** Still valid — nothing revokes it, and `expiration` is `null`. This story closes only the login door. **TM-12** must call `$user->tokens()->delete()` on deactivation; **TM-11** must not treat a stored token as proof of an active account.
- **Multiple tokens accumulating.** Every login adds a row and none are cleaned up, so a user who signs in daily for a year has 365 live tokens, any of which still works. Deliberate (multi-device), but it makes revocation-on-deactivation matter more, not less. Pruning belongs to TM-64.
- **`password` appearing in a log rather than a response.** Criterion 5 is about responses, and `UserResource` guarantees those. `ValidationException` on a malformed request echoes the *field names*, never the values. But `LOG_LEVEL=debug` plus an unhandled exception on this route puts the request body in `storage/logs` — Laravel's default `$dontFlash` covers `password` for session-flash, not for log context. Do not add `Log::debug($request->all())` to this controller while debugging.
- **`email` validated with `email:rfc,dns`.** Turns every login attempt into a DNS lookup an unauthenticated caller controls: slow, and a soft outbound-request amplifier. The plain `email` rule is what task 2 uses.
- **CORS on the new route.** `backend/config/cors.php` `paths` is `['api/*', 'sanctum/csrf-cookie']`, which already covers `api/v1/auth/login`, and `allowed_origins` lists `http://localhost:5173`. `supports_credentials` is `false`, which is correct for a bearer token and must stay false. No change needed — and if a preflight fails, the cause is the Vite proxy (TM-4 task 2), not this route.
- **Trailing-slash and method mismatch.** `POST /api/v1/auth/login/` and `GET /api/v1/auth/login` both 405/404 rather than validating. Worth one test (`getJson` → 405) so a future refactor to `Route::any` or a resource controller is caught.
- **`config:cache` stale after adding the limiter.** The limiter lives in a provider, not config, so `config:cache` does not affect it — but `route:cache` **does** cache the middleware string. If `throttle:login` behaves as though it is absent after a deploy, run `php artisan route:clear`.

---

## Test Plan

`composer test` from `backend/`, against `tm-mysql-test` on 3307. Baseline before this story: **6 tests, 25 assertions** (`HealthTest` 3, `HealthDegradedTest` 2, `Unit\ExampleTest` 1). Match the precedent in `tests/Feature/HealthTest.php` exactly: `test_it_…()` names, `use RefreshDatabase;`, chained `assertJson*` assertions, `config()->set()` for per-test variation. New tests go in `tests/Feature/Auth/` (namespace `Tests\Feature\Auth`) — the first subdirectory under `Feature/`, mirroring the controller's `Api\V1\Auth`.

1. **Create `backend/tests/Feature/Auth/LoginTest.php`** — `RefreshDatabase`. The happy path and the response contract. Six tests:
   - `test_it_returns_a_token_and_the_user_for_valid_credentials` — `User::factory()->create(['email' => 'agent@example.test', 'password' => 'correct-horse'])`, then `postJson(route('auth.login', absolute: false), [...])->assertOk()`, and assert `assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'role', 'is_active', 'created_at']])` plus `assertJsonPath('token_type', 'Bearer')` and `assertJsonPath('user.email', 'agent@example.test')`.
   - `test_the_token_it_returns_authenticates_a_sanctum_request` — the assertion that proves the token is real rather than well-shaped. Take `$response->json('token')` and call `Route::middleware('auth:sanctum')->get('/test-login-token', fn (Request $r) => ['id' => $r->user()->id])` defined **inside the test** via `Route::middleware(…)` before the request, then `withToken($token)->getJson('/test-login-token')->assertOk()->assertJsonPath('id', $user->id)`. This is the only test that catches a missing `HasApiTokens` in a way the happy path does not, and it also proves `auth:sanctum` resolves without a `config/auth.php` entry.
   - `test_it_persists_exactly_one_token_row` — after one login, `$user->tokens()->count()` is `1` and its `name` is `'spa'`.
   - `test_it_does_not_revoke_existing_tokens` — log in twice; `$user->tokens()->count()` is `2`. Pins the multi-device decision so a future "tidy up" has to argue with a test.
   - `test_the_response_never_contains_the_password` — `assertJsonMissingPath('user.password')` **and** `$this->assertStringNotContainsString($user->password, $response->getContent())`. The second half is what actually satisfies criterion 5: it catches a hash leaking through some other key.
   - `test_the_route_is_versioned_under_api_v1` — `assertSame('/api/v1/auth/login', route('auth.login', absolute: false))`, `postJson('/api/auth/login')->assertNotFound()`, and `getJson('/api/v1/auth/login')->assertStatus(405)`.

2. **Create `backend/tests/Feature/Auth/LoginValidationTest.php`** — `RefreshDatabase`. The rejection paths, which are the substance of criteria 2 and 3. Seven tests:
   - `test_it_requires_an_email_and_a_password` — `postJson(…, [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password'])`.
   - `test_it_rejects_a_malformed_email` — `'not-an-email'` → 422 on `email`.
   - `test_it_rejects_an_unknown_email_with_the_generic_message` — 422, and `assertJsonPath('errors.email.0', 'These credentials do not match our records.')`.
   - `test_it_rejects_a_wrong_password_with_the_same_message` — assert the body is **identical** to the unknown-email body: capture both `$response->json()` and `assertSame`. A test that only checks each status separately would not catch someone "improving" one message.
   - `test_a_deactivated_user_cannot_log_in_with_a_correct_password` — `User::factory()->inactive()->create([...])`, correct password, → 422 with the same message, **and** `$user->tokens()->count()` is `0`. The token count is the real assertion: a 422 that still issued a token would be a much worse bug than a wrong message.
   - `test_a_failed_login_issues_no_token` — across unknown email and wrong password, `PersonalAccessToken::count()` is `0`.
   - `test_it_never_reveals_whether_an_email_exists` — the summary test. Post an unknown email and a wrong password for a real user, and `assertSame` on the two full response bodies **and** the two status codes. If a later story adds a distinct "account deactivated" message, this test is where the decision has to be made consciously.

3. **Create `backend/tests/Feature/Auth/LoginThrottleTest.php`** — `RefreshDatabase`. Criterion 4. Three tests:
   - `test_it_allows_five_attempts_per_minute` — five wrong-password posts, each `422`, none `429`.
   - `test_it_blocks_the_sixth_attempt` — a sixth post returns **`429`**, `assertJsonPath('message', 'Too Many Attempts.')`, and the response has a `Retry-After` header (`assertHeader` is enough; do not assert its value, which is timing-dependent).
   - `test_the_limit_applies_to_successful_logins_too` — five *valid* logins, then a sixth returns `429` rather than a token. Otherwise an attacker who guesses right on attempt 3 can keep minting tokens for free, and it pins that the limiter is on the route rather than inside the failure branch.

   These three tests share a rate-limit budget only if the cache persists; `phpunit.xml:25` sets `CACHE_STORE=array`, so each test method starts clean. **Do not** add a `RateLimiter::clear()` in `setUp` to "be safe" — it would mask a real regression to a persistent store.

4. **No unit test for `UserResource` or `LoginRequest` in isolation.** Both are fully covered through the HTTP tests above, and a unit test asserting that `rules()` returns a particular array pins the plan rather than the behaviour.

5. **No test for the timing equalisation.** A wall-clock assertion would be flaky on shared CI and meaningless at `BCRYPT_ROUNDS=4`, where both paths cost about a millisecond. The protection is verified by reading task 4 and by Verification step 8, which measures it once by hand at cost 12.

6. **Regression.** `HealthTest` (3) and `HealthDegradedTest` (2) keep passing untouched; this story adds no config the health endpoint reads. `Unit\ExampleTest` (1) is untouched. TM-8's 21 tests keep passing — task 1 adds a trait to `User` and changes no attribute, cast or column.

Expected total added: **16 tests**, taking the suite from 6 (or 27 with TM-8) to **22 (or 43)**.

---

## Verification Steps

Run in this order. Working directory is stated for every command.

1. **Prerequisites are real:** `backend/` — `php artisan db:table users` lists **`role`** and **`is_active`** (TM-8 landed), and `composer test` exits `0`. **If `role` is missing, stop** — `UserResource` and criterion 3 both depend on it.
2. **Services healthy:** repo root — `docker compose up -d && docker compose ps`; all three `healthy`.
3. **Backend builds:** `backend/` — `php artisan config:clear`, then `php artisan route:list --path=auth` shows exactly `POST api/v1/auth/login … auth.login` with `throttle:login` in its middleware column. A missing middleware column entry means task 6's `->middleware()` was dropped.
4. **Migrate and seed a known user:** `backend/` — `php artisan migrate:fresh --seed`, which creates the admin from `ADMIN_EMAIL` / `ADMIN_PASSWORD` (TM-8's seeder).
5. **The happy path, end to end:** `backend/` — with `php artisan serve` running:

    ```bash
    curl -si -X POST http://localhost:8000/api/v1/auth/login \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d '{"email":"admin@ticket-management.test","password":"password"}'
    ```

    `HTTP/1.1 200 OK`, a body with `token`, `token_type: "Bearer"` and a `user` object whose `role` is `admin`. **The body must not contain the string `$2y$`** — grep for it: `… | grep -c '\$2y\$'` must print `0`.
6. **The token actually authenticates:** there is no protected route in this story, so verify through the test suite (Test Plan 1, second test) rather than by curl. Record that in the completion note. **Do not** add a protected route to `routes/api.php` to make a manual check possible — that is TM-10's.
7. **All three rejections are indistinguishable:** `backend/` — run the three curls and diff the bodies:

    ```bash
    L() { curl -s -X POST http://localhost:8000/api/v1/auth/login \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -d "$1"; echo; }
    L '{"email":"nobody@example.test","password":"whatever"}'   > /tmp/tm9-unknown.json
    L '{"email":"admin@ticket-management.test","password":"wrong"}' > /tmp/tm9-wrong.json
    diff /tmp/tm9-unknown.json /tmp/tm9-wrong.json && echo "identical — correct"
    ```

    Both must be `422` with `errors.email` = `These credentials do not match our records.`, and the `diff` must be empty. (Run these before step 9 or the rate limit will interfere.)
8. **The timing oracle is closed:** `backend/` — measure both paths at the real cost 12. Wait a minute after step 7 so the limiter has reset, then:

    ```bash
    for p in nobody@example.test admin@ticket-management.test; do
      curl -s -o /dev/null -w "$p: %{time_total}s\n" -X POST http://localhost:8000/api/v1/auth/login \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "{\"email\":\"$p\",\"password\":\"wrong\"}"
    done
    ```

    The two timings must be within the same order of magnitude — both around **0.3 s**, not `0.01 s` and `0.3 s`. A fast unknown-email response means the `Hash::make` on the missing-user path was dropped.
9. **Rate limit bites:** `backend/` — six rapid wrong-password posts; the sixth returns `429` with `Too Many Attempts.` and a `Retry-After` header:

    ```bash
    for i in $(seq 1 6); do
      curl -s -o /dev/null -w "attempt $i: %{http_code}\n" -X POST http://localhost:8000/api/v1/auth/login \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d '{"email":"admin@ticket-management.test","password":"wrong"}'
    done
    ```

    Expect `422 422 422 422 422 429`. Reset with `php artisan cache:clear` (development uses `CACHE_STORE=database`).
10. **Deactivated user is refused:** `backend/` —

    ```bash
    php artisan tinker --execute="\$u = App\Models\User::sole(); \$u->is_active = false; \$u->save(); \$u->tokens()->delete();"
    ```

    then repeat step 5. It must return `422`, and `php artisan tinker --execute="echo App\Models\User::sole()->tokens()->count();"` must print `0`. Restore with `php artisan migrate:fresh --seed`.
11. **Backend tests:** `backend/` — `composer test` exits `0` with **16 new tests** across `LoginTest`, `LoginValidationTest` and `LoginThrottleTest`, and the pre-existing counts unchanged.
12. **Single class, for a fast loop:** `backend/` — `php artisan test --filter=LoginValidationTest` exits `0`.
13. **Style:** `backend/` — `./vendor/bin/pint --test` exits `0`. It passed over 31 files before this story.
14. **Contract matches the code:** read `docs/api-contract.md`'s new section against the actual `200` body from step 5, field for field. Every field listed exists and nothing in the response is missing from the table.
15. **Regression:** repo root — `git status --short` lists no path under `frontend/`, and no change to `docker-compose.yml`, `README.md`, `CLAUDE.md`, `docs/erd.md`, `backend/composer.json`, `backend/phpunit.xml` or `backend/config/auth.php`. `backend/config/sanctum.php` is **unchanged** — `expiration` stays `null` in this story.

---

## Done Criteria

- [ ] `App\Models\User` uses `Laravel\Sanctum\HasApiTokens`; nothing else in that file changed.
- [ ] `POST /api/v1/auth/login` exists as `auth.login`, dispatches `LoginController::__invoke`, and carries `throttle:login`; `App\Http\Requests\Api\V1\LoginRequest` validates `email` (required/email/max:255) and `password` (required) with **no** `min:` rule and **no** `dns` check.
- [ ] A valid login returns **`200`** with `token`, `token_type: "Bearer"` and a `user` object rendered by `App\Http\Resources\V1\UserResource` (`id`, `name`, `email`, `role` as its string value, `is_active`, `created_at`). The response body contains no `$2y$` and no `password` key.
- [ ] The returned token authenticates an `auth:sanctum` request — proven by a test, since this story adds no protected route. `config/auth.php` was **not** edited to make it work.
- [ ] Unknown email, wrong password and a deactivated account all return **`422`** with `errors.email` = `These credentials do not match our records.`, and a test asserts the two full bodies are **identical** rather than merely similar.
- [ ] A deactivated user with the correct password is refused **and issues no token** (`$user->tokens()->count() === 0`).
- [ ] The unknown-email path spends one bcrypt operation so it cannot be distinguished by response time; measured by hand at cost 12, both rejection paths take roughly the same time (~0.3 s), not 0.01 s versus 0.3 s.
- [ ] A named `login` rate limiter is registered in `AppServiceProvider::boot()` as `Limit::perMinute(5)->by($request->ip())`; the sixth attempt in a minute returns **`429`** with `Too Many Attempts.` and a `Retry-After` header, for successful logins as well as failures.
- [ ] Logging in twice leaves **two** tokens — existing tokens are not revoked — and each is named `spa`.
- [ ] `composer test` exits `0` with **16 new tests** across three files in `tests/Feature/Auth/`, matching `HealthTest`'s conventions (`test_it_…`, `RefreshDatabase`, chained `assertJson*`); `./vendor/bin/pint --test` still exits `0`.
- [ ] `docs/api-contract.md` documents the endpoint in TM-3's format, including the `429` row and the note that tokens do not expire and survive deactivation until revoked.
- [ ] `config/sanctum.php` is unchanged (`expiration` still `null`), and the overview records that **TM-12 must revoke tokens on deactivation** and **TM-64 owns token expiry and API-wide throttling**.
- [ ] No file under `frontend/` changed; `frontend/src/api/client.ts`'s `LOGIN_PATH = '/auth/login'` matches the route created here.
- [ ] Overview `00-overview.md` updated with this story.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 08 (TM-10).**
