# Story 49 — Notifications are queued, retried and never break a request (Story: TM-57)

## Prerequisites

- **The last story in E8, and every earlier one deferred something to it in writing.** This is the bill:
  | Owed by | What |
  |---|---|
  | [`43` (TM-51)](43-story-queue-and-mail-infrastructure-TM-51.md) | *"No change to `queue.connections.database.after_commit` (currently `false`, `config/queue.php:44`) — that flag is TM-57's fourth acceptance criterion."* It also recorded `retry_after` = **90** and that **no job declares a timeout yet**. |
  | [`44` (TM-52)](44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md) | `tries`, `backoff`, `timeout`, `retryUntil`, `ShouldQueueAfterCommit` |
  | [`45` (TM-53)](45-story-confirm-ticket-creation-to-the-requester-TM-53.md) | the same four |
  | [`46` (TM-54)](46-story-notify-the-requester-on-status-change-and-resolution-TM-54.md) | the same four |
  | [`47` (TM-55)](47-story-notify-admins-on-escalation-TM-55.md) | the same four |
  | [`48` (TM-56)](48-story-shared-responsive-mail-layout-TM-56.md) | *"TM-57 still owns the retry policy"* |
  **All six are hard gates.** There is no retry policy to write until all four notifications exist, and Story 48's `MailLayoutTest` is the pattern this story's cross-cutting suite copies.
- **Nothing on disk today.** `backend/app/Notifications/`, `app/Listeners/`, `app/Events/`, `config/notifications.php` and `resources/views/mail/` **all do not exist**. `config/queue.php:44` reads `'after_commit' => false` and **line 43** `'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90)`.
- **Gate:** `php artisan test --filter='TicketAssignedNotificationTest|TicketCreatedNotificationTest|TicketStatusChangedNotificationTest|TicketEscalatedNotificationTest|MailLayoutTest'` green before you start.
- **This story ships no new email, no new recipient, no new event, no new listener and no template change.** It adds a retry policy, a failure log, and one suite that asserts the four rules across all four events at once.
- **`pcntl` is required for `timeout` to do anything.** `php -m | grep pcntl` on the target machine; without it the worker cannot alarm out of a hung job and the `timeout` value is inert. **It is not an error and nothing breaks** — record the result in the PR.
- **No new composer or npm dependency, no migration, no route, no controller change, no frontend file.**
- **Baseline:** by the time this story runs, Stories 44–48 will have added roughly 90 tests to the 101 measured on 2026-08-27 (98 passing, 3 failing — `PasswordThrottleTest`, `RouteAuthorizationTest`, `TicketReferenceTest`, all pre-existing). **Record the real counts before the first edit.**

---

## Story Goal

The four emails already queue. This story makes their failure behaviour deliberate, and turns four stories' worth of separate promises into one enforced set of rules.

1. `config/notifications.php` gains a **`retry`** block — `tries`, `backoff`, `timeout` — completing the three-key shape Stories 46 and 47 left room for.
2. `App\Notifications\Concerns\HasRetryPolicy` — one trait giving all four notifications their `tries`, `backoff()`, `timeout` and a `failed()` that writes context to the log before the job lands in `failed_jobs`.
3. **`config/queue.php:44` stays `false`, and the decision is measured** — see below. AC4 is discharged by a test, not a flag.
4. `NotificationDispatchTest` — one table-driven suite over all four events asserting AC1, AC3, AC4 and AC5 together, so a fifth notification inherits the rules instead of re-arguing them.

**Not in scope.** **No new notification, recipient, subject, template, event or listener.** **No `retryUntil`** — a wall-clock deadline and a `tries` count are two policies for one question, and `tries` is what AC2 names. **No `ShouldBeEncrypted`** — nothing in the backlog asks, and the payload already lives in a database only the app can read. **No Horizon, no Redis, no separate queue name, no `queue:monitor` alerting** — the deployment runbook (**TM-63**) owns process supervision, and Story 43 already added its `## Queue worker` section. **No change to `retry_after`** (`config/queue.php:43`) — see the decision. **No dead-letter handling, no automatic `queue:retry`, no failure email** (an email about a failed email is a loop waiting to happen). **No change to `MailSafety`, the four listeners, the four events or any Blade view.**

---

## Decision — `after_commit` stays `false`, because turning it on would break the suite and buy nothing

`config/queue.php:44` is **not edited**. This is the single most load-bearing decision in the story, and it was traced through the framework rather than assumed.

- **AC4 is already true, by construction, in all four places.** Stories 44, 45, 46 and 47 each dispatch their event **after** `DB::transaction()` returns, each says so in a code comment, and each ships a test that a rolled-back action dispatches nothing. **No email can reference uncommitted data because no event exists until the commit has happened.**
- **Turning the flag on would defer every job into a callback the test suite never fires.** The chain: `Queue::enqueueUsing()` (`vendor/laravel/framework/src/Illuminate/Queue/Queue.php`) defers to `db.transactions->addCallback(…)` when `shouldDispatchAfterCommit($job)` **and** `db.transactions` is bound. `DatabaseTransactionsManager::addCallback()` (**213–220**) attaches the callback to `callbackApplicableTransactions()->last()`, which is simply `$this->pendingTransactions` (**240–243**) — **there is no test-connection exemption in this version**. `RefreshDatabase::beginDatabaseTransaction()` installs a fresh manager and then calls `$connection->beginTransaction()`, which registers the test's own transaction as pending. **So under `RefreshDatabase` the job would be attached to a transaction that is rolled back, and never pushed at all.**
- **The blast radius is precise and large.** Every `DB::table('jobs')->count()` assertion in Stories 44, 45, 46 and 47, Story 46's `available_at` delay assertions, and Story 47's three-jobs-per-admin count — **roughly a dozen tests would start asserting zero.** They would not error; they would quietly stop proving the thing they exist to prove.
- **What the flag would actually add is a safety net for a mistake nobody has made.** It protects a job dispatched *inside* a transaction. All four dispatches are outside one, and this story's test 15.3 is what keeps them there.
- **Recorded for whoever reaches for it later:** if a future story genuinely needs to dispatch inside a transaction, the right tool is `ShouldQueueAfterCommit` **on that one notification** — `SendQueuedNotifications::__construct()` honours it per-job — not a connection-wide flag that silently changes how the whole suite behaves.

## Decision — the policy is a trait with a property, a method and a hook, because the framework reads each differently

This is not a style choice. `SendQueuedNotifications` picks up each setting by a different mechanism, and getting one wrong fails silently.

| Setting | How the framework reads it | Therefore |
|---|---|---|
| `tries` | `SendQueuedNotifications::__construct()` → `getAttributeValue($notification, Tries::class, 'tries')` | **A declared property or a `#[Tries]` attribute. A `tries()` method is never called.** |
| `timeout` | same, with `Timeout::class` | same |
| `backoff` | `SendQueuedNotifications::backoff()` (**170–178**) reads the attribute, then **overrides it with `$this->notification->backoff()` if that method exists** | **A `backoff()` method or a property; the method wins.** |
| `failed($e)` | `SendQueuedNotifications::failed()` (**158–164**) calls `$this->notification->failed($e)` when the method exists | **A method, and the only hook AC2's "with context" can use.** |

- **`ReadsClassAttributes::getAttributeValue()`** returns the property when it is set and differs from the class default, then the attribute, then the property again. **A trait declaring `public ?int $tries = null` and assigning it in an initialiser therefore works** — the assigned value differs from the `null` default, so it is returned.
- **`tries` is read at queue time, in the job's constructor**, so reading `config()` from the notification's constructor is correct and the value is frozen into the serialised job. **A config change does not retroactively alter jobs already in `jobs`.** Say so in the config comment.
- **An array `backoff` is serialised to a comma string.** `Queue::getJobBackoff()` does `Collection::wrap($backoff)->implode(',')`, and `Worker::calculateBackoff()` (**793–803**) does `explode(',', $job->backoff())` and indexes it by `attempts() - 1`, **falling back to the last element**. So `[60, 300]` with `tries = 3` means: fail → wait 60s → fail → wait 300s → fail → `failed_jobs`. **Returning a string with commas works identically; returning an array is clearer.**
- **The trait cannot set the properties on its own.** PHP traits declare no constructor for the using class, and `Notification` is not `#[AllowDynamicProperties]`. **Each of the four constructors calls `$this->applyRetryPolicy();` as its last statement** — four one-line edits, and task 3 lists them.

## Decision — `tries = 3`, `backoff = [60, 300]`, `timeout = 30`, and `retry_after` stays 90

- **`timeout` must be shorter than `retry_after`, or a job runs twice.** `config/queue.php:43` sets `retry_after` to **90 seconds** — the window after which the database driver considers a reserved job abandoned and hands it to another worker. A job allowed to run for longer than that is picked up by a second worker while the first is still going. **30 < 90 with room to spare**, and this is exactly the number Story 43 recorded and left for this story.
- **`retry_after` is not changed**, because 90 seconds is already generous for an SMTP send and lowering it narrows the safety margin the timeout depends on.
- **`tries = 3` and `backoff = [60, 300]` target a transient outage, not a permanent one.** Six minutes of retrying absorbs a restarting mail relay; a misconfigured `MAIL_HOST` fails three times and lands in `failed_jobs` where a human can see it, which is better than retrying forever against a wrong address.
- **`timeout` needs `pcntl`.** Without the extension the worker cannot alarm out and the value is inert — the job runs until the SMTP client's own socket timeout. **Not an error, and not worth guarding**; the prerequisites say to record whether the extension is present.
- **All three are `env()`-backed** so an operator can widen them during an incident without a deploy.

## Decision — `failed()` logs context; it does not send, retry or alert

`failed_jobs` already stores the uuid, connection, queue, the full serialised payload and the complete exception (Story 43 measured a real row). AC2's *"with context"* is the part that row cannot give you: **which ticket, which reference, which recipient**, in a log line you can grep without unserialising a payload.

- **`Log::error`, once, with scalar context.** Ticket id, reference, notification class, and a recipient descriptor — **never the notifiable model and never a full stack trace**, which the `failed_jobs` row already holds.
- **No email is sent about a failed email.** An alert that travels by the channel that just failed is not an alert.
- **No automatic re-queue.** Story 43 documented `queue:failed`, `queue:retry <uuid>` and `queue:retry all` in the README for exactly this moment.
- **The recipient descriptor is deliberately not an address.** TM-56's fifth criterion keeps staff addresses out of emails; putting them in a log file is the same leak with a longer half-life. `failureContext()` returns an id and a class, not `$notifiable->email`.

---

## Context — Read These Files First

1. `backend/config/queue.php` — **line 43** (`retry_after`, 90) and **line 44** (`after_commit`, `false`). **Read both, change neither.** Lines **123–127** are the `failed` driver, `database-uuids`, which Story 43 verified end to end.
2. `backend/config/notifications.php` — Story 46's `requester` block and Story 47's `admin` block. Task 1 adds `retry` as the third top-level key. **Both earlier stories asked for the shape to be kept open for exactly this.**
3. `vendor/laravel/framework/src/Illuminate/Notifications/SendQueuedNotifications.php` — **the constructor at 92–113** (what is forwarded from the notification), **`failed()` at 158–164**, **`backoff()` at 170–178**, **`retryUntil()` at 186–193**. **Read all four before writing the trait**; each setting travels by a different route and three of them fail silently if you guess.
4. `vendor/laravel/framework/src/Illuminate/Queue/Queue.php` — **`createObjectPayload()` at 173–192** for the payload keys the tests assert on (`maxTries`, `backoff`, `timeout`), **`getJobBackoff()` at 250–266** for the array-to-comma-string conversion, and **`enqueueUsing()`** for the after-commit deferral the decision above turns on.
5. `vendor/laravel/framework/src/Illuminate/Queue/Worker.php:793–803` — `calculateBackoff()`, which indexes the comma list by attempt and repeats the last entry.
6. [`43-story-queue-and-mail-infrastructure-TM-51.md`](43-story-queue-and-mail-infrastructure-TM-51.md) — its **failed-job section of the README** (`queue:failed`, `queue:retry`, `queue:forget`, `queue:flush`, `queue:prune-failed`) and its measured failed-job row. **This story adds no new command and no new documentation of them.**
7. [`48-story-shared-responsive-mail-layout-TM-56.md`](48-story-shared-responsive-mail-layout-TM-56.md) — its `MailLayoutTest` and its notification table. **Task 6's suite copies that shape**, and both files carry the same warning: a notification absent from the array is a notification nobody is checking.
8. The four notification classes and their constructors — `TicketAssignedNotification`, `TicketCreatedNotification`, `TicketStatusChangedNotification`, `TicketEscalatedNotification`. Task 3 adds one line to each and one small method to each.
9. `backend/app/Services/MailSafety.php` (Story 43) — **its `safe_smtp_hosts` list includes `127.0.0.1`.** Test 15.5 uses that fact: SMTP on `127.0.0.1:1` is a **permitted** transport pointing at a **dead** port, which is how a mail outage is simulated without touching the guard.

---

## Product rules (from story)

| Situation | Before | After |
|---|---|---|
| Any of the four events fires | Notification queued, no policy | Queued with `tries`, `backoff` and `timeout` in the payload |
| A send fails once | Job retried with the queue's default backoff (`0`) | Retried after **60s** |
| A send fails twice | — | Retried after a further **300s** |
| A send fails three times | Lands in `failed_jobs` | Lands in `failed_jobs` **and** one `Log::error` names the ticket, reference and notification |
| The mail transport is down entirely | The ticket action already succeeded | **Unchanged, and now asserted** for all four actions |
| A ticket action rolls back | No event dispatched | **Unchanged, and now asserted** for all four |
| A job hangs | Runs until the SMTP socket gives up | Killed at **30s** where `pcntl` is available |
| `config('queue.connections.database.after_commit')` | `false` | **`false`** — deliberately |
| A job already in `jobs` when config changes | — | Keeps the policy it was queued with |
| A fifth notification added later | — | Inherits nothing unless it uses the trait **and** joins test 15's table |

---

## Backend Tasks

### 1 — The retry configuration

**File: `backend/config/notifications.php`** *(Story 46 creates it; Story 47 adds `admin`)*

Add as the third top-level key:

```php
    'retry' => [

        // Attempts per notification, not retries after the first: tries = 3
        // means one send and two retries. Read from config when the job is
        // QUEUED, so changing this does not alter jobs already in `jobs`.
        'tries' => (int) env('NOTIFY_TRIES', 3),

        // Seconds to wait before each retry, indexed by attempt. Laravel joins
        // this into a comma string in the payload and repeats the last entry if
        // there are more attempts than entries. Six minutes of total patience
        // absorbs a restarting relay; a wrong MAIL_HOST fails and is visible.
        'backoff' => [
            (int) env('NOTIFY_BACKOFF_FIRST', 60),
            (int) env('NOTIFY_BACKOFF_SECOND', 300),
        ],

        // Seconds a single send may run. MUST stay below the database queue's
        // retry_after (config/queue.php:43, currently 90) or a slow job is
        // handed to a second worker while the first is still sending it.
        // Requires ext-pcntl; without it the worker cannot enforce this.
        'timeout' => (int) env('NOTIFY_TIMEOUT', 30),

    ],
```

### 2 — Document the variables

**File: `backend/.env.example`**

Append after Story 47's `NOTIFY_ESCALATION_TOKEN`, still **before** the `TICKETS_STALE_AFTER_HOURS` lines:

```
# Notification retry policy. tries=3 is one send and two retries, waiting 60s
# then 300s. NOTIFY_TIMEOUT must stay below DB_QUEUE_RETRY_AFTER (90) or a slow
# job can be picked up twice. See config/notifications.php.
NOTIFY_TRIES=3
NOTIFY_BACKOFF_FIRST=60
NOTIFY_BACKOFF_SECOND=300
NOTIFY_TIMEOUT=30
```

### 3 — The retry-policy trait

**Create file: `backend/app/Notifications/Concerns/HasRetryPolicy.php`**

```php
<?php

namespace App\Notifications\Concerns;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The queue policy every notification in this application shares.
 *
 * Three settings, three different mechanisms, because that is how the framework
 * reads them -- get one wrong and it fails silently:
 *
 *   tries   -- a PROPERTY, read by SendQueuedNotifications::__construct() via
 *              getAttributeValue(). A tries() method is never called.
 *   timeout -- the same.
 *   backoff -- a METHOD. SendQueuedNotifications::backoff() overrides the
 *              attribute with $notification->backoff() when it exists, and
 *              Queue::getJobBackoff() joins the array into a comma string.
 *   failed  -- a METHOD, called by SendQueuedNotifications::failed(). It is the
 *              only hook AC2's "with context" can use.
 *
 * tries and timeout are read from config when the job is QUEUED and frozen into
 * the payload, so a config change does not alter jobs already waiting.
 */
trait HasRetryPolicy
{
    public ?int $tries = null;

    public ?int $timeout = null;

    /**
     * Call as the LAST statement of the using class's constructor. A trait
     * cannot supply a constructor, and Notification is not
     * #[AllowDynamicProperties], so the properties are declared above and
     * assigned here.
     */
    protected function applyRetryPolicy(): void
    {
        $this->tries = max(1, (int) config('notifications.retry.tries', 3));
        $this->timeout = max(1, (int) config('notifications.retry.timeout', 30));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        $backoff = array_values(array_map(
            fn ($seconds): int => max(0, (int) $seconds),
            (array) config('notifications.retry.backoff', [60, 300]),
        ));

        return $backoff === [] ? [60] : $backoff;
    }

    /**
     * Called once, after the last attempt, immediately before the job is
     * written to failed_jobs. That row already holds the payload and the full
     * exception; this line holds what the row cannot give you without
     * unserialising it -- which ticket, and which notification.
     *
     * No email is sent from here: an alert that travels by the channel that
     * just failed is not an alert.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Notification permanently failed.', [
            'notification' => static::class,
            'tries' => $this->tries,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            ...$this->failureContext(),
        ]);
    }

    /**
     * Scalar identifiers only. Never a model, never an email address -- TM-56's
     * fifth criterion keeps staff addresses out of emails, and a log file is
     * the same leak with a longer half-life.
     *
     * @return array<string, scalar|null>
     */
    abstract protected function failureContext(): array;
}
```

**File: each of the four notifications** — add `use App\Notifications\Concerns\HasRetryPolicy;`, add the trait to the `use` list beside `Queueable, SerializesModels`, add `$this->applyRetryPolicy();` as the **last statement of the constructor**, and implement `failureContext()`:

| Notification | `failureContext()` returns |
|---|---|
| `TicketAssignedNotification` | `ticket_id`, `reference`, `recipient` => `'assignee'` |
| `TicketCreatedNotification` | `ticket_id`, `reference`, `recipient` => `'requester'` |
| `TicketStatusChangedNotification` | `ticket_id`, `reference`, `to_status` => `$this->to->slug`, `recipient` => `'requester'` |
| `TicketEscalatedNotification` | `ticket_id`, `reference`, `level`, `recipient` => `'admins'` |

**The constructors are promoted-property constructors with empty bodies today.** Adding a body is the change; **the promoted properties, their `readonly` markers and their order must not move** — `SerializesModels` restores them by reflection and Story 44 measured that path.

**Do not add `retryUntil`, `ShouldQueueAfterCommit`, `ShouldBeEncrypted`, `maxExceptions` or a `#[Tries]` attribute.** One policy, one place.

### 4 — Confirm, and do not change, the queue configuration

**File: `backend/config/queue.php` — read only.**

Add nothing. **Line 44 stays `'after_commit' => false`** and **line 43 stays `retry_after` 90.** Task 6's tests 15.3 and 15.9 are what hold AC4 and the timeout relationship in place instead.

**If a reviewer asks why the flag is off, the answer is in this plan's first decision** — quote the `DatabaseTransactionsManager` line numbers, not an opinion.

### 5 — Document the policy

**File: `docs/api-contract.md`**

Append one paragraph to the `Notifications` section:

```markdown
Every notification is queued with the policy in `config/notifications.php`:
`tries` attempts (default 3) with a `backoff` of 60 then 300 seconds and a
30-second `timeout`, which must stay below the database queue's `retry_after`
of 90. A notification that exhausts its attempts lands in `failed_jobs` with its
payload and exception, and writes one `Log::error` naming the ticket, its
reference and the notification class — no address, and no email about the
failure. Every event dispatches after its transaction commits, so a rolled-back
action queues nothing; `queue.connections.database.after_commit` is
deliberately `false`, because the dispatch sites are already outside their
transactions and enabling it would defer jobs into a callback that
`RefreshDatabase` never fires.
```

**File: `docs/deployment-runbook.md`** — in the `## Queue worker` section Story 43 added, append:

```markdown
Workers should run with no `--tries` flag so each job's own policy applies; a
`--tries` on the command line overrides what the notification declared. Check
`php artisan queue:failed` as part of post-deploy checks — a permanently failed
notification also writes a `Log::error` with the ticket reference.
```

**No frontend changes required.** **No README change** — Story 43 already documented every failed-job command.

---

## Edge Cases & Failure Modes

- **`tries` set as a method instead of a property does nothing at all.** `SendQueuedNotifications::__construct()` reads it through `getAttributeValue(…, 'tries')`, which consults the property and the attribute and never a method. Test 15.6 asserts the value reaches `jobs.payload.maxTries`, which is the only proof that matters.
- **`backoff` set as a property still works, but the method wins.** Both `Queue::getJobBackoff()` and `SendQueuedNotifications::backoff()` prefer `$notification->backoff()` when it exists. The trait defines the method; **do not also define a `$backoff` property**, or two sources disagree and only one is read.
- **An array `backoff` is stored as `"60,300"`.** Test 15.7 asserts on that exact string in `jobs.payload`, so a change to the wrapping is caught rather than discovered during an outage.
- **More attempts than backoff entries repeats the last one.** `Worker::calculateBackoff()` does `$backoff[$job->attempts() - 1] ?? last($backoff)`. Raising `NOTIFY_TRIES` to 5 without adding entries gives 60, 300, 300, 300 — **intended, and worth knowing before someone adds four more entries to "fix" it.**
- **`timeout` above `retry_after` double-sends.** 30 versus 90 today. **Anyone raising `NOTIFY_TIMEOUT` must raise `DB_QUEUE_RETRY_AFTER` first.** Test 15.9 asserts the inequality holds in configuration, so the mistake fails the suite rather than production.
- **Without `pcntl` the timeout is inert.** No error, no warning; the job simply runs to the SMTP client's own limit. Recorded, not guarded.
- **Config is frozen into the payload at queue time.** Lowering `NOTIFY_TRIES` during an incident does not shorten jobs already queued. `php artisan queue:flush` and a re-trigger is the only way to change those. **Say this in the incident notes, not in code.**
- **`failed()` runs in the worker, once, after the last attempt** — not once per attempt. A job released for retry does not log.
- **`failed()` itself throwing would lose the log line and the `failed_jobs` row is written anyway.** `failureContext()` therefore reads only scalars already on the notification's own properties; **it must not query the database**, where a connection failure would turn a mail failure into a second exception.
- **A mail outage cannot fail the request, and this is structural rather than defensive.** Nothing in a request touches the transport: the listener calls `notify()`, which writes a row to `jobs`. **The only way a request could fail is a database failure, which would have failed the ticket action anyway.**
- **A rolled-back action queues nothing.** Each of Stories 44–47 already tests this for its own event; test 15.3 asserts it for all four in one place so a fifth event has somewhere to be added.
- **`after_commit` left `false` means a job dispatched *inside* a transaction would be pushed immediately.** No dispatch site does this today, and test 15.4 is what detects one appearing — it asserts the dispatch is observed at the test's own transaction level, i.e. after the controller's transaction has closed.
- **`RefreshDatabase` makes `DB::transactionLevel()` 1, not 0, inside a test.** Test 15.4 therefore compares against a baseline captured in the test body rather than asserting `=== 0`. **Asserting zero would fail for the wrong reason and teach the next reader something untrue.**
- **A fifth notification inherits none of this automatically.** It must `use HasRetryPolicy`, call `applyRetryPolicy()` and appear in test 15's table. **Both the trait's docblock and the test's say so.**

---

## Test Plan

**15. `backend/tests/Feature/Notifications/NotificationDispatchTest.php`** (new, `RefreshDatabase`) — the cross-cutting suite. It drives **all four real endpoints** and asserts the four rules across every one, in the shape Story 48's `MailLayoutTest` established.

```php
/**
 * One row per event. Add every new notification here: a row missing from this
 * array is a notification with no queueing, recipient or after-commit guarantee.
 *
 * @return array<string, array{action: callable, notification: class-string, recipients: callable, jobs: int}>
 */
private function events(): array
// 'assigned'  => POST /tickets/{t}/assign      -> TicketAssignedNotification,        1 job
// 'created'   => POST /tickets                 -> TicketCreatedNotification,         1 job
// 'status'    => POST /tickets/{t}/status      -> TicketStatusChangedNotification,   1 job
// 'escalated' => POST /tickets/{t}/escalate    -> TicketEscalatedNotification,   3 jobs (three admins)
```

1. `test_every_event_queues_and_sends_nothing_during_the_request` — **AC1.** For each row: perform the action, assert the response is 2xx, assert `DB::table('jobs')->count()` equals the row's expected count, and assert `app('mailer')->getSymfonyTransport()->messages()` is **empty**. **No fakes** — `Notification::fake()` cannot tell queued from inline.
2. `test_every_event_reaches_exactly_its_intended_recipients` — **AC5.** `Notification::fake()`; per row, `assertSentTo` the expected notifiables, `assertSentTimes` the expected count, and `assertNotSentTo` a control user who should receive nothing.
3. `test_a_rolled_back_action_queues_nothing` — **AC4.** Per row, force the failure inside the controller's transaction (a throwing `ActivityRecorder` binding, the mechanism Story 18's atomicity test uses), then assert the exception surfaces, the write did not land, and `jobs` is empty.
4. `test_every_event_dispatches_after_its_transaction_closes` — **AC4, the architectural one.** Capture `$baseline = DB::transactionLevel()` in the test body, register a temporary listener per event that records `DB::transactionLevel()` at dispatch time, perform the action, and assert the recorded level **equals `$baseline`** — proving the controller's own transaction had already closed. **Docblock why it is not `=== 0`:** `RefreshDatabase` holds a transaction open for the whole test.
5. `test_a_dead_mail_transport_does_not_fail_the_action` — **AC3.** `config()->set('mail.default', 'smtp')` and the smtp host to **`127.0.0.1` port `1`** — a host `MailSafety` permits, on a port nothing listens to. Per row: the action still returns 2xx and the row is committed. Then run the worker and assert the job was released or failed, **and the ticket state is unchanged either way.**
6. `test_the_configured_tries_reaches_the_payload` — **AC2.** `config()->set('notifications.retry.tries', 5)`; trigger one event; decode `jobs.payload` and assert `maxTries` is `5`. **The only proof that `tries` was declared in a way the framework reads.**
7. `test_the_configured_backoff_reaches_the_payload_as_a_comma_string` — **AC2.** `config()->set('notifications.retry.backoff', [30, 90, 180])`; assert the payload's `backoff` is exactly `"30,90,180"`.
8. `test_the_configured_timeout_reaches_the_payload` — **AC2.** Assert `payload.timeout` matches config.
9. `test_the_timeout_stays_below_the_queue_retry_after` — **AC2, the configuration invariant.** Assert `config('notifications.retry.timeout') < config('queue.connections.database.retry_after')`. **A one-line test that turns a footgun into a red suite.**
10. `test_an_exhausted_job_lands_in_failed_jobs_with_a_logged_context` — **AC2.** `config()->set('notifications.retry.tries', 1)` and the dead transport from 15.5; trigger one event, run the worker once, then assert `failed_jobs` has one row whose `exception` is non-empty, **and** that `Log::error` was called with `notification`, `ticket_id` and `reference` in its context. Use `Log::spy()`.
11. `test_the_failure_log_carries_no_email_address` — assert the logged context contains no `@`. TM-56's fifth criterion, applied to the log.
12. `test_after_commit_is_disabled_on_the_database_connection` — assert `config('queue.connections.database.after_commit')` is **`false`**, with a docblock quoting this plan's first decision. **A guard against a well-meaning future edit that would silently zero out a dozen assertions elsewhere.**
13. `test_every_notification_uses_the_retry_policy_trait` — reflect over every class in `backend/app/Notifications/` (excluding `Concerns/`) and assert each uses `HasRetryPolicy` and declares `failureContext()`. **The trait is only a guarantee if nothing opts out.**

**Untouched:** every existing test in Stories 44–48. **If any of them changes, this story has changed behaviour and has gone wrong** — the only exception is a test that hardcodes a backoff or tries value it did not set, and there is none.

---

## Verification Steps

1. **Record the baseline first.** From `backend/`, `composer test`, and **write down the pass/fail counts**. This story must not move them.
2. **Record the environment:** `php -m | grep -c pcntl` — note the result in the PR, because it determines whether `timeout` does anything on this machine.
3. **Services up:** from the repo root, `docker compose up -d --wait`.
4. **Backend tests:** `composer test`. Same counts as step 1 plus this story's own; the three long-standing failures and nothing else.
5. **This story alone:** `php artisan test --filter=NotificationDispatchTest`.
6. **Formatting:** `./vendor/bin/pint --test` exits `0`.
7. **The policy is really in the payload.** Trigger any notification, then:
   ```bash
   cd backend
   php artisan tinker --execute="\$p = json_decode(DB::table('jobs')->first()->payload); echo \$p->maxTries.' | '.\$p->backoff.' | '.\$p->timeout;"
   ```
   Expect `3 | 60,300 | 30`.
8. **Retry and failure, by hand.** Set `MAIL_HOST=127.0.0.1` and `MAIL_PORT=1` in `backend/.env`, `php artisan config:clear`, trigger a notification, then `php artisan queue:work --once`. **The job is released, not failed** — `DB::table('jobs')->first()->attempts` is `1` and `available_at` is ~60s ahead. Repeat with time travel or wait; after the third attempt `php artisan queue:failed` lists it and `storage/logs/laravel.log` contains one `Notification permanently failed.` line carrying the reference. Restore `MAIL_PORT=1025`.
9. **AC3 by hand.** With the dead port still configured, create a ticket and escalate one over HTTP. **Both requests must return normally and both rows must be in the database** — the failure is entirely in the worker.
10. **The `after_commit` guard guards.** Set `config/queue.php:44` to `true` and run `composer test`. **Expect a large number of failures across Stories 44–47**, which is the decision's evidence rather than a surprise. Revert, and confirm the suite returns to step 4's counts.
11. **The trait guard guards.** Remove `use HasRetryPolicy;` from one notification and run `php artisan test --filter=test_every_notification_uses_the_retry_policy_trait`. **It must fail and name the class.** Restore.
12. **Regression:** `git diff --stat` touches only `backend/config/notifications.php`, `backend/.env.example`, `backend/app/Notifications/Concerns/HasRetryPolicy.php`, the four notification classes (**one trait import, one trait use, one constructor line and one method each**), `docs/api-contract.md`, `docs/deployment-runbook.md` and the new test file. **No migration, no route, no controller, no event, no listener, no Blade view, no `config/queue.php`, no `frontend/` file.**

---

## Done Criteria

- [ ] All four notifications are queued and **nothing reaches the mail transport during any request**, asserted without fakes across all four endpoints. *(AC1)*
- [ ] Every notification declares `tries`, `backoff` and `timeout` through `HasRetryPolicy`, and tests prove each value reaches `jobs.payload` as `maxTries`, `"60,300"` and `timeout` — the only proof the framework actually read them. *(AC2)*
- [ ] `timeout` is asserted to be **below** the database queue's `retry_after`, so raising one without the other fails the suite. *(AC2)*
- [ ] An exhausted job lands in `failed_jobs` **and** writes one `Log::error` naming the notification, ticket id and reference — with **no email address** in the context, and no email sent about the failure. *(AC2)*
- [ ] A dead mail transport leaves every one of the four ticket actions returning normally with its row committed. *(AC3)*
- [ ] Every event is proven to dispatch **after** its controller's transaction has closed, by comparing the dispatch-time transaction level against the test's own baseline; a rolled-back action queues nothing. *(AC4)*
- [ ] `config/queue.php`'s `after_commit` is **still `false`**, with a test pinning it and this plan's traced reason recorded — enabling it would defer jobs into a callback `RefreshDatabase` never fires and silently zero a dozen assertions. *(AC4)*
- [ ] One table-driven suite asserts the queued notification **and its recipients** for all four events, and a reflection test proves no notification opted out of the trait. *(AC5)*
- [ ] No new notification, recipient, subject, template, event, listener, migration, route or frontend file; no `retryUntil`, `ShouldQueueAfterCommit`, `ShouldBeEncrypted` or change to `retry_after`.
- [ ] Test counts match the baseline recorded in verification step 1, plus this story's own; `./vendor/bin/pint --test` exits `0`.
- [ ] **E8 is complete.** All seven stories (TM-51 … TM-57) are implemented, and `docs/api-contract.md`'s `Notifications` section describes the whole system.
