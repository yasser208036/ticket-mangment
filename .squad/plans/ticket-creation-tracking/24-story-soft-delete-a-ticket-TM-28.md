# Story 24 — Soft-delete a ticket (Story: TM-28)

## Prerequisites

- **Story 22 (TM-26) — PLANNED, NOT IMPLEMENTED. Hard blocker.** It defines `TicketPolicy::delete()` as admin-only, the `can` block this story adds a key to, `TicketActionToolbar.vue`, and `TicketDetailView.vue`. Read [`22-story-ticket-detail-page-TM-26.md`](22-story-ticket-detail-page-TM-26.md) — **its task 3 deliberately omits `delete` from the `can` block and hands that to this story**, and its task 8 ships no delete button for the same reason. **Gate: do not start until `php artisan test --filter='TicketShowTest|TicketPolicyTest'` passes.**
- **Stories 19 (TM-23), 20 (TM-24) and 21 (TM-25) — PLANNED, NOT IMPLEMENTED.** By `NN` execution order they land before this story, and **AC2 ("excluded from all lists, filters, searches") needs all three** to be testable. If the team resequences and any is missing, drop only the assertions that target it and record which story must add them back — see the Test Plan note.
- **Stories 17 (TM-21) and 18 (TM-22) completed — implemented.** `Ticket` already has `use SoftDeletes` (`backend/app/Models/Ticket.php:16`) and the `deleted_at` column (`create_tickets_table.php:33`). `ActivityRecorder::record()` (`backend/app/Services/ActivityRecorder.php:13–19`) is Story 18's wrapper.
- **No new composer or npm dependency, and no migration.** Everything needed already exists: `deleted_at`, `SoftDeletes`, and `ticket_activities`.
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An admin clears spam and duplicates out of the queue without destroying anything.

1. `DELETE /api/v1/tickets/{ticket}` is **admin-only** and performs a **soft** delete.
2. Soft-deleted tickets are absent from every list, filter and search.
3. One activity row records **who** deleted it and **when**.
4. The SPA asks for confirmation **naming the ticket reference** before deleting.

**Not in scope.** **There is no restore endpoint and no purge endpoint.** The story says "keeping it recoverable", and soft-deleting is what makes recovery possible — recovery itself is not in any current backlog item, and a `deleted_at = null` update in the database is the operator path until one exists. **Never call `forceDelete()`** — measured below, it destroys the ticket's entire activity trail, which is the opposite of this story's purpose. Also out of scope: bulk delete, a trash/archive screen, and a delete control on the list view (the detail page is the only entry point). AC2's "statistics" clause is a **forward constraint on TM-29**, which has no plan yet.

---

## Context — Read These Files First

1. [`22-story-ticket-detail-page-TM-26.md`](22-story-ticket-detail-page-TM-26.md) — **task 1** defines `TicketPolicy::delete()` as `$user->isAdmin()`; **task 3** defines the `can` block and states that `delete` is *"intentionally absent … no delete button ships here, and adding the flag now would invite one"* — this story is where it arrives; **task 8** defines the toolbar and its *"no delete button"* rule.
2. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — **`destroy()` at lines 58–76 is the precedent**, and read it carefully for three things: `$this->authorize('delete', $category)` first, the whole body wrapped in `DB::transaction`, and `return response()->noContent()` (**line 74**). Also `reassignTickets()` (**86–98**) for the `ActivityRecorder` call shape — `field`, stringified `old_value`/`new_value`, and human context in `meta`.
3. `backend/app/Policies/CategoryPolicy.php` — `delete()` at **29–32** returns `$user->isAdmin()`. This is the analogue: **policy-gated, not middleware-gated**, which is why the route is classified `admin-policy` in task 4.
4. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — **read `test_agent_refused_by_policy_admin_routes` at lines 43–50 and `test_policy_admin_routes_have_no_admin_middleware` at 60–65 before writing the route.** The first creates a **real** `Category` and passes `$category->id` into `routesFor()` precisely because a policy-gated route needs an existing record to return `403` instead of `404`. Task 4 does the same for tickets — see the measured facts.
5. `backend/app/Services/ActivityRecorder.php` — `record()` (**13–19**) fills the five defaults; `recordMany()` (**21–39**) throws when `DB::transactionLevel() === 0` (**26–28**). **Call `record()`.**
6. `backend/app/Enums/TicketActivityEvent.php` — `Created` and `CategoryChanged` today, plus `Updated` once Story 23 lands. **Append only.** `event` is `varchar(50)`, so no migration.
7. `backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php` — **line 13**: `foreignId('ticket_id')->constrained()->cascadeOnDelete()`. That cascade is the reason `forceDelete()` is forbidden; it does **not** fire on a soft delete.
8. `frontend/src/components/CategoryDeleteDialog.vue` — **the confirmation-dialog precedent, 27 lines.** Props carry the entity (**line 6**), `emit('close')` (**7**), the store call is wrapped in try/catch with `errorMessage` into a local `error` ref (**11–14**), and there are `*-confirm` / `*-cancel` buttons with test ids (**24–25**). Task 9 follows this shape.
9. `frontend/src/stores/categories.ts` — `remove()`, the store-side delete precedent. Match its naming.

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)**, with a temporary policy-gated route registered inside a test.

- **A soft delete preserves the activity trail; a force delete destroys it.** Measured on a ticket with one activity row: after `$ticket->delete()` the row count is still **1**, the ticket is gone from the default scope and present under `withTrashed()`. After `$ticket->forceDelete()` on a second ticket, the activity count went **1 → 0** — the `cascadeOnDelete` foreign key at `create_ticket_activities_table.php:13` fires on a real `DELETE`. **So `forceDelete()` silently erases the audit trail this story exists to preserve. Never call it, and do not add a purge endpoint.**

- **An activity row can be written for an already-soft-deleted ticket.** Measured: inserting a `deleted` row *after* `$ticket->delete()` succeeded and took the count from 1 to **2**. The foreign key only requires the `tickets` row to exist, and a soft delete leaves it there. So task 2 is free to delete first and record second, which is the honest order — it records what actually happened rather than what was about to.

- **`test_agent_refused_by_policy_admin_routes` will FAIL the moment `tickets.destroy` is classified `admin-policy`, unless task 4 also fixes it.** Measured on a policy-gated `DELETE` route with an **agent** token:
  | Request | Status |
  |---|---|
  | `DELETE /…/tickets/999999` (non-existent) | **404** |
  | `DELETE /…/tickets/<real id>` | **403** |

  That test loops `routesFor('admin-policy')` and calls `assertForbidden()` on every one. `routesFor()` defaults `{ticket}` to `999999` (Story 22's task 4 adds the parameter), so the request 404s and the assertion fails. **Route-model binding resolves in middleware, before the policy** — the same ordering Story 22 measured for `show()`. The fix is the one the existing test already uses for categories: create a **real** ticket and pass its id.

- **Deleting an already-deleted ticket returns `404`, not `409` or `204`.** Measured with an **admin** token against a soft-deleted ticket: **404**, because binding excludes trashed rows. A double-submit from the SPA is therefore harmless, and no explicit guard is needed.

- **`DB::transactionLevel()` is `1` under `RefreshDatabase`.** Re-confirmed across this feature. `ActivityRecorder`'s own `LogicException` guard is inert in the suite, so **an `destroy()` that forgot `DB::transaction()` would still pass naive tests** — the delete and the activity insert would each succeed alone. Atomicity is proven only by forcing a failure between them (test 13).

---

## Backend Tasks

### 1 — One new event case

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append. **Do not reorder or rename** — the existing strings are already in rows.

```php
case Deleted = 'deleted';
```

### 2 — `TicketController::destroy()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add after `update()`, following `CategoryController::destroy()`'s shape (**lines 58–76**).

```php
public function destroy(Request $request, Ticket $ticket, ActivityRecorder $recorder): Response
{
    $this->authorize('delete', $ticket);
    $actorId = $request->user()->getKey();
    DB::transaction(function () use ($ticket, $recorder, $actorId): void {
        // Soft delete only. `forceDelete()` would cascade through
        // ticket_activities.ticket_id and erase the trail this story exists to
        // keep — measured, an activity count of 1 goes to 0. The row survives a
        // soft delete, which is also why the activity insert below works after it.
        $ticket->delete();
        $recorder->record($ticket->getKey(), TicketActivityEvent::Deleted, [
            'user_id' => $actorId,
            'meta' => ['reference' => $ticket->reference, 'subject' => $ticket->subject],
        ]);
    });

    return response()->noContent();
}
```

- **`$this->authorize('delete', $ticket)` outside the transaction**, matching `CategoryController.php:60`. Story 22's `TicketPolicy::delete()` answers it.
- **`response()->noContent()` → `204`**, matching `CategoryController.php:74`. Return type is `Response` (`Illuminate\Http\Response`), already imported there; add the import here.
- **Delete first, record second.** Measured safe, and it records what happened rather than what was about to. `created_at` on the activity row is the "when" AC3 asks for; `user_id` is the "who".
- **`meta` snapshots `reference` and `subject`** so TM-45's timeline can render "deleted TKT-2026-000123 — Printer jams" without joining a row that no longer appears in normal queries. Consistent with Story 18's `Created` row carrying `['reference' => …]` and `reassignTickets()` carrying `from_name`/`to_name`.
- **`record()`, not `recordMany()`** — the latter needs all five keys and writes the literal string `"null"` into `meta` if you omit one.
- **No `->withTrashed()` anywhere.** Binding must keep excluding trashed rows so a second delete is a clean `404`.

### 3 — Expose `can.delete`

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Add one line to the `can` block Story 22 creates, which deliberately left it out:

```php
'delete' => $request->user()->can('delete', $this->resource),
```

**Show-route only**, inside the same `$this->when($request->routeIs('tickets.show'), …)` closure. **Do not add it to the index** — Story 22's test asserting `can` is absent from the list must stay green.

### 4 — Route, and the auth test it breaks

**File: `backend/routes/api.php`**

After the `tickets.update` line Story 23 adds:

```php
Route::delete('/tickets/{ticket}', [TicketController::class, 'destroy'])->name('tickets.destroy');
```

Inside the `['auth:sanctum', 'active']` group, **outside** the `admin` group — the gate is `TicketPolicy`, not the `admin` middleware, exactly like `categories.destroy`.

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

Add `'tickets.destroy' => 'admin-policy'` to `ACCESS` (**line 16**). Then **fix `test_agent_refused_by_policy_admin_routes` (lines 43–50), which this classification would otherwise break.** It currently creates a real `Category` and passes its id; do the same for a ticket:

```php
public function test_agent_refused_by_policy_admin_routes(): void
{
    $token = $this->tokenFor(User::factory()->agent()->create());
    $category = Category::query()->create(['name' => 'Test', 'slug' => 'test']);
    $ticket = Ticket::factory()->create();
    foreach ($this->routesFor('admin-policy', $category->id, $ticket->id) as $route) {
        $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden();
    }
}
```

- **Measured: without a real ticket id this test fails with `404`, not `403`** — binding resolves before the policy. This is the second time a parameterised route has forced a change here (Story 22 added the `{ticket}` parameter to `routesFor()`), and it is further reason Story 19's ban on looping `test_agent_reaches_staff_routes` still stands.
- `test_policy_admin_routes_have_no_admin_middleware` (**60–65**) then asserts `tickets.destroy` carries no `admin` middleware — it passes as long as the route is registered outside the admin group. **Do not "fix" that by adding the middleware**; policy-gating is the pattern here.
- **`Ticket::factory()`** comes from Story 19's task 6; add the `use App\Models\Ticket;` import.

### 5 — Document it

**File: `docs/api-contract.md`**

Add after the `PATCH /api/v1/tickets/{ticket}` row:

```markdown
| `DELETE` | `/api/v1/tickets/{ticket}` | Soft delete. Admin only. | admin bearer (TicketPolicy) | TM-28 |
```

And a subsection:

```markdown
### `DELETE /api/v1/tickets/{ticket}`

Admin only, via `TicketPolicy::delete` — **not** the `admin` middleware, so an
agent hitting an existing ticket gets `403` while a non-existent or
already-deleted one gets `404` for everyone (binding resolves first).

Returns `204` with an empty body. The delete is a soft delete: `deleted_at` is
set, the row and its `ticket_activities` rows are kept, and the ticket disappears
from every list, filter and search. One activity row is written with
`event = 'deleted'`, `user_id` set to the actor, `created_at` as the time, and
`meta` snapshotting the `reference` and `subject`.

There is no restore or purge endpoint. `forceDelete()` is never called: the
`ticket_activities.ticket_id` foreign key is `ON DELETE CASCADE`, so a hard
delete would erase the audit trail. Recovery is an operator-level
`deleted_at = NULL` update until a story adds an endpoint.
```

---

## Frontend Tasks

### 6 — The request

**File: `frontend/src/api/tickets.ts`**

```ts
export async function deleteTicket(id: number): Promise<void> {
  await client.delete(`/tickets/${id}`)
}
```

Returns nothing — the endpoint is `204`. Add `delete: boolean` to `TicketPermissions` (Story 22's interface).

### 7 — Store action

**File: `frontend/src/stores/tickets.ts`**

```ts
const deleting = ref(false)

async function removeTicket(id: number): Promise<void> {
  deleting.value = true
  try {
    await deleteTicket(id)
    current.value = null
    // The list is now stale by one row. Reload only if it has been loaded.
    if (meta.value !== null) await load()
  } finally {
    deleting.value = false
  }
}
```

- **Do not catch** — the dialog needs the error to render it, as `CategoryDeleteDialog.vue:13` does.
- **`current.value = null`** so the detail view cannot keep rendering a ticket that no longer exists while the redirect is in flight.
- **Reload the list only when `meta` is non-null**, i.e. the list has actually been fetched. Calling `load()` unconditionally would fire a request for a list the user has never opened.
- Name it `removeTicket`, matching `stores/categories.ts`'s `remove()` rather than inventing `destroy`.

### 8 — Toolbar gains a live Delete button

**File: `frontend/src/components/TicketActionToolbar.vue`** (Story 22's)

Add a fifth button — **rendered only when `ticket.can.delete`, and enabled**, unlike the three that still belong to TM-31, TM-38 and TM-41:

```html
<button data-testid="action-delete" v-if="ticket.can.delete" @click="emit('delete')">Delete</button>
```

Add `const emit = defineEmits<{ delete: [] }>()`. The toolbar **does not** open the dialog itself — the detail view owns that state, so the toolbar stays a presentational component.

**Story 22's toolbar test must be updated again**: after Story 23 it exempts `action-edit` from "every button is disabled"; now it must also exempt `action-delete` and assert the button is absent for an agent.

### 9 — Confirmation dialog

**Create file:** `frontend/src/components/TicketDeleteDialog.vue`

Follows `CategoryDeleteDialog.vue`'s shape.

```ts
const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ deleted: []; close: [] }>()
const store = useTicketsStore()
const error = ref('')

async function confirmDelete(): Promise<void> {
  try {
    await store.removeTicket(props.ticket.id)
    emit('deleted')
  } catch (reason) {
    error.value = errorMessage(reason)
  }
}
```

**AC4 is about the copy**, so it must name the reference explicitly:

```html
<p data-testid="ticket-delete-prompt">
  Delete ticket {{ ticket.reference }} — “{{ ticket.subject }}”? It will be
  removed from the queue but kept recoverable.
</p>
```

Test ids: `ticket-delete-prompt`, `ticket-delete-confirm`, `ticket-delete-cancel`, `ticket-delete-error`.

- **The reference must be in the prompt text**, not only in a heading — the test asserts `wrapper.get('[data-testid="ticket-delete-prompt"]').text()` contains the reference.
- **`:disabled="store.deleting"`** on the confirm button, so a double-click cannot fire two requests. (Even if it did, the second is a harmless `404` — measured — but the UI should not invite it.)
- **Cancel emits `close` and does nothing else.** No request on cancel.

### 10 — Wire it into the detail view

**File: `frontend/src/views/TicketDetailView.vue`** (Story 22's)

```ts
const confirming = ref(false)

async function onDeleted(): Promise<void> {
  confirming.value = false
  await router.replace({ name: 'tickets' })
}
```

Render `<TicketDeleteDialog v-if="confirming && store.current" :ticket="store.current" @deleted="onDeleted" @close="confirming = false" />`, and set `confirming = true` from the toolbar's `@delete`.

- **`router.replace`, not `push`** — the deleted ticket's URL must not sit in history, because going back to it would only render the not-found state.
- **Redirect to the list, not to `/`** — the admin was working through a queue.

### 11 — Formatting

```bash
cd frontend && npx prettier --write src/api/tickets.ts src/stores/tickets.ts src/components/TicketActionToolbar.vue src/components/TicketDeleteDialog.vue src/views/TicketDetailView.vue src/components/TicketDeleteDialog.spec.ts src/components/TicketActionToolbar.spec.ts src/stores/tickets.spec.ts src/views/TicketDetailView.spec.ts
```

Format only what you touch, as in Stories 19–23.

---

## Edge Cases & Failure Modes

- **An agent calling `DELETE` on an existing ticket** → `403` from `TicketPolicy::delete()`. Measured.
- **Anyone calling `DELETE` on a non-existent or already-deleted ticket** → `404`, because binding resolves before the policy and excludes trashed rows. Measured for both. A double-submit is therefore harmless.
- **The activity row survives the delete.** Measured: a soft delete leaves existing rows intact, and a new row can be inserted for an already-soft-deleted ticket. The `cascadeOnDelete` FK only fires on a real `DELETE`.
- **`forceDelete()` erases the trail.** Measured 1 → 0 activity rows. It appears nowhere in this story, and a future purge story must widen the FK strategy before using it.
- **A soft-deleted ticket must vanish from the list, filters and search.** Free from `SoftDeletes`: Story 19 measured `deleted_at is null` in both the paginator's count and page queries, and Story 21 measured it inside the fulltext subquery too. Tests 6–9 pin it rather than assume it.
- **`meta.total` must drop as well as `data`.** A test that only counts rows in `data` would pass while the pagination footer still claimed the old total.
- **The deleted ticket's requester, category and priority are untouched.** Soft-deleting a ticket must not affect master data or the requester record; nothing in the code path touches them, and test 11 asserts the requester still exists.
- **A soft-deleted ticket still blocks its category from being deleted cleanly.** `CategoryController::destroy()` counts `$category->tickets()->withTrashed()->count()` (**line 65**) — deliberately including trashed ones, from Story 15. So deleting a ticket does **not** unblock its category. That is existing, correct behaviour; do not change it.
- **The reference stays occupied.** `tickets.reference` is `char(15) unique` and the soft-deleted row keeps its value. Harmless — references come from the `ticket_sequences` allocator, which never reissues.
- **`destroy()` without `DB::transaction`** would leave a deleted ticket with no activity row, and **no test in the suite would notice** — `transactionLevel` is `1` under `RefreshDatabase`, so the recorder's guard is inert and both writes succeed alone. Test 13 forces a failure between them.
- **Deleting while the detail page is open** clears `current` before the redirect, so the view cannot render a ticket that is gone. Without that, a slow `router.replace` leaves stale content on screen.
- **Deleting when the list has never been loaded** must not fire a list request. The `meta.value !== null` guard in task 7 covers it.
- **Cancelling the dialog** issues no request and leaves the ticket untouched.
- **AC2's "statistics" clause has no code yet.** TM-29 (dashboard with queue statistics) is unplanned. Its counts must use the default scope so trashed rows are excluded automatically; **record this as a constraint TM-29 must honour**, and do not build a statistics query here.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketDeleteTest.php` (new; `RefreshDatabase` + `$this->seed()`)

> **Tests 8 and 9 depend on Stories 20 and 21.** By `NN` order both land first. If the team resequenced and they have not, drop those two and note that **TM-24 and TM-25 must add them** — do not weaken them into list-only assertions.

1. `test_unauthenticated_request_is_rejected` — `401`.
2. `test_agent_is_forbidden` — **AC1.** Agent token on a real ticket → `403`, and the ticket is **not** deleted.
3. `test_admin_can_soft_delete` — **AC1.** `204` with an empty body.
4. `test_delete_is_soft_not_hard` — after the call, `Ticket::find($id)` is `null` **and** `Ticket::withTrashed()->find($id)` is not, with `deleted_at` non-null.
5. `test_writes_one_deleted_activity_row` — **AC3.** Exactly one new row with `event = 'deleted'`, `user_id` equal to the admin's id, a non-null `created_at`, and `meta.reference` / `meta.subject` matching the ticket.
6. `test_pre_existing_activity_rows_survive` — a ticket with a `created` row keeps it after deletion. Pins the soft-vs-hard distinction at the FK level.
7. `test_excluded_from_the_list` — **AC2.** `GET /api/v1/tickets` omits it **and** `meta.total` drops by one.
8. `test_excluded_from_filters` — **AC2.** With `?status_id[]=<its status>` it is absent. *(Story 20.)*
9. `test_excluded_from_search` — **AC2.** A `?q=` term that matched its subject before the delete returns nothing after. *(Story 21. Use `DatabaseTruncation` per Story 21's rule if the assertion goes through `MATCH`.)*
10. `test_detail_route_returns_404_after_deletion` — `GET /api/v1/tickets/{id}` → `404`.
11. `test_requester_and_master_data_are_untouched` — the requester, category, priority and status rows all still exist.
12. `test_deleting_twice_returns_404` — the second `DELETE` → `404`, and no second activity row.
13. `test_delete_is_atomic` — bind a throwing `ActivityRecorder` and assert the ticket is **not** deleted. Verification step 4 removes `DB::transaction` to watch this fail.
14. `test_missing_ticket_returns_404_for_admin_and_agent_alike` — both `404`, documenting that binding beats policy.
15. `test_can_delete_is_true_for_admin_and_false_for_agent` — on `GET /api/v1/tickets/{id}`, `data.can.delete` per role.
16. `test_can_delete_is_absent_from_the_list` — `GET /api/v1/tickets` still has no `can` key at all. Keeps Story 22's guard intact.
17. `test_soft_deleted_ticket_still_blocks_its_category` — delete a ticket, then `DELETE /api/v1/categories/{its category}` → still the `422` reassignment response, because Story 15 counts `withTrashed()`. Pins existing behaviour this story must not change.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

18. `test_agent_refused_by_policy_admin_routes` — **now creates a real ticket and passes its id.** Measured: without that it fails with `404` instead of `403`.
19. `test_policy_admin_routes_have_no_admin_middleware` — green with `tickets.destroy` added, confirming it is policy-gated.
20. `test_every_api_route_is_classified` / `test_every_classified_route_exists` — green.

### Frontend

21. **`frontend/src/stores/tickets.spec.ts`** (extend) — `removeTicket` calls the API and clears `current`; it reloads the list when `meta` is set; it does **not** reload when `meta` is `null`; a rejection re-throws and still clears `deleting`. **4 tests.**
22. **`frontend/src/components/TicketDeleteDialog.spec.ts`** (new) — **the prompt text contains the ticket reference** (AC4); Confirm calls `removeTicket` and emits `deleted`; Cancel emits `close` and issues **no** request; an API error renders `ticket-delete-error` and does **not** emit `deleted`; Confirm is disabled while `store.deleting`. **5 tests.**
23. **`frontend/src/components/TicketActionToolbar.spec.ts`** (modify) — `action-delete` renders and is **enabled** when `can.delete`; it is **absent** when `can.delete` is false; clicking it emits `delete`; `action-assign`/`action-status`/`action-escalate` are still disabled. **4 tests.**
24. **`frontend/src/views/TicketDetailView.spec.ts`** (extend) — the toolbar's `delete` event opens the dialog; the dialog's `deleted` event triggers `router.replace` to `tickets`; `close` hides the dialog without navigating. **3 tests.**

---

## Verification Steps

1. **Story 22 is done.** `php artisan test --filter='TicketShowTest|TicketPolicyTest'` passes. **If not, stop.**
2. **Backend formats and passes:** from `backend/`, `./vendor/bin/pint --test`, then `composer test`. Expect **+20 tests** over your measured baseline, with only TM-14's and TM-21's known failures red.
3. **The new class alone:** `php artisan test --filter=TicketDeleteTest`.
4. **Prove the transaction is load-bearing.** Remove the `DB::transaction(...)` wrapper in `destroy()` and run `--filter=test_delete_is_atomic`. It must **fail**, showing the ticket deleted with no activity row. Restore it. Measured: `transactionLevel` is `1` under `RefreshDatabase`, so **nothing else in the suite will notice the wrapper is gone.**
5. **Prove the auth-test fix was necessary.** Revert `test_agent_refused_by_policy_admin_routes` to pass the default id (drop the `$ticket->id` argument) and run it. It must **fail with `404` where `403` was expected**. Restore the real ticket.
6. **Prove `forceDelete` is the wrong tool**, so nobody swaps it in later:
   ```bash
   php artisan tinker --execute="\$t = App\Models\Ticket::first(); \
     echo App\Models\TicketActivity::where('ticket_id', \$t->id)->count(), ' -> '; \
     \$t->forceDelete(); \
     echo App\Models\TicketActivity::where('ticket_id', \$t->id)->count(), PHP_EOL;"
   ```
   Expect `N -> 0`. **Run this against the test database, then `php artisan migrate:fresh --seed` to restore it.**
7. **Backend by hand.** With an **agent** token: `DELETE …/tickets/<real id>` → `403`; the ticket is still in `GET …/tickets`. With an **admin** token:
   - `DELETE …/tickets/<id>` → `204` empty body.
   - `GET …/tickets/<id>` → `404`. `GET …/tickets` → absent, and `meta.total` down by one.
   - `DELETE …/tickets/<id>` again → `404`.
   - `SELECT event, user_id, created_at, meta FROM ticket_activities WHERE ticket_id = <id>` → the original `created` row **plus** one `deleted` row with the admin's id and `meta.reference`.
   - `SELECT deleted_at FROM tickets WHERE id = <id>` → non-null.
   - `GET …/tickets/<another id>` → `data.can.delete` is `true` as admin, `false` as agent.
8. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npx prettier --check` on task 11's list, `npm test` — **+16 tests across 1 new file**.
9. **Frontend by hand:** `npm run dev`.
   - As an **agent**, open a ticket → **no Delete button**.
   - As an **admin**, open a ticket → Delete is present and enabled; Edit is enabled (Story 23); Assign, Change status and Escalate are still greyed out.
   - Click Delete → the prompt **names the reference and the subject**. *(AC4.)*
   - Click **Cancel** → nothing happens, the ticket is still there.
   - Click Delete → Confirm → you land on `/tickets`, and the ticket is gone from the table.
   - Press **Back** → you do **not** return to the deleted ticket's page. *(The `replace`.)*
   - Paste the deleted ticket's URL → the not-found panel from Story 22.
10. **Regression:** filter and search the list and confirm the deleted ticket appears in neither. Then `DELETE /api/v1/categories/{the deleted ticket's category}` as an admin → still the `422` reassignment response, proving Story 15's `withTrashed()` count is intact.

---

## Done Criteria

- [ ] `DELETE /api/v1/tickets/{ticket}` returns `204` for an admin and `403` for an agent, gated by `TicketPolicy::delete` and **not** by the `admin` middleware.
- [ ] The delete is soft: `deleted_at` is set, the row survives under `withTrashed()`, and `forceDelete()` appears nowhere.
- [ ] Pre-existing activity rows survive the delete.
- [ ] Exactly one `event = 'deleted'` row is written, carrying the actor's `user_id`, a `created_at`, and `meta.reference` / `meta.subject`.
- [ ] The ticket is absent from the list (**and `meta.total` drops**), from filters, and from search.
- [ ] `GET /api/v1/tickets/{id}` returns `404` after deletion, and a second `DELETE` returns `404` with no second activity row.
- [ ] A non-existent ticket is `404` for admin and agent alike.
- [ ] The delete is atomic — proven by a test that fails when `DB::transaction` is removed.
- [ ] `can.delete` is present on the detail route, correct per role, and **still absent from the list**.
- [ ] `tickets.destroy` is classified `admin-policy`, and `test_agent_refused_by_policy_admin_routes` passes a **real** ticket id — proven necessary by reverting it.
- [ ] The requester and all master data are untouched, and a soft-deleted ticket **still** blocks its category's deletion.
- [ ] `TicketActivityEvent::Deleted` is appended; no migration was added.
- [ ] The SPA shows a Delete button to admins only, and the confirmation prompt **names the ticket reference**.
- [ ] Confirming redirects to `/tickets` with `replace`, so Back does not return to the deleted ticket; cancelling issues no request.
- [ ] `docs/api-contract.md` documents the endpoint, the `403`-vs-`404` rule, the activity row, and why there is no restore or purge endpoint.
- [ ] Stories 19–23's suites pass unchanged apart from the two deliberately updated assertions (`RouteAuthorizationTest`'s policy-admin loop and Story 22's toolbar test).
- [ ] `pint --test`, `lint`, `typecheck` clean; **+20 backend and +16 frontend tests** over the measured baseline.
- [ ] No new dependency, no migration, no restore endpoint, no purge endpoint, and no delete control on the list view.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 25 (TM-29, dashboard with queue statistics).**
