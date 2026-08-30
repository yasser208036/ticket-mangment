# Story 57 — The SPA learns three roles

## Prerequisites

- **Story 56 ([`56-story-a-third-role-and-scoped-ticket-visibility.md`](56-story-a-third-role-and-scoped-ticket-visibility.md)) must be implemented, not merely planned.** This story consumes four things it creates: the `'user'` role string on `/auth/me`, the three-way `stats.scope`, `GET /api/v1/agents`, and a `POST /tickets` that `403`s for staff. **Gate: `cd backend && composer test` green before you start.**
- **The SPA is currently broken for staff.** After Story 56, `NewTicketView` posts a `requester` object the API now rejects with `422`, and the nav still offers "New ticket" to admins and agents who get a `403`. Repairing both is this story's first job.
- **`stats.scope === 'own'` no longer exists.** It is referenced in three places that must all change together: `frontend/src/api/stats.ts:21`, `frontend/src/views/DashboardView.vue:39`, and `DashboardView.vue:162` / `:184`.
- **No backend changes.** If you find yourself needing one, it belongs in Story 56 or 58 — stop and raise it.
- **No new npm dependency.** Everything here is Vue 3 + Pinia + existing components.

---

## Story Goal

Three roles get three different applications out of one bundle, and no screen offers an action the API will refuse.

1. `UserRole` in the SPA is `'admin' | 'agent' | 'user'`, and the auth store exposes `isAdmin`, `isAgent`, `isEndUser`.
2. Route guards take a **list** of permitted roles, not a single `role`, and `/tickets/new` is reachable only by an end user.
3. The header offers each role only what it can do — "New ticket" to end users, the admin block to admins.
4. `NewTicketView` no longer asks for requester details and instead offers an **optional agent picker** fed by `GET /api/v1/agents`.
5. The dashboard and the ticket list stop offering filters and labels that make no sense for the caller's scope.

**Not in scope.** Nothing in `backend/`. No assignment-request UI (**Story 58**). No new view — an end user reuses `TicketListView` and `TicketDetailView`, both of which the API already scopes. No design system change: match the Tailwind idiom already in each file.

---

## Context — Read These Files First

1. `frontend/src/api/auth.ts:3` — `export type UserRole = 'admin' | 'agent'`. **This one line is the type root**; `api/users.ts:3` imports it, `router/index.ts:3` imports it, and `RouteMeta` at `router/index.ts:17–22` types `role` from it. Widening it here surfaces every place that must change as a `vue-tsc` error.
2. `frontend/src/stores/auth.ts:16` — `const isAdmin = computed(() => user.value?.role === 'admin')`. The only role predicate in the store. Task 2 adds two siblings.
3. `frontend/src/router/guards.ts:36` — `if (to.meta.role === 'admin' && !auth.isAdmin) return { name: 'forbidden' }`. **A single hardcoded comparison against the literal `'admin'`.** Task 3 generalises it, and `guards.spec.ts` already covers the redirect shape.
4. `frontend/src/router/index.ts` — routes at **27–68**. Three routes carry `meta: { role: 'admin' }` (**46, 52, 58**); `/tickets/new` (**61**) carries nothing.
5. `frontend/src/App.vue` — the desktop nav at **77–126** and the mobile drawer at **229–286**. Both render the same links twice; **every `v-if` you add must be added in both places** or the drawer leaks an admin link on a phone. `data-testid="nav-new-ticket"` appears at **92** and **242**.
6. `frontend/src/views/NewTicketView.vue` — `form` at **15–20** (its `requester` sub-object goes), `validate()` at **28–40** (its two requester checks go), `submit()` at **42–56**, `onMounted` at **58**. The template's requester fieldset follows; delete the whole fieldset, not just the bindings.
7. `frontend/src/api/tickets.ts:75–86` — `CreateTicketPayload`. Task 6 removes `requester` and adds `assigned_to?: number | null`.
8. `frontend/src/api/stats.ts:21` — `scope: 'own' | 'all'`.
9. `frontend/src/views/DashboardView.vue` — `escalatedTo` at **31–41** and the two `scope === 'own'` labels at **162** and **184**. **Read the comment at 31–34**: *"Keyed on `scope`, not on the role — the API decides, the view reports."* That principle is correct and this story keeps it; only the set of values widens.
10. `frontend/src/components/TicketFilterBar.vue:235–251` — the assignee `<select>`. `Me` and `Unassigned` are unconditional today; the named-user options are already `auth.isAdmin`-gated at **245**.
11. `frontend/src/components/UserFormDialog.vue` — `form.role` default at **17**, the `<select>` at **135–142** with exactly two `<option>`s, and the `editingSelf` note at **163**.
12. `frontend/src/api/users.ts:14–20` — `UserListQuery.role` is typed `UserRole`, so the admin list's role filter widens for free once item 1 changes. Check `AdminUsersView.vue` for a hardcoded two-option filter and widen it if present.
13. `frontend/src/router/guards.spec.ts` and `frontend/src/App.spec.ts` — the existing patterns for stubbing `useAuthStore` and asserting on `data-testid`. Match them; do not introduce a new mocking style.

---

## Product rules (from story)

| Screen or control | Admin | Agent | End user |
|---|---|---|---|
| Header — Tickets | ✅ | ✅ | ✅ |
| Header — New ticket | ✕ | ✕ | ✅ |
| Header — Categories / Users / Workload | ✅ | ✕ | ✕ |
| `/tickets/new` route | redirect `forbidden` | redirect `forbidden` | ✅ |
| Assignee filter — Me | ✅ | ✅ | ✕ |
| Assignee filter — Unassigned | ✅ | ✅ | ✕ |
| Assignee filter — named agents | ✅ | ✕ | ✕ |
| Dashboard "Unassigned" card | ✅ | ✅ | ✕ |
| Create form — requester fields | — | — | **removed entirely** |
| Create form — agent picker | — | — | optional |

---

## Frontend Tasks

### 1 — Widen the type at its root

**File: `frontend/src/api/auth.ts:3`**

```ts
export type UserRole = 'admin' | 'agent' | 'user'
```

Then run `npx vue-tsc -b` **before writing anything else**. Every error it reports is a site this story must visit; treat the output as the task list and reconcile it against the tasks below.

### 2 — Role predicates in the auth store

**File: `frontend/src/stores/auth.ts`**

Beside `isAdmin` at line 16, and returned from the store at **59–68**:

```ts
const isAdmin = computed(() => user.value?.role === 'admin')
const isAgent = computed(() => user.value?.role === 'agent')
/** A requester with a login: files tickets, sees only their own. */
const isEndUser = computed(() => user.value?.role === 'user')
/** Admin or agent. The predicate most screens actually want. */
const isStaff = computed(() => isAdmin.value || isAgent.value)
```

**Do not** derive `isStaff` as `!isEndUser` — that reads `true` for a signed-out visitor, and `App.vue` renders its header inside `v-if="auth.isAuthenticated"` but the guards do not.

### 3 — Guards take a list of roles

**File: `frontend/src/router/index.ts:17–22`**

```ts
declare module 'vue-router' {
  interface RouteMeta {
    public?: boolean
    roles?: UserRole[]
  }
}
```

**File: `frontend/src/router/guards.ts:36`** — replace the hardcoded comparison:

```ts
if (to.meta.roles && !to.meta.roles.includes(auth.user!.role))
  return { name: 'forbidden' }
```

`auth.user` is non-null here: the `isAuthenticated` check on the line above already returned. Keep the `void useMasterDataStore().ensureLoaded()` call at **37** **after** the role check — an end user redirected to `forbidden` should not also fire three master-data requests.

**File: `frontend/src/router/index.ts`** — `meta: { role: 'admin' }` becomes `meta: { roles: ['admin'] }` at **46, 52, 58**, and `/tickets/new` (**61**) gains `meta: { roles: ['user'] }`.

### 4 — The header

**File: `frontend/src/App.vue`**

- Desktop **88–95** and mobile **238–245**: the "New ticket" link gains `v-if="auth.isEndUser"`.
- The admin block is already `v-if="auth.isAdmin"` (**97–125**, **246–276**) — **verify, change nothing.**
- The separator `<div v-if="auth.isAdmin" class="mx-1.5 h-4 w-px bg-slate-200" />` at **97** stays keyed on `isAdmin`.
- The role chip at **144–148** renders `auth.user?.role` raw, so an end user's badge reads "user". Map it to a label — `Administrator` / `Support Agent` / `Requester` — in a small `roleLabel` computed, and reuse the same map in `UserFormDialog` (task 7) rather than writing the strings twice.

### 5 — `GET /agents`

**Create file: `frontend/src/api/agents.ts`**

```ts
import client from './client'

/** id and name only -- see the backend's AgentController docblock. */
export interface AgentOption {
  id: number
  name: string
}

export async function listAgents(): Promise<AgentOption[]> {
  const { data } = await client.get<{ data: AgentOption[] }>('/agents')
  return data.data
}
```

**No store.** `CLAUDE.md` says components go through a store, and the rule holds where a store exists — but this list has exactly one consumer (task 6), no cross-view sharing and no mutation. Add a `useAgentsStore` **only** when Story 58's admin review screen needs the same list; note that in a comment so the next reader sees the decision rather than the omission.

### 6 — `NewTicketView`

**File: `frontend/src/views/NewTicketView.vue`**

```ts
const form = reactive<CreateTicketPayload>({
  subject: '',
  description: '',
  category_id: 0,
})
const agents = ref<AgentOption[]>([])
const assignedTo = ref<number | 0>(0)
```

- Delete the two `form.requester.*` checks from `validate()` (**30–33**) and the entire requester fieldset from the template.
- `submit()` (**42–56**) sends `assigned_to: assignedTo.value || undefined` alongside the existing `priority_id: form.priority_id || undefined`. `0` is the "no choice" sentinel already used for `category_id`; keep it.
- `onMounted` (**58**) also calls `listAgents()`, in a `try/catch` that leaves `agents` empty on failure. **An agents-endpoint failure must not block ticket creation** — the picker is optional, so a `403` or a network error degrades to "unassigned", it does not stop the form.
- The picker: a `<select v-model="assignedTo" data-testid="ticket-form-agent">` with `<option :value="0">Let an administrator assign this</option>` first, then one option per agent. Match the Tailwind classes on the existing category `<select>` exactly.
- Add a one-line hint under it: "Optional — leave this if you are not sure who should handle it."

**File: `frontend/src/api/tickets.ts:75–86`**

```ts
export interface CreateTicketPayload {
  subject: string
  description: string
  category_id: number
  priority_id?: number
  assigned_to?: number
}
```

`Requester` (**6–12**) stays — it is still on every `Ticket` response.

### 7 — `UserFormDialog` offers three roles

**File: `frontend/src/components/UserFormDialog.vue`**

- **Line 17**: the cast `('agent' as 'admin' | 'agent')` becomes `UserRole` imported from `../api/auth`. **Do not re-spell the union inline** — that is how it drifted in the first place.
- **135–142**: a third `<option value="user">Requester</option>`. Order it `Requester`, `Support Agent`, `Administrator` — least to most privileged, so a mis-click lands low.
- Check `AdminUsersView.vue` for a role filter `<select>` with two hardcoded options and widen it identically.

### 8 — The dashboard reads the new scope

**File: `frontend/src/api/stats.ts:21`**

```ts
scope: 'all' | 'assigned' | 'authored'
```

**File: `frontend/src/views/DashboardView.vue`**

- `escalatedTo` (**31–41**): the `assignee: 'me'` narrowing now applies only when `scope === 'assigned'`. For `'authored'` the list is already scoped server-side and `assignee: 'me'` would mean *assigned* to me — the wrong filter, and it would return nothing. Keep the comment at **31–34**; extend it with that measured reason.
- **162** and **184**: replace `scope === 'own' ? 'My …' : '…'` with a three-way `scopeLabel` computed — `'all'` → "Queue", `'assigned'` → "My tickets", `'authored'` → "My tickets". Two of three collapse, but write it as a `match`-shaped ternary chain over `scope`, not over the role.
- The **Unassigned** card (**153–158**) gets `v-if="!auth.isEndUser"`. Story 56 already makes the number `0` for an end user; a zero card that links to a filter they cannot use is worse than no card.

### 9 — The ticket list's filters

**File: `frontend/src/components/TicketFilterBar.vue:241–250`**

- `Me` and `Unassigned` gain `v-if="!auth.isEndUser"` — for an end user every ticket is theirs and none is assigned to them, so both options return a confusing empty list.
- The named-agent options at **245** stay `auth.isAdmin`-gated. **Story 56 does not open `/admin/users` to agents**, so this line is still correct.
- When every option is hidden, hide the whole `<div class="flex flex-col gap-1">` wrapper (**230–251**) rather than leaving a labelled select with one entry.

---

## Edge Cases & Failure Modes

- **A signed-in end user deep-links to `/tickets/new` and their session has not hydrated.** `authGuard` awaits `auth.hydrate()` at `guards.ts:32` before any role check, so `auth.user` is populated by the time task 3's line runs. Verified by reading the guard, not assumed.
- **A user's role changes server-side while the SPA is open.** The store caches `user` until `clear()`. They keep a stale nav until reload; the API still refuses. **Accepted** — the same staleness `isAdmin` has had since Story 09. Do not add polling.
- **`GET /agents` fails inside `NewTicketView`.** The picker renders with only the "Let an administrator assign this" option and the form still submits. Assert this in a test — a caught-and-ignored failure is exactly the kind that rots.
- **An end user reaches `TicketDetailView` for a ticket they authored.** `can.update`, `can.assign`, `can.claim`, `can.change_status`, `can.escalate`, `can.delete` and `can.add_note` are **all `false`** after Story 56's policy rewrite, so `TicketActionToolbar` renders no buttons and the timeline is read-only. **This needs no new `v-if`** — the toolbar is already `can`-driven (`TicketActionToolbar.vue:36`). Verify with a test rather than adding guards.
- **The role chip for an unknown future role.** `roleLabel` must fall back to the raw string rather than rendering `undefined`.
- **The mobile drawer.** Every `v-if` in task 4 exists twice. A test that only mounts the desktop nav will not catch a leak; assert on `data-testid` counts, which cover both.
- **`vue-tsc` and the `role` meta.** Renaming `meta.role` to `meta.roles` is a **breaking rename**, not an addition — any route file still setting `role` compiles fine (unknown properties on `RouteMeta` are permitted) and then silently loses its guard. Grep for `meta.role` and `role:` under `src/router/` and confirm zero remain.

---

## Test Plan

### `frontend/src/router/guards.spec.ts` (modified)

1. An admin reaching a `roles: ['admin']` route passes; an agent is redirected to `forbidden`; an end user is redirected to `forbidden`.
2. An end user reaching `/tickets/new` passes; an agent and an admin are redirected to `forbidden`.
3. A route with no `roles` is reachable by all three.
4. `ensureLoaded()` is **not** called on the redirect path — spy on the master-data store.

### `frontend/src/stores/auth.spec.ts` (modified)

5. `isAdmin` / `isAgent` / `isEndUser` / `isStaff` for each of the three roles, and all four `false` when `user` is `null`.

### `frontend/src/App.spec.ts` (modified)

6. End user: `nav-new-ticket` present, `nav-categories` / `nav-users` / `nav-workload` absent.
7. Agent: `nav-new-ticket` absent, admin links absent, `nav-tickets` present.
8. Admin: `nav-new-ticket` absent, all three admin links present.
9. Each assertion counts occurrences so the mobile drawer is covered by the same test.
10. The role chip renders "Requester" for `role: 'user'`.

### `frontend/src/views/NewTicketView.spec.ts` (modified — it currently fills requester fields)

11. No requester input is rendered.
12. A successful submit posts `{subject, description, category_id}` with **no `requester` key** — assert on the payload object, not on a truthy call.
13. Choosing an agent adds `assigned_to`; leaving the default sends no `assigned_to` key at all.
14. `listAgents()` rejecting leaves the form submittable and the select showing one option.
15. Validation still blocks an empty subject, description or category.

### `frontend/src/views/DashboardView.spec.ts` (modified)

16. `scope: 'all'` → "Queue by status", Unassigned card present, escalated link carries no `assignee`.
17. `scope: 'assigned'` → "My tickets by status", escalated link carries `assignee: 'me'`.
18. `scope: 'authored'` → "My tickets by status", Unassigned card **absent**, escalated link carries **no** `assignee`.

### `frontend/src/components/TicketFilterBar.spec.ts` (modified)

19. End user: the assignee control is not rendered at all.
20. Agent: `Anyone` / `Me` / `Unassigned` present, no named-agent options.
21. Admin: all of the above plus one option per loaded user.

### `frontend/src/views/TicketDetailView.spec.ts` (modified)

22. With every `can.*` false, `TicketActionToolbar` renders no action buttons and the note composer is absent.

### New — `frontend/src/api/agents.spec.ts`

23. `listAgents()` unwraps `data.data`; a rejection propagates.

---

## Verification Steps

1. **Backend up and on Story 56:** `docker compose up -d --wait`, then from `backend/` `php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder && php artisan serve`.
2. **Typecheck:** from `frontend/` — `npx vue-tsc -b`. **Zero errors**; this is the gate that proves task 1's widening was followed through everywhere.
3. **Frontend tests:** `npm run test:unit`.
4. **Lint and format:** `npm run lint` (warnings fail) and `npm run format:check`.
5. **Build:** `npm run build` — `vue-tsc -b && vite build`.
6. **By hand, all three roles**, with `npm run dev` on :5173: log in as the demo admin (all nav, no New ticket), a demo agent (no admin nav, no New ticket, list shows only their own plus unassigned), and a demo end user (New ticket only, list shows only what they filed, create a ticket with and without an agent).
7. **Regression:** `cd backend && composer test` — unchanged and still green; this story touches no PHP.

---

## Done Criteria

- [ ] `UserRole` is `'admin' | 'agent' | 'user'` and `npx vue-tsc -b` is clean.
- [ ] `useAuthStore` exposes `isAdmin`, `isAgent`, `isEndUser`, `isStaff`, all `false` when signed out.
- [ ] Route meta is `roles?: UserRole[]`; no `meta.role` remains anywhere under `src/router/`.
- [ ] `/tickets/new` redirects an admin and an agent to `forbidden` and admits an end user.
- [ ] The header shows "New ticket" only to end users and the admin block only to admins, in **both** the desktop nav and the mobile drawer.
- [ ] `NewTicketView` renders no requester fields, posts no `requester` key, and offers an optional agent picker fed by `GET /api/v1/agents`.
- [ ] A failing `GET /agents` leaves the create form usable.
- [ ] The dashboard handles all three `scope` values, and the Unassigned card is hidden from end users.
- [ ] The assignee filter is hidden from end users and offers named agents only to admins.
- [ ] `npm run test:unit`, `npm run lint`, `npm run format:check` and `npm run build` all pass.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 58.**
