# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A ticket-management system: a Laravel 13 JSON API (`backend/`) and a Vue 3 SPA (`frontend/`), with MySQL 8 and Mailpit supplied by Docker Compose. Roles are **Admin** and **Agent** — requesters are contact records, not logins.

The product is early: the Laravel side has a health endpoint and the default user table; the Vue side is still the stock Vite scaffold. Most directories you would expect (controllers, stores, routes) do not exist yet.

## Commands

Run services first — the backend cannot migrate or test without them.

```bash
docker compose up -d          # mysql (3306), mysql-test (3307), mailpit (1025 / UI 8025)
docker compose ps             # wait for all three to report healthy
docker compose down           # stop, keep data
docker compose down -v        # stop and WIPE both databases
```

Backend, from `backend/`:

```bash
composer setup                        # install, create .env, key:generate, migrate, build assets
php artisan serve                     # http://localhost:8000
composer test                         # clears config, then php artisan test
php artisan test --filter=HealthTest  # one test class
php artisan test tests/Feature/ExampleTest.php   # one file
./vendor/bin/pint                     # format (Laravel preset, no pint.json)
./vendor/bin/pint --test              # check formatting without writing
php artisan migrate:fresh --seed      # reset the dev database
```

Frontend, from `frontend/`:

```bash
npm install
npm run dev            # http://localhost:5173
npm run build          # vue-tsc -b && vite build — type errors fail the build
npx vue-tsc -b         # typecheck alone
npx vitest             # no "test" script and no vitest.config.ts exist yet
npx vitest run src/components/HelloWorld.spec.ts   # single file
```

Jira backlog tooling, from the repo root:

```bash
node tools/jira/upload.mjs --dry-run       # preview, writes nothing
node tools/jira/upload.mjs                 # create epics + stories
node tools/jira/upload.mjs --sync-points   # backfill story points
```

## Architecture

### Laravel 13 slim skeleton — configuration lives in `bootstrap/app.php`

There is no `app/Http/Kernel.php` and no `app/Exceptions/Handler.php`. Middleware, exception rendering, and routing are all configured in `backend/bootstrap/app.php`:

- **`apiPrefix: 'api/v1'` (line 16) is set there, not in the route file.** Paths in `routes/api.php` are relative to `/api/v1`, so `Route::get('/health', …)` serves `GET /api/v1/health`. Never write `/api/v1` into a route path — you will get `/api/v1/api/v1/…`.
- Exceptions render as JSON for any `api/*` request or any request that expects JSON (lines 22–24), so API errors do not need per-controller try/catch to avoid HTML error pages.
- Laravel's own `/up` probe is registered at the root, separate from the app's `/api/v1/health`.

Add new API endpoints as `App\Http\Controllers\Api\V1\…` and register them in `backend/routes/api.php`. A future `/api/v2` is meant to sit alongside v1 rather than replace it.

### Models use PHP attributes, not properties

`app/Models/User.php` declares `#[Fillable([...])]` and `#[Hidden([...])]` as class attributes instead of the older `protected $fillable` / `protected $hidden` arrays. Match this idiom in new models. Casts still go in the `casts()` method.

### Tests run against real MySQL on port 3307 — never SQLite

`backend/phpunit.xml` (lines 27–39) points the test suite at the **second** container, `tm-mysql-test`, and the comment there explains why: the planned schema uses a FULLTEXT index, ENUM columns, and `ON DELETE RESTRICT`, all of which SQLite would silently accept while production rejects them. The separate container also means `RefreshDatabase` never truncates your development data.

Do not "simplify" the suite to `:memory:` SQLite, and do not point tests at port 3306.

### Authentication

Sanctum **bearer tokens**, no session — see the header comment in `backend/routes/api.php`. `backend/.env.example` also sets `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL=http://localhost:5173`, so treat the SPA as a token client unless a story says otherwise.

### Health endpoint pattern

`HealthController` probes dependencies rather than assuming them: it returns `200 / "ok"` only when every check passes, `503 / "degraded"` otherwise, and it leaks a probe's error message **only when `app.debug` is true** (host names and drivers otherwise reach unauthenticated callers). Follow that shape when adding checks.

### Two Vite setups — do not confuse them

`frontend/` is the real SPA (Vue 3, TypeScript, Pinia, Vue Router, axios, Vitest). `backend/` has its **own** `package.json` with Vite, Tailwind 4 and `laravel-vite-plugin` for Blade-side assets. Frontend work belongs in `frontend/` unless a task is explicitly about server-rendered views.

The SPA is unwired: Pinia, Vue Router and axios are installed but `src/main.ts` only mounts `App.vue`, and there is no ESLint config file despite the ESLint dependencies. Expect to establish these patterns rather than follow them.

### utf8mb4 everywhere

Both MySQL containers run `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci` so ticket subjects and descriptions accept Arabic and emoji. Keep new columns on that charset.

### Mail

Mailpit captures every outgoing message in development — nothing reaches a real inbox. Read mail at http://localhost:8025.

## Planning workflow (squad-kit)

Work is driven by Jira project **TM** through squad-kit:

- `tools/jira/backlog.json` is the **single source of truth** for the backlog — 9 epics, ~55 stories. Edit it there and re-run `upload.mjs`; do not hand-create issues in Jira. `tools/jira/created-issues.json` maps backlog ids (`E1-S1`) to Jira keys (`TM-2`).
- `.squad/stories/<feature>/<TM-id>/intake.md` — story intake, pasted from the tracker.
- `.squad/plans/<feature>/NN-story-<slug>-<TM-id>.md` — generated implementation plan. `NN` is a **global** sequence across all feature folders.
- Generate a plan with `/squad-plan <intake-path>`. The meta-prompt lives inside the installed squad-kit npm package, not in `.squad/`.

**When implementing from a plan file, treat it as read-only** unless the user explicitly asks to revise it. Implement in application sources and tests instead.

## Repository state

- **This is not yet a git repository** — there is no `.git`. Initialising it, writing the root README, and creating the `docs/` placeholders is story TM-2, planned at `.squad/plans/foundation-environment/01-story-scaffold-monorepo-skeleton-TM-2.md`. Git commands will fail until that runs.
- `docs/` is empty; it is meant to hold the ERD, the API contract, and the deployment runbook.
- `.gitignore` at the root has a `# Managed by squad-kit` block at the end — do not edit inside it.
- `backend/.env` and `tools/jira/.jira.env` are ignored; their `.example` siblings are tracked and are the place to document new variables.
