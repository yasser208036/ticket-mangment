# Story 58 — Agents request assignment instead of claiming

## Prerequisites

- **Stories 56 and 57 implemented.** This story removes the endpoint Story 56 deliberately left in place, and it adds screens on top of the role-aware SPA Story 57 builds. **Gate: `cd backend && composer test` and `cd frontend && npm run test:unit` both green before you start.**
- **Story 27 (TM-32) built self-claim and this story retires it.** Read [`../assignment-workload/27-story-unassigned-queue-with-self-claim-TM-32.md`](../assignment-workload/27-story-unassigned-queue-with-self-claim-TM-32.md) before deleting anything — its conflict-response design (`409` with the winning holder's name) is the concurrency case this story must still handle, in a different shape.
- **Story 26 (TM-31) owns the assign endpoint and it is unchanged.** Approving a request calls the same assignment path; it does **not** introduce a second way to write `tickets.assigned_to`.
- **One migration.** `ticket_activities.event` is `varchar(50)`, so the two new activity events need no schema change.
- **The `claimed` activity event stays in the enum.** The trail is append-only and historical `claimed` rows exist. **Remove the endpoint, keep the case.**
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An agent can no longer take a ticket. They ask, and an admin decides.

1. `POST /api/v1/tickets/{ticket}/assignment-requests` lets an agent ask for an **unassigned** ticket.
2. `GET /api/v1/admin/assignment-requests` gives an admin the pending queue.
3. Approving assigns the ticket to the requester and writes an ordinary `assigned` activity row; declining records the refusal and leaves the ticket alone.
4. `POST /api/v1/tickets/{ticket}/claim` and `TicketPolicy::claim()` are **gone**, along with `can.claim` and the SPA's claim call.
5. The ticket detail page shows an agent a "Request this ticket" button, and shows them the state of a request they already made.

**Not in scope.** No notification emails — the four existing notifications are untouched and an admin learns of a request from the queue screen, not their inbox. Adding one is a follow-up story and should be recorded as such in the PR. No bulk approve. No expiry or auto-decline of stale requests. No request for an **assigned** ticket (a reassignment ask is a different feature).

---

## Context — Read These Files First

1. `backend/app/Http/Controllers/Api/V1/TicketController.php` — `claim()` at **274–284**, `claimTicket()` at **286–297**, `claimConflict()` at **299–307**. **All three are deleted.** Read `claimTicket()` first: its `whereNull('assigned_to')->update(...)` is a compare-and-swap, and task 5's `approve()` needs the same trick for the same reason.
2. `backend/app/Http/Controllers/Api/V1/TicketController.php:232–272` — `assign()`, `changeAssignee()`, `recordAssignment()`, `userName()`. **`changeAssignee()` and `recordAssignment()` are what `approve()` reuses.** Do not write a second assignment writer.
3. `backend/app/Data/AssignmentChange.php` — the readonly DTO `changeAssignee()` takes. `approve()` constructs one of these.
4. `backend/app/Services/ActivityRecorder.php:13–19` — `record()` fills five defaults; the `LogicException` at **26–28** fires outside a transaction.
5. `backend/app/Policies/TicketPolicy.php` — after Story 56, `claim()` reads `$user->isAgent() && $ticket->assigned_to === null`. **That predicate becomes `requestAssignment()`**; the method is renamed, not rewritten.
6. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php:98–124` — the shape for an admin action that locks a row, branches, and returns either a `204` or a structured `422`. `approve()` follows it.
7. `backend/routes/api.php:55` — the claim route. **Line 60–70** is the `admin`-prefixed group the two admin routes join.
8. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php:17` — `'tickets.claim' => 'agent-policy'` and `test_admin_refused_by_agent_policy_routes` (**55–63**). The level survives; only the route name behind it changes.
9. `backend/tests/Feature/Activity/ActivityCoverageTest.php:20–33` — `PRODUCERS` maps every `TicketActivityEvent` case to a test class. **Two new cases mean two new rows, and the test fails until they exist.**
10. `backend/tests/Feature/Security/RateLimitCoverageTest.php:14–30` — every `POST`/`PATCH`/`DELETE` under `api/v1` must carry a `throttle:` middleware. All three new write routes do.
11. `backend/tests/Feature/Tickets/ClaimTicketTest.php` — **deleted** by this story, but read it first: its concurrency test is the model for task 5's.
12. `frontend/src/api/tickets.ts` — `TicketPermissions.claim` at **20**, `claimTicket()` at **110–112**. `frontend/src/stores/tickets.ts` — `claiming` at **59**, `claim()` at **110–118**, both exported at **300–301**. **No component calls any of it** (the My Tickets page that did was removed in TM-33), so the SPA deletion is clean.
13. `frontend/src/components/TicketActionToolbar.vue:36–38` — the Assign button and its `emit`. The Request button is the same shape.
14. `docs/api-contract.md` — `### POST /api/v1/tickets/{ticket}/claim` at **271–286**, replaced wholesale. `ApiContractCoverageTest` fails on a documented row that no longer routes, so **the deletion and the three additions land together**.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Agent wants an unassigned ticket | `POST /claim`, instant | `POST /assignment-requests`, `201`, status `pending` |
| Agent wants an already-assigned ticket | `409` naming the holder | **`422`** — only unassigned tickets can be requested |
| Agent requests the same ticket twice | — | `422`, one pending request per agent per ticket |
| Two agents request the same ticket | Loser gets `409` | **Both succeed.** The admin picks |
| Admin approves | — | Ticket assigned, request `approved`, others on that ticket auto-`declined` |
| Admin approves a ticket assigned meanwhile | — | `422` — the ticket is no longer unassigned |
| Admin declines | — | Request `declined` with an optional note; ticket untouched |
| Admin or end user calls the request endpoint | — | `403` |
| `POST /tickets/{ticket}/claim` | `200` | **`404`** — route removed |

---

## Decision — approval reuses `changeAssignee()`, and the trail says `assigned`

**Do not invent an `assignment_approved` activity event for the assignment itself.**

`ticket_activities` is queried by `field = 'assigned_to'` to reconstruct ownership history — `Admin\UserController::reassignTickets()` (**179–186**) and `TicketController::escalate()` (**173–181**) both take care to write assignment as a row in that exact shape, and the escalate comment at **169–172** says why. An approval that wrote a differently-named row would be invisible to every consumer of assignment history.

**So:** approving writes the ordinary `assigned` row through `recordAssignment()`, with `meta.reason = 'assignment_request'` and `meta.request_id` carrying the request. The two **new** events cover only what the ticket's own fields cannot express: `assignment_requested` and `assignment_request_declined`.

---

## Backend Tasks

### 1 — The table

**Create file: `backend/database/migrations/2026_08_29_110000_create_ticket_assignment_requests_table.php`**

```php
Schema::create('ticket_assignment_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->enum('status', AssignmentRequestStatus::values())->default(AssignmentRequestStatus::Pending);
    $table->string('note', 500)->nullable();
    $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('decided_at')->nullable();
    $table->string('decision_note', 500)->nullable();
    $table->timestamps();
    $table->index(['status', 'created_at']);
    $table->index(['ticket_id', 'status']);
});
```

- **`cascadeOnDelete` on both foreign keys, deliberately, and it is the one place this repo cascades.** A request is a transient intention, not an audit record — the audit record is the `assignment_requested` activity row, which survives because `ticket_activities.user_id` is `nullOnDelete`. `tickets.created_by` is `RESTRICT` precisely because tickets are the durable thing; requests are not, so `Admin\UserController::destroy()` needs **no new branch** for them.
- **No partial unique index** — MySQL 8 has none. Uniqueness of "one pending request per agent per ticket" is enforced in the form request (task 4) under the row lock task 5 takes.
- `utf8mb4` is the server default (`docker-compose.yml`), so `note` accepts Arabic and emoji with no extra clause.

### 2 — Enum and model

**Create file: `backend/app/Enums/AssignmentRequestStatus.php`** — cases `Pending = 'pending'`, `Approved = 'approved'`, `Declined = 'declined'`, plus the `values(): array` helper, matching `UserRole`'s shape exactly (`backend/app/Enums/UserRole.php:9–13`).

**Create file: `backend/app/Models/TicketAssignmentRequest.php`**

```php
#[Fillable(['ticket_id', 'user_id', 'note'])]
#[UsePolicy(AssignmentRequestPolicy::class)]
class TicketAssignmentRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['status' => AssignmentRequestStatus::class, 'decided_at' => 'datetime'];
    }

    public function ticket(): BelongsTo { ... }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function decidedBy(): BelongsTo { return $this->belongsTo(User::class, 'decided_by'); }

    /** @param Builder<TicketAssignmentRequest> $query */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AssignmentRequestStatus::Pending);
    }
}
```

**`decided_by` and `status` are absent from `#[Fillable]` on purpose** — only the controller sets them, the same way `Admin\UserController::store()` assigns `role` outside mass assignment (**UserController.php:63**). `ModelFillableCoverageTest` requires the attribute to exist, which it does.

Add to `backend/app/Models/Ticket.php`: `public function assignmentRequests(): HasMany`.

### 3 — Two activity events

**File: `backend/app/Enums/TicketActivityEvent.php`** — append after `Stale`:

```php
case AssignmentRequested = 'assignment_requested';
case AssignmentRequestDeclined = 'assignment_request_declined';
```

Append only; the existing strings are already in rows.

**File: `frontend/src/lib/activityEvents.ts`** and **`activityProse.ts`** — every case needs a label and a prose form, beside the existing `claimed` entry at `activityEvents.ts:23`. Their spec files enumerate the map; both fail until the two cases are added.

### 4 — Requesting

**Create file: `backend/app/Policies/AssignmentRequestPolicy.php`**

```php
class AssignmentRequestPolicy
{
    /** Only an admin reviews. */
    public function viewAny(User $user): bool { return $user->isAdmin(); }
    public function decide(User $user, TicketAssignmentRequest $request): bool { return $user->isAdmin(); }
}
```

**File: `backend/app/Policies/TicketPolicy.php`** — rename `claim()` to `requestAssignment()`, keeping Story 56's body verbatim:

```php
/** An agent may ask for a ticket nobody holds. Admins assign directly; end users file. */
public function requestAssignment(User $user, Ticket $ticket): bool
{
    return $user->isAgent() && $ticket->assigned_to === null;
}
```

**Create file: `backend/app/Http/Requests/Api/V1/StoreAssignmentRequestRequest.php`**

```php
public function authorize(): bool
{
    return Gate::allows('requestAssignment', $this->route('ticket'));
}

public function rules(): array
{
    return ['note' => ['sometimes', 'nullable', 'string', 'max:500']];
}

public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $validator): void {
        $exists = TicketAssignmentRequest::query()->pending()
            ->where('ticket_id', $this->route('ticket')->getKey())
            ->where('user_id', $this->user()->getKey())->exists();
        if ($exists) {
            $validator->errors()->add('ticket', 'You have already asked for this ticket. An administrator is reviewing it.');
        }
    });
}
```

**The policy returns `false` for an assigned ticket, which is a `403`, and the product rule above says `422`.** Resolve it the way `TicketController::escalate()` already does (see its comment at **139–145**): the policy stays a pure role gate — drop `&& $ticket->assigned_to === null` from `requestAssignment()` — and the "is it unassigned" half becomes a second `$validator->after()` clause returning `422` under `errors.ticket`. **Do it this way**; the repo has one precedent and this must match it.

**Create file: `backend/app/Http/Controllers/Api/V1/AssignmentRequestController.php`** — a `store()` that opens a transaction, `lockForUpdate()`s the ticket, re-checks `assigned_to === null`, creates the row, and records `AssignmentRequested` with `meta.note`. Returns `201` with an `AssignmentRequestResource`.

### 5 — Reviewing

**Create file: `backend/app/Http/Controllers/Api/V1/Admin/AssignmentRequestController.php`**

- `index()` — `$this->authorize('viewAny', TicketAssignmentRequest::class)`, then pending requests with `['ticket.status', 'ticket.priority', 'requester']`, `orderBy('created_at')`, paginated. Accept `?status=` validated against `Rule::enum(AssignmentRequestStatus::class)` so an admin can review history, defaulting to pending.
- `approve()` — the whole story in one transaction:

```php
DB::transaction(function () use ($request, $assignmentRequest, $recorder) {
    $locked = TicketAssignmentRequest::query()->whereKey($assignmentRequest->getKey())->lockForUpdate()->firstOrFail();
    if ($locked->status !== AssignmentRequestStatus::Pending) {
        throw ValidationException::withMessages(['status' => ['This request has already been decided.']]);
    }
    $ticket = Ticket::query()->whereKey($locked->ticket_id)->lockForUpdate()->firstOrFail();
    if ($ticket->assigned_to !== null) {
        throw ValidationException::withMessages(['ticket' => ['This ticket already has an assignee. Decline the request or reassign the ticket directly.']]);
    }
    // The single assignment writer. Story 26 owns it; this story calls it.
    $change = new AssignmentChange($actorId, $locked->user_id, 'assignment_request');
    $this->changeAssignee($ticket, $recorder, $change);
    $locked->forceFill(['status' => AssignmentRequestStatus::Approved, 'decided_by' => $actorId, 'decided_at' => now()])->save();
    // Everyone else who asked for this ticket loses, and is told so.
    $this->declineOthers($locked, $actorId, $recorder);
});
```

`changeAssignee()` and `recordAssignment()` are `private` on `TicketController` today (**244–267**). **Extract both into a `TicketAssignment` service** in `backend/app/Services/`, and have `TicketController::assign()` call it too — copying them is how two assignment writers appear, which the Decision above forbids. `AssignmentChange` already carries the `reason` that reaches `meta`.

- `decline()` — same lock and same already-decided guard; sets `Declined`, `decision_note` from the request, records `AssignmentRequestDeclined` with `meta.reason`. Ticket untouched.
- `declineOthers()` — every other pending request on that ticket becomes `Declined` with `decision_note` "Another agent was assigned.", one `AssignmentRequestDeclined` row each via `recorder->recordMany()`.
- Dispatch `TicketAssigned` **after** the transaction returns, matching `assign()` (**236–239**) and the after-commit comment at **359–363**.

### 6 — Routes, and the one that goes

**File: `backend/routes/api.php`**

Delete **line 55** (`tickets.claim`). Add beside it:

```php
Route::post('/tickets/{ticket}/assignment-requests', [AssignmentRequestController::class, 'store'])->middleware('throttle:write')->name('tickets.assignment-requests.store');
```

Inside the `admin` group (**60–70**):

```php
Route::get('/assignment-requests', [Admin\AssignmentRequestController::class, 'index'])->name('assignment-requests.index');
Route::post('/assignment-requests/{assignmentRequest}/approve', [Admin\AssignmentRequestController::class, 'approve'])->middleware('throttle:write')->name('assignment-requests.approve');
Route::post('/assignment-requests/{assignmentRequest}/decline', [Admin\AssignmentRequestController::class, 'decline'])->middleware('throttle:write')->name('assignment-requests.decline');
```

All three writes carry `throttle:write`, so `RateLimitCoverageTest` (**14–30**) stays green.

### 7 — Delete the claim path

- `TicketController` — `claim()`, `claimTicket()`, `claimConflict()` (**274–307**) and the now-unused `Illuminate\Http\Request` import if nothing else needs it.
- `TicketResource:32` — `'claim' => …` becomes `'request_assignment' => $request->user()->can('requestAssignment', $this->resource) && $this->assigned_to === null`. **Keep the `assigned_to === null` half in the resource** even though the policy no longer carries it — that is exactly the split `escalate` uses on the same line.
- `backend/tests/Feature/Tickets/ClaimTicketTest.php` — delete.
- `RouteAuthorizationTest::ACCESS` — `'tickets.claim'` out; `'tickets.assignment-requests.store' => 'agent-policy'`, `'admin.assignment-requests.index'`/`.approve`/`.decline` → `'admin'`.
- `ActivityCoverageTest::PRODUCERS` — keep `'claimed'` mapped, but repoint it: its producing test is gone. Map it to the new `Tests\Feature\Tickets\AssignmentRequestTest` and add a comment that `claimed` is **historical only**, written by no live code path. Add the two new cases pointing at the same class.
- **`ActivityCoverageTest::test_every_stored_event_round_trips_through_the_enum` (49–72) still writes a `claimed` row directly through the recorder**, so the case must stay in the enum. Verified by reading it.

### 8 — Documentation

- `docs/api-contract.md` — delete `### POST /api/v1/tickets/{ticket}/claim` (**271–286**) and its table row (**line 99**); add four rows and three subsections. Document the `422` bodies exactly.
- `docs/ticket-lifecycle.md` — a section on the request lifecycle beside escalation, and the two new activity events in its event list.
- `CLAUDE.md` — the *Authentication and authorization* paragraph states `TicketPolicy::claim()` allows non-admins to self-claim. **Replace it**; note that the only ways a ticket gains an assignee are now `/assign`, creation-time choice (Story 56), escalation, and an approved request.

---

## Frontend Tasks

### 9 — API and store

**Create file: `frontend/src/api/assignmentRequests.ts`** — `AssignmentRequest` interface, `requestAssignment(ticketId, note?)`, `listAssignmentRequests(query)`, `approveAssignmentRequest(id)`, `declineAssignmentRequest(id, note?)`.

**Create file: `frontend/src/stores/assignmentRequests.ts`** — mirrors the backend concern 1:1 the way `workload` does: `items`, `loading`, `error`, `load()`, `approve(id)`, `decline(id, note)`. Approving refreshes the list.

**File: `frontend/src/api/tickets.ts`** — `TicketPermissions.claim` (**20**) → `request_assignment`; delete `claimTicket()` (**110–112**).
**File: `frontend/src/stores/tickets.ts`** — delete `claiming` (**59**), `claim()` (**110–118**) and both exports (**300–301**); read the comment at **99** before deleting, then delete it too.

### 10 — The agent's button

**File: `frontend/src/components/TicketActionToolbar.vue`** — a Request button, `v-if="ticket.can.request_assignment"`, `data-testid="action-request-assignment"`, emitting `request-assignment`. Same shape as the Assign button (**36–38**).

**Create file: `frontend/src/components/TicketRequestAssignmentDialog.vue`** — an optional note textarea (max 500) and confirm/cancel, following `TicketEscalateDialog.vue`'s structure exactly.

**File: `frontend/src/views/TicketDetailView.vue`** — mount the dialog and, when the loaded ticket carries a pending request by the current user, render a passive banner: "You asked for this ticket. An administrator is reviewing it." The detail payload must carry that flag — add `my_pending_assignment_request` to `TicketResource`'s `tickets.show` block beside `can` (**line 32**) rather than making the SPA fetch a second endpoint.

### 11 — The admin's queue

**Create file: `frontend/src/views/AdminAssignmentRequestsView.vue`** — a table of pending requests: ticket reference and subject (linking to the detail page), requesting agent, note, age via `lib/relativeTime.ts`, and Approve / Decline buttons. Follow `AdminWorkloadView.vue` for layout and loading/empty states.

**File: `frontend/src/router/index.ts`** — `/admin/assignment-requests`, `meta: { roles: ['admin'] }` (Story 57's shape).
**File: `frontend/src/App.vue`** — a nav link inside the existing `v-if="auth.isAdmin"` block, `data-testid="nav-assignment-requests"`, **in both the desktop nav and the mobile drawer**.

---

## Edge Cases & Failure Modes

- **Two agents request the same ticket; the admin approves one.** The other's request flips to `declined` with a decision note inside the same transaction (`declineOthers()`), so no pending request ever points at an assigned ticket.
- **The ticket is assigned by `/assign` while a request is pending.** `approve()` re-reads under `lockForUpdate()` and returns `422`. **The stale request is left pending** — the admin decides whether to decline it. Do not auto-decline on the `/assign` path; that couples two features for no benefit.
- **The ticket is soft-deleted while a request is pending.** Route-model binding on the request still resolves, then `Ticket::query()->whereKey(...)` inside `approve()` misses the soft-deleted row and `firstOrFail()` gives `404`. Acceptable; assert it so it is not mistaken for a bug later.
- **The requesting agent is deleted.** `user_id` is `cascadeOnDelete`, so their pending requests vanish and `Admin\UserController::destroy()`'s ticket-reassignment flow is unaffected. The `assignment_requested` activity row survives with `user_id` nulled.
- **The requesting agent is deactivated, then approved.** Nothing stops it — `changeAssignee()` performs no role or active check (`AssignTicketRequest` does, and it is not in this path). **Add an explicit guard in `approve()`**: if the requester is no longer an active agent, `422` naming them. This is the one real gap the reuse creates; do not skip it.
- **Approving an already-approved request** (double-click, retry) → `422` "already been decided", from the status check under the lock. Not a `409`; the repo uses `409` only for the claim race that this story removes.
- **A `note` of 500 multibyte characters.** `string('note', 500)` is 500 *characters* on utf8mb4, and `max:500` in Laravel counts characters for strings. They agree; no truncation.
- **An agent's detail page after their request is declined.** `can.request_assignment` is `true` again (the ticket is still unassigned) so they may re-ask. Deliberate — a decline is not a ban.
- **`ActivityCoverageTest` and the orphaned `claimed` case.** It stays in the enum with no producer in application code. Task 7's comment is the only thing preventing a future reader from "cleaning it up" and breaking the round-trip test at **49–72**.

---

## Test Plan

### Backend — `tests/Feature/Tickets/AssignmentRequestTest.php` (new; `RefreshDatabase` + `$this->seed()`)

1. Agent requests an unassigned ticket → `201`, one `pending` row, one `assignment_requested` activity row carrying the note.
2. Agent requests an **assigned** ticket → `422` under `errors.ticket`, no row.
3. Same agent requests twice → `422`, still exactly one row.
4. Two different agents request the same ticket → both `201`, two rows.
5. Admin and end user calling the endpoint → `403`.
6. `POST /api/v1/tickets/{id}/claim` → `404`.
7. A `note` over 500 characters → `422`.

### Backend — `tests/Feature/Admin/AssignmentRequestReviewTest.php` (new)

8. `GET /admin/assignment-requests` lists pending oldest-first with ticket and requester embedded; agent and end user get `403`.
9. `?status=declined` returns declined only.
10. Approve → ticket `assigned_to` set, request `approved` with `decided_by`/`decided_at`, one `assigned` activity row with `meta.reason = 'assignment_request'`, and `TicketAssigned` dispatched (`Event::fake()`).
11. Approve with a second pending request on the same ticket → the other becomes `declined` with a decision note and its own `assignment_request_declined` row.
12. Approve when the ticket gained an assignee meanwhile → `422`, request still `pending`, `assigned_to` unchanged.
13. Approve when the requesting agent has been deactivated → `422` naming them.
14. Approve twice → second is `422` "already been decided".
15. Decline → `declined`, note stored, `assignment_request_declined` row, `assigned_to` still `null`, **no** `TicketAssigned` dispatched.
16. A soft-deleted ticket's request → `404` on approve.

### Backend — modified

17. `RouteAuthorizationTest` — the `ACCESS` changes from task 7; the three admin routes must fail `test_agent_refused_by_admin_routes`.
18. `ActivityCoverageTest` — passes with the repointed `claimed` producer and the two new cases.
19. `ApiContractCoverageTest` — passes only once the claim row is deleted and four rows added.
20. `RateLimitCoverageTest` — unchanged and green.
21. `tests/Feature/Tickets/AssignTicketTest.php` — still green after `changeAssignee()` moves into `TicketAssignment`. **This is the regression that proves the extraction was behaviour-preserving.**
22. Delete `tests/Feature/Tickets/ClaimTicketTest.php`.

### Frontend

23. `src/api/assignmentRequests.spec.ts` (new) — each function's URL, payload and unwrapping.
24. `src/stores/assignmentRequests.spec.ts` (new) — `load` populates, `approve` refreshes, a rejection sets `error` and clears `loading`.
25. `src/components/TicketRequestAssignmentDialog.spec.ts` (new) — confirm emits with the note; cancel emits close; over-500 characters blocks submit.
26. `src/components/TicketActionToolbar.spec.ts` (modified) — the button renders on `can.request_assignment` and not otherwise.
27. `src/views/TicketDetailView.spec.ts` (modified) — the pending banner renders from `my_pending_assignment_request` and the dialog opens from the toolbar event.
28. `src/views/AdminAssignmentRequestsView.spec.ts` (new) — rows render; Approve and Decline call the store; empty and error states.
29. `src/App.spec.ts` (modified) — `nav-assignment-requests` present for an admin, absent for an agent and an end user, counted across both nav and drawer.
30. `src/lib/activityEvents.spec.ts` and `activityProse.spec.ts` (modified) — the two new events have a label and prose.

---

## Migration / Rollback

- `php artisan migrate` creates one table. No existing table is altered and no data moves.
- **Half-applied state:** table without code is inert. Code without table is a `QueryException` the first time an agent asks. **Migrate first.**
- **Rollback** drops the table and loses pending requests; the `assignment_requested` activity rows survive in the trail, so the history of who asked is not lost. Restoring `/claim` means reverting the code, not the data.
- **Deploy ordering with the SPA:** the API removes `/claim` and the SPA stops calling it in the same story. Ship backend first — an SPA that still calls `/claim` gets a `404` from a code path no component reaches.

---

## Verification Steps

1. **Services up:** `docker compose up -d --wait`; `tm-mysql-test` healthy on 3307.
2. **Backend migrates:** from `backend/` — `php artisan migrate`, then `php artisan migrate:fresh --seed`.
3. **Backend tests:** `composer test`, then `php artisan test --filter='AssignmentRequest|AssignTicketTest|RouteAuthorizationTest|ActivityCoverageTest|ApiContractCoverageTest|RateLimitCoverageTest'`.
4. **Formatting:** `./vendor/bin/pint --test` from `backend/`.
5. **Frontend:** from `frontend/` — `npx vue-tsc -b`, `npm run test:unit`, `npm run lint`, `npm run format:check`, `npm run build`.
6. **By hand:** with `composer dev` and `npm run dev` running, and a demo database — as an agent, open an unassigned ticket, request it, confirm the banner; as an admin, open Assignment requests, approve it, confirm the ticket's assignee and its timeline row; repeat with two agents on one ticket and confirm the loser is declined.
7. **Regression:** `php artisan test --filter='Notifications'` — untouched and green.

---

## Done Criteria

- [ ] `ticket_assignment_requests` exists with both foreign keys cascading and both indexes.
- [ ] An agent can request an **unassigned** ticket once; a second ask, an assigned ticket, an admin caller and an end-user caller are all refused with the documented status.
- [ ] Two agents may hold pending requests on the same ticket.
- [ ] Approving assigns the ticket through the **single** assignment writer, writes an ordinary `assigned` activity row with `meta.reason = 'assignment_request'`, dispatches `TicketAssigned`, and declines every other pending request on that ticket.
- [ ] Approving is refused with `422` when the ticket gained an assignee, when the request was already decided, or when the requesting agent is no longer an active agent.
- [ ] Declining records the decision and leaves the ticket unassigned with no notification.
- [ ] `POST /api/v1/tickets/{ticket}/claim` returns `404`; `TicketPolicy::claim()`, `TicketController::claim()`, `can.claim`, `claimTicket()` and the store's `claim()` are all gone.
- [ ] The `claimed` activity case remains in the enum with a comment explaining that it is historical.
- [ ] `TicketAssignment` is the only code path that writes `tickets.assigned_to` outside escalation and creation, and `AssignTicketTest` proves the extraction changed nothing.
- [ ] The SPA shows agents a Request button and a pending banner, and admins a review queue linked from the nav in both layouts.
- [ ] `docs/api-contract.md`, `docs/ticket-lifecycle.md` and `CLAUDE.md` describe the request flow and no longer describe self-claim.
- [ ] `composer test`, `./vendor/bin/pint --test`, `npm run test:unit`, `npm run lint` and `npm run build` all pass.
