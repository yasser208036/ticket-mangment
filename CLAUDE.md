# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A ticket-management system: a Laravel 13 JSON API (`backend/`) and a Vue 3 SPA (`frontend/`), with MySQL 8 and Mailpit supplied by Docker Compose. Roles are **Admin** and **Agent** — requesters are contact records, not logins.

Core domain: tickets move through a seven-status workflow (`new → open → in-progress → pending → resolved → closed`, plus `reopened`), can be assigned/claimed/escalated, carry an append-only activity trail, and trigger queued email notifications on assignment, creation, status change, and escalation. Master data (categories, priorities, statuses) is admin-managed; the legal-transition graph is a database table, not hardcoded logic.

## Commands

Run services first — the backend cannot migrate or test without them.

```bash
docker compose up -d --wait   # mysql (3306), mysql-test (3307), mailpit (1025 / UI 8025)
docker compose ps             # container health
docker compose down           # stop, keep data
docker compose down -v        # stop and WIPE both databases
```

Backend, from `backend/`:

```bash
composer setup                          # install, .env, key:generate, migrate, build assets
php artisan serve                       # http://localhost:8000
composer dev                            # serve + queue:listen + Pail + Vite, all at once
composer test                           # clears config, then php artisan test
php artisan test --filter=HealthTest    # one test class
php artisan test --filter=test_a_legal_move_is_allowed_from_every_seeded_status   # one test method
./vendor/bin/pint                       # format (Laravel preset, no pint.json)
./vendor/bin/pint --test                # check formatting without writing
php artisan migrate:fresh --seed        # reset dev DB: schema + admin + master data only
php artisan db:seed --class=DemoSeeder  # add ~50 demo tickets on top (local only, refuses a second run)
php artisan queue:work                  # process queued notification jobs (not run by `serve` alone)
```

Frontend, from `frontend/`:

```bash
npm install
npm run dev            # http://localhost:5173, proxies /api to Laravel
npm run build           # vue-tsc -b && vite build — type errors fail the build
npx vue-tsc -b          # typecheck alone
npm run test:unit       # vitest run (same as `npm test`; CI calls test:unit)
npm run test:watch      # vitest watch mode
npm run lint            # ESLint, warnings fail (--max-warnings 0)
npm run format:check    # Prettier
npx vitest run src/api/client.spec.ts   # single file
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

- **`apiPrefix: 'api/v1'` is set there, not in the route file.** Paths in `routes/api.php` are relative to `/api/v1`, so `Route::get('/health', …)` serves `GET /api/v1/health`. Never write `/api/v1` into a route path — you will get `/api/v1/api/v1/…`.
- Exceptions render as JSON for any `api/*` request or any request that expects JSON, so API errors do not need per-controller try/catch to avoid HTML error pages.
- Laravel's own `/up` probe is registered at the root, separate from the app's `/api/v1/health`.

Add new API endpoints as `App\Http\Controllers\Api\V1\…` and register them in `backend/routes/api.php`. A future `/api/v2` is meant to sit alongside v1 rather than replace it. Every write route (`POST`/`PATCH`/`DELETE`) carries a `throttle:` middleware (`login`, `password`, or `write` — 60/min per user, registered in `AppServiceProvider::boot()`); a new write route without one fails `RateLimitCoverageTest`.

### Models use PHP attributes, not properties

Models declare `#[Fillable([...])]`, `#[Hidden([...])]`, and `#[UsePolicy(...)]` as class attributes instead of `protected $fillable`/`$hidden` arrays. Match this idiom in new models — `ModelFillableCoverageTest` fails a model that defines neither `#[Fillable]` nor `$guarded = ['*']`. Casts still go in the `casts()` method.

### Tests run against real MySQL on port 3307 — never SQLite

`backend/phpunit.xml` points the test suite at the **second** container, `tm-mysql-test`, because the schema uses a FULLTEXT index, ENUM columns, and `ON DELETE RESTRICT`, all of which SQLite would silently accept while production rejects them. The separate container also means `RefreshDatabase` never truncates dev data. Do not "simplify" the suite to `:memory:` SQLite, and do not point tests at port 3306.

`phpunit.xml` also sets `ADMIN_PASSWORD` and `QUEUE_CONNECTION=database` (never `sync` — a queued-vs-synchronous assertion would pass for the wrong reason under `sync`). Because of the seeded `ADMIN_PASSWORD`, `$this->seed()` with no argument now works and creates one admin — tests asserting exact user/admin counts must seed only the specific seeders they need (`$this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class])`) rather than the bare call.

### Domain model

- **Tickets** belong to a `Requester` (a contact record with no login), a `Category`, a `Priority`, and a `Status`, and may have an assignee (`User`, must be an active agent). `TicketReferenceGenerator` allocates `TKT-YYYY-NNNNNN` references from a `ticket_sequences` table inside a DB transaction — it throws outside one. Factories that need a reference go through this generator (`TicketFactory::allocateReference()`), never a private counter, or they collide with real API-created tickets.
- **Status workflow is data, not code.** `status_transitions` holds one row per legal move (14 seeded edges), optionally gated to a role (`resolved → closed` is admin-only). `TicketWorkflow::assertCanTransition()` reads this table; an illegal move is always `422` under `errors.status_id`, **never `403`** — the policy layer is a pure role gate with no per-transition logic. `TicketTimestamps` stamps `first_responded_at` once and clears `resolved_at`/`closed_at` on any move back to a non-terminal status. See `docs/ticket-lifecycle.md` for the full transition table.
- **Escalation** (`POST /tickets/{ticket}/escalate`) is a separate act from a status move: it bumps `escalation_level`, raises priority by one level (capped), and reassigns to the least-loaded active admin unless already held by one.
- **`ticket_activities` is an append-only audit trail.** `TicketActivity` uses `#[UseEloquentBuilder(AppendOnlyBuilder::class)]`, which refuses every write but `insert`. `App\Services\ActivityRecorder` is the **only** writer — it throws `LogicException` if called outside a transaction, matching `TicketReferenceGenerator`'s guard. Every mutation path (create, update, delete, assign, claim, status change, escalate, note, category deletion, staleness) writes a row through it; `ActivityCoverageTest` ties every `TicketActivityEvent` case to a producing test class.
- **Master data caching**: the frontend's `masterData` Pinia store fetches categories/priorities/statuses once and short-circuits (`ensureLoaded()`) once all three are non-empty — tests must seed the store directly rather than mock three API modules.

### Email notifications

Every notification is a **queued domain event → auto-discovered listener → queued `Notification`** chain (no `Event::listen()` registration anywhere — `app/Listeners` is auto-discovered by signature). Four notifications exist: ticket assigned (to the agent), ticket created (confirmation to the requester), status changed (to the requester, delayed + superseded so a burst of changes sends one email), and escalated (to every active admin, immediately, no delay).

- **`App\Services\MailSafety`** listens on `Illuminate\Mail\Events\MessageSending` and **throws** (never returns `false`, which `until()` would treat as a silent cancel) unless the transport is `log`/`array` or SMTP pointed at a host in `MAIL_SAFE_SMTP_HOSTS`. Guards `local` and `testing` only — production is deliberately unguarded.
- **`App\Notifications\Concerns\HasRetryPolicy`** gives every notification its `tries`/`backoff`/`timeout` from `config('notifications.retry')` and a `failed()` hook that logs context (never an email address) and does not retry or alert further. `tries`/`timeout` are properties (read via `getAttributeValue()`); `backoff` must be a **method**, not a property, or the framework silently ignores it.
- **Shared mail layout**: every notification renders through `resources/views/mail/layout.blade.php` (+ `layout-text.blade.php`) and a `mail/partials/summary.blade.php` partial fed a `label => value` map the notification builds — so a requester email and an admin email share markup without sharing a field list. Free text (descriptions, resolution notes, escalation reasons) goes through a Blade view pair with `{{ }}` in the HTML view and `{!! !!}` in the text view — **never** `MailMessage->line()`, which mangles a user's own newlines and markdown.
- `queue.connections.database.after_commit` is deliberately `false` — every dispatch site fires after its own `DB::transaction()` returns, so the flag would only add a redundant safety net (and would silently zero queue assertions under `RefreshDatabase`, which holds its own open transaction).

### Authentication and authorization

Sanctum **bearer tokens**, no session — see the header comment in `backend/routes/api.php`. Policies (`TicketPolicy`, `CategoryPolicy`, `UserPolicy`) gate most actions; `EnsureUserIsAdmin`/`EnsureUserIsActive` middleware cover the rest. `backend/.env.example` sets `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL`, but `cors.php`'s `supports_credentials` is `false` — the SPA is a pure token client, not a cookie one.

### Health endpoint pattern

`HealthController` probes dependencies rather than assuming them: it returns `200 / "ok"` only when every check passes, `503 / "degraded"` otherwise, and it leaks a probe's error message **only when `app.debug` is true** (host names and drivers otherwise reach unauthenticated callers). Follow that shape when adding checks.

### Two Vite setups — do not confuse them

`frontend/` is the real SPA (Vue 3, TypeScript, Pinia, Vue Router, axios, Vitest). `backend/` has its **own** `package.json` with Vite, Tailwind 4 and `laravel-vite-plugin` for Blade-side assets. Frontend work belongs in `frontend/` unless a task is explicitly about server-rendered views.

The SPA uses `eslint.config.js` and `.prettierrc.json`. Prettier owns formatting with single quotes and no semicolons; ESLint's final `skip-formatting` config prevents conflicting style rules. `vue/no-v-html` is an enforced lint error.

The `client.ts` axios instance attaches the bearer token via a request interceptor and clears it + redirects on a `401` from any endpoint other than `/auth/login`, `/auth/me`, `/auth/logout` (avoids a redirect loop while checking session state). Pinia stores generally mirror backend concerns 1:1 (`tickets`, `categories`, `users`, `masterData`, `workload`, `stats`, `auth`) and each store's own methods call the matching `api/*.ts` module — components should go through the store, not call `api/*` directly.

### CI

`.github/workflows/ci.yml` has three jobs on every push/PR to `main`: **backend** (Pint + PHPUnit against MySQL 8 on 3307), **frontend** (ESLint, Prettier, typecheck, `npm run test:unit`), and **secrets** (`gitleaks`, full history scan). All three must pass.

### utf8mb4 everywhere

Both MySQL containers run `--character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci` so ticket subjects and descriptions accept Arabic and emoji. Keep new columns on that charset.

### Mail and queue

Mailpit captures every outgoing message in development — nothing reaches a real inbox (enforced by `MailSafety`, not just convention). Read captured mail at http://localhost:8025. Notifications are queued (`QUEUE_CONNECTION=database`); `php artisan queue:work` (or `composer dev`, which already runs one via `queue:listen --tries=1 --timeout=0`) must be running or nothing is delivered. See `README.md`'s "Queue and mail" section for the full failed-job command reference.

## Planning workflow (squad-kit)

Work is driven by Jira project **TM** through squad-kit:

- `tools/jira/backlog.json` is the **single source of truth** for the backlog — 9 epics, ~55 stories. Edit it there and re-run `upload.mjs`; do not hand-create issues in Jira. `tools/jira/created-issues.json` maps backlog ids (`E1-S1`) to Jira keys (`TM-2`).
- `.squad/stories/<feature>/<TM-id>/intake.md` — story intake, pasted from the tracker.
- `.squad/plans/<feature>/NN-story-<slug>-<TM-id>.md` — generated implementation plan. `NN` is a **global** sequence across all feature folders.
- Generate a plan with `/squad-plan <intake-path>`. The meta-prompt lives inside the installed squad-kit npm package, not in `.squad/`.

**When implementing from a plan file, treat it as read-only** unless the user explicitly asks to revise it. Implement in application sources and tests instead. Plans are frequently audit-and-close: read the plan's own "what already exists" section before assuming a story is unstarted — but also verify it against the current code rather than trusting the plan's timestamp, since later stories often land ahead of or independent of a plan's assumed order.

## Repository state

- `docs/` holds `erd.md` (Mermaid schema diagram), `api-contract.md` (every endpoint, request/response, auth, error cases — CI-enforced complete via `ApiContractCoverageTest`), `ticket-lifecycle.md` (statuses, transitions, timestamps, escalation, activity logging), and `deployment-runbook.md` (env vars, deploy/rollback procedure, first-deploy checklist — no hosting decision has been made, so `staging`/`production` rows stay `_TBD_`).
- `.gitignore` at the root has a `# Managed by squad-kit` block at the end — do not edit inside it.
- `backend/.env`, `frontend/.env`, and `tools/jira/.jira.env` are ignored; their `.example` siblings are tracked and are the place to document new variables. `backend/.env.example` is the exhaustive list — every key there is documented in `docs/deployment-runbook.md`'s environment-variable table.
