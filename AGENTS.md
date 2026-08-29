# Repository Guidelines

## Project Structure & Module Organization

This monorepo contains two applications and Docker-managed services:

- `backend/`: Laravel 13 JSON API. Application code is under `app/`, routes under `routes/`, migrations and seeders under `database/`, and PHPUnit tests under `tests/`.
- `frontend/`: Vue 3 SPA using TypeScript, Pinia, Vue Router, axios, and Vite. Source lives in `src/`; API modules, stores, views, and router configuration have dedicated folders.
- `docs/`: API contract, ERD, and deployment docs.
- `tools/jira/`: backlog synchronization scripts. Edit `backlog.json`, not Jira issues manually.
- `.squad/`: story intakes and implementation plans. Treat plans as read-only unless revision is requested.

## Build, Test, and Development Commands

Start infrastructure from repository root:

```bash
docker compose up -d
docker compose ps
```

Backend commands run from `backend/`:

- `composer setup`: install dependencies, configure environment, migrate, and build assets.
- `php artisan serve`: serve API at `http://localhost:8000`.
- `composer test`: clear cached config and run PHPUnit against test MySQL on port 3307.
- `./vendor/bin/pint --test`: check PHP formatting.

Frontend commands run from `frontend/`:

- `npm run dev`: serve SPA at fixed port 5173 with `/api` proxying.
- `npm run typecheck`: run `vue-tsc`.
- `npm test`: run Vitest once.
- `npm run build`: type-check and create production bundle.

## Coding Style & Naming Conventions

Use four spaces for PHP and two spaces for TypeScript, Vue, JSON, and CSS. Follow Laravel conventions and format PHP with Pint. Vue components use PascalCase (`HealthView.vue`); TypeScript modules and Pinia stores use descriptive lowercase names (`api/health.ts`, `stores/health.ts`). Keep API v1 controllers under `App\Http\Controllers\Api\V1`. Prefer small functions, explicit types, type-only imports, and existing framework helpers.

## Testing Guidelines

Backend feature tests use PHPUnit and names such as `HealthTest.php`; test methods start with `test_`. Database tests must use real MySQL, never SQLite. Frontend tests use Vitest and Vue Test Utils; name files `*.spec.ts` beside their modules. Cover success, degraded API responses, and transport errors.

## Commit & Pull Request Guidelines

History uses imperative subjects with Jira keys, for example `Scaffold the monorepo skeleton (TM-2)`. Keep commits scoped to one story. Pull requests must link the TM issue, summarize behavior, list verification commands, and include screenshots for UI changes. Call out environment, migration, or API-contract changes.

## Security & Configuration

Copy tracked `.env.example` files to ignored `.env` files. Never commit tokens or real credentials. Keep bearer-token authentication separate from cookie/session assumptions. Use `docker compose down -v` only when database deletion is intended.
