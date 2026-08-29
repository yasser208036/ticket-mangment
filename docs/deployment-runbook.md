# Deployment Runbook

> Local development is documented in the root `README.md`, not here. `staging`
> and `production` hosts are not yet provisioned — see `## Environments`.

## Environments

| Environment | Host | Database | Notes |
|-------------|------|----------|-------|
| local | developer machine | `tm-mysql` container, port 3306 | see root `README.md` |
| staging | _TBD — no staging host is provisioned yet_ | _TBD_ | |
| production | _TBD — no production host is provisioned yet_ | _TBD_ | |

`staging`/`production` stay `_TBD_` deliberately — there is no Dockerfile, no
deploy workflow and no infrastructure-as-code anywhere in this repository.
Naming a specific host here would be inventing a decision nobody has made.

## Frontend production build

From `frontend/`:

```bash
npm ci
npm run build
```

`npm run build` runs `vue-tsc -b && vite build` (`frontend/package.json`) — **a
TypeScript error fails the build**, so this doubles as a type-check gate.
Output lands in **`frontend/dist/`** (Vite's default; nothing in
`vite.config.ts` overrides `build.outDir`): an `index.html` plus a
content-hashed `assets/` directory.

Serve `frontend/dist/` as static files from any web server or CDN. The SPA is
a client-side router (Vue Router in `history` mode) — **the web server must
rewrite every unmatched path back to `index.html`**, or a hard refresh on any
route other than `/` 404s. Set `VITE_API_BASE_URL` before building if the API
is not reachable at the default `/api/v1`.

## Deploy procedure

This is a two-artifact deployment: a static SPA build and a PHP application,
not a single container. They communicate over CORS
(`backend/config/cors.php`, `FRONTEND_URL` sets the allowed origin) and can be
deployed independently as long as `FRONTEND_URL` on the backend matches
wherever the frontend actually lands.

### Backend

From `backend/`, on the target host, after pulling the release:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env   # first deploy only — see the environment variable table below
php artisan migrate --force
php artisan config:cache
php artisan route:cache
```

`--force` is required outside `local`/`testing` — `ConfirmableTrait` prompts
otherwise and a non-interactive deploy would hang. **Run `config:cache` only
after every environment variable is final** — a cached config no longer reads
`.env` at all, and a var changed after caching is silently ignored until the
next `config:clear`/`config:cache` cycle.

Restart the queue worker after every deploy (see `## Queue worker` below) — a
long-running `queue:work` process holds the old code in memory and will not
pick up the new release on its own.

### Frontend

Build per the section above and publish `frontend/dist/` to wherever it is
served from. No server-side process to restart — static files only.

## Environment variables

Every key in `backend/.env.example`, grouped as that file groups them.
**Change** means the example value is unsafe or meaningless outside local
dev; **verify** means confirm it matches the target environment; **leave**
means the default is correct as-is.

| Key | Example value | Guidance |
|---|---|---|
| `APP_NAME` | `"Ticket Management"` | Leave, or change for branding — also the mail header/footer text. |
| `APP_ENV` | `local` | **Change to `production`.** Defaults to `production` if unset (`config/app.php`), but set it explicitly. |
| `APP_KEY` | _(blank)_ | **Change.** Generate with `php artisan key:generate` on first deploy; never reuse a key across environments — it encrypts sessions and any encrypted columns. |
| `APP_DEBUG` | `true` | **Change to `false`.** Defaults to `false` in code but the example ships `true` — a `.env` copied carelessly leaks stack traces and file paths to every API error. |
| `APP_URL` | `http://localhost:8000` | **Change** to the API's real public URL. |
| `APP_VERSION` | `0.1.0` | **Change on every deploy.** CI does **not** set this (`grep -n APP_VERSION .github/workflows/ci.yml` → no output, despite a code comment claiming otherwise); export it from the release tag or commit SHA before `config:cache`, e.g. `export APP_VERSION=$(git describe --tags --always)`. Reported by `GET /api/v1/health`. |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | `en` / `en` / `en_US` | Leave. |
| `APP_MAINTENANCE_DRIVER` | `file` | Leave, unless running more than one app server — then switch to `cache` so `artisan down` is visible to every server. |
| `BCRYPT_ROUNDS` | `12` | Leave — `phpunit.xml` overrides to `4` for test speed only; production keeps `12`. |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_DEPRECATIONS_CHANNEL` | `stack` / `single` / `null` | Verify the target host can write to `storage/logs/`, or switch `LOG_CHANNEL` to `errorlog`/`syslog` for a managed host with no persistent disk. |
| `LOG_LEVEL` | `debug` | **Change to `error` or `warning`.** `debug` in production is noisy and can log request payloads. |
| `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` | `mysql` / `127.0.0.1` / `3306` / `ticket_management` / `ticket_user` / `secret` | **Change every value** to the production database. **Never reuse the example password.** |
| `DB_TEST_HOST`/`DB_TEST_PORT`/`DB_TEST_DATABASE` | — | **Do not set in production** — these exist only so `.env.example` documents the second container; `phpunit.xml` supplies its own test-DB values independent of this file. |
| `SESSION_DRIVER`/`SESSION_LIFETIME`/`SESSION_ENCRYPT`/`SESSION_PATH`/`SESSION_DOMAIN` | `database` / `120` / `false` / `/` / `null` | Leave — the `sessions` table exists, but the API is bearer-token only and does not exercise session middleware today. |
| `BROADCAST_CONNECTION` | `log` | Leave, unless a real-time feature is added later. |
| `FILESYSTEM_DISK` | `local` | **Verify.** `local` writes to `storage/app/`, which must be a persistent, writable path on the target host — not ephemeral container storage if the host ever becomes containerized. |
| `QUEUE_CONNECTION` | `database` | Leave — pairs with the `jobs` table and the queue-worker process below. |
| `CACHE_STORE` | `database` | Leave for a single app server. **Change to a shared store (e.g. `redis`)** if ever running more than one — `Schedule::withoutOverlapping()` locks via the cache, and a per-server cache defeats that lock across servers. |
| `MAIL_MAILER`/`MAIL_SCHEME`/`MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD` | Mailpit values | **Change every value** to the production SMTP provider. Mailpit only exists in `docker-compose.yml`'s local services. |
| `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` | `helpdesk@ticket-management.test` / app name | **Change** the address to a real, deliverable sender domain. |
| `MAIL_SAFE_SMTP_HOSTS` | `127.0.0.1,localhost,::1,mailpit` | Leave. `App\Services\MailSafety` only guards `local` and `testing` — production mail is never blocked by this list. |
| `FRONTEND_URL` | `http://localhost:5173` | **Change** to the deployed SPA's real origin. Feeds CORS and every staff-facing notification's deep link — a stale value here blocks every write request from the real frontend with a CORS error, not an obvious API error. |
| `SANCTUM_STATEFUL_DOMAINS` | `localhost:5173,127.0.0.1:5173` | Verify it matches `FRONTEND_URL`'s host for consistency, though `supports_credentials: false` (`config/cors.php`) means this app's actual auth flow does not depend on it. |
| `ADMIN_NAME` | `Admin` | Leave, or change for branding. |
| `ADMIN_EMAIL` | `admin@ticket-management.test` | **Change** to a real, monitored address. |
| `ADMIN_PASSWORD` | `password` | **Change. Mandatory — see the first-deploy checklist below.** The seeder aborts if blank but does **not** reject the literal example value; a deploy that copies `.env.example` verbatim ships a publicly-known password. |
| `NOTIFY_REQUESTER_STATUSES` | `in-progress,pending,resolved,closed,reopened` | Leave, unless the team wants a different set of status-change emails to reach requesters. |
| `NOTIFY_REQUESTER_DELAY_SECONDS` | `300` | Leave. Widen during an incident if the mail backend is struggling; the change applies to jobs queued after the edit, not ones already waiting. |
| `NOTIFY_ESCALATING_ADMIN` | `true` | Leave, unless the team wants an admin who escalates a ticket to stop receiving their own escalation email. |
| `NOTIFY_ESCALATION_TOKEN` | `ESCALATED` | Leave, unless an admin mail rule needs a different filter prefix — changing it is a deploy, not a code change. |
| `NOTIFY_TRIES` / `NOTIFY_BACKOFF_FIRST` / `NOTIFY_BACKOFF_SECOND` | `3` / `60` / `300` | Leave. Widenable during an incident without a deploy. |
| `NOTIFY_TIMEOUT` | `30` | Leave. **Must stay below `DB_QUEUE_RETRY_AFTER` (90, not itself an env var — see `config/queue.php`)** or a slow job can be picked up by a second worker while the first is still running it. |
| `TICKETS_STALE_AFTER_HOURS` | `48` | Verify against the team's actual SLA. |
| `TICKETS_STALE_NOTIFY` | `false` | Leave `false` — no listener exists for `TicketsFlaggedStale` yet; setting `true` dispatches an event nothing consumes. |

## Rollback procedure

### Code

Redeploy the previous release's artifacts (backend + frontend build) the same
way as a forward deploy — there is no automated release history to revert to
in this repository; the deploy mechanism is whatever host process placed the
current code.

### Migrations

Every migration in `backend/database/migrations/` has a working `down()`
(verified — `grep -rL "public function down" backend/database/migrations/`
returns no output, across all 14 current migrations). To reverse the most
recent migration batch:

```bash
php artisan migrate:rollback --step=1   # reverses the last migration only
php artisan migrate:rollback            # reverses the entire last batch
```

**Roll back code before rolling back the migration**, not after — the
previous release's code does not know about columns a newer migration added,
so running old code against a newer schema is the safer of the two
mismatches; running old code against an *older*, rolled-back schema while the
newer code is still deployed guarantees column-not-found errors on the
first request.

`migrate:rollback` is **destructive** for any migration that drops a column
or table in its `up()` — reversing it recreates the structure but not the
data. None of this schema's 14 migrations does that today (all are
additive: `create_*` or `add_*_to_*`), so a rollback here is safe as long as
that remains true; **a future migration that drops a column must ship its
own data-preserving rollback plan, not rely on this note.**

### Cache

After any rollback, clear and rebuild the config/route cache so cached values
from the rolled-back code are not served by the reverted app:

```bash
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
```

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
5. Check `php artisan queue:failed` — a permanently failed notification also
   writes a `Log::error` naming the ticket reference, so an empty list here
   and a clean grep for that line in the logs both mean mail delivery is
   healthy.

## First-deploy checklist

Order matters — each step depends on the one before it:

1. **Set every environment variable from the table above**, especially
   `APP_KEY` (`php artisan key:generate`), `APP_DEBUG=false`, and a
   **real, unique `ADMIN_PASSWORD`** — never the example's literal `password`.
2. `php artisan migrate --force` — creates every table including
   `ticket_sequences`, `ticket_activities`, `status_transitions`, `jobs` and
   `failed_jobs`.
3. `php artisan db:seed --force` — runs `DatabaseSeeder`: `AdminUserSeeder`,
   `CategorySeeder`, `PrioritySeeder`, `StatusSeeder`, `StatusTransitionSeeder`.
   **This seeds master data and the one admin account only — no sample
   tickets, no demo data.** `AdminUserSeeder` aborts with
   `RuntimeException: ADMIN_PASSWORD is empty` if step 1 was skipped.
4. Log in as `ADMIN_EMAIL`/`ADMIN_PASSWORD` through the deployed frontend and
   change the password immediately via `PATCH /api/v1/auth/password` — the
   value that seeded the account should not remain the long-term credential,
   even though it was never the example's `password`.
5. Create any additional agent/admin accounts needed via
   `POST /api/v1/admin/users` (admin bearer required) — see
   `docs/api-contract.md`'s admin users section for the request shape.
6. Confirm the production SMTP credentials (`MAIL_*`) are correct — mail is
   **not** guarded in production (`App\Services\MailSafety` only guards
   `local` and `testing`), so a wrong `MAIL_HOST` here silently fails
   deliveries rather than throwing.
7. Run the post-deploy checks above.

**Re-running `db:seed` is safe.** `AdminUserSeeder` is idempotent by email
(`firstOrNew`) and the other four seeders are all `updateOrCreate`/prune-based
— a partial failure can be retried from step 3 without duplicating data.

## Queue worker

Every notification is queued (`QUEUE_CONNECTION=database`), so an environment
with no running worker accepts tickets normally and **silently delivers no
mail**. Each deployed environment needs:

- a supervised long-running `php artisan queue:work` process, and
- `php artisan queue:restart` in the deploy procedure, after the new code is in
  place, so workers pick it up.

Run the worker with **no `--tries` flag** — each notification declares its own
`tries`, `backoff` and `timeout` via `App\Notifications\Concerns\HasRetryPolicy`
(`config/notifications.php`'s `retry` block), and a `--tries` on the command
line overrides what the notification declared.

`App\Services\MailSafety` guards `local` and `testing` only — **production mail
is not guarded and will reach real inboxes.** Confirm `MAIL_MAILER`,
`MAIL_HOST`/`MAIL_URL` and `MAIL_FROM_ADDRESS` before the first production
deploy.

Use a process manager (systemd, Supervisor, or the host's equivalent) so a
crashed worker restarts rather than silently stopping delivery — none is
prescribed here, as none is chosen yet. Check `php artisan queue:failed` as
part of post-deploy checks — a permanently failed notification also writes a
`Log::error` naming the ticket reference, so a clean failed-jobs list and a
clean grep of that log line both mean mail delivery is healthy. `failed_jobs`
rows are not retried automatically; `php artisan queue:retry <uuid>` (or
`all`) is a manual, deliberate action.

## Scheduled tasks

The application scheduler runs one command. It is inert unless the host invokes
Laravel's scheduler every minute:

```cron
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

| Command | When | Purpose |
|---|---|---|
| `tickets:flag-stale` | daily, 02:00 | Writes a `stale` activity row on every non-terminal ticket idle longer than `TICKETS_STALE_AFTER_HOURS`. |

`tickets:flag-stale` is idempotent — a missed run costs nothing and a doubled run
writes nothing twice. Run it with `--dry-run` to see what it would flag.

**`onOneServer()` is not set.** If the app is ever deployed to more than one
host, add it to the schedule entry **and** point `CACHE_STORE` at a store shared
between them, or every host will run the command. Both are decisions for this
runbook, not for the command.
