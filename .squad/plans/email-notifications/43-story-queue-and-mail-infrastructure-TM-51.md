# Story 43 — Queue and mail infrastructure (Story: TM-51)

## Prerequisites

- **None of the seven E8 stories is planned yet; this is the first.** `.squad/plans/email-notifications/` contains only `00-overview.md` with an empty story table. TM-52 through TM-57 all sit on top of what this story establishes, and **every one of them is blocked by it** — there is no mailable, no notification and no `app/Jobs` directory anywhere in the tree (`grep -rn "ShouldQueue" backend/app backend/routes backend/database` returns **nothing**).
- **Docker up**, all three containers healthy. Measured today: `docker compose ps` reports `tm-mysql` (3306), `tm-mysql-test` (3307) and `tm-mailpit` (1025 / 8025) all **healthy**.
- **No new composer or npm dependency, no migration, no route, no controller, no frontend file.** Everything this story needs is already installed; the work is configuration, one guard class, documentation and tests.
- **Baseline, 2026-08-27, re-measure whatever is merged when you start.** `composer test` → **101 tests, 98 passing, 3 failing, 289 assertions, 3.8s**. The three failures are pre-existing and unrelated to queues or mail:
  | Failing test | Owner |
  |---|---|
  | `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (429 vs 422) | TM-14 |
  | `Authorization\RouteAuthorizationTest::test_every_api_route_is_classified` (missing `tickets.store`) | TM-22 — **Story 32 adopts it**, see [`../status-workflow-escalation/00-overview.md`](../status-workflow-escalation/00-overview.md) |
  | `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` | TM-21 |
  **This story must not change that count except by adding its own passing tests.** `./vendor/bin/pint --test` exits `0`.
- **Story 37 (TM-43) is the only planned story that already depends on a queue, and it deliberately does not use one.** [`../status-workflow-escalation/37-story-flag-stale-tickets-on-a-schedule-TM-43.md`](../status-workflow-escalation/37-story-flag-stale-tickets-on-a-schedule-TM-43.md) dispatches a plain `TicketsFlaggedStale` event with **no `ShouldQueue`** and records that **no E8 story owns a stale-ticket digest**. That gap is still open after this story; it is a backlog decision, not something to fix here.

---

## Story Goal

**This is an audit-and-close story, not a greenfield build.** The queue driver, the three queue tables and the Mailpit container are all already in place and were measured working end to end while this plan was written. What is missing is a guarantee, a document and a test.

Audit of the four acceptance criteria against the code as it stands, **all four measured on 2026-08-27**:

| # | Criterion | Verdict | Evidence |
|---|---|---|---|
| 1 | Queue uses the database driver with `jobs` and `failed_jobs` migrated | ✅ **already true in the app, ❌ contradicted by the test suite** | `config('queue.default')` = `database`; `0001_01_01_000002_create_jobs_table.php` ran in batch 1 and creates all three tables. **But `phpunit.xml:45` forces `QUEUE_CONNECTION=sync`**, so the only place that runs automatically proves the opposite. |
| 2 | Development mail goes to Mailpit and no email can reach a real address from a dev environment | ⚠️ **half true** | A message sent through `MAIL_HOST=127.0.0.1:1025` was captured by Mailpit (`{"Subject":"TM-51 SMTP probe","To":["nobody@example.test"],"From":"helpdesk@ticket-management.test"}`). **Nothing stops a `.env` from pointing `MAIL_MAILER` or `MAIL_HOST` at a real relay.** "Nothing leaves the machine" is currently a convention, not an enforced rule. |
| 3 | README documents running the queue worker and where to view captured mail | ⚠️ **half true** | `README.md:106–107` and `121–122` document the Mailpit ports and UI. **The words "queue worker", `queue:work` and `queue:listen` appear nowhere in the repository's documentation** — and `composer dev` has been silently running a worker all along (see the decision below). |
| 4 | Failed jobs land in `failed_jobs` with their exception and can be retried by command | ✅ **already true, ❌ undocumented and untested** | Measured: a failing job produced `uuid=c07bac98-…`, `connection=database`, `queue=default`, `failed_at=2026-08-27 10:43:21`, `exception=Error: Call to a member function bindTo() on null in …`. `php artisan queue:failed` listed it and `php artisan queue:retry <uuid>` accepted it. |

So the deliverable is four things:

1. The test suite stops lying about the queue — `phpunit.xml` runs on the **database** driver, and a feature test proves a dispatched job is a row rather than an inline call.
2. A guard makes AC2 enforced rather than assumed: in `local` and `testing`, a send is **refused** unless the transport cannot reach the internet.
3. `README.md` grows the queue-and-mail section it never had, including the `composer dev` behaviour nobody has written down.
4. Failed-job handling gets its commands documented and its behaviour pinned by tests.

**Not in scope, and each belongs to a named story.** **No mailable, no notification class, no Blade mail layout, no `app/Jobs` directory** — TM-52 through TM-56 own every line of that. **No `tries`, `backoff`, `retryUntil` or `ShouldQueueAfterCommit` on any class** — TM-57 (E8-S7) owns the retry policy, and this story writes none of it. **No change to `queue.connections.database.after_commit`** (currently `false`, `config/queue.php:44`) — that flag is TM-57's fourth acceptance criterion and flipping it here would silently pre-empt that story. **No queue-depth or mail probe on `GET /api/v1/health`** — the AC does not ask, and a worker-liveness check is a deployment concern that belongs with TM-63. **No Horizon, no Redis, no Supervisor config.** **No frontend file at all.**

---

## Decision — the guard runs at send time, not at boot

`App\Services\MailSafety` is registered as a listener on `Illuminate\Mail\Events\MessageSending` and **throws**. It is not a check in `AppServiceProvider::boot()`.

- **`MessageSending` is dispatched through `until()`** — `Mailer::shouldSendMessage()` at `vendor/laravel/framework/src/Illuminate/Mail/Mailer.php:602–609` returns `$this->events->until(new MessageSending(...)) !== false`, and the call site is `Mailer.php:331`, **before** `sendSymfonyMessage()`. `EsmtpTransport` connects lazily inside that call, so **the guard fires before a socket is opened**. Nothing is transmitted, nothing is half-sent.
- **It covers the queue worker for free.** A queued mailable is serialised in the request and sent in a separate `queue:work` process; a boot-time check in the web process would not run there, and the listener does.
- **A boot-time throw would brick the CLI.** `MAIL_MAILER=ses` in a dev `.env` would make *every* artisan command — including the ones used to diagnose it — die at bootstrap. Failing when mail is actually attempted fails at the right moment.
- **The listener must not return `false`.** `until()` treats `false` as "cancel this message silently", which is the one outcome worse than throwing: the developer sees a green request and no email. Register a **block closure with no return statement**, not `fn () => …`.

## Decision — safety is a property of the transport, not of the recipient

The guard asks *"can this mailer reach the internet?"*, never *"does this address look real?"*.

- **A recipient allowlist would break every story that follows.** TM-52 asserts an agent's address receives the mail, TM-55 asserts **all active admins** do. Seeded and factory users have `@example.com` and `@ticket-management.test` addresses today, but pinning the product to a domain suffix would make "email the right person" untestable the moment a developer creates a user with a real address — while Mailpit swallows it harmlessly anyway.
- **`Mail::alwaysTo()` is deliberately not used.** It rewrites the recipient, which is precisely the field TM-52, TM-54 and TM-55 need to assert in Mailpit. A guard that destroys the evidence is worse than no guard.
- **The real risk is a one-line `.env` edit**, and that is what is checked: `log` and `array` cannot deliver anywhere; `smtp` can, and is safe **only** when it points at a local catcher.

## Decision — `MAIL_URL` is checked, because it silently overrides the host

`config/mail.php:43` reads `'url' => env('MAIL_URL')`. When set, Symfony's DSN wins and `MAIL_HOST` is ignored — so a guard that only inspected `mail.mailers.smtp.host` would wave through `MAIL_URL=smtp://user:pass@smtp.sendgrid.net:587`. The guard resolves the host from the URL when there is one and from `host` otherwise. **Do not simplify this away.**

## Decision — `phpunit.xml` moves from `sync` to `database`

`phpunit.xml:45` currently reads `<env name="QUEUE_CONNECTION" value="sync"/>`.

- **`sync` makes the whole epic untestable in the way it needs to be tested.** TM-52's fifth criterion is *"a feature test asserts the notification is queued rather than sent synchronously"*, and TM-57's first is *"no notification is sent inline during a request"*. Under `sync` a queued mailable executes inside the request, so a test written against it passes for an implementation that does exactly what the criterion forbids.
- **It is safe to flip today and will never be safer.** `grep -rn "ShouldQueue\|dispatch(\|->queue(" backend/app backend/database backend/routes` returns **nothing** — no existing test can regress, because nothing in the application dispatches anything.
- **`RefreshDatabase` already migrates `jobs`, `job_batches` and `failed_jobs`** (they are in the batch-1 migration), and rows written to them roll back with every other table at the end of each test.
- **CI needs no change.** `phpunit.xml`'s `<env>` values win over `.env` because Laravel's Dotenv loader never overwrites an already-set environment variable — the same mechanism that already points the suite at port 3307.

## Decision — `composer dev` already runs a worker, and the README must say so

`backend/composer.json:45–48` maps `composer dev` to `php artisan dev`, and `vendor/laravel/framework/src/Illuminate/Foundation/DevCommands.php:92–108` registers its default processes:

```
self::artisan('serve', 'server');
self::artisan('queue:listen --tries=1 --timeout=0', 'queue');
… pail (when pcntl_fork exists) …
… node('dev') when package.json exists …
```

Two consequences that are worth more than the sentence they cost:

- **A developer running `composer dev` already has a worker**, and will be confused by a README that tells them to start one. The document says both.
- **`--tries=1` means no retry under `composer dev`.** A mailable that declares `public $tries = 3` (TM-57) will still land in `failed_jobs` on its **first** failure there, because the worker's flag overrides the class default only when the class does not set one — and either way the behaviour differs from `php artisan queue:work`. Whoever debugs a "why did my retry not happen" ticket needs this written down.

---

## Context — Read These Files First

1. `backend/phpunit.xml` — **lines 27–39** are the model for the comment task 4 writes: an `<!-- -->` block above an `<env>` explaining *why* the suite is configured against the grain. **Line 45** is the single line that changes.
2. `backend/config/queue.php` — **line 16** (`'default' => env('QUEUE_CONNECTION', 'database')`), **lines 38–45** (the `database` connection, `retry_after` 90, `after_commit` **false** — leave it), **lines 123–127** (`failed.driver` = `database-uuids`, table `failed_jobs`). **This file is not edited by this story.** Read it so you can confirm that AC1 is already satisfied and resist "improving" it.
3. `backend/config/mail.php` — **line 17** (`'default' => env('MAIL_MAILER', 'log')`), **lines 40–50** (the `smtp` mailer: note **`'url' => env('MAIL_URL')` at line 43** taking precedence over `host`), **lines 73–80** (`log` and `array`), **lines 82–98** (`failover` fans out to `smtp` + `log`; `roundrobin` fans out to `ses` + `postmark`), **lines 113–116** (`from`). Task 1 appends one block **after line 116**, inside the closing `];`.
4. `backend/config/seeding.php` — **9 lines, the only bespoke config file in the project.** A flat `return [...]` of `env()` calls. Task 1's block matches its density, not Laravel's boxed-comment style — but it goes *inside* `config/mail.php`, following how `config/app.php` gained `'version'` rather than getting a file of its own.
5. `backend/app/Providers/AppServiceProvider.php` — **`boot()` at 23–28**, currently two `RateLimiter::for()` registrations. Task 3 adds one `Event::listen()` call here. There is no `EventServiceProvider` and `bootstrap/providers.php` lists **only** `AppServiceProvider`; **do not create a second provider for one listener.**
6. `backend/app/Http/Controllers/Api/V1/HealthController.php` — **line 30** reads `config('app.env')`, not `app()->environment()`. Task 2 follows it, and the reason is testability: `config()->set('app.env', 'production')` works in a test, whereas `Application::environment()` reads the container's `env` binding fixed at bootstrap.
7. `backend/app/Services/ActivityRecorder.php` — the shape task 2 matches: a plain class in `App\Services`, no interface, no facade wrapper, throwing `LogicException`/`RuntimeException` on a violated precondition (**26–28**).
8. `backend/tests/Feature/Database/RequestersTableSchemaTest.php` — **the precedent for a schema assertion** (`Schema::hasColumns` at 17). Task 7's table test copies its shape.
9. `backend/tests/Feature/HealthDegradedTest.php` — **the precedent for breaking configuration inside a test** (`config()->set(...)` then asserting the failure path, **32–37**). Task 8's guard tests follow it.
10. `README.md` — **lines 98–122**: the `### Ports` table (Mailpit rows at **106–107**) and the existing claim at **121–122**, *"**No mail leaves the machine in development** — Mailpit captures every outgoing message."* Task 5 inserts a new `## Queue and mail` section **between line 122 and the `## Repository conventions` heading at line 124**, and that existing claim becomes true by enforcement rather than by convention.
11. `backend/.env.example` — **lines 46–47** (the `QUEUE_CONNECTION` comment, already reading *"Notifications are queued. In development run: php artisan queue:work"*) and **lines 51–60** (the Mailpit block). Task 6 edits both. **The last three lines (72–74) are TM-43's; do not touch them.**
12. `docs/deployment-runbook.md` — **24 lines, still a placeholder** with `_TBD_` environments. **TM-63 (E9-S5) owns filling it in.** Task 9 adds one section and leaves every placeholder around it alone, exactly as Story 37's task 7 does.
13. `backend/composer.json:45–48` and `vendor/laravel/framework/src/Illuminate/Foundation/DevCommands.php:92–108` — the source for the `composer dev` claim in task 5. **Read the second one**; it is the only place the `--tries=1` behaviour is visible.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `php artisan test` dispatches a job | Runs **inline** (`sync`) | Stored as a row in `jobs`; **does not run** |
| `APP_ENV=local`, `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1` | Sends to Mailpit | Unchanged — sends to Mailpit |
| `APP_ENV=local`, `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com` | **Delivers to a real inbox** | `RuntimeException` before the socket opens |
| `APP_ENV=local`, `MAIL_URL=smtp://…@smtp.sendgrid.net:587` | **Delivers to a real inbox** | `RuntimeException` — the URL's host is checked, not `MAIL_HOST` |
| `APP_ENV=local`, `MAIL_MAILER=ses` / `postmark` / `resend` / `sendmail` | Delivers (or fatals on a missing SDK) | `RuntimeException` naming the mailer and the transport |
| `APP_ENV=local`, `MAIL_MAILER=log` or `array` | Captured locally | Unchanged — allowed |
| `APP_ENV=local`, `MAIL_MAILER=failover` (→ `smtp`, `log`) | Sends via whichever succeeds | Allowed **only if `smtp` is itself safe**; each nested mailer is checked |
| `APP_ENV=local`, `MAIL_MAILER=roundrobin` (→ `ses`, `postmark`) | Delivers | `RuntimeException` on the first unsafe nested mailer |
| `APP_ENV=testing` (the suite) | `array` transport | Unchanged — `array` is safe, the suite is unaffected |
| `APP_ENV=production`, any mailer | Delivers | **Unchanged — production is not guarded.** Mail must work there. |
| A job throws | Row in `failed_jobs` with the exception | Unchanged, now documented and tested |
| `php artisan queue:retry <uuid>` | Pushes it back | Unchanged, now documented and tested |
| `composer dev` | Silently runs `queue:listen --tries=1 --timeout=0` | Unchanged, **now documented** |

---

## Backend Tasks

### 1 — The safety configuration

**File: `backend/config/mail.php`**

Append after the `'from'` block (**after line 116**, inside the closing `];`):

```php
    /*
    |--------------------------------------------------------------------------
    | Development Mail Safety
    |--------------------------------------------------------------------------
    |
    | In a guarded environment a message must not be able to leave the machine.
    | Mailpit already swallows everything sent to 127.0.0.1:1025, so the risk
    | is not this file's defaults — it is a .env that points MAIL_MAILER or
    | MAIL_URL at a real relay. App\Services\MailSafety enforces the rule on
    | Illuminate\Mail\Events\MessageSending, before the transport connects.
    |
    | Production is deliberately absent: mail has to work there.
    |
    */

    'safety' => [

        'guarded_environments' => ['local', 'testing'],

        // Transports that cannot deliver anywhere.
        'safe_transports' => ['log', 'array'],

        // SMTP is safe only when it points at a local catcher such as Mailpit.
        'safe_smtp_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MAIL_SAFE_SMTP_HOSTS', '127.0.0.1,localhost,::1,mailpit')),
        ))),

    ],
```

**Do not touch `config/queue.php`.** Its two `env('DB_CONNECTION', 'sqlite')` fallbacks (**106** and **125**) look wrong for a MySQL-only project, but `config/database.php:20` carries the identical fallback and both `.env.example` and `phpunit.xml` always set `DB_CONNECTION=mysql`, so the branch is unreachable. Fixing one of the three would be inconsistent; fixing all three is not this story.

### 2 — The guard

**Create file: `backend/app/Services/MailSafety.php`**

```php
<?php

namespace App\Services;

use RuntimeException;

/**
 * Development mail must not be able to leave the machine.
 *
 * Registered on Illuminate\Mail\Events\MessageSending, which the Mailer
 * dispatches through until() *before* the transport connects — so a refusal
 * throws instead of half-sending. The rule is a property of the transport,
 * never of the recipient: rewriting or filtering addresses would destroy the
 * evidence every E8 story needs to assert in Mailpit.
 */
class MailSafety
{
    /** @var list<string> */
    private const FAN_OUT_TRANSPORTS = ['failover', 'roundrobin'];

    public function guardOutgoingMail(): void
    {
        if (! $this->isGuardedEnvironment()) {
            return;
        }

        $this->assertMailerIsSafe((string) config('mail.default'));
    }

    public function isGuardedEnvironment(): bool
    {
        return in_array(
            config('app.env'),
            (array) config('mail.safety.guarded_environments', []),
            true,
        );
    }

    /**
     * @param  list<string>  $seen  mailers already visited, so a failover cycle terminates
     */
    public function assertMailerIsSafe(string $mailer, array $seen = []): void
    {
        if (in_array($mailer, $seen, true)) {
            return;
        }

        $transport = config("mail.mailers.{$mailer}.transport");

        if (! is_string($transport)) {
            throw new RuntimeException(
                "Refusing to send mail: the [{$mailer}] mailer is not configured in config/mail.php."
            );
        }

        if (in_array($transport, self::FAN_OUT_TRANSPORTS, true)) {
            foreach ((array) config("mail.mailers.{$mailer}.mailers", []) as $nested) {
                $this->assertMailerIsSafe((string) $nested, [...$seen, $mailer]);
            }

            return;
        }

        if (in_array($transport, (array) config('mail.safety.safe_transports', []), true)) {
            return;
        }

        if ($transport === 'smtp' && $this->isLocalCatcher($this->smtpHost($mailer))) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Refusing to send mail: the [%s] mailer uses the [%s] transport%s in the [%s] environment, '
            .'which could reach a real inbox. Point MAIL_MAILER at Mailpit (smtp on %s) or set MAIL_MAILER=log.',
            $mailer,
            $transport,
            $transport === 'smtp' ? " on host [{$this->smtpHost($mailer)}]" : '',
            (string) config('app.env'),
            implode(', ', (array) config('mail.safety.safe_smtp_hosts', [])),
        ));
    }

    /**
     * MAIL_URL wins over MAIL_HOST when both are set (config/mail.php:43), so
     * the DSN's host is the one that matters.
     */
    private function smtpHost(string $mailer): string
    {
        $url = config("mail.mailers.{$mailer}.url");

        if (is_string($url) && $url !== '') {
            return (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        }

        return (string) config("mail.mailers.{$mailer}.host", '');
    }

    private function isLocalCatcher(string $host): bool
    {
        $safe = array_map(
            fn (string $candidate): string => mb_strtolower(trim($candidate)),
            (array) config('mail.safety.safe_smtp_hosts', []),
        );

        return $host !== '' && in_array(mb_strtolower($host), $safe, true);
    }
}
```

**`config('app.env')`, not `app()->environment()`** — task 2 follows `HealthController.php:30`, and it is what makes `test_it_does_not_guard_production` a two-line test instead of a container-rebinding one.

### 3 — Register the listener

**File: `backend/app/Providers/AppServiceProvider.php`**

Add to `boot()` (**after line 27**), keeping the two `RateLimiter::for()` registrations above it:

```php
        // Mail must not be able to leave a developer's machine. The Mailer
        // dispatches this through until(), so a listener returning false would
        // cancel the message *silently* — the guard throws instead, and this
        // closure must therefore never return a value.
        Event::listen(MessageSending::class, function (): void {
            $this->app->make(MailSafety::class)->guardOutgoingMail();
        });
```

New imports, in the existing alphabetical block at the top:

```php
use App\Services\MailSafety;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
```

**A block closure with `: void`, not an arrow function.** An arrow function whose body is a call to a `void` method returns `null` and would work today, but the next person to make `guardOutgoingMail()` return a `bool` turns a refusal into a silent drop. The explicit `void` closes that door.

### 4 — Make the suite run the real queue driver

**File: `backend/phpunit.xml`**

Replace **line 45** with a commented block, matching the style of the database comment at **27–39**:

```xml
        <!--
            The queue is exercised, not bypassed. Under "sync" a queued mailable
            runs inline inside the request, so a test asserting a notification
            was *queued* would pass against code that sends it synchronously —
            which is exactly what TM-52 and TM-57 forbid. RefreshDatabase
            migrates jobs, job_batches and failed_jobs like any other table and
            rolls their rows back with everything else.
        -->
        <env name="QUEUE_CONNECTION" value="database"/>
```

**Leave `MAIL_MAILER=array` (line 44) alone.** It is a safe transport under task 1's rules, so the suite passes the guard unchanged and no test touches a socket.

### 5 — Document the worker and the mail catcher

**File: `README.md`**

Insert a new top-level section **between line 122 and `## Repository conventions` (line 124)**:

````markdown
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
````

The `### Ports` table at **100–107** already lists both Mailpit rows and needs no change.

### 6 — Document the two variables

**File: `backend/.env.example`**

Replace **lines 46–47**:

```
# Notifications are queued on the database driver. The jobs, job_batches and
# failed_jobs tables ship with 0001_01_01_000002_create_jobs_table.php.
# Nothing is delivered unless a worker runs: php artisan queue:work
# Failed jobs: php artisan queue:failed / php artisan queue:retry <uuid>
QUEUE_CONNECTION=database
```

Add after **line 60** (`MAIL_FROM_NAME`):

```
# SMTP hosts treated as local mail catchers. When APP_ENV is local or testing,
# App\Services\MailSafety refuses to send unless the mailer is log/array or
# SMTP points at one of these. Add a host only if it cannot deliver to the
# internet. MAIL_URL, when set, overrides MAIL_HOST and is checked instead.
MAIL_SAFE_SMTP_HOSTS=127.0.0.1,localhost,::1,mailpit
```

**Leave lines 72–74 (`TICKETS_STALE_AFTER_HOURS`) untouched** — they are TM-43's.

### 7 — Note the worker in the runbook

**File: `docs/deployment-runbook.md`**

Add one section **after `## Post-deploy checks`**, leaving every `_TBD_` placeholder above it exactly as it is:

```markdown
## Queue worker

Every notification is queued (`QUEUE_CONNECTION=database`), so an environment
with no running worker accepts tickets normally and **silently delivers no
mail**. Each deployed environment needs:

- a supervised long-running `php artisan queue:work --tries=3` process, and
- `php artisan queue:restart` in the deploy procedure, after the new code is in
  place, so workers pick it up.

`App\Services\MailSafety` guards `local` and `testing` only — **production mail
is not guarded and will reach real inboxes.** Confirm `MAIL_MAILER`,
`MAIL_HOST`/`MAIL_URL` and `MAIL_FROM_ADDRESS` before the first production
deploy.

_Process manager, worker count and failed-job alerting: TBD with the rest of
this file (TM-63)._
```

**No frontend changes required.** Nothing in `frontend/` reads mail, queues or environment variables this story touches.

---

## Edge Cases & Failure Modes

- **A listener returning `false` cancels the message with no error.** `Mailer::shouldSendMessage()` (`Mailer.php:602–609`) uses `until()` and treats `false` as a veto. Task 3's closure is declared `: void` for exactly this reason; **a future refactor that makes `guardOutgoingMail()` return a boolean would turn every refusal into a silent drop.** Test 8.5 pins the throw.
- **`MAIL_URL` overrides `MAIL_HOST` silently.** `config/mail.php:43`. A guard reading only `host` would pass `MAIL_URL=smtp://…@smtp.sendgrid.net:587` straight through to a real relay. Enforced in `MailSafety::smtpHost()` and tested by 9.4.
- **`failover` and `roundrobin` hide their real transports one level down.** `config/mail.php:82–98` ships both; `roundrobin` fans out to `ses` and `postmark`, neither of which is safe. Enforced by the recursion in `assertMailerIsSafe()`; the `$seen` list makes a mailer that lists itself terminate instead of recursing forever. Tested by 9.5 and 9.6.
- **A mailer name that is not in `config('mail.mailers')`** — a typo such as `MAIL_MAILER=smpt` — throws the "not configured" branch rather than being treated as safe. **Fail closed, never open.** Tested by 9.7.
- **`APP_ENV=production` is not guarded and must not be.** Mail has to work in production; the guard's whole purpose is to protect *developers*. This is recorded in the runbook (task 7) rather than left implicit, and pinned by test 9.8 so nobody "hardens" it into blocking production mail.
- **The suite's `array` transport keeps working.** `phpunit.xml:44` sets `MAIL_MAILER=array`, which is in `safe_transports`, so `APP_ENV=testing` being guarded costs the existing suite nothing. If a future story needs to prove the guard fires, it clears `mail.safety.safe_transports` rather than opening a socket — see test 8.4.
- **Flipping the suite to the `database` queue driver changes what "the job ran" means.** From this story on, a test that dispatches something and expects its side effect must either `Queue::fake()`, run `$this->artisan('queue:work', ['--once' => true])`, or assert the `jobs` row. **Nothing dispatches anything today** (`grep -rn "ShouldQueue\|dispatch(" backend/app backend/database backend/routes` is empty), so no existing test can regress — but TM-52 onwards must be written with this in mind.
- **`queue:work --once` inside a test is the one mechanism in this plan that is not yet proven in-process.** It was measured working from the CLI (a queued `Illuminate\Mail\Mailable` went `jobs=1` → `DONE` in 520 ms → captured by Mailpit; a failing job went `FAIL` → `failed_jobs=1` with its exception). Inside PHPUnit the worker shares the process and the `RefreshDatabase` transaction, so it should see and write the same rows. **Run test 7.4 first.** If the worker does not observe the row, fall back to asserting through the failer directly — `$this->app['queue.failer']->log('database', 'default', $payload, $exception)` — and record in the PR that the worker was not driven in-process; do **not** silently weaken the assertion to a `Queue::fake()`.
- **`queue:work` with an empty queue sleeps before returning.** `--once` on an empty queue costs the configured `--sleep` (3 s by default). Every test that drives the worker must dispatch first; pass `--sleep=0` if a test ever needs to drive an empty queue.
- **`retry_after` is 90 seconds and no job declares a timeout yet** (`config/queue.php:43`). A mail job that blocks longer than 90 s on an unreachable SMTP host would be released and run twice. **TM-57 owns `tries`, `backoff` and `timeout`**; this story records the number rather than setting a policy it does not own.
- **`after_commit` is `false`** (`config/queue.php:44`). A job dispatched inside a transaction is queued immediately and a worker can pick it up before the commit. **This is TM-57's fourth acceptance criterion and is deliberately left alone here** — see Story Goal.
- **Two workers race.** `composer dev` already runs `queue:listen`; a second `queue:work` in another terminal competes for the same rows. Harmless (the database driver locks each row) but confusing when debugging, which is why task 5 says so.
- **`config:cache` freezes the guard's settings.** If a developer runs `php artisan config:cache` and then edits `MAIL_SAFE_SMTP_HOSTS`, the old list stays in force until `config:clear`. `composer test` already runs `config:clear` first (`composer.json:49–52`), so the suite is immune; the README's existing conventions cover the rest.

---

## Test Plan

All backend. **7 and 8 are Feature tests; 9 is a Unit test.** No frontend test changes.

**Create file: `backend/tests/Fixtures/Jobs/FailingJob.php`** — a fixture, not a test. `composer.json:31–35` maps `Tests\` to `tests/`, and `phpunit.xml:7–14` collects only `tests/Unit` and `tests/Feature`, so a class here is autoloadable and never collected.

```php
<?php

namespace Tests\Fixtures\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class FailingJob implements ShouldQueue
{
    use Queueable;

    public const MESSAGE = 'TM-51 fixture job failed on purpose';

    public function handle(): void
    {
        throw new RuntimeException(self::MESSAGE);
    }
}
```

**7. `backend/tests/Feature/Queue/QueueInfrastructureTest.php`** (new, `RefreshDatabase`) — AC1 and AC4.

1. `test_the_default_queue_connection_uses_the_database_driver` — `config('queue.default')` is `database` **and** `config('queue.connections.database.driver')` is `database`. Catches a `.env` or `phpunit.xml` regression in one assertion.
2. `test_the_queue_tables_exist_with_the_columns_the_driver_needs` — `Schema::hasColumns('jobs', ['queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at'])` and `Schema::hasColumns('failed_jobs', ['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])`, plus `Schema::hasTable('job_batches')`. Shape from `RequestersTableSchemaTest.php:17`.
3. `test_a_dispatched_job_is_stored_rather_than_run_inline` — `FailingJob::dispatch()`, then assert `DB::table('jobs')->count() === 1` and `DB::table('failed_jobs')->count() === 0`. **The job throws, so under `sync` this test fails with the fixture's exception** — which is precisely the regression guard for task 4.
4. `test_a_failing_job_lands_in_failed_jobs_with_its_exception` — dispatch, `$this->artisan('queue:work', ['--once' => true])`, then assert `failed_jobs` has one row, `jobs` is empty, and the row's `exception` contains `FailingJob::MESSAGE` and its `connection`/`queue` are `database`/`default`. **Run this one first** — see Edge Cases.
5. `test_a_failed_job_can_be_pushed_back_onto_the_queue_by_command` — after test 4's setup, `$this->artisan('queue:retry', ['id' => ['all']])->assertExitCode(0)`, then assert `failed_jobs` is empty and `jobs` has one row again. The argument name is `id` and it is variadic (`RetryCommand.php:22–23`).

**8. `backend/tests/Feature/Mail/MailSafetyGuardTest.php`** (new, no `RefreshDatabase` needed) — AC2, the wiring.

1. `test_the_suites_array_transport_is_allowed` — `Mail::raw('body', fn ($m) => $m->to('nobody@example.test')->subject('probe'))` completes with no exception under the suite's own configuration.
2. `test_smtp_pointed_at_mailpit_is_allowed` — assert `app(MailSafety::class)->assertMailerIsSafe('smtp')` does not throw after `config()->set('mail.mailers.smtp.host', '127.0.0.1')`. **Assert against the guard, never by sending** — CI has no Mailpit container and a real SMTP connection would hang the job.
3. `test_smtp_pointed_off_the_machine_is_refused` — `config()->set('mail.mailers.smtp.host', 'smtp.gmail.com')`, expect `RuntimeException` from `assertMailerIsSafe('smtp')`, and assert the message names both `smtp.gmail.com` and `testing`.
4. `test_the_guard_is_wired_to_the_message_sending_event` — set `config(['mail.safety.safe_transports' => []])` so the suite's own `array` transport becomes unsafe, then expect `Mail::raw(...)` to throw `RuntimeException`. **This proves the listener is registered without opening a socket or depending on a container.**
5. `test_a_refused_message_throws_rather_than_being_dropped_silently` — same setup as 8.4; assert the exception type explicitly rather than only that no mail was recorded. This is the test that fails if someone rewrites the listener to `return false`.

**9. `backend/tests/Unit/Services/MailSafetyTest.php`** (new; extends `Tests\TestCase` like `tests/Unit/Enums/UserRoleTest.php:8`, so `config()` is available) — AC2, the rules.

1. `test_log_and_array_transports_are_safe`.
2. `test_a_sending_transport_is_refused` — loop over `ses`, `postmark`, `resend`, `sendmail`; each throws.
3. `test_the_message_names_the_mailer_the_transport_and_the_environment` — one assertion on the string, so the developer who trips it knows what to change.
4. `test_mail_url_is_checked_instead_of_mail_host` — `host` = `127.0.0.1` (safe) **and** `url` = `smtp://user:pass@smtp.sendgrid.net:587`; assert it throws. Then `url` = `smtp://127.0.0.1:1025` with `host` = `smtp.gmail.com`; assert it does **not**.
5. `test_failover_is_safe_only_when_every_nested_mailer_is` — the shipped `failover` (`smtp` + `log`) passes with a Mailpit host and throws with a Gmail one.
6. `test_roundrobin_fanning_out_to_ses_is_refused` — the shipped `roundrobin` (`ses` + `postmark`).
7. `test_an_unknown_mailer_is_refused` — `assertMailerIsSafe('smpt')` throws the "not configured" message. **Fails closed.**
8. `test_production_is_not_guarded` — `config()->set('app.env', 'production')` plus `mail.default` = `ses`; `guardOutgoingMail()` returns with no exception. Then `config()->set('app.env', 'local')` and assert the same call throws.
9. `test_a_mailer_listing_itself_does_not_recurse_forever` — a `failover` whose `mailers` array contains itself plus `log`; assert it returns rather than exhausting the stack.

**No existing test is modified or deleted.** The three pre-existing failures listed in the Prerequisites stay exactly as they are.

---

## Migration / Rollback

**No migration.** `0001_01_01_000002_create_jobs_table.php` already ran in batch 1 and creates `jobs`, `job_batches` and `failed_jobs`; this story adds no column and no index.

Rollback is `git revert` of the commit, with two things to know:

- **`phpunit.xml` is the only change with a blast radius.** Reverting it puts the suite back on `sync`; if TM-52+ tests have landed by then, they will start passing for the wrong reason rather than failing loudly. **Revert the whole story or none of it.**
- **Reverting the guard cannot lose mail.** It only ever refuses; nothing depends on it having run.

Half-applied states worth naming:

- **Guard merged, `MAIL_SAFE_SMTP_HOSTS` missing from a developer's `.env`.** The `env()` default in task 1 covers it — `127.0.0.1`, `localhost`, `::1`, `mailpit` — so an un-updated `.env` still works. **Do not remove that default in favour of requiring the variable.**
- **`phpunit.xml` merged, `.env` not.** Irrelevant: the suite never reads `backend/.env` for this value.
- **A developer with a cached config.** `php artisan config:clear` after pulling. `composer test` already does it.

---

## Verification Steps

1. **Services up:** from the repo root, `docker compose up -d --wait`, then `docker compose ps` shows `tm-mysql`, `tm-mysql-test` and `tm-mailpit` all **healthy**.
2. **Backend tests:** from `backend/`, `composer test`. Expect the three pre-existing failures from the Prerequisites and **nothing else**; the new tests in 7, 8 and 9 all pass.
3. **The two new suites alone:** `php artisan test --filter='QueueInfrastructureTest|MailSafetyGuardTest|MailSafetyTest'`.
4. **Formatting:** `./vendor/bin/pint --test` exits `0`.
5. **The `sync` regression guard actually guards.** Set `phpunit.xml`'s `QUEUE_CONNECTION` back to `sync` and run `php artisan test --filter=QueueInfrastructureTest`. **Tests 7.1 and 7.3 must fail.** Restore `database`.
6. **The guard actually guards, by hand.** In `backend/.env`, set `MAIL_HOST=smtp.gmail.com`, run `php artisan config:clear`, then `php artisan tinker --execute="Mail::raw('x', fn(\$m) => \$m->to('nobody@example.test')->subject('probe'));"`. **Expect a `RuntimeException` naming `smtp.gmail.com`, and no new message in Mailpit.** Restore `MAIL_HOST=127.0.0.1`.
7. **End to end, the way a developer will use it.** With `MAIL_HOST` restored:
   ```bash
   cd backend
   php artisan tinker --execute="\$m = new \Illuminate\Mail\Mailable; \$m->subject('TM-51 verification')->html('<p>hi</p>'); Mail::to('nobody@example.test')->queue(\$m); echo DB::table('jobs')->count();"
   ```
   prints `1` and **nothing is in Mailpit yet** (http://localhost:8025). Then `php artisan queue:work --once` prints `DONE` and the message appears in Mailpit. This is the exact sequence measured while planning: `jobs=1` → `Illuminate\Mail\Mailable .. 520.00ms DONE` → `mailpit_total 1`.
8. **Failed-job round trip, by hand.** `php artisan tinker --execute="\Tests\Fixtures\Jobs\FailingJob::dispatch();"` — or any throwing job — then `php artisan queue:work --once`, `php artisan queue:failed` (one row, with the uuid), `php artisan queue:retry all`, `php artisan queue:flush`. **Leave the tables empty when you are done.**
9. **Documentation is true, not aspirational.** Read the new README section against the code: the `composer dev` claim must match `vendor/laravel/framework/src/Illuminate/Foundation/DevCommands.php:99`, and every command in the failed-jobs table must appear in `php artisan list queue`.
10. **Regression:** `git diff --stat` touches only `backend/config/mail.php`, `backend/app/Services/MailSafety.php`, `backend/app/Providers/AppServiceProvider.php`, `backend/phpunit.xml`, `backend/.env.example`, `README.md`, `docs/deployment-runbook.md` and the four test/fixture files. **No migration, no route file, no controller, no `frontend/` file, no `config/queue.php`.**

---

## Done Criteria

- [ ] `config('queue.default')` is `database` and a feature test asserts it; `jobs`, `job_batches` and `failed_jobs` are migrated and their columns asserted. *(AC1)*
- [ ] `backend/phpunit.xml` runs the suite on the **database** queue driver, with a comment saying why, and a dispatched job is proven to be stored rather than executed inline. *(AC1)*
- [ ] `App\Services\MailSafety` refuses a send in `local` and `testing` unless the transport is `log`/`array` or SMTP on a host in `MAIL_SAFE_SMTP_HOSTS`, checking `MAIL_URL` ahead of `MAIL_HOST` and recursing through `failover`/`roundrobin`. *(AC2)*
- [ ] The guard is registered on `MessageSending` from `AppServiceProvider::boot()` in a closure that **throws and never returns `false`**, and a test proves the wiring without opening a socket. *(AC2)*
- [ ] `APP_ENV=production` is provably unguarded, and the runbook says so. *(AC2)*
- [ ] `README.md` has a `## Queue and mail` section covering `queue:work`, `queue:listen`, restarting after code changes, **the worker `composer dev` already runs and its `--tries=1` consequence**, and the Mailpit UI at http://localhost:8025. *(AC3)*
- [ ] `backend/.env.example` documents `QUEUE_CONNECTION` with the worker command and `MAIL_SAFE_SMTP_HOSTS` with its rule; `TICKETS_STALE_AFTER_HOURS` is untouched. *(AC3)*
- [ ] A failing job is proven to land in `failed_jobs` with its exception, and `queue:retry` is proven to push it back onto `jobs`; both, plus `queue:failed`, `queue:forget`, `queue:flush` and `queue:prune-failed`, are documented in the README. *(AC4)*
- [ ] `composer test` shows the same three pre-existing failures and no new ones; `./vendor/bin/pint --test` exits `0`.
- [ ] No migration, no route, no mailable, no notification, no `tries`/`backoff`/`after_commit` change, no `frontend/` file.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 44.**
