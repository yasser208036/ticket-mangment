# ticket-mangment

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
| Composer | 2.x | installs `laravel/framework ^13.17` |
| Node.js | 22+ | Vite 8 and `tools/jira/upload.mjs` (ESM, `node:` imports) |
| Docker + Compose v2 | current | runs `mysql`, `mysql-test`, `mailpit` |
| Git | 2.x | this repository |

## Starting the stack

### 1. Services (repo root)

```bash
docker compose up -d
docker compose ps          # all three healthy before continuing
```

- `docker compose down` — stop the containers, **data survives**.
- `docker compose down -v` — stop **and wipe the database**. **This deletes all MySQL data in both containers.**

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
npm run dev        # http://localhost:5173
```

### Ports

| Service | URL / port | Source |
|---|---|---|
| Laravel API | http://localhost:8000 | `backend/.env.example` `APP_URL` |
| Vue dev server | http://localhost:5173 | `backend/.env.example` `FRONTEND_URL` |
| MySQL (app) | `127.0.0.1:3306` | `docker-compose.yml` `DB_PORT` |
| MySQL (tests) | `127.0.0.1:3307` | `docker-compose.yml` `DB_TEST_PORT` |
| Mailpit SMTP | `127.0.0.1:1025` | `docker-compose.yml` `MAILPIT_SMTP_PORT` |
| Mailpit web UI | http://localhost:8025 | `docker-compose.yml` `MAILPIT_UI_PORT` |

Each port is an overridable variable (`DB_PORT`, `DB_TEST_PORT`,
`MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`) — set it in your environment or `.env` if
a default is already bound on your machine.

**No mail leaves the machine in development** — Mailpit captures every outgoing
message. Read it at http://localhost:8025.

## Repository conventions

### Environment files

`backend/.env` and `tools/jira/.jira.env` are **git-ignored**. Copy them from
`backend/.env.example` and `tools/jira/.jira.env.example` respectively.
**Never commit a real token.**

### Tests

- **Backend:** `composer test` in `backend/`. Tests run against the
  `ticket_management_test` database on port 3307 (the second MySQL container), so
  the development database is never truncated.
- **Frontend:** `npx vitest` in `frontend/`.

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
