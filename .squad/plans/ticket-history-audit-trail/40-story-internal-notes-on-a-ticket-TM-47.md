# Story 40 — Internal notes on a ticket (Story: TM-47)

## Prerequisites

- **Story 39 (TM-46) must land first.** This story writes a `note_added` activity row and renders its body **inside Story 39's timeline**. It creates none of that machinery: `GET /api/v1/tickets/{ticket}/activities`, `TicketActivityResource`, `frontend/src/api/activities.ts`, `frontend/src/lib/activityEvents.ts`, `frontend/src/lib/activityProse.ts`, `frontend/src/components/TicketTimeline.vue` and `TicketTimelineEntry.vue` are **all Story 39's** ([`39-story-ticket-timeline-TM-46.md`](39-story-ticket-timeline-TM-46.md)). **Verify before starting:** `ls frontend/src/lib/activityProse.ts frontend/src/components/TicketTimelineEntry.vue backend/app/Http/Resources/V1/TicketActivityResource.php`. If any is missing, Story 39 has not landed — **stop**.
- **Story 38 (TM-45) must land first**, transitively. This story's `record()` call passes **two** keys and relies on `recordMany()` filling `field`, `old_value` and `new_value` as `null` — which is Story 38's task 1. Before that fix an omitted key wrote the literal string `"null"` into the `json` column. **`grep -n "'meta' => \[\]" backend/app/Services/ActivityRecorder.php`** must show the defaults inside `recordMany()`.
- **Story 37 (TM-43) explicitly delegates a product decision to this story, and this story answers it.** Its plan, **line 50**: *"an agent who spends a week investigating and writing notes without changing a field will see the ticket flagged. **If the team decides notes should count, the change is one `orWhereExists` in task 3's query and TM-47 is where that conversation belongs**."* **Read the staleness decision below before touching anything.** The answer is no, and the reason is this story's own fourth criterion.
- **AC3 is already owned twice, by stories that do not exist.** *"Notes are internal only and are never included in any email to a requester"* is **E8-S3's fifth criterion** (*"No internal notes or agent-only information appear in the email"*) and **E8-S6's fifth** (*"No email leaks internal notes, agent email addresses or stack traces"*). **There is no mail code in this repository at all** — `backend/app/Mail`, `app/Notifications`, `app/Events`, `app/Listeners`, `app/Jobs` and `resources/views/mail` **all do not exist** (verified while planning). AC3 is therefore true by construction; task 9's test 8 makes it a **tripwire** rather than an assumption. See the decision.
- **`note_added` is this story's one new enum case.** `backend/app/Enums/TicketActivityEvent.php` (14 lines) has `Created` (**7**) and `CategoryChanged` (**8**). The `event` column is `varchar(50)` (`…create_ticket_activities_table.php:15`), so `'note_added'` needs **no migration**.
- **`TrimStrings` and `ConvertEmptyStringsToNull` are both active**, verified in `vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php:461–462` — `bootstrap/app.php`'s `withMiddleware` closure (**21–28**) only adds aliases and priority entries, it does not replace the global stack. **A whitespace-only body therefore becomes `null` before validation runs and fails `required` with no custom rule.** Task 2 depends on this; test 4 pins it.
- **There is still no `TicketFactory`.** `backend/database/factories/` holds **only `UserFactory.php`**; TM-59 owns the rest. Task 9 reuses the `makeTicket()` helper shape Story 39's test plan defines.
- **`RouteAuthorizationTest::ACCESS` gains one key.** By the time this story runs, Story 39 has classified `tickets.activities` and `tickets.store`, so `tickets.notes` is the only addition and the suite should carry exactly **one** pre-existing failure (`PasswordThrottleTest`, TM-14).
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No migration, no new column, no index change, no new composer package, no new npm package.**

---

## What this story does not build, and who owns it

| Deferred | Owner |
|---|---|
| Editing or deleting a note | **Nobody — and that is deliberate.** TM-48 makes it structurally impossible. See the decision. |
| Filtering the timeline to notes only | **TM-49** (E7-S5), second criterion — which names notes explicitly |
| Append-more-on-demand on the timeline | **TM-49**, first criterion |
| Any mailable, notification, queue worker, or the note-exclusion rule inside a template | **E8-S3** and **E8-S6**, both sprint 5 |
| Notifying anyone that a note was added | **Nobody in the backlog.** E8-S2…S5 cover assignment, creation, status change and escalation. Named in the PR, not invented here. |
| A `stale` definition that counts notes | **Nobody — declined.** See the staleness decision |
| Requester-visible replies / public comments | **Nobody.** The backlog has no public-comment story; a note is internal, full stop |
| Mentions, attachments, rich text, markdown | **Nobody.** Not in any criterion |

---

## Story Goal

An agent can write down what they found, and the next agent reads it in the ticket's own history rather than in a chat log.

1. `POST /api/v1/tickets/{ticket}/notes` stores the body as a **`note_added`** activity row and returns it — one row, one writer, inside one transaction.
2. The body is **required and length-validated**, and it is stored **verbatim** — escaping happens at render, which is where it can actually be guaranteed.
3. Adding a note changes **nothing** on the `tickets` table: not `status_id`, not `updated_at`, not a timestamp. Story 37's staleness clock keeps ticking, on purpose, and that trade-off is put in front of the backlog owner rather than settled quietly.
4. The note appears in the timeline in correct chronological position, with its own icon, its own colour, and its body rendered as a block — **the first entry type whose payload is the point**.
5. A note is **permanent**. Nothing in this story or any planned story can edit or delete one, and that is said out loud where a user can read it.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Adding a note | No endpoint | `POST /api/v1/tickets/{ticket}/notes` → **`201`** with the created activity |
| Empty, missing or whitespace-only body | — | `422` `required` — whitespace is trimmed to `null` by global middleware |
| Body under 3 or over 5000 characters | — | `422` `min` / `max`, with messages that say why |
| Body containing `<script>` or HTML | — | **Stored byte-identical.** Escaped at render, never on input |
| Body in Arabic or with emoji | — | Stored byte-identical (`JSON_UNESCAPED_UNICODE`, utf8mb4) |
| `tickets.status_id` after a note | — | **Unchanged** |
| `tickets.updated_at` after a note | — | **Unchanged** — so a note does **not** reset the staleness clock (AC4) |
| A note on a Resolved or Closed ticket | — | **Allowed.** Post-mortem context is the point; unlike `escalate()` there is no terminal guard |
| Editing or deleting a note | — | **Impossible**, and the API contract says so |
| Any email to a requester | No mail exists | Still none, and a test fails the build if a note ever sends one |
| A note in the timeline | — | Own icon and colour, body rendered as a block, newest-first position |

---

## Context — Read These Files First

1. `backend/app/Enums/TicketActivityEvent.php` — 14 lines. Cases at **7–8**, `values()` at **10–13**. Task 1 adds **one** case. **This is the only story in E7 that adds one** — Story 38 adds none and Story 39 adds none.
2. `backend/app/Services/ActivityRecorder.php` — `record()` at **13–19** and its five defaults; the transaction guard at **26–28**; `json_encode(…, JSON_UNESCAPED_UNICODE)` at **34**, which is why an Arabic note round-trips unchanged; `TicketActivity::insert($chunk)` at **37**, which returns **no ids** — the reason task 4 re-reads the row. **This file is not edited.**
3. `backend/app/Http/Controllers/Api/V1/TicketController.php` — **`store()` at 30–50 is the shape task 4 copies**: `$actorId = $request->user()->getKey()` (**33**), `DB::transaction(function () use (…) { … })` (**34–47**), `$recorder->record($ticket->getKey(), …, ['user_id' => $actorId, 'meta' => [...]])` (**44**), then a resource with `->setStatusCode(201)` (**49**). Note `authorize()` at **32** sits **outside** the transaction.
4. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php` — the FormRequest convention: `authorize()` via `Gate::allows(...)` (**12–15**), `rules()` returning arrays of rules (**25–41**), `messages()` (**43–47**), and **`'description' => ['required','string','max:16000']` at 34** — the cap task 2 deliberately does not copy.
5. `backend/app/Http/Requests/Api/V1/DestroyCategoryRequest.php` — **the closer precedent for task 2**, because it authorizes against a **route-bound model**: `Gate::allows('delete', $this->route('category'))` at **13**. Task 2 does the same with `$this->route('ticket')`.
6. `backend/app/Policies/TicketPolicy.php` — eight abilities at **10–48**. **Read `escalate()` at 45–48**: `return ! $ticket->status->is_terminal;`. Task 3 adds `addNote()` and **deliberately does not copy that guard** — see the decision.
7. `backend/app/Http/Resources/V1/TicketResource.php:28` — the `can` block, currently four keys, gated by `$request->routeIs('tickets.show')`. Task 5 adds a fifth. **Story 39's plan forbade itself from touching this file; that constraint was Story 39's, not this story's.**
8. `backend/routes/api.php:44–45` plus Story 39's activities route. All inside the `['auth:sanctum','active']` group opened at **32**. Task 6 adds one line.
9. `backend/bootstrap/app.php:21–28` — `withMiddleware` adds **only** aliases and priority entries. It does **not** call `$middleware->use([...])`, so Laravel's default global stack applies, which is why `TrimStrings` and `ConvertEmptyStringsToNull` are live. Confirm with `grep -n "TrimStrings" backend/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php` → **line 461**.
10. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `self::ACCESS` at **16**, `test_every_api_route_is_classified` at **18–25**. Task 7 adds `'tickets.notes' => 'staff'`.
11. [`39-story-ticket-timeline-TM-46.md`](39-story-ticket-timeline-TM-46.md) — **tasks 2, 8, 9, 10 and 11, and the decision headed *"one event map with a real fallback"*.** Specifically:
    - Task 2's `TicketActivityResource` — `'meta' => $meta` where `$meta = $this->meta ?? []`, and **`'actor' => $this->whenLoaded('user', …)`**, which returns `MissingValue` and **drops the key entirely** when the relation is not loaded. **Task 4 must eager-load `user` on the row it returns.**
    - Task 8's `activityEvents.ts` — `DESCRIPTORS`, `FALLBACK`, `eventDescriptor()`. Task 10 adds **one** entry, which that decision explicitly sanctions: *"Adding a bespoke icon later is one map entry."*
    - Task 8's `activityProse.ts` — `eventPhrase()`, whose **first line is a named special case**, `if (event === 'created') return 'created this ticket'`. Task 11 adds the second.
    - Task 10's `TicketTimelineEntry.vue` — the `timeline-values` block (`v-if="activity.field && activity.from_label && activity.to_label"`) and the `timeline-reason` block. Task 12 adds a third block beside them.
    - Task 9's store additions — `activities`, `activitiesMeta`, `activitiesLoading`, `activitiesError`, `loadActivities` and the `latestActivitiesRequest` guard. Task 13 adds `addNote` **without touching `loadActivities`**.
12. [`../status-workflow-escalation/37-story-flag-stale-tickets-on-a-schedule-TM-43.md`](../status-workflow-escalation/37-story-flag-stale-tickets-on-a-schedule-TM-43.md) — **the decision at 42–50** (*"'last activity' means `tickets.updated_at`"*), **line 46** which cites this story's fourth criterion as its justification, **line 50** which hands the conversation here, **line 57** on why writing to a different table is what keeps the rule stable, and **test 14 at line 437** (`test_flagging_touches_no_ticket_column`) — **the shape task 9's AC4 test copies, in the opposite direction.**
13. [`../status-workflow-escalation/33-story-resolving-requires-a-resolution-note-TM-39.md`](../status-workflow-escalation/33-story-resolving-requires-a-resolution-note-TM-39.md) — **the direct precedent for a long free-text body in this table**: `meta.resolution`, validated `['required','string','min:10','max:5000']` (**line 142**), with the **5000** cap reasoned at **line 89** — *"5000 caps a note that lands in a JSON column; `description` is capped at 16000, and a resolution is a summary, not a transcript."* Task 2 takes the same cap for the same reason and **a different floor**; see the decision.
14. `frontend/src/views/NewTicketView.vue` — **line 14 is the double-submit guard task 14 copies**: `if (submitting.value || !validate()) return`, then `validationErrors(error)` into a field-error map with `errorMessage(error)` as the fallback. Line **27** is the `:disabled="submitting"` button with a changing label.
15. `frontend/src/components/CategoryFormDialog.vue:18–28` — the smaller submit precedent: `validationErrors(e)` into `errors.value`, `message.value` only when the map is empty.
16. `frontend/src/api/errors.ts:6–11` — `validationErrors()` returns `Record<string, string[]>` and `{}` for anything that is not a `422`.
17. `frontend/src/api/categories.ts:36–41` — `createCategory()`, the `POST` client shape: `client.post<{ data: T }>(…)` then `return data.data`. Task 10's `addTicketNote` follows it.

---

## Decision — the body is stored verbatim, and AC2's "escaped" is satisfied at render

AC2 reads *"Note body is required, length validated, and escaped so no HTML or script can be injected"*. The literal reading is input sanitisation. **Do not sanitise on input.**

- **This repository already ruled on it, twice.** Story 29's plan (**line 410**): a reason containing `<script>` is *"stored verbatim; `json` does no escaping and neither should the API. **Escaping is the renderer's job**, which is TM-46's and TM-64's."* Story 34's plan (**line 378**) repeats it. Story 39 implements it and forbids `v-html`.
- **Sanitising on input corrupts legitimate notes.** An agent writing *"the check `if (a < b)` never fires"* or *"customer's email is <redacted>"* would have their words silently rewritten, in a table that is meant to be **evidence** (E7's own goal) and that TM-48 makes unamendable. **A corrupted audit row cannot be fixed after the fact.**
- **Two escaping locations is worse than one.** The timeline, the detail page and any future export each render this string. Escaping at input protects exactly one of them and gives false confidence about the rest; escaping at render protects all of them and is enforceable by a `grep` for `v-html`.

**AC2 is met in full:** no HTML or script *can* be injected, because every render path escapes. Task 12 renders the body through Vue interpolation, task 19's test asserts a `<script>` payload produces no element, and verification step 10 checks it by hand. **E9-S6's fourth criterion — *"every user-supplied string in the timeline and ticket views is checked against XSS"* — has no sharper instance than this one**; the PR should name the note body as the string that criterion was written for.

## Decision — a note does not reset the staleness clock, and the trade-off goes to the backlog owner

Story 37 defined staleness as `tickets.updated_at`, cited **this story's fourth criterion** as its reason (its plan, line 46), and then handed the counter-argument here (line 50): an agent investigating for a week through notes alone sees the ticket flagged as forgotten.

**AC4 is explicit and this story honours it.** No `orWhereExists` on `ticket_activities` is added to `tickets:flag-stale`, and nothing in the note path touches `tickets`.

**But the tension is real and it is now this story's to surface, not to bury.** Put it in the PR description in these words:

> TM-47's fourth criterion requires that a note not change `updated_at` semantics for staleness, and we implemented it exactly: `POST /tickets/{ticket}/notes` writes one row to `ticket_activities` and touches no column on `tickets`. The consequence, which TM-43's plan raised and deferred to this story: **an agent who investigates for a week through notes alone will have the ticket flagged stale.** If the team wants notes to count as activity, the change is one `orWhereExists` in `tickets:flag-stale`'s query — **it is a one-line change to a shipped command and it contradicts this story's own AC4**, so it needs a decision from whoever owns the backlog, not from us.

**Two mechanical rules follow, and both are tests:**

- **The controller never calls `$ticket->save()`, `$ticket->touch()` or `$ticket->update()`.** Route model binding reads the ticket; nothing writes it. Test 6 captures every column before and after.
- **Never add `protected $touches = ['ticket'];` to `TicketActivity`.** It looks harmless, Eloquent supports it, and it would bump `tickets.updated_at` on **every** activity row — silently redefining Story 37's staleness rule and breaking its idempotency clause (its plan, line 57: *"Writing the `stale` row does not bump `tickets.updated_at` — it is a different table — so the ticket stays eligible while the `NOT EXISTS` blocks it. **That asymmetry is what makes the rule stable.**"*). Task 1 adds a comment saying so; test 7 asserts it.

## Decision — AC3 ships as a tripwire, because there is no mail to exclude notes from

`backend/app/Mail`, `app/Notifications`, `app/Events`, `app/Listeners`, `app/Jobs` and `resources/views/mail` **do not exist**. No mailable, no notification, no queued job, no template. AC3 — *"never included in any email to a requester"* — cannot be violated today and cannot be *implemented* today either.

Three things this story does **not** do: invent a mail layer to exclude notes from; add an `is_internal` flag to a table whose every row is already internal; write a rule inside a template that does not exist.

**What it does:** test 8 fakes both `Mail` and `Notification`, posts a note, and asserts **nothing** was sent or queued. It passes today and **fails the day someone wires mail into the note endpoint** — which is the only failure mode that matters. Task 8 also writes the constraint into `docs/api-contract.md`, where E8's planner will be reading the endpoint list.

**E8-S3's and E8-S6's criteria stay theirs.** When a requester mailable exists, excluding `note_added` from it is that story's job; record in the PR that this story's test does **not** cover it, so nobody reads a green suite as proof.

## Decision — a note is permanent, and the API says so

TM-48's whole purpose is that *"activity records are impossible to edit or delete"*. A note is an activity row. Therefore **an agent who makes a typo in a note cannot fix it, and one who writes something they regret cannot remove it.**

That is a genuine product consequence, not an oversight, and it is **not** in any acceptance criterion. This story:

- **Adds no `PATCH` and no `DELETE`** — TM-48's first criterion (*"No update or delete endpoint exists for `ticket_activities` anywhere in the routes file"*) would fail the moment one existed.
- **Says it to the user, once, in the composer** (task 14): the helper text *"Notes are internal and permanent."* An agent who knows this before typing is not surprised by it afterwards.
- **Says it in `docs/api-contract.md`** (task 8), so the next person to be asked for an edit endpoint finds the reason instead of the gap.

**Raise it in the PR.** If the product wants amendable notes, the honest shape is a **second** `note_added` row that supersedes the first — never a mutation — and that is a new story that must reconcile with TM-48.

## Decision — `meta.note`, not `new_value`

The body could go in `new_value`, a `text` column. **It goes in `meta.note` instead.**

- **`meta.resolution` is the precedent in this exact table** (Story 33, task 5). A long free-text body attached to an event already lives under `meta` here; a second convention for the same kind of payload is churn.
- **`new_value` would leak into Story 39's labels.** `TicketActivityResource` computes `'to_label' => $meta['to_name'] ?? $this->new_value`. Putting a 5000-character note in `new_value` makes `to_label` the whole note body — harmless in the sentence (which needs *both* labels, and `from_label` is null) but a landmine for TM-49 and for anyone reading the payload. With `meta.note`, `field`, `old_value`, `new_value`, `from_label` and `to_label` are **all null**, which is the truth: a note is not a field change.
- **`meta` is documented as "event-specific detail"** in the contract section Story 39 writes. A note body is exactly that.

## Decision — `addNote` is a new policy ability, with no terminal guard

E2-S6's second criterion requires every controller action to authorize. `view` is wrong for a write, and reusing `update` would tie note permission to ticket-**editing** permission, which TM-27 may narrow later — a note is not an edit.

**`addNote(User $user, Ticket $ticket): bool` returning `true`.** And **deliberately not** `return ! $ticket->status->is_terminal;`, which is what `escalate()` (**45–48**) does:

- **Noting a closed ticket is the point.** *"This recurred in March, root cause was the firmware"* on a Closed ticket is exactly the *"investigation and context captured where the next agent will find them"* the story asks for. Blocking it pushes that knowledge into chat.
- **A note changes nothing**, so there is no state to protect. `escalate()` guards a terminal ticket because escalating one is incoherent; recording a fact about one is not.

Task 5 exposes it as `can.add_note` so the SPA gates the composer from the server's answer, following the pattern Story 22 set (`TicketResource.php:28`). **Do not hide the composer on `is_terminal` in the frontend** — that would re-implement a guard the policy deliberately declined.

---

## Backend Tasks

### 1 — The event case, and a comment that prevents a specific edit

**File: `backend/app/Enums/TicketActivityEvent.php`**

Add after `CategoryChanged` (**8**):

```php
    case NoteAdded = 'note_added';
```

**File: `backend/app/Models/TicketActivity.php`**

Add beside `UPDATED_AT` (**12**):

```php
    // Do NOT add `protected $touches = ['ticket'];` here. It would bump
    // tickets.updated_at on every activity row, silently redefining the
    // staleness rule TM-43 built on that column and breaking its idempotency
    // clause. An internal note must not reset the staleness clock (TM-47 AC4).
```

**No other change to this file, and no change to `ActivityRecorder.php`.**

### 2 — The request

**Create file: `backend/app/Http/Requests/Api/V1/StoreTicketNoteRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreTicketNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('addNote', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // A whitespace-only body never reaches `min`: TrimStrings then
        // ConvertEmptyStringsToNull (both in Laravel's default global stack)
        // turn "   " into null, which fails `required`. No custom rule needed.
        return ['body' => ['required', 'string', 'min:3', 'max:5000']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body.required' => 'Write something before saving the note.',
            'body.min' => 'A note needs at least 3 characters.',
            'body.max' => 'A note is capped at 5000 characters. Put longer detail in the ticket description instead.',
        ];
    }
}
```

**`Gate::allows('addNote', $this->route('ticket'))`** follows `DestroyCategoryRequest.php:13`, which authorizes against a route-bound model rather than a class.

**On the two bounds, both deliberate:**

- **`max:5000` is taken from Story 33's `meta.resolution` unchanged**, for the reason its plan gives at line 89: the value lands in a `json` column, and `description` is the place for a transcript at 16000 (`StoreTicketRequest.php:34`).
- **`min:3`, not Story 33's `min:10`.** A resolution note summarises a fix and earns a real floor. An internal note is legitimately terse — *"cb 3pm"*, *"no answer"*, *"see #4812"* — and a 10-character floor would reject all three. **3 rejects the `"ok"` and `"."` accidents without rejecting shorthand.** Test 3 asserts both bounds.

### 3 — The policy ability

**File: `backend/app/Policies/TicketPolicy.php`**

Add after `escalate()` (**ends at 48**):

```php
    /**
     * Any active staff member may note any ticket, including a terminal one --
     * a post-mortem note on a Closed ticket is the point of the feature. This
     * deliberately does NOT mirror escalate()'s is_terminal guard: escalating a
     * closed ticket is incoherent, recording a fact about one is not.
     */
    public function addNote(User $user, Ticket $ticket): bool
    {
        return true;
    }
```

### 4 — The endpoint

**Create file: `backend/app/Http/Controllers/Api/V1/TicketNoteController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TicketActivityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTicketNoteRequest;
use App\Http\Resources\V1\TicketActivityResource;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Services\ActivityRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TicketNoteController extends Controller
{
    public function __invoke(StoreTicketNoteRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $actorId = $request->user()->getKey();
        $note = DB::transaction(function () use ($request, $ticket, $recorder, $actorId): TicketActivity {
            $recorder->record($ticket->getKey(), TicketActivityEvent::NoteAdded, [
                'user_id' => $actorId,
                'meta' => ['note' => $request->validated('body')],
            ]);

            // TicketActivity::insert() returns no ids, so the row is re-read.
            // Safe inside this transaction: route model binding already opened
            // the read view, so a note committed by another request after that
            // point is invisible here and the highest id is our own row. If this
            // ever proves wrong the fix is a return value on
            // ActivityRecorder::record() -- a change to TM-45's file that needs
            // its own decision, not a wider select here.
            return $ticket->activities()->with('user')->orderByDesc('id')->firstOrFail();
        });

        return TicketActivityResource::make($note)->response()->setStatusCode(201);
    }
}
```

**Four things here are load-bearing:**

- **`->with('user')` is not optional.** `TicketActivityResource` builds `actor` with `whenLoaded('user', …)`, which **drops the key entirely** when the relation is not loaded. Without it the `201` body has no `actor` and the SPA prepends an entry that renders as "System". Test 2 asserts `actor.name`.
- **`DB::transaction` is required**, not stylistic: `ActivityRecorder` throws a `LogicException` outside one (`ActivityRecorder.php:26–28`).
- **`record()` is called with two keys**, `user_id` and `meta`, exactly as `TicketController.php:44`. `field`, `old_value` and `new_value` come back `null` from `recordMany()`'s defaults. **Never call `recordMany()` directly.**
- **Nothing writes to `$ticket`.** No `save()`, no `touch()`, no `update()`, no attribute assignment. **This is AC4**, and test 6 is what enforces it.

### 5 — Expose the ability to the SPA

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

In the `can` block (**28**), add a fifth key beside the four existing ones:

```php
'add_note' => $request->user()->can('addNote', $this->resource),
```

Keep it inside the existing `$this->when($request->routeIs('tickets.show'), …)` wrapper — the list response does not carry `can`.

### 6 — Route

**File: `backend/routes/api.php`**

Import `TicketNoteController` alphabetically among the `Api\V1` controllers, and add one line after Story 39's activities route:

```php
    Route::post('/tickets/{ticket}/notes', TicketNoteController::class)->name('tickets.notes');
```

**Inside the `['auth:sanctum','active']` group opened at 32**, not the `admin` group. **No `PATCH`, no `DELETE`, no `GET`** — notes are read through Story 39's timeline, and TM-48's first criterion forbids the other two.

### 7 — Classify the route

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add `'tickets.notes' => 'staff'` to `self::ACCESS` (**16**). Story 39 already added `tickets.activities` and `tickets.store`, so this is the only key needed — confirm with `grep -c "tickets.activities" backend/tests/Feature/Authorization/RouteAuthorizationTest.php` → `1`.

### 8 — Document the endpoint

**File: `docs/api-contract.md`**

One row in the endpoints table, after Story 39's activities row:

| Method | Path | Purpose | Auth | Owning story |
|--------|------|---------|------|--------------|
| `POST` | `/api/v1/tickets/{ticket}/notes` | Add an internal note as a `note_added` activity. | bearer (TicketPolicy `addNote`) | TM-47 |

and one section at the end of the file:

```
### `POST /api/v1/tickets/{ticket}/notes`

Requires `Authorization: Bearer <token>`. Any active staff user may note any
ticket, **including a Resolved or Closed one** -- `TicketPolicy::addNote`
deliberately carries no terminal guard, because a post-mortem note is the point.
Body: `{ "body": "..." }`, required, 3 to 5000 characters. Whitespace-only is
trimmed to null by global middleware and returns `422 required`.

Returns **`201`** with the created activity in the same shape as
`GET /api/v1/tickets/{ticket}/activities`: `event` is `note_added`, the body is
`meta.note`, and `field`, `old_value`, `new_value`, `from_label` and `to_label`
are all **null** -- a note is not a field change.

**The note is stored byte-identical.** No HTML escaping, no unicode escaping, no
sanitising. Escaping is the renderer's obligation, and the SPA renders every
note through Vue interpolation and never `v-html`.

**Adding a note changes nothing on the `tickets` table** -- not `status_id`, not
`updated_at`, not any timestamp. This is deliberate: `php artisan
tickets:flag-stale` defines staleness as `tickets.updated_at`, so a note does
not reset the staleness clock. The consequence -- an agent investigating through
notes alone will see the ticket flagged -- is a known trade-off raised with the
backlog owner in TM-47, not an oversight.

**Notes are internal and permanent.** There is no `PATCH` and no `DELETE`: an
activity row cannot be amended (TM-48). If an amendable note is ever wanted, the
shape is a second note that supersedes the first, never a mutation. Notes are
also never sent to a requester -- no mail path reads them, and a test fails the
build if this endpoint ever sends or queues a message. **Excluding notes from a
requester mailable remains E8-S3's and E8-S6's criterion**; nothing here proves
it, because no mailable exists yet.
```

### 9 — Backend tests

**Create file: `backend/tests/Feature/Activity/TicketNotesTest.php`**

`RefreshDatabase` + `$this->seed()`, reusing the `makeTicket()` helper from Story 39's test plan (**there is still no `TicketFactory`**). See the Test Plan for the numbered list.

---

## Frontend Tasks

### 10 — The client and the icon

**File: `frontend/src/api/activities.ts`**

Add beside `listTicketActivities`. **It belongs here, not in a new `notes.ts`**: it returns a `TicketActivity`, and a separate module would import that type back from this one for no gain.

```ts
export async function addTicketNote(
  ticketId: number,
  body: string,
): Promise<TicketActivity> {
  const { data } = await client.post<{ data: TicketActivity }>(
    `/tickets/${ticketId}/notes`,
    { body },
  )
  return data.data
}
```

`data.data` because a single resource is wrapped, matching `createCategory` (`api/categories.ts:36–41`) and unlike `listTicketActivities`, which returns the paginated envelope whole.

**File: `frontend/src/lib/activityEvents.ts`**

Add **one** entry to `DESCRIPTORS`. Story 39's decision sanctions exactly this: *"Adding a bespoke icon later is one map entry."*

```ts
  note_added: { color: '#B45309', path: 'M3 2h7l3 3v9H3zM9 2v4h4' },
```

The colour must match `/^#[0-9A-Fa-f]{6}$/` — Story 39's `activityEvents.spec.ts` asserts that for every descriptor, and `readableTextColor` (`lib/color.ts:11`) falls back to dark on an invalid hex.

### 11 — The phrase and the body reader

**File: `frontend/src/lib/activityProse.ts`**

Add a second named case to `eventPhrase`, directly after the `created` line. **This follows the pattern Story 39 established rather than breaking its contract**: the generic `_added` rule yields *"added note"*, which is grammatically wrong, and `created` is already a named exception for the same reason.

```ts
  if (event === 'note_added') return 'added an internal note'
```

Add a reader beside `activityReason`:

```ts
export function noteBody(activity: TicketActivity): string | null {
  if (activity.event !== 'note_added') return null
  const note = activity.meta.note
  return typeof note === 'string' && note !== '' ? note : null
}
```

**The `event` check matters.** Without it any future event carrying a `meta.note` key would render a note block, and `meta` is a free-form object. **Do not widen this to "any activity with a note".**

### 12 — Render the body in the timeline

**File: `frontend/src/components/TicketTimelineEntry.vue`**

Import `noteBody` alongside the existing `activityReason` / `activitySentence` import, add `const body = computed(() => noteBody(props.activity))`, and add one block in the template **after `timeline-sentence` and before `timeline-values`**:

```vue
    <p v-if="body" class="note" data-testid="timeline-note">{{ body }}</p>
```

with one scoped rule beside the existing `.values` / `.reason` rules:

```css
.note {
  grid-column: 2;
  white-space: pre-wrap;
  padding: 6px 8px;
  border-left: 3px solid #b45309;
  background: #fffbeb;
}
```

**Three constraints:**

- **`{{ body }}`, never `v-html`.** This is the string E9-S6's fourth criterion was written for. `grep -rn "v-html" frontend/src/` must stay empty.
- **`white-space: pre-wrap`** so an agent's line breaks survive. A note is prose, not a label — and the body is stored verbatim, so the newlines are really there.
- **Do not touch `timeline-values`, `timeline-reason`, `timeline-icon` or the `data-system` rules.** A note has `field`, `from_label` and `to_label` all null, so `timeline-values` is already correctly hidden.

### 13 — `addNote` in the store

**File: `frontend/src/stores/tickets.ts`**

Add beside Story 39's timeline state. **Do not touch `loadActivities`, `loadTicket`, `create`, or the `latestActivitiesRequest` guard.**

```ts
const noteSaving = ref(false)

async function addNote(id: number, body: string): Promise<void> {
  noteSaving.value = true
  try {
    const created = await addTicketNote(id, body)
    // Prepended, not refetched. The timeline is ordered created_at DESC, id DESC
    // and this row has the highest of both, so position one IS its correct
    // chronological position -- and no refetch means no race with the
    // latestActivitiesRequest guard.
    activities.value = [created, ...activities.value]
    if (activitiesMeta.value) activitiesMeta.value.total += 1
  } finally {
    noteSaving.value = false
  }
}
```

Add `noteSaving` and `addNote` to the `return`.

**`addNote` deliberately does not catch.** The composer needs the `422` field errors, so the error propagates — the same division `stores/categories.ts` uses with `CategoryFormDialog.vue:24–27`. **Do not set `activitiesError`**: a rejected note is not a broken timeline.

The `activitiesMeta.value` guard matters — `loadActivities` sets it to `null` on failure, so a note posted after a failed timeline load must not throw on `.total`.

### 14 — The composer

**Create file: `frontend/src/components/TicketNoteComposer.vue`**

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import { useTicketsStore } from '../stores/tickets'
const props = defineProps<{ ticketId: number }>()
const store = useTicketsStore()
const body = ref('')
const fieldError = ref('')
const message = ref('')
async function submit(): Promise<void> {
  if (store.noteSaving) return
  fieldError.value = ''
  message.value = ''
  try {
    await store.addNote(props.ticketId, body.value)
    body.value = ''
  } catch (caughtError) {
    const fields = validationErrors(caughtError)
    fieldError.value = fields.body?.[0] ?? ''
    if (!fieldError.value) message.value = errorMessage(caughtError)
  }
}
</script>
<template>
  <form data-testid="note-composer" @submit.prevent="submit">
    <label for="note-body">Internal note</label>
    <textarea
      id="note-body"
      v-model="body"
      data-testid="note-body"
      rows="3"
      maxlength="5000"
    />
    <p data-testid="note-hint">Notes are internal and permanent.</p>
    <p v-if="fieldError" data-testid="note-error-body">{{ fieldError }}</p>
    <p v-if="message" data-testid="note-error">{{ message }}</p>
    <button data-testid="note-submit" :disabled="store.noteSaving">
      {{ store.noteSaving ? 'Saving...' : 'Add note' }}
    </button>
  </form>
</template>
```

**Four deliberate choices:**

- **`if (store.noteSaving) return`** is the double-submit guard from `NewTicketView.vue:14`. An audit row cannot be deleted, so a double-submitted note is **two permanent rows**.
- **No client-side length validation.** `NewTicketView.vue:13` mirrors its rules client-side; this does not. One set of bounds, on the server, with `messages()` that already read as user-facing sentences (task 2) — and the server is the only place that can be authoritative about a `min` after trimming. `maxlength="5000"` on the textarea is a **convenience**, not the validation.
- **`body.value = ''` only on success**, so a rejected note is not lost.
- **The hint is the permanence warning from the decision above.** It is one line and it is the difference between a known constraint and a nasty surprise.

### 15 — Mount it

**File: `frontend/src/views/TicketDetailView.vue`**

Import `TicketNoteComposer`, and in the template add it immediately **above** `<TicketTimeline />`, inside the `v-else-if="store.current"` article:

```vue
<TicketNoteComposer v-if="store.current.can.add_note" :ticket-id="store.current.id" />
```

**Two rules:**

- **The `v-if` reads `can.add_note` from the server** (task 5). **Do not add an `is_terminal` check** — the policy declined that guard on purpose.
- **The composer goes in the view, not inside `TicketTimeline.vue`.** That component takes no props and reads the store; the composer needs a ticket id, and the view already has `store.current.id`. Adding a prop to Story 39's component to thread it through would be a wider change for no benefit.

**No change to `frontend/src/router/index.ts`** — the composer is on an existing route.

---

## Edge Cases & Failure Modes

- **Whitespace-only body.** `TrimStrings` then `ConvertEmptyStringsToNull` (both in the default global stack, `Middleware.php:461–462`) turn `"   "` into `null`, so `required` fires and the response is `422` with *"Write something before saving the note."* **No custom rule and no `prepareForValidation`.** Test 4 asserts it for spaces, tabs and a newline.
- **A 2-character body.** `422` `min` — deliberate: `"ok"` and `"."` are accidents. **A 3-character body is accepted** (`"n/a"`, `"cb1"`); test 3 asserts both sides of the boundary.
- **A 5001-character body.** `422` `max`, message points at the ticket description. **5000 is not a database limit** — `meta` is `json`, good for far more — it is the same product cap Story 33 set for `meta.resolution`.
- **`<script>alert(1)</script>` or `<img src=x onerror=…>` in the body.** Stored byte-identical (`json_encode` with `JSON_UNESCAPED_UNICODE`, `ActivityRecorder.php:34`), returned byte-identical, rendered as literal text by Vue `{{ }}`. **The failure mode is a future `v-html`**, which is why verification step 9 greps for it. Tests 5 and 19b.
- **Arabic, emoji, or a right-to-left body.** utf8mb4 on both containers and `JSON_UNESCAPED_UNICODE` mean the bytes survive; `pre-wrap` preserves the layout. Test 5.
- **A body with newlines.** Preserved in storage and rendered by `white-space: pre-wrap`. **Without that CSS rule the note collapses to one line** and an agent's structured note becomes unreadable — the reason it is in task 12 rather than left to a stylesheet later.
- **A note on a Resolved or Closed ticket.** **Allowed**, by design — `addNote` carries no terminal guard. Test 10 posts to a ticket on a terminal status and asserts `201`. **This is the test that fails if someone "fixes" the policy by copying `escalate()`.**
- **A note on a soft-deleted ticket.** `404`. `Ticket` uses `SoftDeletes` (`Ticket.php:16`) so implicit binding excludes trashed rows — the same behaviour Story 39 documents for the timeline. Test 11.
- **An unknown ticket id.** `404`. An unauthenticated or inactive caller: `401` from `auth:sanctum` / `active` before the request object is resolved. Test 12.
- **`tickets.updated_at` after a note.** Unchanged, and **this is the criterion most likely to be broken by a well-meaning later edit** — a `$ticket->touch()` "so the list sorts right", or `$touches = ['ticket']` on the model. Test 6 captures `updated_at`, `status_id`, `escalation_level`, `escalated_at`, `first_responded_at`, `resolved_at` and `closed_at` before and after; test 7 asserts `TicketActivity` declares no touched relations.
- **The consequence of that, stated once more because it is a real cost:** a ticket worked on only through notes **will** be flagged stale by `tickets:flag-stale`. Not a bug — AC4 requires it. Raised with the backlog owner per the decision.
- **A double-submitted note.** Two permanent rows that nothing can delete. Guarded by `if (store.noteSaving) return` plus `:disabled`. Test 20e clicks twice while the promise is pending and asserts **one** call.
- **A note posted while the timeline load is still in flight.** `addNote` prepends and never refetches, so it cannot race `latestActivitiesRequest`. But `loadActivities` **resolving after** the prepend replaces `activities` wholesale — the server's page 1 already contains the note, so the note survives; only its object identity changes. **No fix needed**; recorded so nobody adds one.
- **A note posted after the timeline failed to load.** `activitiesMeta` is `null`; the `if (activitiesMeta.value)` guard skips the increment and the note still prepends onto an empty array. **Without that guard this throws.** Test 20f.
- **A `422` on submit.** The textarea keeps its content, `note-error-body` shows the server's sentence. **A note is never silently lost** — test 20c.
- **Two agents noting the same ticket simultaneously.** Both succeed; two rows, both kept. Each controller re-reads its own row under the read view opened by route model binding, so neither returns the other's note. **This is reasoned, not measured** — a truly concurrent test is not practical in PHPUnit. Test 13 asserts the sequential case, and the comment in task 4 names the fix if the reasoning is ever wrong.
- **A `note_added` row whose `meta.note` is missing or not a string.** Impossible through this endpoint; reachable by direct SQL. `noteBody()` returns `null`, so the entry renders as *"Ahmed added an internal note"* with no body block rather than `undefined`. Test 17d.
- **A future event that happens to carry `meta.note`.** `noteBody()` checks `event !== 'note_added'` first and returns `null`. Test 17e.

---

## Test Plan

### Backend — `backend/tests/Feature/Activity/TicketNotesTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Uses the `makeTicket()` helper from Story 39's test plan — **there is still no `TicketFactory`**.

1. `test_a_note_is_stored_as_one_note_added_row` — post a body; assert `201`, **exactly one** new `ticket_activities` row, `event === 'note_added'`, `meta['note']` the body, `user_id` the caller, and `field`, `old_value`, `new_value` all **null**. **AC1.**
2. `test_the_response_is_the_created_activity_with_its_actor` — assert `data.event === 'note_added'`, `data.meta.note`, `data.actor.name` the caller's name, and that `data.field`, `data.old_value`, `data.new_value`, `data.from_label`, `data.to_label` are all null. **`actor` is what fails if `->with('user')` is dropped** — prove it by dropping it.
3. `test_the_body_length_bounds_are_enforced` — 2 chars → `422` under `errors.body`; **3 chars → `201`**; 5000 → `201`; 5001 → `422`. All four in one test so the boundary is visible. **AC2.**
4. `test_an_empty_or_whitespace_body_is_rejected` — missing key, `''`, `'   '`, `"\t"`, `"\n"` → all `422` `required` with *"Write something before saving the note."* **Proves the global-middleware reasoning in task 2**; if it fails, `TrimStrings` is not what the plan claims.
5. `test_markup_and_unicode_are_stored_byte_identically` — body `'<script>alert(1)</script> مرحبا 🎫'` plus a newline and a second line. Assert the API echoes it **byte-identical**, and that the raw column read with `DB::table('ticket_activities')->value('meta')` contains the literal `<script>`. **Nothing sanitises on the way in. AC2.**
6. `test_adding_a_note_touches_no_ticket_column` — capture `updated_at`, `status_id`, `escalation_level`, `escalated_at`, `first_responded_at`, `resolved_at`, `closed_at`; post two notes with time advanced between them; assert **every one unchanged**. **AC4, and the most important test in this story.** Shape copied from Story 37's test 14 (`test_flagging_touches_no_ticket_column`). Prove it by adding `$ticket->touch()` to the controller.
7. `test_the_activity_model_does_not_touch_its_parent` — assert `(new TicketActivity)->getTouchedRelations() === []`. A one-line structural guard against the edit that would silently redefine staleness. Prove it by adding `protected $touches = ['ticket'];`.
8. `test_adding_a_note_sends_and_queues_nothing` — `Mail::fake()` and `Notification::fake()`, post a note, then `Mail::assertNothingSent()`, `Mail::assertNothingQueued()`, `Notification::assertNothingSent()`. **AC3's tripwire.** It passes today because no mail layer exists; it fails the day one is wired into this endpoint. **Comment in the test that it does not discharge E8-S3's or E8-S6's criterion.**
9. `test_an_agent_may_note_a_ticket` — agent token → `201`. `TicketPolicy::addNote` returns true for all staff.
10. `test_a_terminal_ticket_may_be_noted` — set the ticket's status to one with `is_terminal = true` (`Status::query()->where('is_terminal', true)->first()`), post → **`201`**. **The test that fails if someone copies `escalate()`'s guard.**
11. `test_a_soft_deleted_ticket_returns_404` — `$ticket->delete()`, post → `404`, and **no new activity row**.
12. `test_unknown_ticket_and_unauthenticated_are_rejected` — id `999999` with a valid token → `404`; no token → `401`.
13. `test_two_sequential_notes_each_return_their_own_body` — post A then B; assert each response's `meta.note` matches what was posted and that two rows exist with distinct ids. **The achievable half of the concurrency argument in task 4's comment.**
14. `test_no_route_can_amend_or_remove_a_note` — `patchJson`, `putJson` and `deleteJson` against `/api/v1/tickets/{id}/notes` and `…/notes/{activityId}` → `405` or `404`, never `2xx`. **TM-48's first criterion, asserted early for the one endpoint this story adds.**

### Backend — modified

15. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `'tickets.notes' => 'staff'` in `ACCESS`. Both classification tests stay green; the suite keeps exactly **one** pre-existing failure (`PasswordThrottleTest`, TM-14).
16. If `backend/tests/Feature/Policies/TicketPolicyTest.php` exists by now, add one case: `addNote` returns `true` for an admin and an agent, on a terminal and a non-terminal ticket. If it does not exist, **do not create it** — tests 9 and 10 cover the behaviour through the endpoint.

### Frontend — modified (all three files are Story 39's)

17. `frontend/src/lib/activityProse.spec.ts`
    - a. `note_added` → `'Ahmed added an internal note'` — **not** the generic `'Ahmed added note'`.
    - b. `actor: null` on a `note_added` → `'System added an internal note'`.
    - c. `noteBody` returns the string for a `note_added` with `meta.note`.
    - d. `noteBody` returns `null` for `meta: {}`, for `meta.note: ''` and for `meta.note: 42`.
    - e. **`noteBody` returns `null` for a non-`note_added` event that carries `meta.note`** — the event check earning its place.
18. `frontend/src/lib/activityEvents.spec.ts` — `note_added` returns a descriptor **distinct** from `created`, from `category_changed` and from the fallback, with a colour matching `/^#[0-9A-Fa-f]{6}$/`. The existing "every descriptor's colour" case covers the pattern automatically.
19. `frontend/src/components/TicketTimeline.spec.ts`
    - a. a `note_added` entry renders `timeline-note` with the body, and **no `timeline-values`**.
    - b. `meta.note: '<script>alert(1)</script>'` → `wrapper.find('script').exists()` is `false` and the note's `text()` contains the literal `'<script>'`. **The XSS assertion, on the string E9-S6's fourth criterion names.**
    - c. a body with `'line one\nline two'` renders both lines inside one `timeline-note` node.
    - d. a non-note entry renders **no** `timeline-note`.
    - e. a `note_added` entry appears **above** an older `status_changed` entry when the payload is ordered newest-first. **AC5's "correct chronological position"**, which for a newest-first timeline means position one.

### Frontend — new

20. `frontend/src/components/TicketNoteComposer.spec.ts` — mock `../api/activities`, mount with `createPinia()`, following `HealthView.spec.ts:9,20`.
    - a. a successful submit calls `addTicketNote` with the ticket id and body, **clears the textarea**, and prepends the returned activity to `store.activities`.
    - b. `store.activitiesMeta.total` increments by one.
    - c. a `422` with `errors.body` renders `note-error-body` with the server's message and **leaves the textarea's content intact**.
    - d. a non-`422` error renders `note-error` from `errorMessage` and no `note-error-body`.
    - e. **double submit** — click twice while the promise is pending; `addTicketNote` is called **once** and the button is `disabled`. *An audit row cannot be deleted, so this test is about data, not polish.*
    - f. posting when `activitiesMeta` is `null` prepends without throwing.
    - g. `note-hint` renders *"Notes are internal and permanent."*

---

## Verification Steps

1. **Services:** `docker compose ps` → all three healthy, `tm-mysql-test` on **3307**.
2. **Confirm Story 39 landed:** `ls backend/app/Http/Resources/V1/TicketActivityResource.php frontend/src/lib/activityProse.ts frontend/src/components/TicketTimelineEntry.vue` → all three present. **Confirm Story 38's fix landed:** `grep -n "'meta' => \[\]" backend/app/Services/ActivityRecorder.php` → a hit inside `recordMany()`.
3. **Confirm the middleware claim rather than trusting it:** `grep -n "TrimStrings" backend/vendor/laravel/framework/src/Illuminate/Foundation/Configuration/Middleware.php` → **461**, and `grep -n "middleware->use(" backend/bootstrap/app.php` → **no output** (the default stack is not replaced).
4. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
5. **Backend tests:** from `backend/`, `composer test`. Expect **+14 tests** and exactly **one** failure — `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user`, TM-14's.
6. **Prove test 6 earns its place — this is AC4's only real defence.** Add `$ticket->touch();` inside the transaction in `TicketNoteController`, run `--filter=test_adding_a_note_touches_no_ticket_column`, confirm it **fails on `updated_at`**. Restore. Then add `protected $touches = ['ticket'];` to `TicketActivity`, run **both** test 6 and test 7, confirm **both** fail. Restore.
7. **Prove test 2 earns its place:** remove `->with('user')` from the controller's re-read, run `--filter=test_the_response_is_the_created_activity_with_its_actor`, confirm it fails because **`actor` is absent, not null**. Restore.
8. **Prove test 10 earns its place:** change `addNote()` to `return ! $ticket->status->is_terminal;`, run `--filter=test_a_terminal_ticket_may_be_noted`, confirm `403`. Restore.
9. **Frontend:** from `frontend/` — `npx vue-tsc -b`, `npm run lint`, `npm run format:check` all exit `0`; `npm test` green with **+1 spec file** and the three extended ones. Then `grep -rn "v-html" frontend/src/` → **no output**.
10. **By hand.** `php artisan serve` in `backend/`, `npm run dev` in `frontend/`, log in, open a ticket.
    - The **Internal note** composer is above the History section, with the hint *"Notes are internal and permanent."*
    - Submit `ok` → the field error *"A note needs at least 3 characters."*, **and the text stays in the box**.
    - Submit an empty box → *"Write something before saving the note."*; submit three spaces → the **same** message, proving the trim path.
    - Submit a real multi-line note → it appears **at the top of the timeline immediately**, no reload, with an amber icon, both lines intact, and *"\<your name\> added an internal note"*.
    - Submit `<img src=x onerror=alert(1)>` → the text appears **literally** and no dialog fires.
    - Submit an Arabic note with an emoji → renders correctly right-to-left.
    - `SELECT event, field, old_value, new_value, meta, user_id FROM ticket_activities ORDER BY id DESC LIMIT 1;` → `event` `note_added`, `field`/`old_value`/`new_value` **NULL**, `meta` a JSON object holding the literal body.
    - **AC4 by hand:** `SELECT updated_at, status_id FROM tickets WHERE id = <id>;` before and after a note → **identical**.
    - Move the ticket to a terminal status (or set `status_id` directly) and add a note → **accepted**.
    - `curl -X DELETE` and `curl -X PATCH` against `/api/v1/tickets/<id>/notes` → `405`, never `2xx`.
11. **Regression:** `git status` shows **no file under `backend/database/`**, and no change to `ActivityRecorder.php`, `docs/erd.md`, `TicketController.php`, `TicketActivityResource.php`, `TicketTimeline.vue`, `activities.ts`'s existing functions, `stores/tickets.ts`'s `loadActivities`/`loadTicket`, `router/index.ts`, `composer.json` or `package.json`. The only edits to Story 39's files are: **one** `DESCRIPTORS` entry, **one** `eventPhrase` case plus `noteBody`, **one** template block plus one CSS rule in `TicketTimelineEntry.vue`, and additive store state.

---

## Done Criteria

- [ ] `POST /api/v1/tickets/{ticket}/notes` stores the body as **exactly one** `note_added` activity row, inside one transaction, through `ActivityRecorder::record()` with two keys — and returns **`201`** with that row, its `actor` eager-loaded so the key is present rather than dropped.
- [ ] `note_added` is the story's **one** new `TicketActivityEvent` case, with **no migration** — `event` is `varchar(50)`.
- [ ] The body lives in **`meta.note`**, following `meta.resolution`'s precedent, and `field`, `old_value`, `new_value`, `from_label` and `to_label` are all **null** — a note is not a field change, and a 5000-character string never leaks into `to_label`.
- [ ] The body is **required, 3–5000 characters**, with messages that read as sentences to a user. A whitespace-only body returns `422 required` **through global middleware, with no custom rule** — asserted for spaces, tabs and newlines.
- [ ] The body is stored and returned **byte-identical**, `<script>` and Arabic and emoji and newlines included. **Nothing sanitises on input**, and AC2 is met at render: the note is interpolated, `grep -rn "v-html" frontend/src/` is empty, and a test proves a script payload creates no element.
- [ ] **Adding a note changes no column on `tickets`** — not `status_id`, not `updated_at`, not any timestamp — proven by a test that fails when `$ticket->touch()` is added, plus a second test that fails when `$touches = ['ticket']` is added to `TicketActivity`, and a comment in the model saying why never to.
- [ ] **The staleness trade-off Story 37 delegated here is answered and escalated, not absorbed.** No `orWhereExists` is added to `tickets:flag-stale`; the PR states in writing that a ticket worked on through notes alone will be flagged, that the fix is one line, and that it contradicts AC4 — **a decision for the backlog owner**.
- [ ] AC3 ships as a **tripwire**: `Mail::fake()` + `Notification::fake()` assert the endpoint sends and queues **nothing**, passing today and failing the day mail is wired in. The test **states that it does not discharge E8-S3's or E8-S6's criterion**, and the PR says so too.
- [ ] `TicketPolicy::addNote` exists, returns `true` for all staff, and **carries no terminal guard** — a Resolved or Closed ticket can be noted, proven by a test that fails if `escalate()`'s guard is copied. `can.add_note` gates the composer from the server, and the frontend adds **no** `is_terminal` check of its own.
- [ ] The note appears in the timeline **in correct chronological position without a refetch** — prepended, because `created_at DESC, id DESC` puts the newest row first — with its own icon and colour from **one** `DESCRIPTORS` entry, and its body in a `pre-wrap` block so line breaks survive.
- [ ] `eventPhrase` gains **one** named case so the sentence reads *"added an internal note"* rather than the generic *"added note"* — the second entry in the pattern Story 39 established with `created`, **not a `switch`**. `noteBody` checks the event before reading `meta.note`, so no future event with a `note` key renders a note block.
- [ ] The composer guards double submission and **keeps the body on a `422`** — an audit row cannot be deleted, so a duplicate note is permanent damage and a lost note is lost work.
- [ ] **A note's permanence is stated to the user** (*"Notes are internal and permanent."*), **in the API contract**, and **in the PR** — with the note that an amendable note means a superseding second row, never a mutation, and that it is a new story reconciling with TM-48.
- [ ] **No `PATCH`, `PUT`, `DELETE` or `GET` route for notes** — a test asserts every one of them returns `405` or `404`, honouring TM-48's first criterion early.
- [ ] `docs/api-contract.md` documents the endpoint: the bounds, the trim behaviour, the `201` shape, the verbatim-storage rule with escaping named as the renderer's job, the `updated_at` guarantee **and its staleness consequence**, the permanence, and the mail constraint with E8 named as its owner.
- [ ] `RouteAuthorizationTest::ACCESS` classifies `tickets.notes`; the suite carries exactly **one** pre-existing failure, `PasswordThrottleTest` (TM-14).
- [ ] **No migration, no column, no index change, no new dependency**, no change to `ActivityRecorder`, `TicketActivityResource`, `TicketController`, `docs/erd.md`, `TicketTimeline.vue`, `router/index.ts` or any seeder. Every edit to Story 39's files is additive and enumerated in the PR.
- [ ] `pint --test`, `vue-tsc -b`, `npm run lint`, `npm run format:check` all exit `0`; **+14 backend tests**, **+1 frontend spec file**, three extended.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 41 (TM-48, the audit trail is append-only).**
