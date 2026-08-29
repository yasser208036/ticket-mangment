# Story 29 — Reassign or unassign with a reason (Story: TM-34)

> **Revised 2026-08-29.** This plan was written in sprint 3, when the event
> system, the notification chain and the activity timeline were all still ahead
> of it. **All three have since landed.** The plan below has been re-verified
> against the code that is actually in `main` and re-scoped to what is still
> outstanding, so it reads as an implementation plan rather than a forecast.
> The original forward-looking framing is preserved only where the *reasoning*
> still constrains future work.

---

## Status

| AC | Delivered | Where |
|---|---|---|
| **AC1** — `assigned_to: null` unassigns; an absent field is a `422` | ✅ backend | `AssignTicketRequest::rules()`, `TicketController::changeAssignee()` |
| **AC2** — optional `reason` ≤ 500 chars stored as `meta.reason`, shown in the timeline | ✅ backend + ✅ rendering | `TicketController::recordAssignment()`, `TicketTimelineEntry.vue` (`timeline-reason`) |
| **AC3** — the new assignee is notified exactly once, the previous one never | ✅ end to end | `App\Events\TicketAssigned` → `SendTicketAssignedNotification` → `TicketAssignedNotification` |
| **AC4** — reassign and unassign are covered by feature tests | ✅ | `AssignTicketTest.php`, **21** tests (was 8) |
| **UI** — an admin can reassign or return a ticket to the queue | ✅ | `TicketAssignDialog.vue`, opened from `TicketActionToolbar.vue`'s now-live `action-assign` button |

**Closed 2026-08-29.** Tasks A and B are implemented; the whole story is
delivered. Everything under *Shipped shape* below is reference material.

Two deviations from the plan as written, both deliberate:

- **The agent picker reads `usersStore.agents`, filled by a new `loadAgents()`,
  not `usersStore.users`.** `users` is the admin table's paginated, searched
  state — an assignee picker hung off it would inherit whatever filter that page
  was left on, and would offer admins and deactivated accounts that `/assign`
  rejects with a `422`. `loadAgents()` asks for `role=agent, status=active` and
  keeps its own list.
- **Task B landed 13 tests rather than 11.** The extra two split the blank-reason
  case from the missing-reason case, and pin a failed assignment's rollback
  separately from the after-commit ordering.

The after-commit test asserts `DB::transactionLevel()` **at dispatch time** is
`1` (RefreshDatabase's own) rather than `2`, which is a deterministic proof that
the dispatch is outside the controller's transaction wherever it is moved
inside it. Verified by mutation: moving it in yields `2` and the test fails.

---

## Story Goal

An admin moves a ticket to someone else or hands it back to the queue, and the
record says why.

1. `POST /api/v1/tickets/{ticket}/assign` accepts **`assigned_to: null`** and
   returns the ticket to the queue.
2. An **optional `reason`** (≤ 500 characters) is stored on the activity row as
   `meta.reason`, for assign, reassign and unassign alike.
3. The **new assignee is notified exactly once, and the previous assignee is
   never notified.**
4. Reassign and unassign are both covered by feature tests.

**Not in scope.** No `reason` on the claim endpoint (Story 27 takes no body).
No reason on status changes or escalation (TM-38, TM-41). No reason history on
the ticket itself — `tickets.escalation_reason` is current state for escalation
only and **must not** be reused for assignment. No bulk reassign and no
"reassign all of this agent's tickets" — that is the shape TM-35 surfaces, not
one this story solves.

---

## Product rules

| Request | Behaviour |
|---|---|
| `{"assigned_to": 5}` on an unassigned ticket | `200`, one `assigned` row (`old_value` null), plus `meta.reason` when sent |
| `{"assigned_to": 5}` on a ticket held by 3 | `200`, one `assigned` row carrying both ids, plus `meta.reason` |
| `{"assigned_to": 5}` on a ticket already held by 5 | `200`, **no** activity row, `updated_at` untouched, **no event** — and the reason is silently dropped |
| `{"assigned_to": null}` on an assigned ticket | `200`, one `unassigned` row, `assigned_to` is `NULL`, **no event** |
| `{"assigned_to": null}` on an already-unassigned ticket | `200`, **no** activity row, `updated_at` untouched |
| `{}` — field absent | `422` `present` — "send it explicitly" |
| `{"assigned_to": ""}` | **Unassigns.** `ConvertEmptyStringsToNull` rewrites it to `null` before validation — see the measured facts |
| `{"assigned_to": 0}` | `422` on `exists`, not a foreign-key error |
| `{"reason": "…"}` | Stored as `meta.reason`; `> 500` characters is a `422` and **nothing is applied** |
| `{"reason": ""}` | Arrives as `null`; `meta.reason` is **absent**, not empty |
| Notification | **One** email to the new assignee. **None** on unassign, on a no-op, on a self-assign, or to the previous assignee |

---

## Settled decisions

### The event is the seam between assignment and mail — and it held

This story deliberately shipped `App\Events\TicketAssigned` with **no listener**,
so that AC3's *rule* (who is notified, how many times, when) was assertable with
`Event::fake()` in sprint 3 while TM-51/TM-52 owned the mail itself in sprint 5.

**That seam worked and is now closed.** `SendTicketAssignedNotification` consumes
the event and sends `TicketAssignedNotification`. What still binds:

- **The dispatch stays outside `DB::transaction()`.** TM-57 requires
  notifications to fire after commit; an email must never reference a row that
  rolled back. Moving it inside is the one change that must break a test.
- **The dispatch stays guarded on `$changed`.** A no-op assign — a double-click,
  a re-assign to the current holder — dispatches nothing. This is the concrete
  mechanism behind "does not notify twice".
- **Nothing carries the previous assignee, and unassign dispatches nothing.** The
  previous holder is notified **zero** times, which satisfies "not twice" with no
  dedup bookkeeping to get wrong.
- **The self-assign guard lives in the listener, not at the dispatch site.**
  `$event->assigneeId === $event->actorId` returns early there. `/assign` cannot
  currently reach that case — the target must be an active **agent** and only
  admins may call it — but Story 27's `/claim` is exactly a self-assign, and the
  listener is the single place every producer passes through.
- **Story 27's `/claim` must not dispatch `TicketAssigned`.** Verified: it does
  not. Keep it that way.

**Consequence, still true:** an agent whose ticket is taken away learns about it
in the app, not by email. If a courtesy "removed from your queue" notice is ever
wanted, that is one more event and a TM-52 conversation — **do not add
`TicketUnassigned` speculatively.**

### `meta.reason` is the storage contract, and TM-46 now renders it

`meta.reason` is written by `recordAssignment()` and rendered by
`TicketTimelineEntry.vue` through its `timeline-reason` block. Two properties of
the contract still matter:

- **The key is absent, not null, when no reason was given**, so a reader can tell
  "no reason offered" from "reason cleared".
- **`meta.reason` on a `category_changed` row is a machine string
  (`'category_deleted'`); here it is free text written by a person.** Same key,
  different provenance. A renderer that quotes it must treat it as user content.
- **The reason is stored verbatim** — no sanitising on the way in, which would
  corrupt a legitimate reason mentioning `<`. Escaping is the renderer's job;
  Vue's `{{ }}` interpolation does it, and `vue/no-v-html` is an enforced lint
  error, so the guard is structural.

The reason is deliberately **not** surfaced on the ticket detail sidebar. The
detail page shows current state; a "last assignment reason" field there would be
a second, competing source of history.

---

## Context — read these files first

1. `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php` — the shipped
   `present`/`nullable` + `reason` rules and their three messages.
2. `backend/app/Http/Controllers/Api/V1/TicketController.php:232` — `assign()`
   and its three private helpers, `changeAssignee()`, `recordAssignment()` and
   `userName()`, plus the `AssignmentChange` DTO it builds.
3. `backend/app/Services/ActivityRecorder.php` — `record()` fills the five
   defaults; `recordMany()` json-encodes `meta` with `JSON_UNESCAPED_UNICODE`,
   which is why an Arabic reason is readable in the raw column.
4. `backend/app/Listeners/SendTicketAssignedNotification.php` — the self-assign
   guard, the inactive-assignee skip and the soft-delete skip. Not queued; the
   notification it sends is.
5. `backend/tests/Feature/Tickets/AssignTicketTest.php` — **eight** tests. Task B
   extends this file; note the name is `AssignTicketTest`, not `TicketAssignTest`.
6. `frontend/src/api/tickets.ts:98` — `assignTicket(id, assignedTo, reason?)`,
   already widened. No change needed.
7. `frontend/src/stores/tickets.ts:91` — `assign(id, assignedTo, reason?)`,
   already widened. No change needed.
8. `frontend/src/components/TicketEscalateDialog.vue` — **the pattern task A
   copies**: `defineProps<{ ticket: TicketDetail }>()`, an `escalated`/`close`
   emit pair, local `error` + `errors` refs fed by `validationErrors()` /
   `errorMessage()`, and a full-screen overlay with a `data-testid` on the root.
9. `frontend/src/components/TicketFilterBar.vue:237` — how the SPA already
   sources a list of agents for a picker: `useUsersStore()`, guarded on
   `auth.isAdmin`. Reuse it rather than calling `listUsers` from the component.
10. `frontend/src/views/TicketDetailView.vue:184-205` — the toolbar wiring and
    the `v-if`-mounted dialog block task A extends.

---

## Measured facts

Measured against **`mysql:8.4` (`tm-mysql-test`, 3307)** through a throwaway
`RefreshDatabase` test. Do not re-derive them.

- **`['present', 'nullable', 'integer', Rule::exists(…)]` is exactly the rule
  AC1 needs.**

  | `assigned_to` | Fails | Message |
  |---|---|---|
  | `null` | no | — |
  | **absent** | **yes** | `The assigned to field must be present.` |
  | active agent | no | — |
  | inactive agent | yes | `The selected assigned to is invalid.` |
  | `0` | yes | `The selected assigned to is invalid.` |
  | `"5"` (numeric string) | no | — |
  | `""` (empty string) | **no** | — see the next fact |

  `nullable` short-circuits `exists` on `null`, and **`present` is what stops an
  empty body from silently unassigning** — which `required` could not express and
  `sometimes` would have allowed.

- **`assigned_to: ""` unassigns, and the server cannot tell it apart from an
  explicit `null`.** Measured through a real HTTP request:
  `ConvertEmptyStringsToNull` rewrote `""` to `null` **before** validation ran.
  **No server-side rule can distinguish "the client meant null" from "the client
  left a select empty".** The mitigation is client-side and documentary: the
  typed client never emits `""`, and task B pins the behaviour so nobody is
  surprised. **Do not remove `ConvertEmptyStringsToNull`** — it is a Laravel
  default, and `reason: ""` relies on it becoming `null` rather than an
  empty-string reason.

- **Unassigning is already a clean `getDirty()`.** On a ticket held by user 2,
  `$ticket->assigned_to = null` gives `{"assigned_to": null}` with
  `getOriginal('assigned_to')` → `2` (an **`int`**). Setting `null` on an
  already-unassigned ticket gives `{}` — so the early return covers the
  idempotent unassign with no extra code, and `updated_at` stays put.

- **`ticket_activities.meta` is a MySQL `json` column, and an Arabic-plus-emoji
  reason round-trips intact and human-readable.** The raw column held
  `{"reason": "إعادة تعيين إلى فريق آخر 🎫", "to_name": null, "from_name": "Nadia"}`
  — unescaped, because `ActivityRecorder` passes `JSON_UNESCAPED_UNICODE`. A
  **500-character Arabic** reason came back at `mb_strlen` 500 / 1000 bytes,
  byte-identical. **`max:500` is a character limit, not a byte limit**, and the
  `json` column has no practical ceiling at this size — so no migration and no
  `mb_` gymnastics.

- **A null `new_value` stores as SQL `NULL`, not the string `"null"`.** So an
  `unassigned` row's `new_value` is symmetric with the `old_value` of a first
  assignment. **Only `record()` gives you that** — `recordMany()` writes the
  literal string `"null"` into `meta` on an omitted key.

- **`DB::transactionLevel()` is `1` under `RefreshDatabase`**, so
  `ActivityRecorder`'s "must be in a transaction" guard is inert in the suite:
  **an `assign()` that lost its `DB::transaction()` still passes naive tests.**
  The atomicity test is the only guard. Do not weaken it.

---

## Shipped shape — reference, not work

### The activity vocabulary

`TicketActivityEvent::Unassigned` is a **distinct case**, not `Assigned` with a
null `new_value`. It follows the rule Story 27 set for `Claimed`: distinct verbs
get distinct cases, so the timeline renders *"returned the ticket to the queue"*
from `event` rather than from a null check three columns away, and TM-35's
`event = 'assigned'` counts keep meaning assignments. **A first assignment and a
reassignment both stay `Assigned`**, told apart by `old_value` being null or not.

### The controller

```php
public function assign(AssignTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
{
    $actorId = $request->user()->getKey();
    $change = new AssignmentChange($actorId, $request->input('assigned_to') === null ? null : (int) $request->input('assigned_to'), $request->input('reason'));
    $changed = DB::transaction(fn (): bool => $this->changeAssignee($ticket, $recorder, $change));
    if ($changed && $change->targetId !== null) {
        TicketAssigned::dispatch($ticket->getKey(), $change->targetId, $actorId, $change->reason);
    }

    return TicketResource::make($ticket->fresh()->load([...]))->response();
}
```

Load-bearing details, each of which task B must pin with a test:

- **`$request->input(...)` with an explicit null check, never
  `$request->integer(...)`.** `integer()` casts `null` to `0`, and `0` is not a
  valid user — the request would try to assign user 0 and violate the foreign key.
- **The transaction returns a plain `bool`.** The `$targetId` lives outside it,
  so `null` never has to mean both "unchanged" and "unassigned".
- **`getDirty() === []` covers two idempotent cases**: re-assigning the same
  agent and unassigning an already-unassigned ticket. Same code, same `200`, same
  absent activity row.
- **`meta.reason` is omitted, not set to `null`, when no reason was given**, so
  `array_key_exists('reason', $meta)` means "a human wrote something".
- **The name lookups stay inside the transaction and stay conditional.** An
  unassign costs one lookup, a first assignment one, a reassignment two.
- **The dispatch is after `DB::transaction()` returns, guarded on `$changed`.**

### The documentation

`docs/api-contract.md`'s `POST /api/v1/tickets/{ticket}/assign` subsection is
already updated in place — the `present` rule, the `null` unassign, the `""`
footgun, the `unassigned` row shape and the `meta.reason` contract are all there.
**Verify, do not rewrite.** `README.md` is untouched by this story.

---

## Task A — the assign / unassign dialog

**The only user-visible gap.** `TicketActionToolbar.vue`'s `action-assign` button
is rendered with `v-if="ticket.can.assign"` and a hard-coded `disabled`, with no
`@click`. An admin currently cannot reassign or unassign anything except through
curl.

### A1 — emit from the toolbar

**File: `frontend/src/components/TicketActionToolbar.vue`**

Add `assign` to the emit type and wire the button:

```ts
const emit = defineEmits<{ assign: []; delete: []; escalate: []; status: [] }>()
```

Remove the `disabled` attribute from `action-assign` and add `@click="emit('assign')"`.
**Leave `action-edit` disabled** — that is a different story's button, and
enabling it here would ship a dead dialog.

### A2 — the dialog component

**Create file: `frontend/src/components/TicketAssignDialog.vue`**

Model it on `TicketEscalateDialog.vue` — same overlay markup, same error
handling, same emit shape.

| Element | `data-testid` | Notes |
|---|---|---|
| Root overlay | `ticket-assign-dialog` | |
| Agent select | `ticket-assign-select` | `v-model="selected"`, options from `useUsersStore()`; the placeholder option's value is `undefined`, **never `''`** |
| Reason textarea | `ticket-assign-reason` | `v-model.trim="reason"`, `maxlength="500"`, label **"Reason (optional)"** |
| Assign | `ticket-assign-confirm` | `:disabled="selected === undefined || store.assigning"` |
| Return to queue | `ticket-assign-unassign` | **`v-if="ticket.assignee"`**, `:disabled="store.assigning"` |
| Cancel | `ticket-assign-cancel` | emits `close` |
| Error block | `ticket-assign-error` | |

```ts
const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ assigned: []; close: [] }>()
const store = useTicketsStore()
const users = useUsersStore()

const selected = ref<number | undefined>(props.ticket.assignee?.id)
const reason = ref('')
const error = ref('')
const errors = ref<Record<string, string[]>>({})

async function submit(assignedTo: number | null): Promise<void> {
  errors.value = {}
  error.value = ''
  try {
    await store.assign(props.ticket.id, assignedTo, reason.value || undefined)
    emit('assigned')
  } catch (caught) {
    errors.value = validationErrors(caught)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(caught)
  }
}
```

`ticket-assign-confirm` calls `submit(selected)`; `ticket-assign-unassign` calls
`submit(null)`.

- **One `submit()` for both paths.** The endpoint, the error handling and the
  reason field are identical; two functions would drift.
- **`selected` is `number | undefined`, never `number | ''`.** This is what makes
  the measured empty-string footgun unreachable from the UI — there is no code
  path that can pass `''`, and the confirm button is disabled while `selected` is
  `undefined`.
- **"Return to queue" only when the ticket is assigned.** On an unassigned ticket
  it is a guaranteed no-op, and a button that does nothing invites a bug report.
- **The reason applies to whichever button is pressed**, so an admin can annotate
  an assignment as easily as a handover. AC2 says "the activity row", not "the
  unassign row".
- **No reset logic.** The dialog is mounted under `v-if` and unmounts on
  `assigned`, so there is nothing to clear.
- **Load the agent list through `useUsersStore()`**, filtered to active agents,
  the way `TicketFilterBar.vue` already does. Do not call `listUsers` from the
  component and do not add a second debounced search — the picker is a select,
  not a typeahead.

### A3 — mount it

**File: `frontend/src/views/TicketDetailView.vue`**

Add `assignOpen` alongside `escalateOpen` / `statusOpen`, pass
`@assign="assignOpen = true"` to the toolbar, and mount the dialog in the same
block as the other two:

```vue
<TicketAssignDialog
  v-if="assignOpen && store.current"
  :ticket="store.current"
  @assigned="assignOpen = false"
  @close="assignOpen = false"
/>
```

`store.assign()` already refreshes the ticket, so the assignee line updates with
no extra call and no reload.

### A4 — no other frontend change

`api/tickets.ts` and `stores/tickets.ts` are **already correct** — the signatures
were widened when this story first landed. If either appears in the diff,
something is wrong.

---

## Task B — close the backend test gap

**File: `backend/tests/Feature/Tickets/AssignTicketTest.php`** — extend; the
eight existing tests stay green and unmodified.

1. `test_reason_is_stored_and_omitted_when_blank` — **AC2.** With
   `reason: 'Going on leave'` → `meta.reason` equals it. With `reason: ''` and
   with the key absent → **`assertArrayNotHasKey('reason', $row->meta)`**, not
   "equals null".
2. `test_reason_over_500_characters_is_rejected` — `422` on `reason`, **and
   `assigned_to` was not applied**.
3. `test_unassigning_an_unassigned_ticket_is_idempotent` — `200`, **zero**
   activity rows, `updated_at` unchanged (capture before, compare after).
4. `test_empty_string_assigned_to_unassigns` — the measured
   `ConvertEmptyStringsToNull` behaviour. `{"assigned_to": ""}` on an assigned
   ticket → `200` and unassigned. Docblock it as **deliberate and unfixable
   server-side**, with a pointer to the typed client that prevents it.
5. `test_zero_assigned_to_is_rejected` — `422`, not a foreign-key error.
6. `test_unassign_dispatches_no_event` — **AC3.** `Event::fake()`; unassign →
   `Event::assertNotDispatched(TicketAssigned::class)`.
7. `test_assignment_dispatches_exactly_one_event_and_a_repeat_dispatches_none` —
   **AC3, the "not twice" mechanism.** `Event::fake()`; assign →
   `assertDispatchedTimes(TicketAssigned::class, 1)` with the correct
   `assigneeId`, `actorId` and `reason`; assign the **same** agent again → still
   exactly **1** in total.
8. `test_event_is_dispatched_after_commit` — force a failure after `save()` (a
   throwing `ActivityRecorder` bound into the container); assert the exception
   propagates, `assigned_to` is unchanged, **and**
   `Event::assertNotDispatched(TicketAssigned::class)`. **This is the test that
   fails if the dispatch is moved inside the transaction** — say so in the
   docblock.
9. `test_arabic_reason_round_trips` — a 500-character Arabic reason; assert
   `mb_strlen` is 500 after reading it back and that the value is byte-identical.
   The utf8mb4 guard CLAUDE.md asks for.
10. `test_reassigning_the_same_agent_drops_the_reason` — `200`, no new row, and
    no `meta.reason` anywhere. Pins the documented consequence of the idempotency
    rule.
11. `test_reassignment_records_both_ids_and_the_reason` — **AC4's reassign half.**
    A → B with a reason: one new `assigned` row carrying `old_value`, `new_value`,
    `from_name`, `to_name` **and** `reason`, plus exactly one event naming **B
    only**.

**No new route, so `RouteAuthorizationTest` is untouched. No policy, resource,
model, migration or seeder change.** If any of those appears in the diff,
something is wrong.

### Frontend — `frontend/src/components/TicketAssignDialog.spec.ts` (new)

12. `ticket-assign-reason` renders, is `maxlength=500`, and its value reaches
    `store.assign` as the third argument.
13. A blank reason omits the key: `store.assign` is called with `undefined`.
14. `ticket-assign-unassign` renders when `ticket.assignee` is set and is
    **absent** when it is `null`.
15. Clicking it calls `store.assign(id, null, …)` — **asserting the second
    argument is exactly `null`, not `''` or `undefined`**. The client-side half
    of the empty-string guard.
16. A `422` on `reason` renders in the error block and the dialog stays mounted.

### Frontend — `frontend/src/components/TicketActionToolbar.spec.ts` (extend)

17. `action-assign` is enabled when `can.assign` is true and emits `assign` on
    click; still absent entirely when `can.assign` is false.

---

## Edge cases and failure modes

- **`{"assigned_to": null}` on an assigned ticket** → `200`, `assigned_to` is
  `NULL`, one `unassigned` row, **no event dispatched**. AC1.
- **`{"assigned_to": null}` on an already-unassigned ticket** → `200`, **no**
  activity row, `updated_at` untouched, no event.
- **`{}` — the field absent** → `422` "Send assigned_to explicitly…".
- **`{"assigned_to": ""}`** → **unassigns**, measured and unfixable server-side.
  Mitigated by the typed client and documented in the API contract.
- **`{"assigned_to": 0}`** → `422`. `0` is an integer and fails `exists`.
- **A 501-character reason** → `422` on `reason`, and **`assigned_to` is not
  applied** — validation runs before the handler.
- **`{"reason": ""}`** → arrives as `null`, so `meta.reason` is **absent**.
- **A 500-character Arabic reason** → stored intact, readable unescaped in the
  raw `json` column. `max:500` counts characters.
- **A reason containing HTML or a script tag** → stored verbatim. Escaping is the
  renderer's job, and `TicketTimelineEntry.vue` interpolates with `{{ }}`;
  `vue/no-v-html` is an enforced lint error. **Do not sanitise on the way in.**
- **Reassigning to the same agent with a new reason** → `200`, **no activity
  row**, and **the reason is silently dropped**. That is the honest consequence
  of the idempotency rule: nothing changed, so there is nothing to annotate.
  Tested and documented, not discovered later as a bug.
- **Two admins unassign at once** → `lockForUpdate()` + `refresh()` serialises the
  read, so the second sees `null` and returns the no-op `200` with no second row.
- **A double-clicked Assign button** → the second request is a no-op `getDirty()`
  return, so `$changed` is false and **no second event is dispatched**.
- **The assignee is deactivated between opening the dialog and pressing Assign**
  → `422` "That user is not an active agent." The dialog stays mounted and
  renders it.
- **The assignee is deactivated between the commit and the listener running** →
  the listener's `active()` lookup returns null, it logs and returns, and no mail
  is queued. The assignment itself stands.
- **The ticket is soft-deleted between the commit and the listener running** →
  same shape: logged, skipped, no mail.
- **A mail-transport failure** → cannot roll back the assignment, because the
  dispatch is outside the transaction and the notification is queued. TM-57's
  third criterion.

---

## Verification

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**,
   `tm-mailpit` healthy.
2. **Gate:** from `backend/`, `php artisan test --filter=AssignTicketTest` green
   **before** you change anything.
3. **Backend:** `./vendor/bin/pint --test`, then `composer test`, then
   `php artisan test --filter=AssignTicketTest`. Expect **+11** tests.
4. **Prove the after-commit test earns its place:** move
   `TicketAssigned::dispatch(...)` **inside** the `DB::transaction` closure,
   re-run `--filter=test_event_is_dispatched_after_commit`, confirm it **fails**,
   restore.
5. **Prove the `input()`-not-`integer()` choice earns its place:** put
   `$request->integer('assigned_to')` back, re-run
   `--filter=test_unassigning_writes_one_unassigned_row`, confirm it fails trying
   to assign user **0**. Restore.
6. **Prove the `present` rule earns its place:** change `present` to `sometimes`,
   re-run, confirm `{}` now returns `200` and silently unassigns. Restore.
7. **Mail, with a worker running.** TM-52 has landed, so an assignment **does**
   now send. Clear http://localhost:8025, run `php artisan queue:work`, then:
   - assign to agent B → **exactly one** message, to B, quoting the reason;
   - unassign → **no new message**;
   - re-assign to B again → **no new message**;
   - claim a ticket as an agent → **no message** (the listener's self-assign
     guard).
8. **Backend by hand.** `php artisan serve`, admin token, a ticket held by agent A:
   - `-d '{"assigned_to":null}'` → `200`, `data.assignee` is `null`;
     `SELECT assigned_to FROM tickets WHERE id=<id>` → `NULL`.
   - `SELECT event, old_value, new_value, meta FROM ticket_activities WHERE ticket_id=<id> ORDER BY id DESC LIMIT 1`
     → `unassigned`, `old_value` A's id, **`new_value` NULL**, `meta.from_name`
     A's name, `meta.to_name` null.
   - `-d '{"assigned_to":null}'` again → `200`, activity count **unchanged**.
   - `-d '{}'` → `422` "Send assigned_to explicitly…".
   - `-d '{"assigned_to":<B>,"reason":"Ahmed is on leave until Sunday"}'` → `200`,
     and the new row's `meta.reason` is that sentence.
   - the same request **again** → `200`, activity count unchanged, reason
     **dropped** (the documented no-op).
   - `-d '{"assigned_to":null,"reason":"إعادة إلى قائمة الانتظار 🎫"}'` → `200`,
     then read the **raw** column: the Arabic and the emoji must be legible, not
     `\uXXXX`.
   - a 501-character reason → `422` on `reason`, `assigned_to` unchanged.
   - `-d '{"assigned_to":""}'` → `200` and **unassigned** (the documented footgun).
   - `-d '{"assigned_to":0}'` → `422`.
   - with an **agent** token → `403`.
9. **Frontend:** from `frontend/`, `npm run lint`, `npx vue-tsc -b`,
   `npm run test:unit` — **+6** tests across one new and one extended spec file.
   Then `npx prettier --check` on every touched file.
10. **Frontend by hand:** `npm run dev`, as an **admin**, on a ticket detail page:
    - the **Assign** button is now enabled and opens the dialog;
    - on an **unassigned** ticket the dialog shows the reason field and **no
      "Return to queue"** button;
    - assign with a reason → the assignee line updates with no reload, and the
      reason appears in the timeline entry;
    - re-open Assign → **"Return to queue" is present**. Click it with a reason →
      the assignee line reads **Unassigned** and a new timeline entry appears;
    - in the network tab, the unassign body is `{"assigned_to":null,"reason":"…"}`
      — **confirm it is `null` and not `""`**;
    - type 500 characters in the reason and submit → accepted; the textarea will
      not take a 501st character;
    - as an **agent**: no Assign button at all.
11. **Regression:** a plain assignment is unchanged (`200`, one `assigned` row,
    `can.assign` correct per role); `/claim` still returns `409` on a contested
    claim and **dispatches no event**; the timeline still renders `timeline-reason`
    for escalation and category rows.

---

## Done criteria

- [ ] The **Assign** button on the ticket detail page is enabled for admins and
      opens a working dialog; `action-edit` stays disabled.
- [ ] The dialog offers an agent picker, an optional reason (`maxlength=500`) and
      a **Return to queue** button shown only when the ticket is assigned.
- [ ] The unassign path sends exactly `null` — asserted in a test, not just by
      inspection — and no code path in the SPA can emit `""`.
- [ ] A `422` from the endpoint renders in the dialog and leaves it mounted.
- [ ] `api/tickets.ts` and `stores/tickets.ts` are **not** in the diff.
- [ ] `AssignTicketTest` covers the reason (stored, omitted when blank, capped at
      500, Arabic round-trip), the two idempotent no-ops, `""`, `0`, and the
      event (one per real assignment, none on unassign, none on a repeat, none
      before commit).
- [ ] The after-commit test is **proven** to fail when the dispatch is moved
      inside the transaction.
- [ ] With a worker running, one assignment produces **one** email to the new
      assignee; unassign, a repeat assign and a self-claim produce **none**.
- [ ] `docs/api-contract.md`'s assign subsection is verified accurate — the
      `present` rule, the `null` unassign, the `""` footgun, the `unassigned` row
      shape and the `meta.reason` contract — and updated **in place** if it is not.
- [ ] `README.md` untouched; no route, policy, resource, model, migration or
      seeder change; `RouteAuthorizationTest` untouched.
- [ ] `pint --test`, `lint`, `vue-tsc` and Prettier clean; **+11 backend** and
      **+6 frontend** tests over the current baseline.

**STOP HERE. Report to the user and wait for confirmation before proceeding to
Story 30 (TM-35, agent workload overview).**
