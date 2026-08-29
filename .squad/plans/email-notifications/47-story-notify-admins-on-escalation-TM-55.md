# Story 47 — Notify admins on escalation (Story: TM-55)

## Prerequisites

- **This is the story Stories 35 and 36 mislabelled as "TM-53".** [`../status-workflow-escalation/35-story-escalate-a-ticket-TM-41.md`](../status-workflow-escalation/35-story-escalate-a-ticket-TM-41.md) defers escalation mail to "TM-53" at lines **26**, **485** and **626**, and [`36-story-escalated-tickets-are-visible-at-a-glance-TM-42.md`](../status-workflow-escalation/36-story-escalated-tickets-are-visible-at-a-glance-TM-42.md) does the same at **47**. **TM-53 is the requester's creation confirmation** ([`45-story-confirm-ticket-creation-to-the-requester-TM-53.md`](45-story-confirm-ticket-creation-to-the-requester-TM-53.md)); escalation mail is **TM-55**, and this is it. Story 45's plan recorded the mislabel so this story would not be skipped on the strength of a plan claiming it was already spoken for. **Do not edit those four lines** — note the correction in the PR instead.
- **Story 43 (TM-51) — hard gate.** [`43-story-queue-and-mail-infrastructure-TM-51.md`](43-story-queue-and-mail-infrastructure-TM-51.md). AC1 says the email is **queued**; under `phpunit.xml:45`'s current `QUEUE_CONNECTION=sync` a queued notification runs inline and test 12.2 would pass against code that sends during the request.
- **Story 35 (TM-41) — hard gate, and it does not exist on disk.** Verified while planning: `TicketController` has **no `escalate()`** (only `show()` **23–28** and `store()` **30–50**); `app/Http/Requests/Api/V1/EscalateTicketRequest.php` does not exist; `TicketActivityEvent` has only `Created` and `CategoryChanged` (**7–8**) — no `Escalated`; `User` has **no `assignedTickets()`** relation. **This story adds one line to the `escalate()` that Story 35 builds.** Gate: `php artisan test --filter=TicketEscalationTest` green before you start.
- **Story 35 dispatches nothing, so this story owns both the event and the dispatch point** — the same position Story 46 is in, and unlike Story 44, which inherited `TicketAssigned` from Story 29. Story 35's scope list says *"No email to admins — [TM-55] owns 'Notify admins on escalation', and E8's rule is that nothing is sent inline during a request."*
- **Story 44 (TM-52) — hard gate for one config key.** Its task 1 adds `'frontend_url' => env('FRONTEND_URL', …)` to `backend/config/app.php`. **That key does not exist today** — `grep -rn "frontend_url" backend/config` matches nothing, and `FRONTEND_URL` is reachable only through `env()` in `config/cors.php:22`. This story's email carries a deep link and needs it. **If Story 44 has not landed, add the key verbatim from its task 1 and record it in the PR so Story 44 does not add a second.**
- **Story 46 (TM-54) — soft dependency, one file.** It creates `backend/config/notifications.php` with a `requester.*` block; task 1 here adds an `admin.*` block beside it. **If Story 46 has not landed, this story creates the file** with only the `admin` key, and Story 46 adds `requester` alongside. Neither blocks the other.
- **Story 45 (TM-53) — soft, for convention only.** Its measured view-pair rules (`{{ }}` in HTML, `{!! !!}` in text, and why `MailMessage->line()` mangles free text) apply here because an escalation **reason** is free text a human wrote. Nothing in this story imports from it.
- **No new composer or npm dependency, no migration, no route, no policy change, no frontend file.** `tickets.escalation_level`, `escalated_at`, `escalated_by` and `escalation_reason` already exist (`2026_08_26_084625_create_tickets_table.php:24–27`), and `users.role` is an enum cast to `App\Enums\UserRole` with `scopeActive()` on the model (`User.php:36, 46–50`).
- **Baseline, 2026-08-27:** `composer test` → **101 tests, 98 passing, 3 failing** (`PasswordThrottleTest`, `RouteAuthorizationTest`, `TicketReferenceTest` — all pre-existing); `./vendor/bin/pint --test` exits `0`.

---

## Story Goal

Every active admin hears about an escalation the moment it happens, with enough in the subject line to file it and enough in the body to decide whether to act.

1. `App\Events\TicketEscalated` — dispatched once from `escalate()`, **after** the transaction commits.
2. `App\Listeners\SendTicketEscalatedNotification` — auto-discovered, synchronous, and the one place the recipient list is built.
3. `App\Notifications\TicketEscalatedNotification implements ShouldQueue` — **one job per admin**, verified below.
4. A subject line whose **first** characters are a filterable token, and which reads differently at level 2 and above.
5. Two Blade views carrying the reason, the level, the requester, who escalated it, where it landed, and a deep link.

**Not in scope, and each belongs to a named story.** **No `tries`, `backoff`, `timeout` or `retryUntil`** — TM-57 (E8-S7), as Stories 43–46 all recorded. **No shared mail layout** — TM-56 unifies the three view pairs this story, TM-53 and TM-54 create; **this story creates the third, which is what makes TM-56 possible.** **No delay and no supersession** — TM-54's AC5 mechanism is deliberately not repeated here; see the decision. **No de-escalation email, no auto-escalation** (TM-43 flags stale tickets and does not escalate them), **no escalation digest, no per-admin preference table, no unsubscribe.** **No email to the requester or the previous assignee** — nothing in the backlog asks for one, and an escalation is an internal routing event. **No change to `escalate()`'s transaction, `escalationTarget()`, `nextPriority()`, `TicketPolicy`, `TicketResource`, `routes/api.php` or any seeder** — Story 35 owns every one of those lines.

---

## Decision — "all active admins" means all of them, including the one who escalated

`User::query()->where('role', UserRole::Admin)->active()->get()`, with **no `reject()` on the actor**.

- **AC1 is unqualified:** *"Escalation queues an email to all active admins."* Story 44's self-exclusion existed because TM-52 has an explicit criterion demanding it (*"No email is sent when a user assigns a ticket to themselves"*). **There is no such clause here**, and inventing one would be the planner overriding the acceptance criteria.
- **The actor is frequently not an admin at all.** Story 35 makes `TicketPolicy::escalate()` a pure role gate returning `true`, so **any staff member can escalate**. Excluding "the actor" would be a no-op in the common case and a surprise in the rare one.
- **Story 35 already made the parallel call for routing:** *"The actor is not excluded — an admin escalating a ticket they hold keeps it and still gets the level and the priority."* Sending them the mail is the same decision one layer up.
- **The tension is recorded rather than hidden:** an admin who escalates a ticket receives their own escalation email. **If the team wants otherwise it is one `->reject()` in the listener and a changed test** — but it is a change to the acceptance criteria, not a bug fix.
- **`->active()` matters.** A deactivated admin keeps their row (deactivation is not a delete) and must not be mailed. `User::scopeActive()` (`User.php:46–50`) already exists.

## Decision — the subject leads with the token, breaking this epic's reference-first convention

| Level | Subject |
|---|---|
| 1 | `[ESCALATED] [TKT-2026-000042] Printer offline` |
| 2 | `[ESCALATED ×2] [TKT-2026-000042] Printer offline` |
| 5 | `[ESCALATED ×5] [TKT-2026-000042] Printer offline` |

- **AC3 is about mail-client filtering**, and a filter rule is written against the **start** of a subject far more reliably than against its middle. TM-52 uses `[{reference}] Assigned to you: …` and TM-54 `[{reference}] Status update: …`; **this story departs from that on purpose**, and the departure is the criterion being satisfied rather than an inconsistency to tidy.
- **The invariant filter token is `[ESCALATED`** — the prefix both forms share. A rule matching that string catches every escalation at every level. **Do not change the bracket, the case or the word.**
- **The token is bracketed so a ticket subject cannot forge it.** The subject is echoed *after* the token, so a ticket titled `ESCALATED: help` sorts into no admin's escalation folder by accident.
- **`×N` follows Story 36's badge rule exactly, including never rendering `×1`.** Its plan states it twice: *"a bare 'Escalated' is the level-1 signal and the `×` is what says 'more than once'"*, and *"Never `×1`."* **The mail and the list badge use the same vocabulary**, so an admin reading the queue and an admin reading their inbox learn one convention, not two.
- **`Str::limit($ticket->subject, 60)`** on the echoed subject, matching Story 44. `substr()` must not be used — it splits multibyte characters.

## Decision — AC4 is carried by three signals, not one

The subject token, a body line, and a mail header.

- **Subject:** `[ESCALATED ×2]` versus `[ESCALATED]`.
- **Body:** at level ≥ 2 the email opens with an extra line — *"This ticket has now been escalated N times."* — that is simply absent at level 1. **Not a styled banner**: Story 36's reasoning applies unchanged — *"colour alone would fail the criterion for a colour-blind reader"* — and the text is what carries it.
- **Header:** `X-Ticket-Escalation-Level: 2` and `X-Ticket-Reference: TKT-2026-000042`, added via `MailMessage::withSymfonyMessage()` (`vendor/laravel/framework/src/Illuminate/Notifications/Messages/MailMessage.php:454`). This is what lets an admin write a rule for *repeat* escalations specifically, which the subject token alone cannot express numerically.

## Decision — no delay, no supersession, one email per escalation

TM-54's `withDelay()` + `shouldSend()` machinery is deliberately **not** repeated.

- **Nothing in TM-55's criteria asks for it.** TM-54's fifth criterion exists because a single agent can walk a ticket through four statuses in a minute; **escalation is a deliberate act with a mandatory reason**, and Story 35 requires a 10–5000 character justification for each one.
- **Two escalations are two facts, not a burst.** Level 1 → level 2 is precisely the transition AC4 says must be *visibly distinguished* — suppressing the second email would delete the distinction the story was written to create.
- **A delay would actively harm this email.** It is the "reach me when I am not looking at the board" signal; holding it for five minutes is the opposite of the point.
- **Recorded so nobody "harmonises" the two stories later.** If escalation storms become real, the answer is a rule in `config('notifications.admin.*')`, not a copy of TM-54's supersession — which is keyed on `status_id` and means nothing here.

## Decision — a Blade view pair, following Story 45's measured rules

- **The escalation reason is free text a human wrote**, exactly like TM-53's description and TM-54's resolution note. Story 45 measured what `MailMessage->line()` does to such text: **newlines collapse into one paragraph**, `[text](url)` becomes a **live link**, and markdown is consumed.
- **`{{ }}` in the HTML view, `{!! !!}` in the text view** — Story 45's rule verbatim, for the reason it measured: Blade escapes in plain-text templates too, emitting `&lt;b&gt;` where the entity is wrong output.
- **This email may carry internal information**, unlike TM-53's and TM-54's. The recipients are admins. It names the escalating staff member, the routed assignee and the current priority, and it carries a deep link into the SPA — all of which TM-53's fifth criterion forbids for a *requester*. **TM-56's fifth criterion still applies: no stack traces, and no internal note bodies.**

---

## Context — Read These Files First

1. [`../status-workflow-escalation/35-story-escalate-a-ticket-TM-41.md`](../status-workflow-escalation/35-story-escalate-a-ticket-TM-41.md) — **its task 4, `escalate()`, in full.** Note that every "from" value is captured before `save()`, that `escalation_level` is incremented **inside** the closure, that the closure returns `$ticket`, and that the method ends with `return TicketResource::make($ticket->load([…]))->response();`. **Task 4 here adds one dispatch between those two statements and changes nothing inside the closure.** Read its `escalationTarget()` helper too — it is what decides where the ticket lands, which the email reports.
2. [`../status-workflow-escalation/36-story-escalated-tickets-are-visible-at-a-glance-TM-42.md`](../status-workflow-escalation/36-story-escalated-tickets-are-visible-at-a-glance-TM-42.md) — **lines 100–102 and 227**, the badge vocabulary. `Escalated` at level 1, `Escalated ×N` above, **never `×1`**, and the reason the text carries the distinction rather than the colour. Task 5's subject line is the same rule in a different medium.
3. `backend/database/migrations/2026_08_26_084625_create_tickets_table.php:24–27` — `escalation_level` is `unsignedTinyInteger` defaulting to `0`, `escalated_at` a nullable timestamp, `escalated_by` a nullable FK with `nullOnDelete`, `escalation_reason` a nullable `text`. **`escalation_level` is cast to `integer` on the model** (`Ticket.php:20`).
4. `backend/app/Models/User.php` — **`scopeActive()` at 46–50**, `role` cast to `UserRole` at **36**, `isAdmin()` at **41–44**, and `Notifiable` already in the trait list at **24**. Task 6 needs all four and adds none of them.
5. `backend/app/Enums/UserRole.php` — two cases, `Admin` and `Agent`. Task 6 filters on `UserRole::Admin`, **never on the string `'admin'`**.
6. [`44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md`](44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md) — its **task 1** (`config('app.frontend_url')`), its **decision on the deep link** (`{frontend_url}/tickets/{id}`, **numeric id**, because `frontend/src/router/index.ts:52` is `/tickets/:id` and `TicketDetailView.vue:9` calls `Number(route.params.id)`), and its **task 3** listener shape with the verified evidence that `app/Listeners` is auto-discovered.
7. [`45-story-confirm-ticket-creation-to-the-requester-TM-53.md`](45-story-confirm-ticket-creation-to-the-requester-TM-53.md) — its **view-pair decision** and the `{{ }}` / `{!! !!}` rule, with the measurements behind both.
8. [`46-story-notify-the-requester-on-status-change-and-resolution-TM-54.md`](46-story-notify-the-requester-on-status-change-and-resolution-TM-54.md) — its **task 1**, `backend/config/notifications.php`, and its instruction to keep the two-level shape so `admin.*` sits beside `requester.*`. **Task 1 here is the other half of that arrangement.**
9. `backend/database/seeders/AdminUserSeeder.php` — **it creates exactly one admin**, and only when `ADMIN_PASSWORD` is set, which `phpunit.xml` never does. Story 35's plan already warns: *"`AdminUserSeeder` creates one admin; most tests need a second."* Every test here builds its admins with `User::factory()->admin()` (`UserFactory.php:49–52`).

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| A ticket is escalated | Two activity rows, fields written | One queued email **per active admin** — AC1 |
| Three active admins exist | — | **Three** rows in `jobs`, one per recipient |
| An admin is deactivated | Keeps their row | **Not emailed** |
| An **agent** escalates | Allowed (pure role gate) | Every active admin is emailed; the agent is not |
| An **admin** escalates | — | They are emailed too — **AC1 says all** |
| Subject, level 1 | — | `[ESCALATED] [TKT-…] <subject>` — AC3 |
| Subject, level 2+ | — | `[ESCALATED ×N] [TKT-…] <subject>` — AC3, AC4 |
| Body, level 2+ | — | An extra opening line naming the count — AC4 |
| Headers | — | `X-Ticket-Escalation-Level` and `X-Ticket-Reference` — AC3, AC4 |
| The email's body | — | Reason, level, requester, who escalated, where it landed, current priority, deep link — AC2 |
| A reason with markdown or line breaks | — | Rendered **literally**, breaks preserved |
| Two escalations in a minute | — | **Two emails.** No delay, no supersession |
| No active admin remains | Story 35 refuses with `422` | Listener logs a warning and queues nothing |
| Escalation refused (terminal ticket) | `422`, nothing written | **No event, therefore no email** |
| Mail transport down | — | Jobs land in `failed_jobs`; **the escalation stands** |

---

## Backend Tasks

### 1 — The admin notification configuration

**File: `backend/config/notifications.php`** *(Story 46's task 1; if that story has not landed, create the file with only this block)*

Add beside the `requester` key:

```php
    'admin' => [

        // Escalations go to every active admin. AC1 of TM-55 is unqualified,
        // and TicketPolicy::escalate() admits all staff, so the escalating user
        // is frequently not an admin at all. Set to false to exclude an admin
        // who escalated a ticket from their own notification.
        'notify_escalating_admin' => (bool) env('NOTIFY_ESCALATING_ADMIN', true),

        // The token every escalation subject starts with, so a mail rule can
        // match on prefix. `[ESCALATED` is the invariant; the level suffix is
        // appended above level one.
        'escalation_subject_token' => env('NOTIFY_ESCALATION_TOKEN', 'ESCALATED'),

    ],
```

**Two keys, not a switchboard.** The first exists because the decision above records a real tension and the team may want the other side of it **without a code change**; the second exists because the token is the contract every admin's mail rule is written against, and pinning it in config means a rename is a deploy rather than a release.

### 2 — Document the two variables

**File: `backend/.env.example`**

Append immediately after Story 46's `NOTIFY_REQUESTER_*` block, still **before** the `TICKETS_STALE_AFTER_HOURS` lines at **72–74**:

```
# Whether an admin who escalates a ticket also receives the escalation email.
# TM-55 says "all active admins", so the default is true.
NOTIFY_ESCALATING_ADMIN=true
# The token every escalation subject starts with, for mail-client filtering.
# Filter on the literal "[ESCALATED" to catch every level.
NOTIFY_ESCALATION_TOKEN=ESCALATED
```

### 3 — The domain event

**Create file: `backend/app/Events/TicketEscalated.php`**

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket was escalated. Dispatched once per successful escalation, after the
 * transaction commits -- TM-57's fourth criterion, and the rule TM-34 set for
 * TicketAssigned.
 *
 * The level and the reason ride along rather than being read off the ticket:
 * a second escalation seconds later would otherwise make the first email
 * report the second one's level and quote the second one's reason. This is the
 * same argument TM-34 made for `reason` and TM-54 for the status ids.
 *
 * Scalar values, no models, no marker interfaces -- the rule TM-34 set for
 * this directory.
 */
class TicketEscalated
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $actorId,
        public readonly int $level,
        public readonly string $reason,
    ) {}
}
```

**`$reason` is `string`, not `?string`.** Story 35's `EscalateTicketRequest` makes it required at 10–5000 characters; a nullable type here would advertise a state the endpoint cannot produce.

### 4 — Dispatch it

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`** *(Story 35's `escalate()`)*

Add the import:

```php
use App\Events\TicketEscalated;
```

Insert between the closing `});` of the transaction and the existing `return TicketResource::make(...)`:

```php
        // AC1. Outside the transaction on purpose: TM-57 requires notifications
        // to fire after commit, and an email announcing an escalation that
        // rolled back is worse than no email. A terminal ticket throws
        // ValidationException from inside the closure and never reaches here.
        TicketEscalated::dispatch(
            $ticket->getKey(),
            $actor->getKey(),
            $ticket->escalation_level,
            $reason,
        );
```

- **`$ticket` is the closure's return value and `$ticket->escalation_level` is the *new* level** — Story 35 increments it inside the transaction and saves. Reading it here is correct and needs no extra capture, unlike Story 46's `$from`, which had to be captured before `save()` because it is overwritten.
- **`$actor` and `$reason` are already local variables** in Story 35's method (its first three lines). Nothing new is read from the request.
- **Nothing inside the closure changes** — not the lock, not the terminal check, not `escalationTarget()`, not either `$recorder->record(...)` call.

### 5 — The notification

**Create file: `backend/app/Notifications/TicketEscalatedNotification.php`**

```php
<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

/**
 * "A ticket has been escalated." Sent to every active admin.
 *
 * Deliberately NOT delayed and NOT superseded, unlike TM-54's status email:
 * escalation is a deliberate act with a mandatory reason, level 1 -> level 2 is
 * exactly the distinction AC4 requires, and this is the "reach me when I am not
 * looking at the board" signal -- holding it back defeats the purpose.
 *
 * tries, backoff and timeout are absent -- TM-57 owns the retry policy.
 * TM-56 replaces these two views with the shared layout.
 */
class TicketEscalatedNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly User $actor,
        public readonly int $level,
        public readonly string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;

        return (new MailMessage)
            ->subject(sprintf(
                '[%s] [%s] %s',
                $this->subjectToken(),
                $ticket->reference,
                Str::limit($ticket->subject, 60),
            ))
            ->withSymfonyMessage(function (Email $message): void {
                // Numeric filtering, which the subject token cannot express.
                $message->getHeaders()->addTextHeader('X-Ticket-Escalation-Level', (string) $this->level);
                $message->getHeaders()->addTextHeader('X-Ticket-Reference', $this->ticket->reference);
            })
            ->view(
                ['mail.tickets.escalated', 'mail.tickets.escalated-text'],
                [
                    'adminName' => $notifiable->name,
                    'reference' => $ticket->reference,
                    'subject' => $ticket->subject,
                    'level' => $this->level,
                    'reason' => $this->reason,
                    'escalatedBy' => $this->actor->name,
                    'requesterName' => $ticket->requester->name,
                    'requesterEmail' => $ticket->requester->email,
                    'assigneeName' => $ticket->assignee?->name,
                    'priorityName' => $ticket->priority->name,
                    'ticketUrl' => $this->ticketUrl($ticket),
                ],
            );
    }

    /**
     * AC3 and AC4. `[ESCALATED` is the invariant prefix every mail rule matches;
     * the suffix appears only above level one -- TM-42's badge rule, in a
     * different medium: a bare token IS the level-one signal, and there is never
     * an `×1`.
     */
    private function subjectToken(): string
    {
        $token = (string) config('notifications.admin.escalation_subject_token', 'ESCALATED');

        return $this->level > 1 ? "{$token} ×{$this->level}" : $token;
    }

    private function ticketUrl(Ticket $ticket): string
    {
        // The SPA route is /tickets/:id and loads with Number(route.params.id),
        // so this is the primary key -- never the reference. TM-52's decision.
        return rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$ticket->getKey();
    }
}
```

- **Eleven scalars into the views, and never the `Ticket` model** — Story 45's rule. A template with a model in scope can reach `ticket_activities`, which TM-56's fifth criterion forbids.
- **`$ticket->assignee?->name` is the one null-safe access.** `tickets.assigned_to` is nullable, and although Story 35's `escalationTarget()` always sets it, the listener re-fetches and a hand-run `UPDATE` could clear it. `requester` and `priority` are `NOT NULL` with `restrictOnDelete` and need no guard.
- **`withSymfonyMessage()` takes a `Symfony\Component\Mime\Email`** and is invoked when the message is built — verified at `MailMessage.php:454`. It runs in the worker like everything else in `toMail()`.
- **`readonly` promoted properties are safe under `SerializesModels`** — measured for Story 44 on **PHP 8.3.6**. Both models serialise as ids and re-resolve in the worker.

### 6 — The listener

**Create file: `backend/app/Listeners/SendTicketEscalatedNotification.php`**

```php
<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\TicketEscalated;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketEscalatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Turns an escalation into one email per active admin.
 *
 * Not queued: the notification it sends is, and Notification::send() fans out
 * to one SendQueuedNotifications job per notifiable -- verified in
 * NotificationSender::queueNotification(), which loops the notifiables and
 * dispatches inside the loop. Three active admins therefore produce three jobs,
 * which is what the tests count.
 */
class SendTicketEscalatedNotification
{
    public function handle(TicketEscalated $event): void
    {
        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with(['requester', 'priority', 'assignee'])
            ->first();

        $actor = User::query()->find($event->actorId);

        if ($ticket === null || $actor === null) {
            Log::warning('Escalation notification skipped: ticket or actor no longer exists.', [
                'ticket_id' => $event->ticketId,
                'actor_id' => $event->actorId,
            ]);

            return;
        }

        $admins = User::query()
            ->where('role', UserRole::Admin)
            ->active()
            ->get();

        if (! config('notifications.admin.notify_escalating_admin', true)) {
            $admins = $admins->reject(fn (User $admin): bool => $admin->is($actor));
        }

        if ($admins->isEmpty()) {
            // Story 35 refuses an escalation with a 422 when no active admin
            // exists, so this means every admin was deactivated between the
            // commit and this line. Nothing to send, and worth knowing about.
            Log::warning('Escalation notification skipped: no active admin to notify.', [
                'ticket_id' => $ticket->getKey(),
                'reference' => $ticket->reference,
            ]);

            return;
        }

        Notification::send(
            $admins,
            new TicketEscalatedNotification($ticket, $actor, $event->level, $event->reason),
        );
    }
}
```

- **No registration** — `app/Listeners` is auto-discovered; evidence in Story 44's task 3. Test 12.8 pins the binding.
- **`where('role', UserRole::Admin)`, not `'admin'`.** `role` is cast to the enum (`User.php:36`), and passing the case keeps the query honest if the backing value ever changes.
- **`$admin->is($actor)` rather than comparing ids**, so the comparison is wrong-model-safe.
- **`Notification::send()`, not a loop of `$admin->notify()`.** Both produce one job each, but `send()` is the framework's own fan-out and keeps `Notification::assertSentTo($admins, …)` readable in tests.

### 7 — The two views

**Create file: `backend/resources/views/mail/tickets/escalated.blade.php`**

```blade
{{--
    HTML part. The third of three view pairs TM-56 unifies.

    This email goes to admins, so unlike TM-53's and TM-54's it MAY name staff,
    the assignee and the priority, and MAY carry a link into the SPA. It still
    may not carry internal note bodies or stack traces -- TM-56 AC5.

    {{ }} everywhere: the reason is free text an agent wrote and must be
    escaped. <pre> preserves its newlines -- measured for TM-53, the markdown
    pipeline collapses them.
--}}
<p>Hello {{ $adminName }},</p>

@if ($level > 1)
    <p><strong>This ticket has now been escalated {{ $level }} times.</strong></p>
@endif

<p><strong>{{ $reference }}</strong> was escalated by {{ $escalatedBy }}.</p>

<p>Subject: {{ $subject }}</p>
<p>Escalation level: {{ $level }}</p>
<p>Priority: {{ $priorityName }}</p>
<p>Requester: {{ $requesterName }} ({{ $requesterEmail }})</p>
<p>Now assigned to: {{ $assigneeName ?? 'nobody' }}</p>

<p>Reason given:</p>

<pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0 0 16px;">{{ $reason }}</pre>

<p><a href="{{ $ticketUrl }}">Open the ticket</a></p>

<p>&mdash; {{ config('app.name') }}</p>
```

**Create file: `backend/resources/views/mail/tickets/escalated-text.blade.php`**

```blade
Hello {{ $adminName }},
@if ($level > 1)

This ticket has now been escalated {{ $level }} times.
@endif

{{ $reference }} was escalated by {{ $escalatedBy }}.

Subject: {{ $subject }}
Escalation level: {{ $level }}
Priority: {{ $priorityName }}
Requester: {{ $requesterName }} ({{ $requesterEmail }})
Now assigned to: {{ $assigneeName ?? 'nobody' }}

Reason given:

{!! $reason !!}

Open the ticket: {{ $ticketUrl }}

-- {{ config('app.name') }}
```

- **`{!! !!}` on the reason in the text view only** — Story 45's measured rule. **The HTML view keeps `{{ }}` and must never be changed.**
- **`@if ($level > 1)`**, matching Story 36's `props.level > 1` computed exactly. **Never render the extra line at level 1.**
- **`$assigneeName ?? 'nobody'`**, not a blank. An admin scanning the mail needs "unassigned" to be a word, not an absence.
- **The link is a plain `<a>` in the HTML part and a bare URL in the text part.** No button component — TM-56 owns anything shared, and a single anchor renders in every client.

### 8 — Document the email

**File: `docs/api-contract.md`**

Append a fourth paragraph to the `Notifications` section that Story 29 creates and Stories 44, 45 and 46 extend:

```markdown
`POST /api/v1/tickets/{ticket}/escalate` dispatches `App\Events\TicketEscalated`
after its transaction commits. `App\Listeners\SendTicketEscalatedNotification`
queues a `TicketEscalatedNotification` to **every active admin** — one job per
recipient — including the escalating user when they are an admin, unless
`NOTIFY_ESCALATING_ADMIN=false`. The subject begins with the filterable token
`[ESCALATED`, gaining an `×N` suffix above level one, and the message carries
`X-Ticket-Escalation-Level` and `X-Ticket-Reference` headers. The body names the
reason verbatim, the level, the requester, who escalated it, where the ticket
landed and its current priority, and links to the ticket in the SPA. Escalations
are never delayed or suppressed: two escalations produce two emails.
```

**No frontend changes required.** **No README change** — Story 43's `## Queue and mail` section already covers running the worker and reading captured mail.

---

## Edge Cases & Failure Modes

- **Three active admins produce exactly three `jobs` rows.** Verified in the framework: `NotificationSender::queueNotification()` loops `foreach ($notifiables as $notifiable)` and dispatches a `SendQueuedNotifications` **inside** that loop (`vendor/laravel/framework/src/Illuminate/Notifications/NotificationSender.php:225–291`). Test 12.2 asserts the count, so a future change to a single fan-out job is caught rather than silently altering delivery semantics.
- **A refused escalation sends nothing.** Story 35 throws `ValidationException` from **inside** the transaction closure when the ticket is terminal, so the dispatch after `DB::transaction()` is never reached. Test 12.7.
- **`AdminUserSeeder` creates one admin, and only with `ADMIN_PASSWORD` set** — which `phpunit.xml` never does. **Every test here builds its own admins** with `User::factory()->admin()` (`UserFactory.php:49–52`) and `->inactive()` (**59–62**). Story 35's plan carries the same warning.
- **An inactive admin is not mailed**, and this is the one recipient rule that is easy to get wrong: deactivation is not a delete, so the row survives with `is_active = false`. `User::scopeActive()` is the guard. Test 12.4.
- **No active admin remains at handling time.** Story 35 refuses the escalation with a `422` when there is no target, so reaching this branch means every admin was deactivated in the intervening milliseconds. Logged as a `warning` and skipped — **do not throw**, which would surface on a request that already succeeded.
- **An agent escalates.** `TicketPolicy::escalate()` is a pure role gate returning `true` after Story 35, so this is the common case. The agent receives nothing; every active admin does. Test 12.3.
- **An admin escalates.** They receive their own email — AC1's plain reading. `NOTIFY_ESCALATING_ADMIN=false` is the supported way to change it, and test 12.5 pins both sides.
- **Level 1 must never render `×1`**, in the subject, the headers' text or the body. Story 36 states the rule twice for the badge; test 12.10 enforces it for the mail. **This is the single most likely thing to be got wrong** by an implementer writing `"{$token} ×{$level}"` unconditionally.
- **A reason at its 5000-character ceiling** renders whole inside `<pre>` with `word-break: break-word`. **Do not truncate it** — AC2 says the email carries the reason. Only the *ticket subject* is bounded, and only in the subject header.
- **A reason containing markdown, HTML or a `<script>` tag.** Escaped in the HTML part by `{{ }}`, inert in the text part, never interpreted as markdown because the views bypass that pipeline. Measured for Story 45.
- **Arabic, emoji and RTL text** in the subject, the reason or a name. `utf8mb4` throughout; `Str::limit()` is multibyte-safe. **`substr()` must not appear anywhere in this story** — it would split a character inside the subject header, which is also where the filter token lives.
- **A ticket subject containing `[ESCALATED]`.** Harmless: the real token is the **prefix**, and the ticket's subject is echoed after it. A mail rule matching on prefix cannot be forged.
- **The ticket is soft-deleted between commit and handling** → `whereKey()` applies the `SoftDeletingScope`, the listener logs a `warning` and returns. **Do not add `withTrashed()`.**
- **The escalating user is deleted between commit and handling.** `users` rows are never deleted by any endpoint (`UserPolicy::delete` denies everyone), so this needs a hand-run `DELETE`; the listener logs and returns rather than sending an email whose "escalated by" line is blank.
- **Two escalations seconds apart produce two emails**, one saying `[ESCALATED]` and one `[ESCALATED ×2]`. **This is required, not a burst** — see the decision. A reviewer who reaches for TM-54's supersession here has misread both stories.
- **The mail transport is down when the worker runs.** Each admin's job fails independently into `failed_jobs`, so a transient failure loses one recipient rather than all of them, and `queue:retry` can replay just that one. **The escalation is already committed and stands.**
- **`php artisan config:cache`** freezes both new variables; `config:clear` is the fix, and `composer test` already runs it. **`php artisan event:cache`** freezes the listener map, as Stories 44–46 all record.

---

## Test Plan

All backend, all Feature. **No frontend test changes. No existing test is modified or deleted.**

Both files share this setup — `$this->seed()` with no argument throws, and `AdminUserSeeder` gives only one admin:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
}

private function admins(int $count): Collection   // User::factory()->admin()->count($count)->create()
private function ticketAt(string $slug): Ticket   // Story 31's helper; TM-59 replaces it with TicketFactory
private function escalate(Ticket $ticket, User $actor, string $reason): TestResponse
```

**12. `backend/tests/Feature/Notifications/TicketEscalatedNotificationTest.php`** (new, `RefreshDatabase`) — who is notified, and how many times.

1. `test_escalation_notifies_every_active_admin` — **AC1.** `Notification::fake()`; three admins; an agent escalates; `assertSentTo($admins, TicketEscalatedNotification::class)` and `assertSentTimes(…, 3)`.
2. `test_one_job_is_queued_per_admin_and_nothing_is_sent_during_the_request` — **AC1, the flagship. No fakes.** Three admins; escalate; assert `DB::table('jobs')->count() === 3` **and** the array transport is empty. Then run the worker three times and assert **3** messages, each with a distinct `To`. **Docblock the framework evidence** that the fan-out is per notifiable.
3. `test_the_escalating_agent_is_not_notified` — the actor is an agent; `assertNotSentTo($agent, …)` while the admins are notified.
4. `test_an_inactive_admin_is_not_notified` — two active admins and one inactive; `assertSentTimes(…, 2)` and `assertNotSentTo($inactive, …)`.
5. `test_an_escalating_admin_is_notified_by_default_and_excluded_when_configured` — an admin escalates: they are notified. Then `config()->set('notifications.admin.notify_escalating_admin', false)`, escalate again, and assert they are not while the others still are. **Both halves in one test, because the pair is the decision.**
6. `test_no_active_admin_queues_nothing_and_logs` — `Log::spy()`; dispatch `TicketEscalated` directly with every admin deactivated; `Notification::assertNothingSent()` and a `warning` naming the reference. **Driven through the event, because Story 35 refuses the endpoint with a `422` in this state** — docblock that.
7. `test_a_refused_escalation_dispatches_nothing` — `Event::fake([TicketEscalated::class])`; escalate a Resolved ticket; assert `422` and `Event::assertNotDispatched(TicketEscalated::class)`.
8. `test_the_listener_is_discovered` — `Event::fake()`; `Event::assertListening(TicketEscalated::class, SendTicketEscalatedNotification::class)`.
9. `test_the_event_carries_the_new_level_and_the_reason` — escalate twice; assert the two dispatched events carry levels `1` and `2` and each its own reason. **The guard against reading the level off the ticket at delivery time.**
10. `test_a_deleted_ticket_or_actor_is_skipped` — soft-delete the ticket, dispatch, `assertNothingSent()` plus a logged `warning`.

**13. `backend/tests/Feature/Notifications/TicketEscalatedMailTest.php`** (new, `RefreshDatabase`) — **AC2, AC3, AC4**: what the email says. Every content assertion runs against **both** parts via a helper returning `[$html, $text]`.

1. `test_the_subject_starts_with_the_filter_token_at_level_one` — **AC3.** `assertStringStartsWith('[ESCALATED] ', $subject)`, and the subject also contains the reference and the ticket subject.
2. `test_the_subject_marks_a_repeat_escalation` — **AC3, AC4.** At level 3, `assertStringStartsWith('[ESCALATED ×3] ', $subject)`.
3. `test_the_subject_never_renders_times_one` — **AC4, the likeliest defect.** At level 1, assert the subject does **not** contain `×`. Story 36 states the rule twice; docblock the reference.
4. `test_the_filter_token_is_configurable` — `config()->set('notifications.admin.escalation_subject_token', 'URGENT-ESC')`; the subject starts `[URGENT-ESC] `.
5. `test_it_sets_the_escalation_headers` — **AC3, AC4.** `X-Ticket-Escalation-Level` equals the level as a string and `X-Ticket-Reference` equals the reference, read off the Symfony message.
6. `test_it_carries_the_reason_level_requester_and_escalator` — **AC2.** Both parts contain the reason verbatim, the level, the requester's name and email, and the escalating user's name.
7. `test_a_repeat_escalation_says_so_in_the_body` — **AC4.** At level 2 both parts contain the "escalated 2 times" line; at level 1 neither does.
8. `test_markdown_and_line_breaks_in_the_reason_survive` — a reason with `# heading`, `**bold**`, `[ref](http://evil.test)` and a blank line: literals present in both parts; the HTML part has no `<strong>`, no `<h1>` around that text and no `href="http://evil.test"`. **The HTML part's only anchor is the ticket link** — assert exactly one `<a href` and that it is `$ticketUrl`.
9. `test_html_in_the_reason_is_escaped_in_html_and_raw_in_text` — `<script>` escaped in the HTML part; `<b>` unescaped in the text part.
10. `test_it_links_to_the_spa_ticket_page_by_numeric_id` — `config()->set('app.frontend_url', 'https://helpdesk.test')`; both parts contain `https://helpdesk.test/tickets/{id}` and **not** the reference inside the URL.
11. `test_it_names_the_assignee_or_says_nobody` — with an assignee, the name; with `assigned_to` nulled, the literal `nobody`.
12. `test_both_parts_are_present_and_non_empty` — TM-56 inherits this.
13. `test_arabic_and_emoji_survive_the_subject_and_the_reason` — a multibyte ticket subject longer than 60 characters and a multibyte reason. **The regression guard against `substr()`.**

---

## Verification Steps

1. **Services up:** from the repo root, `docker compose up -d --wait`; all three containers **healthy**.
2. **Gates present:** from `backend/`, `php artisan route:list --name=tickets.escalate` lists the route, `grep -n "frontend_url" config/app.php` matches, and `grep -n QUEUE_CONNECTION phpunit.xml` shows `database`. **If any is missing, stop and read the Prerequisites.**
3. **Backend tests:** `composer test`. Expect the three pre-existing failures and **nothing else**.
4. **This story alone:** `php artisan test --filter='TicketEscalatedNotificationTest|TicketEscalatedMailTest'`.
5. **Formatting:** `./vendor/bin/pint --test` exits `0`.
6. **The fan-out is real, by hand.** Create three admins, escalate a ticket over HTTP, then:
   ```bash
   cd backend
   php artisan tinker --execute="echo DB::table('jobs')->count();"   # 3
   ```
   Mailpit is **empty**. Then `php artisan queue:work --stop-when-empty` and **three** messages appear, one per admin.
7. **AC3 by hand, in a mail client's terms.** In Mailpit, the subject of each message starts with `[ESCALATED] `. Escalate the same ticket again and the new messages start `[ESCALATED ×2] `. **Searching Mailpit for `[ESCALATED` returns both sets** — that is the criterion.
8. **AC4 by hand.** Compare the two bodies: only the second carries the "escalated 2 times" line, and only the second's `X-Ticket-Escalation-Level` header reads `2`. Confirm the first subject contains **no** `×`.
9. **The `×1` guard guards.** Change `subjectToken()` to append the suffix unconditionally and run `php artisan test --filter=test_the_subject_never_renders_times_one`. **It must fail.** Restore it.
10. **The inactive-admin rule guards.** Drop `->active()` from the listener's query and run `php artisan test --filter=test_an_inactive_admin_is_not_notified`. **It must fail.** Restore it.
11. **The deep link works.** Click "Open the ticket" in Mailpit; it must open `http://localhost:5173/tickets/<numeric id>` and the SPA must load that ticket.
12. **Regression:** `git diff --stat` touches only `backend/config/notifications.php`, `backend/.env.example`, `backend/app/Events/TicketEscalated.php`, `backend/app/Http/Controllers/Api/V1/TicketController.php` (**one dispatch plus one import**), `backend/app/Notifications/TicketEscalatedNotification.php`, `backend/app/Listeners/SendTicketEscalatedNotification.php`, the two Blade views, `docs/api-contract.md` and the two test files. **No migration, no route file, no `TicketPolicy`, no `TicketResource`, no seeder, no `frontend/` file.**

---

## Done Criteria

- [ ] Escalation queues **one email per active admin** — three admins, three `jobs` rows — and nothing reaches the mail transport during the request. *(AC1)*
- [ ] Inactive admins are never notified; an escalating **agent** is not notified; an escalating **admin** is, unless `NOTIFY_ESCALATING_ADMIN=false`. *(AC1)*
- [ ] Both parts carry the reason verbatim, the escalation level, the requester's name and email, who escalated it, where the ticket landed and its current priority. *(AC2)*
- [ ] Every subject **starts** with `[ESCALATED`, the token is configurable, and a ticket subject cannot forge it. *(AC3)*
- [ ] `X-Ticket-Escalation-Level` and `X-Ticket-Reference` headers are set on every message. *(AC3)*
- [ ] Level 2 and above is distinguished three ways — the `×N` subject suffix, an extra body line and the numeric header — and **level 1 never renders `×1`**, matching TM-42's badge rule. *(AC4)*
- [ ] The reason renders literally: markdown uninterpreted, line breaks intact, HTML escaped in the HTML part and raw in the text part; the HTML part's only anchor is the ticket link.
- [ ] `App\Events\TicketEscalated` carries the **new** level and this escalation's reason, has no marker interfaces, and is dispatched **after** the transaction commits; a refused escalation dispatches nothing.
- [ ] `App\Listeners\SendTicketEscalatedNotification` is auto-discovered with **no `Event::listen()` registration**, and a test asserts the binding.
- [ ] **No delay and no supersession** — two escalations produce two emails, deliberately unlike TM-54.
- [ ] No `tries`, `backoff`, `timeout`, shared layout, migration, route or frontend file — TM-56 and TM-57 still own all of it.
- [ ] `composer test` shows the same three pre-existing failures and no new ones; `./vendor/bin/pint --test` exits `0`.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 48.**
