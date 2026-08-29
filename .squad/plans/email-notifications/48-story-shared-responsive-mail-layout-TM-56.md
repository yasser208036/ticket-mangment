# Story 48 — Shared responsive mail layout (Story: TM-56)

## Prerequisites

- **All four mail stories are hard gates. This story unifies them and has nothing to do until they land.** Verified on disk while planning: `backend/app/Notifications/`, `backend/app/Listeners/`, `backend/app/Events/` and `backend/resources/views/mail/` **all do not exist**; `backend/resources/views/` holds **only `welcome.blade.php`**.
  | Story | Supplies | State on disk |
  |---|---|---|
  | [`44` (TM-52)](44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md) | `TicketAssignedNotification` — **the only one still on `MailMessage->line()`, with no views at all** | absent |
  | [`45` (TM-53)](45-story-confirm-ticket-creation-to-the-requester-TM-53.md) | `mail/tickets/created.blade.php` + `created-text.blade.php` | absent |
  | [`46` (TM-54)](46-story-notify-the-requester-on-status-change-and-resolution-TM-54.md) | `mail/tickets/status-changed.blade.php` + `-text` | absent |
  | [`47` (TM-55)](47-story-notify-admins-on-escalation-TM-55.md) | `mail/tickets/escalated.blade.php` + `-text` | absent |
  **Gate: `php artisan test --filter='TicketAssignedMailTest|TicketCreatedMailTest|TicketStatusChangedMailTest|TicketEscalatedMailTest'` green before you start.** A layout extracted from three of four is a layout extracted twice.
- **Story 43 (TM-51) — hard gate, transitively.** `phpunit.xml:44` sets `MAIL_MAILER=array`, which is what every test here renders through, and `App\Services\MailSafety` is what keeps them off a socket.
- **This story ships no new email and changes no recipient, subject or content policy.** Every subject line, every recipient rule and every field decision stays exactly as Stories 44–47 wrote it. **The one exception is TM-52's body markup**, which has to move onto views for AC1 to be true — see the decision.
- **`backend/resources/views/vendor/` does not exist** and **`config/mail.php` has no `markdown` key** (verified). Nothing has been published from `laravel/framework`, so there is no vendor override to reconcile.
- **`backend/package.json` has Tailwind 4, Vite 8 and `laravel-vite-plugin`** — for **Blade-side assets**, not for mail. **No mail template may use a Tailwind class, a compiled stylesheet, a `@vite()` directive or a web font.** A mail client runs no build step and fetches no `<link>`.
- **No new composer or npm dependency, no migration, no route, no controller change, no frontend file, no event, no listener.**
- **Baseline, 2026-08-27:** `composer test` → **101 tests, 98 passing, 3 failing** (`PasswordThrottleTest`, `RouteAuthorizationTest`, `TicketReferenceTest` — all pre-existing); `./vendor/bin/pint --test` exits `0`. Stories 44–47 will have added roughly 80 tests by the time this one starts; **re-measure and record the number before you change anything**, because this story's whole risk profile is "did the refactor break a passing suite".

---

## Story Goal

Four emails that were written independently start looking like one product, and the rules they each asserted separately become one enforced set.

1. **`mail/layout.blade.php` and `mail/layout-text.blade.php`** — header, footer and the responsive shell, `@yield('content')` in both.
2. **`mail/partials/summary.blade.php` and `summary-text.blade.php`** — the ticket-summary block, taking a `$rows` map so each mailable keeps its own content policy.
3. **`mail/partials/link.blade.php` and `link-text.blade.php`** — the one call-to-action shape, which Story 47 explicitly deferred here (*"No button component — TM-56 owns anything shared"*).
4. **TM-52's notification moves off `MailMessage->line()` onto a view pair**, so AC1's *"every mailable"* is literally true. **This breaks two of Story 44's tests by design**, and task 9 amends them.
5. **`MailLayoutTest`** — one table-driven suite that renders all four notifications and asserts AC2, AC3, AC4 and AC5 across every one of them, so a fifth email added by TM-57 or a later epic inherits the rules instead of re-arguing them.

**Not in scope, and each belongs to a named story.** **No `tries`, `backoff`, `timeout`, `retryUntil` or `ShouldQueueAfterCommit`** — TM-57 (E8-S7) owns the retry policy, as Stories 43–47 all recorded. **No new notification, no new recipient, no changed subject line, no changed field list.** **No dark-mode palette, no `prefers-color-scheme` block, no logo image, no inlining library, no `vendor:publish --tag=laravel-mail`.** **No localisation or `@lang()`** — nothing in the backlog asks for it and every string in these templates is English today. **No change to `MailSafety`, `config/notifications.php`, `config/mail.php` or `.env.example`** — AC4's three values are already configured; this story proves it rather than moving it.

---

## Decision — Blade layout inheritance, not `MailMessage->markdown()` and not published vendor views

`@extends('mail.layout')` / `@section('content')` in the HTML view, and the same in the text view against `mail.layout-text`.

- **Measured, end to end.** A layout + child pair rendered through `MailMessage->view(['child', 'child-text'], …)` produced `html_len=1532` and `text_len=138`, with the media query, `max-width:600px`, the summary rows and the child's content all present in the HTML, and this **exact** text part:
  ```
  "Ticket Management\n\nHello Dana,\n\nReference: TKT-2026-000042\nSubject: Printer offline\n\nline one\nline two **not bold**\n\n-- Ticket Management\n"
  ```
  Header from `config('app.name')`, footer from `config('app.name')`, one line per summary row, no stray blank lines, and `**not bold**` still literal. **`@extends` and `@include` work identically in a text template** — that is the whole basis of tasks 2 and 3.
- **The markdown pipeline is not an option here and stays retired.** Stories 45, 46 and 47 each measured why: `->line()` collapses a user's newlines into one paragraph and turns their `[text](url)` into a live link. Descriptions, resolution notes and escalation reasons are all free text. **After this story, `->line()`, `->action()` and `->markdown()` must not appear in any notification** — task 10's grep is the tripwire.
- **Publishing the framework's mail views was rejected.** `vendor:publish --tag=laravel-mail` drops ~15 files of table markup and a theme CSS file into `resources/views/vendor/mail/`, all of it serving a markdown renderer this project no longer uses. Two files of our own, at 1.5 KB rendered, are less to own and less to misread.
- **A size note, since it is a real improvement rather than a wash.** The default notification template rendered at **`html_len=11676`** (measured for Story 44); the layout in task 1 renders the same email at roughly **1.5 KB**. Gmail clips at ~102 KB, so neither is near a limit — but 1.5 KB is a page a human can read in full when something looks wrong.

## Decision — the summary partial owns presentation and never content policy

`@include('mail.partials.summary', ['rows' => [...]])`, where `$rows` is a `label => value` map the **mailable** builds.

- **This is what keeps Stories 45 and 46 passing.** Both deliberately exclude priority, status and category from a **requester's** email — Story 45's decision says the confirmation *"says nothing the requester did not tell us"*, and both have tests asserting those names are absent. **A summary partial that decided for itself what a ticket summary contains would break four tests and one acceptance criterion on the day it was written.**
- **Presentation is genuinely shared and worth sharing:** the two-column table, the label colour, the `white-space: nowrap` on labels, the row padding, and the mobile behaviour. That is the part all four emails were duplicating.
- **The four callers therefore pass different rows**, and that is correct rather than a smell:
  | Notification | Rows |
  |---|---|
  | `TicketAssignedNotification` (staff) | Reference, Subject, Priority, Category, Requester |
  | `TicketCreatedNotification` (requester) | Reference, Subject |
  | `TicketStatusChangedNotification` (requester) | Reference, Subject, Status |
  | `TicketEscalatedNotification` (admin) | Reference, Subject, Escalation level, Priority, Requester, Now assigned to |
- **The free-text block is not a summary row.** A description, a resolution note or an escalation reason goes in the `<pre>` block each view already owns, because it needs `white-space: pre-wrap` and a full-width column. **Do not fold it into `$rows`.**

## Decision — the layout carries no link, in the header or the footer

- **Stories 45 and 46 both ship a test asserting a requester email contains no URL at all** — `test_it_contains_no_link_to_the_application` asserts neither part contains `localhost:5173`, `config('app.frontend_url')` or `/tickets/`. **A footer link in the shared layout breaks both on the day it is written.**
- **The reason is a product fact, not a rendering preference.** Story 45's decision: a requester has **no login**, `POST /api/v1/tickets` is behind `auth:sanctum`, and there is no portal — so a link would land them on a login page they can never pass.
- **The default notification template linked the header to `config('app.url')`** — measured for Story 44, its text part opened `Ticket Management: http://localhost:8000`. **That is the API base URL, not the SPA.** Moving off it is a correctness fix, not just a restyle: no email should ever have pointed a human at port 8000.
- **AC4's "base URL comes from configuration" is discharged by the two staff emails**, which build `{frontend_url}/tickets/{id}` from `config('app.frontend_url')` (Story 44's decision, reused by Story 47), plus **task 10's grep asserting no mail view or notification contains an `http://` or `https://` literal**. A base URL that is used in two of four emails and hardcoded in none satisfies the criterion; a footer link that breaks two others does not.

## Decision — TM-52's notification moves onto views, and two of its tests change

`TicketAssignedNotification::toMail()` currently returns a `MailMessage` built from `->greeting()`, seven `->line()` calls and `->action('View the ticket', $url)`. Task 5 replaces that with `->view(['mail.tickets.assigned', 'mail.tickets.assigned-text'], [...])`.

- **AC1 says *"every notification extends"* the layout.** Leaving one on the framework's markdown template would leave the criterion unmet and the product visibly inconsistent — one email in a different typeface, with a different header, linking to a different host.
- **Story 44 anticipated this in writing:** *"TM-56 is not blocked by this. A `Notification::toMail()` may return a `MailMessage` today and a `->view('mail.layout…')` later, with no change to the listener, the event or any test that asserts recipients."* **Recipient tests are safe; two content tests are not.**
- **Exactly two tests break, and task 9 names them.** `MailMessage::view()` sets `$this->markdown = null` and the message no longer carries an action, so **`$mail->actionUrl` becomes `null`** — which is asserted directly by Story 44's `test_it_links_to_the_spa_ticket_page_by_numeric_id` and `test_a_trailing_slash_on_the_frontend_url_does_not_double`. Both become assertions on the **rendered** parts. **Amend them; do not delete them, and do not leave a second copy in this story's own file.**
- **`test_it_renders_a_plain_text_part_alongside_the_html` survives unchanged** — it asserts both parts are non-empty. Only its comment, which records `html_len=11676`, is now wrong; task 9 updates the number.

## Decision — one fixed-width table, one media query, and "degrades without CSS" is a test

- **A 600px table with `max-width:600px`**, centred by a full-width wrapper table with `align="center"`. Tables and `align` rather than flexbox or grid, because Outlook's Word rendering engine supports neither.
- **Inline styles for everything that matters**, plus a single `<style>` block holding one `@media only screen and (max-width: 600px)` rule that makes the card fluid and reduces its padding. **Gmail strips `<style>` on some clients**, which is exactly why the desktop appearance lives in the inline attributes and only the *mobile improvement* lives in the block.
- **A system font stack, no web font, no `<link>`, no image.** `-apple-system, "Segoe UI", Arial, sans-serif`.
- **"Degrades gracefully without CSS" is asserted, not asserted-to.** Test 14.4 strips every `style` attribute and the `<style>` block and then asserts every summary label and value, the free-text block and the link URL are all still present as text. Test 14.5 asserts the markup contains **no** `display:none`, `visibility:hidden`, `font-size:0`, `background-image:` or `mso-hide` — the five ways a template hides content from a CSS-less reader.
- **No colour carries meaning.** Story 36 already established the rule for the escalation badge — *"colour alone would fail the criterion for a colour-blind reader"* — and Story 47 carries the escalation level as text and as a header. **The layout must not introduce a colour-only signal.**

---

## Context — Read These Files First

1. [`44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md`](44-story-notify-an-agent-when-a-ticket-is-assigned-TM-52.md) — **its task 4** (`toMail()` with `->greeting()`, the seven `->line()` calls and `->action()`) and **its test file 8**, tests **3**, **4** and **6**. Tasks 5 and 9 are written against those exact lines.
2. [`45-story-confirm-ticket-creation-to-the-requester-TM-53.md`](45-story-confirm-ticket-creation-to-the-requester-TM-53.md) — **its task 5**, the first view pair, and **its decision on `{{ }}` versus `{!! !!}`**. That rule is not re-derived here and must survive the refactor untouched. Its test **10.7** (`test_it_contains_no_link_to_the_application`) is the one the layout must not break.
3. [`46-story-notify-the-requester-on-status-change-and-resolution-TM-54.md`](46-story-notify-the-requester-on-status-change-and-resolution-TM-54.md) — **its task 6**, the second view pair, including the `@if (filled($resolution))` block and the `@if`-inside-text-template whitespace shape.
4. [`47-story-notify-admins-on-escalation-TM-55.md`](47-story-notify-admins-on-escalation-TM-55.md) — **its task 7**, the third view pair, and its note that the call-to-action is *"a plain `<a>` in the HTML part and a bare URL in the text part. No button component — TM-56 owns anything shared."* Task 4 is that component.
5. `backend/config/mail.php` — **`'from' => ['address' => env('MAIL_FROM_ADDRESS', …), 'name' => env('MAIL_FROM_NAME', env('APP_NAME', …))]` at 113–116.** AC4's first two values, already configured. **This file is not edited.**
6. `backend/config/app.php` — `'name'` at **16** and `'frontend_url'` (added by Story 44's task 1) after **29**. AC4's other two values.
7. `backend/.env.example:51–60` — the Mailpit block with `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. **Not edited**; read it so you can see AC4 is already satisfied at the source.
8. `backend/resources/views/welcome.blade.php` — the only Blade file in the project today, and **not** a model for anything here: it is a full-page marketing template with a `<style>` sheet meant for a browser.
9. `backend/package.json` — Tailwind and Vite, for Blade-side **browser** assets. **Nothing in `resources/views/mail/` may reference them.**

---

## Product rules (from story)

| Concern | Before this story | After |
|---|---|---|
| TM-52's email | Framework markdown template, header linked to `config('app.url')` (the **API**) | `mail.layout`, no header link |
| TM-53, TM-54, TM-55 emails | Three standalone view pairs, duplicated markup | All three `@extends('mail.layout')` |
| Header | Four different ones | One, `config('app.name')`, no link |
| Footer | Three ad-hoc `— {{ config('app.name') }}` lines plus the framework's | One, in the layout |
| Ticket summary | `<p>Label: value</p>` repeated per view | `@include('mail.partials.summary', ['rows' => …])` |
| Call to action | A framework button in TM-52, a bare `<a>` in TM-55 | `@include('mail.partials.link', …)` |
| Which fields each email shows | Decided per story | **Unchanged** — the partial takes rows, never chooses them |
| Mobile width | Framework default for one, unstyled for three | One `@media` rule, fluid below 600px |
| Without CSS | Untested | Every label, value, note and URL still present as text — tested |
| Plain-text part | True for all four, tested per story | Asserted once for all four |
| From address / product name | `config('mail.from.*')`, `config('app.name')` | **Unchanged**, asserted once |
| Base URL | `config('app.frontend_url')` in two emails | **Unchanged**, plus a no-literal-URL grep |
| Internal notes, staff addresses, stack traces | Asserted per story | Asserted once for all four |

---

## Backend Tasks

### 1 — The HTML layout

**Create file: `backend/resources/views/mail/layout.blade.php`**

```blade
{{--
    The one HTML mail layout. Every notification's HTML view extends this.

    Tables and align=center, not flexbox or grid: Outlook renders with Word.
    Desktop appearance lives in inline style attributes because Gmail strips
    <style> in some contexts; the block below carries only the mobile
    improvement, so losing it degrades to a fixed 600px card rather than to
    unstyled text.

    No link in the header or footer. TM-53 and TM-54 both assert that a
    requester's email contains no URL at all, because a requester has no login.
    Staff emails build their own link from config('app.frontend_url').

    No image, no web font, no Tailwind class, no @vite. A mail client runs no
    build step and fetches no stylesheet.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ config('app.name') }}</title>
<style>
    @media only screen and (max-width: 600px) {
        .tm-card { width: 100% !important; padding: 20px !important; }
    }
</style>
</head>
<body style="margin:0; padding:0; background:#f4f4f5;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" class="tm-card" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px; background:#ffffff; border:1px solid #e4e4e7; padding:32px;">
        <tr><td style="font:600 18px/1.3 -apple-system,'Segoe UI',Arial,sans-serif; color:#18181b; padding-bottom:20px;">{{ config('app.name') }}</td></tr>
        <tr><td style="font:400 15px/1.6 -apple-system,'Segoe UI',Arial,sans-serif; color:#18181b;">@yield('content')</td></tr>
        <tr><td style="font:400 13px/1.5 -apple-system,'Segoe UI',Arial,sans-serif; color:#71717a; padding-top:24px; border-top:1px solid #e4e4e7;">&mdash; {{ config('app.name') }}</td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
```

**The `.tm-card` class is the only class in the project's mail templates**, and it is referenced by exactly one rule. **Do not add a second class without a matching rule** — a class no stylesheet defines is dead weight in a medium where the stylesheet may not arrive.

### 2 — The text layout

**Create file: `backend/resources/views/mail/layout-text.blade.php`**

```blade
{{ config('app.name') }}

@yield('content')
-- {{ config('app.name') }}
```

**Measured output shape**, with a child yielding `Hello Dana,` and two summary rows:

```
"Ticket Management\n\nHello Dana,\n\nReference: TKT-2026-000042\nSubject: Printer offline\n\nline one\nline two **not bold**\n\n-- Ticket Management\n"
```

- **Blank lines are load-bearing here.** The one after the header and the one the child's section ends with produce the separation above. **Do not "tidy" the whitespace** — the text part is the only thing some readers see.
- **No `@yield` default and no `{{-- --}}` header comment.** A Blade comment at the top of a *text* layout is invisible in the output but the file is short enough not to need one; the HTML layout carries the explanation for both.

### 3 — The summary partials

**Create file: `backend/resources/views/mail/partials/summary.blade.php`**

```blade
{{--
    The ticket-summary block. $rows is a label => value map built by the
    NOTIFICATION, never by this file: TM-53's and TM-54's emails deliberately
    omit priority, status and category, and both have tests asserting it. This
    partial owns how a summary looks, never what a summary contains.

    Free text -- a description, a resolution note, an escalation reason -- is
    NOT a row. It needs pre-wrap and a full-width column, and each view keeps
    its own <pre> block for it.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px; font:400 15px/1.6 -apple-system,'Segoe UI',Arial,sans-serif;">
@foreach ($rows as $label => $value)
    <tr>
        <td valign="top" style="padding:4px 16px 4px 0; color:#71717a; white-space:nowrap;">{{ $label }}</td>
        <td valign="top" style="padding:4px 0; color:#18181b;">{{ $value }}</td>
    </tr>
@endforeach
</table>
```

**Create file: `backend/resources/views/mail/partials/summary-text.blade.php`**

```blade
@foreach ($rows as $label => $value)
{{ $label }}: {{ $value }}
@endforeach
```

- **`{{ }}` in both.** Values reaching a summary row are names and references, but a requester name and a ticket subject are both user-supplied and must be escaped in the HTML part. **The `{!! !!}` exception exists only for the free-text `<pre>` block in a text view** — Story 45's rule, and it does not extend to rows.
- **`white-space: nowrap` on the label column only.** It is what keeps `Now assigned to` from wrapping into two lines and misaligning the table.
- **The text partial's `@foreach` sits flush left** — measured, this yields exactly one `Label: value` line per row with no leading spaces.

### 4 — The call-to-action partials

**Create file: `backend/resources/views/mail/partials/link.blade.php`**

```blade
{{--
    The one call-to-action shape. TM-55 deferred this here by name.

    A styled anchor rather than a table-cell button: it renders in every client,
    it is still a link with CSS stripped, and the href is visible to a reader
    who cannot click it because the text part carries the bare URL.
--}}
<p style="margin:0 0 16px;"><a href="{{ $url }}" style="color:#2563eb; text-decoration:underline;">{{ $text }}</a></p>
```

**Create file: `backend/resources/views/mail/partials/link-text.blade.php`**

```blade
{{ $text }}: {{ $url }}
```

**Only TM-52's and TM-55's emails include these.** TM-53's and TM-54's must not — see the decision, and their own tests enforce it.

### 5 — Move TM-52's email onto views

**File: `backend/app/Notifications/TicketAssignedNotification.php`**

Replace the body of `toMail()` — everything after the `$ticket = $this->ticket;` line — with:

```php
        return (new MailMessage)
            ->subject(sprintf('[%s] Assigned to you: %s', $ticket->reference, Str::limit($ticket->subject, 60)))
            ->view(
                ['mail.tickets.assigned', 'mail.tickets.assigned-text'],
                [
                    'agentName' => $notifiable->name,
                    'rows' => [
                        'Reference' => $ticket->reference,
                        'Subject' => $ticket->subject,
                        'Priority' => $ticket->priority->name,
                        'Category' => $ticket->category?->name,
                        'Requester' => sprintf('%s (%s)', $ticket->requester->name, $ticket->requester->email),
                    ],
                    'reason' => $this->reason,
                    'ticketUrl' => $this->ticketUrl($ticket),
                ],
            );
```

**Remove the `->greeting()`, the seven `->line()` calls and the `->action()` call.** `ticketUrl()` and the subject line are unchanged. **`Str::limit` stays; `substr()` must not appear.**

**Create file: `backend/resources/views/mail/tickets/assigned.blade.php`**

```blade
@extends('mail.layout')

@section('content')
<p>Hello {{ $agentName }},</p>

<p>A ticket has been assigned to you.</p>

@include('mail.partials.summary', ['rows' => $rows])

@if (filled($reason))
    <p>Handover note:</p>

    <pre style="white-space:pre-wrap; word-break:break-word; font-family:inherit; margin:0 0 16px;">{{ $reason }}</pre>
@endif

@include('mail.partials.link', ['text' => 'View the ticket', 'url' => $ticketUrl])

<p>You are receiving this because the ticket is now assigned to you.</p>
@endsection
```

**Create file: `backend/resources/views/mail/tickets/assigned-text.blade.php`**

```blade
@extends('mail.layout-text')

@section('content')
Hello {{ $agentName }},

A ticket has been assigned to you.

@include('mail.partials.summary-text', ['rows' => $rows])
@if (filled($reason))

Handover note:

{!! $reason !!}
@endif

@include('mail.partials.link-text', ['text' => 'View the ticket', 'url' => $ticketUrl])

You are receiving this because the ticket is now assigned to you.

@endsection
```

**The handover note gains a `<pre>` block it did not have.** Under `->line()` it was one more paragraph and its newlines collapsed — the same defect Stories 45, 46 and 47 each measured for their own free text. **Fixing it here is part of the unification, not scope creep**, and Story 44's `test_the_handover_reason_appears_only_when_one_was_given` still passes because the line is still absent when the reason is `null`.

### 6, 7 and 8 — Re-parent the three existing pairs

For each of **`created`**, **`status-changed`** and **`escalated`**:

**HTML view** — replace the leading `{{-- … --}}` comment and the `<p>Hello …</p>` opening with:

```blade
@extends('mail.layout')

@section('content')
```

…keep the body exactly as its story wrote it, **delete the trailing `<p>&mdash; {{ config('app.name') }}</p>`** (the layout supplies it), and close with `@endsection`.

**Text view** — same shape against `mail.layout-text`, deleting the trailing `-- {{ config('app.name') }}` line.

Then, in each pair, replace the run of `<p>Label: {{ $value }}</p>` lines with one `@include('mail.partials.summary', ['rows' => …])`, building the map in the **notification** rather than the view:

| Notification | `rows` |
|---|---|
| `TicketCreatedNotification` | `Reference`, `Subject` |
| `TicketStatusChangedNotification` | `Reference`, `Subject`, `Status` → `"{$fromStatus} → {$toStatus}"` |
| `TicketEscalatedNotification` | `Reference`, `Subject`, `Escalation level`, `Priority`, `Requester`, `Now assigned to` |

**Four things must not change while re-parenting:**

- **The `<pre>` blocks stay** — description, resolution note, escalation reason. They are free text, not rows.
- **`{{ }}` in HTML views and `{!! !!}` on free text in text views stays** — Story 45's measured rule.
- **TM-55's escalation "level > 1" line stays exactly as Story 47 wrote it**, including never rendering `×1`.
- **TM-53's and TM-54's views gain no link and no `link` partial.**

### 9 — Amend TM-52's two broken tests

**File: `backend/tests/Feature/Notifications/TicketAssignedMailTest.php`** *(Story 44's test file 8)*

- **`test_it_links_to_the_spa_ticket_page_by_numeric_id`** — `$mail->actionUrl` is now `null`. Replace the assertion with one against the **rendered** parts: both contain `"https://helpdesk.test/tickets/{$ticket->getKey()}"`, and **the reference still does not appear inside the URL**. Keep the `config()->set('app.frontend_url', 'https://helpdesk.test')` setup and the test name.
- **`test_a_trailing_slash_on_the_frontend_url_does_not_double`** — same change, asserting the rendered URL rather than `actionUrl`.
- **`test_it_renders_a_plain_text_part_alongside_the_html`** — the assertions stand. **Update its comment**: the measured `html_len=11676` was the framework template's; the layout renders this email at roughly 1.5 KB.
- **Every other test in the file is untouched**, including tests 1, 5, 7, 8, 9 and 10, all of which assert against rendered output already.

**Amend in place. Do not delete these tests and do not copy them into this story's own test file.**

### 10 — The grep tripwires

Add to **`backend/tests/Feature/Notifications/MailLayoutTest.php`** (task 11's file) two tests that read the source tree rather than a rendered message:

- **No hardcoded URL:** every file under `backend/resources/views/mail/` and `backend/app/Notifications/` contains **no** `http://` or `https://` literal. **AC4** — the base URL comes from `config('app.frontend_url')` or it does not exist.
- **No markdown pipeline:** no file in `backend/app/Notifications/` contains `->line(`, `->action(`, `->greeting(` or `->markdown(`. **AC1 and AC3** — the day one reappears is the day one email stops extending the layout.

### 11 — Document it

**File: `docs/api-contract.md`**

Append one paragraph to the `Notifications` section:

```markdown
Every notification renders through `resources/views/mail/layout.blade.php` and
its plain-text twin `layout-text.blade.php`, which supply the header, the footer
and a 600px card that goes fluid below 600px. Ticket fields are rendered by
`mail/partials/summary.blade.php` from a `label => value` map each notification
builds, so a requester's email and an admin's email share the markup without
sharing a field list. Neither layout contains a link: staff emails build their
own from `config('app.frontend_url')`, and requester emails carry none because a
requester has no login. The from address comes from `config('mail.from')`, the
product name from `config('app.name')`, and no mail template contains a literal
URL.
```

**No frontend changes required.** **No README change.**

---

## Edge Cases & Failure Modes

- **The refactor's real risk is a green suite that stopped proving anything.** Stories 44–47 ship roughly 45 content assertions between them. **Record the pass count before task 1 and compare after task 9** — a drop of two with two amended tests is right; a drop of ten is a view that stopped rendering a field.
- **A missing `@section` renders an empty body, silently.** Blade does not error on a child that extends a layout and defines no matching section. **Test 14.1 asserts every part is non-empty for all four notifications**, which is the only thing standing between a mistyped `@section('body')` and four blank emails.
- **`@include` inside `@section` inside a text layout is whitespace-sensitive.** Measured working with the partial's `@foreach` flush left. **If a re-parented text view suddenly grows blank lines between summary rows, the indentation of the partial is why** — not the layout.
- **Gmail strips `<style>` in the forwarded and clipped views.** Losing the block leaves a fixed 600px card, which is readable on a phone in the way any fixed-width email is. **That is the deliberate degradation** and it is why no desktop styling lives in the block.
- **Outlook ignores `max-width`.** The `width="600"` attribute and the `width:600px` inline style are what hold the card together there; `max-width` is for the clients that honour it. **Keep all three.**
- **A summary value that is `null`.** `TicketAssignedNotification` passes `$ticket->category?->name`, which is `null` when a category has been soft-deleted and not loaded `withTrashed()`. The partial renders an empty cell rather than failing. **Story 44's `test_it_names_a_soft_deleted_category` covers the load; this is the render-side fallback.**
- **A summary label collides with another.** `$rows` is a PHP map, so two rows labelled `Priority` silently become one. All four current callers use distinct labels; **a fifth notification adding a duplicate would lose a row without an error.** Recorded rather than guarded — a guard would cost a value object for a four-caller partial.
- **A very long summary value** — a 255-character ticket subject in a `nowrap`-free value column — wraps normally. **The `nowrap` is on the label column only**; putting it on the value column would produce a horizontally scrolling email.
- **Arabic, emoji and RTL text.** `utf8mb4` throughout, Blade escapes UTF-8 without transcoding, and the layout sets `<meta charset="utf-8">`. **No `dir="rtl"` handling** — nothing in the backlog asks for it, and the browser and mail-client defaults handle mixed content acceptably. Recorded as a known limit.
- **`php artisan view:cache` compiles the templates.** Editing one after caching needs `php artisan view:clear`. CI runs neither and `composer test` runs `config:clear`, so the suite is immune; this is a local note, the same one Story 45 recorded.
- **A fifth email added later.** It inherits AC2–AC5 only if it is added to `MailLayoutTest`'s notification table. **Task 11's docblock must say so**, or the suite quietly stops covering the product.
- **`MailSafety` is untouched and still applies.** `phpunit.xml:44`'s `array` transport is a safe transport, so no test here opens a socket, and no rendering change can reach a real inbox.

---

## Test Plan

**14. `backend/tests/Feature/Notifications/MailLayoutTest.php`** (new, `RefreshDatabase`) — the cross-cutting suite. It builds **all four** notifications from one fixture and renders each through the array transport, so every assertion runs four times.

```php
/**
 * Add every new notification to this list. AC2 through AC5 are enforced here
 * for the whole product; a notification absent from this array is a
 * notification nobody is checking.
 */
private function notifications(Ticket $ticket, User $agent, User $admin): array
// => ['assigned' => [$notification, $agent], 'created' => [...], 'status' => [...], 'escalated' => [...]]

private function parts(Notification $n, object $notifiable): array   // [$html, $text]
```

The fixture ticket is built **hostile on purpose**: assigned to an agent whose address is `leak-me@staff.test`, created by a second agent, carrying an `escalation_reason`, a non-default priority, a soft-deleted category, a `ticket_activities` row whose `meta` holds `INTERNAL-NOTE-CANARY`, and a requester with a multibyte name.

1. `test_every_notification_renders_a_non_empty_html_and_text_part` — **AC3**, and the guard against a mistyped `@section`.
2. `test_every_notification_uses_the_shared_layout` — both parts of each contain the header and footer rendered from `config('app.name')`; the HTML part contains `<!DOCTYPE`, `class="tm-card"` and `max-width:600px`. **AC1.**
3. `test_every_notification_carries_the_mobile_rule` — the HTML part contains `@media only screen and (max-width: 600px)` and `width: 100% !important`. **AC2.**
4. `test_every_notification_is_readable_with_css_stripped` — **AC2, the real one.** Strip the `<style>` block and every `style="…"` attribute, then assert every summary label, every summary value, the free-text block's content and any link URL are still present. **A test that renders and squints proves nothing; this one deletes the CSS and checks the content survived.**
5. `test_no_notification_hides_content_from_a_css_less_reader` — **AC2.** No part contains `display:none`, `visibility:hidden`, `font-size:0`, `background-image:` or `mso-hide`.
6. `test_the_from_address_and_name_come_from_configuration` — **AC4.** `config()->set('mail.from', ['address' => 'desk@configured.test', 'name' => 'Configured Desk'])`; every built message's `From` matches both. Then set `config('app.name')` and assert the header and footer follow it.
7. `test_no_mail_template_or_notification_contains_a_literal_url` — **AC4, task 10's first tripwire.** Scan `backend/resources/views/mail/` and `backend/app/Notifications/` for `http://` and `https://`; assert none. **Failure message must name the offending file.**
8. `test_no_notification_uses_the_markdown_pipeline` — **AC1/AC3, task 10's second tripwire.** No `->line(`, `->action(`, `->greeting(` or `->markdown(` under `backend/app/Notifications/`.
9. `test_no_notification_leaks_an_internal_note` — **AC5.** `INTERNAL-NOTE-CANARY` appears in no part of any of the four.
10. `test_no_notification_leaks_a_staff_email_address` — **AC5.** `leak-me@staff.test` and the second agent's address appear in no **body**. **Assert bodies only** — the recipient's own address legitimately sits in the `To` header, and asserting on headers would fail the assignment email for the wrong reason.
11. `test_no_notification_can_render_a_stack_trace` — **AC5.** No part contains `Stack trace:`, `#0 /`, `vendor/laravel` or `.php:`. A tripwire for any future template that interpolates an exception or a debug dump.
12. `test_the_requester_facing_emails_still_carry_no_link` — the `created` and `status` parts contain no `http`, no `/tickets/` and no `config('app.frontend_url')` value. **Duplicating Stories 45 and 46 on purpose**: this is the assertion the shared layout is most likely to break, and it should fail here as well as there.
13. `test_the_staff_facing_emails_still_carry_their_link` — the `assigned` and `escalated` parts contain `{frontend_url}/tickets/{id}` exactly once each.
14. `test_the_summary_block_shows_only_what_each_notification_passes` — the requester emails contain **no** priority name, status-vocabulary-free field labels aside, no category name; the staff emails **do**. **The single test that proves the partial owns presentation and not policy.**

**Amended, not added:** the two tests named in task 9, in `TicketAssignedMailTest.php`.

**Untouched:** every recipient, queueing, delay, suppression, event and listener test in Stories 44–47. **If any of them changes, the refactor has changed behaviour and has gone wrong.**

---

## Verification Steps

1. **Record the baseline first.** From `backend/`, `composer test` and **write down the pass/fail counts** before editing anything. This story's success criterion is "same counts, minus the two amended assertions".
2. **Services up:** from the repo root, `docker compose up -d --wait`.
3. **Backend tests:** `composer test`. Expect the three long-standing pre-existing failures and **nothing else**; the counts match step 1.
4. **The four mail suites explicitly:** `php artisan test --filter='MailLayoutTest|TicketAssignedMailTest|TicketCreatedMailTest|TicketStatusChangedMailTest|TicketEscalatedMailTest'`.
5. **Formatting:** `./vendor/bin/pint --test` exits `0`.
6. **See all four in Mailpit.** Trigger one of each — assign, create, resolve with a note, escalate — and run `php artisan queue:work --stop-when-empty`. **All four must share a header, a footer and a card**, and only the assignment and escalation emails may contain a link.
7. **Mobile width, by eye.** In Mailpit, open each HTML tab and narrow the browser below 600px. **The card must go full width and its padding must shrink**; nothing may scroll horizontally.
8. **Without CSS, by eye.** In Mailpit, use the message's **Source** tab (or save the HTML and open it with styles disabled). **Every field, note and URL must still be readable in a sensible order.** This is the human half of test 14.4.
9. **AC4 by hand.** Set `MAIL_FROM_ADDRESS=desk@example.test`, `MAIL_FROM_NAME="Support Desk"` and `APP_NAME="Acme Helpdesk"` in `backend/.env`, `php artisan config:clear`, resend. **All four emails change sender and header together, with no template edit.** Restore.
10. **The tripwires trip.** Add `<a href="https://example.com">x</a>` to `mail/layout.blade.php` and run `php artisan test --filter=test_no_mail_template_or_notification_contains_a_literal_url` — **it must fail**, and its message must name the file. Then add a `->line('x')` back into any notification and run `test_no_notification_uses_the_markdown_pipeline` — **it must fail**. Revert both.
11. **The layout is genuinely shared.** Change the footer text in `mail/layout.blade.php` and confirm **all four** Mailpit messages change. Revert.
12. **Regression:** `git diff --stat` touches only the eight new files under `backend/resources/views/mail/`, the four existing view pairs, `backend/app/Notifications/TicketAssignedNotification.php` (plus the three others' `rows` maps), `backend/tests/Feature/Notifications/TicketAssignedMailTest.php`, the new `MailLayoutTest.php` and `docs/api-contract.md`. **No migration, no route, no controller, no event, no listener, no config file, no `.env.example`, no `frontend/` file.**

---

## Done Criteria

- [ ] One HTML layout and one text layout supply the header and footer for **all four** notifications, and a `summary` partial renders every ticket field block. *(AC1)*
- [ ] `TicketAssignedNotification` no longer uses `MailMessage->line()`/`->action()`; **no notification does**, enforced by a source-scanning test. *(AC1)*
- [ ] The HTML renders as a 600px card that goes fluid below 600px via one `@media` rule, with the desktop appearance in inline styles so a stripped `<style>` degrades rather than breaks. *(AC2)*
- [ ] With every `style` attribute and the `<style>` block removed, every label, value, free-text block and URL is still present — asserted, not claimed — and no template hides content with `display:none`, `visibility:hidden`, `font-size:0`, `background-image` or `mso-hide`. *(AC2)*
- [ ] All four notifications render a non-empty plain-text part alongside the HTML. *(AC3)*
- [ ] The from address and name come from `config('mail.from')`, the product name from `config('app.name')`, the base URL from `config('app.frontend_url')`, and **no mail template or notification contains a literal `http://` or `https://`**. *(AC4)*
- [ ] No email body carries an internal note, a staff email address or anything resembling a stack trace — asserted once across all four against a deliberately hostile fixture. *(AC5)*
- [ ] Requester emails still carry **no** link; staff emails still carry exactly one. The summary partial still shows only the fields each notification passes it.
- [ ] Exactly two of Story 44's tests were **amended in place** (`actionUrl` → rendered URL) and no other test in Stories 44–47 changed. Test counts match the baseline recorded in verification step 1.
- [ ] No new notification, recipient, subject, delay, retry policy, migration, route, config file or frontend file — TM-57 still owns the retry policy.
- [ ] `composer test` shows the same pre-existing failures and no new ones; `./vendor/bin/pint --test` exits `0`.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 49.**
