# Story 56 — A third role, and ticket visibility scoped to it

## Prerequisites

- **Stories 06–12 (TM-8 … TM-14) are implemented.** `users.role` is a MySQL ENUM built from `UserRole::values()` (`backend/database/migrations/0001_01_01_000000_create_users_table.php:21`), `EnsureUserIsAdmin`/`EnsureUserIsActive` are registered, and `UserPolicy` is in place. This story widens all three, it does not re-create them.
- **Story 26 (TM-31) left this story a named decision.** Its plan says, verbatim: *"the first story to give an **agent** a named-colleague picker is the one that must revisit `/staff`."* Task 11 below is that revisit, and the answer is now **yes, add one** — because under the new rules a role-`user` account has to pick an agent and can never be allowed near `GET /api/v1/admin/users`.
- **Story 27 (TM-32) built self-claim; Story 58 removes it.** This story only *narrows* `TicketPolicy::claim()` so a role-`user` account cannot claim. **Do not delete the claim endpoint here** — [`58-story-agents-request-assignment-instead-of-claiming.md`](58-story-agents-request-assignment-instead-of-claiming.md) owns that.
- **This story deliberately breaks the SPA for staff.** After it lands, `POST /api/v1/tickets` returns `403` to an admin and to an agent, so `frontend/src/views/NewTicketView.vue` fails for every account that exists today. [`57-story-the-spa-learns-three-roles.md`](57-story-the-spa-learns-three-roles.md) repairs it and **must land in the same PR train**.
- **One migration, no new composer or npm dependency.** `ticket_activities.event` is `varchar(50)` (`backend/database/migrations/2026_08_26_084626_create_ticket_activities_table.php:15`), so no schema change is needed for activity rows.
- **Docker must be up.** `docker compose ps` → `tm-mysql-test` healthy on **3307**.

---

## Story Goal

The system stops having two kinds of account and starts having three, and the ticket queue stops showing everyone everything.

1. `UserRole` gains a third case, `user`, and `users.role` accepts it.
2. **Only a role-`user` account may create a ticket.** An admin or an agent posting to `/tickets` gets a `403`.
3. **Ticket visibility is scoped in the query, not in the client.** A user sees the tickets they created; an agent sees tickets assigned to them plus every unassigned ticket; an admin sees all.
4. A user may **optionally name an agent while creating** the ticket, and `GET /api/v1/agents` is the list they pick from.
5. An agent may work only the tickets they hold — status, escalation and edits are refused on someone else's ticket.

**Not in scope.** No SPA changes (**Story 57**). No assignment-request feature and no removal of `/tickets/{ticket}/claim` (**Story 58**). No change to escalation's target-selection, to the notification chain, to `status_transitions`, or to `Requester` as a table — a role-`user` account is still matched to a **`Requester` row by email**, which is what keeps the four existing notifications working untouched.

---

## Context — Read These Files First

1. `backend/app/Enums/UserRole.php` — 15 lines, two cases, and `values()` at **9–13** feeds the ENUM column. Read it together with item 2; the pair produces a measured surprise that decides task 2.
2. `backend/database/migrations/0001_01_01_000000_create_users_table.php:21` — `$table->enum('role', UserRole::values())->default(UserRole::Agent)`. **The column definition is derived at migrate time, not hardcoded.** Consequence measured below.
3. `backend/app/Policies/TicketPolicy.php` — all 70 lines. Seven of the nine methods `return true` today (**10–48**). This story rewrites six of them. Read the docblocks at **50–58** and **60–65** before touching `escalate()` and `addNote()` — both encode decisions that survive this story.
4. `backend/app/Http/Controllers/Api/V1/TicketController.php` — `index()` at **50–72** (the `->when()` filter chain that task 7 extends), `store()` at **340–367** (the `Requester::firstOrCreate` at **346** that task 9 rewrites), and `escalationTarget()` at **200–221**, which is **unchanged** but explains an edge case below.
5. `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php` — `rules()` at **58–74**. Note `'assigned_to' => ['prohibited']` at **72** and its message at **79**; task 8 inverts both.
6. `backend/app/Http/Requests/Api/V1/AssignTicketRequest.php:20` — `Rule::exists('users', 'id')->where('role', UserRole::Agent)->where('is_active', true)`. **Copy this rule verbatim** into `StoreTicketRequest`; do not invent a second spelling of "active agent".
7. `backend/app/Services/TicketStats.php` — `for()` at **14–31** and `scoped()` at **33–36**. `$scopeToSelf = ! $user->isAdmin()` at **16** is the exact line that becomes three-way, and `'scope' => $scopeToSelf ? 'own' : 'all'` at **25** is a **public API string** the SPA branches on (`frontend/src/views/DashboardView.vue:39`).
8. `backend/app/Models/User.php` — `isAdmin()` at **41–44**, `scopeActive()` at **46–50**, `role` cast at **36**. `#[Fillable]` at **19** deliberately **excludes `role`**; `Admin\UserController::store()` assigns it explicitly at **63**. Keep that.
9. `backend/app/Models/Ticket.php` — `#[Fillable]` at **15**, `casts()` at **21–24**, relations from **41**. There is no query scope on this model yet; task 4 adds the first one.
10. `backend/app/Http/Controllers/Api/V1/Admin/UserController.php` — `update()` at **76–96**. **Line 80** reads `$request->enum('role', UserRole::class) === UserRole::Agent && $user->isAdmin()`; with a third role that test no longer means "demoting". Task 12 is one line.
11. `backend/tests/Feature/Authorization/RouteAuthorizationTest.php:17` — the `ACCESS` manifest. `tickets.store` is `'staff-write'` today and `test_agent_reaches_staff_write_routes` (**74–80**) asserts an agent is **not** `403`. Task 10 moves it.
12. `backend/tests/Feature/Database/UsersTableSchemaTest.php:27` — asserts the literal string `"enum('admin','agent')"`. Task 2 changes it.
13. `backend/tests/Unit/Enums/UserRoleTest.php` — asserts **exactly two cases** (**10–13**) and the exact `values()` array (**21–24**). Both change.
14. `docs/api-contract.md` — `## Authorization` at **18–28**, the endpoint table starting **line 80**, `### POST /api/v1/tickets` at **187**, `### GET /api/v1/tickets` at **112**, `### GET /api/v1/tickets/stats` at **156**. `ApiContractCoverageTest` parses rows with `/^\|\s*`(\w+)`\s*\|\s*`([^`]+)`\s*\|/` — a new route without a row **fails CI**.
15. Grep `isAdmin()` across `backend/app` before you start — every call site is a place where "not an admin" silently meant "an agent".

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `GET /tickets` as an agent | Every ticket in the system | Assigned to me **or** unassigned |
| `GET /tickets` as a role-`user` | — (role does not exist) | Only tickets where `created_by` is me |
| `GET /tickets` as an admin | Every ticket | Unchanged — every ticket |
| `POST /tickets` as an admin or agent | `201` | **`403`** |
| `POST /tickets` as a role-`user` | — | `201`, requester derived from the account |
| `POST /tickets` with `assigned_to` | `422 prohibited` | Accepted; must be an **active agent** |
| `requester` object in the create payload | Required | **`422 prohibited`** — derived from the caller |
| `PATCH /tickets/{id}` as an agent | Allowed on any ticket | Allowed only on a ticket assigned to them |
| `POST /tickets/{id}/status` as an agent | Allowed on any ticket | Allowed only on a ticket assigned to them |
| `POST /tickets/{id}/claim` as a role-`user` | — | `403` (`claim()` becomes agent-only) |
| `GET /tickets/stats` `scope` | `'own'` \| `'all'` | `'assigned'` \| `'authored'` \| `'all'` |

---

## Decision — a role-`user` account is still matched to a `Requester` row

**Do not repoint `tickets.requester_id` at `users`, and do not drop the `requesters` table.**

Four notifications address `$ticket->requester` as a `Notifiable` (`backend/app/Notifications/TicketCreatedNotification.php:53`, and the status-changed, assigned and escalated notifications alongside it). `Requester` gets its mail routing from `Notifiable` on a plain model (`backend/app/Models/Requester.php:14`). Repointing the foreign key would rewrite the entire E8 email epic, the ERD, `RequesterFactory`, `DemoSeeder` and `RequestersTableSchemaTest` for no behavioural gain.

**Instead:** when a role-`user` account creates a ticket, `store()` resolves a `Requester` by **the account's own email** — `Requester::firstOrCreate(['email' => $user->email], ['name' => $user->name])` — and `created_by` holds the account id. `created_by` is the visibility key; `requester_id` stays the mail key. A user who was previously emailed as a contact and later given a login **inherits their own history's requester row**, because the match is on email.

**Measured consequence:** `requesters.phone` and `requesters.company` are `null` for accounts created this way, because `users` has no such columns. That is accepted; do **not** add columns to `users` for it.

---

## Decision — an agent's visibility is `assigned_to = me OR assigned_to IS NULL`

Read literally from the intake: *"Agents → assigned + unassigned tickets only."*

**Measured consequence, and it is the sharp one:** an agent who is unassigned from a ticket they were working **loses sight of it immediately**, and an agent who escalates a ticket they hold loses it too — `escalationTarget()` reassigns to an admin (`TicketController.php:212–214`). Both are correct under the rule as written. Do **not** add an "I used to hold this" clause; if the team wants one, it is a new story with its own column.

**A terminal ticket is not special.** A resolved-then-unassigned ticket reappears in every agent's queue. The unassigned filter is the entry point for Story 58's assignment requests, so this is the behaviour that story depends on.

---

## Backend Tasks

### 1 — The third role

**File: `backend/app/Enums/UserRole.php`**

Append **after** `Agent` — declaration order is asserted by `UserRoleTest::test_values_preserves_declaration_order`:

```php
case User = 'user';
```

**File: `backend/app/Models/User.php`**

Add beside `isAdmin()` (line 41). **Name them `isAgent()` and `isEndUser()`** — `isUser()` on a `User` model reads as a tautology and will be misread:

```php
public function isAgent(): bool
{
    return $this->role === UserRole::Agent;
}

/** A requester with a login. Never staff: creates tickets, sees only their own. */
public function isEndUser(): bool
{
    return $this->role === UserRole::User;
}
```

### 2 — The migration, and the surprise in the one it amends

**Create file: `backend/database/migrations/2026_08_29_100000_add_user_role_to_users_table.php`**

**Measured first, because it decides the file's shape:** `0001_01_01_000000_create_users_table.php:21` builds the column from `UserRole::values()`. The moment task 1 lands, **`php artisan migrate:fresh` already produces `enum('admin','agent','user')`** and this migration is a no-op. It exists for a database that has already migrated — a developer's `backend/.env` database, and any deployed one. Both paths must converge on the identical column definition.

```php
public function up(): void
{
    DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','agent','user') NOT NULL DEFAULT 'agent'");
}

public function down(): void
{
    // Narrowing the ENUM with rows still holding 'user' would silently coerce
    // them to '' under a non-strict sql_mode. Refuse instead: a rollback that
    // destroys account roles is worse than a rollback that stops.
    if (DB::table('users')->where('role', 'user')->exists()) {
        throw new RuntimeException('Cannot roll back: user accounts with role=user still exist. Reassign or delete them first.');
    }
    DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('admin','agent') NOT NULL DEFAULT 'agent'");
}
```

**Do not** use `$table->enum(...)->change()` — it requires `doctrine/dbal`-era behaviour for ENUM widening and will drop the default.

### 3 — The visibility scope, in one place

**File: `backend/app/Models/Ticket.php`**

Add after `casts()`. **This is the single definition of "can see"** — every consumer calls it, and no controller reimplements it:

```php
/**
 * Admin: everything. Agent: theirs plus the unassigned queue they may
 * request from (Story 58). End user: what they filed.
 *
 * @param  Builder<Ticket>  $query
 * @return Builder<Ticket>
 */
public function scopeVisibleTo(Builder $query, User $user): Builder
{
    if ($user->isAdmin()) {
        return $query;
    }
    if ($user->isAgent()) {
        return $query->where(fn (Builder $scoped) => $scoped->where('assigned_to', $user->getKey())->orWhereNull('assigned_to'));
    }

    return $query->where('created_by', $user->getKey());
}
```

The closure around the `OR` is **not optional** — without it the `orWhereNull` escapes any `where()` the caller already applied and an agent sees every unassigned-or-otherwise ticket regardless of the status filter.

### 4 — `TicketPolicy`, rewritten

**File: `backend/app/Policies/TicketPolicy.php`**

`viewAny()` stays `true` — **the list is scoped, not gated**; a role with nothing to see gets an empty page, not a `403`. Everything else:

```php
public function view(User $user, Ticket $ticket): bool
{
    if ($user->isAdmin()) {
        return true;
    }
    if ($user->isAgent()) {
        return $ticket->assigned_to === $user->getKey() || $ticket->assigned_to === null;
    }

    return $ticket->created_by === $user->getKey();
}

/** Only end users file tickets. Staff work them. */
public function create(User $user): bool
{
    return $user->isEndUser();
}

/** Editing the ticket's own fields is staff work, and an agent's own queue only. */
public function update(User $user, Ticket $ticket): bool
{
    return $user->isAdmin() || ($user->isAgent() && $ticket->assigned_to === $user->getKey());
}

public function changeStatus(User $user, Ticket $ticket): bool
{
    return $this->update($user, $ticket);
}
```

- `delete()` and `assign()` are **unchanged** — `$user->isAdmin()`.
- `claim()` becomes `return $user->isAgent() && $ticket->assigned_to === null;` — the old `! $user->isAdmin()` would hand claim to a role-`user` account. Story 58 deletes this method; **narrow it, do not delete it here.**
- `escalate()` becomes `return $user->isAdmin() || ($user->isAgent() && $ticket->assigned_to === $user->getKey());`. **Keep the existing docblock at 50–58 verbatim** — the "terminal ticket is a 422, not a 403" split it describes is still true and still lives in the controller.
- `addNote()` becomes `return ! $user->isEndUser() && $this->view($user, $ticket);`. **Keep the docblock at 60–65** and append one sentence: an internal note stays internal, so an end user cannot write one even on their own ticket.

### 5 — `GET /api/v1/agents`

**Create file: `backend/app/Http/Controllers/Api/V1/AgentController.php`**

Story 26 deferred this and named the condition under which it would be built. That condition is now met: a role-`user` account must choose an agent at creation time (task 8) and can never be given `GET /api/v1/admin/users`, which exposes emails, roles and active flags.

```php
class AgentController extends Controller
{
    /**
     * Active agents, id and name only. Deliberately NOT UserResource: this is
     * the one staff list every authenticated role can read, so it carries no
     * email, no role and no is_active. Admins wanting the full record still
     * use GET /admin/users.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => User::query()->active()
            ->where('role', UserRole::Agent)
            ->orderBy('name')->orderBy('id')
            ->get(['id', 'name'])]);
    }
}
```

No policy and no `admin` middleware — it sits inside the existing `auth:sanctum` + `active` group. It is a **read** route, so it takes no `throttle:` middleware and `RateLimitCoverageTest` stays green.

### 6 — Routes

**File: `backend/routes/api.php`**

One line, beside `/statuses` (line 47), inside the `['auth:sanctum', 'active']` group and **outside** the `admin` prefix group:

```php
Route::get('/agents', AgentController::class)->name('agents.index');
```

### 7 — Scope the list and the stats

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

`index()` — one call, **first in the chain**, before any `->when()`:

```php
$tickets = Ticket::query()
    ->visibleTo($request->user())
    ->with([...])
```

**File: `backend/app/Services/TicketStats.php`**

Replace the boolean at line 16 with the scope, and make the `scope` string three-way. `mine_open` (line 22) stays as it is — it is "assigned to me and open" for every role, and for an end user it is honestly `0`.

```php
public function for(User $user): array
{
    $statuses = ...;
    $priorities = ...;
    $byStatus = $this->scoped($user)->groupBy('status_id')->pluck(DB::raw('count(*)'), 'status_id');
    // ...same for byPriority and totals, all through scoped()
    return [
        'scope' => match (true) {
            $user->isAdmin() => 'all',
            $user->isAgent() => 'assigned',
            default => 'authored',
        },
        // `unassigned` was a global count. An end user has no business with a
        // queue-wide number, so it is scoped too and reads 0 for them.
        'unassigned' => $this->scoped($user)->whereNull('assigned_to')->count(),
        // ...
    ];
}

/** @return Builder<Ticket> */
private function scoped(User $user): Builder
{
    return Ticket::query()->visibleTo($user);
}
```

**`'own'` is gone from the response.** `frontend/src/api/stats.ts:21` types it and `DashboardView.vue:39` branches on it — **Story 57 owns both edits**, and until it lands the dashboard's scoped link is wrong for agents. Say so in the PR.

### 8 — `StoreTicketRequest`

**File: `backend/app/Http/Requests/Api/V1/StoreTicketRequest.php`**

Delete `prepareForValidation()` entirely (**50–55**) — it lower-cased a submitted requester email that is no longer submitted. Then:

```php
public function rules(): array
{
    return [
        'requester' => ['prohibited'],
        'subject' => ['required', 'string', 'max:255'],
        'description' => ['required', 'string', 'max:16000'],
        'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('is_active', true)->whereNull('deleted_at')],
        'priority_id' => ['sometimes', 'integer', Rule::exists('priorities', 'id')],
        'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Agent)->where('is_active', true)],
        'reference' => ['prohibited'], 'created_by' => ['prohibited'],
    ];
}

public function messages(): array
{
    return [
        'category_id.exists' => 'That category does not exist or is no longer active.',
        'requester.prohibited' => 'The requester is your own account; do not send one.',
        'assigned_to.exists' => 'That user is not an active agent.',
    ];
}
```

The `assigned_to.prohibited` message at **line 79** goes with it.

### 9 — `TicketController::store()`

**File: `backend/app/Http/Controllers/Api/V1/TicketController.php`**

`$this->authorize('create', Ticket::class)` at **342** is already there and now does real work. Inside the transaction, replace **345–346**:

```php
$actor = $request->user();
// The account IS the requester. Matched on email so a contact who later
// gets a login inherits their own ticket history -- see the plan's
// "still matched to a Requester row" decision.
$requester = Requester::firstOrCreate(
    ['email' => mb_strtolower($actor->email)],
    ['name' => $actor->name],
);
```

Then, still inside the transaction, after `$ticket->save()` and after the `Created` activity row:

```php
if ($ticket->assigned_to !== null) {
    $recorder->record($ticket->getKey(), TicketActivityEvent::Assigned, [
        'user_id' => $actorId, 'field' => 'assigned_to',
        'old_value' => null, 'new_value' => (string) $ticket->assigned_to,
        'meta' => ['reason' => 'chosen_at_creation', 'to_name' => $this->userName($ticket->assigned_to)],
    ]);
}
```

`assigned_to` is already in `Ticket`'s `#[Fillable]` (`Ticket.php:15`), so add it to the `$request->safe()->only([...])` list at **347**. `userName()` already exists at **269–272**.

After the transaction, beside the existing `TicketCreated::dispatch()` at **364** — **outside** it, for the same after-commit reason the comment at **359–363** gives:

```php
if ($ticket->assigned_to !== null) {
    TicketAssigned::dispatch($ticket->getKey(), $ticket->assigned_to, $actorId, null);
}
```

Order matters: dispatch `TicketCreated` first, so the requester's confirmation is queued before the agent's assignment mail.

### 10 — The authorization manifest

**File: `backend/tests/Feature/Authorization/RouteAuthorizationTest.php`**

In `ACCESS` (**line 17**): add `'agents.index' => 'staff'`, and move `'tickets.store'` from `'staff-write'` to a **new level `'user-policy'`**. Add the mirror of `test_admin_refused_by_agent_policy_routes` (**55–63**):

```php
public function test_staff_refused_by_user_policy_routes(): void
{
    $this->seed();
    foreach (['admin', 'agent'] as $role) {
        Auth::forgetGuards();
        $token = $this->tokenFor(User::factory()->{$role}()->create());
        foreach ($this->routesFor('user-policy') as $route) {
            $this->withToken($token)->json($route['method'], $route['uri'])->assertForbidden();
        }
    }
}
```

`Auth::forgetGuards()` between the two roles is required — `test_unknown_id_agent_forbidden_admin_not_found` (**129–135**) already demonstrates why.

### 11 — `Admin\UserController`, one line

**File: `backend/app/Http/Controllers/Api/V1/Admin/UserController.php:80`**

```php
$demoting = $request->has('role') && $request->enum('role', UserRole::class) !== UserRole::Admin && $user->isAdmin();
```

Without this, promoting an admin to… nothing, or moving the last admin to `role=user`, skips `guardAgainstLockout()` and empties the admin role. `StoreUserRequest`/`UpdateUserRequest` need **no change** — both already use `Rule::enum(UserRole::class)` and pick up the third case for free.

### 12 — Factory and seeders

**File: `backend/database/factories/UserFactory.php`** — beside `agent()` (**54–57**):

```php
public function endUser(): static
{
    return $this->state(fn (array $attributes) => ['role' => UserRole::User]);
}
```

**File: `backend/database/seeders/DemoSeeder.php`** — `seedRequesters()` currently makes contact rows with no logins, and `seedTickets()` sets `created_by` from the staff pool. Give the demo set **four role-`user` accounts** whose emails match four of the seeded requesters, and set those tickets' `created_by` to them, so a `php artisan db:seed --class=DemoSeeder` produces a database where the new scoping is visible by logging in. Reuse `withName()` (**64–71**) for typeable demo logins. `AdminUserSeeder` is unchanged.

### 13 — Documentation

**File: `docs/api-contract.md`**

- `## Authorization` (**18–28**) — replace the two-role description with the three-role table from **Product rules** above, and state the visibility predicate for each role explicitly.
- Endpoint table — add `| `GET` | `/api/v1/agents` | Active agents, id and name only, for the create-ticket picker. | bearer | TM-— |` and amend the `POST /api/v1/tickets` row (**line 94**) to say *"role `user` only; requester derived from the caller"*.
- `### GET /api/v1/tickets` (**112**) — document that results are scoped by role and that no parameter widens them.
- `### GET /api/v1/tickets/stats` (**156**) — `scope` is now `all` | `assigned` | `authored`.
- `### POST /api/v1/tickets` (**187**) — new request body: no `requester` object, optional `assigned_to`.
- New `### GET /api/v1/agents` subsection.

**File: `docs/erd.md`** — note on `tickets.created_by`: it is now the visibility key for role-`user` accounts, and `requester_id` remains the mail key.

**File: `CLAUDE.md`** — the "Roles are **Admin** and **Agent** — requesters are contact records, not logins" line in *What this is*, and the whole *Authentication and authorization* section's claim that *"The ticket list (`GET /tickets`) itself carries no role-based scoping"*. **Both are now false.** Rewrite them.

---

## Edge Cases & Failure Modes

- **An agent escalates their own ticket and loses it.** `escalationTarget()` (`TicketController.php:200–221`) reassigns to the least-loaded admin, so the `200` response body is the last time that agent can read the ticket. Expected. The response is still returned in full — `escalate()` reloads and serialises before the policy is consulted again.
- **An agent is unassigned and the ticket vanishes from their queue.** Same mechanism, via `POST /assign` with `assigned_to: null`. Expected, and it is what makes Story 58's request flow necessary.
- **A ticket that exists but is invisible returns `403`, not `404`.** Route-model binding resolves first, then `view()` denies. This matches the existing precedent asserted in `RouteAuthorizationTest::test_unknown_id_agent_forbidden_admin_not_found` (**129–135**), where an agent gets `403` and an admin `404` for the same id. **It does leak existence.** Accepted for consistency; changing it is a repo-wide decision, not this story's.
- **`GET /tickets/{id}/activities` and `POST /tickets/{id}/notes` are already covered.** `TicketActivityController:17` authorizes `view`, and `StoreTicketNoteRequest` gates on `addNote`. Both tighten for free — **verify, do not re-add checks.**
- **A user created before this story has `role = 'agent'` and keeps it.** The migration changes no rows. Turning existing contacts into logins is an operator action, not a migration.
- **Two accounts, one email, one `Requester`.** `users.email` is unique (`create_users_table.php:19`) so this cannot happen for accounts, but a `Requester` may already exist for that email from a staff-filed ticket. `firstOrCreate` reuses it and **does not overwrite its `name`** — the historical contact name wins over the account name. Deliberate: the requester row is the mail identity.
- **`assigned_to` names an agent who is deactivated between validation and insert.** The row still writes. Same window `AssignTicketRequest` has had since Story 26; not newly introduced and not fixed here.
- **`TicketStats::for()` on an end user with no tickets** returns every status and priority with `count: 0`, `total: 0`, `unassigned: 0`, `mine_open: 0`. It must not 500 — `firstOrFail()` at **line 21** is on an aggregate row, which always exists.
- **`AgentWorkload` is untouched and correct.** `people()` (`AgentWorkload.php:83`) already filters `role = agent` **or** currently holds a ticket, so a role-`user` account never appears in the workload grid.

---

## Test Plan

### Backend — `tests/Unit/Enums/UserRoleTest.php` (modified)

1. `test_it_has_exactly_two_cases` → **three**, renamed.
2. `test_it_uses_documented_values` — add `assertSame('user', UserRole::User->value)`.
3. `test_values_preserves_declaration_order` → `['admin', 'agent', 'user']`.

### Backend — `tests/Feature/Database/UsersTableSchemaTest.php` (modified)

4. `test_role_is_mysql_enum` → `"enum('admin','agent','user')"`.
5. New `test_migrate_fresh_and_the_alter_agree`: assert the column type equals the same literal after `migrate:fresh` — the convergence measured in task 2.
6. `test_invalid_role_is_rejected` — `'manager'` still throws; keep as is.

### Backend — `tests/Feature/Tickets/TicketVisibilityTest.php` (new; `RefreshDatabase` + `$this->seed()`)

7. Admin `GET /tickets` sees all three fixture tickets.
8. Agent sees exactly their assigned ticket plus the unassigned one; not the one assigned to another agent.
9. End user sees only the ticket where `created_by` is them.
10. `GET /tickets/{id}` for an invisible ticket is `403` for both an agent and an end user.
11. Filters compose with the scope: `?status_id[]=…` as an agent never widens past the scope. Assert against a ticket assigned to another agent with the same status.
12. `GET /tickets/stats` returns `scope` `all` / `assigned` / `authored` per role, and `unassigned` is `0` for the end user.

### Backend — `tests/Feature/Tickets/CreateTicketTest.php` (modified — its `requester` payload is now `422`)

13. Every existing case's payload loses `requester` and gains an end-user actor.
14. New: admin `POST /tickets` → `403`; agent → `403`.
15. New: end user `POST /tickets` creates a `Requester` matching their email, and reuses an existing one rather than duplicating.
16. New: `requester` present → `422` under `errors.requester`.
17. New: `assigned_to` naming an active agent → `201`, `assigned_to` set, **two** activity rows (`created`, `assigned`), and `TicketAssigned` dispatched. Assert with `Event::fake()` the way `NotificationDispatchTest` does.
18. New: `assigned_to` naming an admin, an inactive agent, or an unknown id → `422` under `errors.assigned_to`.

### Backend — `tests/Feature/Policies/TicketPolicyTest.php` (new)

19. A table-driven pass over `view`/`update`/`changeStatus`/`escalate`/`addNote`/`claim`/`assign`/`delete` × {admin, holding agent, other agent, author end user, other end user}. This is the story's real contract; write it before the controllers.

### Backend — `tests/Feature/Tickets/AgentListTest.php` (new)

20. `GET /agents` returns active agents only, ordered by name, with **exactly** the keys `id` and `name` — assert the absence of `email` and `role`.
21. Reachable by all three roles; `401` unauthenticated.

### Backend — modified elsewhere

22. `RouteAuthorizationTest` — the new `ACCESS` entries and `test_staff_refused_by_user_policy_routes` from task 10.
23. `tests/Feature/Admin/UserLockoutTest.php` — new case: moving the last active admin to `role=user` is refused under `errors.role`.
24. `tests/Feature/Tickets/AssignTicketTest.php`, `UpdateTicketTest.php`, `TicketStatusTest.php`, `TicketEscalateTest.php`, `ClaimTicketTest.php`, `Activity/*` — each creates a ticket and acts as an agent who may now not hold it. **Expect a wide sweep**: give the acting agent the assignment, or use an admin.
25. `tests/Feature/Documentation/ApiContractCoverageTest.php` — passes only once task 13's `GET /api/v1/agents` row exists.

---

## Migration / Rollback

- `php artisan migrate` runs one `ALTER TABLE users MODIFY`. On a table of any realistic size this is an in-place metadata change on MySQL 8, but it is **not** instant on a large table — it copies. Sequence it before the deploy that ships the code, not during.
- **Half-applied state:** if the ALTER lands and the code does not, nothing breaks — no row uses `'user'`. If the code lands and the ALTER does not, creating a role-`user` account fails with a `QueryException` at insert. That ordering is safe in exactly one direction: **migrate first.**
- **Rollback** is guarded by task 2's `down()` throw. To roll back genuinely, first move every role-`user` account to `agent` or delete it; `Admin\UserController::destroy()`'s reassignment flow handles the tickets they authored.

---

## Verification Steps

1. **Services up:** `docker compose up -d --wait`, then `docker compose ps` shows `tm-mysql-test` healthy.
2. **Backend migrates both ways:** from `backend/` — `php artisan migrate`, then `php artisan migrate:fresh --seed`, and confirm `SHOW CREATE TABLE users` gives the identical `role` definition after each.
3. **Backend tests:** `composer test`. Then the focused pass: `php artisan test --filter='TicketVisibilityTest|TicketPolicyTest|CreateTicketTest|AgentListTest|RouteAuthorizationTest|UserRoleTest|UsersTableSchemaTest'`.
4. **Formatting:** `./vendor/bin/pint --test` from `backend/`.
5. **Regression:** `php artisan test --filter='Notifications|Activity|Admin'` — the notification chain and the audit trail must be untouched.
6. **By hand:** `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder`, then with `php artisan serve` running, log in as a demo end user and confirm `GET /api/v1/tickets` returns only their own.
7. **Frontend is expected to fail here.** `npm run test:unit` in `frontend/` will break on `stats.scope` and on ticket creation. **Story 57 fixes it** — do not patch the SPA in this story.

---

## Done Criteria

- [ ] `UserRole` has three cases and `users.role` accepts `'user'` on both a fresh and an already-migrated database.
- [ ] `POST /api/v1/tickets` is `403` for an admin and for an agent, `201` for a role-`user` account.
- [ ] The create payload carries no `requester`; the `Requester` row is derived from the caller's name and email, and an existing row with that email is reused.
- [ ] `assigned_to` may be supplied at creation, must be an **active agent**, writes an `assigned` activity row and dispatches `TicketAssigned`.
- [ ] `GET /api/v1/tickets` returns admin → all, agent → assigned + unassigned, user → authored. No query parameter widens the scope.
- [ ] `GET /api/v1/tickets/{id}` is `403` on a ticket outside the caller's scope, for every non-admin role.
- [ ] An agent cannot update, change the status of, or escalate a ticket they do not hold.
- [ ] `GET /api/v1/agents` returns `id` and `name` for active agents only, to any authenticated role, and carries no email.
- [ ] `GET /api/v1/tickets/stats` reports `scope` as `all` | `assigned` | `authored`, scoped consistently with the list.
- [ ] Moving the last active admin to `role=user` is refused.
- [ ] `docs/api-contract.md`, `docs/erd.md` and `CLAUDE.md` describe three roles; `ApiContractCoverageTest` is green.
- [ ] `composer test` and `./vendor/bin/pint --test` pass.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 57.**
