# Story 02 — Install and configure the Laravel 13 API (Story: TM-3)

## Prerequisites

- **Story 01 (TM-2) completed:** [`01-story-scaffold-monorepo-skeleton-TM-2.md`](01-story-scaffold-monorepo-skeleton-TM-2.md). The repository is under version control (commit `1fed355`, branch `main`) and `docs/api-contract.md` exists with an empty `## Endpoints` table waiting for this story's first row.
- **Docker services must be running** before any migration or test command: from the repo root, `docker compose up -d` then `docker compose ps` until `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all report `healthy`. `php artisan migrate` and `composer test` both fail without them.
- **`sudo` access is required for task 1.** The `pdo_mysql` PHP extension is a system package, not a Composer dependency. If the executor cannot install system packages, stop after task 1 and report — tasks 3–8 can be written but **cannot be verified**, because every acceptance criterion below depends on a working MySQL driver.
- No coordination with other story owners is needed. The files this story touches (`backend/composer.json`, `backend/config/app.php`, `backend/.env.example`, `backend/phpunit.xml`, `backend/app/Http/Controllers/Api/V1/HealthController.php`, `backend/tests/Feature/`, `docs/api-contract.md`, root `README.md`) are not in flight anywhere else. TM-4 (Vue SPA) is the next consumer of `/api/v1/health`.

---

## Story Goal

Make the Laravel 13 API **provably** talk to MySQL 8, and make `GET /api/v1/health` the endpoint that proves it — returning `200` with the app version and the database connectivity status.

Read this carefully before starting: **most of the file-level scaffolding for this story already landed in the TM-2 commit.** Laravel 13.17 is installed, `vendor/` is present, PHP is 8.3.6, the `api/v1` prefix is configured, `HealthController` exists, and `phpunit.xml` already points at the test container on port 3307. What is *not* true is the part the acceptance criteria actually ask for: **the endpoint returns `503`, not `200`, and `php artisan migrate` cannot run at all.**

Four user-visible outcomes:

1. `php artisan migrate` completes on the MySQL 8 container and reports every migration as `DONE`.
2. `GET /api/v1/health` returns **`200`** with `status: "ok"`, the **app version** (not the API version), and `checks.database.ok: true`.
3. `composer test` runs — it currently exits `1` before reaching a single test — and includes a feature test that asserts the health contract and, through `RefreshDatabase`, proves the migrations run clean on real MySQL.
4. `docs/api-contract.md` records `GET /api/v1/health` as the first entry in its `## Endpoints` table.

**Not in scope:** any new migration or model (the users/roles schema is TM-8, master data is TM-16, tickets are TM-21); the admin seeder (TM-8); queue and mail wiring beyond the config that already exists (TM-51); a CI pipeline (TM-6); the Vue SPA's use of this endpoint (TM-4); rewriting `backend/README.md`, which is still the stock Laravel readme (TM-62 owns API documentation). Do **not** add authentication to `/api/v1/health` — it is deliberately unauthenticated so a load balancer can reach it.

---

## Context — Read These Files First

1. `backend/bootstrap/app.php` — all 25 lines. Confirm `apiPrefix: 'api/v1'` on **line 16** and the comment above it on lines 14–15. There is no `app/Http/Kernel.php` in this skeleton; routing, middleware and exception rendering are all configured here. Note `shouldRenderJsonWhen` on lines 22–24 — it matches `api/*`, so `api/v1/*` errors already render as JSON and the health controller needs no try/catch for the framework's benefit.
2. `backend/routes/api.php` — all 18 lines. The health route is registered on **line 18** as `Route::get('/health', HealthController::class)->name('health')`. The path is `/health`, **not** `/api/v1/health` — the prefix comes from `bootstrap/app.php`. Read the header comment (lines 6–14) before adding anything here.
3. `backend/app/Http/Controllers/Api/V1/HealthController.php` — all 55 lines. This is the file task 3 edits. Note specifically:
   - **Line 31: `'version' => 'v1'`.** This is the *API* version hard-coded as a string. The acceptance criterion asks for the **app version**. This is the functional gap.
   - Lines 21–23 build the `$checks` array with a single `database` probe using `DB::connection()->getPdo()`.
   - Lines 41–54, `private function check()` — the probe wrapper. **Line 51 gates the error message behind `config('app.debug')`**; host names and driver strings otherwise reach unauthenticated callers. Preserve that behaviour exactly.
4. `backend/config/app.php` — read lines 1–56. `'name'` is on **line 16**, `'env'` on 29, `'debug'` on 42, `'url'` on 55. **There is no `version` key** — `grep -in version backend/config/app.php` returns nothing. Task 3 adds one.
5. `backend/composer.json` — all 87 lines. `require` is lines 8–13 (`"php": "^8.3"`, `"laravel/framework": "^13.17"`, `"laravel/sanctum": "^4.0"`, `"laravel/tinker": "^3.0"`). **Line 49 is broken:** `"@php artisan config:clear --ansi @no_additional_args"`. Read the whole `scripts` block (lines 35–69) before editing; `setup` on lines 36–43 runs `migrate --force`.
6. `backend/phpunit.xml` — all 48 lines. Read the comment on lines 27–35, which explains why the suite targets real MySQL rather than SQLite. The test connection is lines 36–40: `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=ticket_management_test`, `DB_URL=""`. **`DB_USERNAME` and `DB_PASSWORD` are absent** — they fall through to `backend/.env`, which is git-ignored. Task 4 closes that hole.
7. `backend/.env.example` — all 70 lines. The variables the app actually reads: `APP_*` (1–5), `DB_*` (21–26), `MAIL_*` (50–57), `FRONTEND_URL` (60), `SANCTUM_STATEFUL_DOMAINS` (61). The variables **nothing reads yet**: `DB_TEST_HOST`/`DB_TEST_PORT`/`DB_TEST_DATABASE` (30–32), `ADMIN_NAME`/`ADMIN_EMAIL`/`ADMIN_PASSWORD` (64–66), `TICKETS_STALE_AFTER_HOURS` (70). Verify with `grep -rn "ADMIN_NAME\|TICKETS_STALE_AFTER_HOURS\|DB_TEST_" backend/app backend/config backend/database backend/routes` — it returns **nothing**. Task 6 annotates them.
8. `backend/config/database.php` — read the `mysql` block, **lines 47–65**. `charset` and `collation` default to `utf8mb4` / `utf8mb4_unicode_ci` (lines 56–57), matching the container's server flags. Note **line 62**: `'options' => extension_loaded('pdo_mysql') ? … : []`. This silently tolerates a missing driver at config time, which is exactly why the failure only surfaces as `could not find driver` when a query runs.
9. `docker-compose.yml` (repo root) — read the `mysql` block (lines 10–32) and `mysql-test` (lines 36–57). The app container's credentials default to `ticket_user` / `secret` (lines 16–17); the test container uses the **same** user and password (lines 42–43) with a fixed database name `ticket_management_test` (line 41). These defaults are what task 4 writes into `phpunit.xml`. Note that Compose reads variables from a **root** `.env` that does not exist, so every `${…:-default}` resolves to its default.
10. `docs/api-contract.md` — all 18 lines. The blockquote on lines 3–4 names TM-3 as the owner of the first entry. The `## Endpoints` table is lines 16–18 with a single `_(none yet)_` placeholder row. Task 7 replaces that row.
11. `README.md` (repo root) — read the Prerequisites table, **lines 24–30**, and the Tests section, lines 92–97. Task 8 adds one row to that table.
12. `backend/tests/` — three files only: `tests/TestCase.php` (10 lines, empty base class), `tests/Feature/ExampleTest.php` (18 lines, asserts `GET /` returns 200 — the stock Blade welcome view), `tests/Unit/ExampleTest.php` (16 lines, `assertTrue(true)`). **There is no `HealthTest`**, despite `CLAUDE.md` documenting `php artisan test --filter=HealthTest`. Task 5 creates it.

No attachments — the intake lists `None.`

---

## Current state — audit before you change anything

Run these four commands first and confirm each result matches the table. They are the measurements this plan was written against; if any has changed, re-read the relevant file before editing it.

```bash
php -r 'echo implode(",", PDO::getAvailableDrivers()), PHP_EOL;'   # → pgsql
cd backend && php artisan migrate:status                           # → QueryException: could not find driver
cd backend && composer test                                        # → error code 1, no tests run
cd backend && php artisan test                                     # → passed, tests: 2
```

| Acceptance criterion | Current state | Verdict |
|---|---|---|
| Laravel 13 installed via composer, PHP 8.3+ | `laravel/framework ^13.17` in `composer.json:10`; PHP 8.3.6; `backend/vendor/autoload.php` present | ✅ already met — verify only |
| `.env.example` documents every variable, no real secrets | 70 lines, no live credentials — but **four blocks document variables no code reads** | ⚠️ task 6 |
| DB points at MySQL 8 and `php artisan migrate` runs clean | Config is correct; **`pdo_mysql` is not installed**, so migrate throws `could not find driver` | ❌ task 1 |
| `GET /api/v1/health` returns 200 with **app version** + DB status | Route resolves, but returns **`503 degraded`** with `"error":"could not find driver"`, and `"version":"v1"` is the *API* version | ❌ tasks 1 and 3 |
| API routes versioned under `/api/v1` from the first route | `apiPrefix: 'api/v1'` at `bootstrap/app.php:16`; `php artisan route:list` shows `GET|HEAD api/v1/health` | ✅ already met — verify only |

For the record, the exact current response body:

```json
{"status":"degraded","app":"Ticket Management","environment":"local","version":"v1","time":"2026-08-25T10:01:32+00:00","checks":{"database":{"ok":false,"error":"could not find driver"}}}
```

---

## Implementation tasks

**No frontend changes required.** `frontend/` is untouched by this story; wiring the SPA to this endpoint is TM-4.

### 1 — Install the `pdo_mysql` extension and make its absence a hard failure

This is the blocking task. `php -m` lists `PDO` and `pdo_pgsql` but **not** `pdo_mysql`; `PDO::getAvailableDrivers()` returns only `pgsql`. Every MySQL query therefore fails with `could not find driver`.

Install the system package (Ubuntu 24.04, PHP 8.3 from the distro repo — `apt-cache policy php8.3-mysql` reports candidate `8.3.6-0ubuntu0.24.04.10`):

```bash
sudo apt-get update
sudo apt-get install -y php8.3-mysql
```

Confirm before moving on — do not proceed on the assumption that apt succeeded:

```bash
php -m | grep -x pdo_mysql
php -r 'echo implode(",", PDO::getAvailableDrivers()), PHP_EOL;'   # must now include mysql
```

The package drops `mysqlnd.ini` and `pdo_mysql.ini` into `/etc/php/8.3/mods-available/` and symlinks them under `/etc/php/8.3/cli/conf.d/`. The CLI picks them up on the next invocation — **no service restart is needed for `php artisan`**. If this project is ever served through php-fpm or Apache, that SAPI needs its own restart; that belongs to TM-63 (deployment runbook), not here.

**File: `backend/composer.json`**

Then make the extension an explicit, enforced dependency so the next developer gets a clear Composer error instead of a runtime `QueryException`. Add `ext-pdo_mysql` to `require` (lines 8–13), keeping the block alphabetically sorted as `"sort-packages": true` (line 79) implies:

```json
    "require": {
        "php": "^8.3",
        "ext-pdo_mysql": "*",
        "laravel/framework": "^13.17",
        "laravel/sanctum": "^4.0",
        "laravel/tinker": "^3.0"
    },
```

Adding a platform requirement invalidates the lock file's content hash, so refresh it without changing any resolved version:

```bash
cd backend
composer update --lock
composer validate --no-check-publish   # must report the manifest is valid
```

**Install the extension before adding the requirement.** In the other order, `composer update --lock` fails on the very platform requirement you just added and you cannot refresh the hash.

### 2 — Fix the `composer test` script

**File: `backend/composer.json`**

`composer test` currently fails before running a single test:

```
No arguments expected for "config:clear" command, got "@no_additional_args".
Script @php artisan config:clear --ansi @no_additional_args handling the test event returned with error code 1
```

The `@no_additional_args` token on **line 49** is not understood by the Composer on this machine (2.7.1), so it is passed through to Artisan as a literal argument and `config:clear` rejects it. Drop the token:

```json
        "test": [
            "@php artisan config:clear --ansi",
            "@php artisan test"
        ],
```

Do **not** "fix" this by requiring a newer Composer, and do **not** remove the `config:clear` step — a stale `bootstrap/cache/config.php` would silently override the `phpunit.xml` test-database settings and the suite would run against the development database on port 3306.

This is the command `README.md:94` and `CLAUDE.md` both tell developers to use, so it must work.

### 3 — Report the real app version in the health payload

**File: `backend/config/app.php`**

Add a `version` key immediately after `'name'` (line 16), matching the surrounding comment-block style:

```php
    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Version
    |--------------------------------------------------------------------------
    |
    | Reported by GET /api/v1/health so a deploy can confirm which build is
    | actually serving traffic. Set APP_VERSION from the release tag in CI;
    | the fallback is the current pre-release value.
    |
    */

    'version' => env('APP_VERSION', '0.1.0'),
```

**File: `backend/.env.example`**

Add the variable directly under `APP_URL` (line 5) so the `APP_*` block stays contiguous:

```dotenv
APP_URL=http://localhost:8000
# Reported by GET /api/v1/health. CI overrides this with the release tag.
APP_VERSION=0.1.0
```

**File: `backend/app/Http/Controllers/Api/V1/HealthController.php`**

Replace the hard-coded `'version' => 'v1'` on **line 31**. Keep the API version in the payload — it is genuinely useful, it is just not what `version` should mean — under its own key:

```php
        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'version' => config('app.version'),
            'api' => 'v1',
            'time' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
```

Changing the meaning of `version` is safe **today**: nothing consumes this endpoint yet. `frontend/src/` is the stock Vite scaffold with no HTTP client wired, and `docs/api-contract.md` has no entries. Task 7 documents the shape so TM-4 codes against the corrected contract rather than the old one. Do not make this change after TM-4 lands.

Leave lines 21–23 (the `$checks` array) and lines 41–54 (`check()`, including the `config('app.debug')` gate on line 51) **exactly as they are**. Do not add further probes — mail and queue health belong to TM-51.

### 4 — Make the test database connection self-contained

**File: `backend/phpunit.xml`**

Lines 36–40 set `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE` and `DB_URL`, but **not** the credentials, so `DB_USERNAME` and `DB_PASSWORD` leak in from `backend/.env` — a git-ignored file that every developer edits. A developer who changes their local `DB_PASSWORD` breaks the whole suite with an authentication error that points nowhere near the cause.

Pin them to the `mysql-test` container's values from `docker-compose.yml:42-43`, inserting after line 39:

```xml
        <env name="DB_DATABASE" value="ticket_management_test"/>
        <env name="DB_USERNAME" value="ticket_user"/>
        <env name="DB_PASSWORD" value="secret"/>
        <env name="DB_URL" value=""/>
```

**This is not a secret.** `secret` is the hard-coded default of a throwaway local container that binds only `127.0.0.1:3307` and holds nothing but data `RefreshDatabase` is about to truncate. Do not move it to an env var — the point of the change is that the suite must not depend on developer-local state.

Leave the comment on lines 27–35 untouched. Do not add `APP_VERSION` here; task 5's test sets it per-test so the assertion cannot silently pass against a matching default.

### 5 — Add the health feature tests

**Delete file: `backend/tests/Feature/ExampleTest.php`**

It asserts that `GET /` returns 200 — the stock Blade welcome view from `routes/web.php:5`. This is a JSON API; a Blade-view assertion is not the suite's first feature test, and it breaks the moment the welcome route goes. `tests/Unit/ExampleTest.php` stays as-is: it touches nothing and removing it is not this story's business.

**Create file: `backend/tests/Feature/HealthTest.php`**

The happy path. `RefreshDatabase` is deliberate — running the migrations against real MySQL 8 on every test run is what turns "migrate runs clean" from a one-off manual check into a regression test.

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_ok_when_the_database_is_reachable(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonMissingPath('checks.database.error')
            ->assertJsonStructure([
                'status',
                'app',
                'environment',
                'version',
                'api',
                'time',
                'checks' => ['database' => ['ok']],
            ]);
    }

    public function test_it_reports_the_configured_app_version(): void
    {
        config()->set('app.version', '9.9.9-test');

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('version', '9.9.9-test')
            ->assertJsonPath('api', 'v1');
    }

    public function test_the_route_is_versioned_under_api_v1(): void
    {
        $this->assertSame('/api/v1/health', route('health', absolute: false));

        // The prefix comes from bootstrap/app.php, so an unversioned path must 404.
        $this->getJson('/api/health')->assertNotFound();
    }
}
```

**Create file: `backend/tests/Feature/HealthDegradedTest.php`**

The failure path, in its own class **without** `RefreshDatabase`. This matters: the test points the default connection at a dead port and calls `DB::purge()`, which would destroy the transaction `RefreshDatabase` relies on for rollback. With `SESSION_DRIVER=array`, `CACHE_STORE=array` and `QUEUE_CONNECTION=sync` set in `phpunit.xml` (lines 25, 43–44), a class without `RefreshDatabase` never opens a database connection at all, so purging is safe.

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthDegradedTest extends TestCase
{
    public function test_it_returns_503_and_hides_the_reason_when_debug_is_off(): void
    {
        $this->breakTheDatabaseConnection();
        config()->set('app.debug', false);

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database.ok', false)
            // Host names and driver strings must not reach an unauthenticated caller.
            ->assertJsonPath('checks.database.error', 'unavailable');
    }

    public function test_it_surfaces_the_probe_error_when_debug_is_on(): void
    {
        $this->breakTheDatabaseConnection();
        config()->set('app.debug', true);

        $response = $this->getJson('/api/v1/health')->assertStatus(503);

        $this->assertNotSame('unavailable', $response->json('checks.database.error'));
    }

    /**
     * Port 1 on loopback is closed, so the connection is refused immediately
     * rather than hanging until a TCP timeout.
     */
    private function breakTheDatabaseConnection(): void
    {
        config()->set('database.connections.mysql.host', '127.0.0.1');
        config()->set('database.connections.mysql.port', 1);
        DB::purge('mysql');
    }
}
```

Match the existing test idiom: `snake_case` method names prefixed `test_`, no PHPUnit attributes — see `tests/Unit/ExampleTest.php:12` and the deleted `tests/Feature/ExampleTest.php:13`.

### 6 — Annotate the variables `.env.example` documents but no code reads

**File: `backend/.env.example`**

The acceptance criterion is "documents every variable the app needs, with no real secrets". There are no secrets — `secret` and `password` are container defaults — but four blocks promise behaviour that does not exist, which is worse than an undocumented variable: a developer sets `ADMIN_EMAIL` and reasonably expects an admin account.

Do **not delete** these blocks; they are the agreed contract for the stories that will consume them. Name the owning story in each comment so the gap is visible rather than misleading.

Lines 28–32 — clarify that `phpunit.xml` hard-codes these values and these entries are documentation of the container, not configuration the suite reads:

```dotenv
# The second MySQL container, for the test suite. These values are *documentation*:
# phpunit.xml sets the test connection itself (see its DB_* env entries) so the
# suite never depends on this file. docker-compose.yml reads DB_TEST_PORT from a
# root .env, not from this one.
DB_TEST_HOST=127.0.0.1
DB_TEST_PORT=3307
DB_TEST_DATABASE=ticket_management_test
```

Lines 63–66 — attribute the seeder to TM-8:

```dotenv
# Credentials for the admin created by DatabaseSeeder (TM-8 — not implemented yet;
# the seeder currently creates a factory user). Change before any real use.
```

Lines 68–70 — attribute the scheduled command to TM-43:

```dotenv
# A ticket with no activity for this many hours is flagged stale by
# `php artisan tickets:flag-stale` (TM-43 — command not implemented yet).
```

Then confirm nothing the app *does* read is missing. Every `env()` call in `backend/config/` must have a counterpart here:

```bash
cd backend
grep -rhoP "env\('\K[A-Z0-9_]+" config/ | sort -u > /tmp/config-vars.txt
grep -oP '^\K[A-Z0-9_]+(?==)' .env.example | sort -u > /tmp/example-vars.txt
comm -23 /tmp/config-vars.txt /tmp/example-vars.txt
```

The third column of that `comm` lists variables the config reads but the example does not document. Laravel's own optional knobs (`DB_SOCKET`, `MYSQL_ATTR_SSL_CA`, `REDIS_*`, and the like) are fine to leave undocumented — they have working defaults in `config/database.php` and this project does not use them. Add an entry only for a variable this project genuinely needs and the reader could not guess. **`APP_VERSION` must appear in both lists** after task 3.

### 7 — Record the endpoint in the API contract

**File: `docs/api-contract.md`**

Replace the `_(none yet)_` placeholder row (line 18) and drop the placeholder blockquote on lines 3–4, which explicitly names this story as its own removal condition. Keep the `## Conventions` section (lines 6–12) unchanged.

````markdown
# API Contract

Every endpoint of the ticket-management API. Paths are absolute; the `/api/v1`
prefix is set in `backend/bootstrap/app.php`, so route definitions in
`backend/routes/api.php` are written relative to it.

## Conventions

- Every route is versioned under `/api/v1` from the first endpoint onward.
- Requests and responses are JSON; errors follow Laravel's default validation
  envelope unless a story states otherwise.
- Authentication uses Laravel Sanctum (`laravel/sanctum ^4.0`, see
  `backend/composer.json`).

## Endpoints

| Method | Path | Purpose | Auth | Owning story |
|--------|------|---------|------|--------------|
| `GET` | `/api/v1/health` | Liveness + readiness. Probes every dependency rather than assuming it. | none | TM-3 |

### `GET /api/v1/health`

Unauthenticated by design — a load balancer or deploy script must be able to
reach it. Returns **`200`** when every check passes and **`503`** when any check
fails, so a non-2xx response is actionable on its own.

`200 OK`

```json
{
  "status": "ok",
  "app": "Ticket Management",
  "environment": "local",
  "version": "0.1.0",
  "api": "v1",
  "time": "2026-08-25T10:01:32+00:00",
  "checks": {
    "database": { "ok": true }
  }
}
```

`503 Service Unavailable`

```json
{
  "status": "degraded",
  "app": "Ticket Management",
  "environment": "local",
  "version": "0.1.0",
  "api": "v1",
  "time": "2026-08-25T10:01:32+00:00",
  "checks": {
    "database": { "ok": false, "error": "unavailable" }
  }
}
```

| Field | Type | Notes |
|---|---|---|
| `status` | string | `ok` or `degraded`. `degraded` iff any entry in `checks` has `ok: false`. |
| `app` | string | `config('app.name')` — `APP_NAME`. |
| `environment` | string | `config('app.env')` — `APP_ENV`. |
| `version` | string | **App** version, `config('app.version')` — `APP_VERSION`. CI sets it from the release tag. |
| `api` | string | API version. Fixed at `v1` for every route under `/api/v1`. |
| `time` | string | ISO-8601 server time. |
| `checks.<name>.ok` | bool | One entry per probed dependency. `database` today; mail and queue arrive with TM-51. |
| `checks.<name>.error` | string | Present **only** when `ok` is `false`. The real driver message when `APP_DEBUG=true`, otherwise the literal `unavailable` — probe messages name hosts and drivers. |
````

### 8 — Add the extension to the documented prerequisites

**File: `README.md`** (repo root)

The PHP row in the Prerequisites table (line 26) says `8.3+` and stops there, which is why a correctly-configured checkout still cannot migrate. Add a row directly beneath it:

```markdown
| Tool | Version | Why |
|---|---|---|
| PHP | 8.3+ | `backend/composer.json` requires `"php": "^8.3"` |
| PHP `pdo_mysql` | matching PHP | the MySQL driver — `php artisan migrate` fails with `could not find driver` without it. Ubuntu/Debian: `sudo apt-get install php8.3-mysql`. Verify with `php -m \| grep pdo_mysql`. |
| Composer | 2.x | installs `laravel/framework ^13.17` |
```

Also extend the Tests section (lines 94–96) with one sentence: the backend suite runs the migrations against MySQL 8 on every run via `RefreshDatabase`, so a broken migration fails `composer test` rather than surfacing on someone's machine later.

Make no other edit to `README.md`. It is TM-2's deliverable and the rest of it is accurate.

---

## Edge Cases & Failure Modes

- **`sudo apt-get install php8.3-mysql` is unavailable** (no sudo, non-Debian host, PHP from a PPA or Homebrew). Every acceptance criterion except "routes are versioned" then remains unverifiable. Stop after task 1 and report — do **not** proceed to write tests that cannot be run, and do **not** work around it by pointing `DB_CONNECTION` at `pgsql`, which is the one driver present. `config/database.php:62` guards `MYSQL_ATTR_SSL_CA` with `extension_loaded('pdo_mysql')`, so a missing driver produces no config-time warning at all — the only symptom is `could not find driver` at query time.
- **Docker not running when tests execute.** `RefreshDatabase` in `HealthTest` migrates against `127.0.0.1:3307`; with `tm-mysql-test` down, every test in that class errors on connection, not on assertion. `HealthDegradedTest` still passes — it deliberately breaks the connection — which makes a half-red suite look like a code bug. Check `docker compose ps` first.
- **A cached config file overrides the test database.** If `bootstrap/cache/config.php` exists (left by `php artisan config:cache`), it wins over `phpunit.xml`'s `<env>` entries and the suite silently truncates the **development** database on port 3306. This is precisely what `config:clear` in the `composer test` script (task 2) prevents — which is why the token fix must keep the step rather than delete the line.
- **`composer update --lock` pulls new versions.** It must not. `--lock` refreshes only the content hash. If `git diff backend/composer.lock` shows changed package versions rather than just the hash, revert and re-run with `--lock`; a framework bump is not this story's change.
- **`DB::purge()` inside a `RefreshDatabase` test.** Discards the connection holding the wrapping transaction, so rollback fails and the test database keeps the mutations. This is why `HealthDegradedTest` is a separate class with no `RefreshDatabase` trait. Do not merge the two classes to "tidy up".
- **The degraded test hangs.** Only if the bogus connection targets a filtered address rather than a closed port. `127.0.0.1:1` is refused immediately; an address like `10.255.255.1` would block until the TCP timeout and make the suite appear stuck. Keep loopback.
- **`assertJsonMissingPath('checks.database.error')` fails on a healthy database.** That means `check()` returned an error key on success — read `HealthController.php:41-54` again; the success branch (line 46) returns `['ok' => true]` with no `error` key, and it must stay that way. The assertion is what keeps the 200 payload from leaking a stale message.
- **`APP_VERSION` unset in production.** `env('APP_VERSION', '0.1.0')` falls back silently, so `/health` reports `0.1.0` for every build and the endpoint stops distinguishing deploys. The fallback is correct for local development; making CI set it is TM-6's job and the comment in `config/app.php` says so.
- **Compose variables have no `.env` to read.** `docker-compose.yml` interpolates `DB_PORT`, `DB_TEST_PORT`, `DB_USERNAME`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `MAILPIT_SMTP_PORT` and `MAILPIT_UI_PORT` from a **root** `.env` that does not exist — every one resolves to its default. Task 4's hard-coded `ticket_user`/`secret` are correct only because of that. If TM-5 ("One-command local environment") introduces a root `.env` with different credentials, `phpunit.xml` must change with it. The comment added in task 6 records the dependency.
- **`ticket_user` cannot create the test database.** `RefreshDatabase` migrates but never issues `CREATE DATABASE`; the schema is created by the container's `MYSQL_DATABASE: ticket_management_test` (`docker-compose.yml:41`) on **first boot only**. After `docker compose down -v`, the volume is recreated and the database returns. If the database is dropped by hand without wiping the volume, recreate it as root rather than granting `ticket_user` global privileges.
- **`php artisan migrate` on a half-applied database.** Laravel migrates per-file and records each in the `migrations` table, so a mid-run failure leaves earlier migrations applied. The four migrations in `database/migrations/` are all `Schema::create`, so a rerun fails on "table already exists" rather than corrupting data. See Migration / Rollback below.

---

## Test Plan

Unit and feature tests run through `composer test` from `backend/`. Both services must be up.

1. **Delete** `backend/tests/Feature/ExampleTest.php` — asserts the stock Blade welcome view returns 200; not a contract this API owns (task 5).
2. **Create** `backend/tests/Feature/HealthTest.php` — `RefreshDatabase`. Three tests:
   - `test_it_reports_ok_when_the_database_is_reachable` — integration. `200`, `status: ok`, `checks.database.ok: true`, no `error` key, full payload structure. Doubles as the regression test for "migrate runs clean": `RefreshDatabase` runs all four migrations against MySQL 8 before the assertion.
   - `test_it_reports_the_configured_app_version` — integration. Overrides `app.version` to `9.9.9-test` and asserts the response echoes it, proving the value is read from config rather than hard-coded. Asserts `api` is `v1` in the same request.
   - `test_the_route_is_versioned_under_api_v1` — unit-ish routing assertion. `route('health', absolute: false)` is `/api/v1/health`, and `GET /api/health` returns `404`. This is the test that would catch someone writing `/api/v1` into the route path and producing `/api/v1/api/v1/health`.
3. **Create** `backend/tests/Feature/HealthDegradedTest.php` — **no** `RefreshDatabase`. Two tests:
   - `test_it_returns_503_and_hides_the_reason_when_debug_is_off` — integration. `503`, `status: degraded`, `checks.database.ok: false`, `error` exactly `unavailable`. This is the security assertion for `HealthController.php:51`.
   - `test_it_surfaces_the_probe_error_when_debug_is_on` — integration. `503` and `error` is *not* `unavailable`, proving the debug gate is a real branch and not dead code.
4. **Unchanged:** `backend/tests/Unit/ExampleTest.php` and `backend/tests/TestCase.php`. Do not add a `RefreshDatabase` trait to the base `TestCase` — `HealthDegradedTest` depends on inheriting a class that opens no connection.
5. **Smoke — the suite runs at all.** `composer test` from `backend/` exits `0`. It currently exits `1` on the script line before PHPUnit starts, so "5 passed" is only meaningful once task 2 lands.
6. **Smoke — formatting.** `./vendor/bin/pint --test` from `backend/` reports `passed`. It passes today; the new files must not change that.
7. **Manual — the live endpoint.** With `php artisan serve` running, `curl -si http://localhost:8000/api/v1/health` returns `HTTP/1.1 200 OK` and a body whose `version` matches `APP_VERSION` in `backend/.env`. This is the only check that exercises the real HTTP stack, including the CORS middleware from `config/cors.php` (whose `paths` on line 17 covers `api/*`, and so `api/v1/*`).

Expected final count: **5 passing tests** (3 in `HealthTest`, 2 in `HealthDegradedTest`) plus the retained `tests/Unit/ExampleTest.php`, for 6 total.

---

## Migration / Rollback

This story adds **no** migration. It makes the four existing ones runnable for the first time:

```
0001_01_01_000000_create_users_table
0001_01_01_000001_create_cache_table
0001_01_01_000002_create_jobs_table
2026_08_25_075421_create_personal_access_tokens_table
```

Forward, from `backend/` with the containers healthy:

```bash
php artisan migrate
php artisan migrate:status   # every row DONE
```

If it fails partway, the `migrations` table records only the batches that completed. Recover with `php artisan migrate:rollback` (reverses the last batch; every one of the four defines a working `down()` — see `2026_08_25_075421_create_personal_access_tokens_table.php:29-32`), or reset the development database outright with `php artisan migrate:fresh`. **`migrate:fresh` drops every table**; it is safe here only because no story has produced data worth keeping yet.

Rolling back the story's own changes is a plain `git revert` — `composer.json`, `composer.lock`, `config/app.php`, `.env.example`, `phpunit.xml`, `HealthController.php`, the test files, `docs/api-contract.md` and `README.md` are all text with no state behind them. The one action git cannot revert is the apt install, and leaving `pdo_mysql` installed harms nothing.

---

## Verification Steps

Run in this order. Every command's working directory is stated.

1. **Driver present:** anywhere — `php -m | grep -x pdo_mysql` prints `pdo_mysql`, and `php -r 'echo implode(",", PDO::getAvailableDrivers()), PHP_EOL;'` includes `mysql`.
2. **Services healthy:** repo root — `docker compose up -d && docker compose ps`. `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all report `healthy`.
3. **Backend builds:** `backend/` — `composer validate --no-check-publish` reports the manifest is valid, and `git diff --stat composer.lock` shows only the content-hash line changed.
4. **Migrations run clean:** `backend/` — `php artisan migrate` completes with no exception; `php artisan migrate:status` lists all four migrations as `DONE`.
5. **Backend tests:** `backend/` — `composer test` exits `0` and reports **5 passing** health tests plus the retained unit test. It must reach PHPUnit; an error mentioning `@no_additional_args` means task 2 was not applied.
6. **Endpoint returns 200:** `backend/` — start `php artisan serve`, then from another shell:

   ```bash
   curl -si http://localhost:8000/api/v1/health | head -1
   curl -s  http://localhost:8000/api/v1/health
   ```

   The first prints `HTTP/1.1 200 OK`. The second's `status` is `"ok"`, `checks.database.ok` is `true`, there is **no** `checks.database.error`, `version` is `"0.1.0"` (or whatever `APP_VERSION` holds), and `api` is `"v1"`.
7. **Versioning holds:** `backend/` — `php artisan route:list` shows `GET|HEAD api/v1/health`, and `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/api/health` prints `404`. A `200` there means a literal `/api/v1` crept into the route path.
8. **Degraded path is honest:** `backend/` — with the API still serving, `docker compose stop mysql` from the repo root, then `curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8000/api/v1/health` prints `503`. Restart with `docker compose start mysql` and confirm it returns to `200`.
9. **Formatting:** `backend/` — `./vendor/bin/pint --test` reports `passed`.
10. **Docs match reality:** compare the two JSON blocks in `docs/api-contract.md` field-by-field against the live responses captured in steps 6 and 8. Every key and every type must match; a contract that drifts on its first entry will drift on all fifty.
11. **Regression:** `git status --short` lists only the ten files this story touches. **No file under `frontend/` is modified**, and no new migration appears in `backend/database/migrations/`.

---

## Done Criteria

- [ ] `php -m | grep -x pdo_mysql` prints `pdo_mysql`, and `ext-pdo_mysql: "*"` is in `backend/composer.json`'s `require` block with `composer.lock`'s hash refreshed via `composer update --lock` (no package version changed).
- [ ] `composer test` from `backend/` exits `0` — the `@no_additional_args` token is gone from the `test` script and the `config:clear` step remains.
- [ ] `php artisan migrate` runs clean against MySQL 8 on port 3306 and `php artisan migrate:status` reports all four migrations `DONE`.
- [ ] `GET /api/v1/health` returns **`200`** with `status: "ok"` and `checks.database.ok: true`; `version` comes from `config('app.version')` and the API version moved to its own `api` key.
- [ ] `config/app.php` has a `version` key and `.env.example` documents `APP_VERSION`; the `comm -23` check in task 6 surfaces no variable this project needs but does not document.
- [ ] `.env.example` names the owning story (TM-8, TM-43) beside every variable no code reads yet, and still contains no real secret.
- [ ] `phpunit.xml` sets `DB_USERNAME` and `DB_PASSWORD` for the test connection, so the suite no longer depends on the git-ignored `backend/.env`.
- [ ] `tests/Feature/HealthTest.php` (3 tests, `RefreshDatabase`) and `tests/Feature/HealthDegradedTest.php` (2 tests, no `RefreshDatabase`) pass; `tests/Feature/ExampleTest.php` is deleted.
- [ ] `docs/api-contract.md` documents `GET /api/v1/health` with both response shapes and a field table, and its `> **Placeholder.**` blockquote is gone.
- [ ] Root `README.md` lists PHP `pdo_mysql` as a prerequisite with the install command and the verification command.
- [ ] `./vendor/bin/pint --test` reports `passed`; no file under `frontend/` and no new migration was touched.
- [ ] Overview `00-overview.md` updated with this story.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 03 (TM-4).**
