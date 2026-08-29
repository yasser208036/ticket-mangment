# Story 04 — One-command local environment with Docker Compose (Story: TM-5)

## Prerequisites

- **Story 01 (TM-2) completed:** [`01-story-scaffold-monorepo-skeleton-TM-2.md`](01-story-scaffold-monorepo-skeleton-TM-2.md). `docker-compose.yml` and the root `README.md` this story edits were both written by that story and committed in `1fed355`. Confirm with `git log --oneline -- docker-compose.yml`, which must show exactly that one commit.
- **Stories 02 (TM-3) and 03 (TM-4) do _not_ block this story**, and this story does not block them. Nothing here touches `backend/` or `frontend/` source. Run it in any order relative to them.
- **One hard coupling with Story 02 (TM-3):** [`02-story-install-configure-laravel-13-api-TM-3.md`](02-story-install-configure-laravel-13-api-TM-3.md) line 487 already anticipates this story — *"If TM-5 introduces a root `.env` with different credentials, `phpunit.xml` must change with it."* Task 1 introduces that file and **must keep every default byte-identical** to what `phpunit.xml` and `backend/.env.example` already hard-code. Changing a default here silently breaks `composer test`.
- **Docker Compose v2.13+** for `docker compose up --wait`, used in task 5. Verified available on this machine: `docker compose up --help` lists `--wait` and `--wait-timeout`.
- No coordination needed with other feature folders. **TM-6** (code quality tooling and CI) will consume the healthchecks this story fixes as its CI service-readiness gate; that is its work, not this story's.

---

## Story Goal

`docker-compose.yml` **already exists** with all three services, named volumes, and interpolated variables. This story is therefore an **audit-and-close**, not a greenfield build: three of the five acceptance criteria are already satisfied, two are not, and one of the "satisfied" ones rests on a defect that has been reproduced.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | `docker compose up -d` starts MySQL 8 and Mailpit **with healthchecks** | ⚠️ **Partially met.** Both MySQL services declare a healthcheck (`docker-compose.yml:27-32`, `52-57`), but it reports **`healthy` up to ~1.5 s before the server is usable** — reproduced below. Mailpit declares none at all; its `healthy` status comes from the image, not from this file. |
| 2 | MySQL data persists in a **named volume** across restarts | ✅ **Met.** `mysql-data` and `mysql-test-data` (`docker-compose.yml:21-22`, `47-48`, `72-74`); `docker volume ls` shows `ticket-mangment_mysql-data` and `ticket-mangment_mysql-test-data`. Verify only. |
| 3 | Mailpit UI reachable and **Laravel mail config points at it** in development | ✅ **Met.** `backend/.env.example:50-57` sets `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`; `backend/config/mail.php:44-45` reads them. Verify only, and document the port coupling in task 1. |
| 4 | Ports and credentials come from **environment variables with documented defaults** | ❌ **Not met.** The variables are interpolated but **nothing reads them**: there is no root `.env`, no root `.env.example`, and `DB_ROOT_PASSWORD`, `MAILPIT_SMTP_PORT` and `MAILPIT_UI_PORT` are documented in no tracked file. `docker compose config` proves every `${…:-default}` currently resolves to its default. `mysql-test`'s database name is hard-coded and not a variable at all (`docker-compose.yml:41`). |
| 5 | README documents the full **start, stop and reset** sequence | ⚠️ **Partially met.** `README.md:34-42` documents start, stop and wipe. There is **no reset sequence**, and line 38 tells the developer to wait for "all three healthy" — which is precisely the signal criterion 1 makes unreliable. |

So the deliverable is: **make `healthy` mean ready, make the variables real, and make the README's reset path exist.**

**Not in scope:** containerising PHP or Node (both deliberately run on the host — `docker-compose.yml:7`), a CI pipeline or any `.github/` file (**TM-6**), production or staging compose files and `docs/deployment-runbook.md` (**TM-63**), a `Makefile` or task runner, `depends_on` between services (nothing in the compose file depends on another service; the *host* apps do, and that is what the healthchecks are for), and any change under `backend/` or `frontend/`.

---

## Context — Read These Files First

1. `docker-compose.yml` — all 74 lines, and read it as the file you are about to edit in four places. The header comment is lines 1–7. The `mysql` service is 10–32: `environment` 14–18, published port 19–20, `volumes` 21–22, the utf8mb4 `command` 23–26, `healthcheck` **27–32**. The `mysql-test` service is 36–57, with **`MYSQL_DATABASE: ticket_management_test` hard-coded on line 41** and its healthcheck on **52–57**. The `mailpit` service is 60–70: `image: axllent/mailpit:latest` on **line 61**, ports 64–66, `environment` 67–70, **and no `healthcheck` key**. The named volumes are declared 72–74.
2. Run `docker compose config` at the repo root and read the resolved output. Every `${…:-default}` resolves to its default because there is no root `.env` — this is the whole of acceptance criterion 4. The command exits `0` with **no warning**, which is why the gap is invisible today.
3. Confirm the exact variable inventory rather than trusting this plan: `grep -on '\${[A-Z_]*' docker-compose.yml`. It returns **eight** distinct names — `DB_DATABASE` (15), `DB_USERNAME` (16), `DB_PASSWORD` (17), `DB_ROOT_PASSWORD` (18, 28, 44, 53), `DB_PORT` (20), `DB_TEST_PORT` (46), `MAILPIT_SMTP_PORT` (65), `MAILPIT_UI_PORT` (66). Task 2 removes the occurrences at 28 and 53; task 4 adds a ninth name. The `.env.example` in task 1 must document exactly the surviving set.
4. `README.md` — the two regions this story edits. **Lines 32–42** are `## Starting the stack` → `### 1. Services (repo root)`, ending with the `down` / `down -v` bullets. **Lines 66–82** are `### Ports`: the table is 68–75, the override sentence is 77–79, the Mailpit note is 81–82. **Lines 86–90** are `### Environment files`, which lists `backend/.env` and `tools/jira/.jira.env` and must gain the root `.env`. Leave `### Tests` (92–97) and everything after it alone.
5. `.gitignore` — lines 1–5. `.env` (line 2) and `.env.*` (line 3) are ignored, `!.env.example` (line 4) negates. Verified with `git check-ignore`: **root `.env` is ignored, root `.env.example` is tracked.** Task 1 depends on this; do **not** add a new rule.
6. `backend/.env.example` — lines 20–26 (`DB_*`), 28–32 (`DB_TEST_*`), 48–57 (`MAIL_*`). These are the values the root `.env.example` must mirror. Note precisely which names are **absent** here: `DB_ROOT_PASSWORD`, `MAILPIT_SMTP_PORT`, `MAILPIT_UI_PORT`. Those three exist only inside `docker-compose.yml` and are the undocumented part of criterion 4. **This file is read-only for this story.**
7. `backend/phpunit.xml` — the comment at lines 27–35 explains why tests use the second container, and lines 36–40 hard-code `DB_HOST=127.0.0.1`, `DB_PORT=3307`, `DB_DATABASE=ticket_management_test`. **The suite reads none of your compose variables.** Any override of `DB_TEST_PORT` in a root `.env` breaks `composer test` until this file is edited too. (TM-3 plans to add `DB_USERNAME`/`DB_PASSWORD` here after line 39; today they still come from `backend/.env`.) **Read-only for this story.**
8. `backend/config/mail.php` — line 17 (`'default' => env('MAIL_MAILER', 'log')`) and lines 40–50, especially the `host`/`port` defaults on **44–45** (`127.0.0.1`, `2525`). The `2525` fallback is why `MAIL_PORT=1025` must stay present in `backend/.env` — an absent variable does **not** fall back to Mailpit's port. **Read-only.**
9. `backend/composer.json` — the `setup` script at lines 36–42, specifically `"@php artisan migrate --force"` on **line 40**. This is the command that fails when `docker compose ps` lies about readiness, and the reason task 2 exists at all.
10. Run `docker compose ps` and `docker volume ls | grep ticket-mangment` before touching anything, and record the output. You need the "before" state to prove task 2 changed behaviour and not just text.

---

## The defect behind task 2 (read before editing the healthcheck)

The current check is `mysqladmin ping -h localhost -p<root-password>` (`docker-compose.yml:28`, `53`). Two properties of it were established by experiment, not by reading:

**It is credential-blind.** `mysqladmin ping` exits `0` when the server *answers*, even if it rejects the credentials. Verified inside the running container:

```
$ docker compose exec mysql mysqladmin ping -h localhost -pWRONG
mysqladmin: connect to server at 'localhost' failed
error: 'Access denied for user 'root'@'localhost' (using password: YES)'
$ echo $?
0
```

So the `-p${DB_ROOT_PASSWORD:-root_secret}` in the test string is decorative — it proves nothing, and a drifted password would not be caught.

**It passes against the initialisation server, before the real one is listening.** The official entrypoint starts a temporary server with `--daemonize --skip-networking --socket="${SOCKET}"` (`/usr/local/bin/docker-entrypoint.sh:124`) to create the database and user, then shuts it down and `exec`s the real `mysqld` (lines 393–413). Because `-h localhost` makes the MySQL client use the **unix socket**, the check succeeds against that temporary, network-less server. Sampled every 0.5 s on a fresh volume:

```
  4.0s  old=OLD_OK   new=new_fail
  4.5s  old=OLD_OK   new=new_fail
  5.0s  old=OLD_OK   new=new_fail
  5.5s  old=old_fail new=new_fail     <- temp server shut down
  7.0s  old=OLD_OK   new=NEW_OK       <- real server listening on TCP
```

A single passing check flips the container to `healthy` **immediately**, even inside `start_period` — `start_period` only suppresses *failures*. So on a cold `docker compose up -d` there is a ~1.5 s window in which `docker compose ps` reports `healthy` while port 3306 is closed. Land in it and `composer setup` fails on `php artisan migrate --force` (`backend/composer.json:40`) with a connection error, immediately after the README told you to wait for `healthy`. The window widens on slower disks and grows with anything added to `/docker-entrypoint-initdb.d/`.

The fix is to force TCP and to assert the thing the app actually needs — that **the application user can reach the application database** — using the container's own environment so no interpolated value can drift from it.

---

## Implementation tasks

**No changes under `backend/` or `frontend/`.** Four files are read for their values (`backend/.env.example`, `phpunit.xml`, `config/mail.php`, `composer.json`); none is edited. Two files are created or edited: `.env.example` (new, repo root) and `docker-compose.yml`, plus `README.md`.

### 1 — Create the file Compose actually reads

**Create file: `.env.example`** (repo root — **not** `backend/.env.example`)

Compose interpolates from a `.env` in the **project directory**, which is the repo root. It never reads `backend/.env`. That is the single most confusing thing about this setup and the comment header says so explicitly.

```dotenv
# Docker Compose settings for the local stack — the ONLY file `docker compose`
# reads for variable interpolation. Copy it and edit the copy:
#
#   cp .env.example .env
#
# `.env` is git-ignored (.gitignore lines 2-3); this template is tracked.
#
# IMPORTANT: Compose does NOT read backend/.env. These values configure the
# CONTAINERS; backend/.env configures the Laravel app that connects to them.
# Both must agree. Every variable below names the file you must edit alongside
# it — change one side only and the app will not reach the container.
#
# You do not need this file to start the stack: every variable has the default
# shown, and those defaults are what backend/.env.example and
# backend/phpunit.xml already expect. Create a .env only to resolve a port
# clash or to change a credential.

# --- MySQL: application database (container tm-mysql) ---------------------
# Published host port. If you change it, change DB_PORT in backend/.env too.
DB_PORT=3306

# Database, user and password created on the volume's FIRST boot only.
# Changing these against an existing volume has NO effect on MySQL itself but
# DOES change what the healthcheck authenticates with, so the container will
# report `unhealthy` forever. See "Changing a credential" in README.md.
# Mirrored by DB_DATABASE / DB_USERNAME / DB_PASSWORD in backend/.env.
DB_DATABASE=ticket_management
DB_USERNAME=ticket_user
DB_PASSWORD=secret

# Root password. Used by the container's own initialisation only — no
# application ever connects as root, and backend/.env has no counterpart.
DB_ROOT_PASSWORD=root_secret

# --- MySQL: test database (container tm-mysql-test) ----------------------
# A second container so `composer test`'s RefreshDatabase never truncates the
# data you are developing against. See backend/phpunit.xml lines 27-35.
#
# WARNING: backend/phpunit.xml hard-codes 127.0.0.1:3307 and
# ticket_management_test (lines 36-39). It reads NOTHING from this file.
# Overriding either value below without editing phpunit.xml breaks
# `composer test` with a connection error.
DB_TEST_PORT=3307
DB_TEST_DATABASE=ticket_management_test

# --- Mailpit (container tm-mailpit) --------------------------------------
# SMTP port Laravel sends to. Mirrored by MAIL_PORT in backend/.env — and
# note backend/config/mail.php line 45 falls back to 2525, not 1025, so an
# absent MAIL_PORT does not quietly still work.
MAILPIT_SMTP_PORT=1025

# Web UI. Read the captured mail at http://localhost:<this port>.
# Nothing in backend/ references it; it is for humans.
MAILPIT_UI_PORT=8025
```

Then create the working copy, so the next task's verification runs against the same mechanism a developer uses:

```bash
cd /home/yasser-mohamed/ticket-mangment
cp .env.example .env
```

Confirm the ignore rules behave as claimed — the negation on `.gitignore:4` is what makes this work, and it is worth one command rather than one assumption:

```bash
git check-ignore -q .env         && echo ".env ignored — correct"
git check-ignore -q .env.example || echo ".env.example tracked — correct"
```

**Every default above is byte-identical to what already exists.** `cp .env.example .env && docker compose config` must produce output identical to `docker compose config` with no `.env` at all. That is verification step 3, and it is the guard against silently breaking `composer test`.

### 2 — Make `healthy` mean **ready** on both MySQL services

**File: `docker-compose.yml`**

Replace the `healthcheck` block at **lines 27–32** (`mysql`) with the following. The YAML below has been validated with `docker compose config` **and** run against a cold volume — it reported healthy at 10.8 s under `up -d --wait`, with the application user genuinely connectable at that moment:

```yaml
    healthcheck:
      # Not `mysqladmin ping`. Two reasons, both verified:
      #   1. `ping` exits 0 even when the server REJECTS the credentials, so it
      #      proves only that something answered.
      #   2. `-h localhost` uses the unix socket, so it passes against the
      #      entrypoint's temporary `--skip-networking` init server — the
      #      container reports `healthy` up to ~1.5s before port 3306 opens,
      #      and `composer setup`'s `artisan migrate` then fails.
      # --protocol=TCP forces the real listener; connecting AS THE APP USER TO
      # THE APP DATABASE asserts exactly the precondition `migrate` needs.
      # $$ escapes to a single $ — these come from the container's own
      # environment (lines 15-17), so they cannot drift from what was created.
      test: ['CMD-SHELL', 'mysql --protocol=TCP -h 127.0.0.1 -u"$$MYSQL_USER" -p"$$MYSQL_PASSWORD" -e "SELECT 1" "$$MYSQL_DATABASE" > /dev/null 2>&1']
      interval: 5s
      timeout: 5s
      retries: 20
      start_period: 60s
```

Apply the **same** block to `mysql-test` at **lines 52–57**. It is identical text — both services set `MYSQL_USER`, `MYSQL_PASSWORD` and `MYSQL_DATABASE`, so the check needs no per-service values. Do not use a YAML anchor to share it: it is six lines, and an anchor would put the reason for the check further from the service it guards.

Three details that are not stylistic:

- **`start_period` goes from `30s` to `60s`.** The check now legitimately fails for the whole of initialisation instead of passing partway through it, and cold-boot init plus `RefreshDatabase`-sized volumes can exceed 30 s on a slow disk. Failures inside `start_period` do not consume `retries`, so a longer period costs nothing and removes a false `unhealthy` on the first-ever `up`.
- **`$$`, not `$`.** Compose interpolates the compose file first; `$$` is the escape that leaves a literal `$` for the shell inside the container. Written as `$MYSQL_USER` it would resolve to the **empty string** at parse time and the check would authenticate as nobody. Verify with `docker compose config`, whose output re-escapes it and must still show `$$MYSQL_USER`.
- **`> /dev/null 2>&1` is required.** The `mysql` client has no `--silent` equivalent for this, and it prints `[Warning] Using a password on the command line interface can be insecure` to stderr on every single run — every 5 s, forever, into the container's health log.

After this change `DB_ROOT_PASSWORD` is interpolated in only two places (lines 18 and 44, the two `MYSQL_ROOT_PASSWORD` assignments) instead of four. Re-run the grep from context item 3 and confirm.

### 3 — Pin Mailpit and declare its healthcheck

**File: `docker-compose.yml`**

Mailpit *does* report `healthy` today — verified with `docker inspect tm-mailpit`, which returns `{"Test":["CMD","/mailpit","readyz"],"Interval":15000000000,"StartPeriod":10000000000,"StartInterval":1000000000}`. That healthcheck comes from **the image's own Dockerfile**, not from this repository. Acceptance criterion 1 is therefore met only for as long as an unpinned `:latest` keeps providing it, and nobody reading `docker-compose.yml` can tell that it is met at all.

Change **line 61** and add a healthcheck to the service. The running image is Mailpit **v1.31.0** (`curl -s http://localhost:8025/api/v1/info` → `"Version":"v1.31.0"`), and `docker manifest inspect axllent/mailpit:v1.31.0` confirms that tag exists — note the **`v` prefix**; a bare `1.31.0` does **not** exist:

```yaml
  # Catches every outgoing email in development. Nothing can reach a real inbox.
  mailpit:
    # Pinned, not `:latest`. The healthcheck below is only meaningful against a
    # known version, and `docker compose up --wait` gates the whole stack on it.
    image: axllent/mailpit:v1.31.0
    container_name: tm-mailpit
    restart: unless-stopped
    ports:
      - '${MAILPIT_SMTP_PORT:-1025}:1025'   # SMTP — Laravel sends here
      - '${MAILPIT_UI_PORT:-8025}:8025'     # Web UI — read the mail here
    environment:
      MP_MAX_MESSAGES: 500
      MP_SMTP_AUTH_ACCEPT_ANY: 1
      MP_SMTP_AUTH_ALLOW_INSECURE: 1
    healthcheck:
      # The image ships this same check; declaring it here means `healthy` is a
      # promise of THIS file, and survives a base-image change.
      test: ['CMD', '/mailpit', 'readyz']
      interval: 10s
      timeout: 5s
      retries: 5
      start_period: 15s
```

`/mailpit readyz` was confirmed to exit `0` in the running container. Keep `MP_MAX_MESSAGES: 500` and both `MP_SMTP_AUTH_*` variables exactly as they are — they are what let Laravel connect with `MAIL_USERNAME=null` / `MAIL_PASSWORD=null` (`backend/.env.example:54-55`).

**Do not add a volume to Mailpit.** Its store is a temp file (`"Database":"/tmp/mailpit-….db"` in the `info` response), so captured mail is deliberately lost on recreate. Acceptance criterion 2 is about MySQL; development mail is throwaway by design, and persisting it would only accumulate junk.

### 4 — Make the test database name a variable

**File: `docker-compose.yml`, line 41**

Every other credential is overridable; this one is not, which makes the `.env.example` in task 1 a lie by omission.

```yaml
      MYSQL_DATABASE: ${DB_TEST_DATABASE:-ticket_management_test}
```

The default is unchanged, so `docker compose config` output for this key must be identical before and after. This is the ninth variable and the reason `DB_TEST_DATABASE` appears in task 1's template.

Leave lines 42–43 (`MYSQL_USER`, `MYSQL_PASSWORD`) as they are — sharing `DB_USERNAME` / `DB_PASSWORD` with the app container is deliberate, and TM-3's plan (line 209) already pins `phpunit.xml` to those same values.

### 5 — README: start, stop, **reset**, and the failure modes

**File: `README.md`**

Three edits. Change nothing else — the rest is TM-2's deliverable and is accurate.

**5a. Replace lines 34–42** (`### 1. Services (repo root)` through the two `down` bullets) with:

````markdown
### 1. Services (repo root)

```bash
docker compose up -d --wait     # blocks until all three report healthy
```

`--wait` is what makes this one command rather than two: it returns only once
every service's healthcheck passes, so the next step cannot start against a
half-initialised database. On a cold volume expect roughly 10–15 seconds. Add
`--wait-timeout 120` on a slow machine.

Without `--wait`, `docker compose up -d` returns as soon as the containers are
*started* — use `docker compose ps` and wait for all three to say `healthy`.
The MySQL healthcheck connects as the application user to the application
database over TCP, so `healthy` means "`php artisan migrate` will work", not
merely "a process is running".

| Command | Effect |
|---|---|
| `docker compose up -d --wait` | start everything, block until healthy |
| `docker compose ps` | show health of all three containers |
| `docker compose logs -f mysql` | follow one service's log |
| `docker compose stop` | stop the containers, **data survives** |
| `docker compose down` | remove the containers, **data survives** (the named volumes are kept) |
| `docker compose down -v` | remove the containers **and wipe both databases** |

#### Resetting

Two different resets — reach for the smaller one first.

**Reset the schema and seed data, keep the containers** (from `backend/`):

```bash
php artisan migrate:fresh --seed
```

**Reset the containers and volumes from scratch** (from the repo root) — **this
destroys all local ticket data in both databases and cannot be undone:**

```bash
docker compose down -v          # deletes volumes ticket-mangment_mysql-data
                                # and ticket-mangment_mysql-test-data
docker compose up -d --wait     # recreates and re-initialises both databases
cd backend && php artisan migrate --seed
```

Only the full reset re-runs MySQL's first-boot initialisation, so it is the only
way to apply a changed `DB_USERNAME`, `DB_PASSWORD`, `DB_DATABASE` or
`DB_TEST_DATABASE`.
````

**5b. In `### Ports` (lines 66–82)**, keep the table at 68–75 and replace the override sentence at **lines 77–79** with the coupling that actually bites. Each override needs a paired edit in a file Compose does not read, and today nothing says so:

````markdown
Every port and credential is an overridable variable read from a **root `.env`**
(copy it from `.env.example`). Compose reads **only** that file — it does *not*
read `backend/.env`, so an override needs a paired edit:

| Root `.env` variable | Default | Also change |
|---|---|---|
| `DB_PORT` | `3306` | `DB_PORT` in `backend/.env` |
| `DB_TEST_PORT` | `3307` | `DB_PORT` in `backend/phpunit.xml` (hard-coded, line 38) |
| `DB_DATABASE` | `ticket_management` | `DB_DATABASE` in `backend/.env` — plus a full reset |
| `DB_TEST_DATABASE` | `ticket_management_test` | `DB_DATABASE` in `backend/phpunit.xml` (line 39) — plus a full reset |
| `DB_USERNAME` / `DB_PASSWORD` | `ticket_user` / `secret` | the same names in `backend/.env` — plus a full reset |
| `DB_ROOT_PASSWORD` | `root_secret` | nothing — containers only |
| `MAILPIT_SMTP_PORT` | `1025` | `MAIL_PORT` in `backend/.env` |
| `MAILPIT_UI_PORT` | `8025` | nothing — the browser only |

#### Changing a credential

`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and `DB_TEST_DATABASE` are applied by
MySQL on the volume's **first boot only**. Editing them against an existing
volume changes nothing inside MySQL — but it *does* change what the healthcheck
authenticates with, so the container will report **`unhealthy`** indefinitely.
That is intended: the alternative is an app that cannot connect and a container
that claims to be fine. Run the full reset above, or change the password inside
MySQL by hand.
````

**5c. In `### Environment files` (lines 86–90)**, add the root `.env` as the first entry — it is the one a developer meets first and the only one that is genuinely optional:

````markdown
### Environment files

Three git-ignored files, each copied from a tracked `.example` sibling:

| File | Copy from | Needed? |
|---|---|---|
| `.env` (repo root) | `.env.example` | **Optional** — Compose falls back to the documented defaults. Create it only to resolve a port clash or change a credential. |
| `backend/.env` | `backend/.env.example` | Required. `composer setup` creates it for you if absent. |
| `tools/jira/.jira.env` | `tools/jira/.jira.env.example` | Only for `tools/jira/upload.mjs`. |

The root `.env` configures the **containers**; `backend/.env` configures the
**Laravel app that connects to them**. Compose reads only the former, Laravel
only the latter — see the coupling table under [Ports](#ports).
**Never commit a real token.**
````

Do **not** touch `docs/deployment-runbook.md`. Its local row (line 10) already points here, and staging/production compose is TM-63's.

---

## Edge Cases & Failure Modes

- **`healthy` reported before MySQL accepts TCP connections.** The defect this story fixes; reproduced above with 0.5 s sampling. Trigger: a cold `docker compose up -d` on a fresh volume. Old behaviour: `docker compose ps` says `healthy` at ~4 s, port 3306 opens at ~7 s, and `composer setup` fails on `artisan migrate --force` (`backend/composer.json:40`) in between. New behaviour: the check cannot pass against the `--skip-networking` init server because `--protocol=TCP` forces the real listener. Enforced by the `test:` line in task 2.
- **`$MYSQL_USER` written instead of `$$MYSQL_USER`.** Compose interpolates the file before the container ever sees it, so a single `$` resolves at parse time — to the **empty string**, since `MYSQL_USER` is not set in your shell. The check then runs `mysql -u"" -p""`, fails forever, and the container never leaves `unhealthy` with no hint as to why. `docker compose config` must still show `$$MYSQL_USER` in its output.
- **A credential changed against an existing volume.** `MYSQL_USER` / `MYSQL_PASSWORD` / `MYSQL_DATABASE` are honoured on first boot only. The old check could not detect this (it was credential-blind — verified: `ping` exits `0` on access denied); the new one **can**, and reports `unhealthy`. This is a deliberate behaviour change, and the single most likely support question this story creates — which is why README §5b documents it explicitly rather than leaving it to be discovered.
- **A root `.env` with a changed `DB_TEST_PORT` or `DB_TEST_DATABASE`.** `backend/phpunit.xml:36-39` hard-codes `127.0.0.1`, `3307` and `ticket_management_test` and reads no compose variable. `composer test` fails on connection, not on assertion, so the suite looks broken rather than misconfigured. Called out in task 1's template, in the README table, and already anticipated by TM-3's plan (line 487).
- **`MAILPIT_SMTP_PORT` overridden without editing `backend/.env`.** `backend/config/mail.php:45` defaults `port` to **`2525`**, not `1025`, so an absent or stale `MAIL_PORT` does not coincidentally still work — mail fails with a connection error to a port nothing is listening on. The `MAIL_*` block at `backend/.env.example:50-57` stays the source of truth for the app side.
- **A port already bound on the developer's machine.** `docker compose up -d` fails with `bind: address already in use` for 3306, 3307, 1025 or 8025. This is now genuinely fixable rather than merely documented: `cp .env.example .env` and change the one port. Before this story there was no file to put the override in.
- **`docker compose up --wait` on an unsupported Compose version.** `--wait` needs Compose v2.13+. On older versions the flag is rejected and the command does nothing. The README keeps the `docker compose ps` path as the documented fallback for exactly this reason — do not delete it in favour of the shorter instruction.
- **`--wait` returns non-zero when a service goes unhealthy.** Correct and desirable: a failing MySQL init now stops the developer at step 1 instead of at a confusing `migrate` error in step 2. Read `docker compose logs mysql` at that point — the entrypoint prints the initialisation failure there, not in `docker compose ps`.
- **`start_period: 60s` masking a genuinely broken container.** Failures inside `start_period` do not count toward `retries`, so a container that will never become healthy sits in `starting` for a full minute before the first failure counts. That is the accepted cost of not flagging a slow cold boot as broken. `--wait` blocks for the same minute; use `--wait-timeout` if that is too long for a script.
- **Mailpit's captured mail lost on recreate.** Its store is `/tmp/mailpit-*.db` inside the container and no volume is added (task 3). Any `docker compose down` empties the inbox. Deliberate — development mail is throwaway, and criterion 2 concerns MySQL only.
- **`axllent/mailpit:v1.31.0` versus a bare `1.31.0`.** `docker manifest inspect` confirms the **`v`-prefixed** tag exists and the unprefixed one does not. Dropping the `v` gives `manifest unknown` on the next machine that pulls, which is exactly the failure pinning was meant to prevent.
- **The README drifting from `docker-compose.yml` again.** Every port, container name and variable in README §5a/§5b is copied from the compose file. Verification step 10 is a manual cross-read, the same guard TM-2 used (`01-story-scaffold-monorepo-skeleton-TM-2.md:285`).

---

## Test Plan

**No PHPUnit or Vitest test is added, and that is a deliberate call.** Everything this story changes is container orchestration: the assertions worth making are "a cold boot reaches a usable database" and "the published ports are reachable", neither of which a test running *inside* the suite can make about the environment it depends on. A `HealthTest` that connects to MySQL already exists in TM-3's plan and already covers the app-to-database path. Automating the *stack* check belongs to **TM-6**, which owns the CI pipeline and will use these healthchecks as its service-readiness gate.

What replaces it is a reproducible manual matrix. Run it in this order:

1. **The cold-boot race — the regression this story exists to prevent.** From the repo root, with the stack down and volumes wiped:

   ```bash
   docker compose down -v
   docker compose up -d
   for i in $(seq 1 40); do
     printf '%5.1fs health=%-9s tcp=' "$(echo "$i*0.5" | bc)" \
       "$(docker inspect tm-mysql --format '{{.State.Health.Status}}')"
     docker compose exec -T mysql sh -c \
       'mysql --protocol=TCP -h 127.0.0.1 -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" \
        -e "SELECT 1" "$MYSQL_DATABASE" >/dev/null 2>&1 && echo READY || echo no'
     sleep 0.5
   done
   ```

   **Pass:** no line reads `health=healthy tcp=no`. That combination is the bug. Against the pre-change compose file this loop reproduces it; keep the output of both runs in the completion note.

2. **`--wait` does not return early.** `docker compose down -v && time docker compose up -d --wait`, then immediately — no sleep — `docker compose exec -T mysql mysql --protocol=TCP -h 127.0.0.1 -uticket_user -psecret -e 'SELECT 1' ticket_management`. It must print `1`. Measured 10.8 s on this machine during planning.

3. **Defaults are unchanged** — the guard on `composer test`. With no root `.env`, `docker compose config > /tmp/before.yml`; then `cp .env.example .env` and `docker compose config > /tmp/after.yml`; `diff /tmp/before.yml /tmp/after.yml` must be **empty**. Any diff means a default in task 1 drifted from `backend/.env.example` or `phpunit.xml`.

4. **An override actually takes effect.** In the root `.env` set `MAILPIT_UI_PORT=8026`, run `docker compose up -d --wait`, confirm http://localhost:8026 serves the UI and 8025 refuses. Revert the file and re-run. This is the only test that proves criterion 4 end-to-end; `docker compose config` alone proves only that the variable is *read*.

5. **The healthcheck detects credential drift.** In the root `.env` set `DB_PASSWORD=wrong`, run `docker compose up -d`, and confirm `tm-mysql` settles into `unhealthy` (allow `start_period` + a few intervals — up to ~90 s). Then revert and confirm it returns to `healthy` after a recreate. The **old** check reported `healthy` throughout; this test pins the improvement rather than trusting it.

6. **Volume persistence (criterion 2).** From `backend/`, `php artisan migrate --force`, then insert a row (`php artisan tinker` → `DB::table('users')->insert(['name'=>'persist','email'=>'p@x.test','password'=>'x']);`). Repo root: `docker compose down && docker compose up -d --wait`. Query it back — the row is there. Then `docker compose down -v && docker compose up -d --wait` and confirm the `users` table is **gone** (`migrate` has not been re-run), proving `-v` genuinely wipes.

7. **Regression — backend suite unaffected.** From `backend/`, `composer test` exits `0` with the counts TM-3 established. Run it **both** with and without a root `.env` present; the results must be identical. This is the concrete check that task 1 did not break the hard-coded `phpunit.xml` values.

8. **Regression — nothing under `backend/` or `frontend/` changed.** `git status --short` lists only `.env.example`, `docker-compose.yml` and `README.md` (plus this plan and `00-overview.md`). No path under `backend/` or `frontend/`.

---

## Migration / Rollback

The compose-file changes take effect on **container recreate**, not on reload — `docker compose up -d` recreates any service whose definition changed (all three here) and **keeps the volumes**, so no data is lost. Mailpit's pinned tag triggers a pull on first `up`.

Half-applied states and how to leave them:

- **Task 2 applied with `$` instead of `$$`.** The container recreates and then sits `unhealthy` forever. `docker inspect tm-mysql --format '{{json .State.Health}}'` shows the failing output; `docker compose config | grep MYSQL_USER` shows whether the escape survived. Fix the file and `docker compose up -d`; no volume work needed.
- **Task 3 applied with a tag that does not exist.** `up` fails at pull with `manifest unknown` and MySQL is left running. Correct the tag (`v1.31.0`, with the `v`) and re-run.
- **A root `.env` created with a changed credential.** MySQL ignores it (first-boot only) and the healthcheck rejects it — see the edge case above. Either revert `.env` and `docker compose up -d`, or accept the reset: `docker compose down -v && docker compose up -d --wait`, then `cd backend && php artisan migrate --seed`.

**Full rollback:** `git checkout -- docker-compose.yml README.md && rm -f .env.example .env && docker compose up -d`. Volumes survive, so the development database is untouched. Nothing in this story writes a migration or changes application code, so there is no schema state to unwind.

---

## Verification Steps

Run in this order. Working directory is stated for every command.

1. **Baseline recorded:** repo root — `docker compose ps` and `docker volume ls | grep ticket-mangment` before any edit. You need this to show what changed.
2. **Compose file is valid:** repo root — `docker compose config --quiet` exits `0`. Then `docker compose config | grep -A3 'healthcheck'` and confirm both MySQL services show `CMD-SHELL` with **`$$MYSQL_USER`** (double dollar) and that `mailpit` now has a `healthcheck` block.
3. **Defaults unchanged:** Test Plan step 3 — the `diff` of `docker compose config` with and without a root `.env` is empty.
4. **Variable inventory matches the documentation:** repo root — `grep -on '\${[A-Z_]*' docker-compose.yml | sort -u -t: -k2` returns nine distinct names, and every one appears in `.env.example`. `DB_ROOT_PASSWORD` must now appear at lines **18 and 44 only** (the two `MYSQL_ROOT_PASSWORD` assignments) — its occurrences inside the healthchecks are gone.
5. **Backend builds / stack starts:** repo root — `docker compose down -v && docker compose up -d --wait` exits `0`. `docker compose ps` shows `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all `(healthy)`.
6. **`healthy` is honest:** Test Plan steps 1 and 2. No sample shows `health=healthy tcp=no`, and a `SELECT 1` immediately after `--wait` returns `1`.
7. **Backend reaches the database:** `backend/` — `php artisan migrate --force` succeeds with no retry, run **immediately** after step 5 with no manual wait. This is the acceptance criterion the defect broke.
8. **Mail path works end to end (criterion 3):** http://localhost:8025 loads the Mailpit UI. Then `backend/` — `php artisan tinker` → `Mail::raw('TM-5 smoke test', fn ($m) => $m->to('dev@ticket-management.test')->subject('TM-5'));` and confirm the message appears in the UI. Confirms `MAIL_HOST` / `MAIL_PORT` (`backend/.env.example:52-53`) reach the container. `docker compose exec mailpit /mailpit readyz` also exits `0`.
9. **Persistence and wipe (criterion 2):** Test Plan step 6 — a row survives `down` + `up`, and `down -v` removes the schema entirely.
10. **README matches the compose file:** read `README.md` §1 and §Ports side by side with `docker-compose.yml`. Every port, container name and variable name matches. Every command in the README is copy-pasteable — actually paste each one.
11. **Env files tracked correctly:** repo root — `git check-ignore -q .env` exits `0`; `git check-ignore -q .env.example` exits `1`.
12. **Regression:** Test Plan steps 7 and 8 — `composer test` exits `0` with TM-3's counts (with and without a root `.env`), and `git status --short` names no file under `backend/` or `frontend/`.
13. **Leave the machine as you found it:** remove any override you added in Test Plan steps 4 and 5 from `.env`, then `docker compose up -d --wait` and confirm all three are `healthy` on the documented defaults.

---

## Done Criteria

- [ ] Root **`.env.example`** exists, is tracked, and documents all nine Compose variables with their current defaults; the header states plainly that Compose reads **this** file and **not** `backend/.env`; each variable names the file that must change alongside it. Root `.env` exists locally and is git-ignored.
- [ ] `docker compose config` output is **identical** with and without a root `.env` — no default drifted from `backend/.env.example` or `backend/phpunit.xml`.
- [ ] Both MySQL healthchecks connect **over TCP as the application user to the application database** using `$$`-escaped container environment variables, with `start_period: 60s`, and redirect output to `/dev/null`. `mysqladmin ping` appears nowhere in `docker-compose.yml`.
- [ ] A cold `docker compose up -d` never reports `healthy` while port 3306 is closed (Test Plan step 1 output recorded, ideally alongside the pre-change run that reproduces the bug).
- [ ] `docker compose up -d --wait` returns only once all three services are healthy, and `php artisan migrate --force` succeeds immediately afterwards with no manual wait.
- [ ] `mailpit` is pinned to **`axllent/mailpit:v1.31.0`** and declares its own `healthcheck` (`/mailpit readyz`) rather than inheriting one from the image. No volume was added to it.
- [ ] `mysql-test`'s database name is `${DB_TEST_DATABASE:-ticket_management_test}` and the resolved default is unchanged.
- [ ] `README.md` documents **start** (`up -d --wait`, with the `ps` fallback), **stop** (`stop`, `down`), **wipe** (`down -v`) and **both resets** (`migrate:fresh --seed`, and the full volume reset), plus the override coupling table and the "Changing a credential" note. The Environment-files section lists all three `.env` files.
- [ ] Mailpit UI reachable at http://localhost:8025 and a message sent from `php artisan tinker` arrives in it.
- [ ] A row survives `docker compose down` + `up`; `docker compose down -v` removes both databases.
- [ ] `composer test` exits `0` with TM-3's counts, both with and without a root `.env`. `git status --short` lists no path under `backend/` or `frontend/`.
- [ ] Overview `00-overview.md` updated with this story.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 05 (TM-6).**
