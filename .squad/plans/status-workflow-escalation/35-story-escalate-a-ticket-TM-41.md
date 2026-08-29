# Story 35 — Escalate a ticket (Story: TM-41)

## Prerequisites

- **Story 22 (TM-26) — landed, and it left this story a check in the wrong layer.** `TicketPolicy::escalate()` is already implemented as `! $ticket->status->is_terminal` (`backend/app/Policies/TicketPolicy.php:45–48`), and `TicketResource` already emits `can.escalate` (`backend/app/Http/Resources/V1/TicketResource.php:28`). Story 22's overview note says *"TM-41 must keep that check."* **The check is kept; it moves layer.** A precondition encoded in a policy can only ever produce `403 This action is unauthorized.`, and **AC5 requires a `422`.** See the first decision section — this is the same split Story 27 (TM-32) made for `claim`, for the same reason, and Story 22 could not have known.
- **Story 34 (TM-40) — PLANNED, NOT IMPLEMENTED.** [`34-story-reopen-a-closed-ticket-TM-40.md`](34-story-reopen-a-closed-ticket-TM-40.md) is the immediate predecessor. It fixes the **10–5000 character bound** this story reuses for the escalation reason, the **`field` is the audit key** invariant task 4 obeys, and the options-object signature on `changeTicketStatus`. **This story adds a separate endpoint and does not touch the status dialog.**
- **Stories 31–33 (TM-37/38/39) — PLANNED, NOT IMPLEMENTED.** This story needs **none of them** at runtime: escalation changes `escalation_level`, `priority_id` and `assigned_to`, and **never `status_id`**. It only reads `statuses.is_terminal`, which has been seeded since TM-16 (`backend/database/seeders/StatusSeeder.php:17–18`). **If the workflow stories slip, this one can still ship** — the only shared file is `TicketActivityEvent`, where every story appends.
- **Story 26 (TM-31) — PLANNED, NOT IMPLEMENTED, and task 4 may have to borrow one line from it.** `TicketActivityEvent::Assigned` (its task 5) and the `assigned` row shape (its task 3, `field = 'assigned_to'`, `meta.from_name` / `meta.to_name`) do not exist yet. Task 4 adds the enum case **only if Story 26 has not**, and writes the row in exactly its shape. **Do not invent a second assignment event.**
- **Story 30 (TM-35, agent workload) — PLANNED, NOT IMPLEMENTED, and task 2 adds a relation it needs.** `backend/app/Models/User.php` has **no** `assignedTickets()` relation (`grep -n "public function" backend/app/Models/User.php` → only `isAdmin()` and `scopeActive()`). Task 2 adds it here, as Story 34 adds `Ticket::activities()` for TM-46. **If TM-35 landed first, skip that half of task 2.**
- **Measured baseline, 2026-08-26, from `backend/`.** `composer test` → **101 tests, 98 passing, 3 failing**; `pint --test` exits `0`. Stories 32–34 take that to **2 failures** and roughly **+59 tests**. **Re-measure whatever is actually merged when you start** — this story's only hard dependency is TM-26, which is already in the tree.
- **Measured, and AC2 depends on it.** `PrioritySeeder::PRIORITIES` (`backend/database/seeders/PrioritySeeder.php:11–16`) seeds four rows: **Low = 1, Medium = 2 (default), High = 3, Urgent = 4.** `priorities.level` is `unsignedTinyInteger` and **unique** (`…_create_priorities_table.php:52`), and `Priority::scopeOrdered()` orders by `level` ascending (`backend/app/Models/Priority.php:48–52`). **Higher level means higher priority; Urgent is the ceiling.**
- **Docker up**, `tm-mysql-test` healthy on **3307**. **No new composer or npm dependency, and no migration.** All four escalation columns already exist and are already cast and already in the API response: `escalation_level` (tinyint, default 0), `escalated_at`, `escalated_by`, `escalation_reason` (`…_create_tickets_table.php:25–28`; `backend/app/Models/Ticket.php:20`; `TicketResource.php:21–27`). **This story fills columns that have been empty since TM-21.**

---

## Story Goal

An agent who cannot finish a ticket says so in writing, and the ticket visibly moves up and moves on — same ticket, same history, louder.

1. `POST /api/v1/tickets/{ticket}/escalate` takes a `reason` of 10–5000 characters, increments `escalation_level`, and stamps `escalated_at`, `escalated_by` and `escalation_reason`.
2. The priority moves **up one level**, and stops at Urgent instead of overflowing.
3. The ticket is reassigned to the **active admin with the fewest open tickets**, unless it is already held by an active admin.
4. One `escalated` activity row records the reason and the resulting level; a companion `assigned` row records the previous and new holder **only when the assignment actually changed**.
5. A **terminal** ticket returns `422` — not `403` — and the Escalate button is hidden for it.

**Not in scope, and each belongs to a named story.** **No status change.** Escalation never touches `status_id`, so it goes nowhere near `TicketWorkflow` or `status_transitions`; an escalated ticket keeps its status. **No de-escalation** — nothing in the backlog asks to lower a level, and adding one would need its own rules for the priority and the assignee. **No escalation badge on the list, no escalated filter, no dashboard count, no sort by most-recently-escalated** — every one of those is **TM-42** (E6-S6). **No email to admins** — TM-53 (E8) owns *"Notify admins on escalation"*, and E8's rule is that nothing is sent inline during a request. **No auto-escalation on a timer** — TM-43 flags stale tickets and deliberately does not escalate them. **No SLA, no escalation-rate reporting** (E9). **No change to `TicketWorkflow`, `status_transitions`, `TicketTimestamps` or any seeder.**

---

## Context — Read These Files First

1. `backend/app/Policies/TicketPolicy.php` — **`escalate()` at 45–48 is the one policy method this story changes**, and `changeStatus()` at **40–43** is the shape it changes to. Read `assign()` at **35–38** as well: it stays admin-only and this story does **not** reuse it, even though escalation reassigns.
2. `backend/app/Http/Resources/V1/TicketResource.php` — **lines 21–28.** `escalation_level` (**22**), `escalated_at` (**23**) and `escalated_by` (**21**) are already emitted unconditionally; `escalation_reason` (**27**) and `can` (**28**) are gated on `routeIs('tickets.show')`. Task 3 changes exactly one entry inside the `can` array and adds no key.
3. `backend/database/seeders/PrioritySeeder.php:11–16` — the four levels. **`level` ascending is "more urgent"**, which is the opposite of what "priority 1" means in some trackers. Task 5's query depends on reading this correctly.
4. `backend/app/Models/Priority.php:48–52` — `scopeOrdered()` orders by `level`. Task 5 does **not** use it (it needs a `>` bound, not the whole list) but must agree with its direction.
5. `backend/app/Models/User.php` — `isAdmin()` at **41–44**, `scopeActive()` at **46–50**, `role` cast to `UserRole` at **36**. **No relations at all.** Task 2 adds the first one.
6. `backend/app/Http/Controllers/Api/V1/CategoryController.php:58–97` — **the whole method is this story's template.** `authorize()` outside the transaction (**60**), `DB::transaction` (**63**), `lockForUpdate()->first()` (**64**), a hand-built `422` when there is no valid target (**78–84**), and `reassignTickets()` (**86–97**) for the activity shape — `field`, stringified ids, human names in `meta`, and **`meta.reason` carrying a machine cause** (**94**).
7. `backend/app/Services/ActivityRecorder.php:13–19` — `record()` and its five defaults. Task 4 calls it **twice** in one transaction; the `LogicException` at **26–28** is why both calls are inside it.
8. `backend/app/Enums/TicketActivityEvent.php` — `Created` and `CategoryChanged` at **7–8**. The naming convention is visible there: **`<field>_changed`**. Task 4 appends `Escalated`, and `Assigned` only if Story 26 has not.
9. [`../assignment-workload/26-story-assign-a-ticket-to-an-agent-TM-31.md`](../assignment-workload/26-story-assign-a-ticket-to-an-agent-TM-31.md) — **its task 3 and task 5.** The `assigned` row's exact shape and the measured fact that `->where('role', UserRole::Agent)` works without `->value` because of the cast. Task 5 uses the same form for `UserRole::Admin`.
10. [`../assignment-workload/00-overview.md`](../assignment-workload/00-overview.md) — two notes this story obeys: *"'Open' means `statuses.is_terminal = false` everywhere in this epic, never `bucket = 'open'`"*, and *"Detect assignment changes from `ticket_activities`, not from a model observer."* The first defines task 5's "fewest open tickets"; the second is why task 4 writes a companion row instead of relying on a model event.
11. `frontend/src/components/TicketActionToolbar.vue` — **2 lines.** `action-escalate` is `v-if="ticket.can.escalate"`, `disabled`, `title="Escalation arrives with TM-41"`. **This is the last of Story 22's four buttons**, and this is the story that enables it.
12. `frontend/src/views/TicketDetailView.vue:11` — **`ticket-escalation` already exists**: `v-if="store.current.escalation_level > 0"`, rendering `escalation_reason` and `escalated_by?.name`. It has shown nothing since TM-26 because the columns were never written. Task 9 adds the level to it and changes nothing else about it.
13. `frontend/src/components/CategoryDeleteDialog.vue` and `frontend/src/components/UserFormDialog.vue:33–36` — the dialog shape and the `validationErrors()`-then-`errorMessage()` order. Task 8 is a one-field version of the first.
14. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php:17` — `ACCESS`. Task 6 adds one `staff` key.
15. `docs/api-contract.md` — the endpoints table ends at **47**; `## Authorization` at **18–28**. Task 10 adds a row and a subsection.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Escalate a non-terminal ticket | No endpoint | `200`, level +1, four columns stamped |
| Escalate a **terminal** ticket | `can.escalate` is false; a direct call would be **`403`** | **`422`** — AC5 |
| Caller is an agent | — | Allowed. Escalation is every staff member's affordance |
| `reason` missing or under 10 characters | — | `422` under `errors.reason` |
| Priority is Low / Medium / High | — | Moves up exactly one level |
| Priority is **Urgent** | — | **Stays Urgent.** No error, no row, no overflow |
| Ticket held by an agent, or unassigned | — | Reassigned to the least-loaded active admin |
| Ticket already held by an **active admin** | — | **Assignment untouched**, no `assigned` row |
| No active admin exists | — | **`422`** — the escalation is refused, nothing is written |
| Second escalation of the same ticket | — | Level 2, priority up again, `escalation_reason` **overwritten**; the trail keeps both reasons |
| Status, `resolved_at`, `first_responded_at` | — | **Untouched.** Escalation is not a transition |

---

## Decision — `TicketPolicy::escalate()` becomes a pure role gate, and the terminal check moves to a `422`

`TicketPolicy::escalate()` currently returns `! $ticket->status->is_terminal`. **AC5 requires a `422` for a terminal ticket, and a policy cannot produce one** — a denied policy is `403 This action is unauthorized.`, the exact string `RouteAuthorizationTest.php:40` asserts. So:

- **`escalate()` becomes `return true;`** — a pure "may any staff member operate this control", matching `changeStatus()` (**40–43**).
- **The terminal check moves into the handler**, inside the transaction and after the lock, as a `ValidationException` → `422`. Under the lock, so a ticket resolved by someone else a moment ago is caught rather than escalated.
- **`can.escalate` in `TicketResource` keeps the state half**: `$request->user()->can('escalate', $this->resource) && ! $this->status->is_terminal`. The button stays hidden on a terminal ticket, which is the behaviour Story 22 shipped and users have seen.

**This is exactly Story 27's split**, which its overview records: *"a precondition encoded in a policy can only ever produce `403` … so Story 27 adds a separate `claim` ability … and the 'is there anything to claim' half lives in `TicketResource`'s `can.claim`."* Story 22's *"TM-41 must keep that check"* is honoured — **the check is not dropped, it is moved to the layer that can return the required status code**. Test 5 asserts the `422` and test 6 asserts the button is still hidden; **both are required, or moving the check silently loses one half.**

## Decision — no active admin is a `422`, not a silent unassign

If `User::active()->where('role', Admin)` is empty, the escalation is **refused** with `422` and *"There is no active administrator to escalate to. Activate an admin account first."* — nothing is written, not even the level.

- **The alternative — escalate anyway and leave it unassigned — quietly breaks AC3** while reporting success. An agent would believe the ticket reached someone.
- **`CategoryController::reassignmentRequired()` (78–84) is the established shape** for "this operation needs a target and there is none": a `422` naming the missing thing and telling the operator how to fix it.
- **It is unreachable in a seeded system** (`AdminUserSeeder` creates one) and reachable only by deactivating every admin, which is an operational mistake worth surfacing loudly. Test 12.

## Decision — routed to the active admin with the **fewest open tickets**

Not the lowest id, and not round-robin.

- **Lowest id sends every escalation in the system to one person** from day one. That is not "routed to an admin", it is a queue with one server.
- **Round-robin needs persistent state** — a cursor table or a counter column — for a tie-break that a `COUNT` already answers better.
- **"Open" means `is_terminal = false`**, the definition E5's overview fixes for the whole product. Not `bucket = 'open'`: a Pending ticket is still on someone's plate.
- **Ties break on the lowest id**, so the choice is deterministic and testable.
- **Soft-deleted tickets do not count** — the relation's default scope excludes them.

`User::assignedTickets()` is added in task 2. **TM-35 (agent workload) needs the same relation**, so it is added once here rather than twice, exactly as Story 34 adds `Ticket::activities()` for TM-46.

**The actor is not excluded from the pool.** An admin escalating a ticket they hold, who is also the least loaded, keeps it — and the level and priority still move, which is what they were asking for. Excluding them would send the ticket to a busier colleague for no reason. Test 11 pins it.

## Decision — two activity rows, and only when something changed

- **`escalated`, always.** `field = 'escalation_level'`, `old_value` / `new_value` the two levels, `meta.reason` the text, `meta.from_priority` / `meta.to_priority` the two priority names. **That single row satisfies AC4** — *"captures the reason and the resulting escalation level"*.
- **`assigned`, only when `assigned_to` actually changed.** Story 26's exact shape: `field = 'assigned_to'`, stringified user ids, `meta.from_name` / `meta.to_name`, plus `meta.reason` carrying the escalation reason. **This is AC3's *"the previous assignee is preserved in the activity trail"***, and it is a separate row because E5's overview requires assignment history to be reconstructable from `ticket_activities` — which means every assignment change must be findable by **`field = 'assigned_to'`**, the same audit rule Story 34 fixed for `status_id`.
- **No `priority_changed` row.** The priority move is recorded in the `escalated` row's meta only. **The consequence, stated:** priority history is not field-keyed for escalations. **TM-27 (edit a ticket) owns `priority_id` history and has not defined its event yet** — when it does, that is the story that decides whether escalation should emit a companion row, and the `escalated` row's meta already holds the data it would need. **Do not invent a `priority_changed` event here** and force TM-27 to match it.

## Decision — 10–5000 characters, the third use of the same bound

Story 33 set 10–5000 for `resolution`, Story 34 reused it for `reason`, and Story 34's overview note says *"TM-41's escalation reason should too"*. **It does.** One bound for every free-text justification in the workflow; a reader learns it once.

---

## Backend Tasks

### 1 — The form request

**Create file: `backend/app/Http/Requests/Api/V1/EscalateTicketRequest.php`**

```php
<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class EscalateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('escalate', $this->route('ticket'));
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:5000'],
            // The terminal check is NOT here: it needs the row lock to be
            // race-free, so it lives in the handler. See the plan's first
            // decision section for why it is not in the policy either.
            'priority_id' => ['prohibited'],
            'assigned_to' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why this ticket needs to be escalated.',
            'reason.min' => 'The escalation reason must be at least 10 characters.',
            'priority_id.prohibited' => 'Escalation raises the priority by one level on its own.',
            'assigned_to.prohibited' => 'Escalation routes the ticket to an admin on its own.',
        ];
    }
}
```

The two `prohibited` rules copy `StoreTicketRequest.php:38–39`'s idiom: a caller who tries to steer the outcome is told the rule, not silently ignored. `TrimStrings` already trims, so `"   "` fails `required`, not `min` — **do not add a `prepareForValidation` trim**; test 3 pins it.

### 2 — The policy, and the relation the routing needs

**File: `backend/app/Policies/TicketPolicy.php`**

Replace the body of `escalate()` (**45–48**):

```php
    public function escalate(User $user, Ticket $ticket): bool
    {
        // A pure role gate, deliberately. The "is this ticket terminal" half
        // moved to TicketController::escalate(), because AC5 of TM-41 requires
        // a 422 and a denied policy can only ever be a 403. TicketResource's
        // `can.escalate` keeps the state half so the button still hides.
        return true;
    }
```

**File: `backend/app/Models/User.php`**

Add after `scopeActive()` (**ends at 50**), with `use Illuminate\Database\Eloquent\Relations\HasMany;`:

```php
    /** @return HasMany<Ticket, $this> */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_to');
    }
```

Docblock shape from `Requester::tickets()` (`backend/app/Models/Requester.php:36–40`). **`Ticket` uses `SoftDeletes`, so the relation excludes trashed rows by default** — which is what task 5's count wants. **If TM-35 has already added this relation, skip it and do not add a second.**

### 3 — `can.escalate` keeps the state half

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Inside the `can` array on **line 28**, change **only** the `escalate` entry:

```php
'escalate' => $request->user()->can('escalate', $this->resource) && ! $this->status->is_terminal,
```

`status` is eager-loaded on `tickets.show` (`TicketController.php:27`), so this costs no query. **Leave `update`, `assign` and `change_status` exactly as they are.**

### 4 — The controller method

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add imports: `App\Http\Requests\Api\V1\EscalateTicketRequest`, `App\Models\Priority` *(already imported at line 9)*, `App\Models\User`, `Illuminate\Validation\ValidationException`.

**File: `backend/app/Enums/TicketActivityEvent.php`** — append:

```php
    case Escalated = 'escalated';
```

…and `case Assigned = 'assigned';` **only if Story 26 has not already added it.** Check first: `grep -n "case Assigned" backend/app/Enums/TicketActivityEvent.php`.

Add to `TicketController`, after `changeStatus()` (or after `store()` if Story 32 has not landed):

```php
    public function escalate(EscalateTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
    {
        $this->authorize('escalate', $ticket);
        $actor = $request->user();
        $reason = $request->string('reason')->value();

        $ticket = DB::transaction(function () use ($ticket, $actor, $reason, $recorder): Ticket {
            Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
            $ticket->refresh()->load(['status', 'priority', 'assignee']);

            // AC5. Under the lock, so a ticket someone else resolved a moment
            // ago is caught here instead of being escalated after the fact.
            if ($ticket->status->is_terminal) {
                throw ValidationException::withMessages(['status' => ["A {$ticket->status->name} ticket cannot be escalated."]]);
            }

            $admin = $this->escalationTarget($ticket);
            $fromPriority = $ticket->priority;
            $toPriority = $this->nextPriority($fromPriority);
            $fromAssigneeId = $ticket->assigned_to;
            $fromAssigneeName = $ticket->assignee?->name;

            $ticket->escalation_level = $ticket->escalation_level + 1;
            $ticket->escalated_at = now();
            $ticket->escalated_by = $actor->getKey();
            $ticket->escalation_reason = $reason;
            $ticket->priority_id = $toPriority->getKey();
            $ticket->assigned_to = $admin->getKey();
            $ticket->save();

            $recorder->record($ticket->getKey(), TicketActivityEvent::Escalated, [
                'user_id' => $actor->getKey(),
                'field' => 'escalation_level',
                'old_value' => (string) ($ticket->escalation_level - 1),
                'new_value' => (string) $ticket->escalation_level,
                'meta' => ['reason' => $reason, 'from_priority' => $fromPriority->name, 'to_priority' => $toPriority->name],
            ]);

            // Only when it really moved. Assignment history must be findable by
            // field = 'assigned_to' -- E5's rule -- so this is a second row in
            // Story 26's exact shape rather than a key on the escalated row.
            if ($fromAssigneeId !== $admin->getKey()) {
                $recorder->record($ticket->getKey(), TicketActivityEvent::Assigned, [
                    'user_id' => $actor->getKey(),
                    'field' => 'assigned_to',
                    'old_value' => $fromAssigneeId === null ? null : (string) $fromAssigneeId,
                    'new_value' => (string) $admin->getKey(),
                    'meta' => ['from_name' => $fromAssigneeName, 'to_name' => $admin->name, 'reason' => $reason],
                ]);
            }

            return $ticket;
        });

        return TicketResource::make($ticket->load(['requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy']))->response();
    }
```

Six things not to re-derive:

- **Every "from" value is captured before `save()`.** `$fromPriority`, `$fromAssigneeId` and `$fromAssigneeName` are read off the refreshed model first; reading them afterwards records `X → X`. Tests 15 and 17 catch it.
- **`old_value` for the level is computed as `$ticket->escalation_level - 1`** *after* the increment, deliberately, so the two values can never be read from different points in the method.
- **`old_value` is `null`, not `"null"`, on a first assignment** — Story 26's rule, and `ActivityRecorder::recordMany()` writes the literal string `"null"` into `meta` if a key is omitted, which is why `record()` is used and the value is passed explicitly.
- **The response is `show()`'s shape minus the `routeIs('tickets.show')` keys**, matching Story 26 and Story 32. The SPA re-reads the detail; **do not add a flag to force `can` into this response.**
- **`status_id` is never assigned.** Escalation is not a transition. `git status` on `TicketWorkflow.php` is part of verification step 11.
- **Both `record()` calls are inside the transaction**, or `ActivityRecorder`'s `LogicException` (**26–28**) fires.

### 5 — The two private helpers

Add to `TicketController`, beside `defaultKey()` (**52–61**):

```php
    private function escalationTarget(Ticket $ticket): User
    {
        // Already held by an active admin? Leave it there -- reassigning to a
        // less busy admin would churn ownership for no gain.
        if ($ticket->assignee !== null && $ticket->assignee->is_active && $ticket->assignee->isAdmin()) {
            return $ticket->assignee;
        }

        // "Open" is is_terminal = false, never bucket = 'open' -- E5's rule for
        // the whole product. Ties break on the lowest id so the choice is
        // deterministic. The actor is NOT excluded: an admin escalating a
        // ticket they hold keeps it, and still gets the level and priority.
        $admin = User::query()->active()->where('role', UserRole::Admin)
            ->withCount(['assignedTickets as open_tickets_count' => fn ($query) => $query->whereHas('status', fn ($status) => $status->where('is_terminal', false))])
            ->orderBy('open_tickets_count')->orderBy('id')->first();

        if ($admin === null) {
            throw ValidationException::withMessages(['assigned_to' => ['There is no active administrator to escalate to. Activate an admin account first.']]);
        }

        return $admin;
    }

    private function nextPriority(Priority $current): Priority
    {
        // AC2: one level up, capped. Levels ascend -- Low 1, Medium 2, High 3,
        // Urgent 4 -- so "up" is the smallest level greater than this one, and
        // `?? $current` is the cap. A gap in the ladder is handled for free.
        return Priority::query()->where('level', '>', $current->level)->orderBy('level')->first() ?? $current;
    }
```

Add `use App\Enums\UserRole;` and `use App\Models\User;`.

- **`withCount` with a constrained closure is one query**, not N. The `whereHas` on `status` is a subquery on `statuses`, which has seven rows.
- **`->where('role', UserRole::Admin)` needs no `->value`** — `role` is cast to the enum (`User.php:36`), measured by Story 26.
- **`nextPriority()` returns the current priority when capped**, so the caller never branches on null and `meta.to_priority` is always a real name.

### 6 — The route and its classification

**File: `backend/routes/api.php`** — inside the `['auth:sanctum', 'active']` group, after the other ticket routes:

```php
    Route::post('/tickets/{ticket}/escalate', [TicketController::class, 'escalate'])->name('tickets.escalate');
```

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`** — add one key to `ACCESS` (**17**):

```php
'tickets.escalate' => 'staff',
```

**`staff`, not `admin-policy`.** Every staff member may escalate; the target is an admin, the caller need not be. **Do not add it to `test_agent_reaches_staff_routes` (53–59)** — that test issues bare `GET`s.

---

## Frontend Tasks

### 7 — The API call and the store action

**File: `frontend/src/api/tickets.ts`**

```ts
export async function escalateTicket(id: number, reason: string): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/escalate`, { reason }); return data.data }
```

**`Promise<Ticket>`, not `TicketDetail`** — the response carries no `can` and no per-caller keys, the same contract Stories 26 and 32 document.

**File: `frontend/src/stores/tickets.ts`**

```ts
const escalating = ref(false)
async function escalate(id: number, reason: string): Promise<void> {
  escalating.value = true
  try {
    await escalateTicket(id, reason)
    // Re-read: escalating changes the priority, the assignee and can.escalate
    // itself, and the response deliberately omits the last of those.
    await loadTicket(id)
  } finally {
    escalating.value = false
  }
}
```

Return both. **Do not catch here** — the dialog renders the error, matching `CategoryDeleteDialog.vue:13`.

### 8 — The dialog

**Create file: `frontend/src/components/TicketEscalateDialog.vue`**

```ts
const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ escalated: []; close: [] }>()
```

State: `reason` (`''`), `error` (`''`), `errors` (`Record<string, string[]>`), `const store = useTicketsStore()`. `const tooShort = computed(() => reason.value.trim().length < 10)`.

```ts
async function confirm(): Promise<void> {
  errors.value = {}
  error.value = ''
  try {
    await store.escalate(props.ticket.id, reason.value)
    emit('escalated')
  } catch (reason_) {
    errors.value = validationErrors(reason_)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(reason_)
  }
}
```

| Element | `data-testid` | Notes |
|---|---|---|
| Root | `ticket-escalate-dialog` | |
| Current level | `ticket-escalate-level` | **"Currently level {{ ticket.escalation_level }}"**, `v-if="ticket.escalation_level > 0"` |
| Reason textarea | `ticket-escalate-reason` | `v-model="reason"`, placeholder **"Why can this not be resolved at your level?"** |
| Hint | `ticket-escalate-hint` | `v-if="tooShort"` — **"At least 10 characters."** |
| Error | `ticket-escalate-error` | `v-if="error || Object.keys(errors).length"`, renders `errors.reason?.[0] ?? errors.status?.[0] ?? errors.assigned_to?.[0] ?? error` |
| Confirm | `ticket-escalate-confirm` | `:disabled="tooShort || store.escalating"` |
| Cancel | `ticket-escalate-cancel` | `@click="emit('close')"` |

- **The error element reads three server keys.** The `422`s this endpoint produces land under `reason`, `status` (terminal) and `assigned_to` (no admin), and all three must be legible to the agent. **This is the only place in the SPA that reads more than one validation key**, and it is why the order is explicit rather than "the first key of the object".
- **The dialog does not show or choose the target admin.** Which admin receives it is a server decision; showing a guess that the server then overrides would be worse than saying nothing. The re-read puts the real name on the page a moment later.
- **On error the dialog stays open** with the reason intact.

### 9 — The toolbar button and the detail page

**File: `frontend/src/components/TicketActionToolbar.vue`**

For the `action-escalate` button **only**: remove `disabled`, remove `title="Escalation arrives with TM-41"`, add `@click="emit('escalate')"`, and declare the emit. Keep `v-if="ticket.can.escalate"` — the server now folds the terminal check into that flag (task 3). **This is Story 22's fourth and final button; after this story none of the four carries a `title` placeholder.**

**File: `frontend/src/views/TicketDetailView.vue`**

Add `const escalateOpen = ref(false)`, import the dialog, bind `@escalate="escalateOpen = true"` on `<TicketActionToolbar>`, and mount it inside the `ticket-detail` article:

```html
<TicketEscalateDialog v-if="escalateOpen && store.current" :ticket="store.current" @escalated="escalateOpen = false" @close="escalateOpen = false" />
```

Extend the **existing** `ticket-escalation` section on line **11** — do not replace it — so it leads with the level:

```html
<section v-if="store.current.escalation_level > 0" data-testid="ticket-escalation"><strong data-testid="ticket-escalation-level">Escalated to level {{ store.current.escalation_level }}</strong>{{ store.current.escalation_reason }} {{ store.current.escalated_by?.name }}<span v-if="store.current.escalated_at" :title="store.current.escalated_at">{{ relativeAge(store.current.escalated_at) }}</span></section>
```

- **`{{ }}` interpolation, never `v-html`** — the reason is agent-authored free text stored verbatim, the same rule Story 33 fixes for the resolution note.
- **No router navigation and no manual refetch** — `store.escalate()` already re-read, so the priority badge, the assignee line and this section all update together. **That is the "no full page reload" half of every dialog story in this epic.**

---

## Documentation

### 10 — The API contract

**File: `docs/api-contract.md`**

One row in the endpoints table:

```markdown
| `POST` | `/api/v1/tickets/{ticket}/escalate` | Raise a ticket's escalation level, priority and visibility. | bearer (TicketPolicy) | TM-41 |
```

And a subsection:

```markdown
### `POST /api/v1/tickets/{ticket}/escalate`

Requires a bearer token; every staff member may escalate. Body is
`{"reason": "<10–5000 chars>"}`.

Increments `escalation_level`, stamps `escalated_at`, `escalated_by` and
`escalation_reason` (the column holds the **latest** reason; the activity trail
holds every one), raises the priority by exactly one level and stops at the
highest, and reassigns the ticket to the active admin with the fewest open
tickets — unless it is already held by an active admin, in which case the
assignment is left alone. **The status is never changed.**

Writes one `escalated` activity row (`field = 'escalation_level'`, both levels,
`meta.reason`, `meta.from_priority`, `meta.to_priority`) and, only when the
assignment moved, one `assigned` row in the same shape the assign endpoint uses,
so assignment history stays findable by `field = 'assigned_to'`.

A **terminal** ticket returns `422` under `errors.status` — not `403`;
`TicketPolicy::escalate` is a pure role gate and the state check lives in the
handler, while `can.escalate` on the detail response folds both together so the
UI hides the control. A missing or too-short `reason` returns `422` under
`errors.reason`; a `priority_id` or `assigned_to` in the body returns `422`. If
no active administrator exists the escalation is refused with `422` under
`errors.assigned_to` and nothing is written.

Returns `200` with the ticket in the detail shape minus `can` and the other
per-caller keys. No email is sent — TM-53 owns escalation notifications.
```

Correct **lines 27–28** if they still read *"Category and ticket policies arrive with their models in TM-17 and TM-22"*, as Stories 26 and 32 both plan to; if either has already done it, leave it.

---

## Edge Cases & Failure Modes

- **A terminal ticket** → `422` `A Resolved ticket cannot be escalated.` (or `Closed`), under `errors.status`, **checked under the row lock** so a concurrent resolve cannot slip past. **Not `403`.** Test 5.
- **The Escalate button on a terminal ticket** → hidden, because `can.escalate` folds the state check back in (task 3). **Both halves are tested** — test 5 for the code, test 6 for the flag — because moving a check between layers is exactly how one half gets lost.
- **`reason` missing, `"   "`, or 9 characters** → `422` `required` / `required` / `min`. `TrimStrings` turns whitespace into `""` **before** validation, so the middle case is `required`, not `min`. Tests 2–4.
- **A 5001-character reason** → `422` `max`.
- **`priority_id` or `assigned_to` in the body** → `422` naming the rule. A caller steering the outcome is corrected, not ignored. Test 7.
- **Priority already Urgent** → stays Urgent, `200`, `meta.from_priority === meta.to_priority === 'Urgent'`, **no error and no overflow**. `nextPriority()` returns the current row. Test 9.
- **Priority Low** → Medium, not High. One level, not "to the top". Test 8.
- **Ticket already held by an active admin** → assignment untouched, **no `assigned` row**, and the level and priority still move. Test 10.
- **The actor is an admin holding the ticket** → keeps it, by the same branch. **Deliberate**, not an oversight: they asked for the level, not for someone else to take it. Test 11.
- **The assignee is an admin who has been deactivated** → the branch requires `is_active`, so the ticket is rerouted to a live admin. **Measured consequence of E5's rule that deactivation does not clear existing assignments.** Test 13.
- **No active admin at all** → `422` under `errors.assigned_to`, **nothing written** — the exception is thrown inside the transaction before any `save()`. Test 12.
- **Exactly one active admin** → they receive it regardless of load. The `orderBy` has nothing to choose between.
- **Two admins tied on open tickets** → the lower id wins, deterministically. Test 14 seeds a tie on purpose; **without the `orderBy('id')` this test flaps.**
- **Second escalation of the same ticket** → level 2, priority up again, `escalation_reason` **overwritten by the newer text**, `escalated_at` and `escalated_by` restamped. **The column is current state; the trail is history** — both reasons are in `ticket_activities`. Test 16, which asserts the old reason is still readable there.
- **`escalation_level` at 255** → `unsignedTinyInteger` (`…_create_tickets_table.php:25`). MySQL strict mode **rejects** 256 with an out-of-range error rather than wrapping to 0, so the 256th escalation of a single ticket is a 500. **Accepted as unreachable; no cap is added**, because a cap would need a product rule about what escalating a maxed ticket means and nothing in the backlog has one.
- **A soft-deleted ticket** → `404`; binding excludes trashed rows and there is no `withTrashed()` in `escalate()`.
- **A non-existent ticket** → `404` for both roles; binding precedes the policy.
- **Two agents escalate at once** → they serialise on `lockForUpdate()`; the level goes to 2, not to 1 twice, and two `escalated` rows record levels 0→1 and 1→2. **The read-modify-write on `escalation_level` is the reason the lock is not optional here** — more so than in any other story in this epic. Test 18 asserts the sequential case; the concurrent one is exercised by hand in verification step 8.
- **A reason containing `<script>` or Arabic** → stored **verbatim** in both the column and `meta.reason` (`ActivityRecorder.php:34`, utf8mb4). Escaping happens at render, in Vue's `{{ }}`. Test 19 round-trips both; frontend test 26 asserts the escape.
- **Status, `resolved_at`, `closed_at`, `first_responded_at`** → all untouched. Test 20 pins it; escalation is not a transition and must never become one.
- **Query cost** → the endpoint costs one extra query for the admin `withCount` and one for `nextPriority()`. `tickets.show` is **unchanged** — task 3 adds no query because `status` is already eager-loaded.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketEscalateTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Reuse the `ticketAt()` fixture helper from Story 31's `TicketWorkflowTest` if it exists, otherwise build tickets with `Model::create` and the seeded master data — **there is still no `TicketFactory`** (TM-59). `tokenFor()` from `RouteAuthorizationTest:122–125`. **`AdminUserSeeder` creates one admin; most tests need a second.**

1. `test_unauthenticated_request_is_rejected` — `401`.
2. `test_escalating_without_a_reason_is_rejected` — `422`, `errors.reason.0` is `Say why this ticket needs to be escalated.`; **`escalation_level` still 0, no activity row.**
3. `test_a_whitespace_only_reason_is_rejected_as_required` — `"   "` → the **`required`** message, not `min`.
4. `test_a_short_reason_is_rejected` — 9 characters → `The escalation reason must be at least 10 characters.` A 5001-character one → `max`.
5. `test_a_terminal_ticket_returns_422_not_403` — **AC5.** A Resolved ticket and a Closed ticket → `422` both times, message naming the status, `errors.status`. **Assert the status code is 422 and explicitly `assertStatus(422)` rather than `assertUnprocessable()` alone**, so a regression to `403` is unmistakable.
6. `test_can_escalate_is_false_on_a_terminal_ticket` — `GET /tickets/{id}` on a Resolved ticket → `data.can.escalate` is `false`; on an Open one → `true`. **The other half of the layer move.**
7. `test_priority_id_and_assigned_to_are_prohibited` — two requests → `422` each, naming the rule.
8. `test_escalation_raises_the_priority_one_level` — **AC2.** Low → Medium; Medium → High; High → Urgent. Three tickets, three assertions, **not** a loop that hides which one broke.
9. `test_urgent_does_not_overflow` — **AC2's second half.** An Urgent ticket → `200`, priority still Urgent, `meta.from_priority === meta.to_priority === 'Urgent'`.
10. `test_a_ticket_held_by_an_active_admin_is_not_reassigned` — assignment unchanged, **no `assigned` row**, level and priority still moved.
11. `test_an_admin_escalating_their_own_ticket_keeps_it` — pins the deliberate non-exclusion.
12. `test_escalation_is_refused_when_no_active_admin_exists` — deactivate every admin → `422`, `errors.assigned_to.0` is `There is no active administrator to escalate to. Activate an admin account first.`, **and `escalation_level` is still 0** — the proof nothing was written.
13. `test_an_inactive_admin_assignee_is_replaced` — ticket held by a deactivated admin, a live admin exists → reassigned to the live one, with an `assigned` row.
14. `test_the_least_loaded_active_admin_receives_it` — **AC3.** Three active admins: one with 3 open tickets, one with 1, one with 1 (the tie), plus a terminal and a soft-deleted ticket on the least-loaded one that **must not count**. Assert the winner is the **lower-id** of the two tied admins. **Remove `orderBy('id')` and confirm this flaps.**
15. `test_the_escalated_row_captures_the_reason_and_the_level` — **AC4.** One `escalated` row; `field = 'escalation_level'`, `old_value = '0'`, `new_value = '1'`, `meta.reason` verbatim, `meta.from_priority` / `meta.to_priority`, `user_id` the actor. **Read `$fromPriority` after `save()` in task 4 and confirm this fails.**
16. `test_the_assigned_companion_row_preserves_the_previous_holder` — **AC3's trail half.** Ticket held by an agent → one `assigned` row with `field = 'assigned_to'`, `old_value` the agent's id, `new_value` the admin's, `meta.from_name` / `meta.to_name`, `meta.reason` the escalation reason. On an **unassigned** ticket, `old_value` is **null**, not `"null"`.
17. `test_a_second_escalation_increments_and_overwrites` — escalate twice with different reasons. `escalation_level` is `2`; `escalation_reason` is the **second** text; **both** reasons are still readable from `ticket_activities`; two `escalated` rows with `0→1` and `1→2`.
18. `test_escalation_is_atomic` — bind a throwing `ActivityRecorder`; the exception propagates and `escalation_level`, `priority_id`, `assigned_to`, `escalated_at` are **all** unchanged. **Delete `DB::transaction` and confirm this fails.**
19. `test_a_reason_is_stored_verbatim` — `<script>alert(1)</script>` and Arabic, in **both** `tickets.escalation_reason` and `meta.reason`, byte-identical.
20. `test_escalation_changes_no_status_or_lifecycle_timestamp` — **the scope fence.** `status_id`, `resolved_at`, `closed_at` and `first_responded_at` are all unchanged after an escalation. **Never delete this test**; escalation must not become a transition.
21. `test_the_response_omits_can` — `assertJsonMissingPath('data.can')` while `data.priority`, `data.assignee`, `data.escalation_level` and `data.escalated_by` are present.
22. `test_a_nonexistent_and_a_soft_deleted_ticket_are_404` — id `999999` as agent and as admin; and a trashed ticket. `Auth::forgetGuards()` between roles.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

23. `test_every_api_route_is_classified` and `test_every_classified_route_exists` cover `tickets.escalate` automatically once `ACCESS` has the key. **No new test.**

### Frontend — `frontend/src/components/TicketEscalateDialog.spec.ts` (new)

`vi.mock('../api/tickets')`, `createPinia()`, following `views/HealthView.spec.ts:1–23`.

24. Confirm is disabled while the reason is under 10 characters, with `ticket-escalate-hint` visible, and enabled at 10.
25. Confirming calls `escalateTicket(ticket.id, reason)` **and then `getTicket(ticket.id)`**, in that order, and emits `escalated`.
26. A `422` under **`status`** renders its message in `ticket-escalate-error`; so does one under **`assigned_to`**; so does one under **`reason`**. **Three cases, because this dialog is the only one reading three keys** — and a non-`422` falls back to `errorMessage()`. The dialog stays mounted in all four.
27. `ticket-escalate-level` shows the current level when it is above zero and is absent at zero.
28. `ticket-escalate-cancel` emits `close` and issues **no** request.

### Frontend — `frontend/src/components/TicketActionToolbar.spec.ts` (Story 32's file; modified)

29. The Escalate button is **no longer disabled** and no longer carries its title, and clicking it emits `escalate`. **Assert that none of the four buttons carries a `title` placeholder any more** — this story is the last one, and that assertion is how the next reader knows Story 22's contract is fully discharged.

### Frontend — `frontend/src/views/TicketDetailView.spec.ts` (Story 32's file; modified)

30. Clicking `action-escalate` mounts `ticket-escalate-dialog`; `close` unmounts it; absent on first render.
31. A ticket with `escalation_level: 2` renders `ticket-escalation` and `ticket-escalation-level` reading **"Escalated to level 2"**; one at `0` renders neither.
32. An `escalation_reason` of `<script>alert(1)</script>` renders as **literal text** — `.text()` contains it, `.html()` has no live `<script>`.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
2. **Backend formats:** from `backend/`, `./vendor/bin/pint --test` → exit `0`.
3. **Backend tests:** `composer test`. Expect **+22 tests** over whatever baseline you measured, and **no new failures**.
4. **Prove the layer move kept both halves:** revert `TicketPolicy::escalate()` to `! $ticket->status->is_terminal`, re-run `--filter=test_a_terminal_ticket_returns_422_not_403`, confirm it fails with a **`403`**, restore. Then revert task 3's `&& ! $this->status->is_terminal`, re-run `--filter=test_can_escalate_is_false_on_a_terminal_ticket`, confirm it fails, restore. **Both reverts must fail a test, or one half of the check was lost.**
5. **Prove the tie-break earns its place:** remove `->orderBy('id')` from `escalationTarget()`, re-run `--filter=test_the_least_loaded_active_admin_receives_it` several times, confirm it is no longer reliable, restore.
6. **Prove test 15 earns its place:** move `$fromPriority = $ticket->priority;` below `$ticket->save()`, re-run `--filter=test_the_escalated_row_captures_the_reason_and_the_level`, confirm `meta.from_priority` equals the new name, restore.
7. **Prove test 18 earns its place:** delete `DB::transaction(` and its closing, re-run `--filter=test_escalation_is_atomic`, confirm it fails, restore.
8. **Backend by hand.** `php artisan serve`. Create a second admin first. With an **agent** token on an Open, Medium, agent-assigned ticket:
   - `POST …/tickets/<id>/escalate -d '{"reason":"short"}'` → `422` `min`.
   - `-d '{"reason":"Needs vendor access I do not have."}'` → `200`.
   - `SELECT escalation_level, escalated_at, escalated_by, escalation_reason, priority_id, assigned_to, status_id FROM tickets WHERE id=<id>` → level **1**, all three stamps set, priority is **High**, `assigned_to` is an **admin**, `status_id` **unchanged**.
   - `SELECT event, field, old_value, new_value, meta FROM ticket_activities WHERE ticket_id=<id> ORDER BY id DESC LIMIT 2` → an `assigned` row (`field='assigned_to'`, both ids, both names) and an `escalated` row (`field='escalation_level'`, `0`→`1`, reason, both priority names).
   - Escalate again → level **2**, priority **Urgent**, `escalation_reason` is the newer text, and the first reason is **still** in `ticket_activities`.
   - Escalate a third time → level **3**, priority **still Urgent**, `200`, no error.
   - Resolve the ticket, then escalate → `422` naming the status. `GET …/tickets/<id>` → `data.can.escalate` is `false`.
   - Deactivate every admin, escalate an Open ticket → `422` about the administrator, and `escalation_level` unchanged. Reactivate.
   - `-d '{"reason":"valid enough here","priority_id":4}'` → `422`.
   - **Concurrency:** two terminals, same ticket, same instant → level goes to **2**, not 1 twice, and there are two `escalated` rows.
9. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+9 tests**. Then `npx prettier --check src/api/tickets.ts src/stores/tickets.ts src/components/TicketEscalateDialog.vue src/components/TicketActionToolbar.vue src/views/TicketDetailView.vue src/components/TicketEscalateDialog.spec.ts`.
10. **Frontend by hand:** `npm run dev`, as an **agent**.
    - Open an Open ticket → **Escalate is enabled**, and **none of the four toolbar buttons shows a placeholder tooltip any more.**
    - Click it → the dialog opens with no level line (level 0). Type `help` → Confirm disabled, hint visible. Type a sentence → enabled.
    - Confirm → the dialog closes; the **priority badge moves up**, the **assignee line shows an admin**, and the **escalation section appears** reading "Escalated to level 1" with the reason — **all without a reload.**
    - Re-open the dialog → it now reads "Currently level 1".
    - Escalate until Urgent, then once more → **succeeds**, level rises, priority stays Urgent.
    - Resolve the ticket → the **Escalate button disappears**.
    - Paste `<script>alert(1)</script>` as a reason on another ticket → rendered as **literal text**, no dialog fires.
11. **Regression:** confirm `git status` shows **no change** to `TicketWorkflow.php`, `TicketTimestamps.php`, `StatusTransitionSeeder.php`, `PrioritySeeder.php`, `StatusSeeder.php` or any migration, and that `TicketPolicy::assign()` and `changeStatus()` are untouched. Then file a ticket from `/tickets/new` and run one ordinary status change to confirm neither path regressed.

---

## Done Criteria

- [ ] `POST /api/v1/tickets/{ticket}/escalate` returns `200` for **any** staff member, is classified `staff`, and carries no `admin` middleware.
- [ ] `escalation_level` increments and `escalated_at`, `escalated_by`, `escalation_reason` are stamped in the **same statement and transaction**, under `lockForUpdate()` — proven by a test that fails when the transaction is removed.
- [ ] The priority rises **exactly one level** and **stops at Urgent** with no error, no overflow and no extra row.
- [ ] The ticket is routed to the **active admin with the fewest open tickets** — "open" meaning `is_terminal = false`, soft-deleted tickets excluded — ties broken by lowest id, proven by a test that becomes unreliable when the tie-break is removed.
- [ ] A ticket already held by an **active admin** is not reassigned, including when that admin is the caller.
- [ ] **No active admin is a `422` and nothing is written**, not a silent unassign.
- [ ] One `escalated` row records both levels, the reason and both priority names; a companion `assigned` row in **Story 26's exact shape** is written **only** when the assignment moved, with `old_value` `null` rather than `"null"` on a first assignment.
- [ ] `TicketActivityEvent::Escalated` is appended, and `Assigned` only if Story 26 had not added it — **no second assignment event, and no `priority_changed` event invented ahead of TM-27.**
- [ ] A **terminal** ticket returns **`422`**, checked **under the lock**, and `can.escalate` is `false` for it — **both halves proven by reverting each and watching a different test fail.**
- [ ] `TicketPolicy::escalate()` is a pure role gate; `assign()` and `changeStatus()` are untouched; the PR records that Story 22's check moved layer rather than being dropped.
- [ ] A missing, whitespace-only or short `reason` returns `422` (`required` for whitespace, not `min`); `priority_id` and `assigned_to` in the body return `422` naming the rule.
- [ ] Escalation changes **no** `status_id`, `resolved_at`, `closed_at` or `first_responded_at` — pinned by a test that must never be deleted.
- [ ] A second escalation overwrites `escalation_reason` while the trail keeps **every** reason — asserted, so the column-vs-trail split is deliberate rather than discovered.
- [ ] `User::assignedTickets()` exists (added here or already by TM-35, never twice); `tickets.show`'s query count is **unchanged**.
- [ ] The Escalate button is enabled and opens a dialog whose error element reads `reason`, `status` **and** `assigned_to`; the dialog does not name the target admin; the detail page shows "Escalated to level N" and updates with **no reload**.
- [ ] **All four of Story 22's toolbar buttons are now live** — none carries a `title` placeholder — and a test asserts it.
- [ ] `docs/api-contract.md` documents the endpoint, the priority cap, the routing rule, the two activity rows, the `422`-not-`403` decision and the column-vs-trail split.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+22 backend and +9 frontend tests**; no new failures.
- [ ] No escalated filter, badge, dashboard count or sort (**TM-42**); no email (**TM-53**); no auto-escalation (**TM-43**); no de-escalation; no new dependency; no migration.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 36 (TM-42, escalated tickets are visible at a glance).**
