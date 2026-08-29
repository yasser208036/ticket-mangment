# Story 55 — Security hardening pass (Story: TM-64)

## Prerequisites

- None. Independent of Stories 50–54. Touches `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php`, four new backend test files, `frontend/eslint.config.js`, and `.github/workflows/ci.yml`.
- **Five of the six ACs are already true, verified by direct audit on 2026-08-28, not assumed.** Read `## What already exists — audit before you write` first — the real work in this story is one gap (AC1) plus turning four one-time verifications into permanent, CI-enforced tripwires so they cannot regress silently. This is not a from-scratch security review; treating it as one would rebuild protections this codebase already has.

---

## What already exists — audit before you write

| AC | Status | Evidence |
|---|---|---|
| **AC1** — login and all write endpoints rate limited | **False. Only 2 of 16 write/state-changing endpoints are limited.** `auth.login` (`throttle:login`) and `auth.password` (`throttle:password`) are the only routes with a `throttle:` middleware in `backend/routes/api.php`. **14 routes have none**: `auth.logout`, `categories.store/update/destroy`, `tickets.store/notes/assign/claim/escalate/status/update/destroy`, `admin.users.store/update`. | `backend/routes/api.php:31–63`, measured by `grep -n "throttle" routes/api.php` |
| **AC2** — CORS restricted to an explicit allowlist, never a wildcard | **Already true.** `backend/config/cors.php:22–25` — `allowed_origins` is `array_filter([env('FRONTEND_URL', ...), 'http://127.0.0.1:5173'])`, no `'*'` anywhere in the file. `supports_credentials: false` (**35**). **Never verified by a test**, so nothing stops a future edit from weakening it. | `backend/config/cors.php` |
| **AC3** — every model defines fillable fields | **Already true. 8 of 8 app models.** `Category`, `Priority`, `Requester`, `Status`, `StatusTransition`, `Ticket`, `TicketActivity`, `User` all carry `#[Fillable([...])]`. **Never verified by a test** — a new model added without the attribute would mass-assign silently. | `grep -n "^class\|#\[Fillable" app/Models/*.php` — all 8 files |
| **AC4** — output escaped, XSS checked in timeline/ticket views | **Already true.** `grep -rn "v-html" frontend/src/` → **no output**. Every activity/note value renders through Vue's default `{{ }}` interpolation, which HTML-escapes by construction. `docs/api-contract.md:205–207,245–247` already documents this as deliberate. **Not enforced by lint** — `frontend/eslint.config.js` uses `pluginVue.configs['flat/essential']`, and `vue/no-v-html` is **not** part of that preset (it is an opt-in rule in `eslint-plugin-vue`), so a future `v-html` would pass CI silently today. | `frontend/eslint.config.js:9–17`; `grep -rn v-html frontend/src/` |
| **AC5** — no query concatenates user input; filters/sorts use bindings or a whitelist | **Already true.** `TicketSearch.php`'s `whereFullText()` and both `orderByRaw()` calls (**37, 40**) bind `$raw`/`$boolean` via `?` placeholders, never string-interpolate them; the `LIKE` pattern is built with `addcslashes($raw, '%_\\')` before being passed as a bound `where()` value. `IndexTicketRequest::SORTS` (**14**) plus `Rule::in(array_keys(self::SORTS))` (**44**) whitelists `sort`; `direction` is `Rule::in(['asc','desc'])` (**45**). The only other raw-SQL call sites (`TicketStats.php:19–21`, `AgentWorkload.php:33`) are **static strings with no interpolated variable**. **Never enforced** — nothing stops a future raw-SQL call from concatenating user input. | `app/Services/TicketSearch.php`, `app/Http/Requests/Api/V1/IndexTicketRequest.php:14,44–45`, `grep -rn "whereRaw\|DB::raw\|selectRaw\|orderByRaw" app/` |
| **AC6** — no secret/token/credential anywhere in repository history | **Already true, and trivially so.** `git log --oneline` → **one commit** (`1fed355`, "Scaffold the monorepo skeleton"). `git ls-files \| grep -E "\.env$\|\.env\."` → only `backend/.env.example` and `tools/jira/.jira.env.example`; no real `.env` was ever committed. `.gitignore` (root and `backend/`) excludes `.env`, `.env.*` except `.env.example`. A grep for hardcoded secrets across `app/`, `config/`, `routes/`, `frontend/src/`, `.github/` found only stock Laravel `env('AWS_SECRET_ACCESS_KEY')`-style lookups in unused default config files — no literal credential anywhere. **No automated scan exists** to keep this true once real commits start landing. | `git log`, `git ls-files`, measured this session |

**The honest shape of this story: fix the one real gap (rate limiting), and convert the other five already-true findings into tests or lint rules that fail the build the moment any of them stops being true — because a security property that was checked once by an agent in a chat session and never again is not hardened, it is a snapshot.**

---

## Decision — one new named rate limiter, `write`, applied to all 14 currently-unprotected write routes, including `logout`

A single `RateLimiter::for('write', ...)` covers every route that isn't `login`/`password` (both already have purpose-built limiters with different, appropriate rates — 5/min by IP for login, 6/min by user for password — and keep them). **`auth.logout` is included even though its abuse value is near zero** (it can only revoke the caller's own current token) — AC1 says "all write endpoints," without carving out an exception, and the marginal cost of throttling it is negligible.

**Rate and key**, matching the existing `password` limiter's idiom exactly (`AppServiceProvider.php:26–27`):

```php
RateLimiter::for('write', fn (Request $request) => Limit::perMinute(60)
    ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
```

**60/minute per authenticated user (or IP if somehow unauthenticated) is safe against the existing and planned test suite.** Verified: Laravel's `Illuminate\Foundation\Testing\TestCase` boots a fresh application — and therefore a fresh `CACHE_STORE=array` instance — per test method (and per `#[DataProvider]` row, since PHPUnit runs each data row through the full `setUp()`/`tearDown()` lifecycle), so rate-limit state **never leaks between test methods**. Within a single method, the heaviest existing write-route loop is `RouteAuthorizationTest::test_agent_reaches_staff_write_routes` (3 routes, one call each) — nowhere near 60. Story 51's planned `TicketWorkflowTest` (42 `#[DataProvider]` cases) each get a fresh app instance, so the limiter resets every case regardless.

## Decision — the other five ACs get a tripwire test each, not a rewrite

Each of AC2–AC6 is already satisfied by existing code. Rewriting `cors.php`, every model, `TicketSearch.php`, or `.gitignore` would be churn with no behavioural change. Instead, each gets **one new automated check that fails the moment the property stops holding** — the same shape already established across this codebase: `RouteAuthorizationTest::test_every_api_route_is_classified`, `ActivityCoverageTest` (Story 51's plan), `ApiContractCoverageTest` (Story 53's plan). This story adds the security-domain instances of that same pattern.

## Decision — AC6's automated check is `gitleaks`, not a hand-rolled regex

No secret-scanning tool exists in this repo today (`grep -rli "gitleaks\|trufflehog" .github/` → no output). A hand-rolled grep for "things that look like secrets" is exactly the kind of check that gives false confidence — it catches the patterns its author thought of and nothing else. `gitleaks` is the established tool for this exact job, ships a maintained default ruleset (AWS keys, private keys, generic high-entropy tokens, and dozens more), and its official GitHub Action needs no repo-side configuration to start. **This is the one place in the epic where a new external dependency is justified** — every other story in this epic (TM-59 through TM-63) deliberately avoided new tooling; this one is specifically about the class of risk that tooling exists to catch, and the whole repo's history is one commit, so the first scan is instant.

---

## Context — Read These Files First

1. `backend/routes/api.php` — **67 lines, all of it.** Every route named in the AC1 gap table above; task 1 adds `->middleware('throttle:write')` to 14 of them, matching the existing `->middleware('throttle:login')` (**32**) / `->middleware('throttle:password')` (**39**) syntax exactly.
2. `backend/app/Providers/AppServiceProvider.php` — **29 lines, all of it.** `boot()` (**23–28**) is where task 1 adds the third `RateLimiter::for()` call, alongside `login` and `password`.
3. `backend/config/cors.php` — **37 lines, all of it.** `allowed_origins` (**22–25**), `supports_credentials` (**35**) — task 2's test asserts both.
4. `backend/app/Models/*.php` — **8 files.** Every one already confirmed to carry `#[Fillable]`; task 3's test iterates this directory rather than hardcoding the 8 names, so a 9th model is covered automatically.
5. `backend/app/Services/TicketSearch.php` — **43 lines, all of it.** The three binding sites task 4's allowlist test names explicitly: `whereFullText()` (**33**), two `orderByRaw()` calls (**37, 40**).
6. `backend/app/Services/TicketStats.php:19–21` and `backend/app/Services/AgentWorkload.php:33` — the other two raw-SQL call sites, both static strings, both belong in task 4's allowlist.
7. `frontend/eslint.config.js` — **17 lines, all of it.** `pluginVue.configs['flat/essential']` (**14**) — task 5 adds an explicit `rules` block; `vue/no-v-html` is not part of this preset (confirmed against `eslint-plugin-vue`'s rule categorization — it is an opt-in, uncategorized rule).
8. `.github/workflows/ci.yml` — **89 lines, all of it.** Task 6 adds a new top-level job, sibling to `backend`/`frontend` (**13, 62**), not a step inside either.
9. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php:19–34` — the completeness-tripwire precedent every new test in this story follows: iterate a real source of truth (`Route::getRoutes()`, `app/Models/*.php`, a grep result) and assert coverage, rather than hand-listing expectations that drift.
10. `backend/tests/Feature/Auth/PasswordThrottleTest.php` — **38 lines.** The pattern for proving a rate limiter is real, not just declared: exhaust it, assert `429` and a `Retry-After` header, and use `Auth::forgetGuards()` between differently-authenticated calls (task 1's behavioural test copies this shape for the new `write` limiter).
11. `backend/tests/Unit/Services/TicketSearchTest.php` — existing coverage for `TicketSearch`'s query-building logic; audited, not edited, by this story.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `POST /api/v1/auth/logout` and 13 other write routes | Untested, unlimited request rate | 429 after 60 requests/minute per user (or IP) |
| `POST/PATCH /api/v1/auth/{login,password}` | Already limited | Unchanged |
| CORS allowlist weakening (e.g. someone adds `'*'`) | Nothing notices | A feature test fails |
| A new Eloquent model with no `#[Fillable]` | Nothing notices | A feature test fails, naming the model |
| A new raw-SQL call site (`whereRaw`/`DB::raw`/`selectRaw`/`orderByRaw`) | Nothing notices | A feature test fails unless the call site is deliberately added to the allowlist |
| A future `v-html` in any `.vue` file | Passes lint and CI today | `npm run lint` fails |
| A secret accidentally committed | Nothing notices until manually caught | `gitleaks` CI job fails the PR |

---

## Implementation tasks

### 1 — Rate limit every write endpoint (AC1)

**File: `backend/app/Providers/AppServiceProvider.php`** — add a third limiter in `boot()`, after the existing two (**27**):

```php
RateLimiter::for('write', fn (Request $request) => Limit::perMinute(60)
    ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));
```

**File: `backend/routes/api.php`** — add `->middleware('throttle:write')` to these 14 route definitions (chain it the same way `throttle:login`/`throttle:password` are chained on **32, 38–39**):

```
Line 36: Route::post('/auth/logout', LogoutController::class)->middleware('throttle:write')->name('auth.logout');
Line 41: Route::post('/categories', [CategoryController::class, 'store'])->middleware('throttle:write')->name('categories.store');
Line 43: Route::patch('/categories/{category}', [CategoryController::class, 'update'])->middleware('throttle:write')->name('categories.update');
Line 44: Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('throttle:write')->name('categories.destroy');
Line 49: Route::post('/tickets', [TicketController::class, 'store'])->middleware('throttle:write')->name('tickets.store');
Line 52: Route::post('/tickets/{ticket}/notes', TicketNoteController::class)->middleware('throttle:write')->name('tickets.notes');
Line 53: Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->middleware('throttle:write')->name('tickets.assign');
Line 54: Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claim'])->middleware('throttle:write')->name('tickets.claim');
Line 55: Route::post('/tickets/{ticket}/escalate', [TicketController::class, 'escalate'])->middleware('throttle:write')->name('tickets.escalate');
Line 56: Route::post('/tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->middleware('throttle:write')->name('tickets.status');
Line 57: Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->middleware('throttle:write')->name('tickets.update');
Line 58: Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->middleware('throttle:write')->name('tickets.destroy');
Line 61: Route::post('/users', [UserController::class, 'store'])->middleware('throttle:write')->name('users.store');
Line 63: Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('throttle:write')->name('users.update');
```

**Create file: `backend/tests/Feature/Security/RateLimitCoverageTest.php`**

Two tests:
1. `test_every_write_route_is_rate_limited` — iterate `Route::getRoutes()`, filter to `api/v1` routes whose method is `POST`/`PATCH`/`DELETE`, assert each one's `gatherMiddleware()` contains a `throttle:` entry (`login`, `password`, or `write`). Model this on `RouteAuthorizationTest::test_every_api_route_is_classified` (**19–26**).
2. `test_the_write_limiter_actually_returns_429` — as an admin, call `POST /api/v1/categories` 61 times with valid, varying payloads (vary `name`/`slug` per call so validation never intervenes before the limiter does); assert the 61st response is `429` with a `Retry-After` header, following `PasswordThrottleTest`'s pattern (`tests/Feature/Auth/PasswordThrottleTest.php:26`).

### 2 — CORS allowlist test (AC2)

**Create file: `backend/tests/Feature/Security/CorsAllowlistTest.php`**

```php
public function test_config_never_allows_a_wildcard_origin(): void
{
    $this->assertNotContains('*', config('cors.allowed_origins'));
    $this->assertFalse(config('cors.supports_credentials'));
}

public function test_the_configured_frontend_origin_is_allowed_and_an_arbitrary_one_is_not(): void
{
    $allowed = config('cors.allowed_origins')[0];
    $this->withHeaders(['Origin' => $allowed])->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', $allowed);
    $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/v1/health');
    $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
}
```

### 3 — Model fillable-field completeness (AC3)

**Create file: `backend/tests/Feature/Security/ModelFillableCoverageTest.php`**

```php
public function test_every_eloquent_model_declares_fillable_fields(): void
{
    foreach (glob(app_path('Models/*.php')) as $path) {
        $class = 'App\\Models\\'.basename($path, '.php');
        if (! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
            continue;
        }
        $reflection = new \ReflectionClass($class);
        $hasFillableAttribute = $reflection->getAttributes(\Illuminate\Database\Eloquent\Attributes\Fillable::class) !== [];
        $model = $reflection->newInstanceWithoutConstructor();
        $this->assertTrue(
            $hasFillableAttribute || $model->getGuarded() === ['*'],
            "{$class} defines neither #[Fillable] nor \$guarded = ['*'] — every field is mass-assignable.",
        );
    }
}
```

`glob(app_path('Models/*.php'))` walks the real directory, so a 9th model added later is covered with no test edit — the same completeness shape as every other tripwire in this epic.

### 4 — Raw-SQL call-site allowlist (AC5)

**Create file: `backend/tests/Feature/Security/RawSqlAllowlistTest.php`**

```php
private const ALLOWLIST = [
    'app/Services/TicketSearch.php:37',
    'app/Services/TicketSearch.php:40',
    'app/Services/TicketStats.php:19',
    'app/Services/TicketStats.php:20',
    'app/Services/TicketStats.php:21',
    'app/Services/AgentWorkload.php:33',
];

public function test_every_raw_sql_call_site_is_reviewed_and_allowlisted(): void
{
    $found = [];
    foreach (\Symfony\Component\Finder\Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        foreach (file($file->getPathname()) as $lineNumber => $line) {
            if (preg_match('/\b(whereRaw|DB::raw|selectRaw|orderByRaw)\(/', $line)) {
                $found[] = 'app/'.$file->getRelativePathname().':'.($lineNumber + 1);
            }
        }
    }
    sort($found);
    $expected = self::ALLOWLIST;
    sort($expected);
    $this->assertSame($expected, $found, 'A raw-SQL call site was added or removed. Review it for string-concatenated user input, then update RawSqlAllowlistTest::ALLOWLIST.');
}
```

This is deliberately a **full-match** assertion, not "contains" — a removed call site must also update the allowlist, so the list never silently grows stale in either direction.

### 5 — Enforce no-`v-html` at lint time (AC4)

**File: `frontend/eslint.config.js`** — add a `rules` block to the first config object (**9–12**):

```diff
 {
   name: 'app/files-to-lint',
   files: ['**/*.{ts,mts,tsx,vue}'],
+  rules: {
+    'vue/no-v-html': 'error',
+  },
 },
```

`npm run lint` already runs with `--max-warnings 0` (`package.json:13`) and is already a required CI step (`ci.yml:79`) — no CI change needed, only the rule.

### 6 — Secret-scanning CI job (AC6)

**File: `.github/workflows/ci.yml`** — new top-level job, sibling to `backend` and `frontend`:

```yaml
  secrets:
    name: Secret scan
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0
      - uses: gitleaks/gitleaks-action@v2
        env:
          GITHUB_TOKEN: ${{ secrets.GITHUB_TOKEN }}
```

`fetch-depth: 0` fetches full history — necessary for the scan to be meaningful, and the entire history is one commit today, so the first run is instant. No `GITLEAKS_LICENSE` is required for the open-source detect-only mode used here.

### No other application changes.

Every other file this story touches is a test or a config addition; no controller, policy, model, or request logic changes.

---

## Edge Cases & Failure Modes

- **The `write` limiter and an existing per-field validation error racing on the same request.** `RateLimitCoverageTest`'s 429-proof test (task 1, test 2) must vary the request payload across all 61 calls (distinct `name`/`slug`) — a validation 422 on attempt 2–60 would mean the limiter was never actually exercised, and the test would prove nothing while still going green.
- **`Auth::forgetGuards()` between differently-authenticated calls in the same test method.** Any new test in this story that authenticates as more than one identity inherits the Sanctum guard-caching trap already diagnosed in Story 51's plan (`PasswordThrottleTest`, `RouteAuthorizationTest:132/134`) — none of this story's new tests currently need two identities in one method, but note it here so a reviewer adding one later does not rediscover the bug.
- **`admin.users.store`/`admin.users.update` now throttled at 60/min could interact with a future bulk-admin-import feature.** Not built yet, and not this story's concern — flagged so the next story that adds bulk user creation knows to request a higher, purpose-built limiter rather than being surprised by 429s.
- **`gitleaks-action` scanning a fork's PR** — `GITHUB_TOKEN` from a fork has restricted permissions; the action's detect-only mode (no PR-comment posting) works within that restriction, but if a future story wants gitleaks to comment on PRs directly, that needs `pull_request_target` and more careful permission scoping — out of scope here.
- **`RawSqlAllowlistTest`'s regex matches inside PHP comments and doc-comments too**, not only executable code — a false positive costs one allowlist entry, never a false negative (missing a real call site), which is the safer failure direction for a security tripwire.
- **The `write` limiter's `by()` key falls back to `$request->ip()` only when `$request->user()` is null** — every one of the 14 newly-throttled routes requires `auth:sanctum` already (`routes/api.php:35`), so in practice the key is always the authenticated user's id; the IP fallback exists for defense-in-depth, not because it is expected to fire.

---

## Test Plan

**Files created:** `backend/tests/Feature/Security/RateLimitCoverageTest.php`, `CorsAllowlistTest.php`, `ModelFillableCoverageTest.php`, `RawSqlAllowlistTest.php`. One new directory: `tests/Feature/Security/`.

**Files edited:** `backend/app/Providers/AppServiceProvider.php` (one `RateLimiter::for` call); `backend/routes/api.php` (14 `->middleware('throttle:write')` additions); `frontend/eslint.config.js` (one rule); `.github/workflows/ci.yml` (one new job).

**Order:**
1. Task 1 first (the one real functional gap) — everything else is additive and order-independent relative to it.
2. Tasks 2–4 in any order — each is an isolated new test file.
3. Task 5 and task 6 last — cheapest, and best verified once the rest of the suite is known-green so their CI runs aren't confused with an unrelated failure.

---

## Verification Steps

1. **Confirm the gap, before task 1.** `grep -c "throttle:" backend/routes/api.php` → **2**.
2. **Task 1, config:** `grep -c "throttle:" backend/routes/api.php` → **16** after the edit.
3. **Task 1, proven both ways:** `php artisan test --filter=RateLimitCoverageTest` → 2 passing. Temporarily remove `->middleware('throttle:write')` from `categories.store`, re-run, confirm `test_every_write_route_is_rate_limited` fails naming it — restore.
4. **Task 2:** `php artisan test --filter=CorsAllowlistTest` → passes. Temporarily add `'*'` to `config/cors.php:22`'s array, re-run, confirm the wildcard test fails — restore.
5. **Task 3:** `php artisan test --filter=ModelFillableCoverageTest` → passes. Temporarily remove `#[Fillable]` from `app/Models/Category.php`, re-run, confirm it fails naming `App\Models\Category` — restore.
6. **Task 4:** `php artisan test --filter=RawSqlAllowlistTest` → passes. Temporarily add a `DB::raw('1')` call anywhere in `app/`, re-run, confirm it fails — restore.
7. **Task 5:** temporarily add `<div v-html="'x'" />` to any `.vue` file, run `npm run lint` from `frontend/` → fails on `vue/no-v-html` — restore, re-run, confirm clean.
8. **Full backend suite:** `composer test` from `backend/` → exits 0, all new tests included, no existing test newly failing (the 60/min limiter must not trip any existing test — this is the step that proves the safety argument in the rate-limit decision above, not just asserts it).
9. **`./vendor/bin/pint --test`** → clean.
10. **Task 6, locally if possible:** run `gitleaks detect --source . -v` (install via `brew install gitleaks` or the release binary) → no findings, matching the audit table's claim.
11. **CI:** push the branch; confirm all three jobs (`backend`, `frontend`, `secrets`) pass.
12. **Regression:** `git status` shows changes only in the files listed under Test Plan — no controller, policy, model, or request logic file changed.

---

## Done Criteria

- [ ] **AC1**: all 16 `POST`/`PATCH`/`DELETE` routes under `api/v1` carry a `throttle:` middleware (`login`, `password`, or the new `write`); proven both by the completeness test and by an actual 429 after 61 rapid calls.
- [ ] **AC2**: `config('cors.allowed_origins')` is asserted to never contain `'*'` and `supports_credentials` is asserted `false`; a disallowed `Origin` header is proven not to receive a matching `Access-Control-Allow-Origin`.
- [ ] **AC3**: every class file under `app/Models/` is asserted to declare `#[Fillable]` or `$guarded = ['*']`, walking the real directory so a future model is covered automatically.
- [ ] **AC4**: `vue/no-v-html` is enabled as an ESLint error, enforced by the existing required `npm run lint` CI step — no new CI step needed.
- [ ] **AC5**: every `whereRaw`/`DB::raw`/`selectRaw`/`orderByRaw` call site in `app/` is enumerated by an exact-match allowlist test; a new or removed call site fails the build until the allowlist is deliberately updated.
- [ ] **AC6**: a `gitleaks` CI job scans full repository history on every push/PR; the PR states the current, verified finding — one commit, no secrets, `.gitignore` correctly excludes every `.env` variant except `.env.example`.
- [ ] The 60/min `write` limit is proven not to break any existing or Story 51-planned test, via a full `composer test` run showing no new failures.
- [ ] `pint --test` and `npm run lint` both clean.
- [ ] No controller, policy, model, or request **logic** file is changed — only route middleware chains, the service provider's `boot()`, new test files, one ESLint rule, and one new CI job.

**STOP HERE. Report to the user and wait for confirmation — this is the last story in epic E9.**
