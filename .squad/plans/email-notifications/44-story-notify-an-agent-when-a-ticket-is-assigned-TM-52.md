# Story 44 — Notify an agent when a ticket is assigned (Story: TM-52)

## Prerequisites

- **Story 43 (TM-51) — hard gate.** [`43-story-queue-and-mail-infrastructure-TM-51.md`](43-story-queue-and-mail-infrastructure-TM-51.md). AC5 of this story (*"a feature test asserts the notification is queued rather than sent synchronously"*) is **unimplementable** until `backend/phpunit.xml` runs on the `database` queue driver — under the current `sync` (`phpunit.xml:45`) a queued notification executes inline and the test would pass against code that does exactly what AC5 forbids. Test 7.2 is written directly against Story 43's change. Story 43 also supplies `App\Services\MailSafety`, which every test in this story sends through.
- **Story 29 (TM-34) — hard gate, and it already delivered half of this story.** [`../assignment-workload/29-story-reassign-or-unassign-with-a-reason-TM-34.md`](../assignment-workload/29-story-reassign-or-unassign-with-a-reason-TM-34.md) task 2 creates `backend/app/Events/TicketAssigned.php` with the exact contract this story consumes, and its task 4 dispatches it **after the transaction commits, only when a new assignee was actually set, and never on unassign**. Its own overview states the split plainly: *"TM-52's remaining job is one listener and one mailable."* **`backend/app/Events/` does not exist yet** (`ls backend/app/Events` → *No such file or directory*). **If it is still absent when you start, create `TicketAssigned` verbatim from Story 29's task 2 and record it in the PR so Story 29 does not add a second** — the same rule Story 37 uses for `Ticket::activities()`.
- **Story 26 (TM-31) — hard gate for the endpoint tests.** It creates `POST /api/v1/tickets/{ticket}/assign` (route name **`tickets.assign`**, admin-only) and `TicketController::assign()`. **Neither exists today**: `backend/routes/api.php` has only `tickets.store` (**44**) and `tickets.show` (**45**), and `TicketController` has only `show()` and `store()`. Tests 7.1, 7.2, 7.4 and 7.5 drive that route. **Without it this story cannot satisfy AC1, AC4 or AC5** — the listener and notification can be built and unit-tested against the event, but the story is not done.
- **Story 27 (TM-32) is not a dependency and must stay that way.** Its `POST /tickets/{ticket}/claim` deliberately dispatches **nothing**, because a self-claim is AC3's excluded case. Story 29 wrote that down *for this story*. Task 3 nevertheless enforces the rule in the listener — see the decision below.
- **`User` is already `Notifiable`.** `backend/app/Models/User.php:24` — `use HasApiTokens, HasFactory, Notifiable;`. **No trait needs adding**, and `$user->notify(...)` works today.
- **No new composer or npm dependency, no migration, no route, no controller change, no frontend file.** `users.email` is `NOT NULL UNIQUE`, and `tickets.requester_id`, `category_id`, `priority_id` and `status_id` are all **NOT NULL** with `restrictOnDelete` (`2026_08_26_084625_create_tickets_table.php:18–21`), so the email's fields cannot be missing for structural reasons. **One exception, and it is real: `Category` uses `SoftDeletes`** (`Category.php:41`), so `$ticket->category` **can** resolve to `null`. Task 3 handles it.
- **Baseline, 2026-08-27:** `composer test` → **101 tests, 98 passing, 3 failing** (`PasswordThrottleTest`, `RouteAuthorizationTest`, `TicketReferenceTest` — all pre-existing, none mail-related); `./vendor/bin/pint --test` exits `0`. **Story 26 adopts `tickets.assign` into `RouteAuthorizationTest::ACCESS`; this story does not touch that file.**

---

## Story Goal

**Story 29 already shipped the rule. This story attaches the mail.** Its plan states the division in a table, and this story is the right-hand column:

| AC3 clause of TM-34 | Delivered by Story 29 | This story |
|---|---|---|
| "notifies the new assignee" | `TicketAssigned` dispatched with the assignee's id | Queued notification + the mail body |
| "does not notify the previous one twice" | No event carries the previous assignee; **unassign dispatches nothing** | Nothing to add |

So the deliverable is **three classes, one config key and two test files**:

1. `App\Listeners\SendTicketAssignedNotification` — auto-discovered, synchronous, and the single place AC3's self-assign rule is enforced.
2. `App\Notifications\TicketAssignedNotification` — `implements ShouldQueue`, so `$user->notify()` pushes one `SendQueuedNotifications` job and the request returns having sent nothing.
3. The mail body: reference, subject, priority, category, requester and a deep link to `{FRONTEND_URL}/tickets/{id}`, plus the handover reason when the assignment carried one.
4. `config('app.frontend_url')`, because `FRONTEND_URL` is currently reachable only through `env()` in `config/cors.php:22` and `env()` returns `null` once config is cached.

**Not in scope, and each belongs to a named story.** **No `tries`, `backoff`, `timeout`, `retryUntil` or `ShouldQueueAfterCommit`** — TM-57 (E8-S7) owns the retry policy in full, exactly as Story 43 recorded. **No shared Blade mail layout, no `resources/views/mail/` directory, no published notification theme** — TM-56 (E8-S6) owns the layout, and it extracts one when there are four emails to share it, not one. **No requester-facing mail** — TM-53 and TM-54. **No admin escalation mail** — TM-55. **No `TicketUnassigned` event and no "removed from your queue" courtesy notice** — Story 29 forbids adding one speculatively; AC4 says unassigning sends nothing to anyone, and that includes the previous holder. **No change to `TicketController`, `routes/api.php`, `TicketPolicy` or `RouteAuthorizationTest`** — Stories 26 and 29 own every one of those lines. **No frontend file, no in-app notification, no `notifications` database table.**

---

## Decision — a queued `Notification`, not a `Mailable`

- **`User` is already `Notifiable`** (`User.php:24`), so `$assignee->notify(...)` costs nothing to enable.
- **The acceptance criteria are phrased in notification terms** — *"dispatches a queued notification to the new assignee only"* — and `Notification::fake()` answers exactly that shape: `assertSentTo($assignee, …)` **and** `assertNotSentTo($otherUsers, …)`. A bare `Mailable` would force every recipient assertion to be a string search of the rendered body.
- **Recipient routing is the framework's problem, not this story's.** `routeNotificationForMail()` already reads `users.email`, which is `NOT NULL UNIQUE`.
- **TM-56 is not blocked by this.** A `Notification::toMail()` may return a `MailMessage` today and a `->view('mail.layout…')` or a custom `Mailable` later, with no change to the listener, the event or any test that asserts recipients.

## Decision — the notification is queued; the listener is not

`TicketAssignedNotification implements ShouldQueue`. `SendTicketAssignedNotification` implements nothing.

- **One job, not two.** If both were queued, a single assignment would push a listener job that pushes a notification job. Queueing the notification alone produces exactly one `SendQueuedNotifications` row in `jobs`, which is what test 7.2 counts.
- **The listener does two indexed primary-key reads and no I/O**, inside a request that has already committed. That is cheaper than serialising a job to do it.
- **AC5 is satisfied by the notification's `ShouldQueue`**, not by the listener's absence of it: the mail transport is never touched during the request either way.
- **No `ShouldQueueAfterCommit` is needed.** Story 29 already dispatches `TicketAssigned` **after** `DB::transaction()` returns (its task 4, guarded on `$changed`), so there is no open transaction when the listener runs. Adding the interface would be a second belt for a fastened one, and **TM-57's fourth criterion owns that guarantee product-wide.**

## Decision — the self-assign guard lives in the listener

AC3 — *"No email is sent when a user assigns a ticket to themselves"* — is enforced by `if ($event->assigneeId === $event->actorId) { return; }`, not at any dispatch site.

- **Story 29 asked for it here, by name:** *"`$targetId === $actorId` is currently **unreachable** on `/assign`… If that rule is ever relaxed, TM-52's criterion is where the guard belongs."* This story is TM-52.
- **The listener is the only place every dispatch passes through.** Today there are two potential producers — Story 26/29's `/assign` and Story 27's `/claim` — and the claim endpoint's compliance rests on it *not* calling `dispatch()`. A guard at the consumer survives a future producer that forgets.
- **It costs one integer comparison and one test**, and it makes AC3 true by construction rather than by three separate stories agreeing to remember.

## Decision — `MailMessage`'s built-in markdown, and no bespoke layout

`toMail()` returns a plain `MailMessage` with `->greeting()`, `->line()` and `->action()`.

- **The plain-text alternative part comes for free, today.** Measured: `MailChannel::buildView()` (`vendor/laravel/framework/src/Illuminate/Notifications/Channels/MailChannel.php:94–102`) returns `['html' => …, 'text' => $this->buildMarkdownText($message)]`. A rendered probe produced **`html_len=11676`, `text_len=463`** — both parts, with **no `mail.markdown` key present in `config/mail.php`**. TM-56's *"every email has a plain-text alternative part"* is therefore already true for this shape and stays true through the refactor.
- **The header and footer already come from configuration**, which is TM-56's fourth criterion. The rendered probe's text part opened with `Ticket Management: http://localhost:8000` — `config('app.name')` and `config('app.url')`.
- **Building a layout for one email is the wrong time to build a layout.** TM-56 has four mailables to unify; extracting a shared header, footer and ticket-summary block from a single example is guesswork. **Do not create `resources/views/mail/` in this story** — `backend/resources/views/` currently holds only `welcome.blade.php`.
- **Measured trap: markdown emphasis does not survive into the text part.** The probe's `->line('**Reference:** …')` rendered literally as `**Reference:** TKT-2026-000042` in plain text. **Every field line in task 4 is plain `Label: value` with no `**`, `_` or backticks.**

## Decision — the deep link is `{frontend_url}/tickets/{id}`, using the numeric id

- **Measured from the SPA's own router**: `frontend/src/router/index.ts:52` declares `{ path: '/tickets/:id', name: 'ticket-detail' }`, and `TicketDetailView.vue:9` loads with `store.loadTicket(Number(route.params.id))`. **The route takes the numeric primary key, not `reference`.** A link built from `TKT-2026-000042` would land on a page that calls `loadTicket(NaN)`.
- **The email still leads with the reference in its subject and first field**, because that is the string a human quotes. The id is only in the URL.
- **`FRONTEND_URL` must be reached through config, never `env()`.** `env()` outside a config file returns `null` once `php artisan config:cache` has run, which would silently ship `http://localhost:5173`-less links — or worse, `/tickets/42` with no host. Task 1 adds `config('app.frontend_url')`.
- **`config/cors.php:22` keeps its own `env('FRONTEND_URL', …)` call and is not edited.** A config file must not call `config()` — the container is still being built when it loads. Two config files reading the same variable with the identical default is the correct shape, not duplication to tidy away.

---

## Context — Read These Files First

1. [`../assignment-workload/29-story-reassign-or-unassign-with-a-reason-TM-34.md`](../assignment-workload/29-story-reassign-or-unassign-with-a-reason-TM-34.md) — **read `## Decision — AC3 delivers the rule and the dispatch point; TM-52 attaches the mail` in full**, then task 2 (the event, with its docblock naming this story three times) and task 4's `assign()` body (the `if ($changed && $targetId !== null)` dispatch **after** `DB::transaction()`). **This is the contract; do not re-derive it.**
2. `backend/app/Events/TicketAssigned.php` — **if it exists.** Four readonly promoted properties: `int $ticketId`, `int $assigneeId`, `int $actorId`, `?string $reason`. **Scalar ids, no models, no marker interfaces.** If the file is absent, see the Prerequisites.
3. `backend/app/Models/User.php` — **`Notifiable` at line 24** (nothing to add) and **`scopeActive()` at 46–50**, which task 3 uses to skip a deactivated assignee.
4. `backend/app/Models/Ticket.php` — `SoftDeletes` at **16**; the `BelongsTo` relations task 3 eager-loads: `requester()` **23–26**, `category()` **28–31**, `priority()` **33–36**. `#[Fillable]` at **12** already contains `assigned_to`.
5. `backend/app/Models/Category.php:41` — **`use SoftDeletes;`**. This is the one relation in the email that can come back `null`, and task 3's `withTrashed()` is why it does not.
6. `backend/database/migrations/2026_08_26_084625_create_tickets_table.php` — **lines 18–21**: `requester_id`, `category_id`, `priority_id`, `status_id` are all `NOT NULL` with `restrictOnDelete`. **line 22**: `assigned_to` is nullable, `nullOnDelete`. Read it so you do not write null-guards the schema already makes unreachable.
7. `backend/config/app.php` — **`'version' => env('APP_VERSION', '0.1.0')` at line 29**, with its boxed comment at **18–27**. That is the precedent for a project-specific key in a stock Laravel config file, and task 1 inserts immediately after it.
8. `backend/config/cors.php:21–24` — the **only** current reader of `FRONTEND_URL`. **Do not edit it.**
9. `frontend/src/router/index.ts:52` — the ticket-detail path. Confirm the `:id` param yourself before writing the URL builder.
10. `backend/app/Services/MailSafety.php` (Story 43) — the guard every test in this story sends through. `phpunit.xml:44` sets `MAIL_MAILER=array`, which is a safe transport, so nothing here trips it.
11. `backend/tests/Feature/Database/AdminUserSeederTest.php` — **the precedent for a non-HTTP feature test** with `RefreshDatabase` and a `setUp()` that fixes config. Task 7's helper follows it.
12. `backend/database/seeders/DatabaseSeeder.php:17–22` — **`$this->seed()` calls `AdminUserSeeder` first, and that seeder throws `RuntimeException('ADMIN_PASSWORD is empty. Set it in .env.')` (`AdminUserSeeder.php:17–19`) because `phpunit.xml` never sets it.** Every test in this story seeds the three master-data seeders explicitly — see task 7. **Do not call `$this->seed()` with no argument.**
13. `backend/tests/Feature/Queue/QueueInfrastructureTest.php` (Story 43) — its `queue:work --once` test is the precedent test 7.2 follows, **including Story 43's recorded caveat** that driving the worker in-process is the one mechanism it did not prove inside PHPUnit.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Admin assigns ticket to agent B | `TicketAssigned` dispatched, **no listener** | One `SendQueuedNotifications` job; B is emailed by the worker |
| The same assignment repeated (double-click) | `getDirty() === []` → **no event** (Story 29) | Still no event, therefore **no second email** |
| Admin unassigns (`assigned_to: null`) | Event **not** dispatched (Story 29) | **No email to anyone**, including the previous holder — AC4 |
| Agent claims a ticket for themselves (TM-32) | Claim endpoint dispatches **nothing** | Still nothing — **and the listener would refuse it anyway** — AC3 |
| `assigneeId === actorId` from any future producer | — | Listener returns immediately; **nothing queued** — AC3 |
| Assignee was deactivated between dispatch and handling | — | Skipped, one `Log::info` line, no job |
| Ticket soft-deleted between dispatch and handling | — | Skipped, one `Log::info` line, no job |
| Ticket's category was soft-deleted | — | The email still names it — the relation is loaded `withTrashed()` |
| The assignment carried a `reason` | Stored as `meta.reason` on the activity row | Quoted in the email as a handover note |
| The assignment carried no `reason` | — | The handover line is **absent**, not "Reason: none" |
| During the HTTP request | — | **Zero messages reach the mail transport** — AC5 |
| The mail transport is down | — | The job fails and lands in `failed_jobs`; **the assignment is already committed and is not rolled back** |

---

## Backend Tasks

### 1 — The frontend URL as configuration

**File: `backend/config/app.php`**

Insert immediately after `'version'` (**after line 29**), matching the boxed-comment style of the block above it:

```php
    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | Where the Vue SPA is served. Notification emails build their deep links
    | from it — the ticket page is {frontend_url}/tickets/{id}, keyed on the
    | numeric id, not the reference (frontend/src/router/index.ts).
    |
    | config/cors.php reads the same FRONTEND_URL variable with the same
    | default. That is deliberate: a config file must not call config(), so the
    | two files each read env() rather than one deferring to the other.
    |
    */

    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
```

**Do not edit `config/cors.php`.** **Do not add a new variable to `backend/.env.example`** — `FRONTEND_URL=http://localhost:5173` is already there at **line 63**, with a comment that already says *"Used for CORS and for links inside notification emails."* That comment stops being aspirational with this story; leave it as it stands.

### 2 — The notification

**Create file: `backend/app/Notifications/TicketAssignedNotification.php`** — this creates `backend/app/Notifications/`, the first directory of its kind.

```php
<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * "A ticket is now yours." Sent to the new assignee and to nobody else.
 *
 * ShouldQueue is the whole of TM-52's fifth criterion: notify() pushes one
 * SendQueuedNotifications job and the request returns having touched no mail
 * transport. tries, backoff and timeout are deliberately absent — TM-57 owns
 * the retry policy for every notification in the system.
 *
 * The body is a plain MailMessage rather than a bespoke Blade view: it already
 * renders an HTML part and a plain-text alternative, and TM-56 replaces it with
 * the shared layout once there are four emails to share one.
 */
class TicketAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?string $reason,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;

        // No markdown emphasis in any line: measured, the text renderer emits
        // "**Reference:**" literally rather than bolding it.
        $message = (new MailMessage)
            ->subject(sprintf('[%s] Assigned to you: %s', $ticket->reference, Str::limit($ticket->subject, 60)))
            ->greeting("Hello {$notifiable->name},")
            ->line('A ticket has been assigned to you.')
            ->line("Reference: {$ticket->reference}")
            ->line("Subject: {$ticket->subject}")
            ->line("Priority: {$ticket->priority->name}")
            ->line("Category: {$ticket->category?->name}")
            ->line(sprintf('Requester: %s (%s)', $ticket->requester->name, $ticket->requester->email));

        if (filled($this->reason)) {
            $message->line("Handover note: {$this->reason}");
        }

        return $message
            ->action('View the ticket', $this->ticketUrl($ticket))
            ->line('You are receiving this because the ticket is now assigned to you.');
    }

    private function ticketUrl(Ticket $ticket): string
    {
        // The SPA route is /tickets/:id and loads with Number(route.params.id),
        // so this is the primary key — never the reference.
        return rtrim((string) config('app.frontend_url'), '/').'/tickets/'.$ticket->getKey();
    }
}
```

Four things that are load-bearing:

- **`readonly` promoted properties are safe here, and this was measured rather than assumed.** `SerializesModels::__unserialize()` (`vendor/laravel/framework/src/Illuminate/Queue/SerializesModels.php:97–100`) restores state with `ReflectionProperty::setValue()` on an object built without its constructor. On **PHP 8.3.6** a readonly property in that state accepts exactly one reflection write (`first_set=OK`, `second_set=FAIL Cannot modify readonly property`), which is precisely one more than unserialization needs. Measured separately: `hasDefaultValue()` is **`false`** for promoted properties, so `__serialize`'s "skip values equal to the default" branch (**44–46**) cannot silently drop a `null` `$reason`.
- **`$reason` has no default value.** The listener always passes both arguments; a default would add a construction path nothing uses.
- **`SerializesModels` turns the `Ticket` into an id and re-fetches it in the worker**, so the email reflects the ticket as it stands when the mail is built, not when it was assigned. That is the correct reading of a notification, and it is why the listener may pass the model rather than the id.
- **`$ticket->category?->name` is the one null-safe access in the file**, and `Category::SoftDeletes` is the reason. Task 3 loads the relation `withTrashed()`, so in practice the `?->` never fires — it is there so a category deleted between load and render degrades to an empty field rather than a failed job.

### 3 — The listener

**Create file: `backend/app/Listeners/SendTicketAssignedNotification.php`** — this creates `backend/app/Listeners/`, the first directory of its kind.

```php
<?php

namespace App\Listeners;

use App\Events\TicketAssigned;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketAssignedNotification;
use Illuminate\Support\Facades\Log;

/**
 * Turns TM-34's domain event into TM-52's email.
 *
 * Deliberately NOT queued: the notification it sends is, so a single
 * assignment produces exactly one job rather than a job that queues a job.
 * The two reads below are primary-key lookups in a request whose transaction
 * has already committed.
 */
class SendTicketAssignedNotification
{
    public function handle(TicketAssigned $event): void
    {
        // AC3 — "No email is sent when a user assigns a ticket to themselves."
        // Enforced here, not at the dispatch sites: this is the one place every
        // producer passes through, and TM-34's plan names this story as where
        // the guard belongs if /assign ever accepts a self-target.
        if ($event->assigneeId === $event->actorId) {
            return;
        }

        $assignee = User::query()->whereKey($event->assigneeId)->active()->first();

        if ($assignee === null) {
            Log::info('Assignment notification skipped: assignee missing or inactive.', [
                'ticket_id' => $event->ticketId,
                'assignee_id' => $event->assigneeId,
            ]);

            return;
        }

        // whereKey() honours SoftDeletes, so a ticket deleted between the
        // commit and this line resolves to null and nothing is queued.
        // The category is loaded withTrashed() because Category soft-deletes
        // and the email should still name the category the ticket carries.
        $ticket = Ticket::query()
            ->whereKey($event->ticketId)
            ->with(['requester', 'priority', 'category' => fn ($query) => $query->withTrashed()])
            ->first();

        if ($ticket === null) {
            Log::info('Assignment notification skipped: ticket no longer exists.', [
                'ticket_id' => $event->ticketId,
                'assignee_id' => $event->assigneeId,
            ]);

            return;
        }

        $assignee->notify(new TicketAssignedNotification($ticket, $event->reason));
    }
}
```

- **No registration is needed, and that is verified rather than assumed.** `Application::configure()` calls `->withEvents()` unconditionally (`vendor/laravel/framework/src/Illuminate/Foundation/Application.php:248–252`), `EventServiceProvider::$shouldDiscoverEvents` is `true` (**41**), and `discoverEventsWithin()` defaults to `app_path('Listeners')` (**166–171**). **Do not add an `Event::listen()` call to `AppServiceProvider`** — Story 43 put the mail-safety listener there because `MessageSending` is a framework event with no class-named listener; this one is discovered by its `handle(TicketAssigned $event)` signature. Test 7.7 pins the discovery.
- **`->active()` is `User::scopeActive()`** (`User.php:46–50`). Story 26's validation already requires an active agent at assign time; this covers the window between commit and handling, and it is why an admin who deactivates an agent immediately after assigning does not send them mail.
- **Both skips log at `info` and return.** Neither is an error: they are races the system is allowed to lose. **Do not throw** — an exception here would surface on the request that made the assignment, which TM-57's third criterion forbids.

### 4 — Document the email

**File: `docs/api-contract.md`**

Story 29's task adds a **`Notifications`** block describing `TicketAssigned` and saying the mailable lands in TM-52. **Amend that block; do not add a second one.** Replace its closing sentence with:

```markdown
`App\Listeners\SendTicketAssignedNotification` turns that event into a queued
`App\Notifications\TicketAssignedNotification` addressed to the new assignee
alone. The email carries the reference, subject, priority, category, requester
and a link to `{FRONTEND_URL}/tickets/{id}`, plus the handover reason when the
assignment carried one. Nothing is sent during the request: `notify()` pushes a
`SendQueuedNotifications` job and a worker delivers it. Self-assignment,
unassignment, a repeated no-op assignment, an inactive assignee and a deleted
ticket all send nothing.
```

**If Story 29 has not landed and no `Notifications` section exists**, add one under `## Endpoints` with the paragraph above preceded by a one-sentence description of `TicketAssigned` taken verbatim from Story 29's task, and note in the PR that Story 29 must amend rather than duplicate it.

**No frontend changes required.** Nothing in `frontend/` renders, triggers or displays an email. **No README change** — Story 43's `## Queue and mail` section already documents running the worker and reading captured mail, which is everything a developer needs to see this story's output.

---

## Edge Cases & Failure Modes

- **A repeated assignment (double-click) sends nothing, and this story adds no logic for it.** Story 29's `getDirty() === []` early return means no event is dispatched at all (its task 4, its test 11). Test 7.5 asserts the *mail* consequence so the two layers are pinned independently — if someone later moves the dispatch above the dirty check, this story's test fails too.
- **Unassignment sends nothing to anyone, including the previous holder.** Story 29 carries no previous-assignee id on the event by design, so there is nothing to accidentally notify. **The consequence is stated rather than hidden: an agent whose ticket is taken away learns about it in the app, not by email.** A courtesy notice needs a new event and a new story; **do not add `TicketUnassigned` here.**
- **Self-assignment is guarded even though it is currently unreachable.** Story 26 requires an active **agent** target and admin-only access, so `/assign` cannot self-target today, and Story 27's `/claim` dispatches nothing. The listener guard is what makes AC3 true when either of those changes. Test 7.4 drives the event directly rather than the endpoint, because the endpoint cannot reach the case.
- **The assignee is deactivated between commit and handling** → skipped with a `Log::info`. Story 29 records that an existing assignment survives its owner's deactivation on purpose (TM-35 surfaces those), so the assignment stands; only the email is dropped.
- **The ticket is soft-deleted between commit and handling** → `whereKey()` applies the `SoftDeletingScope`, resolves to `null`, skipped with a `Log::info`. **The ticket is deliberately not restored with `withTrashed()`** — an email inviting someone to work a deleted ticket is worse than no email.
- **The ticket's category is soft-deleted** → the email still names it, because the relation is loaded `withTrashed()`. Without that, `$ticket->category` is `null` and the email would ship `Category: ` on a ticket that plainly has one. TM-18 makes this rare, not impossible.
- **The ticket changes between dispatch and delivery.** `SerializesModels` stores the id and re-fetches in the worker, so a priority raised or a subject corrected in the intervening seconds is reflected. **The `reason` does not re-fetch** — it is a scalar captured on the event, which is correct: it is what was said at handover, not current state.
- **A very long subject.** `Str::limit($ticket->subject, 60)` bounds the subject **header** only; the full subject is a body line. `tickets.subject` is `varchar(255)` (`create_tickets_table.php:16`), so the unbounded body line has a known ceiling.
- **Arabic and emoji in the subject or requester name.** Both MySQL containers run `utf8mb4`, `Str::limit()` is multibyte-safe, and `MailMessage` renders UTF-8. **`substr()` must not be used anywhere in this story** — it would split a multibyte character in the subject header.
- **The mail transport is down when the worker runs.** The job fails and lands in `failed_jobs` with its exception (Story 43 measured that path end to end). **The assignment is already committed and is not rolled back** — which is TM-57's third criterion, and it holds here only because Story 29 dispatches outside the transaction. **Do not move the `notify()` call into a transaction to "fix" a failed send.**
- **`php artisan event:cache` freezes the listener map.** A cached event map built before this story's listener existed will not dispatch it. `php artisan event:clear` is the fix; **`composer test` already runs `config:clear`, and no `event:cache` is committed or run by CI**, so the suite is immune and this is a local-development note only.
- **`Notification::fake()` cannot prove queueing.** It intercepts before the notification reaches the queue, so `assertSentTo` passes identically for a queued and a synchronous notification. **AC5 therefore has to be tested without a fake** — test 7.2 counts rows in `jobs` and messages on the array transport instead. Do not "simplify" it into a fake.
- **Driving `queue:work --once` inside PHPUnit is the one mechanism neither Story 43 nor this story proved in-process.** It was measured from the CLI in both directions. If test 7.2's post-worker assertion does not see the message, fall back to asserting that `jobs` is **empty** after the worker ran and that `failed_jobs` is empty — that still proves queued-then-processed — and record the fallback in the PR. **Do not downgrade the pre-worker half of the assertion**, which is the part that carries AC5.
- **`$this->seed()` with no argument throws.** `DatabaseSeeder` calls `AdminUserSeeder` first, which aborts on an empty `ADMIN_PASSWORD`, and `phpunit.xml` never sets one. Every test here seeds `CategorySeeder`, `PrioritySeeder` and `StatusSeeder` explicitly.

---

## Test Plan

All backend, all Feature. **No frontend test changes. No existing test is modified or deleted.**

Both files share this setup, following `AdminUserSeederTest`'s shape and Story 31's `ticketAt()` precedent (there is still **no `TicketFactory`** — `database/factories/` holds only `UserFactory.php`; **TM-59 owns it, and if it has landed by then, use it and delete the helper**):

```php
protected function setUp(): void
{
    parent::setUp();
    $this->seed([CategorySeeder::class, PrioritySeeder::class, StatusSeeder::class]);
}

private function ticket(): Ticket   // Model::create against the seeded master data,
                                    // reference via TicketReferenceGenerator in a transaction
```

**7. `backend/tests/Feature/Notifications/TicketAssignedNotificationTest.php`** (new, `RefreshDatabase`) — who is notified, and how.

1. `test_assignment_notifies_the_new_assignee_and_nobody_else` — **AC1.** `Notification::fake()`; admin `POST`s `route('tickets.assign', $ticket)` with `assigned_to` = agent B. `assertSentTo($agentB, TicketAssignedNotification::class)`, then `assertNotSentTo([$admin, $agentC], TicketAssignedNotification::class)`. **Assert `assertSentTimes(TicketAssignedNotification::class, 1)`** so a second listener added later fails here.
2. `test_the_notification_is_queued_rather_than_sent_synchronously` — **AC5, the flagship.** **No fakes.** Assign, then assert `DB::table('jobs')->count() === 1` **and** `app('mailer')->getSymfonyTransport()->messages()` is **empty** — the request touched no transport. Then `$this->artisan('queue:work', ['--once' => true])` and assert the transport now holds **1** message and `failed_jobs` is empty. **This test fails under `QUEUE_CONNECTION=sync`, which is the point** — note that in its docblock alongside Story 43.
3. `test_unassigning_notifies_nobody` — **AC4.** `Notification::fake()`; assign to B, clear the fake state by re-faking, then `POST` `assigned_to: null`. `Notification::assertNothingSent()`.
4. `test_assigning_to_yourself_notifies_nobody` — **AC3.** Driven through the **event**, not the endpoint, because Story 26's rules make self-assign unreachable over HTTP: `Notification::fake()`; `TicketAssigned::dispatch($ticket->getKey(), $user->getKey(), $user->getKey(), null)`; `assertNothingSent()`. **Document in the docblock that this is deliberate**, so nobody "fixes" it into an endpoint call that cannot be written.
5. `test_a_repeated_assignment_notifies_only_once` — assign B, then assign B again; `assertSentTimes(TicketAssignedNotification::class, 1)` across both requests.
6. `test_an_inactive_or_missing_assignee_is_skipped` — dispatch the event with an assignee id that is deactivated, then with one that does not exist; `assertNothingSent()` both times.
7. `test_the_listener_is_discovered` — `Event::fake()`; `Event::assertListening(TicketAssigned::class, SendTicketAssignedNotification::class)`. Cheap, and it is the only thing standing between this story and a stale `event:cache`.
8. `test_a_deleted_ticket_is_skipped` — soft-delete the ticket, dispatch the event, `assertNothingSent()`.

**8. `backend/tests/Feature/Notifications/TicketAssignedMailTest.php`** (new, `RefreshDatabase`) — **AC2**, what the email actually says. Each test builds the notification directly and renders it: `$mail = (new TicketAssignedNotification($ticket, $reason))->toMail($assignee);` then asserts on `$mail->render()` for the HTML and on `$mail->introLines` / `$mail->outroLines` / `$mail->actionUrl` for structure.

1. `test_it_contains_the_reference_subject_priority_category_and_requester` — **AC2.** One assertion per field against the rendered body, using the seeded priority and category names.
2. `test_the_subject_line_leads_with_the_reference` — `assertStringStartsWith("[{$ticket->reference}]", $mail->subject)`.
3. `test_it_links_to_the_spa_ticket_page_by_numeric_id` — `config()->set('app.frontend_url', 'https://helpdesk.test')`; assert `$mail->actionUrl === "https://helpdesk.test/tickets/{$ticket->getKey()}"`. **Assert the reference does not appear in the URL** — that is the whole point of the decision above.
4. `test_a_trailing_slash_on_the_frontend_url_does_not_double` — `'https://helpdesk.test/'` still yields one slash.
5. `test_the_handover_reason_appears_only_when_one_was_given` — with a reason, the line is present; with `null`, no line contains `Handover note`.
6. `test_it_renders_a_plain_text_part_alongside_the_html` — build the message through the mail channel and assert both `getHtmlBody()` and `getTextBody()` are non-empty. **Measured shape: `html_len=11676`, `text_len=463`.** This is the test TM-56 inherits.
7. `test_it_names_a_soft_deleted_category` — soft-delete the ticket's category, run the **listener** (so the `withTrashed()` eager load is exercised, not bypassed), and assert the category name is still in the body.
8. `test_it_does_not_leak_the_assigning_admins_email` — assert the admin's address appears nowhere in the rendered HTML or text. TM-56's fifth criterion, asserted from the first email rather than retrofitted to four.
9. `test_a_long_subject_is_truncated_in_the_header_but_not_in_the_body` — a 200-character subject; the header is bounded, the body line is whole.
10. `test_arabic_and_emoji_survive_the_subject_header` — a multibyte subject longer than 60 characters renders without a broken character. **The regression guard against anyone replacing `Str::limit()` with `substr()`.**

---

## Verification Steps

1. **Services up:** from the repo root, `docker compose up -d --wait`; `docker compose ps` shows all three containers **healthy**.
2. **Gates present:** from `backend/`, confirm `app/Events/TicketAssigned.php` exists and `php artisan route:list --name=tickets.assign` lists the route. **If either is missing, stop and read the Prerequisites** — do not work around them.
3. **Backend tests:** `composer test`. Expect the three pre-existing failures from the Prerequisites and **nothing else**; both new files pass in full.
4. **This story alone:** `php artisan test --filter='TicketAssignedNotificationTest|TicketAssignedMailTest'`.
5. **Formatting:** `./vendor/bin/pint --test` exits `0`.
6. **The queue claim is real, by hand.** With `docker compose` up and `backend/.env` pointing at Mailpit, assign a ticket over HTTP, then:
   ```bash
   cd backend
   php artisan tinker --execute="echo DB::table('jobs')->count();"   # 1
   ```
   Mailpit (http://localhost:8025) is **still empty**. Then `php artisan queue:work --once` and the email appears, addressed to the assignee alone.
7. **The deep link works.** Click "View the ticket" in Mailpit. It must open `http://localhost:5173/tickets/<numeric id>` and the SPA must load that ticket — not a blank detail page, which is what a reference-based URL produces.
8. **The self-assign guard guards.** Comment out the `assigneeId === actorId` early return and run `php artisan test --filter=test_assigning_to_yourself_notifies_nobody`. **It must fail.** Restore it.
9. **Discovery is not accidental.** `php artisan event:list | grep TicketAssigned` shows `SendTicketAssignedNotification`. Then `php artisan event:cache`, add a second listener file, and confirm `event:list` is stale until `php artisan event:clear` — this is the edge case, confirmed once so the note in this plan is trustworthy. **Leave the cache cleared.**
10. **Regression:** `git diff --stat` touches only `backend/config/app.php`, `backend/app/Notifications/TicketAssignedNotification.php`, `backend/app/Listeners/SendTicketAssignedNotification.php`, `docs/api-contract.md` and the two test files. **No migration, no route file, no `TicketController`, no `TicketPolicy`, no `RouteAuthorizationTest`, no `config/cors.php`, no `resources/views/`, no `frontend/` file.**

---

## Done Criteria

- [ ] Assigning a ticket queues **one** notification addressed to the **new assignee only**; the actor, the previous holder and every other agent receive nothing. *(AC1)*
- [ ] The email carries the reference, the subject, the priority name, the category name, the requester's name and email, and a link to `{FRONTEND_URL}/tickets/{id}` built from `config('app.frontend_url')` and the **numeric id**. *(AC2)*
- [ ] The handover `reason` from `TicketAssigned` appears when present and the line is absent when it is not. *(AC2)*
- [ ] `assigneeId === actorId` sends nothing, enforced **in the listener** so it holds for every current and future producer. *(AC3)*
- [ ] Unassigning sends nothing to anyone, and no `TicketUnassigned` event was invented. *(AC4)*
- [ ] A feature test with **no fakes** proves the mail transport receives nothing during the request, exactly one `jobs` row is written, and the message is delivered only once a worker runs. *(AC5)*
- [ ] `App\Listeners\SendTicketAssignedNotification` is auto-discovered with **no `Event::listen()` registration**, and a test asserts the binding.
- [ ] An inactive assignee, a missing assignee, a soft-deleted ticket and a repeated no-op assignment each send nothing and log rather than throw.
- [ ] The email renders both an HTML part and a plain-text alternative, and leaks no agent email address.
- [ ] No `tries`, `backoff`, `timeout`, shared Blade layout, `resources/views/mail/` directory, migration, route, controller change or frontend file — TM-56 and TM-57 still own all of it.
- [ ] `composer test` shows the same three pre-existing failures and no new ones; `./vendor/bin/pint --test` exits `0`.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 45.**
