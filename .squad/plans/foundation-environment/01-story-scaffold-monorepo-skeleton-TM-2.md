# Story 01 — Scaffold the monorepo skeleton (Story: TM-2)

## Prerequisites

- None. This is the first story in the global sequence and the first story of the **foundation-environment** feature.
- **Story 02 (TM-3, "Install and configure the Laravel 13 API") depends on this story.** It assumes the repository is under version control and that `docs/api-contract.md` exists to receive the `/api/v1/health` contract. Do not start TM-3 until this story is merged.
- No coordination with other owners is required: every file touched here is created new or is the root `.gitignore`, which no other in-flight story edits.

---

## Story Goal

Turn the current loose directory `/home/yasser-mohamed/ticket-mangment` into a version-controlled monorepo with a predictable, documented layout so every developer starts from the same place.

Four user-visible outcomes:

1. `git init` has been run at the repository root and the **first commit** contains the source tree **without** `backend/vendor/`, any `node_modules/`, any `.env`, or `tools/jira/.jira.env`.
2. A root `README.md` documents the four top-level directories, the prerequisite toolchain, and the exact commands that bring the stack up.
3. `docs/` — currently **empty** — holds three committed placeholder documents: the ERD, the API contract, and the deployment runbook.
4. `git check-ignore` proves that `tools/jira/.jira.env` is ignored while `tools/jira/.jira.env.example` remains tracked.

**Not in scope:** installing Laravel or Node dependencies, wiring a CI pipeline, adding a root `package.json` or workspace manager, creating a remote on GitHub/GitLab, or writing any application code. The three `docs/` files are **placeholders with a fixed heading skeleton** — filling them in belongs to the stories that produce the schema, the API and the deploy process.

---

## Context — Read These Files First

1. `.gitignore` — the root ignore file **already exists**, 49 lines. Read all of it. Note the five existing blocks: secrets (~lines 1–5), Jira upload state (~lines 7–8), PHP/Laravel (~lines 10–21), Node/Vue (~lines 23–28), editors/OS/logs (~lines 30–40), and the **squad-kit managed block** (~lines 42–49). The squad-kit block is marked `# Managed by squad-kit — do not edit this block` — **do not** add entries inside it or reorder it.
2. `docker-compose.yml` — read the header comment (~lines 1–7) and the three service blocks. The published host ports the README must document are `${DB_PORT:-3306}` (~line 20), `${DB_TEST_PORT:-3307}` (~line 46), `${MAILPIT_SMTP_PORT:-1025}` and `${MAILPIT_UI_PORT:-8025}` (~lines 65–66). Service names are `mysql`, `mysql-test`, `mailpit`; container names are `tm-mysql`, `tm-mysql-test`, `tm-mailpit`.
3. `backend/composer.json` — read `require` (~lines 8–13) for the PHP floor `"php": "^8.3"` and `"laravel/framework": "^13.17"`, and the `scripts` block (~lines 34–45) for the `setup`, `dev` and `test` composer scripts the README will reference.
4. `backend/.env.example` — read the variables the README lists as configuration touchpoints: `APP_URL` (line 5), `DB_*` (lines 21–26), `DB_TEST_*` (lines 30–32), `MAIL_*` (lines 50–57), `FRONTEND_URL` (line 60), `SANCTUM_STATEFUL_DOMAINS` (line 61). Confirm the mail port `1025` and DB ports match `docker-compose.yml`.
5. `frontend/package.json` — read `scripts` (`dev`, `build`, `preview`) and note the stack the README describes: Vue 3.5, Vite 8, TypeScript, Pinia, Vue Router, Vitest.
6. `tools/jira/upload.mjs` — read the header docblock (~lines 1–14). It documents the three invocations and names the four credentials that live in `tools/jira/.jira.env`. The README's tooling section restates these.
7. `tools/jira/.jira.env.example` — 9 lines. This file **is committed**; its sibling `.jira.env` is not. Understand the difference before touching `.gitignore`.
8. `.squad/config.yaml` — confirms `project.projectRoots: ['.']`, `tracker.type: jira`, `naming.includeTrackerId: true`, `naming.globalSequence: true`.
9. Run `ls -la docs/` and confirm it is **empty** (only `.` and `..`). An empty directory cannot be committed by git — this is why task 3 exists.
10. Run `ls -d .git` and confirm it reports **no such file or directory** before starting task 1. If `.git` already exists, **stop and report to the user** rather than re-initialising.

---

## Implementation tasks

No backend changes required. No frontend changes required. Every task below creates a new file, edits the root `.gitignore`, or runs a git command.

### 1 — Initialise the repository

Run from the repository root `/home/yasser-mohamed/ticket-mangment`:

```bash
git init -b main
git config user.name  "<the developer's name>"
git config user.email "<the developer's email>"
```

Use `-b main` so the default branch is **main**, not the git 2.43 fallback `master`. Do **not** stage or commit yet — the ignore rules must be proven correct first (task 2), otherwise `backend/vendor/` lands in history and has to be rewritten out.

**Do not** run `git init` inside `backend/` or `frontend/`. This is a single-repository monorepo, not a submodule layout.

### 2 — Harden the root `.gitignore`

**File: `.gitignore`**

The existing 49 lines already satisfy most of the acceptance criteria. Make exactly one addition and verify the rest — **do not** rewrite or reorder the file, and **do not** touch lines 42–49 (the squad-kit managed block).

Add an unanchored `vendor/` rule directly beneath the existing anchored backend rule so that any Composer vendor directory added later — not only `backend/vendor/` — is ignored. Insert after line 11:

```gitignore
# PHP / Laravel
/backend/vendor/
vendor/
/backend/storage/*.key
```

Verify — do not assume — that each acceptance-criterion path is already covered by the existing rules:

| Path | Covering rule | Line |
|---|---|---|
| `backend/vendor/` | `/backend/vendor/` | 11 |
| any `node_modules/` | `node_modules/` | 24 |
| `backend/.env` | `.env` | 2 |
| `tools/jira/.jira.env` | `tools/jira/.jira.env` | 5 |
| `frontend/dist/` | `/frontend/dist/` | 25 |
| `backend/public/build/` | `/backend/public/build/` | 17 |
| `tools/jira/created-issues.json` | `tools/jira/created-issues.json` | 8 |

Two negations must survive the edit and must be checked explicitly:

- `backend/.env.example` is matched by `.env.*` (line 3) and re-included by `!.env.example` (line 4). It **must** stay tracked.
- `tools/jira/.jira.env.example` is matched by **neither** `.env.*` nor `tools/jira/.jira.env` — the pattern `.env.*` requires the name to begin with `.env.`, and `.jira.env.example` does not. It **must** stay tracked. Confirm with `git check-ignore` in the Verification Steps rather than by reading the patterns.

### 3 — Create the three `docs/` placeholders

`docs/` exists but is empty, so git will not record it. These three files make the directory real in history and give later stories a fixed home.

**Create file: `docs/erd.md`**

```markdown
# Entity-Relationship Diagram

> **Placeholder.** Filled in by the data-model stories. Until then this file exists
> so `docs/` is tracked and the target location is unambiguous.

## Scope

The MySQL 8 schema behind the ticket-management app: users and roles, tickets,
comments, attachments, and the audit trail.

## Diagram

_Add the diagram here (Mermaid `erDiagram` preferred so it renders in the tracker
and on the git host without an image asset)._

## Table notes

| Table | Purpose | Owning story |
|-------|---------|--------------|
| _(none yet)_ | | |
```

**Create file: `docs/api-contract.md`**

```markdown
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
```

**Create file: `docs/deployment-runbook.md`**

```markdown
# Deployment Runbook

> **Placeholder.** Filled in by the deployment story. Local development is
> documented in the root `README.md`, not here.

## Environments

| Environment | Host | Database | Notes |
|-------------|------|----------|-------|
| local | developer machine | `tm-mysql` container, port 3306 | see root `README.md` |
| staging | _TBD_ | _TBD_ | |
| production | _TBD_ | _TBD_ | |

## Deploy procedure

_Steps go here._

## Rollback procedure

_Steps go here._

## Post-deploy checks

_Checks go here._
```

Keep the `> **Placeholder.**` blockquote as the first content line of each file. It tells a reader — and a later agent — that the document is intentionally unfinished rather than lost.

### 4 — Write the root README

**Create file: `README.md`**

The README has five required sections, in this order. Every command below must be **copy-pasteable** and every port must match `docker-compose.yml`.

**Section 1 — Title and one-paragraph summary.** Name the project (`ticket-mangment`) and state the split: a Laravel 13 JSON API in `backend/`, a Vue 3 + Vite SPA in `frontend/`, with MySQL 8 and Mailpit supplied by Docker Compose.

**Section 2 — Layout.** A fenced tree covering exactly the four top-level directories plus the root files:

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

**Section 3 — Prerequisites.** A table. Pull the versions from the manifests, not from memory:

| Tool | Version | Why |
|---|---|---|
| PHP | 8.3+ | `backend/composer.json` requires `"php": "^8.3"` |
| Composer | 2.x | installs `laravel/framework ^13.17` |
| Node.js | 22+ | Vite 8 and `tools/jira/upload.mjs` (ESM, `node:` imports) |
| Docker + Compose v2 | current | runs `mysql`, `mysql-test`, `mailpit` |
| Git | 2.x | this repository |

**Section 4 — Starting the stack.** Three numbered steps, each with its working directory stated:

1. **Services** (repo root):

   ```bash
   docker compose up -d
   docker compose ps          # all three healthy before continuing
   ```

   Document `docker compose down` (stops, keeps data) and `docker compose down -v` (stops and **wipes the database**) exactly as the `docker-compose.yml` header comment does. Bold the warning on `-v`.

2. **Backend** (`backend/`):

   ```bash
   cd backend
   composer setup     # install, copy .env, key:generate, migrate, build assets
   php artisan serve  # http://localhost:8000
   ```

   Note that `composer setup` is a script defined in `backend/composer.json` and that it creates `.env` from `.env.example` only if `.env` is absent.

3. **Frontend** (`frontend/`):

   ```bash
   cd frontend
   npm install
   npm run dev        # http://localhost:5173
   ```

Follow the three steps with a ports table:

| Service | URL / port | Source |
|---|---|---|
| Laravel API | http://localhost:8000 | `backend/.env.example` `APP_URL` |
| Vue dev server | http://localhost:5173 | `backend/.env.example` `FRONTEND_URL` |
| MySQL (app) | `127.0.0.1:3306` | `docker-compose.yml` `DB_PORT` |
| MySQL (tests) | `127.0.0.1:3307` | `docker-compose.yml` `DB_TEST_PORT` |
| Mailpit SMTP | `127.0.0.1:1025` | `docker-compose.yml` `MAILPIT_SMTP_PORT` |
| Mailpit web UI | http://localhost:8025 | `docker-compose.yml` `MAILPIT_UI_PORT` |

State plainly that **no mail leaves the machine in development** — Mailpit captures everything, matching the comment at `docker-compose.yml` line 59.

**Section 5 — Repository conventions.** Four short subsections:

- **Environment files.** `backend/.env` and `tools/jira/.jira.env` are **git-ignored**. Copy from `backend/.env.example` and `tools/jira/.jira.env.example`. **Never commit a real token.**
- **Tests.** Backend: `composer test` in `backend/` (runs against `ticket_management_test` on port 3307, so the development database is never truncated). Frontend: `npx vitest` in `frontend/`.
- **Jira tooling.** Restate the three invocations from the `tools/jira/upload.mjs` docblock:

  ```bash
  node tools/jira/upload.mjs --dry-run       # preview, writes nothing
  node tools/jira/upload.mjs                 # create the backlog
  node tools/jira/upload.mjs --sync-points   # backfill story points
  ```

  Credentials come from `tools/jira/.jira.env`; the token is created at `https://id.atlassian.com/manage-profile/security/api-tokens`.
- **Planning.** Implementation plans live in `.squad/plans/`; story intakes live in `.squad/stories/`. Link `docs/erd.md`, `docs/api-contract.md` and `docs/deployment-runbook.md` with relative links so the placeholders are discoverable.

### 5 — Make the first commit

Only after tasks 1–4 are complete and the Verification Steps below pass:

```bash
git add -A
git status --short          # inspect this list before committing
git commit -m "Scaffold the monorepo skeleton (TM-2)"
```

**Read the `git status --short` output before committing.** It must not contain any path under `backend/vendor/`, any `node_modules/`, `backend/.env`, `tools/jira/.jira.env`, or `tools/jira/created-issues.json`. If any of those appear, **do not commit** — fix `.gitignore` first. Unstaging a 30 MB `vendor/` tree from history is far more expensive than catching it here.

---

## Edge Cases & Failure Modes

- **`.git` already exists.** Re-running `git init` on an initialised repository is non-destructive but silently reuses the existing default branch, which may be `master`. Task 1 requires checking `ls -d .git` first; if it exists, stop and report rather than proceeding.
- **`vendor/` already committed before the ignore file is verified.** `.gitignore` has no effect on paths already tracked. This is the reason task 5 comes last and requires reading `git status --short` first. Recovery once committed means `git rm -r --cached backend/vendor` plus an amend, or a history rewrite — avoid it by ordering the work as specified.
- **The new unanchored `vendor/` rule over-matches.** An unanchored `vendor/` (task 2) ignores a directory named `vendor` at **any** depth, including a hypothetical `frontend/src/vendor/`. No such directory exists today (`find frontend/src` returns only `App.vue`, `main.ts`, `style.css`, `assets/`, `components/`). If one is ever added intentionally, un-ignore it with a targeted `!frontend/src/vendor/` rather than deleting the general rule.
- **`tools/jira/.jira.env.example` accidentally ignored.** A future edit that broadens line 5 to `tools/jira/.jira.env*` would silently drop the example file from the repository and leave new developers with no template. The `git check-ignore` assertion in Verification Step 3 is what catches this; keep it in the story's done criteria.
- **`docs/` stays empty.** Git does not track directories. If task 3 is skipped or the three files are created but not staged, a fresh clone has no `docs/` at all and TM-3 has nowhere to write the health-endpoint contract. Verification Step 4 asserts all three files are tracked, not merely present on disk.
- **Ports already bound on the developer's machine.** `docker compose up -d` fails with `bind: address already in use` when something already holds 3306, 3307, 1025 or 8025. Every port in `docker-compose.yml` is an overridable variable (`DB_PORT`, `DB_TEST_PORT`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`, lines 20/46/65/66). The README's ports table must show the defaults; the override mechanism is worth one sentence beneath the table.
- **The README drifts from `docker-compose.yml`.** Every port and service name in the README is copied from the compose file. When a port changes there, the README is stale and misleads the next developer. Verification Step 5 is a manual cross-read of the two files.
- **`composer setup` fails because MySQL is not up yet.** `composer setup` runs `php artisan migrate --force`, which needs a reachable database. The README's step order (services → backend → frontend) and the `docker compose ps` health check in step 1 exist specifically to prevent this. Do not reorder those steps.

---

## Test Plan

This story adds no application code, so there are no unit tests. Verification is a **repository-state smoke test**. Record each command and its expected output in the story's completion note.

1. **Smoke — repository initialised.** `git rev-parse --is-inside-work-tree` prints `true`; `git branch --show-current` prints `main`.
2. **Smoke — secrets are ignored.** `git check-ignore -v tools/jira/.jira.env backend/.env tools/jira/created-issues.json` lists a matching rule for all three paths and exits `0`.
3. **Smoke — examples are tracked.** `git check-ignore -v tools/jira/.jira.env.example backend/.env.example` prints **nothing** and exits `1` (the "no path is ignored" exit code). Exit `0` here is a **failure** for this story.
4. **Smoke — heavy directories are ignored.** `git check-ignore -v backend/vendor/autoload.php frontend/node_modules/vite/package.json backend/node_modules/x frontend/dist/index.html backend/public/build/manifest.json` matches a rule for every path.
5. **Smoke — docs placeholders are tracked.** After `git add -A`, `git ls-files docs/` lists exactly `docs/api-contract.md`, `docs/deployment-runbook.md`, `docs/erd.md`.
6. **Smoke — the first commit is clean.** `git ls-files | grep -E '(^|/)(vendor|node_modules)/'` returns no lines and exits `1`. `git ls-files | wc -l` is a three-digit number, not five figures — a five-figure count means `vendor/` or `node_modules/` slipped in.
7. **Manual — README accuracy.** Follow the README's "Starting the stack" section verbatim on a clean shell and confirm each of the three steps runs as written. This is the only test that proves the acceptance criterion "documents … how to start the stack".

No existing test files are modified or removed. `backend/tests/` and the Vitest setup in `frontend/` are untouched by this story.

---

## Verification Steps

1. **Repository state:** from the repo root, `git status --short` — the output contains no path under `backend/vendor/`, no `node_modules/`, no `.env`, and no `tools/jira/.jira.env`.
2. **Ignore rules:** from the repo root, `git check-ignore -v tools/jira/.jira.env backend/.env backend/vendor/autoload.php frontend/node_modules/x` — every path resolves to a rule in `.gitignore`.
3. **Negations hold:** from the repo root, `git check-ignore tools/jira/.jira.env.example backend/.env.example; echo "exit=$?"` — prints `exit=1` with no path listed.
4. **Docs tracked:** from the repo root, `git ls-files docs/` — prints the three placeholder paths.
5. **Backend builds:** from `backend/`, `composer validate --no-check-publish` — reports the manifest is valid. No dependency install is required by this story.
6. **Services start:** from the repo root, `docker compose up -d && docker compose ps` — `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all report healthy; http://localhost:8025 loads the Mailpit UI. Tear down with `docker compose down` when finished.
7. **Regression:** nothing to regress — no application source file is modified by this story. Confirm with `git show --stat HEAD` that the first commit touches only `README.md`, `.gitignore`, `docs/*.md` and pre-existing files that were merely added to version control for the first time.

---

## Done Criteria

- [ ] `git init -b main` has been run at the repository root and `git branch --show-current` prints `main`.
- [ ] Root `.gitignore` covers `vendor/`, `node_modules/`, `.env`, `tools/jira/.jira.env` and build output (`frontend/dist/`, `backend/public/build/`), verified by `git check-ignore`, with the squad-kit managed block (lines 42–49) unchanged.
- [ ] `tools/jira/.jira.env` is ignored and absent from `git ls-files`; `tools/jira/.jira.env.example` **is** tracked.
- [ ] Root `README.md` exists and documents the four-directory layout, the prerequisite table, the three-step start sequence, and the ports table matching `docker-compose.yml`.
- [ ] `docs/erd.md`, `docs/api-contract.md` and `docs/deployment-runbook.md` exist, each opening with its `> **Placeholder.**` blockquote, and all three appear in `git ls-files docs/`.
- [ ] The first commit exists and `git ls-files | grep -E '(^|/)(vendor|node_modules)/'` returns nothing.
- [ ] No file under `backend/app/`, `backend/routes/`, or `frontend/src/` was modified.
- [ ] Overview `00-overview.md` updated with this story.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 02 (TM-3).**
