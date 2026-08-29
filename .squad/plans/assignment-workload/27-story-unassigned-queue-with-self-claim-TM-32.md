# Story 27 — Unassigned queue with self-claim (Story: TM-32)

## Prerequisites

- **Story 22 (TM-26) — LANDED.** `TicketPolicy` has all eight abilities (`backend/app/Policies/TicketPolicy.php`), the show-route `can` block is in `TicketResource` (**line 28**), `tickets.show` is routed (`backend/routes/api.php:45`), `routesFor()` already resolves `{ticket}` (`RouteAuthorizationTest.php:122`), and the SPA has `TicketDetail`, `loadTicket()`, `TicketDetailView.vue` and `TicketActionToolbar.vue`. **Everything this story hangs off already exists.**
- **Stories 19 (TM-23), 20 (TM-24) and 25 (TM-29) own AC1 in full, and they precede this story by `NN`.** The unassigned filter is Story 20's `assigned_to=unassigned` sentinel and its `filter-assignee` select; the one-click dashboard entry point is Story 25's `stat-unassigned` card linking to `{ name: 'tickets', query: { assignee: 'unassigned' } }`. Story 25's plan says so explicitly — its line 40 justifies keeping `unassigned` queue-wide *"because E5-S2 (self-claim) is built on exactly this."* **This story writes no filter code and no dashboard code.** See task 1: if the team resequenced and any of the three is missing, **AC1 is deferred and recorded, not faked.**
- **Story 26 (TM-31) — PLANNED, NOT IMPLEMENTED, and this story does not depend on it.** They share `TicketActivityEvent`, `TicketController`, `TicketResource`'s `can` block, `TicketActionToolbar.vue` and `RouteAuthorizationTest`, but no code path here calls the assign endpoint and no task here needs `TicketActivityEvent::Assigned`. **Rebase before you start** and place new members after whatever Story 26 already added. Read [`26-story-assign-a-ticket-to-an-agent-TM-31.md`](26-story-assign-a-ticket-to-an-agent-TM-31.md) anyway — its "active agent" invariant is the one this story must not break.
- **Story 19 owns `TicketFactory` and `RequesterFactory`, which this story's tests need.** `backend/database/factories/` holds only `UserFactory.php` and `backend/tests/Feature/Tickets/` does not exist as of writing. Task 9 creates both from Story 19's task 6 **only if they are still absent**.
- **No new composer or npm dependency, and no migration.** `tickets.assigned_to` is already nullable with the composite index `tickets_assigned_to_status_id_index` (`…_create_tickets_table.php:23, 38`), and `ticket_activities.event` is `varchar(50)` (`…_create_ticket_activities_table.php:15`).
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

An agent picks work out of the queue without waiting to be told, and two agents reaching for the same ticket get a straight answer instead of a silent overwrite.

1. `POST /api/v1/tickets/{ticket}/claim` assigns an **unassigned** ticket to the calling agent.
2. A ticket someone else already holds returns **`409`** with a message naming the holder — never a silent overwrite (AC3).
3. One `claimed` activity row is attributed to the **claiming agent**, not to an admin.
4. The detail page shows a **Claim** button on unassigned tickets, to agents only, and renders the conflict message in place.
5. AC1's unassigned filter and its one-click dashboard card are **verified**, not rebuilt.

**Not in scope.** **No claim control on the list view** — the detail page is the only entry point, matching Story 24's rule for delete. No un-claim, no give-back, no `reason` field: releasing a ticket is **TM-34**'s unassign. No notification to anyone — **TM-34** owns the first notification in this epic and E8 owns mail. No "my tickets" route (**TM-33**), no workload figures (**TM-35**), no round-robin or auto-assignment anywhere. **`TicketPolicy::assign()` is not touched** — see the decision below.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| Agent claims an unassigned ticket | No endpoint | `200`, `assigned_to` = the caller, one `claimed` activity row |
| Agent claims a ticket **another** agent holds | — | **`409`** naming the holder; the row is **untouched**, `updated_at` included (AC3) |
| Agent claims a ticket **they** already hold | — | `200`, **no** activity row, `updated_at` untouched |
| **Admin** calls claim | — | `403`. Admins assign through TM-31's endpoint; the assignee invariant is "active **agent**" |
| Inactive agent calls claim | — | `401` from the `active` middleware, before the policy runs |
| Ticket does not exist, or is soft-deleted | — | `404` from route-model binding, for every role |

---

## Decision — a separate `claim` ability, and why `assign()` stays admin-only

**Stories 22 and 26 both anticipated that this story would relax `TicketPolicy::assign()`. It does not, and that anticipation is superseded here.** Story 22's docblock on `assign()` says *"E5-S2 … must relax this so an agent can claim a ticket where `assigned_to` is null"*, and Story 26 repeats it. Implement it that way and **AC3 becomes unreachable**, for a concrete reason:

A policy failure renders as `403` with the body `{"message": "This action is unauthorized."}` — the exact string `RouteAuthorizationTest.php:40` asserts. So the moment the precondition *"the ticket is unassigned"* lives inside `assign()`, a contested claim returns that message. AC3 asks for *"a clear conflict message rather than silently overwriting"*; `403 This action is unauthorized.` is neither clear nor about the conflict. **Any precondition encoded in a policy can only ever produce a 403.** To answer with a `409` and a holder's name, the request must pass authorization and be judged on state inside the handler.

So this story adds:

- **`TicketPolicy::claim()` — a pure role gate**, `! $user->isAdmin()`. No state, no `assigned_to`.
- **`POST /tickets/{ticket}/claim`** — its own route, its own handler, its own `409`.
- **`assign()` unchanged at `$user->isAdmin()`.** TM-31's endpoint stays the admin surface and TM-34 extends *it* with unassign. Two verbs, two audiences, two idempotency rules: an admin's reassign is last-write-wins (`200`, TM-31's AC5), an agent's claim is first-write-wins (`409`). Collapsing them into one handler means branching every rule on `$user->isAdmin()`.

**Do not "simplify" this later by merging the two endpoints.** Put that sentence in the PR description, and correct the two stale docblock notes on `assign()` while you are in the file (task 3).

---

## Decision — the role gate is in the policy, the state gate is in the resource

`can.claim` on the detail payload must be false on a ticket that is **already assigned** — otherwise the button invites a guaranteed `409`. But the endpoint's `authorize()` must **not** include that state check, or the contested claim 403s instead of 409-ing. One policy method cannot do both.

The split:

- **`TicketPolicy::claim()`** answers *"may this person claim things at all"* → `! $user->isAdmin()`. This is what `$this->authorize('claim', $ticket)` calls.
- **`TicketResource`'s `can.claim`** answers *"is there anything to claim here"* → `$request->user()->can('claim', $this->resource) && $this->assigned_to === null`.

The state half is a **UI affordance**, not an authorization rule, and it belongs where the payload is built. **Do not move `assigned_to === null` into the policy** — task 4's comment says so, and test 5 fails if someone does.

---

## Context — Read These Files First

1. `backend/app/Policies/TicketPolicy.php` — all eight abilities, one per method, `$user` unused in five of them (deliberate — Story 22's plan says do not remove the parameter). **`assign()` returns `$user->isAdmin()` and stays that way.** Task 3 adds `claim()` beside it.
2. `backend/app/Http/Controllers/Api/V1/TicketController.php` — `show()` at **23–27**, `store()` at **30–43**. `TicketActivityEvent`, `TicketResource`, `Ticket`, `ActivityRecorder`, `DB` and `JsonResponse` are already imported (**5, 8, 12, 13, 16, 18**). Task 4 needs `Illuminate\Http\Request` and `App\Models\User` added.
3. `backend/app/Http/Controllers/Api/V1/CategoryController.php` — `destroy()` at **58–76** returns **different responses out of one `DB::transaction`** (`Response|JsonResponse`), and `reassignmentRequired()` at **79–84** is the precedent for **a hand-built error body with a real message**, not `abort()`. Task 4's `409` follows that shape for the reason measured below.
4. `backend/app/Services/ActivityRecorder.php` — `record()` (**13–19**) fills the five defaults; the `LogicException` at **26–28** requires a surrounding transaction. **Call `record()`, not `recordMany()`.**
5. `backend/app/Enums/TicketActivityEvent.php` — `Created` and `CategoryChanged` today. **Append only.** Stories 23, 24 and 26 each append one as well.
6. `backend/app/Http/Resources/V1/TicketResource.php` — **line 28** is the show-only `can` block. Task 5 adds one key to it. **Do not add `can` to the index** — Story 22's plan measured four Gate calls per row as the reason.
7. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` — `ACCESS` (**17**), `test_agent_refused_by_policy_admin_routes` (**44–51**, the real-fixture pattern), `test_admin_not_refused_by_admin_routes` (**68–74**), `routesFor()` (**122–125**, already takes `$ticketId`). Task 6 adds a **new** access level and the two tests that police it.
8. `backend/app/Http/Middleware/EnsureUserIsActive.php` — the `active` alias (`bootstrap/app.php:23`) that every authenticated route carries. **This is why the claim endpoint validates no `is_active`:** the caller is the assignee, and an inactive caller never reaches the handler. Contrast TM-31, which validates a *third party*.
9. [`26-story-assign-a-ticket-to-an-agent-TM-31.md`](26-story-assign-a-ticket-to-an-agent-TM-31.md) — its "active agent" decision and its `assigned` activity-row shape (`field`, stringified `old_value`/`new_value`, names in `meta`). This story's `claimed` row matches that shape so TM-45 can render both from one template.
10. [`../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md`](../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md) — **task 12's `stat-unassigned` card and its `{ name: 'tickets', query: { assignee: 'unassigned' } }` target**, plus its test 23, which already asserts the round trip hydrates `store.assignedTo`. **That is AC1.** Read it before writing a line of frontend code, so you do not build a second entry point.
11. [`../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md`](../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md) — **task 2** for the `unassigned` sentinel, **task 7** for `lib/ticketQuery.ts`'s short URL keys, **task 11** for the `filter-assignee` select that offers Unassigned to agents. Also the trap it records: a link to `?assigned_to=unassigned` (the **API** name) silently produces an unfiltered list, because `fromQuery` ignores unknown keys.
12. `frontend/src/api/errors.ts` — `isUnauthorized`, **`isNotFound` (line 5)**, `validationErrors`, `errorMessage`. `errorMessage` falls through to `data.message` for any status it does not special-case, so **a `409` already surfaces the server's sentence with no change**. Task 10 adds `isConflict` beside `isNotFound`.
13. `frontend/src/stores/tickets.ts` — `loadTicket()` and the four detail refs. Task 11 adds two refs and one action, and **the ordering inside it is load-bearing** — see the task.
14. `frontend/src/components/TicketActionToolbar.vue` — 2 lines, four buttons. Story 26 enables the Assign one; this story adds a fifth.

---

## Measured facts that decide these tasks

Measured this session against **`mysql:8.4` (`tm-mysql-test`, 3307)** through a throwaway `RefreshDatabase` test. Do not re-derive them.

- **One conditional `UPDATE` settles the race, with no lock and no retry.** `Ticket::query()->whereKey($id)->whereNull('assigned_to')->update(['assigned_to' => $me])` returned **1** for the first caller and **0** for the second, and the row still held the **first** caller's id. **The affected-row count *is* the answer to "did I win?"** — this is why task 4 needs neither `lockForUpdate()` nor the read-then-write that TM-31 requires. TM-31's assign endpoint locks because it must report the *previous* assignee truthfully; a claim's previous assignee is always `null`.

- **A losing claim touches nothing.** Measured with `updated_at` pinned to `2020-01-01 00:00:00`: the losing update returned **0** and `updated_at` was **still `2020-01-01 00:00:00`**. So AC3's *"rather than silently overwriting"* is provable, and test 4 proves it.

- **The Eloquent builder's `update()` bumps `updated_at`; `DB::table()`'s does not.** Measured against the same pinned value: via `Ticket::query()` it became `2026-08-26 13:33:28`; via `DB::table('tickets')` it stayed `2020-01-01 00:00:00`. **Use `Ticket::query()`.** `DB::table()` would also bypass the soft-delete scope — see the next fact.

- **`Ticket::query()->whereKey(...)` excludes soft-deleted rows.** Measured on a trashed ticket: the conditional update matched **0** rows, while `Ticket::withTrashed()->whereKey(...)->whereNull('assigned_to')->count()` was **1**. Route-model binding already returns `404` first, so this is belt-and-braces — but it is the reason `DB::table()` is forbidden.

- **The in-memory model is stale after a builder update.** Measured: `$ticket->assigned_to` was still `NULL` while the database held the new id. **`->fresh()` before serialising is mandatory**, not cosmetic.

- **A builder update fires no model events; `save()` fires one.** Measured with an `updated` listener: **0** after `Ticket::query()->...->update(...)`, **1** after `$model->save()`. Nothing observes `Ticket` today, so this changes no behaviour — **but E8's notification stories (TM-42 onward) must not hook the `updated` model event to detect assignment changes, because claims would be invisible.** Record that in the PR description; it is a trap laid for a later sprint.

- **`abort(409, $message)` leaks a full stack trace in this project's test and local environments.** Measured through a temporary route: the body came back with `message`, plus `exception`, `file`, `line` and a ~50-frame `trace`, because `APP_DEBUG` is true. `response()->json([...], 409)` returned exactly `{"message":"…","assignee":{"id":7,"name":"Omar"}}`. **Task 4 builds the body by hand** — the same reason `HealthController` gates its probe messages on `app.debug`, and the same shape `CategoryController::reassignmentRequired()` already uses.

- **A repeat claim by the current holder also returns `affected = 0`.** Measured. So the handler **cannot** treat 0 as "conflict" — it must re-read the holder and compare with the caller. Without that, an agent double-clicking Claim gets a `409` naming themselves. Test 3 catches it.

- **The response costs 7 queries.** `fresh()->load(['requester','category','priority','status','assignee','creator','escalatedBy'])` on a claimed ticket: **7**. A claim always ends up assigned, so unlike TM-31's endpoint there is no 6-vs-7 fixture trap here.

---

## Task 1 — AC1: verify, do not rebuild

**Write no code for AC1.** Confirm the three pieces exist and that the round trip works:

```bash
cd frontend && grep -rn "stat-unassigned\|assignee: 'unassigned'" src/views/DashboardView.vue src/lib/ticketQuery.ts src/components/TicketFilterBar.vue
```

- **Story 25's `stat-unassigned` card** links to `{ name: 'tickets', query: { assignee: 'unassigned' } }` — one click from the dashboard.
- **Story 20's `filter-assignee` select** offers Unassigned to an agent, and `fromQuery` hydrates `store.assignedTo` from `?assignee=unassigned`.
- **Story 20's `assigned_to=unassigned`** sentinel on `GET /api/v1/tickets`.

Then add the one regression this story owns (test 20): mount `DashboardView`, click the card, and assert the resulting route **and** that the list requests `assigned_to=unassigned`. Story 25's test 23 asserts the store state; this asserts the API call, closing the last link.

**If any of the three is missing** because the team resequenced: **do not build a substitute entry point.** Ship AC2–AC4, and record in the PR description exactly which story owes AC1 and which test is therefore absent. A hand-rolled second link would be dead code the moment Story 25 lands.

---

## Backend Tasks

### 2 — One new event case

**File: `backend/app/Enums/TicketActivityEvent.php`**

Append. **Do not reorder or rename.**

```php
case Claimed = 'claimed';
```

**A distinct case, not `Assigned` with a flag.** The two are different sentences in TM-45's timeline — *"Nadia claimed this ticket"* versus *"the admin assigned it to Nadia"* — and the alternative encoding, *"`Assigned` where `user_id === new_value`"*, is an implicit rule that rots the first time an admin assigns a ticket to themselves. It also keeps TM-31's and TM-35's `event = 'assigned'` queries counting only real assignments. `event` is `varchar(50)`: **no migration.**

### 3 — `TicketPolicy::claim()`, and two stale comments to correct

**File: `backend/app/Policies/TicketPolicy.php`**

Add after `assign()`:

```php
/**
 * Pure role gate — deliberately stateless. The "is it unassigned" half lives in
 * TicketResource's `can.claim`, because a precondition in a policy can only
 * produce `403 This action is unauthorized.`, and TM-32's criterion 3 requires a
 * `409` naming the current holder. Do not add `$ticket->assigned_to === null`
 * here; TicketClaimTest::test_contested_claim_is_a_conflict_not_a_forbidden
 * fails if you do.
 *
 * Admins are refused on purpose: the assignee invariant is "active agent"
 * (TM-31), and an admin assigns through POST /tickets/{ticket}/assign.
 */
public function claim(User $user, Ticket $ticket): bool
{
    return ! $user->isAdmin();
}
```

**`assign()` is not modified.** Replace its stale note — *"E5-S2 … must relax this … change it there, not here"* — with:

```php
/** TM-31's admin action. TM-32 did NOT relax this: self-claim is its own
 *  ability and its own endpoint, so an agent's conflict can be a 409 instead of
 *  a 403. TM-34 extends this endpoint with unassign. */
```

`$ticket` is unused in `claim()`. **Keep the parameter** — the ability is invoked with the model and the signature must accept it, exactly as `view()` and `update()` already do.

### 4 — `TicketController::claim()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

Add after `show()`. Add to the imports:

```php
use App\Models\User;
use Illuminate\Http\Request;
```

```php
public function claim(Request $request, Ticket $ticket, ActivityRecorder $recorder): JsonResponse
{
    $this->authorize('claim', $ticket);
    $claimant = $request->user();

    $holderId = DB::transaction(function () use ($ticket, $recorder, $claimant): int|false|null {
        // One conditional UPDATE settles the race. Measured: the winner gets an
        // affected count of 1 and every later caller gets 0, with the row and its
        // updated_at untouched — so no lockForUpdate and no retry loop. Must be
        // the *Eloquent* builder: DB::table() neither bumps updated_at nor
        // respects the soft-delete scope.
        $won = Ticket::query()->whereKey($ticket->getKey())
            ->whereNull('assigned_to')
            ->update(['assigned_to' => $claimant->getKey()]);

        if ($won === 0) {
            // Measured: a repeat claim by the current holder also returns 0, so 0
            // alone is not a conflict. Re-read to find out who actually holds it.
            $current = Ticket::query()->whereKey($ticket->getKey())->value('assigned_to');

            // `false` means "conflict, but the holder is unreadable" — only
            // reachable once TM-34 ships unassign and someone releases the ticket
            // between the update and this read.
            return $current === null ? false : ($current === $claimant->getKey() ? null : (int) $current);
        }

        $recorder->record($ticket->getKey(), TicketActivityEvent::Claimed, [
            'user_id' => $claimant->getKey(),
            'field' => 'assigned_to',
            // A claim only ever wins on an unassigned ticket, so old_value is
            // always null by construction. Do not read it off the row.
            'old_value' => null,
            'new_value' => (string) $claimant->getKey(),
            'meta' => ['to_name' => $claimant->name],
        ]);

        return null;
    });

    if ($holderId !== null) {
        return $this->claimConflict($holderId);
    }

    return TicketResource::make($ticket->fresh()->load([
        'requester', 'category', 'priority', 'status', 'assignee', 'creator', 'escalatedBy',
    ]))->response();
}

private function claimConflict(int|false $holderId): JsonResponse
{
    // Hand-built body, not abort(409, …): measured, abort() ships `exception`,
    // `file`, `line` and a ~50-frame `trace` whenever APP_DEBUG is true. Same
    // reason CategoryController::reassignmentRequired() builds its own.
    $holder = $holderId === false ? null : User::query()->whereKey($holderId)->first(['id', 'name']);

    return response()->json($holder === null ? [
        'message' => 'This ticket\'s assignment changed while you were claiming it. Reload and try again.',
    ] : [
        'message' => "{$holder->name} already claimed this ticket.",
        'assignee' => ['id' => $holder->id, 'name' => $holder->name],
    ], 409);
}
```

Six things that are load-bearing:

- **The three-way return is the whole design.** `null` = "I hold it now, or I already did" → `200`. An `int` = "someone else holds it" → `409` naming them. `false` = "nobody holds it and I still lost" → `409` telling the caller to reload. **Do not collapse `false` into `null`**, or a TM-34 race silently reports success.
- **`->fresh()` before `->load()`.** Measured: after the builder update the in-memory instance still reads `assigned_to === null`, so serialising `$ticket` directly would tell the SPA the claim failed.
- **`updated_at` moves only on a win**, because only the winning statement runs through the Eloquent builder. That is what makes test 4 a real assertion rather than a tautology.
- **No `is_active` validation.** The claimant is the assignee, and the `active` middleware (`bootstrap/app.php:23`) already rejected an inactive caller with `401`. Do not re-check it; do not copy TM-31's `exists` rule, which exists to vet a **third party**.
- **No form request.** The body is empty — `claim` takes no input, which is the point of a separate verb. **Do not accept an `assigned_to` field here**; that would recreate the assign endpoint with weaker rules.
- **The `409` carries `assignee`** so the SPA can name the winner even if its refetch fails. The success response deliberately has **no `can` block** — that is gated on `tickets.show` (`TicketResource.php:28`) — which is why task 11 re-reads the detail rather than splicing the body into `current`.

### 5 — `can.claim` on the detail payload

**File: `backend/app/Http/Resources/V1/TicketResource.php`**

Add one key inside the existing `can` closure (**line 28**):

```php
'claim' => $request->user()->can('claim', $this->resource) && $this->assigned_to === null,
```

- **The `&& $this->assigned_to === null` belongs here, not in the policy.** See the decision above. `assigned_to` is a column on the loaded model, so this costs **no** extra query.
- **Show route only.** The `can` block is already gated on `$request->routeIs('tickets.show')`; do not widen it.

### 6 — Route, and a new access level in the auth test

**File: `backend/routes/api.php`**

After the `tickets.show` line (**45**), inside the `['auth:sanctum', 'active']` group (opens **32**) and **outside** the `admin` group (opens **46**):

```php
Route::post('/tickets/{ticket}/claim', [TicketController::class, 'claim'])->name('tickets.claim');
```

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

`tickets.claim` is the first route in the project that an **agent** may reach and an **admin** may not, so it needs a level of its own.

1. Add `'tickets.claim' => 'agent-policy'` to `ACCESS` (**line 17**). Without it `test_every_api_route_is_classified` fails.
2. Add two tests, both with a **real** ticket id — an admin hitting id `999999` gets `404`, not `403`, because binding resolves before the policy:

```php
public function test_admin_refused_by_agent_policy_routes(): void
{
    $this->seed();
    $token = $this->tokenFor(User::factory()->admin()->create());
    $ticket = Ticket::factory()->create();
    foreach ($this->routesFor('agent-policy', 999999, $ticket->id) as $route) {
        $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden();
    }
}

public function test_agent_policy_routes_have_no_admin_middleware(): void
{
    foreach ($this->routesFor('agent-policy') as $route) {
        $this->assertNotContains('admin', Route::getRoutes()->getByName($route['name'])->gatherMiddleware());
    }
}
```

`Ticket` is already imported (**line 6**) and `routesFor()` already accepts `$ticketId` (**122**).

**Do not add `tickets.claim` to `staff`.** `test_agent_reaches_staff_routes` (**53–59**) is a hand-written list of `GET`s; a `POST` that mutates state does not belong in it.

### 7 — Document the endpoint

**File: `docs/api-contract.md`**

Add a row after the ticket rows:

```markdown
| `POST` | `/api/v1/tickets/{ticket}/claim` | An agent takes an unassigned ticket. `409` if someone else already holds it. | bearer (TicketPolicy) | TM-32 |
```

And a subsection:

```markdown
### `POST /api/v1/tickets/{ticket}/claim`

Empty body. Assigns the ticket to the **calling** agent. Gated by
`TicketPolicy::claim` — **agents only**; an admin gets `403` and assigns through
`POST /api/v1/tickets/{ticket}/assign` instead. There is no `is_active` check
because the caller is the assignee and the `active` middleware already rejected
an inactive account with `401`.

`200` returns the full ticket, the same shape as `GET /tickets/{ticket}` minus
the `can` block.

| Outcome | Status | Body |
|---|---|---|
| The ticket was unassigned | `200` | The ticket, now assigned to the caller |
| The caller already held it | `200` | The ticket, unchanged. **No** activity row, `updated_at` untouched |
| Another agent holds it | **`409`** | `{"message": "<name> already claimed this ticket.", "assignee": {"id", "name"}}` |
| It was released mid-request | `409` | `{"message": "This ticket's assignment changed while you were claiming it. Reload and try again."}` |
| Caller is an admin | `403` | `This action is unauthorized.` |
| No such ticket, or soft-deleted | `404` | Route-model binding, for every role |

A losing claim is a true no-op: `assigned_to`, `updated_at` and
`ticket_activities` are all left exactly as they were.

A winning claim writes one row: `event = 'claimed'`, `field = 'assigned_to'`,
`old_value` **always `null`** (a claim only wins on an unassigned ticket),
`new_value` the claimant's id, `meta.to_name` their name, and `user_id` the
claimant — **not** an admin. `claimed` is a separate event from `assigned` so the
timeline can say "claimed" rather than inferring it from equal ids.

**Note for the notification stories (TM-42 onward):** the claim is written with a
conditional `UPDATE`, which fires **no** Eloquent model events. Detect assignment
changes from `ticket_activities`, not from an `updated` observer.
```

Also add `claim` to the `TicketPolicy` ability list in `## Authorization` (**25–28**) if Story 26 has not already rewritten that paragraph.

---

## 8 — Nothing else changes on the backend

**No migration. No change to `Ticket`, `TicketActivity`, `User`, `ActivityRecorder`, `AssignTicketRequest`, `TicketController::assign()`, or any middleware.** State that in the PR description; three of those are files a reader will expect to see touched.

## 9 — The two factories, **only if Story 19 has not landed**

`ls backend/database/factories/`. If `TicketFactory.php` and `RequesterFactory.php` are there, **skip this task**. Otherwise create both **verbatim from [Story 19's task 6](../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md)** — not a variant. Two things not to re-derive: `TicketFactory` reads the **seeded** `priorities`/`statuses` rows because both tables reject a second default row at the storage layer (so **every test using it calls `$this->seed()`**), and `reference` uses a static counter because the column is `char(15)`. Record in the PR description that this story created them.

---

## Frontend Tasks

### 10 — The API call and one error predicate

**File: `frontend/src/api/errors.ts`**

Add beside `isNotFound` (**line 5**):

```ts
export function isConflict(error: unknown): boolean { return axios.isAxiosError(error) && error.response?.status === 409 }
```

**File: `frontend/src/api/tickets.ts`**

Add after `getTicket()`:

```ts
export interface ClaimConflict { message: string; assignee?: TicketStaff }
export async function claimTicket(id: number): Promise<Ticket> { const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/claim`, {}) ; return data.data }
```

- **`Ticket`, not `TicketDetail`** — the success body has no `can` block, and typing it as `TicketDetail` would be a lie the compiler cannot catch.
- **`{}` as the body, explicitly.** `client.post(url)` with no body sends no `Content-Type`; some proxies then mishandle the empty POST. One empty object costs nothing.
- **`ClaimConflict` is exported for the spec's mock**, not for the happy path. `errorMessage()` already reads `.message` off any status it does not special-case, so **no change to `errorMessage`**.

### 11 — The store action, and why its ordering matters

**File: `frontend/src/stores/tickets.ts`**

Add two refs and one action; import `claimTicket` and `isConflict`.

```ts
const claiming = ref(false)
const claimConflict = ref<string | null>(null)

async function claim(id: number): Promise<void> {
  claiming.value = true
  claimConflict.value = null
  try {
    await claimTicket(id)
    await loadTicket(id)
  } catch (caughtError) {
    if (!isConflict(caughtError)) throw caughtError
    const message = errorMessage(caughtError)
    // AC3: re-read FIRST so the toolbar drops the Claim button and the assignee
    // line names the winner, THEN set the message. loadTicket() clears
    // claimConflict (see below), so setting it before this await would wipe it.
    await loadTicket(id)
    claimConflict.value = message
  } finally {
    claiming.value = false
  }
}
```

And **one line inside `loadTicket()`**, next to where it clears `detailError`:

```ts
claimConflict.value = null
```

- **The clear-inside-`loadTicket` plus set-after-`loadTicket` ordering is the whole correctness of this action.** Clearing in `loadTicket` is what stops a stale conflict banner following the user to another ticket; setting after the await is what stops that same clear from eating the message they need to read. **Both, in that order.** Test 25 asserts the message survives; test 26 asserts navigation clears it.
- **Re-throw anything that is not a `409`.** A `403`, a `404` or a network failure is not a conflict and must not render in the conflict slot.
- Return `claiming`, `claimConflict` and `claim` from the store.

### 12 — The Claim button

**File: `frontend/src/components/TicketActionToolbar.vue`**

Add a fifth button and extend the emits type (Story 26 adds `assign` to it; add `claim` alongside):

```html
<button v-if="ticket.can.claim" data-testid="action-claim" :disabled="store.claiming" @click="emit('claim')">Claim</button>
```

- **Enabled, not `disabled`** — this story ships the handler, so Story 22's "disabled until its story arrives" rule is discharged for this button.
- **No `title`.** The placeholder titles exist only to explain an inert button.
- **`v-if="ticket.can.claim"` alone.** It is already false for admins and for assigned tickets (task 5), so **do not add a second `v-if` on `ticket.assignee`** — two sources of truth for one affordance.
- **Leave the other four buttons exactly as they are.** Edit, Change status and Escalate belong to TM-27, TM-38 and TM-41.

### 13 — Wire the detail view

**File: `frontend/src/views/TicketDetailView.vue`**

Bind the handler on the toolbar and render the conflict inside the `ticket-detail` article:

```html
<TicketActionToolbar :ticket="store.current" @claim="void store.claim(Number(route.params.id))" />
<p v-if="store.claimConflict" data-testid="ticket-claim-conflict">{{ store.claimConflict }}</p>
```

- **`data-testid="ticket-claim-conflict"`, separate from `ticket-error`.** A conflict is not a failed load: the page still shows a perfectly good ticket. Collapsing them would make the `409` indistinguishable from an unreachable API.
- **No router navigation and no manual refetch** — `store.claim()` already re-read the detail, so the assignee line and the button state both settle on their own.
- **No claim control on the list view.** Not asked for by any AC, and the detail page is this epic's single entry point for actions.

---

## Edge Cases & Failure Modes

- **Two agents claim simultaneously** → one `200`, one `409` naming the winner. Settled by the conditional `UPDATE`'s affected-row count in `claim()`; **measured**, first=1 second=0 with the row unchanged. No lock, no retry, no deadlock surface.
- **A losing claim must change nothing** → `assigned_to`, `updated_at` and the activity table are all untouched. **Measured** with `updated_at` pinned to 2020. Test 4.
- **An agent claims a ticket they already hold** → `200`, no activity row, `updated_at` untouched. The affected count is also `0` here (**measured**), which is why `claim()` re-reads the holder instead of treating `0` as a conflict. Test 3.
- **An admin calls claim** → `403 This action is unauthorized.` Enforced by `TicketPolicy::claim()`. The button never renders for them because `can.claim` is false. Test 5 and test 8.
- **An agent claims an assigned ticket via curl, bypassing the hidden button** → `409`, **not** `403`. This is the assertion that fails if someone moves the `assigned_to === null` check into the policy. Test 6, named for exactly that.
- **An inactive agent calls claim** → `401` from the `active` middleware, and their remaining tokens are revoked. Never reaches the policy. Covered by `Auth/ActiveAccountTest`; test 12 asserts it for this route.
- **The ticket does not exist, or is soft-deleted** → `404` for every role, from route-model binding. Also belt-and-braces: `Ticket::query()->whereKey(...)` matched **0** trashed rows (**measured**). Test 11.
- **The ticket is released between the update and the holder read** → `409` with the "assignment changed, reload" message. **Unreachable today** — no endpoint sets `assigned_to` back to `null` — and TM-34 is the story that makes it reachable. The `false` branch exists so TM-34 inherits a defined behaviour instead of a `TypeError` on `$holder->name`. Test 7 forces it by stubbing.
- **The claimant's own account is deleted mid-request** → unreachable; `UserPolicy::delete()` returns `false` for everyone (`UserPolicy.php:29–32`).
- **A stale conflict banner after navigating to another ticket** → cleared by the one line added to `loadTicket()`. Test 26.
- **A `403`, `404` or network error from the claim call** → re-thrown by the store, so it surfaces through the existing error path and **not** in `ticket-claim-conflict`. Test 27.
- **The refetch after a conflict fails** → the banner still renders, because the message is read from the `409` body before the refetch and the `assignee` object in that body carries the winner's name. `detailError` shows the refetch failure separately.
- **`?assignee=unassigned` naming a filter the API does not know** → Story 20's `fromQuery` ignores unknown keys, so a link written as `?assigned_to=unassigned` (the **API** name) silently yields an unfiltered list. Its plan records this; test 20 is what catches a regression.
- **An agent claims the last unassigned ticket** → the dashboard card's count is queue-wide and unscoped (Story 25's deliberate asymmetry), so it drops to 0 for everyone. Correct, and Story 25 tests it.

---

## Test Plan

### Backend — `backend/tests/Feature/Tickets/TicketClaimTest.php` (new; `RefreshDatabase` + `$this->seed()`)

Model the class on `RouteAuthorizationTest`'s `tokenFor()` helper (**117–120**). Create `tests/Feature/Tickets/` if Story 19 has not.

1. `test_unauthenticated_request_is_rejected` — no token → `401`.
2. `test_agent_claims_an_unassigned_ticket` — **AC2.** `200`, `assertJsonPath('data.assignee.id', $agent->id)`, and `assertJsonMissingPath('data.assignee.email')` (nesting `UserResource` would leak the staff directory — Story 18's constraint).
3. `test_claiming_a_ticket_you_already_hold_is_idempotent` — claim twice. Second call `200`; **exactly one** `claimed` row; `updated_at` unchanged (capture before, compare after). **The test that catches treating `affected === 0` as a conflict.**
4. `test_contested_claim_changes_nothing` — **AC3.** Agent A claims, then pin `updated_at` to a past value, then agent B claims → `409`; `assigned_to` is still A; **`updated_at` is still the pinned value**; still exactly one activity row. Assert `message` names A and `assignee.id` is A's id.
5. `test_admin_is_forbidden` — admin token, unassigned ticket → `403`.
6. `test_contested_claim_is_a_conflict_not_a_forbidden` — **the guard named in `TicketPolicy::claim()`'s docblock.** Agent B claims A's ticket and the status is `409`, **explicitly `assertStatus(409)` and not `assertForbidden()`**. Move `assigned_to === null` into the policy and this is the test that goes red.
7. `test_conflict_with_an_unreadable_holder_returns_a_reload_message` — force the `false` branch (bind a `Ticket` query stub, or claim then `DB::table('tickets')->update(['assigned_to' => null])` between the two calls) → `409` with the reload message and **no** `assignee` key.
8. `test_activity_row_is_attributed_to_the_claimant` — **AC4.** One row: `event = 'claimed'`, `user_id === $agent->id`, `field === 'assigned_to'`, `old_value` **null**, `new_value === (string) $agent->id`, `meta.to_name === $agent->name`.
9. `test_claim_does_not_write_an_assigned_row` — assert **zero** rows with `event = 'assigned'`, so TM-31's and TM-35's counts stay clean.
10. `test_response_shape_matches_show_minus_can` — the six relations plus `description` present; **`assertJsonMissingPath('data.can')`**.
11. `test_missing_and_soft_deleted_tickets_are_404_for_both_roles` — id `999999` and a trashed ticket, as agent **and** as admin. `Auth::forgetGuards()` between roles, as `RouteAuthorizationTest:109–115` does.
12. `test_inactive_agent_is_rejected_before_the_policy` — deactivate the agent, reuse their token → `401`, **not** `403`, and `assigned_to` unchanged.
13. `test_claim_is_atomic` — bind a throwing `ActivityRecorder`; assert the exception propagates **and** `assigned_to` is back to `null`. **Without this, dropping `DB::transaction()` passes every other test** — `transactionLevel` is `1` under `RefreshDatabase`, so `ActivityRecorder`'s own guard is inert.
14. `test_response_query_count_is_seven` — `DB::enableQueryLog()` around the request; assert the `load()` portion does not grow.

### Backend — `backend/tests/Feature/Authorization/RouteAuthorizationTest.php` (modified)

15. `test_every_api_route_is_classified` and `test_every_classified_route_exists` cover `tickets.claim` **automatically** once `ACCESS` has the entry.
16. `test_admin_refused_by_agent_policy_routes` and `test_agent_policy_routes_have_no_admin_middleware` — task 6. **Revert the real ticket id to `999999` and confirm the first fails with a `404`**, so the next reader understands why the fixture is there.

### Frontend — `frontend/src/components/TicketActionToolbar.spec.ts` (Story 22's file; modified, or created if absent)

17. `action-claim` renders when `can.claim` is true and clicking it emits `claim`.
18. `action-claim` is **absent** when `can.claim` is false, and absent for a payload where `can.claim` is false but `can.assign` is true (the admin shape).
19. The other four buttons are unchanged — Edit/Change status/Escalate still `disabled` with their titles. **Assert it explicitly**, or a later story silently enables them.

### Frontend — `frontend/src/views/DashboardView.spec.ts` (Story 25's file; extend)

20. **AC1's last link.** Click `stat-unassigned`, then assert the tickets list requests **`assigned_to=unassigned`** (mock `listTickets` and inspect the argument). Story 25's test 23 asserts the store state; this asserts the wire. **Skip and record if Story 25 has not landed.**

### Frontend — `frontend/src/stores/tickets.spec.ts` (new, or extend Story 19's)

21. `claim` calls `claimTicket` then `loadTicket`, and leaves `claimConflict` null on success.
22. `claiming` is true during the call and false after, including after a throw.
23. A `409` sets `claimConflict` to the server's message and does **not** re-throw.
24. A `403` re-throws and leaves `claimConflict` null.
25. On a `409`, `loadTicket` is called **before** `claimConflict` is set — assert the final `claimConflict` is non-null, which fails if the order is swapped, since `loadTicket` clears it.
26. `loadTicket` clears a pre-existing `claimConflict`.

### Frontend — `frontend/src/views/TicketDetailView.spec.ts` (Story 22's file; modified)

27. Clicking `action-claim` calls `store.claim` with the route's id.
28. `ticket-claim-conflict` renders `store.claimConflict` and is **absent** when it is null; `ticket-error` stays absent while a conflict is shown.

---

## Verification Steps

1. **Services:** `docker compose ps` → `tm-mysql-test` healthy on **3307**.
2. **Backend formats:** from `backend/`, `./vendor/bin/pint --test`.
3. **Backend tests:** from `backend/`, `composer test`, then `php artisan test --filter='TicketClaimTest|RouteAuthorizationTest'`. Expect **+16 backend tests** over the baseline at the time you start.
4. **Prove test 13 earns its place:** remove `DB::transaction(` and its closing from `claim()`, re-run `--filter=test_claim_is_atomic`, confirm it **fails**, restore.
5. **Prove the policy/resource split earns its place:** add `&& $ticket->assigned_to === null` to `TicketPolicy::claim()`, re-run `--filter=test_contested_claim_is_a_conflict_not_a_forbidden`, confirm it fails with a `403`, **restore**.
6. **Prove the `DB::table()` ban earns its place:** swap the conditional update to `DB::table('tickets')`, re-run `--filter=test_contested_claim_changes_nothing`, and confirm the `updated_at` assertion on the **winning** claim breaks. Restore.
7. **Backend by hand.** `php artisan serve`. With an **agent** token, on an unassigned ticket:
   - `POST …/tickets/<id>/claim` → `200`, `data.assignee.name` is you, **no `data.can`**.
   - The same call again → `200`, and `SELECT count(*) FROM ticket_activities WHERE ticket_id=<id> AND event='claimed'` is **still 1**.
   - `SELECT event, user_id, field, old_value, new_value, meta FROM ticket_activities WHERE ticket_id=<id>` → one `claimed` row, `user_id` you, `old_value` **NULL**, `meta.to_name` your name. **No `assigned` row.**
   - As a **second agent**: `POST …/tickets/<id>/claim` → **`409`**, body `{"message":"<first agent> already claimed this ticket.","assignee":{…}}` and **no `exception` or `trace` keys**. Re-check `assigned_to` and `updated_at` — both unchanged.
   - `POST …/tickets/999999/claim` → `404`. Soft-delete a ticket, claim it → `404`.
   - With an **admin** token: `POST …/tickets/<unassigned id>/claim` → `403 This action is unauthorized.`
   - `GET …/tickets/<unassigned id>` → `data.can.claim` is `true` as an agent, `false` as an admin. On an **assigned** ticket it is `false` for **both**.
   - Deactivate the agent, reuse their token → `401`.
8. **Frontend:** from `frontend/`, `npm run lint`, `npm run typecheck`, `npm test`. Expect **+12 frontend tests** across one new and three modified spec files. Then `npx prettier --check src/api/errors.ts src/api/tickets.ts src/stores/tickets.ts src/components/TicketActionToolbar.vue src/views/TicketDetailView.vue src/stores/tickets.spec.ts`.
9. **Frontend by hand:** `npm run dev`, signed in as an **agent**.
   - From the dashboard, click the **Unassigned** card → the list opens filtered, the URL reads `?assignee=unassigned`, and the assignee select shows **Unassigned**. *(AC1, one click.)*
   - Open one of those tickets → **Claim** is present and enabled; Edit, Change status and Escalate are still greyed out.
   - Click Claim → the assignee line becomes your name **with no page reload**, and the Claim button disappears.
   - Go back to the unassigned list and reload → the ticket you claimed is **gone from it**, and the dashboard's Unassigned count has dropped by one.
   - In a second browser profile as **another agent**, open a ticket the first agent holds → **no Claim button**. Then claim it by curl → `409`.
   - Reproduce the race in the UI: as agent B, open an **unassigned** ticket and leave the page open; claim the same ticket as agent A in the other profile; now click **Claim** as B → `ticket-claim-conflict` names A, the assignee line updates to A, the button vanishes, and **`ticket-error` does not appear**.
   - Navigate to a different ticket → the conflict banner is **gone**.
   - As an **admin**, open an unassigned ticket → **no Claim button** at all.
10. **Regression:** confirm `GET /api/v1/tickets/{ticket}` still returns the four original `can` keys plus `claim`, that Story 26's assign flow still returns `200` for an admin reassignment (last-write-wins is unchanged), and that `php artisan test --filter=TicketShowTest` is green.

---

## Done Criteria

- [ ] `POST /api/v1/tickets/{ticket}/claim` assigns an unassigned ticket to the calling agent and returns `200` with the full ticket minus `can`.
- [ ] An admin gets `403`; an inactive agent gets `401` from the middleware; a missing or soft-deleted ticket gets `404` for every role.
- [ ] A contested claim returns **`409`** with a message naming the current holder and an `assignee` object — **and no stack trace**, proven by asserting `exception` is absent from the body.
- [ ] A losing claim leaves `assigned_to`, `updated_at` **and** `ticket_activities` untouched.
- [ ] Claiming a ticket you already hold returns `200`, writes no row, and does not bump `updated_at`.
- [ ] Exactly one `event = 'claimed'` row per successful claim, `user_id` the **claimant**, `old_value` **null**, `meta.to_name` set — and **zero** `event = 'assigned'` rows.
- [ ] `TicketActivityEvent::Claimed` is **appended**; no migration.
- [ ] **`TicketPolicy::assign()` is unchanged**, `claim()` is a pure role gate with no `assigned_to` check, and the stale "E5-S2 must relax this" comment has been corrected.
- [ ] `can.claim` is `true` only for an agent on an unassigned ticket, lives on the show route only, and costs no extra query.
- [ ] The claim uses one conditional `UPDATE` through the **Eloquent** builder — no `lockForUpdate`, no retry loop, no `DB::table()`.
- [ ] The claim is atomic — proven by a test that fails when `DB::transaction` is removed.
- [ ] `tickets.claim` is classified **`agent-policy`**, the two new authorization tests pass, and the real-ticket-id fixture is proven necessary by reverting it.
- [ ] The detail page shows Claim to agents on unassigned tickets only, renders the conflict in `ticket-claim-conflict` (never `ticket-error`), and refreshes the assignee without a reload.
- [ ] `claimConflict` is cleared by `loadTicket` **and** survives the refetch that follows a `409` — both asserted.
- [ ] Non-`409` errors from the claim call are re-thrown, not shown as conflicts.
- [ ] **AC1 was verified, not rebuilt:** no filter code and no dashboard code was written, and the round-trip test asserts the list requests `assigned_to=unassigned`. If Stories 19/20/25 had not landed, the PR description records which owes AC1.
- [ ] `docs/api-contract.md` documents every outcome, the `409`-vs-`403` distinction, the activity row, and **the warning that a conditional `UPDATE` fires no model events** for TM-42 onward.
- [ ] No claim control on the list view, no un-claim, no reason, no notification, no new dependency, no migration.
- [ ] `pint --test`, `lint`, `typecheck` clean; **+16 backend and +12 frontend tests** over the measured baseline, with Story 22's and Story 25's spec files extended rather than duplicated.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 28 (TM-33, my tickets view).**
