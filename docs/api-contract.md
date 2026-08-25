# API Contract

> **Placeholder.** The first real entry is `GET /api/v1/health`, added by
> TM-3 ("Install and configure the Laravel 13 API").

## Conventions

- Every route is versioned under `/api/v1` from the first endpoint onward.
- Requests and responses are JSON; errors follow Laravel's default validation
  envelope unless a story states otherwise.
- Authentication uses Laravel Sanctum (`laravel/sanctum ^4.0`, see
  `backend/composer.json`).

## Endpoints

| Method | Path | Purpose | Auth | Owning story |
|--------|------|---------|------|--------------|
| _(none yet)_ | | | | |
