# Story 45 — Confirm ticket creation to the requester (Story: TM-53)

## Prerequisites

- **Story 43 (TM-51) — hard gate.** [`43-story-queue-and-mail-infrastructure-TM-51.md`](43-story-queue-and-mail-infrastructure-TM-51.md). AC1 says the confirmation is **queued**; under `phpunit.xml:45`'s current `QUEUE_CONNECTION=sync` a queued notification runs inline and the test proving AC1 would pass against code that sends during the request. Test 9.2 is written directly against Story 43's flip to `database`. Story 43 also supplies `App\Services\MailSafety`, which every test here sends through — `phpunit.xml:44` sets `MAIL_MAILER=array`, a safe transport, so nothing trips it.
- **Story 44 (TM-52) is NOT a dependency, in either direction.** It notifies an **agent** and builds a deep link from `config('app.frontend_url')`; this story notifies a **requester** and deliberately builds no link at all (see the decision). They share no file except `docs/api-contract.md`. **Ship them in any order.** What Story 44 does supply is the shape — domain event → auto-discovered listener → queued notification — and this story repeats it deliberately rather than inventing a second pattern.
- **Story 18 (TM-22) is IMPLEMENTED, and this story edits its controller.** `backend/app/Http/Controllers/Api/V1/TicketController.php` has `store()` at **30–50**, with `DB::transaction(…)` closing at **47** and the `201` return at **49**. Its plan lists *"the ticket-created email (**TM-53**)"* as explicitly out of its scope, so **no other story owns the dispatch point** and task 3 is unambiguously this story's.
- **There are no feature tests for `POST /api/v1/tickets` at all.** `backend/tests/Feature/` has no `Tickets` directory; Story 18 shipped the endpoint and deferred *"backfilling the missing tests listed above"*. **This story's tests are the first to exercise that route.** Keep them scoped to the confirmation email — **do not** backfill TM-22's validation coverage here.
- **`requesters.email` is `NOT NULL UNIQUE`** (`2026_08_26_084623_create_requesters_table.php:17`) **and `requester.email` is `required|email`** (`StoreTicketRequest.php:30`). TM-21's plan states the reason in one sentence: *"`email` is `NOT NULL` and unique because **TM-22** matches an existing requester by email and **TM-53** emails them a confirmation."* **AC4's case therefore cannot be produced through the API.** It is still implemented and still tested — see the decision, which turns on the fact that `NOT NULL` does not forbid `''`.
- **`POST /api/v1/tickets` sits inside the `auth:sanctum` group** (`routes/api.php:32, 44`). **There is no public intake form and none is planned.** Every ticket is created by staff on behalf of a contact record, which is why "as submitted" means "as submitted to the system" and why the email carries no link.
- **No new composer or npm dependency, no migration, no route, no policy change, no frontend file.** `tickets.requester_id`, `category_id`, `priority_id` and `status_id` are all `NOT NULL` with `restrictOnDelete` (`2026_08_26_084625_create_tickets_table.php:18–21`), and `Requester` has **no** `SoftDeletes` (`Requester.php`), so `$ticket->requester` can never resolve to `null`.
- **Baseline, 2026-08-27:** `composer test` → **101 tests, 98 passing, 3 failing** (`PasswordThrottleTest`, `RouteAuthorizationTest`, `TicketReferenceTest` — all pre-existing); `./vendor/bin/pint --test` exits `0`.
- **A backlog labelling error to know about before you read the neighbouring plans.** [`../status-workflow-escalation/35-story-escalate-a-ticket-TM-41.md`](../status-workflow-escalation/35-story-escalate-a-ticket-TM-41.md) (**26**, **485**, **626**) and [`../status-workflow-escalation/36-story-escalated-tickets-are-visible-at-a-glance-TM-42.md`](../status-workflow-escalation/36-story-escalated-tickets-are-visible-at-a-glance-TM-42.md) (**47**) defer escalation email to "**TM-53**". **That is wrong: escalation mail is TM-55 (E8-S5, *"Notify admins on escalation"*).** TM-53 is this story. **Do not add any escalation notification here**, and do not "reconcile" those plans — record the mislabel in the PR so TM-55 is not skipped later.

---

## Story Goal

A requester gets one plain, queued email that proves their request was logged and gives them the string to quote.

1. `App\Events\TicketCreated` — dispatched once from `TicketController::store()`, **after** the transaction commits.
2. `App\Listeners\SendTicketCreatedConfirmation` — auto-discovered, synchronous, and the one place AC4's missing-address rule is enforced.
3. `App\Notifications\TicketCreatedNotification implements ShouldQueue` — one `SendQueuedNotifications` job per ticket; the request touches no mail transport.
4. **The project's first `resources/views/mail/` templates**, one HTML and one text, because AC2's *"echoes the subject and description as submitted"* cannot survive Laravel's markdown pipeline — measured below.
5. `Notifiable` on `Requester`, so the notification addresses a contact record without an on-demand route.

**Not in scope, and each belongs to a named story.** **No `tries`, `backoff`, `timeout` or `retryUntil`** — TM-57 (E8-S7) owns the retry policy, as Stories 43 and 44 both recorded. **No shared mail layout, no header/footer partial, no ticket-summary component** — TM-56 (E8-S6) extracts those from the views this story and TM-54/TM-55 create; **creating them is what gives TM-56 something to extract, and unifying them is not this story's job.** **No status-change or resolution email** — TM-54. **No escalation email** — TM-55, *not* TM-53, whatever Stories 35 and 36 say. **No agent-facing mail** — TM-52. **No deep link, no "view your ticket" button, no requester portal, no public intake form, no magic link.** **No change to `StoreTicketRequest`, `routes/api.php`, `TicketPolicy`, `TicketResource` or `RouteAuthorizationTest`.** **No requester login capability of any kind** — TM-21's first criterion, and adding `Notifiable` does not breach it.

---

## Decision — a Blade view pair, not `MailMessage->line()`

Story 44 returns a plain `MailMessage` and says so proudly. **This story must not**, and the reason was measured rather than reasoned:

A description of `"First line with **stars** and # hash\n[click me](http://evil.test) <b>html</b>\n\n- item three"` pushed through `->line()` produced, in the rendered mail:

| Behaviour | Through `->line()` | Through a Blade view pair |
|---|---|---|
| `[click me](http://evil.test)` | became a **live `href="http://evil.test"`** | literal text |
| `# hash` | consumed as markdown | literal text |
| `**stars**` | consumed as markdown | literal `**stars**` |
| `<b>html</b>` | escaped to `&lt;b&gt;` | escaped to `&lt;b&gt;` |
| The requester's **newlines** | **collapsed into one paragraph** | preserved |

**The collapsed newlines alone disqualify `->line()`.** A support description is the one field in this system where line breaks carry meaning — steps to reproduce, an error dump, an address — and AC2 says *as submitted*. Turning a requester's own `[text](url)` into a clickable link in the mail they receive is the second reason.

Measured with a two-view `MailMessage`: **`html_len=246`, `text_len=170`** — both parts still produced, `**stars**` and `# hash` intact, **no** `<h1>`, **no** `<strong>`, **no** `href`, and `hash\n[click` present in the HTML, i.e. the newline survived inside `<pre>`. `MailChannel::buildView()` (`vendor/laravel/framework/src/Illuminate/Notifications/Channels/MailChannel.php:94–98`) returns `$message->view` verbatim when one is set, which is why the markdown pipeline is bypassed entirely.

## Decision — `{{ }}` in the HTML view, `{!! !!}` in the text view

This looks backwards and is not. **Measured:** the text part of the two-view probe rendered `&lt;b&gt;html&lt;/b&gt;` — Blade's `{{ }}` HTML-escapes in a **plain-text** template too, where there is no markup to escape and the entity is simply wrong output.

- **HTML view: `{{ $description }}` inside `<pre style="white-space: pre-wrap">`.** Escaping is mandatory (it is what turned `<script>` into `&lt;script&gt;`), and `<pre>` is what preserves the newlines.
- **Text view: `{!! $description !!}`.** A text part has no markup, so escaping can only corrupt. **This is safe precisely because the output is never parsed as HTML** — do not "harden" it back to `{{ }}`.
- **Verified raw text output** of the probe: `"Hello Dana,\n\nReference: R\nSubject: S\n\na\nb\n"` — blank lines and single newlines both survive, so the text view's own layout can be written with ordinary blank lines.

## Decision — the email carries no link, and that is not an omission

- **A requester has no login.** TM-21's first criterion is *"no login capability"*: no `password`, no `role`, no `is_active`, no guard entry. The SPA's router guards every non-public route (`frontend/src/router/guards.ts`), so `/tickets/{id}` would put a requester on a login page they can never pass.
- **`POST /api/v1/tickets` is authenticated** (`routes/api.php:32`), so no requester ever reached a page in the first place.
- **The reference is the affordance.** AC2 says the email *leads with the reference*; the "what to do next" instruction is to quote it in a reply, not to click anything.
- **Consequence recorded rather than hidden:** a requester cannot check their own ticket. A portal or a magic link is a product decision with its own story; **do not smuggle one in as a URL in this email.**

## Decision — the email says nothing the requester did not tell us

AC5 is *"No internal notes or agent-only information appear in the email"*, and the safest reading is the narrow one: the confirmation echoes **reference, subject, description** and nothing else about the ticket.

- **No priority and no status.** Neither is the requester's; `priority_id` defaults to `Medium` from `PrioritySeeder` when the client omits it, and status names (`New`, `Pending`, `Reopened`) are internal workflow vocabulary. Telling a requester their ticket is "Medium" is an invitation to argue with a triage decision they were not part of.
- **No category**, for the same reason — it is a staff taxonomy, chosen by whoever filed the ticket, not a fact about the request.
- **No assignee, no creator, no agent name, no agent email address, no `escalation_reason`, no activity or `meta`.** TM-56's fifth criterion says the same thing product-wide; asserting it from the first requester email is cheaper than retrofitting it to four.
- **`ticket_activities` is never read by this story.** TM-47 (internal notes) ships a tripwire test asserting that adding a note sends and queues nothing, and its plan says *"excluding notes from a requester mailable stays E8-S3's criterion"*. **This story discharges that criterion by never loading the relation** — test 10.6 pins it.

## Decision — AC4's guard is real code for a case the API cannot produce, and it keys on `blank()`

`requesters.email` is `NOT NULL` and `StoreTicketRequest` requires a valid address, so no request can create a ticket whose requester has no email.

- **`NOT NULL` does not forbid the empty string.** MySQL accepts `''` in a `NOT NULL VARCHAR`, and `Requester::firstOrCreate`, a future importer, a seeder, or a `db:seed` fixture can all reach that state without going near `StoreTicketRequest`. The guard is `blank($requester->email)`, which catches `''`, `'  '` and `null` alike.
- **It is enforced in the listener**, the one place every producer passes through — the same argument Story 44 made for its self-assign guard, and for the same reason: a guard at the consumer survives a producer that forgets.
- **It is testable today** by creating a `Requester` with `email: ''` through the model and dispatching the event, which is exactly what test 9.5 does. **Do not make the field nullable to make the test easier** — TM-21 chose `NOT NULL` deliberately and TM-22 matches requesters on that column.
- **"Logs the skip" is `Log::warning`, not `info`.** A ticket whose requester cannot be reached is a data defect someone should fix, unlike Story 44's races, which are `info`.

## Decision — the event carries the ticket id and nothing else

`TicketCreated(int $ticketId)`. No actor, no requester id, no reference.

- **Story 29 set the rule for `app/Events/`:** scalar ids, no models, and **no marker interfaces** — no `ShouldQueue`, no `ShouldBroadcast`, no `SerializesModels`. This story follows it exactly.
- **`TicketAssigned` carries an `actorId` because its self-assign guard needs one.** Nothing in this story compares the creator to anybody, and AC5 forbids naming them in the email, so carrying an actor would add a field with no consumer and one way to leak.

---

## Context — Read These Files First

1. `backend/app/Http/Controllers/Api/V1/TicketController.php` — **`store()` at 30–50.** Read the `DB::transaction(…)` closure (**34–47**), note that `$ticket` is its return value, and that `$recorder->record(…, TicketActivityEvent::Created, …)` at **44** happens **inside** it. **Task 3 adds one line between line 47 and the `return` at 49**, and touches nothing else in the file.
2. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php` — **`rules()` at 25–41**, especially `requester.email` (**30**) and `description` `max:16000` (**34**). **Do not edit this file.** The 16,000-character ceiling is the bound on what the email's `<pre>` block has to render.
3. `backend/database/migrations/2026_08_26_084623_create_requesters_table.php:17` — `$table->string('email')->unique();`, **not nullable**. The whole of the AC4 decision rests on this line.
4. `backend/app/Models/Requester.php` — **7 lines of body**: `#[Fillable(['name','email','phone','company'])]` and a `tickets()` `HasMany`. Task 1 adds one trait and one import. **No `SoftDeletes`, no casts, no policy** — leave it that way.
5. [`../ticket-creation-tracking/17-story-tickets-and-requesters-schema-TM-21.md`](../ticket-creation-tracking/17-story-tickets-and-requesters-schema-TM-21.md) — **the paragraph beginning *"Criterion 1 says 'no login capability'"***. It enumerates exactly what `Requester` must not have. Read it before task 1 so you can see that `Notifiable` is not on the list.
6. `backend/tests/Feature/Database/RequestersTableSchemaTest.php:20–25` — `test_it_has_no_login_columns` loops `['password','role','is_active','remember_token']`. `Notifiable` adds **no columns**, so this test stays green; check it does.
7. [`44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md`](44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md) — the listener and notification shape this story repeats, its decision on queueing the notification but not the listener, and its evidence that `app/Listeners` is auto-discovered (`Application.php:248–252`, `EventServiceProvider.php:41` and **166–171**). **Do not re-derive any of it.**
8. [`../ticket-history-audit-trail/40-story-internal-notes-on-a-ticket-TM-47.md`](../ticket-history-audit-trail/40-story-internal-notes-on-a-ticket-TM-47.md) — **line 122** and **its test 8**. TM-47 ships a tripwire asserting its note endpoint sends and queues nothing and says outright that excluding notes from a requester mailable is **this story's** criterion. Test 10.6 is where that debt is paid.
9. `backend/resources/views/` — currently **`welcome.blade.php` only**. Task 5 creates `mail/tickets/`, the first mail templates in the project.
10. `backend/database/seeders/DatabaseSeeder.php:17–22` — **`$this->seed()` with no argument throws**, because `AdminUserSeeder` (**17–19**) aborts on the empty `ADMIN_PASSWORD` that `phpunit.xml` never sets. Every test here seeds `CategorySeeder`, `PrioritySeeder` and `StatusSeeder` explicitly.
11. `backend/database/seeders/PrioritySeeder.php:11–15` and `CategorySeeder.php:10–16` — the names (`Medium` is the default priority; `Hardware`, `Software`, …) that test 10.5 asserts are **absent** from the email.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `POST /api/v1/tickets` succeeds | `201`, ticket + one `created` activity row | Plus one `SendQueuedNotifications` job addressed to the requester |
| During the request | — | **Zero messages reach the mail transport** — AC1 |
| The email's subject | — | Leads with `[<reference>]` — AC2 |
| The email's body | — | Reference, subject, description **verbatim**, and what happens next — AC2 |
| A description with `**bold**`, `# heading` or `[a](b)` | — | Rendered **literally**; no markdown is interpreted — AC2 |
| A description with `<script>` | — | Escaped in the HTML part; harmless in the text part |
| A description with blank lines and indentation | — | Preserved in both parts |
| Response-time expectation | — | Stated as "we will review and reply"; **no duration, no SLA, no guarantee** — AC3 |
| Requester email is `''` or whitespace | Unreachable via the API | **Nothing queued**, one `Log::warning` — AC4 |
| Ticket soft-deleted between commit and handling | — | Skipped, one `Log::warning` |
| The ticket has internal notes, an assignee, an escalation reason | — | **None of it appears in the email** — AC5 |
| The ticket's priority, status, category | — | **Deliberately absent** — AC5 |
| `POST /tickets` rolls back | No ticket | **No event, therefore no email** |
| Mail transport is down when the worker runs | — | Job lands in `failed_jobs`; **the ticket is already committed and stands** |

---

## Backend Tasks

### 1 — Let a contact record be notified

**File: `backend/app/Models/Requester.php`**

Add the trait and its import:

```php
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'phone', 'company'])]
class Requester extends Model
{
    use Notifiable;
    …
}
```

- **This is not a login capability and does not breach TM-21's first criterion.** `Notifiable` adds **no column, no guard entry and no `Authenticatable` contract**; it supplies `routeNotificationForMail()`, which returns `$this->email`. `RequestersTableSchemaTest::test_it_has_no_login_columns` stays green — confirm it does.
- **`via()` returns `['mail']` only** (task 4), so the `database` channel is never used and **no `notifications` table is needed**. Do not create one, and do not run `php artisan notifications:table`.
- **The alternative was `Notification::route('mail', $email)->notify(…)`.** It was rejected because on-demand notifications are asserted with `assertSentOnDemand()`, which makes AC1's *"to the requester's address"* an assertion about an anonymous routing bag rather than about the requester — and this is a persisted record with a stable identity, which is exactly what `Notifiable` is for.

### 2 — The domain event

**Create file: `backend/app/Events/TicketCreated.php`**

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket was logged. Dispatched once per successful creation, after the
 * transaction commits, so a confirmation can never quote a ticket that rolled
 * back — TM-57's fourth criterion, and the rule TM-34 set for TicketAssigned.
 *
 * The ticket id and nothing else: no actor, because nothing compares the
 * creator to anyone and TM-53's fifth criterion forbids naming them in the
 * email. Scalar id, no marker interfaces — the rule TM-34 set for this
 * directory. TM-53 attaches the queued confirmation.
 */
class TicketCreated
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
    ) {}
}
```

**If `backend/app/Events/` does not exist yet**, this story creates it; if Story 29 has already created it for `TicketAssigned`, add this file alongside and change nothing else in the directory.

### 3 — Dispatch it

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add the import alongside the existing `App\Enums\TicketActivityEvent`:

```php
use App\Events\TicketCreated;
```

Insert between the closing `});` of the transaction (**line 47**) and the `return` (**line 49**):

```php
        // AC1, and the only new line in this controller. Outside the
        // transaction on purpose: TM-57 requires notifications to fire after
        // commit, and a confirmation quoting a rolled-back reference is worse
        // than no confirmation. A failed POST dispatches nothing because the
        // exception propagates before this line.
        TicketCreated::dispatch($ticket->getKey());
```

**Nothing else in this file changes** — not the transaction body, not the `->load([...])` tail, not the `201`.

### 4 — The notification

**Create file: `backend/app/Notifications/TicketCreatedNotification.php`**

```php
<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * "We have logged your request." Sent to the requester's address and nowhere
 * else. Carries the reference, the subject and the description as submitted,
 * and deliberately nothing about priority, status, category, assignment or
 * history — TM-53's fifth criterion, and TM-47's deferred one.
 *
 * A view pair rather than MailMessage->line(): the markdown pipeline collapses
 * the description's newlines and turns a requester's own [text](url) into a
 * live link, which AC2's "as submitted" forbids. Measured while planning.
 *
 * tries, backoff and timeout are absent — TM-57 owns the retry policy.
 * TM-56 replaces these two views with the shared layout.
 */
class TicketCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
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
            ->subject("[{$ticket->reference}] We have logged your request")
            // The two-element list form, measured: MailChannel::buildView()
            // returns this verbatim, so no markdown renderer is involved.
            ->view(
                ['mail.tickets.created', 'mail.tickets.created-text'],
                [
                    'requesterName' => $notifiable->name,
                    'reference' => $ticket->reference,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                ],
            );
    }
}
```

- **The subject leads with the reference** — AC2's first clause, in the one place a mail client shows without opening anything. **No `Str::limit` on the ticket subject here**: `tickets.subject` is `varchar(255)` and the reference is already first, so truncation would only risk splitting a multibyte character for no gain.
- **Exactly four view variables.** The `Ticket` model is *not* passed into the views — a template with a model in scope is one `{{ $ticket->assignee?->name }}` away from breaking AC5. **Do not pass `$ticket`.**
- **`readonly` promoted properties are safe under `SerializesModels`**, measured for Story 44 on **PHP 8.3.6**: `ReflectionProperty::setValue()` accepts exactly one write on a constructor-less object, and `hasDefaultValue()` is `false` for promoted properties.

### 5 — The two views

**Create file: `backend/resources/views/mail/tickets/created.blade.php`**

```blade
{{--
    The requester's confirmation, HTML part. TM-56 extracts the header, footer
    and any shared block from this file and its siblings; until then it is
    deliberately plain.

    {{ }} everywhere: the description is user-submitted text and must be
    escaped. <pre> is what preserves its newlines — measured, the markdown
    pipeline collapses them, which is why this file exists at all.

    Nothing here may reference priority, status, category, the assignee, the
    creator or ticket_activities. TM-53 AC5, and TM-47's deferred criterion.
--}}
<p>Hello {{ $requesterName }},</p>

<p>We have logged your request. Please quote this reference in any reply:</p>

<p><strong>{{ $reference }}</strong></p>

<p>Subject: {{ $subject }}</p>

<p>What you told us:</p>

<pre style="white-space: pre-wrap; word-break: break-word; font-family: inherit; margin: 0 0 16px;">{{ $description }}</pre>

<p>A member of our team will review your request and reply to this email address. Quote the reference above in any follow-up so we can find your request straight away.</p>

<p>&mdash; {{ config('app.name') }}</p>
```

**Create file: `backend/resources/views/mail/tickets/created-text.blade.php`**

```blade
Hello {{ $requesterName }},

We have logged your request. Please quote this reference in any reply:

{{ $reference }}

Subject: {{ $subject }}

What you told us:

{!! $description !!}

A member of our team will review your request and reply to this email address.
Quote the reference above in any follow-up so we can find your request straight
away.

-- {{ config('app.name') }}
```

Four things that are load-bearing:

- **`{!! !!}` on the description in the text view only.** Measured: `{{ }}` there emitted `&lt;b&gt;html&lt;/b&gt;` into a plain-text body, where the entity is simply wrong. It is safe because a text part is never parsed as HTML. **The HTML view keeps `{{ }}` and must never be changed to `{!! !!}`** — that is the line between escaping and an injection.
- **`white-space: pre-wrap` plus `word-break: break-word`**, not a bare `<pre>`. A pasted error dump can exceed any mail client's width, and a non-wrapping `<pre>` produces a horizontally scrolling email.
- **`font-family: inherit`** so the description does not arrive in a monospace face that reads as a code block. It is prose that happens to need its line breaks.
- **Not one sentence promises a response time.** AC3. The phrases `SLA`, `guarantee`, `within 24`, `within 48`, `business day`, `business hours`, `response time` and `as soon as possible` appear in **neither** file, and test 10.4 keeps it that way.

### 6 — The listener

**Create file: `backend/app/Listeners/SendTicketCreatedConfirmation.php`**

```php
<?php

namespace App\Listeners;

use App\Events\TicketCreated;
use App\Models\Ticket;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns TicketCreated into the requester's confirmation.
 *
 * Not queued: the notification it sends is, so one creation produces exactly
 * one job rather than a job that queues a job. The single read below is a
 * primary-key lookup in a request whose transaction has already committed.
 */
class SendTicketCreatedConfirmation
{
    public function handle(TicketCreated $event): void
    {
        // whereKey() honours SoftDeletes, so a ticket deleted between the
        // commit and this line resolves to null and nothing is queued.
        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with('requester')
            ->first();

        if ($ticket === null) {
            Log::warning('Ticket confirmation skipped: ticket no longer exists.', [
                'ticket_id' => $event->ticketId,
            ]);

            return;
        }

        // AC4 — "A ticket created without a requester email queues nothing and
        // logs the skip." requesters.email is NOT NULL and StoreTicketRequest
        // requires a valid address, so the API cannot reach this. NOT NULL does
        // not forbid '', which an import or a seeder can write, so blank()
        // rather than is_null().
        if (blank($ticket->requester->email)) {
            Log::warning('Ticket confirmation skipped: requester has no email address.', [
                'ticket_id' => $ticket->getKey(),
                'reference' => $ticket->reference,
                'requester_id' => $ticket->requester->getKey(),
            ]);

            return;
        }

        $ticket->requester->notify(new TicketCreatedNotification($ticket));
    }
}
```

- **No registration.** `app/Listeners` is auto-discovered — evidence in Story 44's task 3, verified there against `Application.php:248–252` and `EventServiceProvider.php:41, 166–171`. **Do not add an `Event::listen()` call.** Test 9.7 pins the binding.
- **`Log::warning`, not `info`.** Both branches describe a data defect, not a race: a ticket that vanished within milliseconds of being created, or a requester row that should never have existed. Story 44's skips are races and log at `info`; the difference is deliberate.
- **The log line carries the reference**, which is the string a human can act on. **It must not carry the requester's email address** — there is none, and logging the whole model would put contact details in a log file for no reason.
- **The `requester` relation is eager-loaded and `category`, `priority`, `status`, `assignee` and `activities` are not.** That is AC5 enforced by absence: the notification physically cannot reach what was never loaded, and `Ticket` has no `activities()` relation yet in any case.

### 7 — Document the email

**File: `docs/api-contract.md`**

Under the `Notifications` section — the one Story 29 adds for `TicketAssigned` and Story 44 amends — append a second paragraph. **If no such section exists yet, create it under `## Endpoints`.**

```markdown
`POST /api/v1/tickets` dispatches `App\Events\TicketCreated` after its
transaction commits. `App\Listeners\SendTicketCreatedConfirmation` turns it into
a queued `App\Notifications\TicketCreatedNotification` addressed to the
requester alone. The email leads with the reference and echoes the subject and
description exactly as submitted; it carries no priority, status, category,
assignee, internal note or link, and it makes no promise about a response time.
A requester whose address is blank is skipped with a logged warning and nothing
is queued. Nothing is sent during the request.
```

**No frontend changes required.** Nothing in `frontend/` renders or triggers this email; the new-ticket form's behaviour is unchanged. **No README change** — Story 43's `## Queue and mail` section already covers running the worker and reading captured mail.

---

## Edge Cases & Failure Modes

- **A failed `POST /tickets` sends nothing.** The dispatch sits after `DB::transaction()` returns, so an exception inside the closure propagates before it. Test 9.6 forces a failure and asserts both no ticket and no event.
- **A description at the `max:16000` ceiling** (`StoreTicketRequest.php:34`) renders whole in both parts. `<pre>` with `word-break: break-word` is what stops a single 16,000-character line producing a horizontally scrolling email. **Do not truncate the description** — AC2 says *as submitted*.
- **A description containing markdown.** `**bold**`, `# heading`, `[text](url)`, `- list` and backticks all render **literally** in both parts. This is the whole reason the views exist; test 10.2 is the regression guard, and it fails the moment someone "simplifies" `toMail()` back to `->line()`.
- **A description containing HTML or a `<script>` tag.** Escaped by `{{ }}` in the HTML part — measured, `<script>` became `&lt;script&gt;`. The text part passes it through unescaped by design, where it is inert.
- **Arabic, emoji and RTL text.** Both MySQL containers run `utf8mb4`, Blade escapes UTF-8 without transcoding, and no `substr()` appears anywhere in this story. **`substr()` must not be introduced** — it would split a multibyte character in the subject header.
- **A blank requester email** → nothing queued, one `Log::warning`. Reachable only by writing `''` directly, which is exactly how test 9.5 produces it.
- **The ticket is soft-deleted between commit and handling** → `whereKey()` applies the `SoftDeletingScope`, resolves to `null`, skipped with a `Log::warning`. **Do not add `withTrashed()`** — a confirmation for a deleted ticket is worse than none.
- **The ticket changes between dispatch and delivery.** `SerializesModels` stores the id and re-fetches in the worker, so a subject corrected in the intervening seconds is reflected. AC2's *as submitted* is about the markdown and whitespace pipeline, not about freezing a snapshot; a corrected subject is the better email.
- **Two tickets created back to back by the same requester** produce two independent jobs and two emails. There is **no digest and no throttle** — TM-54's fifth criterion introduces one for *status changes*; nothing in the backlog asks for one on creation, and suppressing a confirmation would defeat its purpose as proof of logging.
- **The requester already existed** (`Requester::firstOrCreate` matched on email, `TicketController.php:36`). They are notified for **every** ticket, which is correct: the confirmation is per request, not per contact.
- **The mail transport is down when the worker runs.** The job fails into `failed_jobs` with its exception (Story 43 measured that path). **The ticket is already committed and stands** — TM-57's third criterion, which holds here only because the dispatch is outside the transaction. **Do not move `notify()` into a transaction.**
- **`php artisan view:cache` freezes the two Blade templates.** A cached view compiled before these files existed is not a problem — `view:cache` compiles what it finds — but editing a template after caching requires `php artisan view:clear`. **CI runs neither**, and `composer test` runs `config:clear`, so the suite is immune; this is a local-development note.
- **`php artisan event:cache` freezes the listener map**, exactly as Story 44 recorded. `php artisan event:clear` is the fix.
- **`Notification::fake()` cannot prove queueing.** It intercepts before the queue, so `assertSentTo` passes identically for a queued and a synchronous notification. **AC1's "queues" therefore has to be tested without a fake** — test 9.2 counts `jobs` rows and transport messages. Do not simplify it into a fake.
- **Driving `queue:work --once` inside PHPUnit** is the one mechanism Stories 43 and 44 both flagged as unproven in-process. Same fallback: if the post-worker assertion does not see the message, assert instead that `jobs` is empty and `failed_jobs` is empty after the worker ran, and record it in the PR. **Do not weaken the pre-worker half**, which is what carries AC1.

---

## Test Plan

All backend, all Feature. **No frontend test changes. No existing test is modified or deleted.**

Both files share this setup — there is still **no `TicketFactory`** (`database/factories/` holds only `UserFactory.php`; **TM-59 owns it, and if it has landed, use it and delete the helper**):

```php
protected function setUp(): void
{
    parent::setUp();
    $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
}

private function createTicketViaApi(array $overrides = []): TestResponse
// Sanctum-acting as an agent, POST route('tickets.store') with a valid
// requester block, subject, description and the seeded Hardware category.

private function ticketWithRequester(string $email, string $description): Ticket
// Model::create against the seeded master data for the cases the API cannot
// produce; reference via TicketReferenceGenerator inside a transaction.
```

**9. `backend/tests/Feature/Notifications/TicketCreatedNotificationTest.php`** (new, `RefreshDatabase`) — who is notified, and how.

1. `test_creating_a_ticket_notifies_the_requester` — **AC1.** `Notification::fake()`; `POST /api/v1/tickets`; `assertSentTo($requester, TicketCreatedNotification::class)` and `assertSentTimes(TicketCreatedNotification::class, 1)`. **Also assert the acting agent receives nothing** — `assertNotSentTo($agent, TicketCreatedNotification::class)`.
2. `test_the_confirmation_is_queued_rather_than_sent_synchronously` — **AC1, the flagship. No fakes.** `POST`, then assert `DB::table('jobs')->count() === 1` **and** `app('mailer')->getSymfonyTransport()->messages()` is **empty**. Then `$this->artisan('queue:work', ['--once' => true])` and assert the transport holds **1** message and `failed_jobs` is empty. **This test fails under `QUEUE_CONNECTION=sync`** — say so in its docblock, next to the reference to Story 43.
3. `test_the_message_is_addressed_to_the_requester_and_nobody_else` — after the worker runs, assert the sent message's `To` is exactly the requester's address, and that the agent's address appears in no header (`To`, `Cc`, `Bcc`, `Reply-To`).
4. `test_an_existing_requester_is_notified_for_each_ticket` — `POST` twice with the same requester email; one `Requester` row, **two** notifications.
5. `test_a_requester_with_a_blank_email_queues_nothing_and_logs` — **AC4.** `Log::spy()`; build the ticket with `ticketWithRequester('', …)`; `TicketCreated::dispatch($ticket->getKey())`; `Notification::assertNothingSent()` and assert a `warning` was logged carrying the reference. **Docblock must state that the API cannot produce this state and why the guard exists anyway.**
6. `test_a_failed_creation_dispatches_nothing` — `Event::fake([TicketCreated::class])`; force the failure the way Story 18's atomicity test does (a throwing `ActivityRecorder` binding); assert the exception surfaces, no ticket row exists, and `Event::assertNotDispatched(TicketCreated::class)`.
7. `test_the_listener_is_discovered` — `Event::fake()`; `Event::assertListening(TicketCreated::class, SendTicketCreatedConfirmation::class)`.
8. `test_a_deleted_ticket_is_skipped` — soft-delete, dispatch, `Notification::assertNothingSent()`, one logged `warning`.

**10. `backend/tests/Feature/Notifications/TicketCreatedMailTest.php`** (new, `RefreshDatabase`) — **AC2, AC3, AC5**: what the email actually says. Each test renders the notification and inspects **both** parts, via the array transport after `$requester->notify(…)` with the queue faked, or by building the `MailMessage` and rendering its two views directly. **Every content assertion must run against the HTML part and the text part**; a helper returning `[$html, $text]` keeps that honest.

1. `test_it_leads_with_the_reference_and_echoes_the_subject_and_description` — **AC2.** Subject header starts `"[{$ticket->reference}]"`; both parts contain the reference, the subject and the description.
2. `test_markdown_in_the_description_is_not_interpreted` — **AC2, the regression guard for the whole design.** Description `"Steps:\n# 1 restart\n**check** the cable\n[link](http://evil.test)"`. Assert both parts contain the literal `**check**`, `# 1 restart` and `[link](http://evil.test)`, and that the HTML part contains **no** `<strong>`, **no** `<h1>` around that text and **no** `href="http://evil.test"`. **This test fails the moment `toMail()` goes back to `->line()`.**
3. `test_line_breaks_in_the_description_survive` — a three-line description with a blank line; assert the HTML part contains the newline sequence inside the `<pre>` block and the text part contains it verbatim. **Measured reference:** the raw text body of the two-view probe was `"Hello Dana,\n\nReference: R\nSubject: S\n\na\nb\n"`.
4. `test_it_promises_no_response_time` — **AC3.** Assert **neither** part contains, case-insensitively, any of `SLA`, `guarantee`, `within 24`, `within 48`, `business day`, `business hours`, `response time`, `as soon as possible`. Assert it **does** contain the plain expectation sentence, so the test fails on an empty template as well as a promising one.
5. `test_it_carries_no_agent_or_triage_information` — **AC5.** Build a ticket that is assigned to an agent, carries a non-default priority and an `escalation_reason`. Assert neither part contains the agent's name, the agent's email, the priority name, the status name, the category name or the escalation reason.
6. `test_it_carries_no_activity_or_note_content` — **AC5, and TM-47's deferred criterion.** Write a `ticket_activities` row whose `meta` holds a distinctive string; assert that string appears in neither part. **Docblock: this is the test TM-47's plan defers to, at line 122 of `40-story-internal-notes-on-a-ticket-TM-47.md`.**
7. `test_it_contains_no_link_to_the_application` — assert neither part contains `localhost:5173`, `config('app.frontend_url')` or the string `/tickets/`. **Docblock the reason: a requester has no login.**
8. `test_html_in_the_description_is_escaped_in_the_html_part` — a `<script>` in the description; assert the HTML part contains `&lt;script&gt;` and **not** `<script>`.
9. `test_the_text_part_is_not_html_escaped` — a `<b>` in the description; assert the text part contains `<b>` and **not** `&lt;b&gt;`. **The regression guard against `{!! !!}` being "hardened" to `{{ }}`.**
10. `test_both_parts_are_present_and_non_empty` — `getHtmlBody()` and `getTextBody()` are both non-empty. TM-56 inherits this test.
11. `test_arabic_and_emoji_survive_both_parts` — a multibyte subject and description render without a broken character.

---

## Verification Steps

1. **Services up:** from the repo root, `docker compose up -d --wait`; `docker compose ps` shows all three containers **healthy**.
2. **Gate present:** from `backend/`, confirm `grep -n QUEUE_CONNECTION phpunit.xml` shows `database`. **If it still says `sync`, stop and land Story 43 first** — test 9.2 is meaningless without it.
3. **Backend tests:** `composer test`. Expect the three pre-existing failures from the Prerequisites and **nothing else**.
4. **This story alone:** `php artisan test --filter='TicketCreatedNotificationTest|TicketCreatedMailTest'`.
5. **Formatting:** `./vendor/bin/pint --test` exits `0`.
6. **The queue claim is real, by hand.** With `backend/.env` pointing at Mailpit, `POST` a ticket over HTTP, then:
   ```bash
   cd backend
   php artisan tinker --execute="echo DB::table('jobs')->count();"   # 1
   ```
   Mailpit (http://localhost:8025) is **still empty**. Then `php artisan queue:work --once` and the confirmation appears, addressed to the requester alone.
7. **Read the email in Mailpit, both tabs.** Submit a description containing `**bold**`, `# heading`, `[click](http://evil.test)`, a blank line and a `<script>` tag. In the **HTML** tab: the markdown is literal, there is no clickable `evil.test` link, the script tag is visible as text, and the blank line is still there. In the **Text** tab: the same, with no `&lt;` entities. **This is the acceptance check for AC2 and it cannot be done from the test output alone.**
8. **The design decision guards itself.** Replace `->view([...], [...])` in `toMail()` with `->line($ticket->description)` and run `php artisan test --filter=test_markdown_in_the_description_is_not_interpreted`. **It must fail.** Restore it.
9. **The AC4 guard guards.** Comment out the `blank($ticket->requester->email)` branch and run `php artisan test --filter=test_a_requester_with_a_blank_email_queues_nothing_and_logs`. **It must fail.** Restore it.
10. **Discovery is live:** `php artisan event:list | grep TicketCreated` shows `SendTicketCreatedConfirmation`. Leave any event and view caches cleared.
11. **Regression:** `git diff --stat` touches only `backend/app/Models/Requester.php`, `backend/app/Events/TicketCreated.php`, `backend/app/Http/Controllers/Api/V1/TicketController.php` (**one line plus one import**), `backend/app/Notifications/TicketCreatedNotification.php`, `backend/app/Listeners/SendTicketCreatedConfirmation.php`, the two Blade views, `docs/api-contract.md` and the two test files. **No migration, no route file, no `StoreTicketRequest`, no `TicketResource`, no `TicketPolicy`, no `RouteAuthorizationTest`, no `frontend/` file.**

---

## Done Criteria

- [ ] Creating a ticket queues **one** confirmation addressed to the requester's address; the acting agent and everyone else receive nothing, and the mail transport is untouched during the request. *(AC1)*
- [ ] The subject leads with `[<reference>]`, and both the HTML and text parts carry the reference, the subject and the description. *(AC2)*
- [ ] The description is echoed **verbatim**: markdown is not interpreted, line breaks survive, HTML is escaped in the HTML part and passed through in the text part. Proven by tests that fail if `toMail()` reverts to `->line()`. *(AC2)*
- [ ] The email states plainly that someone will review and reply, and contains none of `SLA`, `guarantee`, `within 24`, `within 48`, `business day`, `business hours`, `response time` or `as soon as possible`. *(AC3)*
- [ ] A requester whose email is blank queues nothing and logs a `warning` carrying the reference — enforced in the listener with `blank()`, because `NOT NULL` does not forbid `''`. *(AC4)*
- [ ] The email carries no assignee, creator, agent name, agent email, priority, status, category, escalation reason, activity or note content, and no link into the application. TM-47's deferred criterion is discharged by a test that names it. *(AC5)*
- [ ] `App\Events\TicketCreated` carries only the ticket id, has no marker interfaces, and is dispatched **after** the transaction commits — a rolled-back creation dispatches nothing.
- [ ] `App\Listeners\SendTicketCreatedConfirmation` is auto-discovered with **no `Event::listen()` registration**, and a test asserts the binding.
- [ ] `Requester` gained `Notifiable` and nothing else; `RequestersTableSchemaTest::test_it_has_no_login_columns` is still green and no `notifications` table exists.
- [ ] No `tries`, `backoff`, `timeout`, shared layout, deep link, migration, route or frontend file — TM-54, TM-55, TM-56 and TM-57 still own all of it.
- [ ] `composer test` shows the same three pre-existing failures and no new ones; `./vendor/bin/pint --test` exits `0`.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 46.**
