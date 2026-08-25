# foundation-environment — plan overview

Entry point for the **foundation-environment** feature. Stories execute in order by their `NN` prefix.

## Stories

| NN | File | Title | Tracker id | Depends on | Status |
|----|------|-------|------------|------------|--------|
| 01 | [`01-story-scaffold-monorepo-skeleton-TM-2.md`](01-story-scaffold-monorepo-skeleton-TM-2.md) | Scaffold the monorepo skeleton | TM-2 | — | ✅ Done — 2026-08-25, commit `5205b0c` |

## Dependency notes

- **Story 01 (TM-2) is the entry point for the whole project.** It runs `git init`, hardens the root `.gitignore`, writes the root `README.md`, and creates the three `docs/` placeholders. Every later story assumes the repository is under version control.
- **TM-3 ("Install and configure the Laravel 13 API", sprint 1) depends on Story 01.** It writes the `GET /api/v1/health` contract into `docs/api-contract.md`, which Story 01 creates. Plan and execute TM-3 only after Story 01 is merged.
- Story 01 touches no application source: `backend/app/`, `backend/routes/` and `frontend/src/` are untouched, so it cannot conflict with work in the **authentication-agent** feature folder.

## Status log

- **2026-08-25 — Story 01 (TM-2) implemented and committed** (`5205b0c`, branch `main`). `git init -b main` run at the repo root; root `.gitignore` hardened with an unanchored `vendor/` rule and verified with `git check-ignore`; root `README.md` written; the three `docs/` placeholders (`erd.md`, `api-contract.md`, `deployment-runbook.md`) created and tracked. First commit is clean — no `vendor/`, `node_modules/`, `.env`, or `tools/jira/.jira.env`. **TM-3 is now unblocked.**
