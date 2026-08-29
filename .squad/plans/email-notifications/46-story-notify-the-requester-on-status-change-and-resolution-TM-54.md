# Story 46 — Notify the requester on status change and resolution (Story: TM-54)

## Prerequisites

- **Story 43 (TM-51) — hard gate.** [`43-story-queue-and-mail-infrastructure-TM-51.md`](43-story-queue-and-mail-infrastructure-TM-51.md). AC5's whole mechanism is a **delayed** queued notification; under `phpunit.xml:45`'s current `QUEUE_CONNECTION=sync` there is no delay, no `available_at`, and nothing to supersede. Tests 11.4 and 11.5 are written directly against Story 43's flip to `database`.
- **Story 45 (TM-53) — hard gate for one line.** It adds `Notifiable` to `backend/app/Models/Requester.php` (its task 1). **`Requester` has no `Notifiable` today.** If Story 45 has not landed when you start, add the trait verbatim from its task 1 and record it in the PR so Story 45 does not add a second. Story 45 also establishes the **view-pair rule** this story repeats without re-deriving — see the decision.
- **Stories 32, 33 and 34 (TM-38, TM-39, TM-40) — hard gates, and none of them exists on disk.** Verified while planning: `TicketController` has **no `changeStatus()`** (only `show()` **23–28** and `store()` **30–50**); `app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php`, `app/Services/TicketWorkflow.php` and `app/Services/TicketTimestamps.php` do **not** exist; `TicketActivityEvent` has only `Created` and `CategoryChanged` (**7–8**) — no `StatusChanged`, no `Reopened`; `Status` has **no `SLUG_*` constants**. This story adds **one line** to a method those three stories build between them. **Gate: `php artisan test --filter='TicketStatusTest|TicketResolutionTest|TicketReopenTest'` green before you start.**
- **No E6 story dispatches anything, and that is stated rather than assumed.** Story 32's plan closes its scope list with *"**No notification** — E8 owns every line of mail"*, Story 34's with *"no reopen notification (E8)"*. **There is no `TicketStatusChanged` event anywhere in the backlog**, so unlike Story 44 — which inherited `TicketAssigned` from Story 29 — **this story owns both the event and the dispatch point.**
- **Story 44 (TM-52) is not a dependency** and shares no file with this story except `docs/api-contract.md` and `app/Events/`. Ship them in any order.
- **No new composer or npm dependency, no migration, no route, no policy change, no frontend file.** The five seeded statuses this story keys on already exist (`StatusSeeder.php:12–18`: `new`, `open`, `in-progress`, `pending`, `resolved`, `closed`, `reopened`), and `statuses.slug` is `unique` (`2026_08_26_073219_create_statuses_table.php:18`).
- **Baseline, 2026-08-27:** `composer test` → **101 tests, 98 passing, 3 failing** (`PasswordThrottleTest`, `RouteAuthorizationTest`, `TicketReferenceTest` — all pre-existing); `./vendor/bin/pint --test` exits `0`.

---

## Story Goal

A requester hears about the moves that matter to them, once, with the resolution note when there is one.

1. `config/notifications.php` — **the project's second bespoke config file**, holding the requester-visible status slugs and the suppression window. AC3 is this file existing and the call site reading it.
2. `App\Events\TicketStatusChanged` — dispatched once from `changeStatus()`, **after** the transaction commits.
3. `App\Listeners\SendTicketStatusChangedNotification` — auto-discovered, synchronous, and the one place AC4's internal-only rule is enforced.
4. `App\Notifications\TicketStatusChangedNotification implements ShouldQueue` — **delayed** by the configured window and **superseded at send time** when the ticket has moved on again. That pair is the whole of AC5.
5. Two Blade views naming the old status, the new status and, on a resolution, the note.

**Not in scope, and each belongs to a named story.** **No `tries`, `backoff`, `timeout` or `retryUntil`** — TM-57 (E8-S7), as Stories 43, 44 and 45 all recorded. **No shared mail layout** — TM-56 extracts one from the views this story, TM-53 and TM-55 create. **No escalation email** — TM-55. **No agent-facing status email** — nothing in the backlog asks for one; the agent who made the change does not need telling, and the assignee learns from the app. **No deep link, no requester portal** — Story 45's decision holds unchanged: a requester has no login. **No reopen *reason* in the email** — see the decision. **No digest, no daily summary, no per-requester preference table, no unsubscribe link.** **No change to `changeStatus()`'s transaction, `TicketWorkflow`, `TicketTimestamps`, `status_transitions`, `ChangeTicketStatusRequest`, `TicketResource`, `routes/api.php` or `TicketPolicy`** — Stories 31–34 own every one of those lines and this story adds exactly one dispatch after the commit.

---

## Decision — AC3 is a config file keyed on slug, and it is a new file

**Create `backend/config/notifications.php`.** Not a `notify_requester` column on `statuses`, not a constant in the listener, not a match arm in the controller.

- **AC3 names the shape:** *"Which statuses are requester-visible is configuration driven, not hardcoded at the call site."* A `match ($to->slug)` in `changeStatus()` is exactly the call-site hardcoding it forbids.
- **A column was rejected for the reason Story 33 already gave.** The E6 overview records: *"Three slug constants, and no `requires_note` column on `statuses` … **Which moves are legal stays data; what three statuses mean is code.**"* Whether a requester should hear about a status is a product judgement, not per-tenant data, and a column would need a migration, a seeder change and an admin screen nobody asked for.
- **A new file rather than a key on an existing one.** `config/seeding.php` is the precedent for a bespoke file; **TM-55 needs an admin-recipient rule and TM-57 needs the retry policy**, and both belong beside this. Story 43 put `mail.safety` inside `config/mail.php` because it is genuinely about mail transport; this is about *which notifications fire*, which is not.
- **Keyed on `slug`, never on `id`.** Ids differ between the development and test databases; `StatusSeeder` guarantees the seven slugs and `statuses.slug` is `unique`.

## Decision — five statuses are visible, and `new` and `open` are deliberately not

Default `NOTIFY_REQUESTER_STATUSES=in-progress,pending,resolved,closed,reopened`.

| Slug | Visible | Why |
|---|---|---|
| `new` | **No** | TM-53's creation confirmation already told them, seconds earlier. A second email saying "your ticket is New" is the burst AC5 exists to prevent. |
| `open` | **No** | `new → open` is a triage step with no requester-facing meaning. Including it would email nearly every ticket immediately after its confirmation. |
| `in-progress` | Yes | "Someone is working on it" is the single most chase-preventing fact in the system, and chasing is what the story exists to stop. |
| `pending` | Yes | **Pending usually means we are waiting on them.** Suppressing this one would be the worst omission on the list. |
| `resolved` | Yes | AC2's email. |
| `closed` | Yes | The end of the thread; a requester who disagrees needs to know to reply. |
| `reopened` | Yes | Story 34 left reopen notification to E8 by name. A ticket coming back to life is a fact the requester should have. |

**The list is a default, not a law** — it is one env variable, and changing it needs no code. **Do not put this table in the config file's comments**; put the slugs there and the reasoning here.

## Decision — AC5 is a delay plus supersede-at-send, not a cooldown lock

The notification declares `withDelay()` returning the configured window, and `shouldSend()` returns `false` when the ticket's current `status_id` no longer matches the one the notification was queued for.

An agent moving a ticket `open → in-progress → pending → resolved` in thirty seconds queues three jobs, all delayed. When the window elapses the first two find the ticket already `resolved`, decline, and **one email goes out describing the final move.**

- **A cooldown lock drops the wrong email.** `Cache::add("notify:{$ticketId}", …, 300)` is atomic and simple, and it would suppress the **later** change — telling the requester "in progress" and never "resolved". The most important email in this story is the one a naive throttle eats.
- **Laravel 13's notification deduplicator does nothing here — measured.** `NotificationSender::queueNotification()` reads `deduplicationId`/`withDeduplicators` (**264–268**) and passes it to `->withDeduplicator(…)` (**289**), but the only consumer in the framework is `Illuminate/Queue/SqsQueue.php:581`. **On the `database` driver it is inert.** Do not reach for it; the same goes for `messageGroup`, which is SQS FIFO ordering.
- **`ShouldBeUnique` is not available on a notification.** `Illuminate\Notifications\SendQueuedNotifications` (**22**) implements `ShouldQueue` and nothing else — no `ShouldBeUnique`, no `uniqueId()`. Getting job-level uniqueness would mean wrapping the notification in a bespoke job, which buys the wrong semantics anyway.
- **`shouldSend()` runs in the worker, after the delay — verified.** `SendQueuedNotifications::handle()` (**137–140**) calls `$manager->sendNow(…)`, which reaches `NotificationSender::shouldSendNotification()` (**199–203**), which calls `shouldSend($notifiable, $channel)` when the method exists. The check therefore sees the ticket's state at *delivery* time, which is the only time it is worth checking.
- **`withDelay()` is the hook, not a `$delay` property.** `NotificationSender::queueNotification()` (**254–256**) prefers `withDelay($notifiable, $channel)` over the `Delay` attribute or property, and a method can read `config()` at queue time — a property would freeze the window at construction.
- **The cost, stated plainly: every status email is delayed by the window.** The default is **300 seconds**. That is long enough to absorb an agent working a ticket in one sitting and short enough that a resolution email is not stale. **Setting `NOTIFY_REQUESTER_DELAY_SECONDS=0` disables both the delay and, in practice, the supersession** — the plan keeps that as a supported configuration and test 11.6 pins its behaviour.
- **Supersession is keyed on `status_id`, not on a timestamp or a counter.** "Near-identical" in AC5 means "about a status the ticket has already left". A ticket moved `in-progress → pending → in-progress` inside the window sends **one** email for the final `in-progress` and drops the middle one, which is exactly right.

## Decision — the event carries the resolution and never the reopen reason

`TicketStatusChanged(int $ticketId, int $fromStatusId, int $toStatusId, ?string $resolution)`.

- **Scalar ids, no models, no marker interfaces** — the rule Story 29 set for `app/Events/` and Stories 44 and 45 followed.
- **`resolution` rides on the event** so the email can quote it without re-reading `ticket_activities` — the same argument Story 29 made for `reason` on `TicketAssigned`, and it keeps this story from depending on Story 33's `meta.resolution` query shape.
- **The reopen `reason` does not ride on it.** Story 34 stores `meta.reason` on the reopen row, and it is a note staff write to each other about why a ticket is coming back. AC2 asks for the **resolution** note and nothing else; TM-56's fifth criterion forbids leaking agent-facing text. **A reopen email says the ticket is open again and does not say why.**
- **Status *names* are not carried.** The listener resolves both ids against `statuses`, seven rows behind a primary-key lookup. Carrying names would freeze a label that an admin could rename between dispatch and delivery — and with a five-minute delay that window is real.

## Decision — a Blade view pair, and Story 45's escaping rule applies unchanged

`toMail()` returns a `MailMessage` with `->view(['mail.tickets.status-changed', 'mail.tickets.status-changed-text'], [...])`.

- **The resolution note is free text a human wrote**, exactly like TM-53's description. Story 45 measured what `MailMessage->line()` does to such text: **newlines collapse into one paragraph**, `[text](url)` becomes a **live link**, and markdown is consumed. A resolution note is where an agent pastes steps and commands; it needs the same treatment.
- **`{{ }}` in the HTML view, `{!! !!}` in the text view** — Story 45's measured rule, verbatim. Blade's `{{ }}` HTML-escapes in plain-text templates too, emitting `&lt;b&gt;` where the entity is wrong output; the HTML part must stay escaped because that is what turns `<script>` into `&lt;script&gt;`.
- **Do not extract a shared layout.** This is the second of the three view pairs TM-56 unifies. **Creating the third is what makes that story possible.**

---

## Context — Read These Files First

1. [`32-story-change-a-tickets-status-TM-38.md`](../status-workflow-escalation/32-story-change-a-tickets-status-TM-38.md) — **`changeStatus()` at its task 2**. Read the `DB::transaction` closure end to end: the `lockForUpdate()` + `refresh()->load('status')` + `$from = $ticket->status` block, `assertCanTransition()` **inside** the transaction, and the `return TicketResource::make(...)` tail. **Task 4 adds one line between the closing `});` and that `return`, and touches nothing inside the closure.**
2. [`33-story-resolving-requires-a-resolution-note-TM-39.md`](../status-workflow-escalation/33-story-resolving-requires-a-resolution-note-TM-39.md) — **its task 4**, which adds `$timestamps->apply(...)` and `'resolution' => $request->validated('resolution')` into that same closure's `meta`, and **its `Status::SLUG_NEW` / `SLUG_RESOLVED` / `SLUG_CLOSED` constants**. `$request->validated('resolution')` is the exact expression task 4 reuses for the dispatch.
3. [`34-story-reopen-a-closed-ticket-TM-40.md`](../status-workflow-escalation/34-story-reopen-a-closed-ticket-TM-40.md) — **its task 3**, which makes the event `Reopened` for a reopen while keeping `field = 'status_id'`, and adds `Status::SLUG_REOPENED`. **Read its rule: query status history by `field`, never by event.** This story keys on the target slug, not on the activity event, for the same reason.
4. `backend/database/seeders/StatusSeeder.php:12–18` — the seven slugs, their `is_terminal` flags and their `sort_order`. Task 1's default list is drawn from here; **check it before writing the config**, in case a story appended an eighth.
5. `backend/app/Models/Status.php` — **11 lines of body**: `#[Fillable]`, `casts()` with `bucket`/`is_terminal`, `scopeOrdered()`. **No `SLUG_*` constants yet** — Story 33 adds them. **This story adds none.**
6. [`45-story-confirm-ticket-creation-to-the-requester-TM-53.md`](45-story-confirm-ticket-creation-to-the-requester-TM-53.md) — its **task 1** (`Notifiable` on `Requester`), its **decision on view pairs** with the measured markdown evidence, and its **`{{ }}` / `{!! !!}` rule**. Tasks 5 and 6 here follow all three without re-deriving them.
7. [`44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md`](44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md) — its **task 3**, for the listener shape and the verified evidence that `app/Listeners` is auto-discovered (`Application.php:248–252`, `EventServiceProvider.php:41`, **166–171**).
8. `backend/config/seeding.php` — **9 lines, the precedent for a bespoke config file.** A flat `return [...]` of `env()` calls. Task 1 matches its density, not Laravel's boxed-comment style.
9. `backend/.env.example` — **lines 46–49** (the queue block Story 43 rewrites) and **line 63** (`FRONTEND_URL`). Task 2 appends after the `ADMIN_*` block and **before** the `TICKETS_STALE_AFTER_HOURS` lines at **72–74**, which are TM-43's and stay last.
10. `backend/app/Models/Requester.php` — the notifiable. **No `SoftDeletes`**, and `tickets.requester_id` is `NOT NULL` with `restrictOnDelete` (`2026_08_26_084625_create_tickets_table.php:18`), so `$ticket->requester` can never be `null`.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Status moves to `in-progress`, `pending`, `resolved`, `closed` or `reopened` | Activity row only | One **delayed** notification queued to the requester — AC1 |
| Status moves to `open` (or any slug absent from config) | Activity row only | **Nothing queued** — AC4 |
| The email body | — | Reference, subject, "**{old} → {new}**", and the resolution note when there is one — AC1, AC2 |
| Resolving with a note | Note stored as `meta.resolution` | Note quoted **verbatim** in the email — AC2 |
| Reopening with a reason | Reason stored as `meta.reason` | Email says the ticket is open again and **does not quote the reason** |
| Which slugs are visible | — | `config('notifications.requester.visible_statuses')`, from `NOTIFY_REQUESTER_STATUSES` — AC3 |
| Four moves in thirty seconds, three of them visible | — | Three jobs queued, **one email sent**, describing the final move — AC5 |
| A move away and back inside the window | — | The middle email is dropped; the final one is sent |
| The ticket has moved on when the job runs | — | `shouldSend()` returns `false`; **nothing sent, nothing failed** |
| `NOTIFY_REQUESTER_DELAY_SECONDS=0` | — | No delay; every visible move emails immediately — supported, and tested |
| A blank requester email | Unreachable via the API | Nothing queued, one `Log::warning` |
| Ticket soft-deleted between commit and delivery | — | `shouldSend()` returns `false`; nothing sent |
| A rolled-back status change | No status change | **No event, therefore no email** |
| Mail transport down when the worker runs | — | Job lands in `failed_jobs`; **the status change stands** |

---

## Backend Tasks

### 1 — The notification configuration

**Create file: `backend/config/notifications.php`**

```php
<?php

return [

    'requester' => [

        // Status slugs a requester hears about. A move INTO one of these queues
        // an email; every other target is internal-only. Slugs, never ids --
        // ids differ between the development and test databases, and
        // StatusSeeder guarantees these seven. `new` and `open` are absent on
        // purpose: TM-53's confirmation already covered creation, and `open` is
        // a triage step with no requester-facing meaning.
        'visible_statuses' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('NOTIFY_REQUESTER_STATUSES', 'in-progress,pending,resolved,closed,reopened')),
        ))),

        // How long a status email waits before it is sent. During the window a
        // later change supersedes it, so an agent working through a ticket in
        // one sitting produces one email rather than four. 0 sends immediately
        // and effectively disables supersession.
        'delay_seconds' => (int) env('NOTIFY_REQUESTER_DELAY_SECONDS', 300),

    ],

];
```

**TM-55 adds its admin-recipient rule to this file and TM-57 adds the retry policy.** Keep the two-level shape (`requester.*`) so `admin.*` and `retry.*` sit alongside without restructuring.

### 2 — Document the two variables

**File: `backend/.env.example`**

Append after the `ADMIN_*` block (**after line 70**) and **before** the `TICKETS_STALE_AFTER_HOURS` comment at **72**:

```
# Status slugs a requester is emailed about, from StatusSeeder. A move into any
# other status sends nothing. See config/notifications.php.
NOTIFY_REQUESTER_STATUSES=in-progress,pending,resolved,closed,reopened
# How long a status email waits before sending. A further change to the same
# ticket inside this window replaces it, so one sitting produces one email.
# Set to 0 to send immediately.
NOTIFY_REQUESTER_DELAY_SECONDS=300
```

**Leave lines 72–74 last** — they are TM-43's and its plan edits that comment.

### 3 — The domain event

**Create file: `backend/app/Events/TicketStatusChanged.php`**

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket's status moved. Dispatched once per successful transition, after the
 * transaction commits -- TM-57's fourth criterion, and the rule TM-34 set for
 * TicketAssigned.
 *
 * Status ids rather than names: an admin can rename a status, and with TM-54's
 * delay there are minutes between dispatch and delivery. The listener resolves
 * both against `statuses`, seven rows behind a primary-key lookup.
 *
 * `resolution` rides along so the email can quote the note without re-reading
 * ticket_activities. The reopen `reason` deliberately does NOT: it is a note
 * staff write to each other, and TM-54 AC2 asks only for the resolution.
 *
 * Scalar ids, no models, no marker interfaces -- the rule TM-34 set for this
 * directory.
 */
class TicketStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $fromStatusId,
        public readonly int $toStatusId,
        public readonly ?string $resolution,
    ) {}
}
```

### 4 — Dispatch it

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`** *(Story 32's `changeStatus()`, as extended by Stories 33 and 34)*

Add the import:

```php
use App\Events\TicketStatusChanged;
```

Story 32's closure returns `$ticket`; this story needs the **pre-move** status id as well, so the closure's return becomes a two-element array. Replace `return $ticket;` at the end of the closure with:

```php
            return [$ticket, $from->getKey()];
```

and the assignment above it with:

```php
        [$ticket, $fromStatusId] = DB::transaction(function () use (…): array {
```

Then, between the closing `});` and the existing `return TicketResource::make(...)`:

```php
        // AC1. Outside the transaction on purpose: TM-57 requires notifications
        // to fire after commit, and an email naming a status that rolled back is
        // worse than no email. A refused transition throws from
        // assertCanTransition() inside the closure and never reaches this line.
        TicketStatusChanged::dispatch(
            $ticket->getKey(),
            $fromStatusId,
            $ticket->status_id,
            $request->validated('resolution'),
        );
```

Three things that are load-bearing:

- **`$from->getKey()` is captured inside the closure**, where `$from` is the status read after `refresh()` and before `save()`. Reading it outside would give the **new** status and every email would say `Resolved → Resolved`. Test 11.2 is what catches that.
- **The closure's `use (…)` list and body are otherwise untouched** — not the lock, not `assertCanTransition()`, not `TicketTimestamps::apply()`, not the `$recorder->record(...)` call, not the `array_filter` on `meta`.
- **`$request->validated('resolution')` is the same expression Story 33's task 4 already puts into `meta`** — read from the request, not from the activity row, so nothing here depends on how that row is shaped.

### 5 — The notification

**Create file: `backend/app/Notifications/TicketStatusChangedNotification.php`**

```php
<?php

namespace App\Notifications;

use App\Models\Status;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * "Your ticket moved." Sent to the requester and nobody else.
 *
 * AC5 lives in two methods here. withDelay() holds the message back for the
 * configured window; shouldSend() drops it if the ticket has moved on since.
 * Together, an agent working a ticket through four statuses in one sitting
 * produces one email describing the final move rather than four near-identical
 * ones. Verified: SendQueuedNotifications::handle() reaches
 * NotificationSender::shouldSendNotification(), so shouldSend() runs in the
 * worker after the delay -- which is the only moment the check is meaningful.
 *
 * tries, backoff and timeout are absent -- TM-57 owns the retry policy.
 * TM-56 replaces these two views with the shared layout.
 */
class TicketStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly Status $from,
        public readonly Status $to,
        public readonly ?string $resolution,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * NotificationSender::queueNotification() prefers this method over a $delay
     * property or a Delay attribute, so the window is read from config at queue
     * time rather than frozen when the object was constructed.
     */
    public function withDelay(object $notifiable, string $channel): int
    {
        return max(0, (int) config('notifications.requester.delay_seconds'));
    }

    /**
     * AC5. Runs in the worker, after the delay. False means "the ticket has
     * moved on, so this message is about a status it has already left".
     * Keyed on status_id, not on a timestamp: a ticket that went
     * in-progress -> pending -> in-progress inside the window should send one
     * email about the final in-progress and drop the middle one.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $current = Ticket::query()->whereKey($this->ticket->getKey())->value('status_id');

        return $current !== null && (int) $current === $this->to->getKey();
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->ticket->reference}] Status update: {$this->to->name}")
            ->view(
                ['mail.tickets.status-changed', 'mail.tickets.status-changed-text'],
                [
                    'requesterName' => $notifiable->name,
                    'reference' => $this->ticket->reference,
                    'subject' => $this->ticket->subject,
                    'fromStatus' => $this->from->name,
                    'toStatus' => $this->to->name,
                    'resolution' => $this->resolution,
                ],
            );
    }
}
```

- **`shouldSend()` uses `->value('status_id')`, not `->first()`.** One column, one row, and `whereKey()` still applies the `SoftDeletingScope` — so a soft-deleted ticket yields `null` and the message is dropped. That is the deleted-ticket case handled by the same three lines.
- **Six scalars into the views, and never the `Ticket` model.** Story 45's rule: a template with a model in scope is one `{{ $ticket->assignee?->name }}` away from breaking TM-56's fifth criterion.
- **`readonly` promoted properties are safe under `SerializesModels`** — measured for Story 44 on **PHP 8.3.6**. The two `Status` models serialise as ids and re-resolve in the worker like the `Ticket` does.

### 6 — The two views

**Create file: `backend/resources/views/mail/tickets/status-changed.blade.php`**

```blade
{{--
    HTML part. The second of three view pairs TM-56 unifies.

    {{ }} everywhere: the resolution is free text an agent wrote and must be
    escaped. <pre> preserves its newlines -- measured for TM-53, the markdown
    pipeline collapses them, which is why these views exist rather than
    MailMessage->line().

    Nothing here may reference the assignee, the creator, an agent name or
    email, ticket_activities, or the reopen reason. TM-54 AC2 asks for the
    resolution note and nothing else.
--}}
<p>Hello {{ $requesterName }},</p>

<p>Your request <strong>{{ $reference }}</strong> has moved from <strong>{{ $fromStatus }}</strong> to <strong>{{ $toStatus }}</strong>.</p>

<p>Subject: {{ $subject }}</p>

@if (filled($resolution))
    <p>How it was resolved:</p>

    <pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0 0 16px;">{{ $resolution }}</pre>
@endif

<p>Reply to this email quoting {{ $reference }} if anything is still outstanding.</p>

<p>&mdash; {{ config('app.name') }}</p>
```

**Create file: `backend/resources/views/mail/tickets/status-changed-text.blade.php`**

```blade
Hello {{ $requesterName }},

Your request {{ $reference }} has moved from {{ $fromStatus }} to {{ $toStatus }}.

Subject: {{ $subject }}
@if (filled($resolution))

How it was resolved:

{!! $resolution !!}
@endif

Reply to this email quoting {{ $reference }} if anything is still outstanding.

-- {{ config('app.name') }}
```

- **`{!! !!}` on the resolution in the text view only** — Story 45's measured rule. `{{ }}` there emits `&lt;b&gt;` into a plain-text body, where the entity is simply wrong. **The HTML view keeps `{{ }}` and must never be changed.**
- **`@if (filled($resolution))`, not `@if ($resolution)`.** A resolution of `"0"` is falsy and would silently vanish.
- **Neither file promises a response time**, and neither mentions the reopen reason. Tests 12.5 and 12.6 keep both true.

### 7 — The listener

**Create file: `backend/app/Listeners/SendTicketStatusChangedNotification.php`**

```php
<?php

namespace App\Listeners;

use App\Events\TicketStatusChanged;
use App\Models\Status;
use App\Models\Ticket;
use App\Notifications\TicketStatusChangedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns a transition into the requester's update.
 *
 * Not queued: the notification it sends is, so one transition produces exactly
 * one job. AC4 is enforced here -- an internal-only target queues nothing at
 * all, rather than queueing a job that later decides not to send.
 */
class SendTicketStatusChangedNotification
{
    public function handle(TicketStatusChanged $event): void
    {
        $to = Status::query()->find($event->toStatusId);

        // AC4, and AC3's call site: the list is configuration, never a match arm.
        if ($to === null || ! in_array($to->slug, (array) config('notifications.requester.visible_statuses', []), true)) {
            return;
        }

        $from = Status::query()->find($event->fromStatusId);

        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with('requester')
            ->first();

        if ($ticket === null || $from === null) {
            Log::warning('Status notification skipped: ticket or origin status no longer exists.', [
                'ticket_id' => $event->ticketId,
            ]);

            return;
        }

        // requesters.email is NOT NULL and StoreTicketRequest requires a valid
        // address, so the API cannot reach this -- but NOT NULL does not forbid
        // '', which an import can write. Same guard as TM-53's listener.
        if (blank($ticket->requester->email)) {
            Log::warning('Status notification skipped: requester has no email address.', [
                'ticket_id' => $ticket->getKey(),
                'reference' => $ticket->reference,
            ]);

            return;
        }

        $ticket->requester->notify(
            new TicketStatusChangedNotification($ticket, $from, $to, $event->resolution),
        );
    }
}
```

- **No registration** — `app/Listeners` is auto-discovered; evidence in Story 44's task 3. **Do not add an `Event::listen()` call.** Test 11.7 pins the binding.
- **The visibility check comes first**, before any other query. An internal-only transition costs one primary-key lookup and returns.
- **`with('requester')` and nothing else.** The notification receives six scalars, so `category`, `priority`, `assignee` and `activities` are never loaded — TM-56's fifth criterion enforced by absence, the same way Story 45 does it.

### 8 — Document the email

**File: `docs/api-contract.md`**

Append a third paragraph to the `Notifications` section that Story 29 creates and Stories 44 and 45 extend:

```markdown
`POST /api/v1/tickets/{ticket}/status` dispatches `App\Events\TicketStatusChanged`
after its transaction commits. `App\Listeners\SendTicketStatusChangedNotification`
queues a `TicketStatusChangedNotification` to the requester **only** when the
target status slug appears in `config('notifications.requester.visible_statuses')`
(`NOTIFY_REQUESTER_STATUSES`, default `in-progress,pending,resolved,closed,reopened`).
The email names the old and new status, echoes the subject, and quotes the
resolution note verbatim when the transition carried one; it never quotes a
reopen reason and carries no assignee, agent name, internal note or link. Each
message is held for `NOTIFY_REQUESTER_DELAY_SECONDS` (default 300) and is
dropped at send time if the ticket has changed status again since, so a run of
rapid changes produces one email describing the final state.
```

**No frontend changes required.** **No README change** — Story 43's `## Queue and mail` section already covers running the worker and reading captured mail.

---

## Edge Cases & Failure Modes

- **The delay makes `queue:work --once` a no-op unless time moves.** `DatabaseQueue` filters on `available_at <= currentTime()` (`vendor/laravel/framework/src/Illuminate/Queue/DatabaseQueue.php:105, 149, 194`) and `currentTime()` comes from Carbon, so **`$this->travelTo(now()->addSeconds(301))` before driving the worker** is mandatory in tests 11.4–11.6. A test that forgets it sees an empty queue and a green assertion for the wrong reason — **assert the `jobs` row count before travelling**, so an unreached job cannot masquerade as a suppressed one.
- **`Notification::fake()` bypasses `shouldSend()` entirely.** The fake replaces the sender, so nothing calls it and every suppression test would pass vacuously. **AC5 must be tested either end to end (queue, travel, work, count messages) or by calling `shouldSend()` directly.** Tests 11.4 and 11.5 do one each.
- **A rolled-back transition sends nothing.** The dispatch is after `DB::transaction()` returns, and `assertCanTransition()` throws from inside the closure, so a `422` never reaches the dispatch. Test 11.8.
- **A no-op transition cannot occur.** Story 31's graph has no self-edges, so `assertCanTransition()` refuses `X → X` with a `422` — there is no `getDirty()`-style early return to worry about here, unlike assignment.
- **The status is renamed between dispatch and delivery.** The event carries ids and the listener resolves names at *queue* time, so a rename inside the window is not reflected. This is deliberate: the alternative is resolving in `toMail()`, which would let an admin's typo fix change the meaning of an email already scheduled. **Recorded as intended.**
- **The origin status is deleted between dispatch and handling.** `statuses` has no `SoftDeletes` and no delete endpoint, so this needs a hand-run `DELETE`. The listener logs and returns rather than sending an email that says "from ".
- **`visible_statuses` is empty or misspelt.** An empty list means **nothing is ever sent** — silently. Test 11.3 pins the empty-list behaviour so it is a documented configuration rather than a mystery; a misspelt slug simply never matches, which is why the config comment names `StatusSeeder` as the source of truth.
- **`NOTIFY_REQUESTER_DELAY_SECONDS=0`.** `withDelay()` returns `0`, the job is immediately available, and `shouldSend()` almost always passes because no time has elapsed. **Rapid changes then do produce several emails** — that is what the operator asked for by setting `0`, and test 11.6 asserts it rather than pretending otherwise.
- **A negative delay** is clamped by `max(0, …)`. Without the clamp, `->delay(-60)` would produce an `available_at` in the past, which happens to work and would still be a configuration bug hidden from its author.
- **Two visible changes, the second back to the first status** (`in-progress → pending → in-progress`) → two jobs; the first is dropped because `to` was `in-progress` and the ticket is now `in-progress`… **and so is the second.** Both match. **This is the one case where supersession does not deduplicate**, and the second job sends. **Only one email goes out** because the first job was dropped, so the observable outcome is still correct — but the reason is subtle, and test 11.5's docblock must state it so nobody "fixes" it into a counter.
- **A resolution note at its `max` length** (Story 33 sets 10–5000 characters) renders whole in both parts inside `<pre>` with `word-break: break-word`. **Do not truncate it** — AC2 says the email includes the note.
- **A resolution note containing markdown, HTML or a `<script>` tag.** Escaped in the HTML part by `{{ }}`, inert in the text part, and never interpreted as markdown because the views bypass that pipeline. Measured for Story 45; tests 12.3 and 12.7 repeat the guard here.
- **A reopen carries `meta.reason` and the email must not quote it.** The event never receives it, so the template cannot reach it. Test 12.6.
- **Arabic, emoji and RTL text** in the subject or the resolution. `utf8mb4` throughout, Blade escapes UTF-8 without transcoding, and **no `substr()` appears anywhere in this story.**
- **The mail transport is down when the worker runs.** The job fails into `failed_jobs` (Story 43 measured that path). **The status change is already committed and stands** — TM-57's third criterion, which holds only because the dispatch is outside the transaction.
- **`php artisan config:cache` freezes both new variables.** `composer test` runs `config:clear` first, so the suite is immune; a developer editing `NOTIFY_REQUESTER_STATUSES` after caching needs `config:clear`.
- **`php artisan event:cache`** freezes the listener map, as Stories 44 and 45 both record. `event:clear` is the fix.

---

## Test Plan

All backend, all Feature. **No frontend test changes. No existing test is modified or deleted.**

Both files seed explicitly — `$this->seed()` with no argument throws, because `AdminUserSeeder` aborts on the empty `ADMIN_PASSWORD` `phpunit.xml` never sets:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class, StatusTransitionSeeder::class]);
}

private function ticketAt(string $slug): Ticket   // Story 31's helper; TM-59 replaces it with TicketFactory
private function moveTo(Ticket $ticket, string $slug, ?string $resolution = null): TestResponse
```

**11. `backend/tests/Feature/Notifications/TicketStatusChangedNotificationTest.php`** (new, `RefreshDatabase`) — who is notified, when, and how often.

1. `test_a_visible_transition_queues_one_notification_to_the_requester` — **AC1.** `Notification::fake()`; move a ticket to `in-progress`; `assertSentTo($requester, …)`, `assertSentTimes(…, 1)`, and `assertNotSentTo($agent, …)`.
2. `test_the_notification_carries_the_status_it_moved_from` — **AC1.** Assert via the fake's callback that `$notification->from->slug` is the **pre-move** slug and `$notification->to->slug` the target. **This is the test that fails if `$from` is read outside the transaction**; say so in its docblock.
3. `test_an_internal_only_transition_queues_nothing` — **AC4 and AC3 together.** Move to `open` (absent from the default list) → `assertNothingSent()`. Then `config()->set('notifications.requester.visible_statuses', ['open'])`, move another ticket to `open`, and assert it **is** sent. **One test proving both that the list is honoured and that it is the only thing consulted.**
4. `test_a_status_email_is_delayed_and_not_sent_during_the_request` — **AC5, first half. No fakes.** Move to `in-progress`; assert `DB::table('jobs')->count() === 1`, the transport is empty, and the row's `available_at` is at least 300 seconds ahead of `created_at`. Then `travelTo(now()->addSeconds(301))`, `artisan('queue:work', ['--once' => true])`, and assert **one** message on the transport.
5. `test_rapid_changes_produce_one_email_describing_the_final_status` — **AC5, the flagship. No fakes.** Move `open → in-progress → pending → resolved` (with a note) inside the window; assert **3** rows in `jobs`; `travelTo(now()->addSeconds(301))`; run the worker three times; assert exactly **1** message on the transport and that its body names `Resolved`, not `In Progress` or `Pending`. **Docblock the `A → B → A` subtlety from the Edge Cases** so the mechanism is not mistaken for a counter.
6. `test_a_zero_delay_sends_immediately_and_does_not_suppress` — `config()->set('notifications.requester.delay_seconds', 0)`; two rapid visible moves; run the worker twice with no time travel; assert **2** messages. **The supported configuration, asserted rather than assumed.**
7. `test_the_listener_is_discovered` — `Event::fake()`; `Event::assertListening(TicketStatusChanged::class, SendTicketStatusChangedNotification::class)`.
8. `test_a_refused_transition_dispatches_nothing` — `Event::fake([TicketStatusChanged::class])`; post an illegal edge; assert `422` and `Event::assertNotDispatched(TicketStatusChanged::class)`.
9. `test_should_send_is_false_once_the_ticket_has_moved_on` — construct the notification for `in-progress`, move the ticket to `pending`, then call `shouldSend($requester, 'mail')` directly and assert `false`; assert `true` when the ticket is still at `in-progress`. **The unit-level companion to test 11.5, and the only place the method is exercised without the worker.**
10. `test_should_send_is_false_for_a_deleted_ticket` — soft-delete, then `shouldSend()` is `false`.
11. `test_a_requester_with_a_blank_email_queues_nothing_and_logs` — `Log::spy()`; dispatch the event for a ticket whose requester has `email: ''`; `assertNothingSent()` and a `warning` naming the reference.

**12. `backend/tests/Feature/Notifications/TicketStatusChangedMailTest.php`** (new, `RefreshDatabase`) — **AC1, AC2** and the exclusions. Every content assertion runs against **both** parts via a helper returning `[$html, $text]`.

1. `test_it_names_the_old_and_new_status` — **AC1.** Both parts contain both names; the subject header is `"[{reference}] Status update: {new}"`.
2. `test_a_resolution_email_quotes_the_note` — **AC2.** Both parts contain the note verbatim.
3. `test_markdown_and_line_breaks_in_the_resolution_survive` — **AC2, the view-pair guard.** A note of `"Steps:\n# 1 replaced cable\n**checked** link\n[ref](http://evil.test)"`: both parts contain the literals; the HTML part contains no `<strong>`, no `<h1>` around that text and no `href="http://evil.test"`. **Fails the moment `toMail()` reverts to `->line()`.**
4. `test_a_non_resolution_email_has_no_resolution_block` — moving to `in-progress` produces neither part containing `How it was resolved`.
5. `test_a_resolution_of_the_string_zero_is_still_shown` — the `filled()` guard, not truthiness.
6. `test_a_reopen_email_does_not_quote_the_reopen_reason` — reopen with a distinctive reason; assert it appears in **neither** part, while the status names do. **Docblock the decision.**
7. `test_html_in_the_resolution_is_escaped_in_html_and_raw_in_text` — `<script>` escaped in the HTML part; `<b>` unescaped in the text part. **The regression guard against `{!! !!}` being "hardened" to `{{ }}`.**
8. `test_it_carries_no_agent_or_triage_information` — a ticket with an assignee, a non-default priority and an `escalation_reason`; none of those strings, nor the acting agent's name or email, appear in either part.
9. `test_it_contains_no_link_to_the_application` — neither part contains `localhost:5173`, `config('app.frontend_url')` or `/tickets/`. Story 45's decision, unchanged.
10. `test_it_promises_no_response_time` — neither part contains, case-insensitively, `SLA`, `guarantee`, `within 24`, `within 48`, `business day`, `business hours`, `response time` or `as soon as possible`.
11. `test_both_parts_are_present_and_non_empty` — TM-56 inherits this.
12. `test_arabic_and_emoji_survive_both_parts`.

---

## Verification Steps

1. **Services up:** from the repo root, `docker compose up -d --wait`; all three containers **healthy**.
2. **Gates present:** from `backend/`, `php artisan route:list --name=tickets.status` lists the route and `grep -n "SLUG_RESOLVED" app/Models/Status.php` matches. **If either is missing, stop and land Stories 32–34 first.** Confirm `grep -n QUEUE_CONNECTION phpunit.xml` shows `database`.
3. **Backend tests:** `composer test`. Expect the three pre-existing failures and **nothing else**.
4. **This story alone:** `php artisan test --filter='TicketStatusChangedNotificationTest|TicketStatusChangedMailTest'`.
5. **Formatting:** `./vendor/bin/pint --test` exits `0`.
6. **The delay is real, by hand.** With Mailpit up, move a ticket to `In Progress` over HTTP, then:
   ```bash
   cd backend
   php artisan tinker --execute="\$j = DB::table('jobs')->first(); echo \$j->available_at - \$j->created_at;"   # 300
   ```
   Mailpit is empty and `php artisan queue:work --once` prints nothing — **the job is not yet available.**
7. **Supersession is real, by hand.** Without waiting, move the same ticket to `Resolved` with a note. `DB::table('jobs')->count()` is **2**. Set `NOTIFY_REQUESTER_DELAY_SECONDS=0` in `backend/.env`, `php artisan config:clear`, then `php artisan queue:work --stop-when-empty`. **Exactly one email arrives in Mailpit, and it names Resolved.** Restore the delay.
8. **AC4 by hand.** Move a ticket to `Open`. `DB::table('jobs')->count()` is **0** — nothing was queued at all, not a job that declined to send.
9. **AC3 by hand.** Set `NOTIFY_REQUESTER_STATUSES=open`, `php artisan config:clear`, move a ticket to `Open` → a job appears; move one to `Resolved` → none does. **No code changed.** Restore the default.
10. **Read the email in Mailpit, both tabs.** Resolve a ticket with a note containing `**bold**`, `# heading`, `[click](http://evil.test)`, a blank line and a `<script>` tag. HTML tab: literal markdown, no clickable `evil.test`, script visible as text, blank line intact. Text tab: the same, with no `&lt;` entities.
11. **The `$from` capture guards itself.** Move `$from->getKey()` outside the transaction closure and run `php artisan test --filter=test_the_notification_carries_the_status_it_moved_from`. **It must fail.** Restore it.
12. **Regression:** `git diff --stat` touches only `backend/config/notifications.php`, `backend/.env.example`, `backend/app/Events/TicketStatusChanged.php`, `backend/app/Http/Controllers/Api/V1/TicketController.php` (**the closure's return, its destructuring, one dispatch and one import**), `backend/app/Notifications/TicketStatusChangedNotification.php`, `backend/app/Listeners/SendTicketStatusChangedNotification.php`, the two Blade views, `docs/api-contract.md` and the two test files. **No migration, no route file, no `TicketWorkflow`, no `TicketTimestamps`, no `ChangeTicketStatusRequest`, no `TicketResource`, no `TicketPolicy`, no `frontend/` file.**

---

## Done Criteria

- [ ] A transition into a requester-visible status queues **one** notification to the requester naming the **old** and **new** status; the acting agent and everyone else receive nothing. *(AC1)*
- [ ] A resolution email quotes the note verbatim — markdown uninterpreted, line breaks intact, HTML escaped in the HTML part and raw in the text part. *(AC2)*
- [ ] The visible-status list lives in `config/notifications.php`, is backed by `NOTIFY_REQUESTER_STATUSES`, is keyed on **slug**, and is the **only** thing the listener consults — proven by a test that flips the list and inverts the outcome with no code change. *(AC3)*
- [ ] A transition into any other status queues **nothing at all** — not a job that later declines. *(AC4)*
- [ ] Four rapid changes produce **one** email describing the final status, via `withDelay()` plus `shouldSend()`; `NOTIFY_REQUESTER_DELAY_SECONDS=0` is supported and tested as sending each one. *(AC5)*
- [ ] `App\Events\TicketStatusChanged` carries ticket id, both status ids and the resolution — **never the reopen reason** — has no marker interfaces, and is dispatched **after** the transaction commits; a refused transition dispatches nothing.
- [ ] `$from->getKey()` is captured **inside** the transaction, with a test that fails if it moves out.
- [ ] The email carries no assignee, creator, agent name, agent email, priority, category, escalation reason, internal note, reopen reason, response-time promise or link.
- [ ] `App\Listeners\SendTicketStatusChangedNotification` is auto-discovered with **no `Event::listen()` registration**, and a test asserts the binding.
- [ ] No `tries`, `backoff`, `timeout`, shared layout, migration, route or frontend file — TM-55, TM-56 and TM-57 still own all of it.
- [ ] `composer test` shows the same three pre-existing failures and no new ones; `./vendor/bin/pint --test` exits `0`.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 47.**
