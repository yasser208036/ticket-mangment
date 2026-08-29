# Story 29 — Reassign or unassign with a reason (Story: TM-34)

## Prerequisites

- **Story 26 (TM-31) is a hard blocker and this story edits four of its files.** It creates `AssignTicketRequest`, `TicketController::assign()`, `TicketActivityEvent::Assigned`, `assignTicket()` / `stores/tickets.ts::assign()` and `TicketAssignDialog.vue` — and it deliberately left `assigned_to` **`required`** and `reason` **`prohibited`** with messages naming TM-34. **This story is where both are relaxed.** Gate: `php artisan test --filter=TicketAssignTest` green before you start. Read [`26-story-assign-a-ticket-to-an-agent-TM-31.md`](26-story-assign-a-ticket-to-an-agent-TM-31.md) end to end — it is the file you are extending, not one you are reading for background.
- **Story 27 (TM-32) is not a blocker, but one decision here is about it.** Its `POST /tickets/{ticket}/claim` must stay event-free — see the notification decision. If it has landed, task 4 adds nothing to it; if it has not, record the constraint for whoever writes it.
- **Story 28 (TM-33) is not a blocker.** Its `stats.load()` call in `stores/tickets.ts::assign()` (its task 6) stays where it is; task 8 notes why unassign leaves it alone.
- **TM-51 and TM-52 (E8, sprint 5) own every line of mail in this system, and they do not exist.** `backend/app/` has **no** `Events`, `Notifications` or `Mail` directory. `QUEUE_CONNECTION=database` is already in `backend/.env.example:47` and the `jobs`/`failed_jobs` tables are migrated (`0001_01_01_000002_create_jobs_table.php`), but there is no worker documentation, no mailable and no layout. **AC3 is therefore split — see the decision below.**
- **TM-46 (E7, sprint 4) owns the timeline, and it does not exist.** There is no `GET /api/v1/tickets/{ticket}/activities` and no timeline component. **AC2's "shown in the timeline" is a forward constraint on TM-46**, exactly as Story 24's AC2 statistics clause was a forward constraint on TM-29.
- **No new composer or npm dependency, and no migration.** `ticket_activities.meta` is a MySQL `json` column (measured) and holds the reason. `tickets.assigned_to` is already nullable with `ON DELETE SET NULL` (`…_create_tickets_table.php:23`).
- **No route change**, so `RouteAuthorizationTest` is untouched. `tickets.assign` stays classified `admin-policy`.
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An admin moves a ticket to someone else or hands it back to the queue, and the record says why.

1. `POST /api/v1/tickets/{ticket}/assign` accepts **`assigned_to: null`** and returns the ticket to the queue.
2. An **optional `reason`** (≤ 500 characters) is stored on the activity row as `meta.reason`, for assign, reassign and unassign alike.
3. The **new assignee is notified exactly once, and the previous assignee is never notified** — implemented here as a single dispatched domain event, with the mailable landing in TM-52.
4. Reassign and unassign are both covered by feature tests.

**Not in scope.** **No mail is sent and no mailable, notification, layout or queue worker is written** — TM-51 and TM-52 own all of it, in sprint 5, and TM-57's first criterion forbids sending inline from a request. **No timeline rendering** — TM-46 owns that; this story ships the storage contract and documents it. No `reason` on the claim endpoint (Story 27 takes no body). No reason on status changes or escalation (TM-38, TM-41). No reason history on the ticket itself — `tickets.escalation_reason` is current state for escalation only and **must not** be reused for assignment. No bulk reassign, no "reassign all of this agent's tickets" (that is the shape TM-35 will surface, not solve).

---

## Product rules (from story)

| Request | Before this story | After this story |
|---|---|---|
| `{"assigned_to": 5}` on an unassigned ticket | `200`, `assigned` row | Unchanged, plus `meta.reason` when sent |
| `{"assigned_to": 5}` on a ticket held by 3 | `200`, `assigned` row with both ids | Unchanged, plus `meta.reason` |
| `{"assigned_to": null}` | **`422`** required | **`200`**, `unassigned` row, `assigned_to` is `NULL` |
| `{}` — field absent | `422` required | **`422`** `present` — "send it explicitly" |
| `{"reason": "..."}` | `422` prohibited | Stored as `meta.reason`; `> 500` chars is a `422` |
| `{"assigned_to": null}` on an already-unassigned ticket | — | `200`, **no** activity row, `updated_at` untouched |
| Notification | none | **One** event for the new assignee. **None** on unassign. **None** to the previous assignee. |

---

## Decision — AC3 delivers the rule and the dispatch point; TM-52 attaches the mail

AC3 says *"Reassignment notifies the new assignee and does not notify the previous one twice."* Taken as "send an email", it is **not buildable in sprint 3**, and building it anyway would have to be torn out:

- **TM-51 (sprint 5)** owns the queue driver, the worker documentation and the Mailpit guarantee.
- **TM-52 (sprint 5)** owns *"Notify an agent when a ticket is assigned"*, down to *"Assignment dispatches a queued notification to the new assignee only"* and *"A feature test asserts the notification is queued rather than sent synchronously"*.
- **TM-57 (sprint 5)** states outright: *"Every mailable is queued; no notification is sent inline during a request"* and *"Notifications dispatch after the database transaction commits."*

So a synchronous `Mail::send()` here would be deleted by TM-57, and building the queue now would swallow TM-51 whole into a 3-point story.

**What this story delivers instead: `App\Events\TicketAssigned`, dispatched exactly once, after the transaction commits, only when a new assignee was actually set.** That is the whole of AC3's *rule* — who is notified, how many times, and when — as behaviour a feature test can assert with `Event::fake()`. **TM-52's remaining job is one listener and one mailable.**

What is delivered vs. deferred, stated plainly so nobody has to guess:

| AC3 clause | Here | TM-52 |
|---|---|---|
| "notifies the new assignee" | `TicketAssigned` dispatched with the assignee's id | Queued notification + mailable |
| "does not notify the previous one twice" | No event carries the previous assignee; **unassign dispatches nothing** | Nothing to add |
| "queued, not synchronous" | — | Its own criterion |
| "after commit" | Dispatched **outside** `DB::transaction()` | Keeps it that way |

**Do not add a listener, a notification class, a mailable, a `ShouldQueue`, or a `MAIL_*` change.** Verification step 8 checks Mailpit is **empty** after exercising the whole story.

## Decision — "not twice" means the previous assignee is notified **zero** times

*"Does not notify the previous one twice"* is ambiguous on its own. **TM-52 settles it: *"Unassigning sends no email to anyone."*** The reading consistent with both stories is therefore the simple one: **only the new assignee is ever notified about an assignment; the previous holder is not notified at all.** Zero satisfies "not twice", and it means there is no dedup bookkeeping to get wrong — the event simply does not carry the previous assignee.

Consequence to state in the PR description: **an agent whose ticket is taken away learns about it in the app, not by email.** If the team wants a courtesy "removed from your queue" notice, that is a TM-52 conversation and one more event away; **do not add `TicketUnassigned` speculatively** — nothing would consume it.

**Related, and worth writing down for TM-52:** Story 27's `POST /tickets/{ticket}/claim` must **not** dispatch `TicketAssigned`. TM-52's third criterion is *"No email is sent when a user assigns a ticket to themselves"*, and a claim is exactly that. Also, `$targetId === $actorId` is currently **unreachable** on `/assign`, because TM-31's rule requires the target to be an active **agent** and only admins may call it — so task 4 adds **no** self-assign guard. If that rule is ever relaxed, TM-52's criterion is where the guard belongs.

## Decision — AC2 stores the reason now, TM-46 renders it

`meta.reason` on the activity row is the whole storable half of AC2, and it is delivered. *"Shown in the timeline"* needs `GET /api/v1/tickets/{ticket}/activities`, which is **TM-46 (sprint 4)**.

So this story: writes `meta.reason`, **documents the exact key in `docs/api-contract.md`** so TM-46 renders it without guessing, and adds a note that TM-46's prose rendering must include it. It does **not** invent a placeholder timeline, and it does **not** surface the reason on the detail page — the detail page shows current state, and a "last assignment reason" field there would be a second, competing source of history.

---

## Context — Read These Files First

1. `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php` (Story 26's task 2) — `rules()` has `'assigned_to' => ['required', 'integer', Rule::exists(…)]` and `'reason' => ['prohibited']`; `messages()` has `assigned_to.required`, `assigned_to.exists` and `reason.prohibited` — the last one literally reads *"Assignment reasons arrive with TM-34."* **Task 3 rewrites three of those five lines.**
2. `backend/app/Http/Controllers/Api/V1/TicketController.php` — Story 26's `assign()`. Read its `lockForUpdate()` + `refresh()` + `$previousId` block and its `getDirty() === []` early return; **task 4 changes what goes into the transaction's return value and adds one dispatch after it.** The `->fresh()->load([…])` tail is unchanged.
3. `backend/app/Services/ActivityRecorder.php` — `record()` (**13–19**) fills the five defaults, and **`recordMany()` json-encodes `meta` with `JSON_UNESCAPED_UNICODE` (line 34)**, which is why an Arabic reason is readable in the raw column (measured).
4. `backend/app/Enums/TicketActivityEvent.php` — by the time this story runs it holds `Created`, `CategoryChanged`, and one case each from Stories 23, 24, 26 and 27. **Append only.**
5. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — `reassignTickets()` at **86–98** remains the reference for the activity-row shape: `field`, stringified `old_value`/`new_value`, and human context in `meta` (`reason`, `from_name`, `to_name`). **`meta.reason` there is a machine string (`'category_deleted'`); here it is free text from a human.** Same key, different provenance — task 5's docs note says so.
6. `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php` — **line 19: `$table->json('meta')->nullable()`**, and **17–18**: `old_value` / `new_value` are nullable `text`. No migration is needed for any of this.
7. `frontend/src/components/TicketAssignDialog.vue` (Story 26's task 11) — the agent list, the 300 ms debounced `listUsers` call, `selected`, `errors`/`error`, and the `ticket-assign-confirm` / `ticket-assign-cancel` buttons. **Task 7 adds two controls to it and changes what `confirm()` sends.**
8. `frontend/src/stores/tickets.ts` — Story 26's `assign()`, and Story 28's `void useStatsStore().load()` inside it. Task 8 widens the signature only.
9. [`../ticket-creation-tracking/22-story-ticket-detail-page-TM-26.md`](../ticket-creation-tracking/22-story-ticket-detail-page-TM-26.md) — its `can.assign` row reads *"Admin only. TM-31 owns the endpoint; E5-S2 will relax it for self-claim."* **Story 27 superseded that**; nothing here changes `can.assign`.

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** through a throwaway `RefreshDatabase` test. Do not re-derive them.

- **`['present', 'nullable', 'integer', Rule::exists(…)]` is exactly the rule AC1 needs.** Measured:

  | `assigned_to` | Fails | Message |
  |---|---|---|
  | `null` | **no** | — |
  | **absent** | **yes** | `The assigned to field must be present.` |
  | active agent | no | — |
  | inactive agent | yes | `The selected assigned to is invalid.` |
  | `0` | yes | `The selected assigned to is invalid.` |
  | `"5"` (numeric string) | no | — |
  | `""` (empty string) | **no** | — **see the next fact** |

  `nullable` short-circuits `exists` on `null`, and **`present` is what stops an empty body from silently unassigning** — which `required` could not express and `sometimes` would have allowed.

- **`assigned_to: ""` unassigns the ticket, and the server cannot tell it apart from an explicit `null`.** Measured through a real HTTP request: `ConvertEmptyStringsToNull` (in the global middleware stack) rewrote `""` to `null` **before** validation ran — `{"has":true,"value":null,"is_null":true}`. **So no server-side rule can distinguish "the client meant null" from "the client left a select empty".** The mitigation is client-side and documentary, not a validation rule: task 7 never emits `""`, task 9 documents the behaviour, and test 8 pins it so nobody is surprised later. **Do not try to fix this by removing `ConvertEmptyStringsToNull`** — it is Laravel's default and `reason: ""` relies on it becoming `null` rather than an empty-string reason.

- **Unassigning is already a clean `getDirty()`.** Measured: on a ticket held by user 2, `$ticket->assigned_to = null` gives `{"assigned_to": null}` with `getOriginal('assigned_to')` → `2` (an **`int`**). Setting `null` on an **already-unassigned** ticket gives `{}` — so Story 26's early return covers the idempotent unassign with no new code, and `updated_at` stays put.

- **`ticket_activities.meta` is a MySQL `json` column, and an Arabic-plus-emoji reason round-trips intact and human-readable.** Measured: the raw column held `{"reason": "إعادة تعيين إلى فريق آخر 🎫", "to_name": null, "from_name": "Nadia"}` — unescaped, because `ActivityRecorder` passes `JSON_UNESCAPED_UNICODE`. A **500-character Arabic** reason came back at `mb_strlen` 500 / 1000 bytes, byte-identical. **`max:500` is a character limit, not a byte limit**, and the `json` column has no practical ceiling at this size — so no migration and no `mb_` gymnastics.

- **A null `new_value` stores as SQL `NULL`, not the string `"null"`.** Measured on an unassign row. So an `unassigned` row's `new_value` is symmetric with the `old_value` of a first assignment, which Story 26 measured as `null`. **Only `record()` gives you that** — `recordMany()` writes the literal string `"null"` into `meta` on an omitted key, per Story 26's note.

- **`DB::transactionLevel()` is `1` under `RefreshDatabase`.** Re-confirmed across this feature: `ActivityRecorder`'s guard is inert in the suite, so **an `assign()` that lost its `DB::transaction()` still passes naive tests.** Story 26's atomicity test is the guard; task 4 must not break it.

---

## Backend Tasks

### 1 — One new event case

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append. **Do not reorder or rename.**

```php
case Unassigned = 'unassigned';
```

- **A distinct case, not `Assigned` with a null `new_value`.** It follows the rule Story 27 set for `Claimed`: distinct verbs get distinct cases, so TM-46 renders *"returned the ticket to the queue"* from `event` rather than from a null check three columns away. It also keeps TM-35's `event = 'assigned'` counts meaning assignments.
- **A first assignment and a reassignment both stay `Assigned`**, distinguished by `old_value` being null or not — that is Story 26's shape and it does not change.
- `event` is `varchar(50)`: **no migration.**

### 2 — The domain event

**Create file: `backend/app/Events/TicketAssigned.php`** — this creates `backend/app/Events/`, the first directory of its kind in the project.

```php
<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket gained a new assignee. Dispatched once per actual change, after the
 * transaction commits, and NEVER on unassign — TM-52's fourth criterion says
 * "Unassigning sends no email to anyone", and TM-34's third says the previous
 * assignee is not notified.
 *
 * TM-52 attaches the queued notification. There is deliberately no listener,
 * no mailable and no ShouldQueue here: TM-57 requires notifications to be
 * queued and to fire after commit, and TM-51 owns the queue infrastructure.
 * Story 27's claim endpoint must NOT dispatch this — a self-claim is the
 * self-assign case TM-52 excludes.
 */
class TicketAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $assigneeId,
        public readonly int $actorId,
        public readonly ?string $reason,
    ) {}
}
```

- **Scalar ids, not models.** TM-52 will queue its listener, and a queued listener re-fetches anyway; ids avoid a serialised model going stale between dispatch and handling.
- **No `ShouldBroadcast`, no `ShouldQueue`, no `SerializesModels`.** Nothing here is queued yet, and adding the marker interfaces now would make the event's behaviour change the moment a worker starts.
- **No listener and no `AppServiceProvider` registration.** Laravel auto-discovers listeners; with none written the dispatch is a no-op with a `Event::fake()`-observable effect, which is precisely what AC3 needs in sprint 3.
- **`reason` rides on the event** so TM-52's email can quote the handover note without re-reading `ticket_activities`.

### 3 — Relax `AssignTicketRequest`

**File: `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php`**

`rules()` — replace the `assigned_to` and `reason` entries:

```php
'assigned_to' => [
    // `present` + `nullable`, not `required`: null is a legal value (AC1 —
    // return the ticket to the queue) but an absent field must still be a 422,
    // so an empty body can never silently unassign. Measured: `required`
    // rejects null, `sometimes` would accept {}.
    'present',
    'nullable',
    'integer',
    Rule::exists('users', 'id')
        ->where('role', UserRole::Agent)
        ->where('is_active', true),
],
// AC2. Free text from a human, stored as meta.reason on the activity row.
// 500 characters, measured safe as 500 Arabic characters / 1000 bytes in the
// json column. `nullable` because ConvertEmptyStringsToNull turns "" into null.
'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
```

`messages()` — replace three entries:

```php
'assigned_to.present' => 'Send assigned_to explicitly. Use null to return the ticket to the queue.',
'assigned_to.exists' => 'That user is not an active agent.',   // unchanged
'reason.max' => 'Keep the reason to 500 characters or fewer.',
```

**Delete `assigned_to.required` and `reason.prohibited`** — both are dead once the rules change, and leaving `reason.prohibited` in place would be an actively false statement about the API.

**`authorize()` is unchanged.** `Gate::allows('assign', $ticket)` still gates on `TicketPolicy::assign()`, which is still admin-only after Story 27.

### 4 — Unassign, the reason, and the one dispatch

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add to the imports:

```php
use App\Events\TicketAssigned;
```

Rework Story 26's `assign()`. **Keep its `authorize()`, its `lockForUpdate()` + `refresh()`, its `getDirty()` early return and its `->fresh()->load([…])` tail exactly as they are** — only the marked parts change.

```php
public function assign(AssignTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
{
    $this->authorize('assign', $ticket);
    $actorId = $request->user()->getKey();
    // input(), not integer(): integer() casts null to 0, which would then fail
    // the exists rule's own guarantee and try to assign user 0.
    $targetId = $request->input('assigned_to') === null ? null : (int) $request->input('assigned_to');
    $reason = $request->input('reason');

    $changed = DB::transaction(function () use ($ticket, $recorder, $actorId, $targetId, $reason): bool {
        Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
        $ticket->refresh();
        $previousId = $ticket->assigned_to;
        $ticket->assigned_to = $targetId;
        if ($ticket->getDirty() === []) {
            return false;   // covers "same agent again" AND "unassign an unassigned ticket"
        }
        $ticket->save();

        $recorder->record(
            $ticket->getKey(),
            $targetId === null ? TicketActivityEvent::Unassigned : TicketActivityEvent::Assigned,
            [
                'user_id' => $actorId,
                'field' => 'assigned_to',
                'old_value' => $previousId === null ? null : (string) $previousId,
                'new_value' => $targetId === null ? null : (string) $targetId,
                'meta' => [
                    // AC2. Absent rather than null when nothing was given, so a
                    // reader can tell "no reason offered" from "reason cleared".
                    ...($reason === null ? [] : ['reason' => $reason]),
                    'from_name' => $previousId === null ? null : User::query()->whereKey($previousId)->value('name'),
                    'to_name' => $targetId === null ? null : User::query()->whereKey($targetId)->value('name'),
                ],
            ],
        );

        return true;
    });

    // AC3, and the only new behaviour outside the transaction. Dispatched here,
    // not inside, because TM-57 requires notifications to fire after commit —
    // an email must never reference a row that rolled back. Nothing is
    // dispatched on unassign (TM-52: "Unassigning sends no email to anyone") and
    // nothing carries the previous assignee, so they are notified zero times.
    if ($changed && $targetId !== null) {
        TicketAssigned::dispatch($ticket->getKey(), $targetId, $actorId, $reason);
    }

    return TicketResource::make($ticket->fresh()->load([
        'requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy',
    ]))->response();
}
```

Six things that are load-bearing:

- **`$request->input(...)` with an explicit null check, never `$request->integer(...)`.** `integer()` casts `null` to `0`, and `0` is not a valid user — the request would try to assign user 0 and violate the foreign key. Story 26's `assign()` used `integer()` legitimately because null was impossible there; **that line must change.**
- **The transaction returns a plain `bool`.** Story 26's version had nothing to return; a three-valued return would be needed only if `null` were both "unchanged" and "unassigned" — the separate `$targetId` variable outside avoids that entirely.
- **`getDirty() === []` now covers two idempotent cases**, not one: re-assigning the same agent (Story 26's AC5) and unassigning an already-unassigned ticket. Same code, same `200`, same absent activity row. Add that to the comment.
- **`meta.reason` is omitted, not set to `null`, when no reason was given.** `array_key_exists('reason', $meta)` then means "a human wrote something", which is what TM-46 needs to decide whether to render a quote block. Test 6 asserts the absence.
- **The name lookups stay inside the transaction and stay conditional.** An unassign costs one lookup (`from_name`), a first assignment costs one (`to_name`), a reassignment costs two. Unchanged from Story 26 in kind.
- **The dispatch is after `DB::transaction()` returns, guarded on `$changed`.** A no-op assign dispatches nothing — otherwise a double-click would email the assignee twice, which is the literal thing AC3 forbids. Test 11 covers it.

### 5 — Document the changes

**File: `docs/api-contract.md`**

Story 26 wrote the `POST /api/v1/tickets/{ticket}/assign` subsection. **Replace its body table and add three bullets**; do not add a second subsection.

```markdown
| Body field | Rules |
|---|---|
| `assigned_to` | **Required to be present**, and **nullable**. An integer must be an existing user with `role = agent` and `is_active = true`. **`null` unassigns** and returns the ticket to the queue. An absent field is a `422`, so an empty body cannot silently unassign. |
| `reason` | Optional free text, ≤ **500 characters**. Stored as `meta.reason` on the activity row. |
```

```markdown
- **`assigned_to: ""` unassigns.** Laravel's `ConvertEmptyStringsToNull`
  middleware rewrites `""` to `null` before validation, so the API cannot tell an
  empty form field from an explicit `null`. Clients must send `null` deliberately
  and must never submit an empty assignee field.
- **Unassigning writes `event = 'unassigned'`** with `old_value` the previous
  user id, `new_value` `null`, `meta.from_name` their name and `meta.to_name`
  `null`. A first assignment and a reassignment both stay `event = 'assigned'`,
  told apart by whether `old_value` is null.
- **`meta.reason` is absent when no reason was given**, not `null` — so a reader
  can distinguish "no reason offered" from "reason cleared". Note that
  `meta.reason` on a `category_changed` row is a machine string
  (`'category_deleted'`), while here it is free text written by a person.
- **Notifications.** A successful assignment or reassignment dispatches
  `App\Events\TicketAssigned` (ticket id, new assignee id, actor id, reason)
  **after the transaction commits**. **Unassigning dispatches nothing, and the
  previous assignee is never notified.** No email is sent yet: **TM-52** attaches
  the queued notification and **TM-51** owns the queue and Mailpit guarantees.
  A no-op assign dispatches nothing, so a double submit cannot double-notify.
```

**File: `README.md`**

**No change.** The queue-worker and Mailpit sections are TM-51's third criterion; adding them here would take that story's only documentation deliverable.

---

## Frontend Tasks

### 6 — Widen the API call

**File: `frontend/src/api/tickets.ts`**

Replace Story 26's `assignTicket`:

```ts
export async function assignTicket(id: number, assignedTo: number | null, reason?: string): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/assign`, { assigned_to: assignedTo, ...(reason ? { reason } : {}) }); return data.data }
```

- **`assigned_to` is always in the body**, because the server requires it `present`.
- **`...(reason ? { reason } : {})`** — an empty or absent reason omits the key entirely rather than sending `""`. It would arrive as `null` anyway (measured), but omitting it keeps the request honest and the server's `meta.reason` absent rather than present-and-null.
- **`number | null`, never `number | ''`.** The type is the guard against the measured empty-string footgun.

### 7 — Reason and Return-to-queue in the dialog

**File: `frontend/src/components/TicketAssignDialog.vue`**

Add one field, one button, and change what `confirm()` sends.

| Element | `data-testid` | Notes |
|---|---|---|
| Reason textarea | `ticket-assign-reason` | `v-model.trim="reason"`, `maxlength="500"`, label **"Reason (optional)"** |
| Return to queue | `ticket-assign-unassign` | **`v-if="ticket.assignee"`**, `:disabled="store.assigning"`, label **"Return to queue"** |

```ts
const reason = ref('')
async function submit(assignedTo: number | null): Promise<void> {
  try {
    await store.assign(props.ticket.id, assignedTo, reason.value || undefined)
    emit('assigned')
  } catch (caughtError) {
    errors.value = validationErrors(caughtError)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(caughtError)
  }
}
```

`ticket-assign-confirm` calls `submit(selected)`; `ticket-assign-unassign` calls `submit(null)`.

- **One `submit()` for both paths.** The endpoint, the error handling and the reason field are identical; two functions would drift.
- **`ticket-assign-confirm` keeps `:disabled="selected === undefined || store.assigning"`** from Story 26. **This is what makes the empty-string footgun unreachable from the UI**: there is no code path that passes `''`, because `selected` is `number | undefined` and `undefined` cannot be submitted. Test 16 asserts the payload is exactly `null` on the unassign path.
- **"Return to queue" only when the ticket is assigned.** On an unassigned ticket it would be a guaranteed no-op, and a button that does nothing invites a bug report.
- **The reason applies to whichever button is pressed**, so an admin can annotate an assignment as easily as a handover. AC2 says "the activity row", not "the unassign row".
- **`reason` is not cleared between submits** — the dialog unmounts on `assigned` (Story 26's task 13 uses `v-if`), so there is nothing to reset.

### 8 — Widen the store action

**File: `frontend/src/stores/tickets.ts`**

```ts
async function assign(id: number, assignedTo: number | null, reason?: string): Promise<void> {
```

Pass both through to `assignTicket`. **Everything else in the action stays**: the `assigning` flag, the `await loadTicket(id)` refresh, and Story 28's `void useStatsStore().load()`.

- **Keep the stats refresh on the unassign path too.** The actor is an admin whose own `mine_open` is unaffected, so it is a no-op for them — but it is one call, it is already there, and removing it for one branch would be a special case with no benefit. **The previous assignee's badge only updates on their next navigation**, which is inherent to a per-caller figure and is not this story's problem to solve.
- **Do not catch here.** The dialog renders the error, matching Story 26.

---

## Edge Cases & Failure Modes

- **`{"assigned_to": null}` on an assigned ticket** → `200`, `assigned_to` is `NULL`, one `unassigned` row, **no event dispatched**. AC1. Tests 2 and 10.
- **`{"assigned_to": null}` on an already-unassigned ticket** → `200`, **no** activity row, `updated_at` untouched, no event. Measured: `getDirty()` is `{}`. Test 5.
- **`{}` — the field absent** → `422` "Send assigned_to explicitly…". **Measured**; this is why the rule is `present` and not `sometimes`. Test 4.
- **`{"assigned_to": ""}`** → **unassigns**, because `ConvertEmptyStringsToNull` rewrites it to `null` before validation. **Measured, and unfixable server-side.** Mitigated by the typed client (task 6/7) and documented (task 5). Test 8 pins the behaviour so a future reader finds it deliberate.
- **`{"assigned_to": 0}`** → `422`. `0` is an integer and fails `exists`. Test 9.
- **A 501-character reason** → `422` on `reason`, and **`assigned_to` is not applied** — validation runs before the handler. Test 7.
- **`{"reason": ""}`** → arrives as `null` (measured), so `meta.reason` is **absent**, not an empty string. Test 6.
- **A 500-character Arabic reason** → stored intact, readable unescaped in the raw `json` column (measured, 1000 bytes). `max:500` counts characters. Test 12.
- **A reason with HTML or a script tag** → stored verbatim; `json` does no escaping and neither should the API. **Escaping is the renderer's job**, which is TM-46's and TM-64's (its fourth criterion names the timeline explicitly). **Do not sanitise on the way in** — it would corrupt a legitimate reason mentioning `<`. Record it as a forward constraint on TM-46.
- **Reassigning to the same agent with a new reason** → `200`, and **no activity row**, because `getDirty()` is empty. **The reason is silently dropped.** That is the honest consequence of Story 26's AC5 idempotency rule: nothing changed, so there is nothing to annotate. Test 13 pins it, and it is called out in the docs so it is not discovered as a bug.
- **Two admins unassign at once** → `lockForUpdate()` + `refresh()` serialises the read, so the second sees `null` and returns the no-op `200` with no second row. Unchanged from Story 26's mechanism.
- **A double-clicked Assign button** → the second request is a no-op `getDirty()` return, so `$changed` is false and **no second event is dispatched**. This is the concrete mechanism behind "does not notify twice". Test 11.
- **The assignee is deactivated between opening the dialog and pressing Assign** → `422` "That user is not an active agent." Unchanged from Story 26.
- **An event dispatched with no listener** → a no-op today. Once TM-52 adds a listener, a mail-transport failure must not roll back the assignment — which is why the dispatch is outside the transaction, and which is TM-57's third criterion.
- **`Event::fake()` in an unrelated test suite** → nothing else dispatches events, so no existing test changes behaviour. Task 4 is the project's first `dispatch()` call.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketAssignTest.php` (Story 26's file; extend)

Story 26's 16 tests all stay green **except** two that must be updated, called out below. `$this->seed()` + `RefreshDatabase` as before.

1. **Update Story 26's `test_missing_and_malformed_assigned_to_are_rejected`.** Its `{"assigned_to": null}` case now expects **`200`**, and `{}` now expects the **`present`** message instead of `required`. **Do not delete the test** — split it into tests 2 and 4 below and keep the non-integer case where it is.
2. `test_admin_unassigns_a_ticket` — **AC1.** Assign to an agent, then `{"assigned_to": null}` → `200`, `assertJsonPath('data.assignee', null)`, and `tickets.assigned_to` is `NULL` in the database.
3. `test_unassign_writes_an_unassigned_row` — `event = 'unassigned'`, `old_value === (string) $agent->id`, **`new_value` is SQL `NULL`**, `meta.from_name === $agent->name`, `meta.to_name` null, `user_id === $admin->id`.
4. `test_absent_assigned_to_is_rejected` — `{}` → `422`, message "Send assigned_to explicitly. Use null to return the ticket to the queue."
5. `test_unassigning_an_unassigned_ticket_is_idempotent` — `200`, **zero** activity rows, `updated_at` unchanged (capture before, compare after).
6. `test_reason_is_stored_and_omitted_when_blank` — **AC2.** With `reason: 'Going on leave'` → `meta.reason` equals it. With `reason: ''` and with the key absent → **`assertArrayNotHasKey('reason', $row->meta)`**, not "equals null".
7. `test_reason_over_500_characters_is_rejected` — `422` on `reason`, **and `assigned_to` was not applied**.
8. `test_empty_string_assigned_to_unassigns` — **the measured `ConvertEmptyStringsToNull` behaviour.** `{"assigned_to": ""}` on an assigned ticket → `200` and unassigned. Docblock it as *deliberate and unfixable server-side*, with a pointer to the client-side guard.
9. `test_zero_assigned_to_is_rejected` — `422`, not a foreign-key error.
10. `test_unassign_dispatches_no_event` — **AC3.** `Event::fake()`; unassign → `Event::assertNotDispatched(TicketAssigned::class)`.
11. `test_assignment_dispatches_exactly_one_event_and_a_repeat_dispatches_none` — **AC3, the "not twice" mechanism.** `Event::fake()`; assign → `assertDispatchedTimes(TicketAssigned::class, 1)` with the correct `assigneeId`, `actorId` and `reason`; assign the **same** agent again → still exactly **1** in total.
12. `test_arabic_reason_round_trips` — a 500-character Arabic reason; assert `mb_strlen` is 500 after reading it back and that the value is byte-identical. **The utf8mb4 guard CLAUDE.md asks for.**
13. `test_reassigning_the_same_agent_drops_the_reason` — `200`, no new row, and no `meta.reason` anywhere. Pins the documented consequence of AC5's idempotency.
14. `test_reassignment_records_both_ids_and_the_reason` — **AC4's reassign half.** A → B with a reason: one new `assigned` row carrying `old_value`, `new_value`, `from_name`, `to_name` **and** `reason`, plus exactly one event naming **B only**.
15. `test_event_is_dispatched_after_commit` — force a failure after `save()` (throwing `ActivityRecorder`); assert the exception propagates, `assigned_to` is unchanged, **and** `Event::assertNotDispatched(TicketAssigned::class)`. **This is the test that fails if the dispatch is moved inside the transaction** — note that in the docblock.
16. Story 26's `test_assignment_is_atomic` and `test_reason_is_prohibited` — **the second must be deleted**, and its deletion noted in the PR description as this story's whole point. The first stays unchanged.

### Backend — no other file changes

**No new route, so `RouteAuthorizationTest` is untouched. No policy, resource, model, migration or seeder change.** If any of those files appears in the diff, something is wrong.

### Frontend — `frontend/src/components/TicketAssignDialog.spec.ts` (Story 26's file; extend)

17. `ticket-assign-reason` renders, is `maxlength=500`, and its value reaches `assignTicket` as the third argument.
18. A blank reason omits the key: `assignTicket` is called with `undefined`, and the request body has **no** `reason` key.
19. `ticket-assign-unassign` renders when `ticket.assignee` is set and is **absent** when it is `null`.
20. Clicking it calls `store.assign(id, null, …)` — **asserting the second argument is exactly `null`, not `''` or `undefined`**. The client-side half of the empty-string guard.
21. A `422` on `reason` renders in `ticket-assign-error` (or the field error) and the dialog stays mounted.
22. Story 26's existing assign-path tests still pass with the widened signature — run them, do not rewrite them.

### Frontend — `frontend/src/stores/tickets.spec.ts` (extend)

23. `assign(id, null)` forwards `null` to `assignTicket` and still calls `loadTicket` and `useStatsStore().load()`.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**, and **`tm-mailpit` healthy** — you will be checking it is empty.
2. **Gate:** from `backend/`, `php artisan test --filter=TicketAssignTest` green **before** you change anything.
3. **Backend formats:** `./vendor/bin/pint --test`.
4. **Backend tests:** `composer test`, then `php artisan test --filter=TicketAssignTest`. Expect **+13 tests** over Story 26's 16, **minus** the deleted `test_reason_is_prohibited`.
5. **Prove test 15 earns its place:** move `TicketAssigned::dispatch(...)` **inside** the `DB::transaction` closure, re-run `--filter=test_event_is_dispatched_after_commit`, confirm it **fails**, restore.
6. **Prove the `input()`-not-`integer()` change earns its place:** put `$request->integer('assigned_to')` back, re-run `--filter=test_admin_unassigns_a_ticket`, and confirm it fails trying to assign user **0**. Restore.
7. **Prove test 4 earns its place:** change `present` to `sometimes`, re-run, confirm `{}` now returns `200` and silently unassigns. Restore.
8. **Mailpit must be empty.** Open http://localhost:8025, clear it, then run every by-hand step below. **At the end there must be zero messages.** If anything arrived, a mailable was written and TM-51/TM-52's scope was taken.
9. **Backend by hand.** `php artisan serve`, admin token, a ticket held by agent A:
   - `-d '{"assigned_to":null}'` → `200`, `data.assignee` is `null`. `SELECT assigned_to FROM tickets WHERE id=<id>` → `NULL`.
   - `SELECT event, old_value, new_value, meta FROM ticket_activities WHERE ticket_id=<id> ORDER BY id DESC LIMIT 1` → `unassigned`, `old_value` A's id, **`new_value` NULL**, `meta.from_name` A's name, `meta.to_name` null.
   - `-d '{"assigned_to":null}'` again → `200`, and the activity count is **unchanged**.
   - `-d '{}'` → `422` "Send assigned_to explicitly…".
   - `-d '{"assigned_to":<B>,"reason":"Ahmed is on leave until Sunday"}'` → `200`, and the new row's `meta.reason` is that sentence.
   - `-d '{"assigned_to":<B>,"reason":"..."}'` **again** → `200`, activity count unchanged, reason **dropped** (the documented no-op).
   - `-d '{"assigned_to":null,"reason":"إعادة إلى قائمة الانتظار 🎫"}'` → `200`, then read the **raw** column: the Arabic and the emoji must be legible, not `\uXXXX`.
   - `-d '{"assigned_to":null,"reason":"'"$(python3 -c 'print("x"*501)')"'"}'` → `422` on `reason`, and `assigned_to` unchanged.
   - `-d '{"assigned_to":""}'` → `200` and **unassigned**. Confirm the documented footgun behaves as documented.
   - `-d '{"assigned_to":0}'` → `422`.
   - With an **agent** token: any of the above on a real ticket → `403`.
10. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test` — **+7 tests** across two extended spec files. Then `npx prettier --check src/api/tickets.ts src/stores/tickets.ts src/components/TicketAssignDialog.vue src/components/TicketAssignDialog.spec.ts src/stores/tickets.spec.ts`.
11. **Frontend by hand:** `npm run dev`, as an **admin**:
    - Open an **unassigned** ticket → Assign → the modal shows the reason field and **no "Return to queue"** button.
    - Assign with a reason → the assignee line updates with no reload.
    - Re-open Assign → **"Return to queue" is now present**. Click it with a reason → the assignee line reads **Unassigned**.
    - Open the browser network tab and repeat the unassign → the request body is `{"assigned_to":null,"reason":"…"}`. **Confirm it is `null` and not `""`.**
    - Type 500 characters in the reason and submit → accepted. Confirm the textarea will not take a 501st character (`maxlength`).
    - As an **agent**: no Assign button at all.
12. **Regression:** Story 26's assign flow is unchanged for a plain assignment (`200`, one `assigned` row, `can.assign` correct per role); Story 27's claim still returns `409` on a contested claim and **dispatches no event**; Story 28's `/my-tickets` badge still updates after an assign.

---

## Done Criteria

- [ ] `assigned_to: null` unassigns and returns `200`; the field being **absent** is a `422` naming the explicit-null requirement.
- [ ] Unassigning writes one `event = 'unassigned'` row with `old_value` the previous id, `new_value` SQL `NULL`, `meta.from_name` set and `meta.to_name` null.
- [ ] A first assignment and a reassignment both stay `event = 'assigned'`, told apart by `old_value`.
- [ ] `reason` is optional, capped at **500 characters**, stored as `meta.reason`, and **absent from `meta` rather than null** when not given.
- [ ] A 500-character **Arabic** reason round-trips byte-identically and is legible unescaped in the raw `json` column.
- [ ] Unassigning an already-unassigned ticket returns `200`, writes nothing, and leaves `updated_at` alone.
- [ ] `App\Events\TicketAssigned` exists, carries `ticketId`, `assigneeId`, `actorId` and `reason`, and is dispatched **exactly once** per real assignment, **outside** the transaction — proven by a test that fails when the dispatch is moved inside.
- [ ] **No event is dispatched on unassign, on a no-op assign, or by Story 27's claim endpoint**, and no event carries the previous assignee.
- [ ] **No mailable, notification, listener, `ShouldQueue`, queue worker or `MAIL_*` change was written**, and **Mailpit is empty** after the full by-hand pass. TM-51 and TM-52 keep their scope.
- [ ] `AssignTicketRequest`'s `reason.prohibited` rule **and** its message are deleted, along with `assigned_to.required`.
- [ ] `$request->integer('assigned_to')` is gone — proven necessary by reverting it and watching the unassign test try to assign user `0`.
- [ ] `assigned_to: ""` unassigns, is **tested** as deliberate, and is documented as unfixable server-side because of `ConvertEmptyStringsToNull`.
- [ ] Reassigning the same agent with a new reason drops the reason — tested and documented, not discovered later as a bug.
- [ ] The dialog has an optional reason field (`maxlength=500`) and a **Return to queue** button shown only when the ticket is assigned; the unassign path sends exactly `null`.
- [ ] `docs/api-contract.md`'s existing assign subsection is **updated in place** — no second subsection — and states the `null` behaviour, the `""` footgun, the `unassigned` event shape, the `meta.reason` contract, and that **TM-46 must render the reason** and **TM-64/TM-46 own escaping it**.
- [ ] `README.md` untouched; no route, policy, resource, model, migration or seeder change; `RouteAuthorizationTest` untouched.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+13 backend and +7 frontend tests** over Story 26's baseline, with one deliberately deleted test recorded in the PR description.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 30 (TM-35, agent workload overview).**
