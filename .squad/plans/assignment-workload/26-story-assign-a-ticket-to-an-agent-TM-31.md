# Story 26 — Assign a ticket to an agent (Story: TM-31)

## Prerequisites

- **Story 22 (TM-26) — LANDING NOW, and this story's hard gate.** As of writing, its backend half is in the working tree: `TicketPolicy::assign()` already returns `$user->isAdmin()` (`backend/app/Policies/TicketPolicy.php:18`), the `can` block is on the show route (`backend/app/Http/Resources/V1/TicketResource.php:28`), `tickets.show` is routed (`backend/routes/api.php:45`), and the SPA has `TicketDetail`, `getTicket()`, `loadTicket()`, `TicketDetailView.vue` and `TicketActionToolbar.vue`. **Do not re-add any of them.** Its test file `tests/Feature/Tickets/TicketShowTest.php` does not exist yet. **Gate: `php artisan test --filter='TicketShowTest|RouteAuthorizationTest'` green before you start.**
- **Story 19 (TM-23) — PLANNED, NOT IMPLEMENTED, and it owns the two factories this story's tests need.** `backend/database/factories/` contains **only** `UserFactory.php`; there is no `TicketFactory` or `RequesterFactory`, and `backend/tests/Feature/Tickets/` does not exist. Task 8 below creates them **verbatim from Story 19's task 6** if they are still absent — see [`../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md`](../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md). If Story 19 has landed first, **skip task 8 entirely**; do not create a second variant.
- **Story 20 (TM-24) handed this story a decision.** Its task 11 says: *"TM-31 (assignment) genuinely needs one … so that story should own introducing `GET /api/v1/staff` or relaxing `UserPolicy::viewAny`."* **The decision is made below in "Decision — no `/staff` endpoint", and the answer is neither.** Read that section before writing any frontend code.
- **Stories 23 (TM-27), 24 (TM-28) and 25 (TM-29) — PLANNED, NOT IMPLEMENTED.** By `NN` order they land before this one. Nothing here depends on them, but three of them touch the same three files (`TicketController`, `TicketActivityEvent`, `TicketActionToolbar.vue`), so **rebase before you start** and place new methods after whatever is already there.
- **No new composer or npm dependency, and no migration.** `tickets.assigned_to` already exists, nullable, `ON DELETE SET NULL` (`backend/database/migrations/2026_08_26_084625_create_tickets_table.php:23`), and it is already in `#[Fillable]` (`backend/app/Models/Ticket.php:12`). `ticket_activities.event` is `varchar(50)` (`…_create_ticket_activities_table.php:15`), so a new enum case needs no schema change.
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An admin gives a ticket an accountable owner, and the trail says who moved it and from whom.

1. `POST /api/v1/tickets/{ticket}/assign` sets `assigned_to`, admin-only, after proving the target is an **active agent**.
2. An inactive user, an admin, or a non-existent id is a **`422`** — never a silent no-op.
3. One `assigned` activity row records the **previous** and the **new** assignee.
4. The SPA's Assign button opens a modal listing **only active agents**, searchable by name.
5. Assigning an already-assigned ticket is a **reassignment**, not an error.

**Not in scope.** **`assigned_to: null` is rejected here** — unassigning is **TM-34**'s first criterion, and so is the optional `reason` on the activity row. **Self-claim by an agent is TM-32**, which is the story that relaxes `TicketPolicy::assign()`; leave that method admin-only and do **not** add a claim endpoint, an unassigned filter, or a conflict response. **No notification is sent** — TM-34's third criterion owns that, and the whole email epic (E8) is untouched. No bulk assign, no round-robin, no workload numbers (**TM-35**), no assign control on the list view: the detail page is the only entry point, exactly as Story 24 does for delete.

---

## Context — Read These Files First

1. `backend/app/Policies/TicketPolicy.php` — **`assign()` at line 18 already exists and is already correct.** Read its docblock in Story 22's plan: *"E5-S2 … must relax this so an agent can claim a ticket where `assigned_to` is null — change it there, not here."* **This story changes no policy method.** That single fact is why the route is classified `admin-policy` and not put behind the `admin` middleware.
2. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — the **shape** this story copies twice over. `destroy()` at **58–76**: `$this->authorize(...)` *outside* the transaction, then `DB::transaction`, then `Category::query()->whereKey(...)->lockForUpdate()->first()` at **line 65**. And `reassignTickets()` at **86–98** for the `ActivityRecorder` call shape — `field`, **stringified** `old_value`/`new_value`, human names in `meta`.
3. `backend/app/Services/ActivityRecorder.php` — `record()` (**13–19**) fills the five defaults; `recordMany()` (**21–39**) requires all five and will write the literal string `"null"` into `meta` if you omit one. **Call `record()`.** The `LogicException` at **26–28** fires only outside a transaction.
4. `backend/app/Enums/TicketActivityEvent.php` — `Created` and `CategoryChanged` today. **Append only**; the existing strings are already in rows. Stories 23 and 24 each append one too, so expect merge neighbours.
5. `backend/app/Http/Controllers/Api/V1/TicketController.php` — **`show()` at 23–27, `store()` at 29–43.** `Ticket`, `TicketResource`, `ActivityRecorder`, `TicketActivityEvent`, `DB` and `JsonResponse` are **all already imported** (lines 5, 8, 12–18). Task 3 needs only `Illuminate\Http\Request` and the new form request added to the imports.
6. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — **`index()` at 19–38.** The `$request->validate([...])` block at **22–26** and the `->when(...)` chain at **27–35** are what task 5 extends by exactly one filter. Note `->orderBy('name')->orderBy('id')` at **34** — the modal wants that order and gets it for free.
7. `backend/app/Models/User.php` — `isAdmin()` at **41–44**, `scopeActive()` at **46–50**. `role` is cast to `UserRole` (**36**), which is why `where('role', UserRole::Agent)` works without `->value` — measured below.
8. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **read `ACCESS` (line 17), `test_agent_refused_by_policy_admin_routes` (44–51) and `routesFor()` (122–125) before writing the route.** `routesFor()` **already** takes `$ticketId = 999999` (Story 22 added it), so the only change task 4 makes is passing a **real** ticket id — for the reason measured below.
9. `frontend/src/components/TicketActionToolbar.vue` — **2 lines.** The Assign button is `data-testid="action-assign"`, `v-if="ticket.can.assign"`, `disabled`, `title="Assignment arrives with TM-31"`. Story 22's plan states the contract exactly: *"Each future story's job is: remove `disabled`, remove `title`, add the handler."* **This is that story.**
10. `frontend/src/components/CategoryDeleteDialog.vue` — **27 lines, the dialog precedent.** Entity via props (**6**), `emit('close')` (**7**), store call in try/catch into a local `error` ref (**13**), `*-confirm` / `*-cancel` test ids (**24–25**). Task 11 follows this shape.
11. `frontend/src/views/AdminUsersView.vue` — **lines 9–17 are the debounce precedent**: a module-scope `timer`, `clearTimeout`, `setTimeout(…, 300)`. Task 11 reuses it. **Do not import `useUsersStore` into the dialog** — see task 11's first bullet.
12. `frontend/src/api/users.ts` — `UserListQuery` at **13–18** and `listUsers()` at **29–36**. `AdminUser` already carries an optional `tickets_count` (**11**) that no endpoint sends yet; leave it alone, TM-35 fills it.
13. `docs/api-contract.md` — the endpoint table ends at **line 47**; `## Authorization` at **18–28** still says *"ticket policies arrive with their models in TM-17 and TM-22"*. Task 7 adds the row and the subsection.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Ticket has no assignee | `assigned_to` is `null` and nothing can change it | Admin sets it via the assign endpoint |
| Ticket already has an assignee | — | **Reassignment**, `200`, one activity row carrying both ids (AC5) |
| Target is the **same** agent | — | `200`, **no** activity row, `updated_at` untouched |
| Target is inactive, an admin, or unknown | — | `422` (AC2) |
| Target is `null` or absent | — | `422` `required`. **TM-34** makes `null` legal |
| Caller is an agent | — | `403` on a real ticket, `404` on a non-existent one |

---

## Decision — no `/staff` endpoint, one new filter instead

Story 20 left this open. **Do not add `GET /api/v1/staff`, and do not relax `UserPolicy::viewAny()`.**

Every consumer of a staff list that this epic actually specifies is an **admin**: assign (this story), reassign/unassign (**TM-34**), workload (**TM-35**). TM-32's self-claim needs no list of colleagues at all — an agent claims for themselves. So the only caller is an admin, and admins already have `GET /api/v1/admin/users`, which already paginates, already searches `name` **or** `email`, and already filters `status=active`. The one thing it cannot do is filter by role, which AC4 requires.

**So: add a `role` filter to the existing admin endpoint (task 5), and the modal calls `GET /api/v1/admin/users?role=agent&status=active&search=<term>&per_page=100`.** A new endpoint duplicating search, pagination and ordering to serve one modal is not justified by anything in E5.

**Story 20's constraint therefore stands unchanged:** an agent still cannot enumerate staff, so its list-filter assignee dropdown keeps offering only Anyone / Me / Unassigned. **Say so in the PR description** so the next reader does not re-open a closed question, and note that the first story to give an *agent* a named-colleague picker is the one that must revisit `/staff`.

---

## Decision — the target must be an **active agent**, so an admin cannot hold a ticket

AC1 says *"validating the target is an active **agent**"*, and this plan implements that literally: `role = 'agent' AND is_active = 1`. **Measured consequence: an admin — including the admin doing the assigning — gets a `422`.**

That is the story's own wording and it is consistent with the rest of E5 (TM-35 counts load *per agent*). It is also **one `where()` clause** in `AssignTicketRequest::rules()` if the team decides admins should be assignable: drop `->where('role', UserRole::Agent)`. **Do not make that call silently mid-implementation** — raise it, and if it changes, change test 5 with it.

**Separately: an existing assignment survives its owner's deactivation.** Measured — deactivating a user leaves `tickets.assigned_to` pointing at them (the foreign key is `ON DELETE SET NULL`, and deactivation is not a delete). Validation rejects *new* assignments to an inactive user; it does not retro-clean old ones. **That is deliberate** — TM-35's fourth criterion exists precisely to surface *"inactive agents holding open tickets"*. Do not add a cleanup here.

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** through a throwaway `RefreshDatabase` test, on the schema as it stands. Do not re-derive them.

- **One `exists` rule covers all three `422` cases, and cannot distinguish them.** With `Rule::exists('users', 'id')->where('role', UserRole::Agent)->where('is_active', true)`:

  | `assigned_to` | Fails | Default message |
  |---|---|---|
  | active agent | no | — |
  | inactive agent | **yes** | `The selected assigned to is invalid.` |
  | **admin** | **yes** | `The selected assigned to is invalid.` |
  | `999999` | **yes** | `The selected assigned to is invalid.` |
  | `null` | yes | `The assigned to field is required.` |
  | absent | yes | `The assigned to field is required.` |
  | `"abc"` | yes | `The assigned to field must be an integer.` |

  So AC2 is satisfied by a single rule, and **task 2 must override the message** — `"The selected assigned to is invalid."` tells an admin nothing. There is no way to say *which* of the three it was without three separate queries, and leaking *"that account is deactivated"* to a caller who guessed an id is worse than one honest message.

- **`->where('role', UserRole::Agent)` and `->where('role', 'agent')` behave identically**, in the validation rule and on the query builder (both counted the same 3 agents). `users.role` is `enum('admin','agent')` — read from `information_schema`. **Use the enum case**, not the string literal; it is the project idiom and it survives a rename.

- **Re-assigning to the same agent is already a no-op, with no comparison of your own.** Measured on a saved ticket: setting `assigned_to` to the same id leaves `getDirty()` **empty** — `{}` — and **so does setting it to the same id as a numeric string** (`"2"` against int `2`). This is the same `getDirty()` behaviour Story 23 measured for the edit endpoint, and it is what makes AC5 free: a repeat assign returns `200`, writes no activity row, and does not bump `updated_at`.

- **`getOriginal()` must be read before `save()`.** Measured: `getDirty()` → `{"assigned_to":6}` with `getOriginal('assigned_to')` → `2` (an **`int`**, not a string) before the save; **after** `save()` the same call returns `6`. Get this wrong and every activity row reads `old_value === new_value`. Test 8 is written to catch it.

- **An activity row with `old_value = null` inserts cleanly** — the column is `text` nullable. So the first-ever assignment needs no sentinel value; `null` means *"was unassigned"*.

- **The response costs 7 queries.** Measured `fresh()->load(['requester','category','priority','status','assignee','creator'])`: **7** on an assigned ticket, **6** on an unassigned one — the null-foreign-key batch skip Story 22 documented for `show()`. Adding `escalatedBy` on an unescalated ticket adds none. **A query-count test must therefore fix the assignment state**, or it flaps.

- **`DB::transactionLevel()` is `1` under `RefreshDatabase`.** `ActivityRecorder`'s own guard is inert in the suite, so **an `assign()` that forgot `DB::transaction()` would still pass naive tests** — the save and the insert each succeed alone. Atomicity is proven only by forcing a failure between them (test 14).

- **A policy-gated route returns `404` before it returns `403`.** Story 24 measured this on `DELETE /tickets/{id}` and it applies unchanged here: route-model binding runs in middleware, ahead of the policy. So for an **agent**, `POST …/tickets/999999/assign` is **404** and `POST …/tickets/<real id>/assign` is **403**. `test_agent_refused_by_policy_admin_routes` loops `routesFor('admin-policy')` and asserts `assertForbidden()` on every entry, and `routesFor()` defaults `{ticket}` to `999999` — **so that test breaks the moment `tickets.assign` is classified, unless task 4 passes a real ticket id.**

---

## Backend Tasks

### 1 — One new event case

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append. **Do not reorder or rename** the existing cases.

```php
case Assigned = 'assigned';
```

`event` is `varchar(50)`, so **no migration**. Stories 23 (`Updated`) and 24 (`Deleted`) append here too — if either has landed, add this line below theirs.

### 2 — `AssignTicketRequest`

**Create file:** `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php`

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\UserRole;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AssignTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Ticket $ticket */
        $ticket = $this->route('ticket');

        return Gate::allows('assign', $ticket);
    }

    /** @return array<string, list<mixed>|string> */
    public function rules(): array
    {
        return [
            // TM-34 relaxes this to `nullable` so a null unassigns. Until then a
            // null is a 422, not a silent unassign.
            'assigned_to' => [
                'required',
                'integer',
                Rule::exists('users', 'id')
                    ->where('role', UserRole::Agent)
                    ->where('is_active', true),
            ],
            'reason' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            // One message for three cases — inactive, wrong role, non-existent.
            // Measured: `exists` cannot tell them apart, and naming which one it
            // was would confirm an account exists to someone guessing ids.
            'assigned_to.exists' => 'That user is not an active agent.',
            'assigned_to.required' => 'Choose an agent to assign this ticket to.',
            'reason.prohibited' => 'Assignment reasons arrive with TM-34.',
        ];
    }
}
```

- **`authorize()` calls the Gate**, matching `StoreTicketRequest.php:12–15`. The controller still calls `$this->authorize()` as well — belt and braces, and it is the project's existing double-guard pattern.
- **`reason` is `prohibited`**, mirroring how `StoreTicketRequest.php:38–39` prohibits `assigned_to` with a message naming the owning story. It makes TM-34's arrival a one-line diff instead of an unnoticed silent drop.

### 3 — `TicketController::assign()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add after `show()` (**23–27**), before `store()`. Add to the imports:

```php
use App\Http\Requests\Api\V1\AssignTicketRequest;
use App\Models\User;
```

```php
public function assign(AssignTicketRequest $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
{
    $this->authorize('assign', $ticket);
    $actorId = $request->user()->getKey();
    $targetId = $request->integer('assigned_to');

    DB::transaction(function () use ($ticket, $recorder, $actorId, $targetId): void {
        // Lock the row before reading the current assignee, matching
        // CategoryController::destroy():65. Without it two concurrent assigns can
        // each record the same `old_value` and the trail lies about the handover.
        Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->first();
        $ticket->refresh();
        $previousId = $ticket->assigned_to;
        $ticket->assigned_to = $targetId;
        // AC5: a repeat assign to the same agent is not an error and not an
        // event. Measured — getDirty() is empty even when the incoming id
        // arrives as a numeric string, so no comparison of our own is needed.
        if ($ticket->getDirty() === []) {
            return;
        }
        $ticket->save();
        $recorder->record($ticket->getKey(), TicketActivityEvent::Assigned, [
            'user_id' => $actorId,
            'field' => 'assigned_to',
            // Cast both, matching CategoryController::reassignTickets():94.
            // getOriginal() returns an int; a first assignment leaves old_value null.
            'old_value' => $previousId === null ? null : (string) $previousId,
            'new_value' => (string) $targetId,
            'meta' => [
                'from_name' => $previousId === null ? null : User::query()->whereKey($previousId)->value('name'),
                'to_name' => User::query()->whereKey($targetId)->value('name'),
            ],
        ]);
    });

    return TicketResource::make($ticket->fresh()->load([
        'requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy',
    ]))->response();
}
```

Six things that are load-bearing:

- **Read `$previousId` from the locked row, not from the bound instance.** `$ticket->refresh()` after the lock is what makes the `old_value` true under concurrency. `$ticket->getDirty()` still works after a refresh because `refresh()` re-syncs originals from the database.
- **Return early on an empty `getDirty()`.** No `save()` (which would bump `updated_at` for nothing) and no activity row. The response is still `200` with the unchanged ticket — AC5's *"not an error"*.
- **`record()`, not `recordMany()`.** The wrapper fills the five defaults (`ActivityRecorder.php:13–19`); `recordMany()` needs all five and writes the string `"null"` into `meta` on an omission.
- **`meta` carries the two names** so TM-45's timeline can render *"reassigned from Nadia to Omar"* without joining `users`, exactly as `reassignTickets()` carries `from_name`/`to_name`. **`from_name` is `null` on a first assignment** — the timeline reads that as "from the queue".
- **The name lookups cost at most two extra queries, and only when something changed.** They are outside the response's 7. Do not eager-load `assignee` to get the old name — it would be the *new* one by then.
- **`->fresh()` before `->load()`**, matching Story 23's `update()`. The eager-load list is `show()`'s exactly, so the payload shape is identical — **except there is no `can` block**, because that is gated on `$request->routeIs('tickets.show')` (`TicketResource.php:28`). Note that in a comment: the SPA re-reads the detail after assigning, and task 10 depends on it.

### 4 — Route, and the auth test it breaks

**File: `backend/routes/api.php`**

After the `tickets.show` line (**45**), inside the `['auth:sanctum', 'active']` group (opens **32**) and **outside** the `admin` group (opens **46**):

```php
Route::post('/tickets/{ticket}/assign', [TicketController::class, 'assign'])->name('tickets.assign');
```

**`{ticket}/assign`, not a `PATCH` on the ticket** — AC1 names the path. **Never write `/api/v1` into the path**; the prefix comes from `bootstrap/app.php:19`.

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Two edits, both mandatory — `test_every_api_route_is_classified` fails without the first, and `test_agent_refused_by_policy_admin_routes` fails without the second.

1. Add `'tickets.assign' => 'admin-policy'` to `ACCESS` (**line 17**), next to the three `categories.*` entries.
2. Give `test_agent_refused_by_policy_admin_routes` (**44–51**) a **real** ticket id, the same way it already creates a real `Category`:

```php
$ticket = Ticket::factory()->create();
foreach ($this->routesFor('admin-policy', $category->id, $ticket->id) as $route) {
```

`Ticket` is already imported (**line 6**) and `routesFor()` already accepts `$ticketId` (**122**) — Story 22 added both. The class will need `$this->seed()` in this test for the factory's master-data lookups; add it above the fixture line.

**`admin-policy`, not `admin`.** The route must carry **no** `admin` middleware, because TM-32 relaxes `TicketPolicy::assign()` to let an agent claim an unassigned ticket — middleware would make that impossible without moving the route. `test_policy_admin_routes_have_no_admin_middleware` (**61–66**) enforces it automatically once the classification is added.

### 5 — One new filter on the staff list

**File: `backend/app/Http/Controllers/Api/V1/Admin/UserController.php`**

`index()`, lines **19–38**. Add one validation rule and one `when()`; change nothing else.

In the `$request->validate([...])` block (**22–26**), after `'status'`:

```php
'role' => ['sometimes', Rule::enum(UserRole::class)],
```

In the `->when(...)` chain, after the two `status` clauses (**32–33**):

```php
->when(filled($filters['role'] ?? null), fn ($query) => $query->where('role', $filters['role']))
```

Add `use Illuminate\Validation\Rule;` to the imports. `UserRole` is already imported (**line 5**).

- **`Rule::enum(UserRole::class)`**, matching `UpdateUserRequest.php:21`. An unknown role is a `422`, not a silent empty list.
- **The validated value is the raw string**, and `where('role', 'agent')` matches — measured. Do not cast it.
- **`->orderBy('name')->orderBy('id')` (line 34) already gives the modal alphabetical order.** Do not add a second sort.
- **This does not change any existing response.** Omitting `role` behaves exactly as before, which is what keeps `AdminUsersView` and its tests green.

### 6 — Nothing to change in the policy or the resource

**`backend/app/Policies/TicketPolicy.php` — no change.** `assign()` at **line 18** is already `$user->isAdmin()`. TM-32 changes it, not this story.

**`backend/app/Http/Resources/V1/TicketResource.php` — no change.** `can.assign` is already in the show-route block (**line 28**), which is what the toolbar reads. **Do not add `can` to the assign response** and do not add it to the index.

### 7 — Document both changes

**File: `docs/api-contract.md`**

Add one row after **line 47**:

```markdown
| `POST` | `/api/v1/tickets/{ticket}/assign` | Set the ticket's owner. Admin-only; target must be an active agent. | bearer (TicketPolicy) | TM-31 |
```

Amend the `GET /api/v1/admin/users` row (**44**) so the table stops being wrong:

```markdown
| `GET` | `/api/v1/admin/users` | Paginated, searchable staff list. Filters: `search`, `status`, `role`. | admin bearer (UserPolicy) | TM-12 / TM-31 |
```

Then a subsection after the ticket endpoints:

```markdown
### `POST /api/v1/tickets/{ticket}/assign`

Sets `assigned_to`. Gated by `TicketPolicy::assign` — **admin-only**, and
deliberately **not** behind the `admin` middleware so TM-32 can relax the policy
to allow an agent to claim an unassigned ticket.

| Body field | Rules |
|---|---|
| `assigned_to` | Required integer. Must be an existing user with `role = agent` **and** `is_active = true`. |
| `reason` | Prohibited. Arrives with TM-34. |

Returns `200` with the full ticket — the same shape as `GET /tickets/{ticket}`
minus the `can` block, which is emitted only on the detail route.

- An inactive user, an **admin**, or an unknown id → `422`, `errors.assigned_to`
  = "That user is not an active agent." One message covers all three: the query
  cannot distinguish them, and naming the reason would confirm an account exists.
- `null` or a missing field → `422` `required`. TM-34 makes `null` mean *unassign*.
- Assigning an already-assigned ticket is a **reassignment**: `200`, and one
  `assigned` activity row carrying the previous and the new assignee.
- Assigning to the agent who already holds it is idempotent: `200`, **no**
  activity row, `updated_at` unchanged.
- An agent caller gets `403` on a real ticket and `404` on one that does not
  exist — route-model binding resolves before the policy.
- Deactivating a user does **not** clear tickets they already hold. TM-35
  surfaces those.

Each assignment writes one `ticket_activities` row: `event = 'assigned'`,
`field = 'assigned_to'`, `old_value` the previous user id (**`null` on a first
assignment**), `new_value` the new one, and `meta.from_name` / `meta.to_name`
for the timeline. `user_id` is the admin who assigned.
```

Also correct **lines 27–28**, which still promise ticket policies as future work:

```markdown
deletion is denied for everyone. `TicketPolicy` defines `viewAny`, `view`,
`create`, `update`, `delete`, `assign`, `changeStatus` and `escalate`.
```

### 8 — The two factories, **only if Story 19 has not landed**

Check first: `ls backend/database/factories/`. If `TicketFactory.php` and `RequesterFactory.php` are present, **skip this task**.

Otherwise create both **exactly as [Story 19's task 6](../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md) specifies** — copy the code from there rather than writing your own. Two things not to re-derive:

- **`TicketFactory` reads the seeded `priorities` / `statuses` rows** instead of having factories of its own, because both tables carry an `is_default_unique` virtual column with a unique index. **Every test using it must call `$this->seed()`.**
- **`reference` uses a static counter**, not `fake()->unique()` — the column is `char(15)` and `TKT-2026-000001` is exactly 15 characters.

**Record in the PR description that this story created them**, so Story 19 does not add a second variant.

---

## Frontend Tasks

### 9 — The API call and the query filter

**File: `frontend/src/api/tickets.ts`**

Add after `getTicket()` (**line 12**):

```ts
export async function assignTicket(id: number, assignedTo: number): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/assign`, { assigned_to: assignedTo }); return data.data }
```

**The return type is `Ticket`, not `TicketDetail`** — the assign response carries no `can` block, by design (task 3). Typing it as `TicketDetail` would be a lie the compiler cannot catch.

**File: `frontend/src/api/users.ts`**

Add one optional field to `UserListQuery` (**13–18**):

```ts
role?: UserRole
```

`UserRole` is already imported (**line 2**). `listUsers()` passes the whole object as `params` (**32–35**), so nothing else changes.

### 10 — The store action

**File: `frontend/src/stores/tickets.ts`**

Add `assigning` alongside `detailLoading`, and one action. Import `assignTicket`.

```ts
const assigning = ref(false)
async function assign(id: number, assignedTo: number): Promise<void> {
  assigning.value = true
  try {
    await assignTicket(id, assignedTo)
    // Re-read the detail rather than patching `current` from the response: the
    // assign endpoint deliberately omits the `can` block, so splicing its body
    // into `current` would strip the flags the toolbar renders from.
    await loadTicket(id)
  } finally {
    assigning.value = false
  }
}
```

Return `assigning` and `assign` from the store. **Do not catch here** — the dialog renders the error, matching `CategoryDeleteDialog.vue:13` and `usersStore.create/update` (`stores/users.ts:55–62`), which also let the caller catch.

### 11 — The assign modal

**Create file:** `frontend/src/components/TicketAssignDialog.vue`

```ts
const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ assigned: []; close: [] }>()
```

State: `agents` (`AdminUser[]`), `search` (`''`), `selected` (`number | undefined`, initialised to `props.ticket.assignee?.id`), `loading`, `error` (`''`), `errors` (`Record<string, string[]>`).

Load on mount and on debounced search:

```ts
const fetchAgents = async () => {
  loading.value = true
  try {
    // per_page 100 is the endpoint's validated maximum. Server-side `search`
    // narrows the list, so a team larger than 100 active agents still works —
    // it just cannot browse them all unpaged. Add paging here when that happens.
    agents.value = (await listUsers({ role: 'agent', status: 'active', search: search.value || undefined, per_page: 100 })).data
  } catch (reason) { error.value = errorMessage(reason) } finally { loading.value = false }
}
```

- **Call `listUsers` directly. Do NOT import `useUsersStore`.** That store holds the admin *screen's* list, filters and page (`stores/users.ts:12–18`); calling its `load()` from here would overwrite an admin's in-progress filters on `/admin/users` and reset their page. This is the one place in the SPA where bypassing a store is correct — **say so in a comment**.
- **Debounce with the `AdminUsersView.vue:9–17` pattern** — a `let timer`, `clearTimeout`, `setTimeout(…, 300)` in a `watch` on `search`. Same 300 ms.
- **`selected` starts at the current assignee** so a reassignment shows where the ticket is now, and the current holder stays **selectable** — the endpoint is idempotent (AC5) and disabling them would imply an error that does not exist.
- **Errors: `validationErrors(reason)` first, then `errorMessage(reason)`**, exactly as `UserFormDialog.vue:33–36`. `errors.assigned_to?.[0]` is the `422` from the server — which is the only thing that catches an agent deactivated *between* the list load and the submit.

| Element | `data-testid` | Notes |
|---|---|---|
| Root | `ticket-assign-dialog` | |
| Search input | `ticket-assign-search` | `v-model="search"`, placeholder `"Search agents by name"` |
| Loading | `ticket-assign-loading` | `v-if="loading"` |
| Option row | `ticket-assign-option` | `v-for` over `agents`; label is the name, plus `" (current)"` when `agent.id === ticket.assignee?.id` |
| Empty | `ticket-assign-empty` | `v-if="!loading && !agents.length"` — **"No active agents match."** |
| Error | `ticket-assign-error` | `v-if="error || errors.assigned_to"` |
| Confirm | `ticket-assign-confirm` | `:disabled="selected === undefined || store.assigning"` |
| Cancel | `ticket-assign-cancel` | `@click="emit('close')"` |

`confirm()` calls `store.assign(props.ticket.id, selected)`, then `emit('assigned')`; on throw it fills `errors`/`error` and **keeps the dialog open**.

### 12 — Wire the toolbar button

**File: `frontend/src/components/TicketActionToolbar.vue`**

For the `action-assign` button **only**: remove `disabled`, remove `title="Assignment arrives with TM-31"`, add `@click="emit('assign')"`, and declare `const emit = defineEmits<{ assign: [] }>()`. **Leave the other three buttons exactly as they are** — they belong to TM-27, TM-38 and TM-41. Keep the `v-if="ticket.can.assign"`: policy decides *hidden*, and that half of Story 22's rule does not change.

### 13 — Mount the dialog on the detail page

**File: `frontend/src/views/TicketDetailView.vue`**

Add `const assignOpen = ref(false)`, bind `@assign="assignOpen = true"` on `<TicketActionToolbar>`, and inside the `ticket-detail` article:

```html
<TicketAssignDialog v-if="assignOpen && store.current" :ticket="store.current" @assigned="assignOpen = false" @close="assignOpen = false" />
```

**No router navigation and no manual refetch** — `store.assign()` already re-read the detail, so the "Assignee:" line (**line 11**) updates on its own. **Do not add an assign control to the list view.**

---

## Edge Cases & Failure Modes

- **Target is an admin** → `422` "That user is not an active agent." Enforced by `->where('role', UserRole::Agent)` in `AssignTicketRequest::rules()`. **Measured**, and the deliberate reading of AC1 — see the decision section above. Test 5.
- **Target is an inactive agent** → same `422`, same message. The modal never lists them (`status=active`), so this only fires when someone is deactivated between the list load and the submit, or on a hand-rolled request. Test 4.
- **Target does not exist** → same `422`. Test 6.
- **`assigned_to` is `null`, absent, or non-numeric** → `422` `required` / `integer`. **Measured messages** in the table above. TM-34 relaxes the first. Test 7.
- **A `reason` field in the body** → `422` naming TM-34, not a silent drop. Test 13.
- **Assigning the agent who already holds the ticket** → `200`, **no** activity row, `updated_at` unchanged. Enforced by the `getDirty() === []` early return in `assign()`. Test 10.
- **Reassigning between two agents** → `200`, one row with both ids and both names. Test 8, which also guards the `getOriginal()`-before-`save()` ordering.
- **Two admins assign the same ticket at once** → last write wins on `assigned_to`, and **both activity rows are still truthful** because `lockForUpdate()` + `refresh()` serialises the read of the previous assignee. There is no `409` here; the conflict response is TM-32's third criterion, for claims.
- **An agent caller** → `403` on a real ticket, **`404` on a non-existent one** (binding precedes the policy — measured by Story 24, re-stated above). Both are correct; test 3 asserts the `403` and test 12 the `404`.
- **A soft-deleted ticket** → `404`, because binding excludes trashed rows. No `withTrashed()` anywhere in `assign()`.
- **The assignee is deactivated after assignment** → the ticket keeps them. Measured. Not cleaned up here; TM-35 surfaces it. Test 11.
- **The assignee's account is deleted** → `assigned_to` becomes `null` via `ON DELETE SET NULL` (`create_tickets_table.php:23`), and the activity row's `old_value` still names the id. `UserPolicy::delete()` returns `false` for everyone (`UserPolicy.php:29–32`), so this is unreachable through the API today.
- **More than 100 active agents** → the modal shows the first 100 alphabetically and search narrows it; there is no paging control. Documented in task 11's comment. Not a defect at this product's scale, but **do not silently truncate without that comment**.
- **The staff list request fails (network, or a 403 from a stale admin session)** → `ticket-assign-error` renders `errorMessage()`, which maps `403` to "You do not have permission to do that." (`api/errors.ts:16–17`). The dialog stays open; confirm stays disabled because nothing is selected.
- **An admin whose own account was deactivated mid-session** → the `active` middleware returns `401` and revokes tokens before the policy runs. Unchanged behaviour; no handling needed here.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketAssignTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Model the class on `RouteAuthorizationTest`'s `tokenFor()` helper (**117–120**). If `tests/Feature/Tickets/` does not exist yet, this story creates it.

1. `test_unauthenticated_request_is_rejected` — no token → `401`.
2. `test_admin_assigns_an_unassigned_ticket` — **AC1.** `200`, `assertJsonPath('data.assignee.id', $agent->id)`, and `assertJsonMissingPath('data.assignee.email')` (nesting `UserResource` would leak the staff directory — Story 18's constraint).
3. `test_agent_is_forbidden` — agent token, **real** ticket id → `403`.
4. `test_inactive_agent_is_rejected` — **AC2.** `422`, `assertJsonPath('errors.assigned_to.0', 'That user is not an active agent.')`.
5. `test_admin_target_is_rejected` — **the decision above.** `422`, same message. **If the team decides admins are assignable, this is the test that changes.**
6. `test_unknown_user_is_rejected` — `assigned_to: 999999` → `422`, same message.
7. `test_missing_and_malformed_assigned_to_are_rejected` — `{}`, `{"assigned_to": null}`, `{"assigned_to": "abc"}` → three `422`s; assert the `required` message on the first two and the `integer` message on the third.
8. `test_reassignment_records_previous_and_new_assignee` — **AC3 + AC5.** Assign to agent A, then to agent B. Assert `200`; exactly **two** `assigned` rows; the second has `old_value === (string) $a->id`, `new_value === (string) $b->id`, `meta.from_name === $a->name`, `meta.to_name === $b->name`, and `user_id === $admin->id`. **This is the test that catches reading `getOriginal()` after `save()`** — revert task 3's ordering and confirm it fails.
9. `test_first_assignment_records_a_null_previous_assignee` — one row, `old_value` **null**, `meta.from_name` null.
10. `test_assigning_the_same_agent_is_idempotent` — **AC5.** Assign twice to the same agent. Second call `200`; **still exactly one** activity row; `updated_at` unchanged (capture it before, compare after).
11. `test_existing_assignment_survives_deactivation` — assign, deactivate the agent, assert `tickets.assigned_to` still holds their id, then assert a **new** assignment to them is `422`. **Measured behaviour; this test is what stops someone "fixing" it.**
12. `test_nonexistent_ticket_is_404_for_both_roles` — id `999999` as admin **and** as agent → `404` both times. Use `Auth::forgetGuards()` between them, as `RouteAuthorizationTest:109–115` does.
13. `test_reason_is_prohibited` — `{"assigned_to": <id>, "reason": "x"}` → `422` with a message naming TM-34.
14. `test_assignment_is_atomic` — force a failure between the save and the activity insert (bind a throwing `ActivityRecorder`), assert the exception propagates **and** `tickets.assigned_to` is still the old value. **Without this, dropping `DB::transaction()` passes every other test** — measured, `transactionLevel` is `1` under `RefreshDatabase`.
15. `test_response_shape_matches_show_minus_can` — assert the response has `data.requester`, `data.category`, `data.priority`, `data.status`, `data.assignee`, `data.creator`, `data.description`, and **`assertJsonMissingPath('data.can')`**.
16. `test_response_query_count_is_seven` — wrap the request in `DB::enableQueryLog()`; assert the `load()` portion does not grow. **Assign the ticket first** — measured, an unassigned ticket costs 6 and an assigned one 7, so an un-fixed fixture flaps.

### Backend — `backend/tests/Feature/Admin/UserIndexRoleFilterTest.php` (new, or added to Story 12's user-index tests if one exists)

17. `test_filters_users_by_role` — 2 admins + 3 agents; `?role=agent` → 3 rows, every `role` is `agent`.
18. `test_role_filter_composes_with_status_and_search` — **AC4's server half.** One active agent named "Nadia", one inactive agent named "Nadia", one active admin named "Nadia"; `?role=agent&status=active&search=Nadia` → **exactly one** row.
19. `test_unknown_role_is_rejected` — `?role=wizard` → `422`, **not** an empty `200`.
20. `test_omitting_role_returns_every_user` — the regression that proves task 5 changed no existing behaviour.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

21. `test_every_api_route_is_classified` and `test_policy_admin_routes_have_no_admin_middleware` both cover `tickets.assign` **automatically** once `ACCESS` has the entry. `test_agent_refused_by_policy_admin_routes` needs the real ticket id from task 4 — **revert that one line and confirm the test fails with a `404`**, so the next person understands why it is there.

### Frontend — `frontend/src/components/TicketAssignDialog.spec.ts` (new)

Mock `../api/users` and `../api/tickets`, mount with `createPinia()`, following `views/HealthView.spec.ts:9–20`.

22. Lists only what the API returned, and calls `listUsers` with **`{ role: 'agent', status: 'active', per_page: 100 }`** — asserting the request, which is AC4's client half.
23. Renders `ticket-assign-empty` when the list is empty.
24. Typing in `ticket-assign-search` refetches **once** after the debounce (`vi.useFakeTimers()`, advance 300 ms, assert `listUsers` called twice total).
25. `ticket-assign-confirm` is disabled with nothing selected and enabled once an option is chosen.
26. Confirming calls `assignTicket(ticket.id, selectedId)` and emits `assigned`.
27. A `422` from the server renders `ticket-assign-error` with the server's message and **keeps the dialog mounted**.
28. `ticket-assign-cancel` emits `close` and issues **no** request.
29. The current assignee is listed, marked `(current)`, and **still selectable** — AC5 in the UI.

### Frontend — `frontend/src/components/TicketActionToolbar.spec.ts` (Story 22's file; modified, or created if Story 22 has not written it)

30. The Assign button is **no longer `disabled`** and no longer carries the `title`, and clicking it emits `assign`. **The other three buttons are still disabled and still carry their titles** — assert that explicitly, or the next story silently enables them.

### Frontend — `frontend/src/views/TicketDetailView.spec.ts` (Story 22's file; modified)

31. Clicking `action-assign` mounts `ticket-assign-dialog`; `close` unmounts it. Guard: the dialog is **absent** on first render.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
2. **Backend formats:** from `backend/`, `./vendor/bin/pint --test`.
3. **Backend tests:** from `backend/`, `composer test`. Then the targeted run: `php artisan test --filter='TicketAssignTest|UserIndexRoleFilterTest|RouteAuthorizationTest'`. Expect **+20 backend tests** over the baseline at the time you start.
4. **Prove test 14 earns its place:** delete `DB::transaction(` and its closing from `assign()`, re-run `--filter=test_assignment_is_atomic`, confirm it **fails**, restore.
5. **Prove task 4's real ticket id earns its place:** revert `$ticket->id` to the default in `test_agent_refused_by_policy_admin_routes`, re-run, confirm a `404`-vs-`403` failure, restore.
6. **Backend by hand.** `php artisan serve`, and with an **admin** token against a real ticket id:
   - `POST …/tickets/<id>/assign -d '{"assigned_to":<active agent id>}'` → `200`, `data.assignee.name` set, **no `data.can`**.
   - The same call again → `200`, and `SELECT count(*) FROM ticket_activities WHERE ticket_id=<id> AND event='assigned'` is **still 1**.
   - A different active agent → `200`, and the count is now **2**; the newest row's `old_value`, `new_value`, `meta.from_name`, `meta.to_name` are all populated.
   - `-d '{"assigned_to":<an admin id>}'` → `422` "That user is not an active agent." Same for an inactive agent, for `999999`, for `null`, and for `{}`.
   - `-d '{"assigned_to":<id>,"reason":"x"}'` → `422` naming TM-34.
   - `POST …/tickets/999999/assign` → `404`.
   - `GET …/tickets/<id>` → `data.can.assign` is `true` as admin, `false` as agent.
   - With an **agent** token: `POST …/tickets/<real id>/assign` → `403`; `POST …/tickets/999999/assign` → `404`.
   - `GET …/admin/users?role=agent&status=active&search=<name>` → only matching active agents; `?role=wizard` → `422`; `?role` omitted → the full list, unchanged.
7. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+8 new tests** plus the two modified spec files. Then `npx prettier --check src/api/tickets.ts src/api/users.ts src/stores/tickets.ts src/components/TicketAssignDialog.vue src/components/TicketActionToolbar.vue src/views/TicketDetailView.vue src/components/TicketAssignDialog.spec.ts`.
8. **Frontend by hand:** `npm run dev`.
   - As an **agent**, open a ticket → **no Assign button** (`can.assign` is false).
   - As an **admin**, open a ticket → Assign is present and **enabled**; Edit, Change status and Escalate are still greyed out with their titles.
   - Click Assign → the modal lists active agents **alphabetically**, and **no admin and no deactivated account appears**. *(AC4.)*
   - Type a partial name → the list narrows after a beat, one request not one per keystroke (check the network tab).
   - Cancel → nothing happens, no request fired.
   - Assign → the modal closes and the "Assignee:" line updates **without a page reload**.
   - Re-open Assign → the current holder is preselected and marked `(current)`; confirming them again closes cleanly with no error.
   - Assign to someone else → the line updates again.
   - Deactivate that agent on `/admin/users`, return to the ticket → **the ticket still shows them**; open Assign → they are **gone from the list**.
9. **Regression:** open `/admin/users`, set a search term and page 2, then open a ticket in another tab and use the assign modal; return to `/admin/users` and confirm **your filters and page are untouched** — the proof that task 11's direct `listUsers` call was the right choice. Then file a ticket from `/tickets/new` to confirm `store.create` still works.

---

## Done Criteria

- [ ] `POST /api/v1/tickets/{ticket}/assign` returns `200` for an admin and `403` for an agent, gated by `TicketPolicy::assign` and **not** by the `admin` middleware.
- [ ] `TicketPolicy` was **not** modified; `assign()` is still `$user->isAdmin()` for TM-32 to relax.
- [ ] An inactive user, an **admin**, and an unknown id each return `422` with `errors.assigned_to` = "That user is not an active agent."
- [ ] `null`, a missing field, and a non-integer each return `422`; a `reason` field returns `422` naming TM-34.
- [ ] Reassignment returns `200` and writes exactly one `assigned` row carrying `old_value`, `new_value`, `meta.from_name`, `meta.to_name` and the acting admin's `user_id`.
- [ ] A **first** assignment writes `old_value` as `null`, not a sentinel.
- [ ] Re-assigning the same agent returns `200`, writes **no** row, and leaves `updated_at` alone.
- [ ] The assignment is atomic — proven by a test that fails when `DB::transaction` is removed.
- [ ] The previous assignee is read under `lockForUpdate()` + `refresh()`, so concurrent assigns cannot record a false `old_value`.
- [ ] The response matches `show()`'s shape **minus** `can`, at **7** queries on an assigned ticket.
- [ ] `tickets.assign` is classified `admin-policy`, and `test_agent_refused_by_policy_admin_routes` passes a **real** ticket id — proven necessary by reverting it.
- [ ] `GET /api/v1/admin/users` accepts `role`, composes it with `status` and `search`, rejects an unknown role with `422`, and behaves **identically** when `role` is omitted.
- [ ] **No `GET /api/v1/staff` was added and `UserPolicy::viewAny` was not relaxed**, and the PR description records that decision plus the fact that Story 20's agent-facing assignee dropdown is unchanged.
- [ ] `TicketActivityEvent::Assigned` is **appended**; `Created` and `CategoryChanged` untouched; **no migration**.
- [ ] The SPA's Assign button is enabled for admins only, opens a modal listing **only active agents**, ordered by name and searchable, with the current holder marked and still selectable.
- [ ] The modal calls `listUsers` directly and **never touches `useUsersStore`** — verified by the `/admin/users` filter-preservation check.
- [ ] Assigning updates the detail page with no reload and no navigation; cancelling issues no request.
- [ ] `docs/api-contract.md` documents the endpoint, the single `422` message and why it is single, the `403`-vs-`404` rule, the activity row, the `role` filter, and that deactivation does not clear existing assignments.
- [ ] No unassign path, no self-claim, no reason, no notification, no bulk assign, no assign control on the list view, no new dependency.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+20 backend and +8 frontend tests** over the measured baseline, with Story 22's two spec files updated rather than duplicated.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 27 (TM-32, unassigned queue with self-claim).**
