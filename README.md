# ticket-mangment

[![CI](https://github.com/yasser208036/ticket-mangment/actions/workflows/ci.yml/badge.svg)](https://github.com/yasser208036/ticket-mangment/actions/workflows/ci.yml)

A ticket-management system split into two apps: a **Laravel 13 JSON API** in
[`backend/`](backend/) and a **Vue 3 + Vite SPA** in [`frontend/`](frontend/),
with **MySQL 8** and **Mailpit** supplied by Docker Compose. The Laravel app and
the Vue dev server run on the host and connect to the containers.

## Layout

```
ticket-mangment/
├── backend/            Laravel 13 API (PHP 8.3+, MySQL 8, Sanctum)
├── frontend/           Vue 3 SPA (Vite, TypeScript, Pinia, Vue Router)
├── docs/               ERD, API contract, deployment runbook
├── tools/
│   └── jira/           Backlog upload script (tools/jira/upload.mjs)
├── docker-compose.yml  MySQL, a separate test MySQL, and Mailpit
├── .gitignore
└── README.md
```

## Prerequisites

| Tool | Version | Why |
|---|---|---|
| PHP | 8.3+ | `backend/composer.json` requires `"php": "^8.3"` |
| PHP `pdo_mysql` | matching PHP | MySQL driver required by migrations and tests. Ubuntu/Debian: `sudo apt-get install php8.3-mysql`. |
| Composer | 2.x | installs `laravel/framework ^13.17` |
| Node.js | 22+ | Vite 8 and `tools/jira/upload.mjs` (ESM, `node:` imports) |
| Docker + Compose v2 | current | runs `mysql`, `mysql-test`, `mailpit` |
| Git | 2.x | this repository |

## Starting the stack

### 1. Services (repo root)

```bash
docker compose up -d --wait
```

`--wait` returns only after every healthcheck passes. Use `docker compose ps`
when starting without it.

| Command | Effect |
|---|---|
| `docker compose ps` | show container health |
| `docker compose stop` | stop containers; keep containers and data |
| `docker compose down` | remove containers; keep named-volume data |
| `docker compose down -v` | remove containers and both databases |

Reset schema and seed data from `backend/`:

```bash
php artisan migrate:fresh --seed
```

Full reset, including volumes:

```bash
docker compose down -v
docker compose up -d --wait
cd backend && php artisan migrate --seed
```

Database names and credentials apply only on a volume's first boot. Changing
them against an existing volume makes healthchecks fail because MySQL retains
old values. Run full reset after changing `DB_DATABASE`, `DB_TEST_DATABASE`,
`DB_USERNAME`, or `DB_PASSWORD`.

### Demo data

```bash
php artisan migrate:fresh --seed                    # schema + admin + master data
php artisan db:seed --class=DemoSeeder              # ~50 demo tickets
```

`DemoSeeder` is **local only** — it throws in production, and `--force` does
not bypass that check. It also **refuses to run twice**; reset with
`migrate:fresh --seed` before seeding it again. Demo logins are
`<name>@<DEMO_EMAIL_DOMAIN>` (default `demo.test`) with password
`DEMO_PASSWORD` (default `password`).

### 2. Backend (`backend/`)

```bash
cd backend
composer setup     # install, copy .env, key:generate, migrate, build assets
php artisan serve  # http://localhost:8000
```

`composer setup` is a script defined in `backend/composer.json`. It creates
`.env` from `.env.example` **only if `.env` is absent**, then generates the app
key, runs migrations, and builds the Blade-side assets. It runs
`php artisan migrate --force`, so the MySQL container from step 1 must be
healthy first.

### 3. Frontend (`frontend/`)

```bash
cd frontend
npm install
cp .env.example .env   # API base URL and development proxy target
npm run dev        # http://localhost:5173
```

Dev server is pinned to port **5173** and proxies `/api` to Laravel, keeping
development API requests same-origin.

### Ports

| Service | URL / port | Source |
|---|---|---|
| Laravel API | http://localhost:8000 | `backend/.env.example` `APP_URL` |
| Vue dev server | http://localhost:5173 | `backend/.env.example` `FRONTEND_URL` |
| MySQL (app) | `127.0.0.1:3306` | `docker-compose.yml` `DB_PORT` |
| MySQL (tests) | `127.0.0.1:3307` | `docker-compose.yml` `DB_TEST_PORT` |
| Mailpit SMTP | `127.0.0.1:1025` | `docker-compose.yml` `MAILPIT_SMTP_PORT` |
| Mailpit web UI | http://localhost:8025 | `docker-compose.yml` `MAILPIT_UI_PORT` |

Compose reads only root `.env`, copied from `.env.example`; it does not read
`backend/.env`. Pair overrides with app configuration:

| Root variable | Also change |
|---|---|
| `DB_PORT` | `DB_PORT` in `backend/.env` |
| `DB_TEST_PORT` | `DB_PORT` in `backend/phpunit.xml` |
| `DB_TEST_DATABASE` | `DB_DATABASE` in `backend/phpunit.xml` |
| `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | matching `backend/.env`, then full reset |
| `MAILPIT_SMTP_PORT` | `MAIL_PORT` in `backend/.env` |
| `DB_ROOT_PASSWORD`, `MAILPIT_UI_PORT` | containers only |

**No mail leaves the machine in development** — Mailpit captures every outgoing
message. Read it at http://localhost:8025.

## Queue and mail

Notifications are queued (`QUEUE_CONNECTION=database`), so dispatching one
writes a row to the `jobs` table and the request returns without sending
anything. **Nothing is delivered unless a worker is running.**

```bash
cd backend
php artisan queue:work         # process jobs until stopped
php artisan queue:work --once  # process exactly one job, then exit
php artisan queue:listen       # same, but reloads code between jobs
```

`composer dev` (`php artisan dev`) **already starts a worker** alongside
`php artisan serve`, Pail and the Vite dev server — it runs
`queue:listen --tries=1 --timeout=0`. Two consequences:

- Do not start a second worker on top of it; two workers race for the same rows.
- **Under `composer dev` a failing job goes straight to `failed_jobs` with no
  retry**, because of that `--tries=1`. Run `php artisan queue:work` when you
  want the retry behaviour a job actually declares.

`queue:work` holds a booted application in memory, so **restart it after editing
job or mailable code**. `php artisan queue:restart` asks running workers to exit
after their current job.

### Reading captured mail

Every outgoing message goes to Mailpit: **http://localhost:8025**. Nothing
reaches a real inbox from a development machine, and not only because Mailpit is
the configured SMTP host — `App\Services\MailSafety` listens on
`Illuminate\Mail\Events\MessageSending` and **refuses** the send when `APP_ENV`
is `local` or `testing` and the mailer is anything other than:

- the `log` or `array` transport (they cannot deliver anywhere), or
- `smtp` pointed at a host in `MAIL_SAFE_SMTP_HOSTS` (`127.0.0.1`, `localhost`,
  `::1`, `mailpit` by default).

Point `MAIL_HOST` — or `MAIL_URL`, which overrides it — at a real relay and the
send throws before a socket is opened. Production is not guarded.

### Failed jobs

A job that exhausts its attempts is inserted into `failed_jobs` with its uuid,
connection, queue, full serialised payload and the complete exception and stack
trace. It is not retried automatically.

| Command | Effect |
|---|---|
| `php artisan queue:failed` | list failed jobs with uuid, connection, queue and time |
| `php artisan queue:retry <uuid>` | push one failed job back onto the queue |
| `php artisan queue:retry all` | push every failed job back |
| `php artisan queue:forget <uuid>` | delete one failed job |
| `php artisan queue:flush` | delete every failed job |
| `php artisan queue:prune-failed --hours=48` | delete failed jobs older than 48 hours |

Retrying deletes the `failed_jobs` row and inserts a fresh `jobs` row; a worker
must be running for it to be processed.

## Repository conventions

### Environment files

| File | Purpose |
|---|---|
| `.env` | Optional Compose overrides; copy from root `.env.example` |
| `backend/.env` | Laravel configuration; required |
| `frontend/.env` | Vite API and proxy configuration |
| `tools/jira/.jira.env` | Jira upload credentials |

All are git-ignored. Copy from tracked `.example` files. Root `.env` configures
containers; `backend/.env` configures Laravel. Never commit real tokens.

### Tests

- **Backend:** `composer test` in `backend/`. Tests run against the
  `ticket_management_test` database on port 3307 (the second MySQL container), so
  the development database is never truncated.
  The suite runs migrations against MySQL 8 on every run via `RefreshDatabase`.
- **Frontend:** `npm test` in `frontend/` (`npm run test:watch` while working).

### Code quality

```bash
cd backend  && composer lint
cd frontend && npm run lint
cd frontend && npm run format:check
cd frontend && npm run typecheck
```

Fix with `composer lint:fix`, `npm run lint:fix`, and `npm run format`.

### Jira tooling

The backlog is pushed to Jira with `tools/jira/upload.mjs`:

```bash
node tools/jira/upload.mjs --dry-run       # preview, writes nothing
node tools/jira/upload.mjs                 # create the backlog
node tools/jira/upload.mjs --sync-points   # backfill story points
```

Credentials come from `tools/jira/.jira.env`; create the API token at
https://id.atlassian.com/manage-profile/security/api-tokens.

### Planning

Implementation plans live in [`.squad/plans/`](.squad/plans/); story intakes live
in [`.squad/stories/`](.squad/stories/). The placeholder docs are
[`docs/erd.md`](docs/erd.md), [`docs/api-contract.md`](docs/api-contract.md), and
[`docs/deployment-runbook.md`](docs/deployment-runbook.md).
