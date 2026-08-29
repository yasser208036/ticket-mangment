# Story 54 — Production build and deployment runbook (Story: TM-63)

## Prerequisites

- None. Independent of Stories 50–53. Docs-only, plus one build verification run.
- **`docs/deployment-runbook.md` is a genuine placeholder — 45 lines, and only one section has real content.** `## Environments`, `## Deploy procedure`, `## Rollback procedure`, and `## Post-deploy checks` (**6–24**) are all stub headings with `_Steps go here._`/`_TBD_`. `## Scheduled tasks` (**26–45**) is fully written and accurate — **verified against `routes/console.php:17` and `FlagStaleTickets.php`, unchanged, do not rewrite it.**
- **There is no Dockerfile anywhere in this repository** (`find . -iname "Dockerfile*"` → no output) and no production `docker-compose` override. `docker-compose.yml` at the repo root is explicitly local-only (its own header comment: *"Local development services... The Laravel app and the Vue dev server run on the host and connect to these"*). **This story documents a traditional host deployment — PHP-FPM/a web server serving `backend/public`, a static host or web server serving `frontend/dist` — not a container build.** Do not invent a Dockerfile or a specific cloud provider; the codebase has made no such decision, and none of TM-63's five ACs asks for one.

---

## What already exists — audit before you write

| AC | Status | Evidence |
|---|---|---|
| **AC1** — frontend build verified, output path documented | **Not documented; verified today, 2026-08-28.** `npm run build` from `frontend/` → `vue-tsc -b && vite build` succeeds, output is `frontend/dist/` (`index.html`, one CSS bundle 45.91 kB / gzip 8.14 kB, one JS bundle 276.61 kB / gzip 82.93 kB). | Measured this session |
| **AC2** — migrations, config/route caching, queue worker, scheduler as long-running processes | **Scheduler is done** (`## Scheduled tasks`, **26–45**). **Migrations, caching, and the queue worker have no content anywhere in `docs/`.** | `docs/deployment-runbook.md:26–45` |
| **AC3** — every required production env var listed with guidance; debug off | **Not documented.** `config/app.php:55` — `'debug' => (bool) env('APP_DEBUG', false)` — **defaults to `false`**, but `backend/.env.example:4` sets `APP_DEBUG=true` for local dev, so a production `.env` copied carelessly from the example ships with debug **on**. | `backend/.env.example` (79 lines, all vars); `config/app.php:55` |
| **AC4** — rollback steps, including reversing a migration | **Not documented.** Verified today: `grep -rL "public function down" backend/database/migrations/` → **no output** — all 14 migrations have a working `down()`, so `migrate:rollback` is viable without exception. | Measured this session |
| **AC5** — first-deploy checklist, safe initial admin creation | **Not documented.** `AdminUserSeeder.php:17–19` aborts if `ADMIN_PASSWORD` is blank, and `.env.example:70`'s example value is the literal string `password` — a real deploy that copies the example verbatim seeds an admin with a published, guessable password. | `backend/database/seeders/AdminUserSeeder.php:17–19`; `backend/config/seeding.php` |

**The honest shape of this story: the scheduler section is done and untouched; every other section is written from scratch, grounded in commands and config verified against this codebase today, not a generic Laravel checklist.**

---

## Decision — this is a two-artifact deployment: a static SPA build and a PHP application, not a single container

`frontend/` and `backend/` are deployed independently — the SPA is a static build served by any web server or CDN, the API is a PHP-FPM/`artisan serve`-style process behind a web server, and they communicate over CORS (`backend/config/cors.php:22–25`, `FRONTEND_URL` sets the allowed origin). The runbook's `## Deploy procedure` is split into a **Backend** and a **Frontend** subsection for this reason, not because they must ship in lockstep — they can be deployed independently as long as `FRONTEND_URL` on the backend matches wherever the frontend actually lands.

## Decision — the env var table is exhaustive against `.env.example`, not a curated subset

`backend/.env.example` has **35 keys** across `APP_*`, `DB_*`, `SESSION_*`, `BROADCAST_*`/`FILESYSTEM_*`, `QUEUE_CONNECTION`, `CACHE_STORE`, `MAIL_*`, `FRONTEND_URL`/`SANCTUM_STATEFUL_DOMAINS`, `ADMIN_*`, and `TICKETS_*`. Task 3 documents every one with production guidance (change it / leave it / verify it), not just the ones that "feel important" — a var omitted from the table is a var a future deployer has to reverse-engineer from source, which is exactly what this story exists to prevent.

## Decision — `APP_VERSION` is documented as a manual deploy step, because CI does not set it despite its own comment claiming otherwise

`backend/.env.example:6` says *"Reported by GET /api/v1/health. CI overrides this with the release tag"*, and `config/app.php:24` repeats the claim. **Neither is true**: `grep -n "APP_VERSION" .github/workflows/ci.yml` → no output. CI never sets it. The runbook does not silently perpetuate this — task 3's `APP_VERSION` row states the current behaviour plainly (defaults to `0.1.0`, `config/app.php:29`) and gives the actual step a deploy must take (`export APP_VERSION=$(git describe --tags --always)` or equivalent, before `config:cache`), rather than repeating a comment that has been false since it was written.

## Decision — rollback is `migrate:rollback`, verified viable, not a generic warning

All 14 migrations (`backend/database/migrations/`) have a working `down()` — confirmed by `grep -rL "public function down" backend/database/migrations/` returning nothing. The rollback section names the actual command (`php artisan migrate:rollback --step=N`) with a real worked example against this schema's migration list, rather than a generic "be careful" paragraph.

## Decision — the first-deploy admin checklist centers on the one real footgun: the example password

`AdminUserSeeder.php:17–19` throws if `ADMIN_PASSWORD` is blank — that part is already safe. **The unsafe part is that `.env.example:70` ships the literal value `password`**, and nothing stops a deploy from copying the example file verbatim into production the way `ci.yml:54`'s `cp .env.example .env` does for tests. Task 5's checklist makes this the first, boldest line item.

---

## Context — Read These Files First

1. `docs/deployment-runbook.md` — **45 lines, all of it.** `## Environments` (**6–12**), `## Deploy procedure` (**14–16**), `## Rollback procedure` (**18–20**), `## Post-deploy checks` (**22–24**) are the stub sections tasks 1–4 replace. `## Scheduled tasks` (**26–45**) is correct and **untouched**.
2. `backend/.env.example` — **79 lines, all of it.** Every key task 3's table documents, grouped exactly as the file groups them (`APP_*` **1–11**, DB **22–35**, session **37–41**, queue/cache **47,49**, mail **53–60**, frontend/CORS **62–64**, admin **66–70**, stale-ticket **72–79**).
3. `backend/config/app.php:24,29,42,55` — `version`, `env` (defaults to `'production'` if unset), `debug` (defaults to `false` if unset — the risk is an inherited `true` from a copied `.env`, not the code default).
4. `backend/config/cors.php` — **37 lines, all of it.** `allowed_origins` (**22–25**) reads `FRONTEND_URL`; `supports_credentials: false` (**35**) — bearer-token auth, no cookie, so `SANCTUM_STATEFUL_DOMAINS` is present in `.env.example` but not load-bearing for this app's actual auth flow.
5. `backend/config/queue.php:15` — `'default' => env('QUEUE_CONNECTION', 'database')`; `.env.example:47` sets `QUEUE_CONNECTION=database`, meaning **queued jobs sit in the `jobs` table until a worker runs** — the reason AC2 calls out the queue worker as a long-running process.
6. `backend/database/seeders/AdminUserSeeder.php` — **31 lines, all of it.** The blank-password guard (**17–19**); idempotent by email (**21–23**).
7. `backend/database/seeders/DatabaseSeeder.php` — **25 lines, all of it.** `php artisan db:seed` with no class runs exactly this: `AdminUserSeeder`, `CategorySeeder`, `PrioritySeeder`, `StatusSeeder`, `StatusTransitionSeeder` — **no demo/sample data**, safe to run in production as the codebase stands today.
8. `backend/config/seeding.php` — **8 lines, all of it.** `ADMIN_PASSWORD` has no fallback (`env('ADMIN_PASSWORD')`, no default argument) — confirms the seeder's guard is the only thing stopping a blank password, not a code-level default.
9. `backend/database/migrations/` — **14 files.** List them in the runbook's rollback example in chronological (filename) order; every one confirmed to have `down()`.
10. `backend/composer.json:36–44` — the `setup` script (`composer setup`), which is the **local** dev path (unforced `migrate`, includes `npm run build` for the unused Blade welcome page) — task 2 documents the **different**, production-appropriate command sequence, not this script.
11. `backend/routes/console.php` — **17 lines, all of it.** Confirms `## Scheduled tasks`'s claims are current; no edit needed here, only cross-referenced.
12. `frontend/vite.config.ts` — **24 lines, all of it.** No `build.outDir` override, so Vite's default `dist/` is the real output path — confirmed by the build run in the audit table above.
13. `frontend/package.json:6–17` — `"build": "vue-tsc -b && vite build"` — a type error fails the build, which task 1 states as the verification step.
14. `.github/workflows/ci.yml` — confirms (by absence) that `APP_VERSION` is never set by CI, grounding the decision above.
15. `README.md:1–40` — the tone and table style (`## Prerequisites`'s tool/version/why table, **22–29**) task 3's env-var table matches; also confirms local dev is fully out of this story's scope, per the runbook's own opening disclaimer.

---

## Implementation tasks

### 1 — `## Environments` and the frontend build (AC1)

**File: `docs/deployment-runbook.md`**, replace **lines 6–12**:

```markdown
## Environments

| Environment | Host | Database | Notes |
|-------------|------|----------|-------|
| local | developer machine | `tm-mysql` container, port 3306 | see root `README.md` |
| staging | _TBD — no staging host is provisioned yet_ | _TBD_ | |
| production | _TBD — no production host is provisioned yet_ | _TBD_ | |

## Frontend production build

From `frontend/`:

\`\`\`bash
npm ci
npm run build
\`\`\`

`npm run build` runs `vue-tsc -b && vite build` (`frontend/package.json:8`) — **a
TypeScript error fails the build**, so this doubles as a type-check gate.
Output lands in **`frontend/dist/`** (Vite's default; nothing in
`vite.config.ts` overrides `build.outDir`): an `index.html` plus a
content-hashed `assets/` directory. Verified 2026-08-28: `index.html` (0.77 kB),
one CSS bundle (45.91 kB, gzip 8.14 kB), one JS bundle (276.61 kB, gzip
82.93 kB).

Serve `frontend/dist/` as static files from any web server or CDN. The SPA is
a client-side router (Vue Router in `history` mode) — **the web server must
rewrite every unmatched path back to `index.html`**, or a hard refresh on any
route other than `/` 404s. Set `VITE_API_BASE_URL` (`frontend/.env.example:2`)
before building if the API is not reachable at the default `/api/v1`.
```

`staging`/`production` stay `_TBD_` — **no host is provisioned for either**, confirmed by there being no Dockerfile, no deploy workflow, and no infrastructure-as-code anywhere in the repository. Documenting a specific host would be inventing a decision nobody has made.

### 2 — `## Deploy procedure`: backend build, migrations, caching, queue worker (AC2)

**File: `docs/deployment-runbook.md`**, replace the `## Deploy procedure` stub:

```markdown
## Deploy procedure

### Backend

From `backend/`, on the target host, after pulling the release:

\`\`\`bash
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env   # first deploy only — see the environment variable table below
php artisan migrate --force
php artisan config:cache
php artisan route:cache
\`\`\`

`--force` is required outside `local`/`testing` — `ConfirmableTrait` prompts
otherwise and a non-interactive deploy would hang. **Run `config:cache` only
after every environment variable is final** — a cached config no longer reads
`.env` at all, and a var changed after caching is silently ignored until the
next `config:clear`/`config:cache` cycle.

Restart the queue worker after every deploy (below) — a long-running
`queue:work` process holds the old code in memory and will not pick up the
new release on its own.

### Frontend

Build per the section above and publish `frontend/dist/` to wherever it is
served from. No server-side process to restart — static files only.

## Long-running processes

Two processes must run continuously, independent of the request/response
cycle the web server handles:

| Process | Command | Why |
|---|---|---|
| Queue worker | `php artisan queue:work --tries=3` | `QUEUE_CONNECTION=database` (`.env.example:47`) — queued jobs sit in the `jobs` table until a worker drains them. Without one running, nothing queued is ever delivered. Restart on every deploy. |
| Scheduler | `* * * * * php artisan schedule:run` | See `## Scheduled tasks` below — already documented, unchanged by this story. |

Both need a process supervisor (systemd, Supervisor, or the host's
equivalent) so a crashed worker restarts rather than silently stopping
delivery. No specific supervisor is prescribed here — none is chosen yet.
```

### 3 — Environment variables table (AC3)

**File: `docs/deployment-runbook.md`**, new section after `## Deploy procedure`:

```markdown
## Environment variables

Every key in `backend/.env.example`, grouped as that file groups them.
**Change** means the example value is unsafe or meaningless outside local
dev; **verify** means confirm it matches the target environment; **leave**
means the default is correct as-is.

| Key | Example value | Guidance |
|---|---|---|
| `APP_NAME` | `"Ticket Management"` | Leave, or change for branding. |
| `APP_ENV` | `local` | **Change to `production`.** Defaults to `production` if unset (`config/app.php:42`), but set it explicitly. |
| `APP_KEY` | _(blank)_ | **Change.** Generate with `php artisan key:generate` on first deploy; never reuse a key across environments — it encrypts sessions and any encrypted columns. |
| `APP_DEBUG` | `true` | **Change to `false`.** Defaults to `false` in code (`config/app.php:55`) but the example ships `true` — a `.env` copied carelessly leaks stack traces and file paths to every API error. |
| `APP_URL` | `http://localhost:8000` | **Change** to the API's real public URL. |
| `APP_VERSION` | `0.1.0` | **Change on every deploy.** CI does **not** set this (verified — see the decision above); export it from the release tag or commit SHA before `config:cache`, e.g. `export APP_VERSION=$(git describe --tags --always)`. Reported by `GET /api/v1/health`. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | `en` / `en` / `en_US` | Leave. |
| `APP_MAINTENANCE_DRIVER` | `file` | Leave, unless running more than one app server — then switch to `cache` so `artisan down` is visible to every server. |
| `BCRYPT_ROUNDS` | `12` | Leave — `phpunit.xml` overrides to `4` for test speed only; production keeps `12`. |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_DEPRECATIONS_CHANNEL` | `stack` / `single` / `null` | Verify the target host can write to `storage/logs/`, or switch `LOG_CHANNEL` to `errorlog`/`syslog` for a managed host with no persistent disk. |
| `LOG_LEVEL` | `debug` | **Change to `error` or `warning`.** `debug` in production is noisy and can log request payloads. |
| `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | `mysql` / `127.0.0.1` / `3306` / `ticket_management` / `ticket_user` / `secret` | **Change every value** to the production database. **Never reuse the example password.** |
| `DB_TEST_*` | — | **Do not set in production** — these exist only so `.env.example` documents the second container; `phpunit.xml` supplies its own test-DB values independent of this file (see `CLAUDE.md`). |
| `SESSION_DRIVER`/`SESSION_LIFETIME`/`SESSION_ENCRYPT`/`SESSION_PATH`/`SESSION_DOMAIN` | `database` / `120` / `false` / `/` / `null` | Leave — the `sessions` table exists (`0001_01_01_000000_create_users_table.php`), but the API is bearer-token only and does not exercise session middleware today. |
| `BROADCAST_CONNECTION` | `log` | Leave, unless a real-time feature is added later. |
| `FILESYSTEM_DISK` | `local` | **Verify.** `local` writes to `storage/app/`, which must be a persistent, writable path on the target host — not ephemeral container storage if the host ever becomes containerized. |
| `QUEUE_CONNECTION` | `database` | Leave — pairs with the `jobs` table and the queue-worker process above. |
| `CACHE_STORE` | `database` | Leave for a single app server. **Change to a shared store (e.g. `redis`)** if ever running more than one — `Schedule::withoutOverlapping()` (`routes/console.php:17`) locks via the cache, and a per-server cache defeats that lock across servers. |
| `MAIL_MAILER`/`MAIL_SCHEME`/`MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` | Mailpit values | **Change every value** to the production SMTP provider. Mailpit only exists in `docker-compose.yml`'s local services. |
| `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` | `helpdesk@ticket-management.test` / app name | **Change** the address to a real, deliverable sender domain. |
| `FRONTEND_URL` | `http://localhost:5173` | **Change** to the deployed SPA's real origin. Feeds `config/cors.php:22`'s `allowed_origins` — a stale value here blocks every write request from the real frontend with a CORS error, not an obvious API error. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,127.0.0.1:5173` | Verify it matches `FRONTEND_URL`'s host for consistency, though `supports_credentials: false` (`config/cors.php:35`) means this app's actual auth flow does not depend on it. |
| `ADMIN_NAME` | `Admin` | Leave, or change for branding. |
| `ADMIN_EMAIL` | `admin@ticket-management.test` | **Change** to a real, monitored address. |
| `ADMIN_PASSWORD` | `password` | **Change. Mandatory — see the first-deploy checklist below.** The seeder aborts if blank but does **not** reject the literal example value; a deploy that copies `.env.example` verbatim ships a publicly-known password. |
| `TICKETS_STALE_AFTER_HOURS` | `48` | Verify against the team's actual SLA. |
| `TICKETS_STALE_NOTIFY` | `false` | Leave `false` — no listener exists for `TicketsFlaggedStale` yet (`.env.example:77–78`); setting `true` dispatches an event nothing consumes. |
```

### 4 — `## Rollback procedure` (AC4)

**File: `docs/deployment-runbook.md`**, replace the stub:

```markdown
## Rollback procedure

### Code

Redeploy the previous release's artifacts (backend + frontend build) the same
way as a forward deploy — there is no automated release history to revert to
in this repository; the deploy mechanism is whatever host process placed the
current code.

### Migrations

Every migration in `backend/database/migrations/` has a working `down()`
(verified 2026-08-28 — `grep -rL "public function down" database/migrations/`
returns nothing). To reverse the most recent migration batch:

\`\`\`bash
php artisan migrate:rollback --step=1   # reverses the last migration only
php artisan migrate:rollback            # reverses the entire last batch
\`\`\`

**Roll back code before rolling back the migration**, not after — the
previous release's code does not know about columns a newer migration added,
so running old code against a newer schema is the safer of the two
mismatches; running old code against an *older*, rolled-back schema while the
newer code is still deployed guarantees column-not-found errors on the
first request.

`migrate:rollback` is **destructive** for any migration that drops a column
or table in its `up()` — reversing it recreates the structure but not the
data. None of this schema's 14 migrations does that today (all 14 are
additive: `create_*` or `add_*_to_*`), so a rollback here is safe as long as
that remains true; **a future migration that drops a column must ship its
own data-preserving rollback plan, not rely on this note.**

### Cache

After any rollback, clear and rebuild the config/route cache so cached values
from the rolled-back code are not served by the reverted app:

\`\`\`bash
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
\`\`\`
```

### 5 — `## Post-deploy checks` and the first-deploy admin checklist (AC5)

**File: `docs/deployment-runbook.md`**, replace the `## Post-deploy checks` stub and add a new final section:

```markdown
## Post-deploy checks

1. `curl https://<api-host>/api/v1/health` → `200`, `"status": "ok"`, and
   `"version"` matching the release just deployed (confirms `APP_VERSION` was
   set — see the environment variable table). A `503` means a dependency
   check failed; the response body names which one only when `APP_DEBUG` is
   `false`-safe (`checks.database.error` is `"unavailable"` unless debug is
   on — if it ever shows a real error message in production, `APP_DEBUG` was
   not set correctly).
2. `curl https://<api-host>/up` → `200` (Laravel's own liveness probe,
   separate from `/api/v1/health`).
3. Confirm the queue worker process is running (`systemctl status` or the
   supervisor's equivalent) and confirm the scheduler's cron entry is
   installed (`crontab -l` on the app host).
4. Log in through the deployed frontend as the admin account created below,
   and confirm `GET /api/v1/auth/me` returns the expected role.

## First-deploy checklist

Order matters — each step depends on the one before it:

1. **Set every environment variable from the table above**, especially
   `APP_KEY` (`php artisan key:generate`), `APP_DEBUG=false`, and a
   **real, unique `ADMIN_PASSWORD`** — never the example's literal `password`.
2. `php artisan migrate --force` — creates every table including
   `ticket_sequences`, `ticket_activities`, and `status_transitions`.
3. `php artisan db:seed --force` — runs `DatabaseSeeder`
   (`database/seeders/DatabaseSeeder.php:17–23`): `AdminUserSeeder`,
   `CategorySeeder`, `PrioritySeeder`, `StatusSeeder`,
   `StatusTransitionSeeder`. **This seeds master data and the one admin
   account only — no sample tickets, no demo data.** `AdminUserSeeder` aborts
   with `RuntimeException: ADMIN_PASSWORD is empty` if step 1 was skipped.
4. Log in as `ADMIN_EMAIL`/`ADMIN_PASSWORD` through the deployed frontend and
   change the password immediately via `PATCH /api/v1/auth/password` — the
   value that seeded the account should not remain the long-term credential,
   even though it was never the example's `password`.
5. Create any additional agent/admin accounts needed via
   `POST /api/v1/admin/users` (admin bearer required) — see
   `docs/api-contract.md`'s `admin.users.store` section for the request
   shape.
6. Run the post-deploy checks above.
```

### No application source changes.

Only `docs/deployment-runbook.md` is edited.

---

## Edge Cases & Failure Modes

- **`config:cache` run before `.env` is final.** The most common Laravel deploy footgun, and the reason task 2's snippet orders `.env` setup before `config:cache` and task 4's rollback snippet clears the cache before rebuilding it. A cached config silently ignores every subsequent `.env` edit until the next `config:clear`.
- **A rollback that crosses a schema-changing migration without rolling code back first.** Documented explicitly in task 4 — old code against a newer schema tolerates unknown columns; old code against a schema that just lost a column (because it was rolled back first) does not.
- **Running `db:seed` more than once.** `AdminUserSeeder` is idempotent by email (`AdminUserSeeder.php:21–23`, `firstOrNew`) and `DatabaseSeeder`'s other four members (`Category`/`Priority`/`Status`/`StatusTransitionSeeder`) are all `updateOrCreate`/prune-based — safe to re-run. Worth one line in the checklist so a deployer does not fear re-running step 3 after a partial failure.
- **`FRONTEND_URL` pointing at the wrong origin.** Surfaces as a browser-side CORS error on every write request, not a 4xx/5xx from the API — the single most likely "it works from curl but not the browser" support case this runbook will field, called out explicitly in the env var table.
- **Multiple app servers with `CACHE_STORE=database` and no `onOneServer()`.** Already flagged in the existing `## Scheduled tasks` section (**42–45**) — the env var table's `CACHE_STORE` row cross-references it rather than repeating the explanation.
- **A future migration that drops a column or table.** `migrate:rollback`'s safety claim in task 4 is conditional on every migration being additive, which is true today and stated as a condition, not a permanent guarantee — the note tells the next author what changes if that stops being true.

---

## Test Plan

This is a documentation story; "tests" means verifying every command and claim against the real codebase, which was done during planning (see the audit table's "Measured this session" rows) and must be re-verified once more at implementation time since the codebase may have moved.

1. **Frontend build:** `cd frontend && npm ci && npm run build` → exits 0; `ls frontend/dist/` shows `index.html` and `assets/`.
2. **Migration reversibility:** `grep -rL "public function down" backend/database/migrations/` → no output.
3. **CI does not set `APP_VERSION`:** `grep -n "APP_VERSION" .github/workflows/ci.yml` → no output (confirms the decision's premise still holds).
4. **Env var completeness:** every key in `backend/.env.example` has a row in the new table — diff the two manually; 35 keys in, 35 rows out.

---

## Verification Steps

1. **Frontend build, for real:** from `frontend/`, `npm ci && npm run build` → succeeds, confirm `dist/` output matches what's documented.
2. **Local rehearsal of the backend deploy sequence** (against the `local` environment, not production, since none exists): from `backend/`, `php artisan config:cache && php artisan route:cache` → both exit 0 with no errors, confirming no route uses a closure (which `route:cache` cannot serialize) and no config file throws when cached.
3. **Undo the local rehearsal:** `php artisan config:clear && php artisan route:clear` — leave the local dev environment as it was found.
4. **Rollback command sanity check:** on a disposable local database (`docker compose down -v && docker compose up -d --wait` from the repo root, then `php artisan migrate` from `backend/`), run `php artisan migrate:rollback --step=1` → succeeds, then `php artisan migrate` again → succeeds, confirming the last migration's `down()` and `up()` are both consistent.
5. **Markdown renders cleanly:** open `docs/deployment-runbook.md` in a Markdown preview and confirm every table and fenced code block renders correctly, in particular the environment-variable table (35 rows).
6. **Regression:** `git status` shows only `docs/deployment-runbook.md` changed; no application source file, migration, config file, or route file is touched.

---

## Done Criteria

- [ ] **AC1**: the frontend production build is documented with the exact command, its output path (`frontend/dist/`), and confirmation that a TypeScript error fails it — verified by an actual `npm run build` run, numbers quoted from that run.
- [ ] **AC2**: the deploy procedure covers migrations (`migrate --force`), config and route caching (`config:cache`/`route:cache`, with the cache-before-`.env`-is-final footgun called out), and names the queue worker and the scheduler as the two long-running processes a production deploy must supervise — the existing, already-correct `## Scheduled tasks` section is left untouched.
- [ ] **AC3**: every one of the 35 keys in `backend/.env.example` has a row in the new table with change/verify/leave guidance; `APP_DEBUG`'s row states plainly that the example ships `true` and production must set `false`.
- [ ] **AC4**: rollback steps name the actual command (`migrate:rollback`), are grounded in the verified fact that all 14 current migrations have a working `down()`, state the code-before-schema rollback ordering, and name the one condition (additive-only migrations) under which the safety claim holds.
- [ ] **AC5**: a first-deploy checklist exists, in dependency order, and its first and most emphasized item is setting a real `ADMIN_PASSWORD` before seeding — not the example's literal `password`.
- [ ] No application source file is changed — only `docs/deployment-runbook.md`.
- [ ] `## Scheduled tasks` (the one section that was already correct) is byte-identical before and after this story, confirmed by `git diff` showing no hunk touching those lines.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 55 (TM-64, security hardening pass).**
