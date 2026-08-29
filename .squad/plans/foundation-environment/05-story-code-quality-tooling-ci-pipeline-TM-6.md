# Story 05 — Code quality tooling and CI pipeline (Story: TM-6)

## Prerequisites

- **Story 01 (TM-2) completed:** [`01-story-scaffold-monorepo-skeleton-TM-2.md`](01-story-scaffold-monorepo-skeleton-TM-2.md). Verified: the remote exists and `main` is pushed — `git branch -vv` shows `main 1fed355 [origin/main]` and `git ls-remote --heads origin` returns `refs/heads/main`. The remote is `https://github.com/yasser208036/ticket-mangment.git`, so a workflow pushed to `.github/workflows/` **will** run. There is no `.github/` directory today.
- **Story 02 (TM-3) must be _implemented_, not merely planned:** [`02-story-install-configure-laravel-13-api-TM-3.md`](02-story-install-configure-laravel-13-api-TM-3.md). Hard blocker on one line of the workflow. `composer test` **exits `1` today**, before PHPUnit even starts:

  ```
  No arguments expected for "config:clear" command, got "@no_additional_args".
  Script @php artisan config:clear --ansi @no_additional_args handling the test event returned with error code 1
  ```

  TM-3 task 2 removes that token (`backend/composer.json:49`). Until it lands, the backend job is red for a reason that has nothing to do with CI. Confirm before starting: `cd backend && composer test` exits `0`.
- **Story 03 (TM-4) must be _implemented_, not merely planned:** [`03-story-scaffold-vue-3-spa-api-client-TM-4.md`](03-story-scaffold-vue-3-spa-api-client-TM-4.md). Hard blocker for two reasons. `frontend/package.json` has **no `test` script** (verified: `scripts` is lines 6–10, `dev`/`build`/`preview` only), so `npm test` in CI fails with "Missing script". And there are no test files, so even `npx vitest run` exits `1` with "No test files found". TM-4 adds `test`, `test:watch` and `typecheck` and the eleven tests they run. Confirm before starting: `cd frontend && npm test` exits `0` with 11 passing.
- **Story 04 (TM-5) is _not_ a blocker.** CI uses a GitHub Actions **service container**, not `docker-compose.yml`, so nothing here depends on it. One finding does carry over, and task 3 reuses it: TM-5 established by experiment that `mysqladmin ping` is the wrong readiness probe. See [`04-story-one-command-local-environment-TM-5.md`](04-story-one-command-local-environment-TM-5.md).
- **No new dependency on either side.** Verified against the installed tree: `eslint@10.9.1`, `eslint-plugin-vue@10.10.0`, `typescript-eslint@8.68.0`, `@vue/eslint-config-typescript@14.9.0`, `@vue/eslint-config-prettier@10.2.0`, `prettier@3.9.6` are all present in `frontend/node_modules`, and `laravel/pint@1.30.5` is at `backend/vendor/bin/pint`. `frontend/package-lock.json` and `backend/composer.lock` are both tracked and in sync (`composer validate` → "valid"; `npm ci --dry-run` succeeds). **`frontend/package-lock.json` must be unchanged by this story** — if a task seems to need a package, re-read it.

---

## Story Goal

Four static checks and one workflow that runs all of them, so style drift and broken code fail on push instead of in review.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | `php artisan pint --test` passes on the backend | ⚠️ **Passes — but that command does not exist.** `php artisan list --raw \| grep -iE 'pint\|lint\|format\|style'` returns **nothing**; there is no Artisan wrapper for Pint in Laravel 13. The real command is `./vendor/bin/pint --test`, which CLAUDE.md already documents (lines 30–31) and which **exits `0` over 31 files today**. See "Acceptance criterion 1 names a command that does not exist" below. |
| 2 | `npm run lint` and `npm run format:check` pass on the frontend | ❌ **Not met.** Neither script exists (`frontend/package.json:6-10`), there is **no ESLint config file** and **no Prettier config file** — verified by listing `frontend/`. Every dependency needed is already installed. |
| 3 | GitHub Actions workflow runs backend tests, frontend tests and both linters | ❌ **Not met.** No `.github/` directory exists. |
| 4 | CI spins up a MySQL 8 service so backend feature tests run against a real database | ❌ **Not met**, and it is the fiddliest of the five: `backend/phpunit.xml:36-39` hard-codes `127.0.0.1`, port **3307** and `ticket_management_test`, so the service container must publish **3307**, not 3306. |
| 5 | The workflow fails the build on any linter or test failure | ❌ **Not met.** Note that ESLint exits `0` on warnings, so this criterion needs `--max-warnings 0` rather than just "the step runs". |

Five user-visible outcomes:

1. `frontend/eslint.config.js` exists and `npm run lint` exits `0` — validated during planning against the current scaffold: **0 problems across 5 files**.
2. `frontend/.prettierrc.json` exists and `npm run format:check` exits `0` **without reformatting a single file** — see the section below; this is the least obvious finding in the story.
3. `./vendor/bin/pint --test` and `composer lint` both exit `0` from `backend/`.
4. `.github/workflows/ci.yml` runs on every push and every pull request into `main`, in two parallel jobs, with a MySQL 8 service container on port 3307.
5. Any failing linter or test fails the build, and one failure does not hide the others.

**Not in scope:** setting `APP_VERSION` from the release tag — see "Reassigning APP_VERSION" below, which moves it to TM-63 with a reason; any deploy, build-artifact or release job (**TM-63**); type-aware ESLint rules (`vueTsConfigs.recommendedTypeChecked`) — `npm run typecheck` already covers the type layer at a fraction of the lint cost; PHPStan or Larastan; a root `.editorconfig`; building `backend/`'s Blade-side Vite assets in CI (nothing serves them — `backend/package.json` is Tailwind + `laravel-vite-plugin` for `resources/views/welcome.blade.php` only); branch-protection rules or any GitHub setting outside the repository tree; and pre-commit hooks or Husky.

---

## Context — Read These Files First

1. `frontend/package.json` — all 33 lines. `scripts` is **6–10** (`dev`, `build`, `preview` — task 4 adds four more). `dependencies` 11–16, `devDependencies` **17–32**. Read the devDependencies line by line and confirm all six lint/format packages are already there: `@vue/eslint-config-prettier` (20), `@vue/eslint-config-typescript` (21), `eslint` (24), `eslint-plugin-vue` (25), `prettier` (27), `typescript` (28). **Nothing is added here.**
2. Confirm no config file exists before creating one: `ls -a frontend/ | grep -iE 'eslint|prettier'` returns nothing. CLAUDE.md line 88 says the same in prose.
3. `frontend/node_modules/@vue/eslint-config-typescript/dist/index.d.mts` — the last line is the export list. The API this story uses is **`defineConfigWithVueTs`** and **`vueTsConfigs`**; `createConfig` and the bare `defineConfig` are both marked `@deprecated` in that file. `vueTsConfigs` keys are enumerated in `CONFIG_NAMES` on the same file — `recommended` is the one task 1 uses.
4. **`@eslint/js` is NOT installed** — `ls node_modules/@eslint/js` fails. A flat config that imports it dies with `ERR_MODULE_NOT_FOUND` before linting anything. This was hit during planning. Task 1 uses `globalIgnores` from **`eslint/config`** (part of ESLint itself) instead, which is why no dependency is needed.
5. `backend/composer.json` — `scripts` is lines 35–69; `setup` is 36–43, and the `test` script's broken `@no_additional_args` token is on **line 49**. Task 5 adds two keys to this block. **TM-3 edits line 49 in the same block** — it is a hard prerequisite, so it lands first; do not "helpfully" fix line 49 from this story.
6. `backend/phpunit.xml` — the comment at 27–35 explains why the suite uses a real MySQL, and **lines 36–39 hard-code `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=ticket_management_test`**. These four values dictate the entire shape of the service container in task 3. Note what is **absent**: `DB_USERNAME` and `DB_PASSWORD`, which today come from `backend/.env` (`ticket_user` / `secret`, `backend/.env.example:25-26`). TM-3 plans to add them here after line 39. Either way the service must create that user with that password. **Read-only for this story.**
7. `backend/tests/` — three files only: `Feature/ExampleTest.php`, `Unit/ExampleTest.php`, `TestCase.php`. `php artisan test` passes **2 tests / 2 assertions** right now, and does so **without `pdo_mysql`**, because neither example touches the database. TM-3's `HealthTest` is the first test that needs MySQL — which is why criterion 4 has no teeth until TM-3 lands.
8. Run `php -r 'echo implode(",", PDO::getAvailableDrivers());'` in `backend/`. It prints **`pgsql`** — `pdo_mysql` is missing on this machine (TM-3 task 1 installs it). In CI it is one entry in `setup-php`'s `extensions:` list. Expect the counter-intuitive consequence: **CI can be green on database tests while the same suite cannot run locally.**
9. `CLAUDE.md` — lines **30–31** (the Pint commands; already correct, leave them), **42–43** and **88**. Line 42 says `npx vitest  # no "test" script and no vitest.config.ts exist yet`; line 43 cites `npx vitest run src/components/HelloWorld.spec.ts`, **a file that has never existed** and whose component TM-4 deletes; line 88 says "there is no ESLint config file despite the ESLint dependencies". All three are stale after TM-4 and this story. Task 6 fixes them — the overview has assigned this to TM-6 since Story 03 was planned.
10. `README.md` — `### Tests` is lines **92–97** (backend `composer test`, frontend `npx vitest`). Task 6 replaces the frontend line and adds a code-quality section. Line 1 is the `# ticket-mangment` heading, where task 6 adds the status badge.
11. `backend/.editorconfig` — 18 lines, `root = true` on line 1, `indent_size = 4` on line 6. Because `root = true` scopes it to `backend/`, it does **not** reach `frontend/`. Do not add a root `.editorconfig` in this story; changing what governs indentation under `frontend/` after task 2 has been verified is a way to break `format:check` with no code change.
12. Run each of these before you start and record the output — they are the "before" half of every verification step:

    ```bash
    cd backend  && ./vendor/bin/pint --test; echo "pint=$?"      # expect 0
    cd backend  && composer test         >/dev/null 2>&1; echo "composer test=$?"  # 0 only after TM-3
    cd frontend && npm test              >/dev/null 2>&1; echo "npm test=$?"       # 0 only after TM-4
    cd frontend && npx prettier --check . >/dev/null 2>&1; echo "prettier=$?"       # expect 1 — see below
    ```

---

## Product rules — three findings that change what you write

### Acceptance criterion 1 names a command that does not exist

`php artisan pint --test` cannot be made to pass, because Laravel 13 ships no such command. Verified:

```
$ php artisan list --raw | awk '{print $1}' | grep -iE "pint|lint|format|style"
NONE
```

The intake wording is a slip for the command CLAUDE.md documents on lines 30–31, `./vendor/bin/pint --test`, which **already exits `0` over 31 files**. Use `./vendor/bin/pint --test` everywhere and add a `composer lint` alias (task 5) so there is a memorable entry point. **Do not write a custom `PintCommand`** to make the acceptance criterion literally true — it would wrap a binary that works, and every future reader would have to learn about it.

### Prettier's defaults would fight every file in the repository, and Story 03's plan

`npx prettier --check .` exits **`1`** today, on five files:

```
[warn] src/App.vue
[warn] src/components/HelloWorld.vue
[warn] src/main.ts
[warn] src/style.css
[warn] vite.config.ts
```

The entire disagreement is quotes and semicolons — Prettier defaults to `"double"` and `semi: true`, while the Vue/Vite scaffold uses single quotes and no semicolons:

```diff
- import { createApp } from 'vue'
+ import { createApp } from "vue";
```

Two ways to make criterion 2 pass, and only one is right:

- **Reformat the code to Prettier's defaults.** This rewrites five files, and — far worse — **every code block in Story 03's plan is written in single-quote / no-semicolon style** (see `03-…-TM-4.md` tasks 2 through 7). TM-4 would land format-dirty on arrival, and whoever noticed would have to choose between the plan and the linter.
- **Configure Prettier to match the code.** Two options do it. Verified during planning with exactly `{"semi": false, "singleQuote": true}`:

  ```
  Checking formatting...
  All matched files use Prettier code style!
  exit=0
  ```

  Zero files changed, and every file TM-4 creates stays clean. `src/App.vue`, `src/components/HelloWorld.vue` and `src/style.css` were also diffed individually under that config — **no drift at all**, not just no quote drift.

Task 2 takes the second path. This is the reason `prettier --write` appears nowhere in this plan.

### Reassigning APP_VERSION

Story 02's plan hands `APP_VERSION` to this story in three places (`02-…-TM-3.md` lines 165, 450, 486: *"Set APP_VERSION from the release tag in CI"*). **It does not belong here, and this story does not implement it.** The reason is concrete: setting `APP_VERSION` is only meaningful for a build that gets deployed, and this workflow has **no build or deploy job** — nothing is published, and there is no environment to publish to (TM-63 owns environments; `docs/deployment-runbook.md` is still a placeholder). Injecting it into the *test* job would be worse than useless: `phpunit.xml` does not read `APP_VERSION`, and TM-3's health test sets it per-test precisely so the assertion cannot pass against a matching default (`02-…-TM-3.md:220`).

Task 6 records the reassignment to **TM-63** in the overview's dependency notes. Nothing in `backend/config/app.php` or `backend/.env.example` is touched.

---

## Frontend Tasks

### 1 — The ESLint flat config

**Create file: `frontend/eslint.config.js`**

`frontend/package.json` has `"type": "module"` (line 5), so a `.js` config is ESM and needs no loader. Do **not** use `eslint.config.ts` — that requires `jiti`, which is not installed (`ls node_modules/jiti` fails), so a TypeScript config would fail to load.

The config below was written to a scratch file in `frontend/`, run, and then removed. Result: **exit `0`, zero problems, 5 files linted** (`eslint.config.mjs`, `src/App.vue`, `src/components/HelloWorld.vue`, `src/main.ts`, `vite.config.ts`).

```js
import { globalIgnores } from 'eslint/config'
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import pluginVue from 'eslint-plugin-vue'
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting'

// Flat config — ESLint 10 supports no other kind. `eslint/config` ships with
// ESLint itself, which is why this file needs no new dependency; importing
// `@eslint/js` instead would fail with ERR_MODULE_NOT_FOUND (it is not
// installed, and is not a transitive dependency of anything here).
export default defineConfigWithVueTs(
  {
    name: 'app/files-to-lint',
    files: ['**/*.{ts,mts,tsx,vue}'],
  },

  globalIgnores(['**/dist/**', '**/coverage/**']),

  pluginVue.configs['flat/essential'],

  // `recommended`, deliberately not `recommendedTypeChecked`. Type-aware rules
  // need a full type-check per lint run, and `npm run typecheck` (vue-tsc,
  // added by TM-4) already covers that layer — a second one would double CI
  // time to re-report what the build already fails on.
  vueTsConfigs.recommended,

  // MUST come last: turns off every rule that would fight Prettier, so
  // formatting has exactly one owner. See .prettierrc.json.
  skipFormatting,
)
```

Three details that are not stylistic:

- **`skipFormatting` last.** Flat config is order-sensitive; earlier entries win only until a later one overrides them. Put it before `vueTsConfigs.recommended` and stylistic rules come back to life, so `npm run lint` and `npm run format:check` start contradicting each other.
- **`defineConfigWithVueTs`, not `defineConfig`.** Both are exported, but `index.d.mts` marks the plain `defineConfig` `@deprecated` ("renamed to `defineConfigWithVueTs` in 14.3.0"), as it does `createConfig`.
- **`globalIgnores`, not an `ignores` key in a config object.** A bare `{ ignores: [...] }` object only scopes *that* object; `globalIgnores` excludes the paths from the whole run. `dist/` and `coverage/` are both git-ignored (root `.gitignore:26,28`) but are present on any machine that has run `npm run build`, and linting minified output produces hundreds of meaningless errors.

### 2 — The Prettier config

**Create file: `frontend/.prettierrc.json`**

```json
{
  "semi": false,
  "singleQuote": true
}
```

That is the whole file, and both options are load-bearing — see "Prettier's defaults would fight every file" above. With them, `prettier --check .` passes on every existing file with **zero** rewrites; without them it fails on five and puts the linter at odds with Story 03's prescribed code.

Do **not** add `printWidth`, `trailingComma`, `arrowParens` or `tabWidth`. Every current file already satisfies Prettier's defaults for all of them (verified by diffing each file), so adding them changes nothing today and creates a reformat the first time someone edits one.

**Create file: `frontend/.prettierignore`**

```
# Prettier ignores node_modules on its own; these are the generated outputs it
# does not.
dist
coverage

# npm owns this file's formatting and rewrites it on every install.
package-lock.json
```

`package-lock.json` passes Prettier today — it is excluded because `npm install` regenerates it, not because it is currently dirty.

### 3 — Frontend scripts

**File: `frontend/package.json`**

TM-4 has already turned the `scripts` block (lines 6–10) into `dev`, `build`, `preview`, `test`, `test:watch`, `typecheck`. Add four more, giving:

```json
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc -b && vite build",
    "preview": "vite preview",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "vue-tsc -b",
    "lint": "eslint . --max-warnings 0",
    "lint:fix": "eslint . --fix",
    "format": "prettier --write .",
    "format:check": "prettier --check ."
  },
```

**`--max-warnings 0` is required by acceptance criterion 5, not decoration.** ESLint exits `0` when it reports only warnings, so without it a rule set to `warn` — anything in `vueTsConfigs.recommended`, or the first rule anyone tunes — passes CI while printing problems. With it, a warning fails the build.

Change nothing in `dependencies` or `devDependencies`. `git diff --stat package-lock.json` must be empty at the end of this story.

---

## Backend Tasks

### 4 — Nothing to configure for Pint

**No Pint config file.** `backend/pint.json` does not exist and must not be created: Pint's default Laravel preset already passes over all 31 files (`./vendor/bin/pint --test` → exit `0`, verified with Pint 1.30.5), and CLAUDE.md line 30 documents the absence deliberately ("Laravel preset, no `pint.json`"). A config file whose every value matches the default is a file that will drift.

**No source file is reformatted.** If `./vendor/bin/pint --test` fails when you run it, something outside this story changed `backend/app/` — investigate that rather than running `./vendor/bin/pint` to paper over it.

### 5 — Composer script aliases

**File: `backend/composer.json`**, `scripts` block (lines 35–69)

Add `lint` and `lint:fix` next to the existing `test` key, so the backend has the same shape of entry point as the frontend and the workflow reads the same in both jobs:

```json
        "lint": [
            "@php vendor/bin/pint --test"
        ],
        "lint:fix": [
            "@php vendor/bin/pint"
        ],
```

TM-3 edits **line 49** in this same block (removing `@no_additional_args` from `test`). TM-3 is a hard prerequisite, so it has already landed; verify with `grep -n no_additional_args backend/composer.json`, which must return nothing before you edit this file.

`composer lint` and `./vendor/bin/pint --test` are interchangeable. The workflow in task 6 calls **`./vendor/bin/pint --test`** directly, so a broken `scripts` block cannot make a *style* failure look like a *style* pass.

---

## CI Tasks

### 6 — The workflow

**Create file: `.github/workflows/ci.yml`**

`.github/` does not exist yet; create both directory levels.

```yaml
name: CI

on:
  push:
  pull_request:
    branches: [main]

# A push to a branch with an open PR triggers both events. Grouping by ref
# means the superseded run is cancelled instead of burning a runner.
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  backend:
    name: Backend — Pint + PHPUnit
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.4
        env:
          MYSQL_ROOT_PASSWORD: root_secret
          MYSQL_DATABASE: ticket_management_test
          MYSQL_USER: ticket_user
          MYSQL_PASSWORD: secret
        ports:
          # 3307, NOT 3306: backend/phpunit.xml lines 36-39 hard-code
          # 127.0.0.1:3307 and read nothing from this file.
          - 3307:3306
        options: >-
          --health-cmd="mysqladmin --protocol=TCP -h 127.0.0.1 -uticket_user -psecret status"
          --health-interval=5s
          --health-timeout=5s
          --health-retries=20
          --health-start-period=60s

    defaults:
      run:
        working-directory: backend

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP 8.3
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          # pdo_mysql is the extension missing on the development machine
          # (PDO::getAvailableDrivers() returns only pgsql there). Without it
          # every database test errors on connection, not on assertion.
          extensions: pdo_mysql, mbstring, bcmath, intl
          coverage: none
          tools: composer:v2

      - name: Cache Composer downloads
        uses: actions/cache@v4
        with:
          path: ~/.cache/composer/files
          key: composer-${{ hashFiles('backend/composer.lock') }}
          restore-keys: composer-

      - name: Install PHP dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Prepare the environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Pint — code style
        run: ./vendor/bin/pint --test

      # `!cancelled()` and not `always()`: the tests still run when Pint fails,
      # so one push reports both, but a cancelled workflow stops cleanly.
      - name: PHPUnit
        if: ${{ !cancelled() }}
        run: composer test

  frontend:
    name: Frontend — ESLint + Prettier + Vitest
    runs-on: ubuntu-latest

    defaults:
      run:
        working-directory: frontend

    steps:
      - uses: actions/checkout@v4

      - name: Set up Node.js 22
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
          cache-dependency-path: frontend/package-lock.json

      # `ci`, not `install`: it installs exactly the lockfile and fails if
      # package.json and package-lock.json have drifted apart.
      - name: Install Node dependencies
        run: npm ci

      - name: ESLint
        run: npm run lint

      - name: Prettier
        if: ${{ !cancelled() }}
        run: npm run format:check

      - name: Type check
        if: ${{ !cancelled() }}
        run: npm run typecheck

      - name: Vitest
        if: ${{ !cancelled() }}
        run: npm test
```

Six decisions in that file that are not arbitrary:

- **Two jobs, not four.** Backend and frontend run in parallel and share nothing. Splitting lint from test inside each would double `composer install` and `npm ci` to isolate checks that `if: ${{ !cancelled() }}` already isolates.
- **`if: ${{ !cancelled() }}` on every check after the first.** Without it, a Prettier failure hides whether the tests pass, so each push tells you about one problem and the next tells you about the next. Install steps deliberately do **not** carry it — there is nothing to check if the dependencies are not there, and a failed install fails its dependents anyway.
- **`ports: 3307:3306`.** The single most likely thing to get wrong. `phpunit.xml:38` says `3307` and PHPUnit's `<env>` entries reach Laravel before `.env` does, so `backend/.env`'s `DB_PORT=3306` never wins. Map 3306 and every database test fails with "Connection refused".
- **`mysqladmin … --protocol=TCP … status`, not `ping`.** Both properties were measured for TM-5 and re-measured against these exact flags. `ping` exits `0` **even on access denied** (`-uticket_user -pWRONG ping` → exit `0`), and `-h localhost` uses the unix socket, so it passes against the entrypoint's `--skip-networking` initialisation server — the service would be declared ready before port 3306 is open. `--protocol=TCP … status` exits `0` with the right credentials and `1` with the wrong ones. It also carries **no nested quotes**, which matters because this string is passed through YAML to `docker create` verbatim.
- **No `DB_*` variables in the job's `env:`.** PHPUnit's `<env name="…" value="…"/>` does not override a variable that is already set in the environment unless it carries `force="true"`, and `phpunit.xml:36-40` uses none. Setting `DB_PORT` at job level would silently defeat the file that is supposed to be authoritative. Configure the *service*, never the job.
- **`push:` with no branch filter.** The acceptance criterion says "every push". The `pull_request` trigger is scoped to `main` so a PR gets one extra run for the merge commit, and `concurrency` cancels what it supersedes.

The frontend `.env` is **not** created in CI. `src/api/client.ts` falls back to `'/api/v1'` when `VITE_API_BASE_URL` is unset (`03-…-TM-4.md` task 4), and no test issues a real request.

### 7 — Documentation

**File: `README.md`**

Add the status badge under the heading on line 1:

```markdown
# ticket-mangment

[![CI](https://github.com/yasser208036/ticket-mangment/actions/workflows/ci.yml/badge.svg)](https://github.com/yasser208036/ticket-mangment/actions/workflows/ci.yml)
```

The owner/repo come from `git remote -v` (`https://github.com/yasser208036/ticket-mangment.git`); the badge path must match the workflow **filename**, so renaming `ci.yml` later means editing this line.

Replace the `### Tests` section (lines 92–97) with:

````markdown
### Tests

- **Backend:** `composer test` in `backend/`. Tests run against the
  `ticket_management_test` database on port 3307 (the second MySQL container), so
  the development database is never truncated.
- **Frontend:** `npm test` in `frontend/` (`npm run test:watch` while working).

### Code quality

Four checks, all of them run by CI on every push. Run them before you push:

```bash
cd backend  && ./vendor/bin/pint --test   # or: composer lint
cd frontend && npm run lint               # ESLint, warnings fail
cd frontend && npm run format:check       # Prettier
cd frontend && npm run typecheck          # vue-tsc
```

Fix instead of report: `composer lint:fix` in `backend/`, `npm run lint:fix` and
`npm run format` in `frontend/`.

Formatting has exactly one owner per language — **Pint** for PHP and **Prettier**
for everything under `frontend/`. `frontend/eslint.config.js` ends with
`@vue/eslint-config-prettier/skip-formatting`, which switches off every ESLint
rule that would otherwise disagree with Prettier. `frontend/.prettierrc.json`
sets `singleQuote` and `semi: false` to match the code that is already there — do
not remove it, or every file in `frontend/` needs reformatting.
````

**File: `CLAUDE.md`**

Three stale claims, all assigned to this story by the overview when Story 03 was planned. Fix only these:

- **Lines 42–43** — replace

  ```
  npx vitest             # no "test" script and no vitest.config.ts exist yet
  npx vitest run src/components/HelloWorld.spec.ts   # single file
  ```

  with the commands that now exist. `src/components/HelloWorld.spec.ts` **never existed**, and TM-4 deleted the component it named:

  ```
  npm test               # vitest run
  npm run test:watch     # vitest, watch mode
  npm run lint           # ESLint (flat config, warnings fail the run)
  npm run format:check   # Prettier
  npx vitest run src/api/client.spec.ts   # single file
  ```

- **Line 88** — the sentence "there is no ESLint config file despite the ESLint dependencies" is now false. Replace it with a note that `eslint.config.js` and `.prettierrc.json` exist, that Prettier is configured to `singleQuote` / no-semicolons to match the existing code, and that `skip-formatting` gives formatting a single owner.
- Add a short **CI** subsection stating that `.github/workflows/ci.yml` runs both linters and both suites on every push, that the backend job uses a MySQL 8 service container **on port 3307** to match `phpunit.xml`, and that `pdo_mysql` comes from `setup-php` there.

Leave lines 30–31 (the Pint commands) exactly as they are — they were correct before this story and still are.

**Do not** edit `docs/api-contract.md`, `docs/erd.md` or `docs/deployment-runbook.md`. `.squad/plans/foundation-environment/00-overview.md` gets this story's row plus the `APP_VERSION` reassignment to TM-63.

---

## Edge Cases & Failure Modes

- **`php artisan pint --test` run as written in the acceptance criterion.** Fails with "Command "pint" is not defined". Verified: no Artisan command matches `pint|lint|format|style`. Every command in this plan, the workflow and the README uses `./vendor/bin/pint --test`.
- **A flat config importing `@eslint/js`.** `ERR_MODULE_NOT_FOUND` from `eslint.config.js`, and **zero files are linted** — ESLint exits `2`, which is a failure, but the message points at module resolution rather than at the missing package. It is not installed and is not transitive. Task 1's `globalIgnores` import from `eslint/config` is the reason this story needs no dependency.
- **`eslint.config.ts` instead of `.js`.** ESLint 10 loads a TypeScript config only through `jiti`, which is absent. The failure is at config load, before any rule runs.
- **`skipFormatting` placed before `vueTsConfigs.recommended`.** No error, no warning — flat config just resolves later entries over earlier ones, so the formatting rules come back and `npm run lint` starts reporting what `npm run format:check` considers correct. Symptom: `lint` and `format:check` disagree about the same line. Order in task 1 is load-bearing.
- **Prettier defaults left in place (no `.prettierrc.json`).** `format:check` fails on five files today, and on **every file TM-4 creates** — its plan is written in single-quote, no-semicolon style throughout. The tempting fix (`prettier --write .`) rewrites the scaffold *and* silently commits the project to a style its own plans contradict.
- **`printWidth` or `tabWidth` added to `.prettierrc.json` "for completeness".** Every current file already matches Prettier's defaults for those, so the addition is invisible until someone edits a long line and gets an unrelated reformat in their diff. Keep the file to the two options that are doing work.
- **A root `.editorconfig` added later.** `backend/.editorconfig` sets `indent_size = 4` and confines itself with `root = true`. A *root* one would reach `frontend/`, and Prettier's interaction with `.editorconfig` is exactly the kind of change that turns a green `format:check` red with no code edit. Out of scope here; if a future story adds one, re-run `npm run format:check` as part of it.
- **ESLint warnings passing CI.** ESLint exits `0` when nothing exceeded `warn`. `--max-warnings 0` in the `lint` script is what makes acceptance criterion 5 true for warnings; dropping it makes the linter advisory.
- **MySQL service mapped to 3306.** Every database test fails with "Connection refused" on `127.0.0.1:3307`, and nothing in the log mentions ports. `phpunit.xml:38` is the authority; the service publishes to match it.
- **`DB_PORT` (or any `DB_*`) set in the workflow's `env:`.** PHPUnit's `<env>` entries do not override an already-set environment variable without `force="true"`, and `phpunit.xml` uses none — so the job-level value wins over the file that is meant to be authoritative, and the suite quietly runs against the wrong target. Configure the service container, never the job environment.
- **The MySQL service reported ready before it is.** The failure TM-5 reproduced, in CI clothing: `mysqladmin ping -h localhost` passes against the entrypoint's `--skip-networking` init server, so steps begin against a closed port. `--protocol=TCP … status` cannot pass early, and (unlike `ping`, exit `0` on access denied — measured) fails if the user was not created.
- **Quoting inside `options:`.** GitHub passes that string to `docker create` verbatim. The health command in task 6 is deliberately free of nested quotes; a variant using `-e 'SELECT 1'` puts single quotes inside a double-quoted value inside a YAML folded scalar, and a mis-parse shows up as a service that never becomes healthy, ~60 s into the job, with no useful error.
- **`npm ci` failing on lockfile drift.** `npm ci` errors out instead of resolving, which is the point — but it means adding a dependency without committing `package-lock.json` fails CI at install, not at lint. Verified in sync today (`npm ci --dry-run` succeeds).
- **Test output is JSON locally and a table in CI.** `laravel/pao` ("Agent-optimized output for PHP testing tools") plus `laravel/agent-detector` rewrite PHPUnit and Pint output to JSON when an agent environment variable is present — `CLAUDECODE`, `CLAUDE_CODE`, `CURSOR_AGENT`, `COPILOT_*`, `GEMINI_CLI` and others are listed in `vendor/laravel/agent-detector/src/AgentDetector.php:8-30`. Locally you see `{"tool":"pint","result":"passed"}`; under `env -i` the same command prints `PASS ... 31 files`. GitHub Actions sets none of those variables, so CI logs are human-readable. **Do not grep CI logs for JSON, and do not add `--format=json` to make them match.**
- **CI green while the suite cannot run locally.** `setup-php` installs `pdo_mysql`; this machine does not have it (`PDO::getAvailableDrivers()` → `pgsql`). Until TM-3 task 1 is run locally, `HealthTest` errors on connection here and passes there. That asymmetry is expected, and CI is the correct signal.
- **Double workflow runs on a pull-requested branch.** `push` and `pull_request` both fire. The `concurrency` group cancels the superseded run; without it the repository burns two runners per push and the badge flickers between the two.
- **Badge shows "no status".** The URL embeds the workflow **filename**. Renaming `ci.yml`, or copying the badge before the first push, leaves a permanently grey badge. It goes green only after a run completes on the default branch.

---

## Test Plan

**No PHPUnit or Vitest test is added.** Every deliverable here *is* a check: the assertion "ESLint passes" is `npm run lint`, and a test that shelled out to it would only restate its exit code. The existing suites are what the workflow runs, and TM-3 (5 health tests + 1 unit test) and TM-4 (11 frontend tests) own them.

What replaces it is an exit-code matrix. Run every command from the stated directory and record the code.

1. **The four checks pass locally.**

   | Directory | Command | Expected |
   |---|---|---|
   | `backend/` | `./vendor/bin/pint --test` | `0` (31 files, unchanged from before this story) |
   | `backend/` | `composer lint` | `0` |
   | `frontend/` | `npm run lint` | `0`, and **0 problems** printed |
   | `frontend/` | `npm run format:check` | `0`, "All matched files use Prettier code style!" |
   | `frontend/` | `npm run typecheck` | `0` |

2. **`format:check` passes with no file rewritten** — the finding this story rests on. From `frontend/`: `npm run format:check`, then `git status --short` lists no file under `frontend/src/`. If any file needs writing, `.prettierrc.json` is wrong; do **not** run `npm run format` to make step 1 pass.

3. **Each linter genuinely fails on bad input.** A check that cannot fail is not a check. Three temporary edits, each reverted with `git checkout --` immediately after:
   - `frontend/` — append `const unused: number = 'x'` to `src/main.ts`; `npm run lint` must exit non-zero (`@typescript-eslint/no-unused-vars`).
   - `frontend/` — change one `'` to `"` in `src/main.ts`; `npm run format:check` must exit non-zero and name the file.
   - `backend/` — add two blank lines inside a method in `app/Http/Controllers/Api/V1/HealthController.php`; `./vendor/bin/pint --test` must exit non-zero. Revert with `git checkout -- app/`.

4. **ESLint and Prettier do not disagree.** From `frontend/`: `npm run format` (writes), then `npm run lint`. Exit `0` **and** `git status --short` shows no change. A non-zero exit means `skipFormatting` is not last in `eslint.config.js`.

5. **`--max-warnings 0` bites.** Add `rules: { 'no-console': 'warn' }` to a config block in `eslint.config.js`, put `console.log('x')` in `src/main.ts`, and confirm `npm run lint` exits **non-zero** while `npx eslint .` exits `0`. Revert both edits. This is the only test of acceptance criterion 5 for warnings.

6. **The workflow is valid YAML and well-formed.** `python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml'))"` from the repo root exits `0`. Then read the rendered file once more against the checklist in task 6 — particularly `3307:3306` and the health command.

7. **The workflow actually runs — the only test that proves criteria 3–5.** Push the branch and open the Actions tab (or `gh run watch`). Both jobs must appear and both must be green. Then confirm the negative: push a commit that breaks exactly one thing (a double quote in `src/main.ts`) and check that **the frontend job fails, the Prettier step is the one marked failed, and the Vitest step still ran** — that last part is what `if: ${{ !cancelled() }}` buys. Revert.

8. **The MySQL service is real (criterion 4).** In the successful backend run's log, confirm the `mysql` service container was created and became healthy, and that the PHPUnit step reports TM-3's health tests **passing** rather than erroring on connection. A suite that passes because the database tests were skipped does not satisfy this criterion — check the test count against TM-3's expected 5 + 1.

9. **Regression — no dependency moved.** Repo root: `git diff --stat frontend/package-lock.json backend/composer.lock` is empty. `git status --short` lists only `.github/workflows/ci.yml`, `frontend/eslint.config.js`, `frontend/.prettierrc.json`, `frontend/.prettierignore`, `frontend/package.json`, `backend/composer.json`, `README.md`, `CLAUDE.md`, plus this plan and the overview.

10. **Regression — the suites themselves are untouched.** `backend/` — `composer test` reports the same counts as before this story. `frontend/` — `npm test` reports 11 passing. No test file was added, modified or deleted by this story.

---

## Verification Steps

Run in this order. Working directory is stated for every command.

1. **Prerequisites are actually met, not just planned:** `backend/` — `composer test` exits `0` (TM-3 landed) and `grep -n no_additional_args composer.json` returns nothing. `frontend/` — `npm test` exits `0` with 11 passing (TM-4 landed). **If either fails, stop** — the workflow you are about to write cannot go green, and you would be debugging someone else's story through CI logs.
2. **Baseline recorded:** the four commands from Context item 12. Expect `pint=0` and `prettier=1`; the second is what task 2 fixes.
3. **Frontend checks:** Test Plan steps 1, 2 and 4 — `lint`, `format:check` and `typecheck` all exit `0`, no file was rewritten, and `format` followed by `lint` leaves the tree clean.
4. **Linters can fail:** Test Plan steps 3 and 5. Confirm every temporary edit is reverted afterwards with `git status --short`.
5. **Backend checks:** `backend/` — `./vendor/bin/pint --test` and `composer lint` both exit `0` and report 31 files.
6. **Backend builds / suite unaffected:** `backend/` — `composer test` exits `0` with the counts from step 1.
7. **Workflow parses:** Test Plan step 6.
8. **Workflow runs green:** Test Plan step 7, first half. Both jobs green on a real push.
9. **Workflow fails loudly:** Test Plan step 7, second half. One broken file fails the build, names the right step, and the later steps still ran. Revert.
10. **MySQL service verified:** Test Plan step 8 — the service became healthy and TM-3's database tests passed against it.
11. **Docs match reality:** the badge on `README.md:1` resolves (green, not "no status") after step 8; the four commands in the new "Code quality" section are copy-pasteable — paste each one; `CLAUDE.md` no longer mentions `HelloWorld.spec.ts`, no longer says there is no ESLint config, and lines 30–31 are unchanged.
12. **Regression:** Test Plan steps 9 and 10 — lockfiles unchanged, file list as expected, both suites reporting the same counts.

---

## Done Criteria

- [ ] `frontend/eslint.config.js` is a flat config using **`defineConfigWithVueTs` + `vueTsConfigs.recommended`**, with `globalIgnores` imported from **`eslint/config`** (not `@eslint/js`, which is not installed) and `@vue/eslint-config-prettier/skip-formatting` **last**. `npm run lint` exits `0` with **0 problems**.
- [ ] `frontend/.prettierrc.json` is exactly `{"semi": false, "singleQuote": true}` and `npm run format:check` exits `0` **with no file rewritten**. `frontend/.prettierignore` covers `dist`, `coverage` and `package-lock.json`.
- [ ] `frontend/package.json` has `lint`, `lint:fix`, `format` and `format:check`, with **`--max-warnings 0`** on `lint`; `dependencies` and `devDependencies` are unchanged and `git diff --stat frontend/package-lock.json` is empty.
- [ ] `backend/composer.json` has `lint` and `lint:fix` scripts wrapping Pint. **No `backend/pint.json` was created** and no PHP source file was reformatted; `./vendor/bin/pint --test` exits `0` over 31 files.
- [ ] Every linter demonstrably fails on bad input (Test Plan steps 3 and 5), including a `warn`-level ESLint rule, and every temporary edit was reverted.
- [ ] `.github/workflows/ci.yml` triggers on `push` and on `pull_request` into `main`, sets a `concurrency` group with `cancel-in-progress`, and runs two parallel jobs.
- [ ] The backend job uses a **`mysql:8.4` service published on `3307:3306`** — matching `backend/phpunit.xml:38` — with a `--protocol=TCP … status` health command and **no `DB_*` variables in the job `env:`**; `setup-php` installs `pdo_mysql`.
- [ ] Both jobs run all their checks with `if: ${{ !cancelled() }}` after the first, so one failure does not hide the rest, and **any** linter or test failure fails the build (demonstrated on a real push, Verification step 9).
- [ ] A real push produces two green jobs, and the backend job's log shows the MySQL service healthy with TM-3's database tests passing against it — not skipped.
- [ ] `README.md` carries the CI badge on line 1 and a "Code quality" section documenting all four checks and their fix counterparts; `CLAUDE.md` lines 42–43 and 88 are corrected, a CI subsection is added, and lines 30–31 are unchanged.
- [ ] `APP_VERSION` was **not** implemented here, and the overview records its reassignment to **TM-63** with the reason (no build or deploy job exists to carry it).
- [ ] Overview `00-overview.md` updated with this story.

**STOP HERE. Report to the user and wait for confirmation — this is the last story in the foundation-environment feature.**
