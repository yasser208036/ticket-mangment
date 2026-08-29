# Story 12 — Change my own password (Story: TM-14)

## Prerequisites

- **Story 11 (TM-13) must be _implemented_, not merely planned — and it is not.** Verified on 2026-08-26: `backend/app/Policies/` does not exist, `tests/Feature/Authorization/` and `tests/Feature/Policies/` do not exist, `grep -c AuthorizesRequests app/Http/Controllers/Controller.php` prints `0`, `grep -c prependToPriorityList bootstrap/app.php` prints `0`, and `grep -c UsePolicy app/Models/User.php` prints `0`. Two things in this story depend on TM-13 having landed:
  1. **Task 5 adds a row to `RouteAuthorizationTest::ACCESS`.** TM-13's completeness test fails the moment `auth.password` is registered without one — by design. If that file is absent, TM-13 has not landed and task 5 has nothing to edit.
  2. **TM-13 hoists `active` above `SubstituteBindings`.** This route takes no route parameter, so the `404`-oracle TM-13 fixes does not apply to it — but TM-13's fix also puts `EnsureUserIsActive` ahead of the binding, and `test_a_deactivated_user_cannot_change_their_password` asserts the `401` that middleware produces.
  **Neither is a code dependency in the compile sense.** If a decision is made to run this story first, everything in the Backend Tasks works, task 5 is deferred, and the two must then land together — TM-13's completeness test will fail on `auth.password` until the row exists. Say which order you took; do not leave it implied.
- **Stories 06–10 (TM-8 … TM-12) are present in application sources.** This story builds directly on four of them: `User.php:16`'s `#[Fillable]` includes `password`, `casts()` at 28–36 maps it to `hashed`, `routes/api.php:28-36` is TM-10's `['auth:sanctum', 'active']` group this route nests into, `LogoutController.php` is the `204` idiom, and `LoginRequest.php` is the FormRequest idiom.
- **TM-12 shipped sources without tests, and that is still true.** `tests/Feature/Admin/` does not exist and `docs/api-contract.md` (66 lines) has TM-12's four table rows but none of its four detail sections. **Out of scope here**; task 6 adds this story's row and section beside them without filling TM-12's gaps.
- **Measured baselines, 2026-08-26, containers healthy:** `backend/` — `php artisan test` → **62 passed / 151 assertions**. `frontend/` — `npm run test` → **23 passed across 5 files**. If TM-13 lands first, the backend baseline is **86**. Re-measure rather than trusting either number.
- **No coordination with another owner.** Nothing outside this feature folder touches `/auth/*`.

---

## Story Goal

Let a signed-in user rotate their own password by proving they know the current one, and make that rotation cut off every other device.

1. **`PATCH /api/v1/auth/password`** takes `current_password`, `password` and `password_confirmation`, and returns **`204`** with an empty body.
2. **A wrong current password is a `422` that changes nothing** — not "nothing visible", nothing: the write and the token revocation are one transaction.
3. **Every other token for that user is revoked; the token that made the request survives**, so the person changing their password stays signed in on the device they changed it from and is signed out everywhere else.
4. **An `/account/password` screen** in the SPA, reachable from the header, that renders the `422` on the field it belongs to.

**Not in scope.** Admin-initiated password reset and email-based self-service reset — no story in `tools/jira/backlog.json` owns either, `password_reset_tokens` exists and nothing reads it, and TM-12's plan already recorded the gap. Tightening `Password::defaults()` beyond its current meaning (**TM-64**, security hardening). Rotating the caller's *own* token. Any change to `UserPolicy` (see Product rules — this route deliberately consults no policy).

---

## Product rules (from story)

### `currentAccessToken()` is not always a token, and the obvious one-liner is a 500

Acceptance criterion 3 — *all **other** tokens are revoked* — reads as one line:

```php
$user->tokens()->whereKeyNot($user->currentAccessToken()->getKey())->delete();   // WRONG
```

`Laravel\Sanctum\Guard::__invoke` (`vendor/laravel/sanctum/src/Guard.php:30-38`) does **not** start at the bearer header. It starts here:

```php
foreach (Arr::wrap(config('sanctum.guard', 'web')) as $guard) {
    if ($user = $this->auth->guard($guard)->user()) {
        return $this->supportsTokens($user)
            ? $user->withAccessToken(new TransientToken)
            : $user;
    }
}
```

`backend/config/sanctum.php` is published in this repo and line 40 is `'guard' => ['web']`. So a session hit returns the user carrying a **`TransientToken`**, and `vendor/laravel/sanctum/src/TransientToken.php` is 30 lines with exactly two methods — `can()` and `cant()`. No `id`, no `getKey()`, no `delete()`. `getKey()` on it is `Error: Call to undefined method`, a **`500`** from the endpoint whose whole job is to be safe.

**Is that path reachable today?** No, and it is one line of config away from being reachable. `bootstrap/app.php` never calls `$middleware->throttleApi()` or `statefulApi()`, so the `api` group is `[SubstituteBindings::class]` alone (`Configuration/Middleware.php:495-499` filters the other two out when the properties are unset) — with no `StartSession`, the `web` guard has no session to read and returns no user. But `backend/.env.example:64` sets `SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173` and `CLAUDE.md` documents it, which is what someone reads before adding `statefulApi()`.

So task 3 type-checks instead of assuming, and the fallback is the *correct* behaviour rather than a guard clause:

```php
$current = $user->currentAccessToken();

$user->tokens()
    ->when(
        $current instanceof PersonalAccessToken,
        fn (Builder $query) => $query->whereKeyNot($current->getKey()),
    )
    ->delete();
```

A session caller has no bearer token to preserve, so revoking **all** of them is right for that path — the `when()` is not defensive padding, it is the second half of the rule.

**`LogoutController.php:11` has the same latent shape** (`currentAccessToken()->delete()`). It is TM-10's code, it is unreachable for the same reason, and it is **not fixed here** — one story, one endpoint. Note it in the overview so whoever calls `statefulApi()` fixes both.

### `current_password` resolves the *default* guard, and the default is `web`

`Illuminate\Validation\Concerns\ValidatesAttributes::validateCurrentPassword` (`ValidatesAttributes.php:602-614`):

```php
$guard = $auth->guard(Arr::first($parameters));

if ($guard->guest()) {
    return false;
}

return $hasher->check($value, $guard->user()->getAuthPassword());
```

With no parameter, `Arr::first([])` is `null` and the **default** guard answers. `backend/config/auth.php:19` makes that `env('AUTH_GUARD', 'web')` — the session guard, which on an API request is a guest, which makes the rule return `false`. A **correct** current password would be rejected with "The password is incorrect."

It happens to work anyway: `Illuminate\Auth\Middleware\Authenticate::authenticate` calls `$this->auth->shouldUse($guard)` on the guard that succeeded (`Authenticate.php:81-84`), so by the time the FormRequest validates, the default *is* `sanctum`. That is a chain of three facts holding up a security check.

**Write `current_password:sanctum`.** It costs eight characters, it is immune to a future story that validates the same field outside the `auth:sanctum` middleware, and the failure mode it prevents — a valid password rejected as invalid, with no clue why — is the kind of bug that gets "fixed" by removing the rule.

### What "a minimum strength rule" means today, measured

Acceptance criterion 2 asks for a minimum strength rule. `Password::defaults()` is the answer, and it is worth knowing exactly what it currently says. `Illuminate\Validation\Rules\Password::default()` (`Password.php:166-173`):

```php
$password = is_callable(static::$defaultCallback)
    ? call_user_func(static::$defaultCallback)
    : static::$defaultCallback;

return $password instanceof Rule ? $password : static::min(8);
```

Nothing in this project calls `Password::defaults($callback)` — `AppServiceProvider::boot()` holds TM-9's rate limiter and nothing else (`AppServiceProvider.php:23-26`). So **today it means "at least 8 characters" and nothing more**: no letter requirement, no digit requirement, no breach check.

**Use it as-is, and do not tighten it here.** Three reasons, in order of weight:

1. **TM-64 owns it, on the record.** TM-12's plan chose `Password::defaults()` for `StoreUserRequest` precisely so that *"it puts the policy in one place (`AppServiceProvider::boot()`) for TM-64's hardening pass to tighten, and every future password endpoint inherits the change."* This story is the second endpoint. Inheriting is the design working.
2. **Tightening here changes an endpoint this story does not own.** A `Password::defaults()` callback applies to `StoreUserRequest.php:18` too, so `POST /admin/users` would start rejecting passwords it accepts today — a behaviour change to TM-12's endpoint, made from TM-14's diff, with TM-12's tests still unwritten to catch it.
3. **`->uncompromised()` must not be added.** It calls the Have I Been Pwned range API on every request. That is a network dependency inside a password change, a test suite that fails offline, and a `500` when the service is slow.

The plan is honest about the consequence: **`password1` passes today.** Task 1's test pins the current rule (a 7-character password is a `422`, an 8-character one is not), so when TM-64 tightens the default that test fails and has to be updated deliberately.

### `UserPolicy` must not be consulted here, and that corrects a note in TM-13's plan

TM-13's overview says TM-14 *"inherits `UserPolicy::view`'s self branch (written for it)"*. Working the story through, **it does not** — and calling the obvious ability would break the endpoint for everyone it is for.

- `UserPolicy::update` in TM-13's plan is **admins only, including on their own record**, with a docblock saying so: *"there is no self-service name/email endpoint … TM-14 covers the one thing an agent may change about themselves with its own confirmation flow rather than through this ability."* So `$this->authorize('update', $request->user())` here is a **`403` for every agent** — the majority of the endpoint's users.
- `UserPolicy::view` is the wrong verb, and its self branch is `$user->is($target)`, which for `$request->user()` against itself is **always `true`**. TM-13 rejected exactly that pattern for `MeController` and `LogoutController`: *"a check that cannot fail is worse than no check: the next reader sees a policy call and assumes a rule is being enforced."*

So this endpoint's authorization is the guard chain plus the `current_password` rule, and its record is a **`'auth.password' => 'self'`** row in TM-13's manifest (task 5) — the same treatment `auth.me` and `auth.logout` get, for the same reason. **Do not add an ability to `UserPolicy` for this**, and do not add a `authorize()` override to the FormRequest: TM-13's FormRequest rule is about routes that have a *policy* question outside the `admin` prefix, and this one does not.

`UserPolicy::view`'s self branch therefore stays unreachable over HTTP after this story, exercised only by its unit test. That is worth writing down rather than quietly leaving TM-13's note wrong.

### "422 without changing anything" needs a transaction, not just validation order

Acceptance criterion 4 is satisfied for the *wrong current password* case by validation alone — the FormRequest rejects before the controller body runs, so nothing is written. Two tests pin it: the stored hash is unchanged **and** the token count is unchanged.

The case the criterion does not name, and the one that actually needs code: the password write succeeds and the token revocation then fails. The user now has a new password **and** live tokens on every device they meant to cut off — the exact opposite of the story's purpose, from a request that returned a `500`.

Task 3 wraps both in `DB::transaction()`, matching `UserController.php:57-69`'s precedent. Order inside it is write-then-revoke, and the revocation deliberately excludes the current token so the request that opened the transaction can still finish.

### Throttling: beyond the four criteria, and here anyway

None of the acceptance criteria mentions rate limiting. Task 2 adds `throttle:password` regardless, and the reason is that this endpoint is a **password oracle**: a caller holding a stolen token can test candidate passwords against `Hash::check` as fast as the server will answer, and a hit is worth far more than the token — it is the credential the person reuses elsewhere.

Two measurements make it concrete. TM-9's plan measured `Hash::check` at **287.9 ms** at `BCRYPT_ROUNDS=12` (`backend/.env.example:15`), so an unthrottled endpoint is also a CPU-exhaustion lever: one client, one connection, 3.5 bcrypt rounds a second of somebody else's server. And `bootstrap/app.php` never calls `throttleApi()`, so **`Configuration/Middleware.php:495-499` leaves the `api` group as `[SubstituteBindings::class]` alone** — there is no ambient limit to fall back on. `auth/login` is the only throttled route in the application (`routes/api.php:24-26`).

Following TM-9's precedent rather than inventing one: a **named limiter**, registered beside `login` in `AppServiceProvider::boot()`. It differs in one way that matters — `login` is keyed by IP because the caller has no identity yet; this one is keyed by **user id**, because the token names them and an IP key would let one user's fumbling lock out a colleague behind the same NAT.

**This is the one thing in the plan not traceable to an acceptance criterion.** It is three lines and three tests, and dropping it invalidates nothing else in the story. Say so if you drop it.

### The gap this story does not close

**A user who has forgotten their password still cannot be helped.** This endpoint needs the current one; TM-12 deliberately gave `PATCH /admin/users/{user}` no `password` field; `password_reset_tokens` was created by the users migration and nothing reads it. Recovery is still `php artisan tinker`.

That is unchanged by this story and it is **not a reason to widen it**. Do not add a `password` field to `UpdateUserRequest` "while you are in there" — TM-12's plan refused that specifically, and admin-initiated reset is a story with its own decisions about notifying the user and forcing a rotation on next sign-in.

---

## Context — Read These Files First

1. `backend/app/Models/User.php` — all 48 lines. **Line 16, `#[Fillable(['name', 'email', 'password', 'is_active'])]` — `password` is fillable**, so `$user->update(['password' => $plain])` is the write. `casts()` at 28–36 maps `'password' => 'hashed'`. Confirm in the framework that it hashes exactly once: `HasAttributes.php:1499-1501` is `if (! Hash::isHashed($value)) { return Hash::make($value); }`, so a plaintext string is hashed and an already-hashed one is passed through.
2. `backend/app/Http/Requests/Api/V1/LoginRequest.php` — all 16 lines, and the FormRequest idiom task 1 matches: namespace `App\Http\Requests\Api\V1` (**not** `…\Auth`), **no `authorize()` override**, one `rules(): array`, arrays of rules. Task 1 is its sibling in the same namespace.
3. `backend/app/Http/Requests/Api/V1/Admin/StoreUserRequest.php` — all 23 lines. **Line 18 is `'password' => ['required', 'string', Password::defaults()]`** — the rule task 1 reuses, and the endpoint a `Password::defaults()` callback would also change. Note the `@return array<string, list<mixed>|string>` docblock on `rules()`; copy it.
4. `backend/app/Http/Controllers/Api/V1/Auth/LogoutController.php` — all 17 lines, and the shape task 3 follows: single `__invoke(Request $request)`, **`use Illuminate\Http\Response;`** (not `JsonResponse` — `response()->noContent()` returns `Illuminate\Http\Response`, and TM-10's plan flagged the `TypeError` that copying `HealthController`'s signature causes), and `$request->user()->currentAccessToken()`. **Read the Product rule on `TransientToken` before copying that last call.**
5. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php:53-72` — `update()`. The `DB::transaction(function () use (...) {...})` pattern task 3 reuses, and **line 83's `->whereKeyNot($user->getKey())`** is the exact idiom task 3's revocation uses. Read 74–87 too: the lockout guard is what "validation that needs the controller" looks like in this codebase.
6. `backend/routes/api.php` — all 36 lines. `apiPrefix` is set in `bootstrap/app.php:18`, so task 4 writes `/auth/password`, never `/api/v1/auth/password`. **Lines 28–36 are TM-10's `['auth:sanctum', 'active']` group; the new route goes inside it and beside `auth.logout` / `auth.me`, NOT inside the `admin` sub-group at 30–35.** Line 24–26 is the `->middleware('throttle:login')` shape task 4 copies.
7. `backend/app/Providers/AppServiceProvider.php` — all 27 lines. **Line 25 is the only thing in `boot()`:** `RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));`. Task 2 adds one statement beside it and no imports (`Limit`, `Request`, `RateLimiter` are all imported at 5–7).
8. `backend/config/sanctum.php` — **line 40, `'guard' => ['web']`.** This is the config that makes `Guard.php:32-38`'s session branch real, and therefore the reason task 3 type-checks `currentAccessToken()`. Read it before you decide the check is paranoia.
9. `backend/vendor/laravel/sanctum/src/Guard.php:30-62` — `__invoke()`. Read both branches: the session branch returns `withAccessToken(new TransientToken)` at 35, the bearer branch returns `withAccessToken($accessToken)` — a real `PersonalAccessToken` — at 50–52.
10. `backend/vendor/laravel/sanctum/src/TransientToken.php` — the whole file, 30 lines. Two methods, `can()` and `cant()`. Confirm for yourself that there is no `id`, no `getKey()` and no `delete()`.
11. `backend/vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:602-614` — `validateCurrentPassword`. `Arr::first($parameters)` is the guard name and `null` means the default. Then `backend/config/auth.php:19` — `'guard' => env('AUTH_GUARD', 'web')`. Then `vendor/laravel/framework/src/Illuminate/Auth/Middleware/Authenticate.php:81-84` — `shouldUse($guard)`. Those three files together are why task 1 names the guard explicitly.
12. `backend/vendor/laravel/framework/src/Illuminate/Validation/Rules/Password.php:148-173` — `defaults()` and `default()`. **Line 172 is the fallback: `static::min(8)`.** Then `grep -rn 'Password::defaults(' backend/app/` — two hits after this story, both call sites, **zero** registrations.
13. `backend/vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php` — three messages the tests assert verbatim: line 39 `'confirmed' => 'The :attribute field confirmation does not match.'`, line 41 **`'current_password' => 'The password is incorrect.'`** (note it does **not** interpolate `:attribute`, so the field name never appears), line 48 `'different' => 'The :attribute field and :other must be different.'`.
14. `backend/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:103-115` — `$middlewarePriority`. `AuthenticatesRequests` at 109, **`ThrottleRequests` at 110**. That ordering is why task 2's limiter can read `$request->user()`: auth runs first even though the route lists `auth:sanctum` before `throttle:password`.
15. `backend/tests/Feature/Auth/LogoutTest.php` — all ~60 lines, and **the test file to model task 7 on**. `Tests\Feature\Auth` namespace, `use RefreshDatabase;`, a `private function tokenFor(User $user): string` at the bottom, `$this->withToken($token)->postJson(route('auth.logout', absolute: false))`, and note **line 37**: `$secondToken->accessToken->id` is how you get a created token's id for a "the other one survived" assertion. Line 45's `Auth::forgetGuards()` is mandatory before replaying a token in the same test.
16. `backend/tests/Feature/Auth/LoginThrottleTest.php` — all 38 lines. The throttle-test idiom for task 8: no cache clearing is needed because `phpunit.xml:25` sets `CACHE_STORE=array` and the store is per-test, and the assertions are `assertStatus(429)`, `assertJsonPath('message', 'Too Many Attempts.')` and `assertHeader('Retry-After')`.
17. `backend/tests/Feature/Auth/ActiveAccountTest.php:14-22` — the shape of "a token stops working when the account is deactivated", which task 7's `401` test reuses.
18. **TM-13's plan, [`11-story-role-based-authorization-via-policies-TM-13.md`](11-story-role-based-authorization-via-policies-TM-13.md)** — read its task 8 (the `ACCESS` manifest) and its Product rule on where authorization goes for routes outside `/admin/*`. Task 5 edits that manifest; the Product rule above explains why this story is the exception it names rather than an example of it.
19. `frontend/src/api/auth.ts` — all 38 lines. Task 9 appends one function and one interface. Note the module exports `UserRole` and `AuthUser` and that every function unwraps `data` itself.
20. `frontend/src/api/client.ts` — all 39 lines. **Line 5, `SESSION_PATHS = ['/auth/login', '/auth/me', '/auth/logout']`**, and lines 28–34: a `401` on any *other* path clears the token and calls the unauthorized handler. **`/auth/password` is deliberately absent from that list** — see task 9 — and task 12 adds the test that keeps it absent.
21. `frontend/src/views/LoginView.vue` — all 88 lines, and the view idiom task 11 matches: `<script setup lang="ts">`, `ref`s per field, a `submitting` ref, `errorMessage(caughtError)` into an `error` ref, `data-testid` on every input/button/message, `autocomplete` on the password field, and the scoped `.panel` / `form` / `.bad` styles. Copy the styles rather than inventing new ones.
22. `frontend/src/api/errors.ts` — all 21 lines. `validationErrors(error)` returns `error.response.data.errors` for a `422` and `{}` otherwise; `errorMessage(error)` maps `429` → `'Too many attempts. Try again in a minute.'` (line 14–15) and `403` → a permission message, then falls back to the server `message`, then `'The API is unreachable.'`. Task 11 uses **both**: `validationErrors` for the field messages, `errorMessage` for the form-level one.
23. `frontend/src/App.vue` — all 36 lines. Lines 17–23 are the header, rendered only `v-if="auth.isAuthenticated"`, with `data-testid="session-user"` and `data-testid="sign-out"`. Task 12 adds one `RouterLink` inside it — without which the new screen has no way in.
24. `frontend/src/router/index.ts` — all 44 lines. **Routes are private by default**: `guards.ts:32-36` checks `to.meta.public` and `to.meta.role`, so a route with no `meta` is authenticated-any-role, which is exactly what this screen needs. Task 10 adds a route with **no `meta` block at all**.
25. `frontend/src/api/client.spec.ts` — all 102 lines, and the technique task 13 uses: swap `client.defaults.adapter` for a function that captures the config (39–48) or throws an `AxiosError` with a chosen status (19–30). No HTTP mocking library. Note `it.each` at 80–91 over `SESSION_PATHS`.
26. `frontend/src/views/HealthView.spec.ts` — all 63 lines. The component-spec idiom: `mount(Component, { global: { plugins: [createPinia()] } })`, `flushPromises()`, `wrapper.get('[data-testid="…"]').text()`, `wrapper.find(…).exists()`. `frontend/src/router/guards.spec.ts:33-43` shows `createAppRouter(createMemoryHistory())`, which task 14 needs.
27. `frontend/.prettierrc.json` — `{"semi": false, "singleQuote": true}` at the default 80 columns. `npm run format:check` is a gate. Write it that way and save the diff.
28. `docs/api-contract.md` — 66 lines today: `## Conventions` 7–16, `## Endpoints` table 18–29, then `### POST /api/v1/auth/login` (31), `### POST /api/v1/auth/logout` (38), `### GET /api/v1/auth/me` (45), `### GET /api/v1/health` (52). **TM-13 inserts an `## Authorization` section between 16 and 18, so use the headings as anchors, not these line numbers.**

---

## Backend Tasks

### 1 — The form request

**Create file: `backend/app/Http/Requests/Api/V1/UpdatePasswordRequest.php`**

Namespace `App\Http\Requests\Api\V1`, beside `LoginRequest` — not under `…\Auth`, which is a controller namespace only.

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            // :sanctum is named on purpose. The rule resolves the DEFAULT guard
            // (ValidatesAttributes.php:602-614) and config/auth.php:19 makes
            // that `web`, which is a guest here; it only works implicitly
            // because auth:sanctum calls shouldUse() on success
            // (Authenticate.php:83). Naming it means a correct password can
            // never be rejected as incorrect by a routing change.
            'current_password' => ['required', 'string', 'current_password:sanctum'],

            // `confirmed` requires password_confirmation and needs no rule of
            // its own. `different` stops a "change" that changes nothing from
            // reporting success and signing the user out of their other
            // devices. Password::defaults() is min(8) today (Password.php:172)
            // and TM-64 owns tightening it — see Product rules.
            'password' => [
                'required',
                'string',
                'confirmed',
                'different:current_password',
                Password::defaults(),
            ],
        ];
    }
}
```

- **No `authorize()` override.** `FormRequest::authorize()` returns `true` by default and there is no policy question here — see the Product rule. `LoginRequest` makes the same choice for the same reason.
- **`confirmed`, not a hand-written `same:` rule.** Failure lands on `password` with `'The password field confirmation does not match.'` (`validation.php:39`), and a **missing** `password_confirmation` fails it too — `validateConfirmed` delegates to `validateSame` against a `null` other value. No `'password_confirmation' => ['required']` line is needed and adding one produces two errors for one mistake.
- **`different:current_password` is a real rule, not politeness.** Without it, submitting the same password twice returns `204` and revokes the user's other sessions — a destructive no-op that reads as success.
- **Field names are the payload contract.** `current_password` because that is what the rule is called and what the SPA renders the error under; `password` + `password_confirmation` because `confirmed` derives the second from the first and because `StoreUserRequest.php:18` already calls the new password `password`.

### 2 — The limiter

**File: `backend/app/Providers/AppServiceProvider.php`**

One statement in `boot()`, beside line 25. **No new imports** — `Limit`, `Request` and `RateLimiter` are already at lines 5–7.

```php
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        // Keyed by user, not by IP: the token names the caller, and an IP key
        // would let one person's fumbled form lock out a colleague behind the
        // same NAT. Six a minute absorbs a mistyped password twice over and
        // still makes guessing pointless — at BCRYPT_ROUNDS=12 each attempt
        // costs the server ~288ms of CPU, so this is a availability limit as
        // much as a credential one. ThrottleRequests sorts after
        // AuthenticatesRequests (Kernel.php:109-110), so user() is populated.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(6)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
    }
```

- **`?->getAuthIdentifier() ?? $request->ip()`** — the null branch is unreachable behind `auth:sanctum` and it is written anyway, because a limiter that throws when `user()` is null turns a `401` into a `500`.
- **`getAuthIdentifier()`, not `->id`.** It is the contract method (`Authenticatable`), it is what `Model::getKey()` resolves to, and it does not assume the primary key is called `id`.

### 3 — The controller

**Create file: `backend/app/Http/Controllers/Api/V1/Auth/PasswordController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    public function __invoke(UpdatePasswordRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        // One transaction, because the failure that matters is the second half
        // failing after the first succeeded: a new password plus live tokens on
        // every device the user meant to cut off, returned as a 500.
        DB::transaction(function () use ($request, $user): void {
            $user->update(['password' => $request->validated('password')]);
            $this->revokeOtherTokens($user);
        });

        return response()->noContent();
    }

    /**
     * Revoke every token for this user except the one that made the request.
     *
     * currentAccessToken() is NOT always a PersonalAccessToken. Sanctum's guard
     * checks config('sanctum.guard') — config/sanctum.php:40 is ['web'] —
     * before it reads the bearer header, and a session hit returns a
     * TransientToken (Guard.php:32-38), which has can() and cant() and nothing
     * else; getKey() on it is a 500. That path is unreachable today only
     * because bootstrap/app.php never calls statefulApi(), and .env.example:64
     * already sets SANCTUM_STATEFUL_DOMAINS.
     *
     * The fallback is the right answer rather than a guard clause: a session
     * caller has no token to preserve, so all of them go.
     */
    private function revokeOtherTokens(User $user): void
    {
        $current = $user->currentAccessToken();

        $user->tokens()
            ->when(
                $current instanceof PersonalAccessToken,
                fn (Builder $query) => $query->whereKeyNot($current->getKey()),
            )
            ->delete();
    }
}
```

- **`$request->validated('password')` returns the plain string.** Do **not** use `$request->string('password')`: that returns a `Stringable`, which reaches `Hash::isHashed()` as an object and depends on PHP's non-strict coercion to work at all. `validated()` is also the proof the value passed the rules.
- **`->update([...])`, not `->forceFill()` or a manual `Hash::make()`.** `password` is in `#[Fillable]` (`User.php:16`) and the `hashed` cast hashes it exactly once (`HasAttributes.php:1499-1501`). A hand-rolled `Hash::make()` **double-hashes** and locks the user out of an account whose endpoint returned `204`.
- **`: Response`, and the import is `Illuminate\Http\Response`.** `response()->noContent()` returns that class, not `JsonResponse`. TM-10 hit this exact `TypeError`.
- **`__invoke`, matching `Login`/`Logout`/`MeController`.** `UserController` is the project's only multi-action controller and only because it is a resource.
- **The current token is not rotated.** Acceptance criterion 3 says *other* tokens, and rotating would mean returning a new token and teaching the SPA to swap it mid-flight for no stated benefit.

### 4 — The route

**File: `backend/routes/api.php`**

Add the import beside the other three controllers at the top:

```php
use App\Http\Controllers\Api\V1\Auth\PasswordController;
```

Then one route **inside** TM-10's group and **outside** the `admin` sub-group:

```php
Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::post('/auth/logout', LogoutController::class)->name('auth.logout');
    Route::get('/auth/me', MeController::class)->name('auth.me');
    Route::patch('/auth/password', PasswordController::class)
        ->middleware('throttle:password')
        ->name('auth.password');
    Route::middleware('admin')->prefix('admin')->name('admin.')->group(function (): void {
```

- **`PATCH`, exactly as acceptance criterion 1 says.** A `PUT` alias would be a second row in `route:list` and a second thing to authorize; task 7 pins that `POST` and `PUT` are `405`.
- **No `/api/v1` in the path.** `bootstrap/app.php:18` sets `apiPrefix`; writing it here yields `/api/v1/api/v1/auth/password`.
- **Inside the auth group, above the `admin` sub-group.** Placed after it — or worse, inside it — the route would require the admin role and every agent would get a `403`.

### 5 — Register the route in TM-13's manifest

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

**If this file does not exist, TM-13 has not landed.** Say so in your report and skip this task; see Prerequisites.

One line in the `ACCESS` constant, in route order:

```php
        'auth.logout' => 'self',
        'auth.me' => 'self',
        'auth.password' => 'self',
```

`self`, not `admin`: any live account may change their own password, and the record is always the caller's own. This is what makes TM-13's `test_every_api_route_is_classified` pass with the new route registered.

**Then check one of TM-13's tests against this route rather than assuming.** `test_an_agent_reaches_every_self_route` loops the `self` routes; `PATCH /auth/password` **with no body is a `422`**, not a `200` or `204`. If that test asserts a specific status per route, add the `422` case explicitly. If it asserts "not `401` and not `403`", it already passes — and a `422` is exactly the right proof that the route was reached rather than refused. Do not relax the assertion to make it pass; a `422` from an empty body is the expected result.

`test_an_unauthenticated_caller_is_refused_by_every_non_public_route` needs no change: no token is a `401` here like everywhere else.

### 6 — The contract

**File: `docs/api-contract.md`**

Add a row to the `## Endpoints` table, immediately after the `auth/me` row:

```markdown
| `PATCH` | `/api/v1/auth/password` | Change your own password, confirming the current one. | bearer (throttled) | TM-14 |
```

Then a detail section after `### GET /api/v1/auth/me` and before `### GET /api/v1/health`:

```markdown
### `PATCH /api/v1/auth/password`

Requires `Authorization: Bearer <token>`. Limited to **6 requests per minute per
user** (not per IP — the token identifies the caller). Body:

| Field | Required | Notes |
|---|---|---|
| `current_password` | yes | Checked against the signed-in user's hash. |
| `password` | yes | The new password. At least 8 characters, and it must differ from the current one. |
| `password_confirmation` | yes | Must match `password`. |

Returns **`204`** with an empty body. On success every **other** token for that
user is revoked; the token that made the request keeps working, so the caller
stays signed in on that device and is signed out everywhere else.

A wrong current password returns **`422`** with the message
`"The password is incorrect."` under `errors.current_password`, and **nothing is
written** — the password write and the token revocation are one transaction. A
short, unconfirmed, or unchanged new password returns `422` under
`errors.password`. A missing, malformed, revoked, or inactive-account token
returns `401` with `Unauthenticated.` Excess requests return `429` with a
`Retry-After` header.

There is **no** admin-initiated or email-based password reset. `PATCH
/api/v1/admin/users/{user}` has no `password` field by design (TM-12), and no
story owns self-service recovery yet.
```

---

## Frontend Tasks

### 7 — Note before you start

Everything below is additive: **no existing frontend file is rewritten**, three are edited by a few lines each (`src/api/auth.ts`, `src/router/index.ts`, `src/App.vue`), and one new view plus three new specs are created. `src/api/client.ts`, `src/api/errors.ts`, `src/stores/auth.ts` and `src/router/guards.ts` are **not touched**.

### 8 — Skipped intentionally

There is **no Pinia store for this screen.** A password change is one fire-and-forget request with no state to share: nothing else in the app reads it, there is no list to keep fresh, and there is no stale-response race because the component is the only caller. `src/stores/users.ts` exists because a paginated, filtered list has genuine shared state. Adding `src/stores/password.ts` would be a store whose only job is to forward a promise.

### 9 — `changePassword` in the API module

**File: `frontend/src/api/auth.ts`**

Append at the end of the file, after `logout()`:

```ts
export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}

export async function changePassword(
  payload: ChangePasswordPayload,
): Promise<void> {
  await client.patch('/auth/password', payload)
}
```

- **Snake-case keys, sent verbatim.** The keys are the server's validation field names and the SPA renders `errors.current_password` back against the same string. Renaming to camelCase here would need a mapping in both directions and would break the error-to-field wiring.
- **`Promise<void>`** — the endpoint returns `204`; there is nothing to unwrap.
- **Do NOT add `/auth/password` to `SESSION_PATHS` in `src/api/client.ts:5`.** That list exists so a `401` from the session endpoints does not trigger a redirect loop. A `401` here means the token is genuinely dead — revoked from another device, or the account deactivated — and the correct response is exactly what the interceptor does: clear it and send the user to the login form. Task 13 adds the test that keeps it out of the list.

### 10 — The route

**File: `frontend/src/router/index.ts`**

Import the view beside the others (alphabetical, so first):

```ts
import AccountPasswordView from '../views/AccountPasswordView.vue'
```

Add the route after `/forbidden` and before `/admin/users`:

```ts
      {
        path: '/account/password',
        name: 'account-password',
        component: AccountPasswordView,
      },
```

- **No `meta` block at all.** Routes are private by default here — `guards.ts:32-36` sends an unauthenticated visitor to `login` and only a `meta.role` mismatch to `forbidden` — so an absent `meta` means "any live account", which is what this screen is. **Adding `meta: { role: 'admin' }` by pattern-matching on the `/admin/users` entry above it would lock every agent out of their own password.**
- **`declare module 'vue-router'` at lines 10–15 needs no change.** The new route uses neither key.

### 11 — The screen

**Create file: `frontend/src/views/AccountPasswordView.vue`**

Match `LoginView.vue`'s structure and reuse its scoped styles verbatim.

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { changePassword } from '../api/auth'
import { errorMessage, validationErrors } from '../api/errors'

const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const changed = ref(false)
const submitting = ref(false)

async function submit(): Promise<void> {
  submitting.value = true
  fieldErrors.value = {}
  error.value = null
  changed.value = false
  try {
    await changePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    currentPassword.value = ''
    password.value = ''
    passwordConfirmation.value = ''
    changed.value = true
  } catch (caughtError) {
    fieldErrors.value = validationErrors(caughtError)
    // Field messages are the whole story for a 422; a form-level banner on top
    // of them says the same thing twice in two places.
    if (!Object.keys(fieldErrors.value).length)
      error.value = errorMessage(caughtError)
  } finally {
    submitting.value = false
  }
}
</script>
```

Template requirements, each with a `data-testid` so task 14 can assert it:

- Three `<input type="password">`: `password-current` (`autocomplete="current-password"`), `password-new` and `password-confirmation` (both `autocomplete="new-password"`), each `required`, each `v-model`-bound.
- **`data-testid="password-error-current_password"` under the first input**, rendered from `fieldErrors['current_password']?.[0]`, and `data-testid="password-error-password"` under the second from `fieldErrors['password']?.[0]`. The keys are the server's field names — that is the whole reason task 9 sends snake_case.
- `data-testid="password-error"` for the form-level message from `errorMessage` (the `429`, the network failure).
- `data-testid="password-changed"` for the success message: **"Password changed. You have been signed out on your other devices."** — say what happened, because the user is still signed in here and will otherwise assume nothing did.
- `data-testid="password-submit"`, `:disabled="submitting"`, label switching to `'Saving...'` while in flight, exactly as `LoginView.vue:53-55` does.
- `<form @submit.prevent="submit">`.

**No redirect on success.** The caller's own token survives, so they stay signed in and stay on the page. Navigating to `/` would be a lie about what happened, and navigating to `/login` would be wrong twice.

### 12 — A way in

**File: `frontend/src/App.vue`**

The screen is unreachable without this. One `RouterLink` inside the existing header (lines 17–23), between the user name and the sign-out button:

```vue
  <header v-if="auth.isAuthenticated" class="bar">
    <span data-testid="session-user">{{ auth.user?.name }}</span>
    <RouterLink :to="{ name: 'account-password' }" data-testid="nav-password">
      Password
    </RouterLink>
    <button type="button" @click="signOut" data-testid="sign-out">
      Sign out
    </button>
  </header>
```

`RouterLink` is globally registered by the router plugin, so no import is needed — the same way `RouterView` is used at line 24. The `.bar` flex styles at 28–35 already space three children; **no style change.**

---

## Edge Cases & Failure Modes

- **`currentAccessToken()` returning a `TransientToken`.** `getKey()` on it is `Error: Call to undefined method`, a `500` from the password endpoint. Unreachable today only because `bootstrap/app.php` never calls `statefulApi()`; `.env.example:64` already sets `SANCTUM_STATEFUL_DOMAINS`. Task 3's `instanceof PersonalAccessToken` check is the fix, and the fallback — revoke everything — is correct for that path. **`LogoutController.php:11` has the same shape and is deliberately left alone.**
- **`current_password` without `:sanctum`.** Resolves the default guard, which `config/auth.php:19` makes `web`. It works today only because `Authenticate.php:83` calls `shouldUse('sanctum')`. Move the rule to a route outside `auth:sanctum` and `$guard->guest()` is true, so `validateCurrentPassword` returns `false` and **a correct password is rejected as incorrect** — with a message that gives no hint the guard is the problem.
- **Hashing the new password by hand.** `Hash::make($request->validated('password'))` passed into a fillable `password` attribute is hashed **again** by the `hashed` cast (`HasAttributes.php:1499-1501` only skips already-hashed values, and a bcrypt hash *is* already-hashed — so this one actually survives). The genuine failure is the reverse: `forceFill` with a plaintext value bypassing the cast, storing the password in clear. Use `update()` and let the cast work.
- **Revoking the current token along with the others.** `$user->tokens()->delete()` with no exclusion signs the user out of the device they just used, so the SPA's next request `401`s and the interceptor bounces them to the login form — which reads as "the password change failed". Acceptance criterion 3 says *other*.
- **A wrong current password that still writes.** Cannot happen through validation ordering — the FormRequest rejects before the controller body — but the test asserts the **stored hash** and the **token count**, not just the status code, because "422 without changing anything" is a claim about the database.
- **The revocation failing after the write.** Without `DB::transaction()`, the user has a new password and live tokens everywhere, returned as a `500`. This is the only failure mode in the story that a passing status code hides.
- **Reusing the current password.** Without `different:current_password`, a `204` and a mass sign-out for a change that changed nothing.
- **A missing `password_confirmation`.** `confirmed` fails on `password` (`validateConfirmed` → `validateSame` against `null`), so no separate `required` rule is needed. Adding one produces two error messages for one mistake and the SPA renders whichever comes first.
- **`Password::defaults()` meaning less than it looks.** It is `min(8)` and nothing else today (`Password.php:172`), so `password1` is accepted. Stated in Product rules, pinned by a test, and owned by **TM-64**. A story that registers a `Password::defaults()` callback also changes `POST /admin/users` (`StoreUserRequest.php:18`) — whose tests TM-12 never wrote.
- **`throttle:password` keyed by IP.** Two agents behind one office NAT share a budget, so one person mistyping their password six times locks the other out of their own account. `->by($request->user()?->getAuthIdentifier())` is why task 2 does not copy `login`'s key.
- **A limiter that throws when `user()` is null.** `ThrottleRequests` sorts after `AuthenticatesRequests` (`Kernel.php:109-110`) so it cannot happen behind `auth:sanctum` — and the `?? $request->ip()` fallback means a future unauthenticated route gets a `429` rather than a `500`.
- **A `429` mistaken for a `422`.** The `429` body is `{"message": "Too Many Attempts."}` with **no `errors` key**, so `validationErrors()` returns `{}` and task 11's `if (!Object.keys(...).length)` branch is what renders it. Drop that branch and a throttled user sees an empty form with no explanation.
- **A `401` from `/auth/password`.** The interceptor clears the token and redirects, because the path is **not** in `SESSION_PATHS` (`client.ts:5`). That is correct — the token is dead — and it is the one behaviour a "for symmetry" edit would break. Task 13 pins it.
- **Adding `meta: { role: 'admin' }` to the new route.** Copies the neighbouring `/admin/users` entry and locks every agent out of their own password, with no server error to explain it: `guards.ts:35` redirects to `/forbidden` before any request is made.
- **The screen with no link to it.** `/account/password` typed by hand would work, and nobody would find it. Task 12 is not cosmetic; task 14 asserts the link.
- **A success message left on screen after a later failure.** `changed.value = false` at the top of `submit()`, before the request. Without it a failed second attempt renders "Password changed" above an error.
- **Unicode passwords.** Both MySQL containers are `utf8mb4` and bcrypt hashes **bytes**, so a multibyte password works — but bcrypt silently truncates at **72 bytes**, which is roughly 24 Arabic characters. Nobody hits it and one test records it so a future `max:` rule is a decision rather than a surprise.
- **`npm run lint` after adding a spec.** `tsconfig.app.json` sets `noUnusedLocals` / `noUnusedParameters`, so an unused import fails `npm run typecheck` and `npm run build` while `vitest` still passes; Prettier is a separate gate. Run `npm run format && npm run lint && npm run typecheck && npm run test`.
- **`Auth::forgetGuards()` omitted between two tokens in one test.** The resolved guard caches its user, so replaying a revoked token still succeeds and the "other tokens no longer authenticate" assertion tests nothing. `LogoutTest.php:45` is the precedent.
- **`Sanctum::actingAs()` used in a new test.** It attaches `Mockery::mock(PersonalAccessToken::class)->shouldIgnoreMissing(false)` (`Sanctum.php:70-92`). The mock **is** `instanceof PersonalAccessToken`, so task 3's `when()` fires and `->getKey()` on the mock throws `BadMethodCallException`. `grep -rn "Sanctum::actingAs" backend/tests/` returns nothing today; keep it that way and mint real tokens.

---

## Test Plan

`composer test` from `backend/`, against `tm-mysql-test` on 3307. **Measured baseline 2026-08-26: 62 tests / 151 assertions** — 86 if TM-13 landed first. Re-run before starting.

### Backend

1. **Create `backend/tests/Feature/Auth/PasswordTest.php`** — namespace `Tests\Feature\Auth`, `use RefreshDatabase;`, real bearer tokens via a `private function tokenFor(User $user): string` copied from `LogoutTest.php`. **Never `Sanctum::actingAs()`.** Seventeen tests:
   - `test_it_changes_the_password_and_returns_204` — `assertNoContent()`.
   - `test_the_new_password_actually_works_for_login` — after the change, `POST /auth/login` with the **new** password is `200` and with the **old** one is `422`. This is the test that proves the write landed; asserting the `204` alone would pass against a controller that did nothing.
   - `test_it_hashes_the_new_password_once` — reload the user and assert `Hash::check('new-secret-value', $user->password)` **and** `$user->password !== 'new-secret-value'`. Catches both a bypassed cast and a double hash.
   - `test_it_revokes_the_users_other_tokens` — mint three tokens, change the password with the first, assert `$user->tokens()->count()` is **`1`**.
   - `test_it_keeps_the_token_that_made_the_request` — the surviving row's id equals the acting token's, then `Auth::forgetGuards()` and that same token still gets `200` from `/auth/me`. Use `$created->accessToken->id` as `LogoutTest.php:37` does.
   - `test_the_revoked_tokens_no_longer_authenticate` — replay a second token after `Auth::forgetGuards()`: `401` with `assertJsonPath('message', 'Unauthenticated.')`.
   - `test_it_leaves_another_users_tokens_alone` — a second user with two tokens still has two. The negative control; without it a `PersonalAccessToken::query()->delete()` passes every test above.
   - `test_a_wrong_current_password_is_422_and_changes_nothing` — `422`, `assertJsonPath('errors.current_password.0', 'The password is incorrect.')`, **the stored hash is byte-identical to before**, and the token count is unchanged. **Acceptance criterion 4**, asserted against the database rather than the status line.
   - `test_it_requires_the_current_password` — `patchJson` with only `password` + confirmation is `422` on `current_password`.
   - `test_it_requires_a_confirmation` — `password` with no `password_confirmation` is `422` on **`password`** (not on `password_confirmation`), pinning where `confirmed` reports.
   - `test_it_rejects_a_mismatched_confirmation` — `422` on `password`.
   - `test_it_rejects_a_password_shorter_than_eight_characters` — a 7-character password is `422`; an 8-character one is not. **Pins `Password::defaults()` at today's `min(8)`, so TM-64's tightening breaks this test on purpose.**
   - `test_it_rejects_reusing_the_current_password` — `422` on `password`, and the token count is unchanged.
   - `test_it_rejects_a_request_with_no_token` — `401`.
   - `test_a_deactivated_user_cannot_change_their_password` — an `->inactive()` user with a real token gets `401`, and their tokens are revoked (`EnsureUserIsActive.php:40-43`).
   - `test_the_method_is_pinned` — `postJson` and `putJson` on the same path are **`405`**. Stops a later `Route::any` or an `apiResource` refactor widening it silently.
   - `test_it_accepts_a_unicode_password` — an Arabic password under 72 bytes round-trips: the change is `204` and logging in with it works.
   - `test_the_route_is_versioned_under_api_v1` — `assertSame('/api/v1/auth/password', route('auth.password', absolute: false))`, matching `ProtectedRouteTest.php:15`.

   *(That is 18 bullets for 17 tests — fold `test_the_revoked_tokens_no_longer_authenticate` into `test_it_keeps_the_token_that_made_the_request` if you prefer one assertion per behaviour; the count in Done Criteria is what your run must match, not this list's arithmetic.)*

2. **Create `backend/tests/Feature/Auth/PasswordThrottleTest.php`** — same namespace and idiom as `LoginThrottleTest.php`. No cache clearing: `phpunit.xml:25` sets `CACHE_STORE=array`, so each test starts empty. Three tests:
   - `test_it_allows_six_attempts_per_minute` — six wrong-current-password requests, each `422`.
   - `test_it_blocks_the_seventh_attempt` — `assertStatus(429)`, `assertJsonPath('message', 'Too Many Attempts.')`, `assertHeader('Retry-After')`.
   - `test_the_limit_is_per_user_not_per_ip` — user A burns all six and is `429`; user B's **first** request in the same test is still `422`. The assertion that fails if the limiter is keyed by `$request->ip()`.

3. **No test for `UpdatePasswordRequest::rules()` in isolation.** Every rule is exercised over HTTP above; a test asserting `rules()` returns a particular array pins the plan rather than the behaviour, which is the call TM-9 and TM-12 both made.

4. **All existing backend tests must pass unchanged** — 62, or 86 with TM-13. Task 2 touches `AppServiceProvider::boot()`, which every test boots, and task 4 changes the route table that `ProtectedRouteTest.php:26-36` iterates. Note that test filters on `str_starts_with($name, 'auth.')` and excludes only `auth.login`, so **`auth.password` is picked up automatically** and must carry `auth:sanctum` and `active` — it does, and that is a free assertion, not a change.

**Backend total added: 20 tests** (17 + 3) → **82**, or **106** with TM-13.

### Frontend

`npm run test` from `frontend/`. **Measured baseline: 23 across 5 files.** Write everything Prettier-formatted (no semicolons, single quotes, 80 columns).

5. **Create `frontend/src/api/auth.spec.ts`** — the adapter-swap technique from `client.spec.ts:11-30`, no mocking library. Three tests:
   - `changePassword` sends **`PATCH`** to `/auth/password` — assert `config.method` and `config.url` from a capturing adapter.
   - it sends the three snake_case keys verbatim — assert the parsed `config.data`, so a well-meaning camelCase rename fails here rather than silently breaking the `422`-to-field wiring.
   - it rejects on a `422` — `failingAdapter(422)`, `await expect(...).rejects.toThrow()`. Proves the function does not swallow the error the view needs.

6. **Create `frontend/src/views/AccountPasswordView.spec.ts`** — `vi.mock('../api/auth', async (loadOriginal) => ({ ...(await loadOriginal()), changePassword: vi.fn() }))`, matching `auth.spec.ts:8-13`. Mount with `mount(AccountPasswordView)` — **no Pinia plugin needed**, the view uses no store. Nine tests:
   - submits the three field values from the three inputs.
   - on success, renders `password-changed` **and** clears all three inputs — assert the `element.value`s are empty, because a form that keeps the old password on screen is the bug this catches.
   - renders `password-error-current_password` from a `422` whose `errors.current_password` is `['The password is incorrect.']`, and renders **no** `password-error`.
   - renders `password-error-password` from a `422` on `errors.password`.
   - renders `password-error` with `'Too many attempts. Try again in a minute.'` for a `429` — a body with a `message` and no `errors` key.
   - renders `'The API is unreachable.'` for a non-Axios error.
   - does not render `password-changed` after a failure, and clears a previous success message on a second submit.
   - disables `password-submit` while the request is in flight and re-enables it after — resolve a deferred promise to hold it open.
   - clears a previous field error on resubmit — a `422` then a success leaves no `password-error-current_password` behind.

7. **Create `frontend/src/App.spec.ts`** — mount with a real memory router, as `guards.spec.ts:34` builds one:

    ```ts
    const mountApp = async () => {
      const router = createAppRouter(createMemoryHistory())
      await router.push('/')
      await router.isReady()
      return mount(App, { global: { plugins: [createPinia(), router] } })
    }
    ```

    Two tests: `nav-password` **exists** when the auth store holds a user, and **does not exist** when it does not. Two tests is the whole budget here, and they are the only thing standing between the new screen and being unreachable.

8. **Edit `frontend/src/api/client.spec.ts`** — one test added, nothing else changed: a `401` from `/auth/password` **does** clear the token and **does** call the unauthorized handler, mirroring lines 60–68. This is the test that stops someone adding the path to `SESSION_PATHS` "for symmetry" with the other `/auth/*` routes. **Do not extend the `it.each` at 80–91** — that list is the paths that must *not* trigger it.

9. **TM-11's specs must pass untouched** — `src/stores/auth.spec.ts`, `src/router/guards.spec.ts`, `src/stores/health.spec.ts`, `src/views/HealthView.spec.ts`. Task 10 adds a route and task 12 adds a header link; if `guards.spec.ts` breaks, one of them changed behaviour rather than adding to it.

**Frontend total added: 15 tests** (3 + 9 + 2 + 1) → **38 across 8 files**.

**Combined: 35 new tests.**

---

## Migration / Rollback

No schema change, no data change, no new dependency on either side.

- **The only stateful side effect is token revocation, and it is not reversible.** A user who runs this endpoint has their other tokens deleted; rolling the code back does not restore them, it just means those people sign in again. Say that in the release note rather than treating it as a bug report.
- **A half-applied state to avoid: task 4 without task 2.** `->middleware('throttle:password')` with no `RateLimiter::for('password', …)` registered is **not** a silent no-op — `ThrottleRequests` resolves an unnamed limiter to `null` and throws, so the endpoint `500`s on its first request. Land tasks 2 and 4 together.
- **Task 4 without task 5** (when TM-13 has landed) leaves TM-13's `test_every_api_route_is_classified` failing. That is the test doing its job; add the row rather than skipping the test.
- **Rollback order is frontend-then-backend.** Removing the route while `AccountPasswordView.vue` is still linked gives a `404` inside a screen; removing the screen first gives nothing.

---

## Verification Steps

Run in this order. The working directory is stated for every command.

1. **Prerequisites are real:** repo root — `docker compose ps` shows all three containers **healthy**. `backend/` — `ls app/Http/Controllers/Api/V1/Auth/LogoutController.php app/Http/Requests/Api/V1/LoginRequest.php` succeeds and `grep -c "'password'" app/Models/User.php` is non-zero. Then **decide and record the TM-13 order**: `ls tests/Feature/Authorization/RouteAuthorizationTest.php` either succeeds (do task 5) or does not (skip it and say so).
2. **Record the real baseline:** `backend/` — `php artisan test`; expect **62** (or **86** with TM-13). `frontend/` — `npm run test`; expect **23**. Write both down.
3. **Confirm the two facts the plan is built on, before writing code:** `backend/` —

    ```bash
    grep -n "'guard'" config/sanctum.php                 # => 40: 'guard' => ['web'],
    grep -c "statefulApi\|throttleApi" bootstrap/app.php  # => 0
    php artisan tinker --execute="echo get_class(Illuminate\Validation\Rules\Password::defaults()), PHP_EOL; echo config('auth.defaults.guard'), PHP_EOL;"
    ```

    The last command prints `Illuminate\Validation\Rules\Password` and **`web`**. If `config('auth.defaults.guard')` is not `web`, re-read the Product rule on `current_password` before choosing the rule string.
4. **After tasks 1–4, the route is wired and gated:** `backend/` — `php artisan optimize:clear`, then `php artisan route:list --path=auth`. There is exactly one `PATCH api/v1/auth/password` row named `auth.password`, and its middleware lists **`auth:sanctum`**, **`active`** and **`throttle:password`**. A missing `active` means the route was placed beside TM-10's group instead of inside it; a missing `throttle:password` means task 4 dropped the chained call.
5. **The happy path, end to end:** `backend/` — `php artisan migrate:fresh --seed && php artisan serve`, then

    ```bash
    TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/auth/login \
      -H 'Accept: application/json' -H 'Content-Type: application/json' \
      -d '{"email":"admin@ticket-management.test","password":"password"}' \
      | python3 -c 'import sys,json; print(json.load(sys.stdin)["token"])')

    curl -s -X PATCH http://localhost:8000/api/v1/auth/password \
      -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
      -d '{"current_password":"password","password":"new-secret-value","password_confirmation":"new-secret-value"}' \
      -w '\nstatus=%{http_code}\n'
    ```

    **`status=204`** and an empty body. Then log in with `new-secret-value` (`200`) and with `password` (`422`).
6. **The caller's own token survives:** replay `$TOKEN` against `/api/v1/auth/me` — **`200`**. If it is `401`, the revocation did not exclude the current token and every user of this endpoint gets bounced to the login form.
7. **Other tokens are gone:** before step 5, mint a second token by logging in twice and keep it as `$OTHER`. After the change, `curl` `/auth/me` with `$OTHER` → **`401`**, and

    ```bash
    php artisan tinker --execute="echo App\Models\User::where('email','admin@ticket-management.test')->sole()->tokens()->count(), PHP_EOL;"
    ```

    prints **`1`**.
8. **A wrong current password changes nothing:** capture the hash first, then attempt the change with a wrong `current_password`:

    ```bash
    php artisan tinker --execute="echo App\Models\User::where('email','admin@ticket-management.test')->sole()->password, PHP_EOL;"
    ```

    The response is **`422`** with `errors.current_password` = `["The password is incorrect."]`, the hash printed again afterwards is **identical**, and the token count is **unchanged**. **Acceptance criterion 4.**
9. **The validation edges:** a 7-character new password is `422` on `password`; the same password as the current one is `422` on `password`; a mismatched `password_confirmation` is `422` on `password`; omitting `password_confirmation` entirely is `422` on **`password`**, not on `password_confirmation`.
10. **The method is pinned:** `curl -X POST` and `curl -X PUT` on the same path are both **`405`**.
11. **Throttling works and is per user:** send seven wrong-password requests as one user — the seventh is **`429`** with `{"message":"Too Many Attempts."}` and a `Retry-After` header. Then, **without waiting**, send one as a *different* user's token: **`422`**, not `429`. If the second user is throttled, the limiter is keyed by IP.
12. **A deactivated user is refused:** create an agent, mint their token, deactivate them via `PATCH /admin/users/{id}` as the admin, then replay their token here — **`401`**, and their token count is `0`.
13. **Backend gates:** `backend/` — `composer test` exits `0` with **20 new tests** and every pre-existing test still passing. `composer lint` exits `0`. Restore the dev database with `php artisan migrate:fresh --seed`.
14. **The screen, in a browser:** repo root — `docker compose up -d`; `backend/` — `php artisan serve`; `frontend/` — `npm run dev`. Sign in, and the header shows a **Password** link. Follow it to http://localhost:5173/account/password.
15. **The wrong-password message lands on the right input:** enter a wrong current password and a valid new one. The message renders **under the current-password field**, not as a form-level banner, and the form still holds what you typed in the other two fields.
16. **The success state says what happened:** with a second browser profile signed in as the same user, change the password in the first. The first shows "Password changed. You have been signed out on your other devices.", all three inputs are empty, and **you are still signed in here**. Reload the second profile — it is bounced to the login form.
17. **An agent can reach it:** sign in as an agent and visit `/account/password`. The screen renders. **If you land on `/forbidden`, task 10 added a `meta.role` block** — remove it.
18. **Frontend gates:** `frontend/` — `npm run format && npm run lint && npm run typecheck && npm run test`, all exiting `0`, with **15 new tests** (38 total) and TM-11's four spec files passing untouched. Then `npm run format:check` alone exits `0`.
19. **Contract matches the code:** read `docs/api-contract.md`'s new row and section against the statuses measured in steps 5–12, field by field, including the "no admin-initiated reset" paragraph.
20. **Regression:** repo root — `git status --short` shows changes confined to `backend/app/Http/Controllers/Api/V1/Auth/PasswordController.php`, `backend/app/Http/Requests/Api/V1/UpdatePasswordRequest.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`, `backend/tests/Feature/Auth/Password*Test.php`, `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (one line, if TM-13 landed), `docs/api-contract.md`, `frontend/src/api/auth.ts`, `frontend/src/api/auth.spec.ts`, `frontend/src/api/client.spec.ts`, `frontend/src/router/index.ts`, `frontend/src/App.vue`, `frontend/src/App.spec.ts`, `frontend/src/views/AccountPasswordView.{vue,spec.ts}`, and this feature's `.squad/plans/` files. **Nothing in `backend/app/Models/`, `backend/app/Policies/`, `backend/bootstrap/app.php`, `backend/config/`, `backend/database/`, `phpunit.xml`, `composer.json`, `composer.lock`, `frontend/package.json`, `frontend/src/api/client.ts`, `frontend/src/api/errors.ts`, `frontend/src/stores/`, `frontend/src/router/guards.ts`, `docker-compose.yml`, `docs/erd.md` or `CLAUDE.md`.**

---

## Done Criteria

- [ ] `PATCH /api/v1/auth/password` exists as `auth.password`, is registered **inside** TM-10's `['auth:sanctum', 'active']` group and **outside** the `admin` sub-group, carries `throttle:password`, and `POST`/`PUT` on the same path are **`405`**. **Acceptance criterion 1.**
- [ ] `UpdatePasswordRequest` requires `current_password`, `password` and `password_confirmation`, with **`current_password:sanctum`** — the guard named explicitly, because the rule resolves the *default* guard (`ValidatesAttributes.php:602-614`) and `config/auth.php:19` makes that `web`. A missing `password_confirmation` is a `422` on **`password`**, pinned by a test.
- [ ] `password` is validated with `Password::defaults()`, **unchanged** — no `Password::defaults($callback)` registered anywhere (`grep -rn 'Password::defaults(' backend/app/` returns two call sites and zero registrations), so it means `min(8)` today (`Password.php:172`) and **TM-64 owns tightening it**. A test pins a 7-character password as a `422` so that tightening breaks it deliberately. **Acceptance criterion 2.**
- [ ] `password` also carries **`different:current_password`**, so a "change" to the same password is a `422` rather than a `204` plus a mass sign-out.
- [ ] Every **other** token for that user is revoked and **the token that made the request still works** — a test asserts the surviving row is the acting token's and that it still gets `200` from `/auth/me`, plus a negative control that a second user's tokens are untouched. **Acceptance criterion 3.**
- [ ] The revocation **type-checks `currentAccessToken()`** against `PersonalAccessToken` and revokes everything when it is not one. `config/sanctum.php:40` is `'guard' => ['web']`, so `Guard.php:32-38` can return a `TransientToken`, which has only `can()`/`cant()` — `getKey()` on it is a `500`. `LogoutController.php:11`'s identical latent shape is **left alone** and recorded in `00-overview.md`.
- [ ] The password write and the token revocation are one **`DB::transaction()`**, so a failed revocation cannot leave a new password beside live tokens.
- [ ] A wrong current password returns `422` with `errors.current_password` = `["The password is incorrect."]` and **the stored hash and token count are both unchanged** — asserted against the database, not just the status line. **Acceptance criterion 4.**
- [ ] The new password is written with `$user->update(['password' => $request->validated('password')])` — fillable (`User.php:16`) plus the `hashed` cast (`User.php:32`, `HasAttributes.php:1499-1501`), so it is hashed exactly once. **No `Hash::make()` and no `forceFill`.** A test reloads the user and asserts both `Hash::check` and that the stored value is not the plaintext.
- [ ] `RateLimiter::for('password', …)` is registered beside `login` in `AppServiceProvider::boot()`, keyed by **`$request->user()?->getAuthIdentifier() ?? $request->ip()`** at **6/minute**, with a test proving user B is not throttled by user A's attempts. Recorded in the plan as the **one addition beyond the acceptance criteria**, with its reason.
- [ ] **`UserPolicy` is not touched and not consulted.** `grep -rn authorize app/Http/Controllers/Api/V1/Auth/PasswordController.php` returns nothing, and the route is classified **`'auth.password' => 'self'`** in TM-13's `RouteAuthorizationTest::ACCESS` — or that task is explicitly reported as deferred because TM-13 has not landed. TM-13's note that TM-14 would consume `UserPolicy::view`'s self branch is **corrected in `00-overview.md`**: it does not.
- [ ] `docs/api-contract.md` has the `PATCH /api/v1/auth/password` table row and a detail section covering the three fields, the `204`, the surviving-token behaviour, the `422` field keys, the `429`, and the fact that **no admin-initiated or email-based reset exists**. TM-12's four missing detail sections are still missing and remain TM-12's debt.
- [ ] `frontend/src/api/auth.ts` exports `changePassword` and `ChangePasswordPayload`, sending the three **snake_case** keys verbatim by `PATCH`, with a spec asserting the method, the URL and the key names.
- [ ] **`/auth/password` is NOT in `SESSION_PATHS`** (`client.ts:5`), and a test in `client.spec.ts` asserts that a `401` from it **does** clear the token and call the unauthorized handler — because a `401` there means the token is genuinely dead. `client.ts` itself is unchanged.
- [ ] `frontend/src/router/index.ts` routes `/account/password` as `account-password` with **no `meta` block** — private by default via `guards.ts:32-36`, reachable by admins **and agents**. `guards.ts` is unchanged and TM-11's `guards.spec.ts` passes untouched.
- [ ] `AccountPasswordView.vue` renders `errors.current_password` **under the current-password input** and `errors.password` under the new-password input, falls back to `errorMessage()` only when a response carries no `errors` key (so a `429` still renders), clears all three inputs on success, shows a message that says other devices were signed out, and **does not redirect** — the caller stays signed in.
- [ ] `App.vue` links to the new screen from the authenticated header, and `src/App.spec.ts` asserts the link is present when signed in and absent when not. Without it the screen is unreachable.
- [ ] `composer test` exits `0` with **20** new backend tests and no pre-existing test modified; `composer lint` exits `0`. `npm run test` exits `0` with **15** new frontend tests (38 total); `npm run lint`, `npm run typecheck` and `npm run format:check` all exit `0`.
- [ ] No new dependency on either side; `backend/app/Models/`, `backend/app/Policies/`, `backend/bootstrap/app.php`, `backend/config/`, `backend/database/`, `phpunit.xml`, `frontend/src/api/client.ts`, `frontend/src/api/errors.ts`, `frontend/src/stores/`, `docker-compose.yml`, `docs/erd.md` and `CLAUDE.md` untouched.
- [ ] **`00-overview.md` records four things:** the TM-13 ordering decision actually taken, the correction to TM-13's note about `UserPolicy::view`, the `TransientToken` hazard still latent in `LogoutController`, and that **admin-initiated password reset remains owned by no story**.

**This is the last story in the authentication-agent feature. Report to the user and confirm before moving on to `categories-priorities-statuses` (TM-16).**
