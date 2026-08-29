# Story 32 — Change a ticket's status (Story: TM-38)

## Prerequisites

- **Story 31 (TM-37) — PLANNED, NOT IMPLEMENTED. This story is hard-blocked on it.** Verified on disk while planning: `backend/app/Services/TicketWorkflow.php`, `backend/app/Models/StatusTransition.php`, `backend/database/seeders/StatusTransitionSeeder.php` and the `status_transitions` migration **do not exist**. Every guard in this plan calls into files Story 31 creates. Read [`31-story-transition-table-and-workflow-guard-TM-37.md`](31-story-transition-table-and-workflow-guard-TM-37.md) before this one — it is the spec for the service you are about to call, and it fixes the three message strings this story's tests assert. **Gate: do not start until `php artisan test --filter='TicketWorkflowTest|StatusTransitionSeederTest'` passes.**
- **Story 22 (TM-26) — backend landed, frontend half landed, tests never written.** `TicketPolicy::changeStatus()` returns `true` for all staff (`backend/app/Policies/TicketPolicy.php:40–43`), `TicketResource` emits `can.change_status` (`backend/app/Http/Resources/V1/TicketResource.php:28`), `tickets.show` is routed (`backend/routes/api.php:45`), and `TicketDetailView.vue` / `TicketActionToolbar.vue` exist. **`frontend/src/views/TicketDetailView.spec.ts` and `frontend/src/components/TicketActionToolbar.spec.ts` do not exist** — `ls frontend/src/components/*.spec.ts` matches nothing. **This story creates both**, rather than pretending TM-26 will.
- **Story 26 (TM-31) — PLANNED, NOT IMPLEMENTED, and this story copies its shape wholesale.** `POST /tickets/{ticket}/assign` is absent from `routes/api.php`, and `TicketAssignDialog.vue` does not exist. [`../assignment-workload/26-story-assign-a-ticket-to-an-agent-TM-31.md`](../assignment-workload/26-story-assign-a-ticket-to-an-agent-TM-31.md) is the precedent for the endpoint, the `lockForUpdate()` ordering, the "response omits `can`, store re-reads" decision and the dialog. **If Story 26 lands first, place `changeStatus()` after `assign()` and expect a merge neighbour in five shared files.** Its plan already names this story twice — the `action-status` button and TM-38's obligation to the `mine_open` badge.
- **Measured baseline, 2026-08-26, from `backend/`.** `composer test` → **101 tests, 289 assertions, 98 passing, 3 failing**; `./vendor/bin/pint --test` exits `0`. From `frontend/`: six spec files exist (`api/auth`, `api/client`, `router/guards`, `stores/auth`, `stores/health`, `views/HealthView`). **This story fixes one of the three backend failures and owns neither of the other two:**
  1. `PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` — `429` vs `422`. **TM-14 owns it.**
  2. `RouteAuthorizationTest::test_every_api_route_is_classified` — `ACCESS` has no `tickets.store` key. **This story adopts it** — see task 5's second bullet. It is the array this story edits, and leaving a known-red neighbour line is worse than fixing one word.
  3. `TicketReferenceTest::test_calling_outside_a_transaction_throws` — `RefreshDatabase` holds an open transaction so the guard cannot fire. **TM-21/TM-22 own it.**
  **Re-measure before you start.** Expect **2** failures at the end, not 3.
- **Measured, and task 3 depends on it.** Eager-loading `show()`'s seven relations costs **5 queries on an unassigned ticket and 6 on an assigned one** — not seven. `assignee`, `creator` and `escalatedBy` all target `users`, and Eloquent issues no query for an eager load whose key list is empty, so a null `assigned_to` and a null `escalated_by` each cost zero. Measured today against `tm-mysql` inside a rolled-back transaction.
- **Docker up.** `docker compose ps` → `tm-mysql`, `tm-mysql-test`, `tm-mailpit` healthy. **No new composer or npm dependency. No migration.** `tickets.status_id` is already fillable (`backend/app/Models/Ticket.php:12`) and `ticket_activities.event` is `varchar(50)` (`…_create_ticket_activities_table.php:15`), so a new enum case needs no schema change.

---

## Story Goal

The status control on the ticket detail page stops being a list of every status and becomes a list of the moves that are actually legal for the person looking at it — and the server refuses the rest in its own words.

1. `POST /api/v1/tickets/{ticket}/status` moves a ticket through `TicketWorkflow::assertCanTransition()` and returns the updated ticket.
2. `GET /api/v1/tickets/{ticket}` gains `data.allowed_transitions`, computed per caller from `TicketWorkflow::allowedTransitions()`.
3. Each accepted change writes exactly one `status_changed` activity row carrying both status **ids** and both status **names**.
4. The SPA's **Change status** button opens a dialog offering only `allowed_transitions`; confirming updates the badge with no reload and no navigation.
5. A rejected transition renders the server's own message — `A ticket cannot move from New to Resolved.`, not "Something went wrong".

**Not in scope, and each belongs to a named story.** **No timestamp is stamped or cleared.** `resolved_at`, `closed_at` and `first_responded_at` stay untouched — TM-39's second, fourth and fifth criteria. Test 15 exists to keep them that way. **No resolution note** (TM-39) and **no reopen reason** (TM-40): both fields are explicitly `prohibited` on the request so a premature client gets a `422` naming the story, not a silent drop. **No escalation** (TM-41) — the Escalate button stays disabled. **No status control on any list view** — the detail page is the only entry point, as Story 26 does for assign and Story 24 for delete. **No bulk status change, no keyboard shortcut, no optimistic update.** **No change to `TicketWorkflow`, `StatusTransitionSeeder` or the `status_transitions` schema** — Story 31 owns all three and this story only calls them. **No notification** — E8 owns every line of mail.

---

## Context — Read These Files First

1. [`31-story-transition-table-and-workflow-guard-TM-37.md`](31-story-transition-table-and-workflow-guard-TM-37.md) — **task 5 and the "Decision — both refusals are 422, never 403" section.** The three message strings, the `errors.status_id` key, and the sentence *"TM-38 must call `assertCanTransition()` inside its own transaction, after `lockForUpdate()`"* are this story's contract. **The strings in this plan's tests must match that plan's service exactly.**
2. `backend/app/Http/Controllers/Api/V1/TicketController.php` — **`show()` at 23–28, `store()` at 30–50, `defaultKey()` at 52–61.** `Ticket`, `Status`, `TicketResource`, `ActivityRecorder`, `TicketActivityEvent`, `DB` and `JsonResponse` are **already imported** (lines 5–18). Task 2 adds only the new form request and `TicketWorkflow`. Note `store()`'s `DB::transaction` + `$recorder->record(...)` at **34–47** — that is the shape task 2 copies.
3. `backend/app/Http/Controllers/Api/V1/CategoryController.php:58–97` — **`destroy()` at 58–76 is the lock precedent**: `$this->authorize(...)` *outside* the transaction, then `DB::transaction`, then `…->whereKey(…)->lockForUpdate()->first()` at **64**. **`reassignTickets()` at 86–97 is the activity-row precedent** — `field`, **stringified** ids in `old_value`/`new_value`, human names under `meta`. Task 2 mirrors it field for field.
4. `backend/app/Services/ActivityRecorder.php` — `record()` (**13–19**) fills the five defaults; `recordMany()` (**21–39**) requires all five. **Call `record()`.** The `LogicException` at **26–28** fires outside a transaction, which is a second reason task 2's write is wrapped.
5. `backend/app/Enums/TicketActivityEvent.php` — `Created` and `CategoryChanged` today (**7–8**). **Append only**; those strings are already in rows. Stories 23, 24, 26 and 29 each append one too — expect merge neighbours.
6. `backend/app/Http/Resources/V1/TicketResource.php` — **line 28.** `escalation_reason` (**27**) and `can` (**28**) are both gated on `$request->routeIs('tickets.show')`. Task 3 adds a third key behind the **same** gate, which is what makes task 2's response automatically omit it. Read how `can` calls `$request->user()->can(...)` inline — task 3's container call is in keeping with that, not a new pattern.
7. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php` — `rules()` at **25–41** and `messages()` at **43–47**. `Rule::exists('statuses', 'id')` is at **37**, and `'assigned_to' => ['prohibited']` with its message at **46** is the precedent task 1 copies twice.
8. `backend/routes/api.php:44–45` — `tickets.store` and `tickets.show` inside the `['auth:sanctum', 'active']` group. Task 4 adds one line **after 45**, still inside that group and **not** inside the `admin` prefix at **46**.
9. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **`ACCESS` at 17**, `test_every_api_route_is_classified` (**19–26**), `test_agent_reaches_staff_routes` (**53–59**), `routesFor()` (**122–125**, it already takes `$ticketId`). Task 5 adds **two** keys.
10. `frontend/src/api/tickets.ts` — **12 lines.** `TicketDetail` at **9** is what task 6 extends; `Status` is **already imported** at line 4. `getTicket()` at **12** is the call the store re-reads with.
11. `frontend/src/stores/tickets.ts` — **12 lines.** `current` / `detailLoading` / `loadTicket()` at **8–10**. Task 7 adds one ref and one action beside them.
12. `frontend/src/components/TicketActionToolbar.vue` — **2 lines.** `action-status` is `v-if="ticket.can.change_status"`, `disabled`, `title="Status changes arrive with TM-38"`. Story 22's plan states the contract: *"Each future story's job is: remove `disabled`, remove `title`, add the handler."* **This is that story, for that one button.**
13. `frontend/src/components/CategoryDeleteDialog.vue` — **27 lines, the dialog precedent.** Entity via props (**6**), `emit('close')` (**7**), the store call in try/catch into a local `error` ref (**11–14**), a `<select v-model>` of options (**21–23**), `*-confirm` / `*-cancel` test ids (**24–25**). Task 8 follows this shape almost exactly.
14. `frontend/src/components/UserFormDialog.vue:33–36` — the two-step error read: `validationErrors(error)` first, `errorMessage(error)` only when the map is empty. **AC5 lives in those four lines.**
15. `frontend/src/api/errors.ts` — `validationErrors()` at **6–11** returns `{}` for anything that is not a `422`; `errorMessage()` at **12–22** maps `403` and `429` to fixed strings and otherwise returns the server's `message`. **Read line 19** — the server message already wins over the generic fallback.
16. `frontend/src/views/TicketDetailView.vue` — **script at 1–10, one long template line at 11.** `<TicketActionToolbar :ticket="store.current" />` and the status `<ColorBadge>` are both on line 11. Task 9 adds a ref, one binding and one component.
17. `frontend/src/views/HealthView.spec.ts:1–23` — the spec skeleton every frontend test in this story copies: `vi.mock('../api/…')`, `mount(Component, { global: { plugins: [createPinia()] } })`, `flushPromises()`.
18. `docs/api-contract.md` — the endpoints table ends at **47**; `## Authorization` at **18–28** still says *"Category and ticket policies arrive with their models in TM-17 and TM-22"*; Story 31 inserts a `## Status workflow` section between **28** and **30** whose last paragraph says the endpoint *"arrives with TM-38"*. Task 10 rewrites that paragraph.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Agent opens a ticket | Change status button is visible and **disabled** | Enabled, opens a dialog |
| The dialog's options | — | **Only** `allowed_transitions` for this caller, `sort_order` order |
| A legal move | — | `200`, badge updates in place, one `status_changed` row |
| A move not in the graph | Impossible — no endpoint | `422`, `A ticket cannot move from New to Resolved.` |
| An admin-only edge, agent caller | — | `422`, `Only an administrator can move a ticket from Resolved to Closed.` |
| The status it already has | — | `422`, `This ticket is already Open.` — **not** a `200` no-op |
| An unknown `status_id` | — | `422` from `Rule::exists`, before the workflow is consulted |
| A `resolution` or `reason` field | — | `422` naming **TM-39** / **TM-40**, never a silent drop |
| `resolved_at` / `closed_at` / `first_responded_at` | Always null | **Still always null.** TM-39 stamps them |
| Ticket creation | `status_id` accepted, defaults to New | **Unchanged.** Creation is an entry point, not a transition |
| Non-existent or trashed ticket | — | `404` for **both** roles — binding precedes the policy |

---

## Decision — the response omits `can` and `allowed_transitions`, and the store re-reads

`changeStatus()` returns `show()`'s shape **minus** the two `routeIs('tickets.show')` keys, and `store.changeStatus()` then calls `loadTicket(id)`. One extra round-trip, deliberately:

- **Both keys are stale the instant the status changes.** `allowed_transitions` changes wholesale, and `can.escalate` flips because `TicketPolicy::escalate()` reads `$ticket->status->is_terminal` (`TicketPolicy.php:45–48`). Splicing the status response into `current` would leave the toolbar rendering from the **old** ticket's affordances — an Escalate button on a ticket that was just closed.
- **It costs nothing to implement.** Task 3's key sits behind the existing `routeIs('tickets.show')` gate, so the status route omits both with **zero** new conditionals.
- **It is Story 26's decision, for the same reason**, and the two endpoints should not diverge. Its store comment is quoted in task 7.

**Do not "optimise" this into a single round-trip** by returning the full detail shape. The next story that needs it (TM-40's reopen) will inherit the same trap.

## Decision — `tickets.status` is `staff`, and this story adopts `tickets.store`

- `TicketPolicy::changeStatus()` returns `true` for every staff member, so the route is `staff`, **not** `admin-policy` and **not** behind the `admin` middleware. The role gate that exists — the admin-only `resolved → closed` edge — lives in the **graph**, not in the route, which is the entire point of Story 31.
- `ACCESS` is also missing `tickets.store`, which is why `test_every_api_route_is_classified` is red at baseline. **Add both keys as `staff`.** `TicketPolicy::create()` returns `true` for everyone, so `staff` is the correct classification, and this story is the one holding the pen on that array. **Record in the PR description that baseline failure 2 was fixed here and not by TM-22.**

## Decision — the dialog is the only place that explains an empty graph

When `allowed_transitions` is empty the button stays **enabled** and the dialog renders `ticket-status-empty` — "No status changes are available from here." A disabled button was rejected because Story 22's contract for this button is *remove* `disabled`, and because every seeded status has at least one outgoing edge (Story 31's `test_no_status_is_a_dead_end`): an empty list means a hand-edited graph, and a silently dead button would hide that. **The `v-if="ticket.can.change_status"` stays** — policy owns hidden, and that half of Story 22's rule does not change.

---

## Backend Tasks

### 1 — The form request

**Create file: `backend/app/Http/Requests/Api/V1/ChangeTicketStatusRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ChangeTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('changeStatus', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            'status_id' => ['required', 'integer', Rule::exists('statuses', 'id')],
            // Whether the move is legal is TicketWorkflow's answer, not a rule
            // here: the graph lives in status_transitions and a validator would
            // have to duplicate it. This only proves the status exists.
            'resolution' => ['prohibited'],
            'reason' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'status_id.exists' => 'That status does not exist.',
            'resolution.prohibited' => 'A resolution note arrives with TM-39.',
            'reason.prohibited' => 'A reopen reason arrives with TM-40.',
        ];
    }
}
```

`authorize()` via `Gate::allows` on the bound model copies `DestroyCategoryRequest.php:11–14`. **Do not** add a `status_id` rule that checks the transition — the `422` for an illegal move must come from `TicketWorkflow` so its three messages stay in one place.

### 2 — The controller method

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add to the imports: `App\Http\Requests\Api\V1\ChangeTicketStatusRequest` and `App\Services\TicketWorkflow`. Everything else task 2 needs is already imported (**5–19**).

Add after `store()` (ends at **50**):

```php
    public function changeStatus(ChangeTicketStatusRequest $request, Ticket $ticket, TicketWorkflow $workflow, ActivityRecorder $recorder): JsonResponse
    {
        $this->authorize('changeStatus', $ticket);
        $actor = $request->user();
        $target = Status::query()->findOrFail($request->integer('status_id'));

        $ticket = DB::transaction(function () use ($ticket, $target, $actor, $workflow, $recorder): Ticket {
            // Lock first, then re-read: two agents submitting from stale dropdowns
            // must serialise here, or both pass the guard against the same old
            // status and the second writes a move that was never legal.
            Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
            $ticket->refresh()->load('status');
            $from = $ticket->status;

            // Throws ValidationException -> 422 under `status_id`. TM-37 owns the
            // three messages; nothing here catches or rewrites them.
            $workflow->assertCanTransition($ticket, $target, $actor);

            $ticket->status_id = $target->getKey();
            $ticket->save();

            $recorder->record($ticket->getKey(), TicketActivityEvent::StatusChanged, [
                'user_id' => $actor->getKey(),
                'field' => 'status_id',
                'old_value' => (string) $from->getKey(),
                'new_value' => (string) $target->getKey(),
                'meta' => ['from_name' => $from->name, 'to_name' => $target->name],
            ]);

            return $ticket;
        });

        return TicketResource::make($ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }
```

Five things not to re-derive:

- **`$from` is read after `refresh()` and before `save()`.** Read it after the save and the activity row records `X → X`. Test 4 is what catches that, the same way Story 26's test 8 does for assignment.
- **`assertCanTransition()` is inside the transaction, after the lock.** Story 31's plan requires it in writing. Moving it above `DB::transaction` compiles, passes tests 5–8, and reintroduces the race.
- **No timestamp is written.** Not `resolved_at`, not `closed_at`, not `first_responded_at`. Test 15 fails the moment one is added; TM-39 is where they belong.
- **`findOrFail` on the target cannot 404 in practice** — the form request already proved the id with `Rule::exists`. It is there so a future caller that bypasses the request still fails loudly rather than passing `null` into the workflow.
- **`->load([...])` repeats `show()`'s seven relations**, so the payload matches. The two `routeIs('tickets.show')` keys drop out on their own; **do not add a flag to suppress them**.

### 3 — `allowed_transitions` on the detail response

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Add `use App\Services\TicketWorkflow;` and one key immediately after `can` (**line 28**):

```php
            // Behind the same tickets.show gate as `can`: it is a per-caller
            // affordance, not a ticket attribute, and it costs two extra queries
            // that no list or write response should pay. Resolved from the
            // container because the resource is built by JsonResource::make(),
            // which takes no constructor arguments -- the same reason `can`
            // reaches for $request->user()->can() inline on the line above.
            'allowed_transitions' => $this->when($request->routeIs('tickets.show'), fn () => StatusResource::collection(app(TicketWorkflow::class)->allowedTransitions($this->resource, $request->user()))->resolve()),
```

`StatusResource` is in the same namespace (`App\Http\Resources\V1`) and needs no import. `->resolve()` returns a plain array, matching how `reassignmentRequired()` embeds a collection (`CategoryController.php:83`).

**`TicketController::show()` is not modified** — the resource has the `Request`, and therefore the user, already.

### 4 — The route

**File: `backend/routes/api.php`**

Add after **line 45**, inside the `['auth:sanctum', 'active']` group and **outside** the `admin` prefix that starts at **46**:

```php
    Route::post('/tickets/{ticket}/status', [TicketController::class, 'changeStatus'])->name('tickets.status');
```

### 5 — The new activity event and the route classification

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append one case after `CategoryChanged` (**line 8**):

```php
    case StatusChanged = 'status_changed';
```

**Append only.** `created` and `category_changed` are already in rows.

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add **two** keys to `ACCESS` (**line 17**), both `'staff'`:

- `'tickets.status' => 'staff'` — this story's route.
- `'tickets.store' => 'staff'` — **TM-22's orphan, adopted here.** This is what turns baseline failure 2 green. `TicketPolicy::create()` returns `true` for everyone, so `staff` is correct. **Say so in the PR description.**

`test_every_api_route_is_classified`, `test_every_classified_route_exists` and `test_policy_admin_routes_have_no_admin_middleware` then cover both automatically. **Do not add either route to `test_agent_reaches_staff_routes` (53–59)** — that test issues bare `GET`s and both new entries are `POST`s with required bodies.

### 6 — Documentation

**File: `docs/api-contract.md`**

Add one row to the endpoints table after **line 47**:

```markdown
| `POST` | `/api/v1/tickets/{ticket}/status` | Move a ticket to a legal next status. | bearer (TicketPolicy) | TM-38 |
```

Rewrite the closing paragraph of the `## Status workflow` section Story 31 added — the one beginning *"The endpoint that consumes this arrives with TM-38"* — as:

```markdown
`POST /api/v1/tickets/{ticket}/status` is the only way a status changes.
`GET /api/v1/tickets/{ticket}` carries `data.allowed_transitions`: the statuses
this caller may move this ticket to right now, in `sort_order`. It is computed
per request and is absent from every other response, including the status
change's own.
```

Add a subsection after the existing endpoint subsections (the file's last one ends at **98**):

```markdown
### `POST /api/v1/tickets/{ticket}/status`

Requires a bearer token; `TicketPolicy::changeStatus` admits every staff member,
so the role rules live in `status_transitions`, not in the route. Body is
`{"status_id": <int>}`.

Returns `200` with the ticket in `GET /api/v1/tickets/{ticket}`'s shape **minus**
`can` and `allowed_transitions`, both of which are recomputed per caller and are
stale the moment the status changes — clients re-read the detail rather than
splicing this response into it.

An unknown `status_id` returns `422` before the workflow is consulted. An illegal
move, an admin-only move made by an agent, and a move to the current status each
return `422` under `errors.status_id` with the message the Status workflow
section lists. A `resolution` or `reason` field returns `422` naming TM-39 or
TM-40. A non-existent or soft-deleted ticket returns `404` for both roles.

Each accepted change writes one `ticket_activities` row: `event = 'status_changed'`,
`field = 'status_id'`, `old_value` and `new_value` the two status ids, and
`meta.from_name` / `meta.to_name` the two status names. `user_id` is the caller.
No timestamp is stamped — `resolved_at`, `closed_at` and `first_responded_at`
arrive with TM-39.
```

Also correct **lines 27–28** if they still read *"Category and ticket policies arrive with their models in TM-17 and TM-22"*:

```markdown
deletion is denied for everyone. `TicketPolicy` defines `viewAny`, `view`,
`create`, `update`, `delete`, `assign`, `changeStatus` and `escalate`.
```

**If Story 26 has already made that correction, leave it alone** — it plans the identical edit.

---

## Frontend Tasks

### 7 — The API call and the type

**File: `frontend/src/api/tickets.ts`**

Extend `TicketDetail` (**line 9**) with one field — `Status` is already imported at **line 4**:

```ts
export interface TicketDetail extends Ticket { escalated_by: TicketStaff | null; escalation_reason: string | null; can: TicketPermissions; allowed_transitions: Status[] }
```

Add after `getTicket()` (**line 12**):

```ts
export async function changeTicketStatus(id: number, statusId: number): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/status`, { status_id: statusId }); return data.data }
```

**The return type is `Ticket`, not `TicketDetail`** — the response carries neither `can` nor `allowed_transitions`, by design. Typing it as `TicketDetail` is a lie the compiler cannot catch, and the same trap Story 26 documents.

### 8 — The store action

**File: `frontend/src/stores/tickets.ts`**

Import `changeTicketStatus`, add one ref beside `detailLoading` (**line 8**) and one action:

```ts
const changingStatus = ref(false)
async function changeStatus(id: number, statusId: number): Promise<void> {
  changingStatus.value = true
  try {
    await changeTicketStatus(id, statusId)
    // Re-read rather than patching `current`: the status response omits `can`
    // and `allowed_transitions`, and both are stale the moment the status
    // moves -- can.escalate flips on a terminal status and the legal moves
    // change wholesale.
    await loadTicket(id)
    // TM-29's dashboard stats store does not exist yet. When it lands, the
    // mine_open badge must be refreshed here -- E5's overview records this as
    // TM-38's obligation. A poller is not the substitute.
  } finally {
    changingStatus.value = false
  }
}
```

Return `changingStatus` and `changeStatus` from the store. **Do not catch here** — the dialog renders the error, matching `CategoryDeleteDialog.vue:13` and `UserFormDialog.vue:33–36`.

### 9 — The dialog

**Create file: `frontend/src/components/TicketStatusDialog.vue`**

```ts
const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ changed: []; close: [] }>()
```

State: `selected` (`number | undefined`, **not** initialised to the current status — it is never a legal target), `error` (`''`), `errors` (`Record<string, string[]>`). `const store = useTicketsStore()`.

```ts
async function confirm(): Promise<void> {
  if (selected.value === undefined) return
  errors.value = {}
  error.value = ''
  try {
    await store.changeStatus(props.ticket.id, selected.value)
    emit('changed')
  } catch (reason) {
    // AC5: the server's own message, not a generic one. validationErrors first
    // -- errorMessage() would return the envelope's top-level `message`, which
    // for a 422 is Laravel's summary, not TicketWorkflow's sentence.
    errors.value = validationErrors(reason)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(reason)
  }
}
```

| Element | `data-testid` | Notes |
|---|---|---|
| Root | `ticket-status-dialog` | |
| Select | `ticket-status-select` | `v-model.number="selected"`, `v-if="ticket.allowed_transitions.length"`, `v-for` over `ticket.allowed_transitions`, label is `status.name` |
| Empty | `ticket-status-empty` | `v-if="!ticket.allowed_transitions.length"` — **"No status changes are available from here."** |
| Error | `ticket-status-error` | `v-if="error || errors.status_id"`, renders `errors.status_id?.[0] ?? error` |
| Confirm | `ticket-status-confirm` | `:disabled="selected === undefined || store.changingStatus"` |
| Cancel | `ticket-status-cancel` | `@click="emit('close')"` |

- **The options come from `props.ticket.allowed_transitions`, never from `useMasterDataStore().statuses`.** That store holds all seven statuses (`stores/masterData.ts:14`) and offering them is exactly the accident AC2 exists to prevent. **Do not import `useMasterDataStore` into this component.**
- **On error the dialog stays open** with the selection intact, so the reader can pick a different move.
- **No client-side pre-check of the graph.** The server is the authority; a second copy in TypeScript would drift the first time an edge is edited at runtime.

### 10 — Wire the toolbar button

**File: `frontend/src/components/TicketActionToolbar.vue`**

For the `action-status` button **only**: remove `disabled`, remove `title="Status changes arrive with TM-38"`, add `@click="emit('status')"`, and declare `const emit = defineEmits<{ status: [] }>()`. **Leave the other three buttons exactly as they are** — they belong to TM-27, TM-31 and TM-41. Keep `v-if="ticket.can.change_status"`.

### 11 — Mount the dialog on the detail page

**File: `frontend/src/views/TicketDetailView.vue`**

Add `const statusOpen = ref(false)` to the script (**9**), import `ref` and `TicketStatusDialog`, bind `@status="statusOpen = true"` on `<TicketActionToolbar>`, and add inside the `ticket-detail` article on line **11**:

```html
<TicketStatusDialog v-if="statusOpen && store.current" :ticket="store.current" @changed="statusOpen = false" @close="statusOpen = false" />
```

**No router navigation and no manual refetch** — `store.changeStatus()` already re-read the detail, so the status `<ColorBadge>` on line **11** updates on its own. **That is AC4**; adding a `router.go(0)` or a `window.location.reload()` fails it.

---

## Edge Cases & Failure Modes

- **An illegal move** → `422`, `errors.status_id[0]` is `A ticket cannot move from New to Resolved.`; **the ticket's status is unchanged and no activity row is written**, because the guard throws inside `DB::transaction` before the `save()`. Test 5 asserts all three.
- **An admin-only edge from an agent** → `422`, `Only an administrator can move a ticket from Resolved to Closed.` The dropdown never offered it (task 3 filters by role), so this fires on a hand-rolled request or a stale page. **Both halves are tested separately** — a filtered dropdown is not enforcement. Tests 6 and 16.
- **Moving to the current status** → `422`, `This ticket is already Open.` Deliberately not a `200` no-op: a no-op would still have to decide whether to write an activity row, and either answer is wrong.
- **An unknown `status_id`** → `422` `That status does not exist.` from `Rule::exists`, before the workflow runs. **A different message from the workflow's**, so a broken client can tell "no such status" from "no such move".
- **`resolution` or `reason` in the body** → `422` naming TM-39 / TM-40, not a silent drop. Test 11.
- **`status_id` missing, null, or non-numeric** → `422` `required` / `integer`. Note `ConvertEmptyStringsToNull` rewrites `""` to `null` **before** validation, so an empty form field reads as `required` — the same measured behaviour E5's overview records for `assigned_to`. **Do not remove that middleware.**
- **Two agents submit from stale dropdowns at the same time** → they serialise on `lockForUpdate()`; the first wins and the second is re-checked against the **new** status, so it gets a truthful `422` rather than overwriting. **This is the one behaviour in the story with no automated test** — a reliable two-connection race needs harnessing the suite does not have. It is enforced by the comment in task 2 and by review; verification step 6 exercises it by hand.
- **The graph table is empty (Story 31 migrated but not seeded)** → `allowed_transitions` is `[]`, the dialog renders `ticket-status-empty`, and every submitted move is a `422`. **Indistinguishable from correct enforcement in the logs** — which is why Story 31's plan makes the seeder a deploy step from this story onward. Say it in the PR.
- **A status with no outgoing edge** → same as above for that one ticket. Not an error.
- **A non-existent ticket** → `404` for **both** roles; route-model binding resolves before the policy, so there is no `403`-vs-`404` split here as there is on admin-policy routes. Test 12.
- **A soft-deleted ticket** → `404`; binding excludes trashed rows and there is no `withTrashed()` in `changeStatus()`. Test 13.
- **An inactive caller** → `401` from the `active` middleware, tokens revoked, before the policy runs. Unchanged.
- **`GET /tickets/{id}` on a ticket whose status was deleted from the graph** → `allowed_transitions` is `[]`; the rest of the payload is unaffected. No 500.
- **Query cost on `tickets.show`** → **exactly two more than before**: one for the edges, one for the eager-loaded target statuses. Measured baseline for the relation load alone is **5 unassigned / 6 assigned** (the three `users` relations collapse to one query when `assigned_to` and `escalated_by` are null). Test 18 pins the full-request number you measure.
- **A client that splices the status response into its detail state** → loses `can` and `allowed_transitions` and renders a toolbar from the previous status. Prevented by the return type in task 7 and the store comment in task 8; test 3 asserts the keys are absent so the contract is visible from the API side too.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketStatusTest.php` (new; `RefreshDatabase` + `$this->seed()`)

`tests/Feature/Tickets/` does not exist unless Story 26 landed first; this story creates it. Model the class on `RouteAuthorizationTest`'s `tokenFor()` helper (**122–125**), and reuse the `ticketAt(string $slug)` fixture helper from Story 31's `TicketWorkflowTest` — **there is still no `TicketFactory`** (`backend/database/factories/` holds only `UserFactory.php`; TM-59 owns it). If `TicketFactory` exists by then, use it and drop the helper.

1. `test_unauthenticated_request_is_rejected` — no token → `401`.
2. `test_an_agent_makes_a_legal_move` — **AC1.** `new → open` → `200`, `assertJsonPath('data.status.slug', 'open')`, and the row in `tickets` really moved.
3. `test_the_response_omits_can_and_allowed_transitions` — **the decision above.** `assertJsonMissingPath('data.can')` **and** `assertJsonMissingPath('data.allowed_transitions')`, while `data.requester`, `data.category`, `data.priority`, `data.status`, `data.creator` are all present.
4. `test_the_change_writes_one_status_changed_row` — **AC3.** Exactly one row; `event = 'status_changed'`, `field = 'status_id'`, `old_value === (string) $newId`, `new_value === (string) $openId`, `meta.from_name === 'New'`, `meta.to_name === 'Open'`, `user_id === $agent->id`. **This is the test that catches reading `$from` after `save()`** — move that line below the save and confirm it fails.
5. `test_an_illegal_move_is_rejected_and_changes_nothing` — **AC1's teeth.** `new → resolved` → `422`, `assertJsonPath('errors.status_id.0', 'A ticket cannot move from New to Resolved.')`, the ticket's `status_id` is unchanged, and `ticket_activities` has **no** `status_changed` row.
6. `test_an_admin_only_edge_is_refused_for_an_agent` — **AC1 + Story 31's AC5.** `resolved → closed` as an agent → `422`, `Only an administrator can move a ticket from Resolved to Closed.`
7. `test_an_admin_only_edge_is_allowed_for_an_admin` — the same move as an admin → `200`.
8. `test_moving_to_the_current_status_is_rejected` — → `422`, `This ticket is already Open.`, and **no** activity row.
9. `test_an_unknown_status_is_rejected_before_the_workflow` — `status_id: 999999` → `422`, `That status does not exist.` **Assert the message, not just the code** — it is what distinguishes the two failure modes.
10. `test_missing_and_malformed_status_id_are_rejected` — `{}`, `{"status_id": null}`, `{"status_id": "abc"}` → three `422`s; `required` on the first two, `integer` on the third.
11. `test_resolution_and_reason_are_prohibited` — two requests → `422` naming **TM-39** and **TM-40** respectively.
12. `test_a_nonexistent_ticket_is_404_for_both_roles` — id `999999` as agent **and** admin. Use `Auth::forgetGuards()` between them, as `RouteAuthorizationTest:109–115` does.
13. `test_a_soft_deleted_ticket_is_404` — `$ticket->delete()`, then the request → `404`.
14. `test_the_change_is_atomic` — bind a throwing `ActivityRecorder`; assert the exception propagates **and** `tickets.status_id` is unchanged. **Without this, deleting `DB::transaction()` passes every other test.**
15. `test_no_timestamp_is_stamped` — **the scope fence.** Move a ticket `in-progress → resolved` and assert `resolved_at` is **still null**; `resolved → closed` as an admin and assert `closed_at` is **still null**; `new → open` and assert `first_responded_at` is **still null**. **TM-39 is the story that deletes this test and replaces it.**
16. `test_show_returns_allowed_transitions_filtered_by_role` — **AC2's server half.** On a `resolved` ticket: an agent sees `['reopened']`; an admin sees `['closed', 'reopened']` in that order. Use `Auth::forgetGuards()` between the two calls.
17. `test_allowed_transitions_is_absent_from_other_responses` — `POST /tickets` (`201`) has no `data.allowed_transitions` and no `data.can`. Guards the `routeIs('tickets.show')` gate.
18. `test_show_query_count_is_pinned` — wrap `GET /tickets/{id}` in `DB::enableQueryLog()`. **Measure the number on an assigned ticket before task 3 and assert exactly that number + 2.** Record the measured pair in the PR description. The relation load alone is 5/6 (see Prerequisites); the request adds token and binding queries.
19. `test_the_status_route_carries_no_admin_middleware` — covered automatically by `RouteAuthorizationTest::test_policy_admin_routes_have_no_admin_middleware` once `ACCESS` has the key; no new test needed. **Listed so nobody adds a redundant one.**

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

20. `test_every_api_route_is_classified` and `test_every_classified_route_exists` cover `tickets.status` **and** `tickets.store` once task 5's two keys land. **This is the test that goes from red to green** — confirm it, and say so in the PR.

### Frontend — `frontend/src/components/TicketStatusDialog.spec.ts` (new)

`vi.mock('../api/tickets')`, mount with `createPinia()`, following `views/HealthView.spec.ts:1–23`. Build a `TicketDetail` fixture with `allowed_transitions: [Open, Pending]`.

21. Renders one option per `allowed_transitions` entry **and no others** — mount with a fixture whose `allowed_transitions` has 2 of the 7 statuses and assert the select has exactly 2 options. **AC2's client half.**
22. Renders `ticket-status-empty` and **no select** when `allowed_transitions` is `[]`.
23. `ticket-status-confirm` is disabled with nothing selected and enabled once an option is chosen.
24. Confirming calls `changeTicketStatus(ticket.id, selectedId)` **and then `getTicket(ticket.id)`**, in that order, and emits `changed`. **AC4's client half** — the re-read is the mechanism.
25. A `422` whose body is `{"errors": {"status_id": ["A ticket cannot move from New to Resolved."]}}` renders **that exact sentence** in `ticket-status-error` and **keeps the dialog mounted**. **This is AC5**; assert the string, not merely that an error appeared.
26. A non-`422` failure (a network error) renders `errorMessage()`'s fallback instead, and still keeps the dialog open.
27. `ticket-status-cancel` emits `close` and issues **no** request.

### Frontend — `frontend/src/components/TicketActionToolbar.spec.ts` (new — TM-26 never wrote it)

28. The Change status button is **no longer `disabled`** and no longer carries its `title`, and clicking it emits `status`.
29. **The other three buttons are still disabled and still carry their titles** — assert explicitly, or the next story silently enables them.

### Frontend — `frontend/src/views/TicketDetailView.spec.ts` (new — TM-26 never wrote it)

30. Clicking `action-status` mounts `ticket-status-dialog`; `close` unmounts it; the dialog is **absent** on first render.
31. After `changed`, the dialog unmounts and the status `ColorBadge` renders the **new** status name — driven by setting `store.current` to the re-read fixture. **AC4**, asserted at the view level rather than trusting the store.

---

## Verification Steps

1. **Gate:** from `backend/`, `php artisan test --filter='TicketWorkflowTest|StatusTransitionSeederTest'` → green. **If it is not, stop: Story 31 has not landed and nothing here can work.**
2. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
3. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
4. **Backend tests:** from `backend/`, `composer test`. Expect **+19 tests** over the baseline you measured and **2 failures, not 3** — `PasswordThrottleTest` and `TicketReferenceTest` only. Then `php artisan test --filter='TicketStatusTest|RouteAuthorizationTest'`.
5. **Prove test 4 earns its place:** move the `$from = $ticket->status;` line below `$ticket->save()`, re-run `--filter=test_the_change_writes_one_status_changed_row`, confirm it **fails** with `from_name` equal to the new status, restore.
6. **Prove test 14 earns its place, and exercise the lock:** delete `DB::transaction(` and its closing from `changeStatus()`, re-run `--filter=test_the_change_is_atomic`, confirm it **fails**, restore. Then open two terminals against `php artisan serve` and submit the same stale move from both — **one `200`, one `422`**, and exactly one activity row.
7. **Backend by hand.** `php artisan serve`, with an **agent** token and a real ticket id:
   - `GET …/tickets/<id>` → `data.allowed_transitions` lists **Open** and **Pending** for a New ticket, in that order, and `data.can.change_status` is `true`.
   - `POST …/tickets/<id>/status -d '{"status_id":<open>}'` → `200`, `data.status.slug` is `open`, **no `data.can`**, **no `data.allowed_transitions`**.
   - `SELECT event, field, old_value, new_value, meta, user_id FROM ticket_activities WHERE ticket_id=<id> ORDER BY id DESC LIMIT 1` → one `status_changed` row with both ids and both names.
   - `SELECT resolved_at, closed_at, first_responded_at FROM tickets WHERE id=<id>` → **all three still NULL** after moving the ticket all the way to Resolved.
   - The same move again → `422` `This ticket is already Open.`
   - `-d '{"status_id":<resolved>}'` from Open → `422` `A ticket cannot move from Open to Resolved.`
   - From Resolved, `-d '{"status_id":<closed>}'` → `422` `Only an administrator can move a ticket from Resolved to Closed.`; the same call with an **admin** token → `200`.
   - `-d '{"status_id":999999}'` → `422` `That status does not exist.`; `-d '{"status_id":<open>,"resolution":"x"}'` → `422` naming TM-39; `-d '{"status_id":<open>,"reason":"x"}'` → `422` naming TM-40.
   - `POST …/tickets/999999/status` → `404`, as agent and as admin.
   - `POST …/tickets` → still `201`, still defaults to New, still **no** `allowed_transitions` in the response.
8. **Prove the graph is the authority:** `DELETE FROM status_transitions WHERE from_status_id=<new>` then `GET …/tickets/<a New ticket>` → `allowed_transitions` is `[]`. Restore with `php artisan db:seed --class=StatusTransitionSeeder`.
9. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+11 tests** across three new spec files. Then `npx prettier --check src/api/tickets.ts src/stores/tickets.ts src/components/TicketStatusDialog.vue src/components/TicketActionToolbar.vue src/views/TicketDetailView.vue src/components/TicketStatusDialog.spec.ts src/components/TicketActionToolbar.spec.ts src/views/TicketDetailView.spec.ts`.
10. **Frontend by hand:** `npm run dev`.
    - Open a ticket as an **agent** → **Change status** is present and **enabled**; Edit, Assign and Escalate are still greyed out with their titles. *(If Story 26 landed, Assign is enabled too — that is expected.)*
    - Click it → the dialog lists **only** Open and Pending for a New ticket. Confirm no other status appears.
    - Choose Open, confirm → the dialog closes and the status badge changes **without a page reload and without the URL changing**. *(AC4.)*
    - Re-open the dialog → the options are now In Progress and Pending. The graph moved with the ticket.
    - Move the ticket to Resolved, open the dialog → **Closed is absent** for an agent. Sign in as an **admin**, reopen → Closed is there. *(AC2.)*
    - Force a rejection: in devtools, edit the select's value to a status id that is not offered, then confirm → the dialog **stays open** and shows the server's sentence verbatim, e.g. `A ticket cannot move from Resolved to Open.` *(AC5.)*
    - Cancel → nothing happens, no request in the network tab.
11. **Regression:** file a ticket from `/tickets/new` (`store.create` still works, still lands on the detail page), open `/admin/categories` and delete a category with tickets (the other dialog still renders its `422`), and confirm `git status` shows **no change** to `TicketWorkflow.php`, `StatusTransitionSeeder.php`, `TicketPolicy.php` or any migration.

---

## Done Criteria

- [ ] `POST /api/v1/tickets/{ticket}/status` returns `200` for any staff member, is classified `staff`, and carries **no** `admin` middleware.
- [ ] The move is validated by `TicketWorkflow::assertCanTransition()` **inside** `DB::transaction` **after** `lockForUpdate()` — the guard is not duplicated in a validation rule and `TicketWorkflow` was **not** modified.
- [ ] An illegal move, an admin-only move by an agent, and a move to the current status each return `422` under `errors.status_id` with Story 31's three distinct messages; the ticket is unchanged and no activity row is written.
- [ ] An unknown `status_id` returns `422` with its **own** message; `resolution` and `reason` return `422` naming TM-39 and TM-40.
- [ ] Exactly one `status_changed` row per accepted change, carrying both ids, both names under `meta`, and the acting user — proven by a test that fails when `$from` is read after `save()`.
- [ ] **No timestamp is written**: `resolved_at`, `closed_at` and `first_responded_at` are still null after moving a ticket through Resolved and Closed.
- [ ] `GET /api/v1/tickets/{ticket}` returns `data.allowed_transitions` in `sort_order`, filtered by the caller's role, and it is **absent** from every other response including this endpoint's own.
- [ ] `tickets.show`'s query count is exactly **two** more than the measured pre-change number, pinned by a test, with both figures in the PR description.
- [ ] `TicketActivityEvent::StatusChanged` is **appended**; `Created` and `CategoryChanged` untouched; **no migration**.
- [ ] `ACCESS` gains `tickets.status` **and** `tickets.store`, and `RouteAuthorizationTest::test_every_api_route_is_classified` goes from **red to green** — recorded in the PR as adopted from TM-22.
- [ ] The SPA's Change status button is enabled, opens a dialog offering **only** `allowed_transitions`, and the other three toolbar buttons are still disabled with their titles — asserted, not assumed.
- [ ] The dialog never imports `useMasterDataStore`, and there is no second copy of the graph in TypeScript.
- [ ] Confirming updates the status badge with **no reload and no navigation**; a rejection keeps the dialog open and renders the **server's sentence verbatim**.
- [ ] `changeTicketStatus()` is typed `Promise<Ticket>`, not `TicketDetail`, and the store re-reads via `loadTicket()` with the comment explaining why.
- [ ] The `mine_open` badge obligation from E5's overview is recorded as a comment in `store.changeStatus()`, deferred to TM-29, and **not** substituted with a poller.
- [ ] `docs/api-contract.md` documents the endpoint, the three `422` messages, the `404`-for-both-roles rule, the activity row, the omitted keys and why, and the Status workflow section no longer says the endpoint "arrives with TM-38".
- [ ] `pint --test`, `lint`, `typecheck` clean; **+19 backend and +11 frontend tests** over the measured baseline; **2** pre-existing backend failures remain, both named and neither in a file this story touched.
- [ ] No timestamp write, no resolution note, no reopen reason, no escalation, no list-view control, no bulk action, no new dependency, no migration.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 33 (TM-39, resolving requires a resolution note).**
