# Story 22 — Ticket detail page (Story: TM-26)

## Prerequisites

- **Story 19 (TM-23) — PLANNED, NOT IMPLEMENTED. It is this story's only hard blocker.** Verified on disk: `TicketController` has **no `index()` or `show()`**, `TicketPolicy` has **only `create()`** (`backend/app/Policies/TicketPolicy.php:9–12`), and `frontend/src/views/TicketListView.vue`, `frontend/src/lib/relativeTime.ts` and `frontend/src/components/ColorBadge.vue` do not exist. Read [`19-story-paginated-ticket-list-TM-23.md`](19-story-paginated-ticket-list-TM-23.md) first — its task 3 (the `description` conditional), task 9 (the store) and task 12 (the list view) are what this story builds on.
- **Stories 20 (TM-24) and 21 (TM-25) are sequenced ahead of this one but are NOT dependencies.** This story touches `TicketPolicy`, `TicketResource`, the route file, `stores/tickets.ts` and `TicketListView.vue` — none of the filter, sort or search code. **If the team resequences, this story can be pulled forward to run directly after Story 19**; only the baselines below change. It does not touch `IndexTicketRequest` or `TicketSearch`.
- **Story 17 (TM-21) completed — implemented.** All seven nullable escalation and lifecycle columns already exist (`escalation_level`, `escalated_at`, `escalated_by`, `escalation_reason`, `first_responded_at`, `resolved_at`, `closed_at` — `create_tickets_table.php:25–31`), and `Ticket::escalatedBy()` is already defined (`Ticket.php:53–56`). **This story adds no migration.**
- **No new composer or npm dependency.**
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

**One coordination point, and it is the whole shape of task 3.** AC3's toolbar exposes four actions owned by **four future stories**: `PATCH /api/v1/tickets/{ticket}` is **TM-27** (sprint 3), `POST …/assign` is **TM-31** (sprint 3), `POST …/status` is **TM-38** (sprint 3), `POST …/escalate` is **TM-41** (sprint 4). Read the **Product rules** section below before writing any policy method — this story defines the abilities all four of those stories will consume, so getting the rules right now avoids four conflicting redefinitions.

---

## Story Goal

An agent opens one screen and sees everything about a ticket, plus which actions they are allowed to take on it.

1. `GET /api/v1/tickets/{ticket}` returns the full ticket with **requester, category, priority, status, assignee and creator**.
2. The page renders **every** ticket field, including `description`, the escalation state (`escalation_level`, `escalated_at`, `escalated_by`, `escalation_reason`) and `first_responded_at` / `resolved_at` / `closed_at` **when set**.
3. An action toolbar exposes **assign, change status, escalate and edit**, each hidden or disabled per policy.
4. A non-existent **or soft-deleted** ticket returns **404**, and the SPA shows a distinct not-found state.
5. The page is reachable directly by URL and survives a reload.

**Not in scope — and this is the most important boundary in the plan.** **No action in the toolbar performs anything.** This story delivers the toolbar *shell* with genuine policy-driven gating; each button's handler is wired by its owning story (TM-27, TM-31, TM-38, TM-41). AC3 says the toolbar "exposes … each hidden or disabled according to policy" — that is what ships. Also out of scope: the activity/history timeline (**TM-45**), inline editing (**TM-27**), and delete (**TM-28**).

---

## Product rules (from story) — the five policy abilities

`Gate::allows('view', $ticket)` currently returns **`false`**, measured — Laravel denies an ability the policy does not define. So **every ability the toolbar checks must be defined here, or all five flags ship as `false` and the toolbar is uniformly dead.** Each rule below comes from the acceptance criteria of the story that will own the action:

| Ability | Rule | Source | Notes for the owning story |
|---|---|---|---|
| `view` | any authenticated, active staff member | this story | No per-agent scoping exists in this product; `create()` is already unconditional for the same reason. |
| `update` | any staff | **TM-27** — *"As an agent, I want to correct a ticket's subject, description, category or priority"* | TM-27 must **not** allow `status` or `assigned_to` through `PATCH`; its own AC3 says so. |
| `delete` | **admin only** | **TM-28** — *"DELETE … is admin-only"* | Defined here for completeness; **no delete button ships in this story** (TM-28 owns the confirm dialog). |
| `assign` | **admin only** | **TM-31** — *"As an admin, I want to assign a ticket to a specific agent"* | **E5-S2 (unassigned queue with self-claim) will need to relax this** so an agent may claim an *unassigned* ticket. Change it there, not here. |
| `changeStatus` | any staff | **TM-38** — *"As an agent, I want to move a ticket to its next status"* | TM-38 gates the *individual transitions* through `TicketWorkflow::allowedTransitions`, which does not exist yet. This ability answers "may you open the status control at all", not "is this transition legal". |
| `escalate` | any staff **and the current status is not terminal** | **TM-41** — *"A terminal ticket cannot be escalated and returns 422"* | The only ability with a state condition, and it is implementable today: `statuses.is_terminal` is seeded `true` for `resolved` and `closed` (`StatusSeeder.php:17–18`), and `$ticket->status->is_terminal` was measured working. **TM-41 must keep this check** and add its `422` on the endpoint. |

---

## Context — Read These Files First

1. [`19-story-paginated-ticket-list-TM-23.md`](19-story-paginated-ticket-list-TM-23.md) — **task 3 is critical.** It rewrites `description` as `$this->when(! $request->routeIs('tickets.index'), …)`, **inverted so a new route gets `description` by default**. That is why `tickets.show` needs *no* work to include the description — confirm the inversion landed that way rather than as an opt-in list. Also read task 9 (store shape, `latestRequest` guard) and task 12 (`data-testid` contract).
2. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — **the `show()` precedent, lines 42–47.** Three lines: `$this->authorize('view', $category)`, then `return CategoryResource::make($category)->response();`. Implicit route-model binding, no manual lookup, returns `JsonResponse`. Task 2 copies this exactly.
3. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — `show()` at **50–55**, same shape. Note it returns `JsonResponse` while `index()` returns `AnonymousResourceCollection`.
4. `backend/app/Policies/TicketPolicy.php` — **13 lines, only `create()`.** Task 1 adds six methods.
5. `backend/app/Http/Resources/V1/TicketResource.php` — **29 lines.** `escalation_level` and `escalated_at` are already exposed (**21–22**), as are the three lifecycle timestamps (**23–25**). **`escalated_by` and `escalation_reason` are deliberately absent** — Story 18's overview records that decision and hands their exposure to this story. `assignee` / `creator` are inline `['id','name']` closures (**19–20**) because nesting `UserResource` would leak `email` to every agent; **keep that constraint for `escalated_by`.**
6. `backend/app/Models/Ticket.php` — `escalatedBy()` at **53–56** already exists and is unused. `casts()` at **18–21** casts all four datetime columns. `use SoftDeletes` at **line 16** is what makes AC4 work for free.
7. `backend/database/seeders/StatusSeeder.php` — **lines 17–18**: `resolved` and `closed` are the two `is_terminal` statuses. This is the fixture for the `escalate` policy test.
8. `backend/routes/api.php` — **line 44** is `tickets.store`; the group opens at **32** and the `admin` group at **45**. The new route goes with the other ticket routes, **outside** the admin group.
9. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `ACCESS` at **line 16** (Story 19 adds `tickets.index` and `tickets.store`). `routesFor()` at **121–124** resolves `{user}` and `{category}` to `999999`; **it does not know about `{ticket}`** — task 4 is about that.
10. `frontend/src/views/ForbiddenView.vue` — the "dead end" view precedent: a `.panel` main, a `data-testid` message, and a `RouterLink` home. Task 9's not-found state follows this shape.
11. `frontend/src/views/NewTicketView.vue` — the success panel at **line 20** (`new-ticket-created`) and `another()` at **line 14**. Story 18's overview commits this story to **replacing both with a redirect to the detail page**. `createdReference` is declared at **line 8**.
12. `frontend/src/api/errors.ts` — `errorMessage()` at **11–21** maps `403` and `429` but **not `404`**. Task 7 needs a 404 to be distinguishable from a generic failure; task 6 adds `isNotFound()` beside the existing `isUnauthorized()` (**2–4**) rather than a new message string.
13. `frontend/src/lib/relativeTime.ts` (Story 19's task 10) — reuse `relativeAge()` for the detail page's timestamps; **do not write a second date helper.**

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** with a temporary route registered inside a test.

- **AC4 needs no code at all — the default binding already does it.** Measured with `Route::get('/scratch/tickets/{ticket}', fn (Ticket $ticket) => …)`: a live ticket → **200**, a **soft-deleted** ticket → **404**, a non-existent id → **404**. `SoftDeletes` on the model (`Ticket.php:16`) puts `deleted_at is null` into the binding query. **Do not add `->withTrashed()` to the route** — measured, that flips the soft-deleted case to **200** and breaks AC4. Do not write a manual `findOrFail` either.
- **404 wins over 403, and that is the right precedent here.** Binding resolves in middleware, before the controller's `authorize()` runs, so an unknown id is a `404` for everyone — no policy is consulted. This differs from `admin/users/{user}`, where `RouteAuthorizationTest::test_unknown_id_agent_forbidden_admin_not_found` (**108–114**) asserts an agent gets `403` and an admin `404`; there the difference comes from the `admin` **middleware** rejecting before binding. `tickets.show` has no such middleware, so **one behaviour for everyone**. Task 5 asserts it.
- **`Gate::allows` on an ability the policy does not define returns `false`.** Measured: `Gate::allows('create', Ticket::class)` → `true`; `Gate::allows('view', $ticket)` → **`false`**, because `TicketPolicy` has no `view()`. This is why task 1 must define all six abilities before task 3's `can` block means anything — a missing method is a silently disabled button, not an error.
- **The show endpoint costs 6 queries on a typical ticket and 8 at most.** Measured with all seven relations eager-loaded (`requester, category, priority, status, assignee, creator, escalatedBy`): **6 queries** for an unassigned, unescalated ticket — the base row plus five relations, with `assignee` and `escalatedBy` **skipped entirely** because their foreign keys are null. Set both and it becomes **8**. This is the same null-foreign-key batch skip Story 19 documented for the list; here it means a query-count assertion must fix the fixture's assignment and escalation state or it will flap.
- **`$ticket->status->is_terminal` is available and correctly cast.** Measured `true` on a ticket whose status is one of the two terminal ones. So the `escalate` ability's state condition needs no new column and no new query beyond the `status` relation the endpoint already loads.
- **`escalated_by` and `escalation_reason` are genuinely missing from the resource** — confirmed by dumping the serialised keys: `id, reference, subject, description, requester, category, priority, status, assignee, creator, escalation_level, escalated_at, first_responded_at, resolved_at, closed_at, created_at, updated_at`. AC2 requires the escalation state, so task 3 adds both.

---

## Baselines — read this before you start

**These are Story 21's projected end state, not measured reality.** Stories 19, 20 and 21 are all unimplemented. Re-measure:

```bash
cd backend  && php artisan test 2>&1 | tail -5
cd frontend && npm test 2>&1 | tail -5
```

- Story 21 is expected to leave the backend at **165 tests / 162 passing** and the frontend at **78 tests across 13 files**. **If this story is pulled forward to run right after Story 19**, the baselines are **119 / 116** and **42 across 10 files** instead; the deltas below are what matter.
- **Two failures are expected to still be red and are out of scope**: `Auth\PasswordThrottleTest::test_seventh_attempt_is_blocked_per_user` (TM-14) and `Database\TicketReferenceTest::test_calling_outside_a_transaction_throws` (TM-21).
- **Expected delta: +16 backend tests, +14 frontend tests across 2 new files.** From Story 21's end state that is **181 backend / 178 passing** and **92 frontend across 15 files**.

---

## Backend Tasks

### 1 — Six policy abilities

**File: `backend/app/Policies/TicketPolicy.php`**

Implement the table in **Product rules** above. Keep `create()` unchanged.

```php
<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function viewAny(User $user): bool   // Story 19's task 1 — do not duplicate if present
    {
        return true;
    }

    public function view(User $user, Ticket $ticket): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    /** TM-27 owns `PATCH /api/v1/tickets/{ticket}`; any staff member may correct intake mistakes. */
    public function update(User $user, Ticket $ticket): bool
    {
        return true;
    }

    /** TM-28 owns `DELETE /api/v1/tickets/{ticket}` and its criterion 1 says admin-only. */
    public function delete(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /**
     * TM-31 owns `POST /api/v1/tickets/{ticket}/assign` as an admin action.
     * E5-S2 (unassigned queue with self-claim) must relax this so an agent can
     * claim a ticket where `assigned_to` is null — change it there, not here.
     */
    public function assign(User $user, Ticket $ticket): bool
    {
        return $user->isAdmin();
    }

    /**
     * TM-38 owns `POST /api/v1/tickets/{ticket}/status`. This answers "may you
     * open the status control", not "is this transition legal" — the legal
     * moves come from TM-38's `TicketWorkflow::allowedTransitions`.
     */
    public function changeStatus(User $user, Ticket $ticket): bool
    {
        return true;
    }

    /**
     * TM-41 owns `POST /api/v1/tickets/{ticket}/escalate`, and its criterion 5
     * says a terminal ticket cannot be escalated. `statuses.is_terminal` is
     * seeded true for `resolved` and `closed`. TM-41 must keep this check and
     * add the matching 422 on the endpoint.
     */
    public function escalate(User $user, Ticket $ticket): bool
    {
        return ! $ticket->status->is_terminal;
    }
}
```

- **`viewAny()` is Story 19's task 1.** If it is already there, leave it; if this story runs first, add it. **Do not create a second one.**
- **`escalate()` touches `$ticket->status`**, so the relation must be loaded or it lazy-loads one extra query per check. Task 2's eager-load list covers it; **do not** call this ability from a list context.
- **`$user` is unused in four of these methods.** That is intentional and matches `create()`; do not remove the parameter — the policy signature requires it.

### 2 — `TicketController::show()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add `show()` **after** `index()` and **before** `store()`, matching `CategoryController.php:42–47`.

```php
public function show(Ticket $ticket): JsonResponse
{
    $this->authorize('view', $ticket);

    return TicketResource::make($ticket->load([
        'requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy',
    ]))->response();
}
```

- **Implicit route-model binding, no `findOrFail`.** Measured: binding already returns **404** for both a missing and a soft-deleted ticket. Writing the lookup by hand would only be a way to get it wrong.
- **`escalatedBy` is the one relation the list does not load.** `status` must stay in the list because `escalate()` reads `is_terminal` off it.
- **`JsonResponse`, not `AnonymousResourceCollection`** — single resource, matching both `show()` precedents.
- No `use` additions needed: `Ticket`, `TicketResource` and `JsonResponse` are all already imported (**lines 8, 12, 16**).

### 3 — Extend `TicketResource` for the detail route

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Add three blocks, all gated on the show route so the list payload is untouched. **Keep it one class** — Story 18's overview commits TM-23 and TM-26 to varying what the resource emits rather than adding a second class.

```php
'escalated_by' => $this->whenLoaded('escalatedBy', fn () => ['id' => $this->escalatedBy->id, 'name' => $this->escalatedBy->name]),
'escalation_reason' => $this->when($request->routeIs('tickets.show'), fn () => $this->escalation_reason),
'can' => $this->when($request->routeIs('tickets.show'), fn () => [
    'update' => $request->user()->can('update', $this->resource),
    'assign' => $request->user()->can('assign', $this->resource),
    'change_status' => $request->user()->can('changeStatus', $this->resource),
    'escalate' => $request->user()->can('escalate', $this->resource),
]),
```

- **`escalated_by` is `['id','name']`, never a nested `UserResource`** — `UserResource` exposes `email` and the staff directory is admin-only. Same reason `assignee` and `creator` are inline at **lines 19–20**.
- **`whenLoaded` already guards a null relation** (Story 19 measured this: it returns before invoking the closure), so an unescalated ticket serialises `escalated_by` as `null` with no extra code.
- **The `can` block is show-only, deliberately.** Four `Gate` calls per row on a 100-row list is 400 policy evaluations, and `escalate()` reads `$ticket->status` — cheap only because the relation is loaded. **Do not add `can` to the index.** TM-28's `delete` is intentionally **absent** from this block: no delete button ships here, and adding the flag now would invite one.
- **`escalation_reason` is show-only** because it is free text that can be long, and the list has no column for it.

### 4 — Route, and the `{ticket}` parameter the auth test cannot resolve

**File: `backend/routes/api.php`**

Immediately after the `tickets.index` line Story 19 adds:

```php
Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
```

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add `'tickets.show' => 'staff'` to `ACCESS` (**line 16**). Then **`routesFor()` needs a third parameter** — it currently resolves only `{user}` and `{category}` (**line 123**), so `route('tickets.show')` throws `UrlGenerationException: Missing required parameter`:

```php
private function routesFor(string $level, int $categoryId = 999999, int $ticketId = 999999): array
{
    return collect(Route::getRoutes())->filter(fn ($route) => (self::ACCESS[$route->getName()] ?? null) === $level)->map(fn ($route) => ['name' => $route->getName(), 'method' => $route->methods()[0], 'uri' => route($route->getName(), ['user' => 999999, 'category' => $categoryId, 'ticket' => $ticketId], absolute: false)])->values()->all();
}
```

Then extend `test_agent_reaches_staff_routes` with a **real** ticket id, because `tickets.show` at id `999999` is a legitimate `404`:

```php
$ticket = Ticket::factory()->create();
$this->withToken($token)->getJson("/api/v1/tickets/{$ticket->id}")->assertOk();
```

- **`tickets.show` is `staff`, not `staff-write`** — a `GET` an agent genuinely gets `200` from, alongside `categories.show`.
- **This is the second time `routesFor()` has needed a new parameter** (Story 19 added `staff-write`, this adds `{ticket}`). Story 19's plan already forbids converting `test_agent_reaches_staff_routes` into a loop over `routesFor('staff')`, and this story is why that stays true: `categories.show` and `tickets.show` both `404` at id `999999`, so the loop would need real fixtures for every parameterised route. **Still do not convert it.**

### 5 — Document the endpoint

**File: `docs/api-contract.md`**

Add a row after the `GET /api/v1/tickets` row:

```markdown
| `GET` | `/api/v1/tickets/{ticket}` | One ticket, with requester, category, priority, status, assignee, creator and escalator, plus a `can` block. | bearer (TicketPolicy) | TM-26 |
```

And a subsection:

```markdown
### `GET /api/v1/tickets/{ticket}`

Returns the full ticket. Unlike the list, it includes `description`,
`escalation_reason`, `escalated_by` and a `can` object:

| Field | Notes |
|---|---|
| `escalated_by` | `{id, name}` or `null`. Never the email — the staff directory is admin-only. |
| `escalation_reason` | Free text or `null`. Detail route only. |
| `can.update` | Any staff. TM-27 owns the endpoint. |
| `can.assign` | Admin only. TM-31 owns the endpoint; E5-S2 will relax it for self-claim. |
| `can.change_status` | Any staff. Whether the *control* opens, not whether a given transition is legal — TM-38 owns that. |
| `can.escalate` | Any staff, **and** the current status is not terminal. TM-41 owns the endpoint. |

A non-existent **or soft-deleted** ticket returns `404` for every caller: route
model binding applies the soft-delete scope and resolves before the policy, so
no `403` is possible here. `TicketPolicy` defines `viewAny`, `view`, `create`,
`update`, `delete`, `assign`, `changeStatus` and `escalate`; `delete` is
admin-only and is not surfaced in `can` until TM-28.
```

---

## Frontend Tasks

### 6 — Detect a 404

**File: `frontend/src/api/errors.ts`**

Add beside `isUnauthorized` (**lines 2–4**):

```ts
export function isNotFound(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 404
}
```

**Do not add a 404 case to `errorMessage()`.** AC4 wants a distinct *state*, not a distinct sentence; the view branches on `isNotFound`.

**File: `frontend/src/api/tickets.ts`**

```ts
export interface TicketPermissions {
  update: boolean
  assign: boolean
  change_status: boolean
  escalate: boolean
}

export interface TicketDetail extends Ticket {
  escalated_by: TicketStaff | null
  escalation_reason: string | null
  can: TicketPermissions
}

export async function getTicket(id: number): Promise<TicketDetail> {
  const { data } = await client.get<{ data: TicketDetail }>(`/tickets/${id}`)
  return data.data
}
```

`TicketDetail extends Ticket` because the detail response is a superset — `Ticket` already has `description` (which Story 19's `TicketListItem` omits via `Omit<>`).

### 7 — Detail state in the store

**File: `frontend/src/stores/tickets.ts`**

Add a third concern alongside `creating` and the list state. **Do not touch either.**

```ts
const current = ref<TicketDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref<string | null>(null)
const detailNotFound = ref(false)

async function loadTicket(id: number): Promise<void> {
  detailLoading.value = true
  detailError.value = null
  detailNotFound.value = false
  current.value = null
  try {
    current.value = await getTicket(id)
  } catch (caughtError) {
    if (isNotFound(caughtError)) detailNotFound.value = true
    else detailError.value = errorMessage(caughtError)
  } finally {
    detailLoading.value = false
  }
}
```

- **`detailNotFound` is separate from `detailError`.** Collapsing them into one string makes AC4's distinct state impossible to render or assert.
- **`current` is cleared before the request**, so navigating from ticket 5 to ticket 9 never shows 5's data under 9's URL while loading.
- **Four separate refs, not a shared `loading`/`error` with the list.** The list and the detail can be mounted in sequence; sharing state makes a stale list error appear on the detail page.
- **No `latestRequest` guard is needed here** — unlike the debounced search, detail loads are driven by navigation, one at a time. Note that in a comment so nobody adds one by analogy.

### 8 — The action toolbar

**Create file:** `frontend/src/components/TicketActionToolbar.vue`

```ts
const props = defineProps<{ ticket: TicketDetail }>()
```

Four buttons, each **rendered only when its `can` flag is true** and each **`disabled`**, since no action exists yet:

| Button | `data-testid` | Shown when | Title attribute |
|---|---|---|---|
| Edit | `action-edit` | `ticket.can.update` | `Editing arrives with TM-27` |
| Assign | `action-assign` | `ticket.can.assign` | `Assignment arrives with TM-31` |
| Change status | `action-status` | `ticket.can.change_status` | `Status changes arrive with TM-38` |
| Escalate | `action-escalate` | `ticket.can.escalate` | `Escalation arrives with TM-41` |

```html
<button data-testid="action-edit" v-if="ticket.can.update" disabled title="Editing arrives with TM-27">Edit</button>
```

- **`v-if` on the flag, `disabled` unconditionally.** AC3 says "hidden **or** disabled according to policy": policy decides *hidden*, the missing implementation decides *disabled*. Those are two different reasons and conflating them means the next story cannot tell them apart.
- **The `title` naming the owning story is deliberate** — it tells a reviewer why the button is inert, and it is the string each future story deletes when it wires its handler.
- **No delete button.** `can.delete` is not in the API payload and TM-28 owns the confirm-dialog flow.
- Each future story's job is: remove `disabled`, remove `title`, add the handler. **Do not add click handlers or modals now.**

### 9 — The detail view

**Create file:** `frontend/src/views/TicketDetailView.vue`

Reads `route.params.id`, calls `store.loadTicket(...)` on mount, and **watches the param** so `/tickets/5` → `/tickets/9` reloads without a remount.

```ts
const route = useRoute()
const store = useTicketsStore()
const load = () => void store.loadTicket(Number(route.params.id))
onMounted(load)
watch(() => route.params.id, load)
```

Four mutually exclusive states, following `ForbiddenView.vue`'s dead-end shape for the last one:

| State | `data-testid` | Condition |
|---|---|---|
| Loading | `ticket-loading` | `store.detailLoading` |
| Not found | `ticket-not-found` | `!store.detailLoading && store.detailNotFound` |
| Error | `ticket-error` | `!store.detailLoading && store.detailError` |
| Content | `ticket-detail` | `!store.detailLoading && store.current` |

The not-found state reads **"Ticket not found. It may have been deleted."** with a `RouterLink` to `{ name: 'tickets' }` (`data-testid="ticket-not-found-back"`) — AC4's distinct state, and the wording covers the soft-delete case that produces the same 404.

Content requirements — AC2 is "all ticket fields", so be exhaustive:

- **Always:** `reference` (as the heading), `subject`, `description`, requester (name, email, phone, company), `CategoryBadge` for category, `ColorBadge` for priority and status, assignee (`?? 'Unassigned'`), creator, `created_at` and `updated_at` with `relativeAge()` and a `:title` carrying the ISO string.
- **Only when set (`v-if`):** `escalation_level > 0` with `escalated_at`, `escalated_by.name` and `escalation_reason` in a block with `data-testid="ticket-escalation"`; and each of `first_responded_at`, `resolved_at`, `closed_at` with test ids `ticket-first-responded`, `ticket-resolved`, `ticket-closed`. **AC2 says "when set"** — an unescalated ticket must not render an empty escalation panel.
- `<TicketActionToolbar :ticket="store.current" />`.

**Reuse `relativeAge()`, `CategoryBadge` and `ColorBadge`** from Story 19. Write no new date or badge code.

### 10 — Route, and the two links into it

**File: `frontend/src/router/index.ts`**

Add **after** `/tickets/new` so the static path is matched first:

```ts
{ path: '/tickets/:id', name: 'ticket-detail', component: TicketDetailView },
```

- **Order matters.** `/tickets/:id` would otherwise capture `/tickets/new` and try to load a ticket with id `NaN`. Vue Router matches in definition order for same-specificity routes; putting the static route first is the guarantee. **Test this** (frontend test 24).
- **No `meta.role`** — every authenticated agent may view a ticket.

**File: `frontend/src/views/TicketListView.vue`** (Story 19's)

Make the `reference` cell a `RouterLink` to `{ name: 'ticket-detail', params: { id: ticket.id } }`, `data-testid="tickets-reference-link"`. Story 18's overview assigns this to TM-26.

**File: `frontend/src/views/NewTicketView.vue`**

Story 18's overview commits this story to **replacing the success panel with a redirect**. Remove the `createdReference` ref (**line 8**), the `another()` function (**line 14**) and the `new-ticket-created` section (**line 20**), and redirect in `submit()`:

```ts
const ticket = await tickets.create(payload)
await router.push({ name: 'ticket-detail', params: { id: ticket.id } })
```

- **`push`, not `replace`** — back from the new ticket should return to the blank form, which is a sensible place to file a second ticket. That preserves the "create another" affordance the removed button provided, via the browser.
- `tickets.create` already returns the full `Ticket` including `id`, so no extra request.
- **Story 19's `NewTicketView` tests, if any assert `new-ticket-created`, must be updated** — they are asserting behaviour this story deliberately removes. Story 18 shipped no frontend tests, so check before assuming there are none.

### 11 — Formatting

```bash
cd frontend && npx prettier --write src/api/errors.ts src/api/tickets.ts src/stores/tickets.ts src/components/TicketActionToolbar.vue src/views/TicketDetailView.vue src/views/TicketListView.vue src/views/NewTicketView.vue src/router/index.ts src/views/TicketDetailView.spec.ts src/components/TicketActionToolbar.spec.ts src/stores/tickets.spec.ts
```

Same policy as Stories 19–21: **format only what you touch.** This clears `NewTicketView.vue`, one of the files still failing `format:check` from Story 18.

---

## Edge Cases & Failure Modes

- **A non-existent ticket id** → `404` from route-model binding, before any policy call. Rendered as `ticket-not-found`.
- **A soft-deleted ticket** → **also `404`**, and via the same mechanism: `SoftDeletes` puts `deleted_at is null` in the binding query (measured). The not-found copy covers both cases on purpose. **Adding `->withTrashed()` to the route flips this to `200`** and silently breaks AC4 — measured.
- **A non-numeric id (`/api/v1/tickets/abc`)** → `404`. The route has no `->whereNumber('ticket')` constraint and does not need one; binding fails to resolve and 404s.
- **`/tickets/abc` in the SPA** → `Number('abc')` is `NaN`, the request goes to `/tickets/NaN`, the API 404s, and the not-found state renders. Acceptable, and asserted.
- **`/tickets/new` must not be captured by `/tickets/:id`.** Route order is the only thing preventing it; frontend test 24 pins it.
- **An unescalated ticket** must render **no** escalation panel. `escalation_level` defaults to `0` (`create_tickets_table.php:25`), so the `v-if` keys off `> 0`, not off `escalated_at` being non-null.
- **A ticket in a terminal status** (`resolved`, `closed`) hides the Escalate button entirely, because `escalate()` returns `false` and the toolbar `v-if`s on the flag. This is the one action whose visibility depends on ticket state rather than role.
- **An agent** sees Edit, Change status and Escalate but **not** Assign (`assign` is admin-only). **An admin** sees all four. Both asserted.
- **Every ability the toolbar reads must exist on the policy** — measured, `Gate::allows` returns `false` for an undefined ability, so a typo like `changeStatus` vs `change_status` produces a silently missing button and no error. The snake_case is only in the **JSON keys**; the ability names are camelCase.
- **`escalate()` lazy-loads `status` if it is not eager-loaded**, adding a query per call. Task 2's list includes `status`; **this is why `can` must never be added to the index**, where it would be one extra query per row on top of 400 policy calls.
- **Navigating `/tickets/5` → `/tickets/9`** reuses the component, so `onMounted` does not re-fire. The `watch` on `route.params.id` is what reloads; without it the page shows ticket 5 under ticket 9's URL.
- **`current` is cleared before each load**, so the previous ticket never flashes under the new URL.
- **A reload on `/tickets/9`** works because the route is registered statically and the store loads on mount — AC5 needs no server-side change (the SPA is served by Vite in dev; production history-fallback is TM-6's concern, already handled).
- **`escalation_reason` can be long free text.** It is show-only and rendered in a block that wraps; it is not in the list payload.
- **An agent must not see any staff email.** `escalated_by`, `assignee` and `creator` are all `['id','name']`; nesting `UserResource` would leak `email`. Asserted for `escalated_by` in backend test 8.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketShowTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Uses Story 19's `TicketFactory` and `RequesterFactory`. `tests/Feature/Tickets/` exists once Story 19 lands.

1. `test_unauthenticated_request_is_rejected` — `401`.
2. `test_agent_can_view_a_ticket` — `200` with `data.id` and `data.reference`.
3. `test_returns_every_relation` — **AC1.** `assertJsonPath` for `data.requester.name`, `data.category.name`, `data.priority.name`, `data.status.name`, `data.creator.name`, and `data.assignee.name` on an assigned ticket.
4. `test_includes_description_unlike_the_list` — asserts `data.description` is present here **and** absent from `GET /api/v1/tickets`. This pins Story 19's inverted `when()`; if someone flips it to an opt-in list, this fails.
5. `test_exposes_escalation_state_when_set` — **AC2.** A ticket with `escalation_level`, `escalated_at`, `escalated_by` and `escalation_reason` set; assert all four, including `data.escalated_by.name`.
6. `test_escalation_fields_are_null_when_unset` — `data.escalated_by` is `null`, `data.escalation_reason` is `null`, `data.escalation_level` is `0`.
7. `test_exposes_lifecycle_timestamps_when_set` — `first_responded_at`, `resolved_at`, `closed_at` all non-null on a resolved ticket.
8. `test_does_not_leak_staff_emails` — `assertJsonMissingPath` for `data.escalated_by.email`, `data.assignee.email`, `data.creator.email`.
9. `test_can_block_for_an_agent` — `can.update` true, `can.change_status` true, **`can.assign` false**.
10. `test_can_block_for_an_admin` — all four true (on a non-terminal ticket).
11. `test_cannot_escalate_a_terminal_ticket` — a ticket in `resolved`; `can.escalate` is **false** for both an agent and an admin. Use `Status::where('is_terminal', true)`.
12. `test_can_block_is_absent_from_the_list` — `GET /api/v1/tickets` has no `data.0.can`. Guards the per-row cost.
13. `test_missing_ticket_returns_404` — **AC4.**
14. `test_soft_deleted_ticket_returns_404` — **AC4**, the half that a `withTrashed()` route would break.
15. `test_missing_ticket_is_404_for_agent_and_admin_alike` — both `404`, **not** `403`. Documents the deliberate difference from `admin/users/{user}`.
16. `test_query_count_is_bounded` — fix the fixture with **both** `assigned_to` and `escalated_by` set and assert **8** queries; note in a comment that an unassigned, unescalated ticket is **6** because Eloquent skips null-key relation batches.

### Backend — `backend/tests/Feature/Policies/TicketPolicyTest.php` (new)

`tests/Feature/Policies/UserPolicyTest.php` is the sibling to model.

17. `test_all_staff_may_view_and_create_and_update`.
18. `test_only_admins_may_delete_and_assign` — the two admin-only abilities, agent denied.
19. `test_escalate_is_denied_for_a_terminal_status_and_allowed_otherwise` — parameterised over both terminal statuses and one open one.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

20. `test_every_api_route_is_classified` / `test_every_classified_route_exists` — stay green with `tickets.show` added.
21. `test_agent_reaches_staff_routes` — gains the real-ticket-id assertion. **Confirm it does not 404**, which is what a naive `999999` would do.

### Frontend

22. **`frontend/src/stores/tickets.spec.ts`** (extend) — `loadTicket` populates `current` and clears `detailLoading`; a 404 sets `detailNotFound` **and leaves `detailError` null**; a 500 sets `detailError` **and leaves `detailNotFound` false**; `current` is cleared at the start of a load; the list state and `creating` are untouched by a detail load. **5 tests.**
23. **`frontend/src/components/TicketActionToolbar.spec.ts`** (new) — all four buttons render for all-true `can` and every one is `disabled`; `action-assign` is absent when `can.assign` is false; `action-escalate` is absent when `can.escalate` is false; no `action-delete` exists. **4 tests.**
24. **`frontend/src/views/TicketDetailView.spec.ts`** (new) — the four states are mutually exclusive (loading shows no content; `detailNotFound` renders `ticket-not-found` and **not** `ticket-error`; a 500 renders `ticket-error` and not `ticket-not-found`); content renders reference, subject, description, requester email and the three badges; the escalation panel is **absent** on an unescalated ticket and **present** with reason and escalator when set; changing `route.params.id` triggers a reload; **`router.resolve('/tickets/new').name` is `'new-ticket'`, not `'ticket-detail'`** (the route-order guard, via `createAppRouter(createMemoryHistory())`). **5 tests.**

### Frontend — modified

25. **`frontend/src/views/TicketListView.spec.ts`** (extend) — the reference cell is a link to `/tickets/:id`.
26. **`frontend/src/views/NewTicketView.spec.ts`** — if Story 19 or 18 left a test asserting `new-ticket-created` or `new-ticket-another`, **replace it** with one asserting a `push` to `ticket-detail` after a successful create. If no spec exists, add that one test.

---

## Verification Steps

1. **Story 19 is done.** `php artisan test --filter=TicketIndexTest` passes and `php artisan route:list --name=tickets` shows `tickets.index`. **If not, stop.**
2. **Backend formats and passes:** from `backend/`, `./vendor/bin/pint --test`, then `composer test`. Expect **+16 tests** over your measured baseline, with only TM-14's and TM-21's failures red.
3. **The new suites alone:** `php artisan test --filter='TicketShowTest|TicketPolicyTest'`.
4. **Prove the soft-delete 404 is load-bearing, not incidental.** Temporarily add `->withTrashed()` to the `tickets.show` route and run `--filter=test_soft_deleted_ticket_returns_404`. It must **fail with a 200**. Remove it. This is the one-character change that silently breaks AC4.
5. **Prove the policy abilities are wired.** Temporarily rename `TicketPolicy::changeStatus` to `changeStatuss` and run `--filter=test_can_block_for_an_agent`. It must fail with `can.change_status` **false, not an error** — measured, `Gate::allows` denies unknown abilities silently. Restore the name.
6. **Confirm `can` really is absent from the list**, so the per-row cost never creeps in: `--filter=test_can_block_is_absent_from_the_list`.
7. **Backend by hand**, with an agent token then an admin token:
   - `GET …/tickets/<id>` → `200`, `description` present, `can` present.
   - As an **agent**: `can.assign` is `false`. As an **admin**: `true`.
   - On a **resolved** ticket: `can.escalate` is `false` for both.
   - `GET …/tickets/999999` → `404`. Soft-delete one (`php artisan tinker --execute="App\Models\Ticket::first()->delete();"`) then `GET` it → `404`.
   - `GET …/tickets` → **no** `can` key on any row, and no `description`.
8. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npx prettier --check` on task 11's list, `npm test` — **+14 tests across 2 new files**.
9. **Frontend by hand:** `npm run dev`, sign in.
   - From `/tickets`, click a reference → the detail page loads.
   - **Reload the page.** It renders the same ticket. *(AC5.)*
   - **Paste the URL into a new tab.** Same. *(AC5.)*
   - Visit `/tickets/999999` → the not-found state with a working back link, **not** a generic error.
   - Visit `/tickets/new` → **the new-ticket form, not the detail page.** *(The route-order trap.)*
   - File a ticket → you land on its detail page. Press **Back** → the blank form.
   - As an **agent**, confirm no Assign button; as an **admin**, confirm there is one. Every button is greyed out.
   - Find or create a resolved ticket → no Escalate button.
10. **Regression:** `/tickets` still lists and pages; `/tickets/new` still files. Confirm the removed `new-ticket-created` panel is not referenced anywhere: `grep -rn "new-ticket-created\|new-ticket-another" frontend/src` returns **nothing**.

---

## Done Criteria

- [ ] `GET /api/v1/tickets/{ticket}` returns the ticket with requester, category, priority, status, assignee, creator **and escalator**.
- [ ] `description`, `escalation_reason` and `escalated_by` appear on the detail route and **`description` is still absent from the list**.
- [ ] `escalated_by`, `assignee` and `creator` expose only `id` and `name` — **no staff email anywhere in the payload**.
- [ ] A `can` block with `update`, `assign`, `change_status`, `escalate` is present on the detail route and **absent from the list**.
- [ ] `TicketPolicy` defines `viewAny`, `view`, `create`, `update`, `delete`, `assign`, `changeStatus`, `escalate`; `delete` and `assign` are admin-only; `escalate` is false on a terminal status.
- [ ] A non-existent **and** a soft-deleted ticket both return `404`, for agents and admins alike — with no `->withTrashed()` on the route.
- [ ] `tickets.show` is classified `staff` in `ACCESS`, `routesFor()` resolves `{ticket}`, and `RouteAuthorizationTest` is fully green.
- [ ] The SPA detail page renders every ticket field, and the escalation panel and each lifecycle timestamp appear **only when set**.
- [ ] The toolbar shows Edit / Assign / Change status / Escalate gated by `can`, every button `disabled` with a `title` naming its owning story, and **no delete button**.
- [ ] Loading, not-found, error and content are mutually exclusive; a 404 renders the not-found state and not the error state.
- [ ] `/tickets/:id` is reachable by URL, survives a reload, and reloads when the id changes without a remount.
- [ ] `/tickets/new` still resolves to the new-ticket form, not the detail route.
- [ ] The list's `reference` links to the detail page.
- [ ] `NewTicketView` redirects to the detail page on success; `new-ticket-created` and `new-ticket-another` no longer exist anywhere.
- [ ] `docs/api-contract.md` documents the endpoint, the `can` semantics and the 404-for-everyone rule.
- [ ] Story 19's suites pass unchanged (except the deliberately replaced `NewTicketView` assertion).
- [ ] `pint --test`, `lint`, `typecheck` clean; **+16 backend and +14 frontend tests** over the measured baseline.
- [ ] No migration, no new dependency, and **no action endpoint** — TM-27, TM-31, TM-38 and TM-41 remain unstarted.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 23 (TM-27, edit a ticket).**
