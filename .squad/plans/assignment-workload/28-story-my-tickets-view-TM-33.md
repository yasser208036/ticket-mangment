# Story 28 — My tickets view (Story: TM-33)

## Prerequisites

- **Story 19 (TM-23), Story 20 (TM-24) and Story 25 (TM-29) are all hard blockers, and none is implemented as of writing.** This story adds a second route onto Story 19's `TicketListView.vue`, drives it with Story 20's store, `lib/ticketQuery.ts` and `TicketFilterBar.vue`, and reads AC4's count out of Story 25's `stores/stats.ts`. **There is nothing to build until all three have landed.** Gate: `cd frontend && npm test` green with `TicketListView.spec.ts`, `ticketQuery.spec.ts`, `TicketFilterBar.spec.ts` and `DashboardView.spec.ts` all present.
- **This story is frontend only.** The Jira labels say `frontend` and they are right: **no migration, no route, no controller, no policy, no resource, no `docs/api-contract.md` change.** Every figure and every filter it needs already exists on the API by the time Stories 20 and 25 land. Say so in the PR description — a reader will expect a backend diff.
- **Stories 26 (TM-31) and 27 (TM-32) are not blockers, but this story touches two lines they own.** Task 6 adds a `stats.load()` call inside `stores/tickets.ts`'s `assign()` (Story 26) and `claim()` (Story 27). If either has not landed, add the call to the one that has and record the other as owed. **Rebase before you start.**
- **Master data already carries everything AC1 needs.** `statuses.is_terminal` exists (`backend/database/migrations/2026_08_26_073219_create_statuses_table.php:24`), `StatusResource` ships it (**line 12**), and `frontend/src/api/statuses.ts:3` types it. Seeded: **five non-terminal** (`new`, `open`, `in-progress`, `pending`, `reopened`) and **two terminal** (`resolved`, `closed`) — `StatusSeeder.php:13–19`.
- **No new npm dependency.** Vue Router, Pinia and the existing components cover all four criteria.

---

## Story Goal

An agent opens one link and sees exactly their own live work, with no filtering ritual.

1. **`/my-tickets`** pre-applies *assigned to me* **and** *no terminal statuses*.
2. It is the **same view, table and filter bar** as `/tickets` — one component, two routes, zero duplicated markup (AC2).
3. A toggle reveals **resolved and closed** tickets (AC3).
4. The header nav shows a **live count** of the caller's open tickets (AC4).

**Not in scope.** **No new API endpoint and no new query parameter** — `assigned_to=me` and `status_id[]` are Story 20's, `mine_open` is Story 25's. No saved views, no per-user default filters, no "my team" scope, no sort preference memory. No SLA or age highlighting. **No `TicketTable.vue` extraction** — see the decision below. No changes to the ticket detail page. TM-34 and TM-35 own reassignment and workload; nothing here reads or writes another agent's tickets.

---

## Product rules (from story)

| Route / action | Result |
|---|---|
| `/my-tickets` with **no** query string | Preset applied: `assignedTo = 'me'`, `statusIds = <five non-terminal ids>`, page 1, default sort |
| `/my-tickets?…` with **any** query string | The URL wins — `fromQuery` hydrates exactly as on `/tickets` |
| AC3 toggle **on** | `statusIds = []` → every status including `resolved` and `closed` |
| AC3 toggle **off** | `statusIds` back to the five non-terminal ids, **discarding a hand-picked selection** |
| **Clear all** on `/my-tickets` | Resets to **the route's preset**, not to an empty queue |
| **Clear all** on `/tickets` | Unchanged — resets to nothing |
| Nav badge | `stats.data.mine_open`, rendered **only when `> 0`** |
| `/tickets` | Completely unchanged in behaviour and in every existing `data-testid` |

---

## Decision — one view, two routes. No `MyTicketsView.vue`, no `TicketTable.vue`

AC2 says *"reuses the same table and filter components as the main list rather than duplicating them"*. Story 19 puts the table **inline in `TicketListView.vue`** (its task 12) rather than in a component, so there are two ways to satisfy AC2:

- **Extract `TicketTable.vue` + `TicketPager.vue` from `TicketListView.vue`, then compose a new `MyTicketsView.vue` from them.** Three files touched, two created, and every `data-testid` in Story 19's and Story 20's specs moves house.
- **Register `/my-tickets` against `TicketListView.vue` itself, and switch on `route.meta.scope`.** One route entry, one new pure function, and a handful of lines in the existing view.

**Take the second.** It is the strongest possible reading of AC2 — the table is not merely a shared component, it is literally the same component instance — and it is the smaller diff for a 2-point story. It also means a column added to the queue tomorrow appears on both screens with no second edit.

The cost is honest and bounded: `TicketListView.vue` gains a mode flag. **Every existing `data-testid` stays exactly as Story 19 defined it** (`tickets-table`, `tickets-row`, `tickets-empty`, `tickets-count`, `tickets-prev`, `tickets-next`, `tickets-per-page`, `tickets-age`), so Stories 19, 20 and 25's specs keep passing untouched. The only new ids are `my-tickets-include-done` and `nav-my-tickets`.

**Do not extract a table component "while you are in there."** If a third consumer ever appears, that is the story that earns the extraction.

---

## Context — Read These Files First

1. [`../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md`](../ticket-creation-tracking/19-story-paginated-ticket-list-TM-23.md) — **task 12** is the view this story extends: the four mutually-exclusive states, the eight `data-testid` values, and the empty-state rule (*"the empty state must test `!store.error` as well"*). **Task 13** registers `/tickets` as route name `tickets` and adds the `nav-tickets` link to `App.vue`. Task 5's store owns `items`, `meta`, `page`, `perPage`, `loading`, `error` and the `latestRequest` guard.
2. [`../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md`](../ticket-creation-tracking/20-story-filter-and-sort-the-ticket-queue-TM-24.md) — **task 6** for the filter refs (`statusIds`, `priorityIds`, `categoryIds`, `assignedTo`, `escalated`, `sort`, `direction`), `activeFilterCount`, `applyFilters()`, `setSort()` and **`clearAll()`**, which task 5 below modifies; **task 7** for `lib/ticketQuery.ts`, `TicketQueryState`, `toQuery`/`fromQuery` and the rule that `fromQuery` **must be total**; **task 8** for `TicketFilterBar.vue` and its `filter-clear` button; **task 9** for the URL-sync block and **the mandatory `syncing` flag**.
3. [`../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md`](../ticket-creation-tracking/25-story-dashboard-with-queue-statistics-TM-29.md) — **task 5** for `TicketStats` and `mine_open`; **task 6** for `stores/stats.ts` (`data`, `error`, `loading`, `load()` — and **no `clear()`**, which task 7 below adds); **task 8** for `DashboardView`'s `openStatusIds` computed and its `stat-mine-open` card, whose link is `{ name: 'tickets', query: { assignee: 'me', status: openStatusIds.join(',') } }`. **That card link and this story's preset must describe the same set** — see the note in task 2.
4. `frontend/src/stores/masterData.ts` — **`ensureLoaded()` at line 10** (idempotent, returns the in-flight promise), `statuses` at **line 8**, and the `computed` idiom at **15–16**. Task 2 adds two computeds here.
5. `frontend/src/router/guards.ts` — **line 37: `void useMasterDataStore().ensureLoaded()`**. The `void` is the whole reason task 4 must `await ensureLoaded()` itself: the guard starts the load and does not wait for it, so on a cold navigation `statuses` is still `[]` when the view runs.
6. `frontend/src/router/index.ts` — the `declare module 'vue-router'` `RouteMeta` block at **13–18** (`public?`, `role?`), and the route list at **23–51**. Task 3 adds one field and one route.
7. `frontend/src/stores/auth.ts` — **`clear()` at 47–53 already calls `useMasterDataStore().clear()`**. That is the precedent task 7 follows for the stats store, and `isAuthenticated` (**13**) is what task 8 watches.
8. `frontend/src/App.vue` — the nav at **22–25** and the exact link classes (`class="hover:text-indigo-600"`). Task 8 adds one link there. Note the header is `v-if="auth.isAuthenticated"` (**18**) but **`App` itself is mounted once, while unauthenticated** — which is why task 8 uses a watcher, not `onMounted`.
9. `backend/database/seeders/StatusSeeder.php:13–19` — the seven statuses and which two are terminal. Use these names in the by-hand verification.

---

## Measured facts that decide these tasks

Measured this session with a throwaway Vitest spec against the project's own `vue-router` and `@vue/test-utils` (`vue-router ^4.6.4`, `vitest ^4.1.11`).

- **Navigating `/tickets` → `/my-tickets` does NOT remount the shared component.** Measured with `onMounted`/`onUnmounted` probes on one component registered against two routes: the transition logged **no** `unmounted` and **no** `mounted` — only the watchers fired. **So an `onMounted`-only preset never runs when the user clicks the nav link from `/tickets`, and AC1 silently fails.** This single fact dictates task 4's shape.

- **`route.meta` is reactive across that transition.** The same probe logged `metaWatch: undefined -> mine`, and `watch(() => route.fullPath)` logged `/tickets -> /my-tickets`. Either is a usable signal; task 4 watches `fullPath` because it also covers a query change on the same path, which `meta` does not.

- **Leaving to a different component and coming back does remount.** `/my-tickets → /other → /my-tickets` logged `unmounted` then `mounted:/my-tickets:scope=mine`. **So both entry paths exist** — a watcher-only implementation misses the remount case and an `onMounted`-only one misses the reuse case. Task 4 wires the *same* function to both, which is why it must be idempotent.

- **`router.replace({ query })` preserves the current path.** Measured on `/my-tickets`: after `replace({ query: { assignee: 'me' } })` the full path was `/my-tickets?assignee=me`. Story 20's URL-sync block therefore needs **no change** to work on the second route — it never names a path.

- **`Object.keys(route.query).length` is `0` on a bare `/my-tickets` and `1` after that replace.** That is the "did the user bring a URL" test task 4 uses, and it is why the preset must be applied **before** the first `replace` writes the query — after it, the same navigation would take the `fromQuery` branch.

- **Five non-terminal statuses, two terminal.** Read from `StatusSeeder.php:13–19`: `new`, `open`, `in-progress`, `pending`, `reopened` are `is_terminal = false`; `resolved` and `closed` are `true`. So the preset URL is five comma-joined ids — verbose, but **identical in shape to the `stat-mine-open` card link Story 25 already ships**, so it introduces no new URL vocabulary.

---

## Frontend Tasks

### 1 — Export the default state from `lib/ticketQuery.ts`

**File: `frontend/src/lib/ticketQuery.ts`** (Story 20's task 7)

`fromQuery` already needs a set of defaults to fall back to. **Name and export them**, so the preset and `clearAll()` cannot drift from what `fromQuery` returns for a bare URL:

```ts
export const EMPTY_QUERY_STATE: TicketQueryState = {
  statusIds: [], priorityIds: [], categoryIds: [],
  assignedTo: '', escalated: null,
  sort: 'created_at', direction: 'desc', page: 1,
}
```

Have `fromQuery` build its result from a **copy** of this (`{ ...EMPTY_QUERY_STATE }`) rather than from a fresh literal. **A copy, not the constant itself** — `fromQuery` mutates its result, and handing out the shared object would let one call's `statusIds` array leak into the next.

### 2 — `presetFor()`, and the terminal-status computeds

**File: `frontend/src/lib/ticketQuery.ts`**

```ts
/** AC1: assigned to me, terminal statuses excluded. Pure, so it is testable without a router. */
export function presetFor(openStatusIds: number[]): TicketQueryState {
  return { ...EMPTY_QUERY_STATE, assignedTo: 'me', statusIds: [...openStatusIds] }
}
```

**File: `frontend/src/stores/masterData.ts`**

Add two computeds beside `activeCategories` (**line 15**):

```ts
const openStatusIds = computed(() => statuses.value.filter((entry) => !entry.is_terminal).map((entry) => entry.id))
const terminalStatusIds = computed(() => statuses.value.filter((entry) => entry.is_terminal).map((entry) => entry.id))
```

Export both.

- **`!is_terminal`, never `bucket === 'open'`.** `bucket` has three values and a `pending` ticket is still on someone's plate — Story 25's plan settles this and its `mine_open` figure uses `is_terminal = false`. **The badge in task 8 and the preset here must mean the same thing**, or the count will disagree with the row count on screen.
- **Story 25's `DashboardView` keeps its own `openStatusIds` computed off `stats.data.by_status`. Do not refactor it to use this one.** Its plan gives the reason: `by_status` carries `is_terminal` so the card link is right on first paint without waiting for master data. Two derivations of one definition is a real cost, so record it: **`masterData` is the authority; the `stats` copy exists only to avoid a blocking await on the dashboard.** If they ever disagree, the bug is in the API response.

### 3 — The route

**File: `frontend/src/router/index.ts`**

Extend the `RouteMeta` interface (**13–18**):

```ts
scope?: 'mine'
```

And add the route **after** `/tickets` and **before** `/tickets/new`:

```ts
{ path: '/my-tickets', name: 'my-tickets', component: TicketListView, meta: { scope: 'mine' } },
```

- **The same `TicketListView` import Story 19 already added.** No new import, no new view file.
- **No `meta.role`.** An admin may open it: TM-31's invariant stops them being *newly* assigned, but a demoted agent still holds tickets, and `mine_open` is defined as the caller's own for both roles (Story 25's plan, its `mine_open` table row). Gating the route would hide real work.
- **`/my-tickets`, not `/tickets/mine`.** `/tickets/:id` is Story 22's detail route, and `/tickets/mine` would be caught by it and 404 on a non-numeric id.

### 4 — Preset hydration in the shared view

**File: `frontend/src/views/TicketListView.vue`**

Replace Story 20's task-9 `onMounted` and its `route.query` watcher with **one function wired to both signals**:

```ts
const route = useRoute()
const store = useTicketsStore()
const masterData = useMasterDataStore()

async function hydrateFromRoute(): Promise<void> {
  if (syncing) return
  if (route.meta.scope === 'mine' && Object.keys(route.query).length === 0) {
    // The guard starts master data with `void ensureLoaded()` (guards.ts:37) and
    // does NOT await it, so `statuses` can still be [] here. Without this await
    // openStatusIds is empty, the preset carries no status filter, and AC1
    // silently ships resolved and closed tickets. ensureLoaded() is idempotent
    // and returns the in-flight promise, so this costs nothing when warm.
    await masterData.ensureLoaded()
    store.preset = presetFor(masterData.openStatusIds)
    Object.assign(store, store.preset)
  } else {
    // The URL always wins — a shared or bookmarked /my-tickets?… link must show
    // what it says, not the preset.
    store.preset = route.meta.scope === 'mine' ? presetFor(masterData.openStatusIds) : null
    Object.assign(store, fromQuery(route.query))
  }
  await store.load()
}

// Measured: /tickets → /my-tickets does NOT remount this component (same
// component on both routes), so onMounted alone never applies the preset. And
// leaving to another component and returning DOES remount, so a watcher alone
// misses that path. Both signals, one idempotent handler.
onMounted(hydrateFromRoute)
watch(() => route.fullPath, hydrateFromRoute)
```

- **`store.preset` is set on both branches of the `mine` route**, because AC3's toggle and task 5's `clearAll()` need the route's baseline even when the user arrived with a URL.
- **`if (syncing) return` at the top.** Story 20's URL-sync watcher sets that flag while it writes the query; without this guard, applying the preset triggers `replace`, which re-enters this handler and re-runs `store.load()` — two requests for one navigation.
- **Keep Story 20's store→URL watcher and its `syncing` flag exactly as they are.** Measured: `replace({ query })` preserves the path, so it already works on `/my-tickets` with no edit.
- **The preset write must happen before the first `replace`.** After it, `route.query` is non-empty and the same navigation would take the `fromQuery` branch — which is correct on a *later* navigation and wrong on this one. The ordering above is what makes that true.

Then two rendering changes, **both scoped to the `mine` route**:

```ts
const isMine = computed(() => route.meta.scope === 'mine')
const includeDone = computed({
  get: () => store.statusIds.length === 0 || store.statusIds.some((id) => masterData.terminalStatusIds.includes(id)),
  set: (value) => {
    // Off means "show my open work", so it resets to the preset's status set
    // rather than subtracting the two terminal ids from a hand-picked list —
    // subtracting would leave a filter the toggle did not put there.
    store.statusIds = value ? [] : [...masterData.openStatusIds]
    void store.applyFilters()
  },
})
const emptyMessage = computed(() =>
  isMine.value
    ? includeDone.value ? 'You have no tickets.' : 'You have no open tickets.'
    : 'No tickets on this page.',
)
```

| Element | `data-testid` | Rendering rule |
|---|---|---|
| Include-done toggle | `my-tickets-include-done` | `v-if="isMine"`, `<input type="checkbox" v-model="includeDone">`, label **"Include resolved and closed"** |
| Empty state | `tickets-empty` | **Unchanged id**, text is now `{{ emptyMessage }}` |

- **`includeDone` is derived, not a separate ref.** A ref would desync the moment the user picks statuses in `TicketFilterBar`. The getter answers the honest question — *"are terminal statuses currently visible?"* — so hand-picking `Resolved` in the filter bar ticks the box, which is correct.
- **The `set` discards a hand-picked selection when switching off.** That is the documented asymmetry; it is why the getter is a disjunction and the setter is an assignment. Test 10 pins it.
- **Reuse `tickets-empty`.** Story 19's spec targets that id and must keep passing; only the text varies. **Do not add `my-tickets-empty`.**
- **Nothing else in the view changes.** The table, the pager, the per-page select, the sortable headers and `<TicketFilterBar />` are all untouched — that is AC2.

### 5 — Preset-aware `clearAll()`

**File: `frontend/src/stores/tickets.ts`** (Story 20's task 6)

Add one ref and reset to it:

```ts
/** Set by TicketListView on the /my-tickets route. `clearAll()` returns here
 *  instead of to an empty queue, so the route's own filter is not the first
 *  thing "Clear all" destroys. */
const preset = ref<TicketQueryState | null>(null)

async function clearAll(): Promise<void> {
  const base = preset.value ?? EMPTY_QUERY_STATE
  statusIds.value = [...base.statusIds]
  priorityIds.value = [...base.priorityIds]
  categoryIds.value = [...base.categoryIds]
  assignedTo.value = base.assignedTo
  escalated.value = base.escalated
  sort.value = base.sort
  direction.value = base.direction
  page.value = 1
  await load()
}
```

Export `preset`.

- **`TicketFilterBar.vue` needs no change at all.** It already calls `clearAll()` (Story 20's task 8); the behaviour difference lives entirely in the store. That is the point of putting it here rather than branching in the component.
- **Spread every array.** Assigning `base.statusIds` directly would alias the preset object, and the next filter-bar selection would mutate the baseline `clearAll()` resets to.
- **On `/tickets` the behaviour is byte-for-byte unchanged**, because `preset` is `null` there and `EMPTY_QUERY_STATE` is what Story 20's version wrote by hand. Test 5 asserts it.
- Story 20's `filter-clear` `:disabled` expression compares against the hard defaults, so on `/my-tickets` the button is **enabled at the preset** and clicking it is a near no-op. **Leave it.** "Clear all" returning you to the route's baseline is the right behaviour, and making the disabled check preset-aware would mean threading the baseline into the component for a cosmetic gain.

### 6 — Keep the count live without polling

**File: `frontend/src/stores/tickets.ts`**

After a successful `assign()` (Story 26's task 10) and a successful `claim()` (Story 27's task 11), refresh the stats:

```ts
// AC4's "live": the count changes when assignment changes, so refresh on exactly
// those events. Do NOT poll — mine_open moves a few times a day and a timer
// would issue a 6-query request every N seconds forever.
void useStatsStore().load()
```

Place it **after** the `await loadTicket(id)` in each, and **`void`, not `await`** — the detail page must not wait on a nav badge.

- **Import direction is `tickets → stats`.** `stores/stats.ts` imports only `api/stats`, so there is no cycle. Calling one store from another action is idiomatic Pinia.
- **Forward constraint: TM-38 (status changes) must add the same call.** Moving a ticket into `resolved` or `closed` changes `mine_open` too, and without it the badge goes stale until the next sign-in. **Record this in the PR description**; it is the third and last mutation that touches the figure.
- If Story 26 or 27 has not landed, add the call to whichever has and note the other as owed.

### 7 — `clear()` on the stats store

**File: `frontend/src/stores/stats.ts`** (Story 25's task 6)

```ts
function clear(): void { data.value = null; error.value = null }
```

**File: `frontend/src/stores/auth.ts`**

Add to `clear()` (**47–53**), beside the master-data line:

```ts
useStatsStore().clear()
```

Without this, signing out and back in as a different user shows the **previous** user's `mine_open` in the badge until the new `load()` resolves. `masterData.clear()` at **line 48** is the precedent this follows exactly.

### 8 — The nav link and its badge

**File: `frontend/src/App.vue`**

```ts
const stats = useStatsStore()
const myOpenCount = computed(() => stats.data?.mine_open ?? 0)

// App is mounted once, while still unauthenticated, so onMounted would never
// fire for a signed-in session. `immediate` covers a hard reload with a stored
// token; the watch covers signing in without a remount.
watch(() => auth.isAuthenticated, (signedIn) => { if (signedIn) void stats.load() }, { immediate: true })
```

Add the link to the nav (**22–25**), **before** `nav-new-ticket`, matching its classes exactly:

```html
<RouterLink :to="{ name: 'my-tickets' }" class="hover:text-indigo-600" data-testid="nav-my-tickets">My tickets<span v-if="myOpenCount > 0" data-testid="nav-my-tickets-count">{{ myOpenCount }}</span></RouterLink>
```

- **The badge renders only when `> 0`.** A permanent "0" is noise, and it is what an admin would see on every screen — while a **demoted agent still holding tickets** sees their real number. One rule serves both.
- **AC4 says "sidebar"; this app has a header nav** (`App.vue:18–33`) and no sidebar. The header is the navigation chrome, so the count goes there. **Do not introduce a sidebar for one number** — that is a layout change no AC asks for. Note the substitution in the PR description.
- **`stats.load()` is called here as well as in `DashboardView`** (Story 25's task 8). Both are cheap and idempotent-enough; the dashboard needs the full payload on mount regardless of the header, and the header needs it on routes that are not the dashboard.
- **No `v-if="!auth.isAdmin"` on the link.** See the route note in task 3.

### 9 — Formatting

```bash
cd frontend && npx prettier --write src/lib/ticketQuery.ts src/stores/masterData.ts src/stores/tickets.ts src/stores/stats.ts src/stores/auth.ts src/router/index.ts src/views/TicketListView.vue src/App.vue src/lib/ticketQuery.spec.ts src/stores/tickets.spec.ts src/stores/stats.spec.ts src/views/TicketListView.spec.ts src/App.spec.ts
```

**Format only what you touch**, the same policy Stories 19, 20 and 25 set. `src/stores/masterData.ts` is one of the files already failing `format:check` before this story, so this clears one more of that backlog. **Do not run `npm run format` across the repo** to make CI green — ask first, as Story 19's plan says.

---

## 10 — Nothing changes on the backend

**No migration, no route, no controller, no request, no resource, no policy, no seeder, no `docs/api-contract.md` edit, and no backend test.** `GET /api/v1/tickets?assigned_to=me&status_id[]=…` and `GET /api/v1/tickets/stats` already answer all four criteria. Put this in the PR description; three of those files are ones a reviewer will look for.

---

## Edge Cases & Failure Modes

- **Clicking `nav-my-tickets` while already on `/tickets`** → the component is **not** remounted (measured), so the preset arrives from the `route.fullPath` watcher in task 4. **This is the story's primary failure mode**; test 12 navigates between the two routes specifically to catch it.
- **Hard-loading `/my-tickets` cold, before master data resolves** → `await masterData.ensureLoaded()` blocks the preset until `statuses` is populated. Skip that await and the preset ships `statusIds: []`, which renders resolved and closed tickets and looks like a working page. Test 13 mounts with an unresolved master-data store and asserts the request carries five status ids.
- **Master data fails to load entirely** → `ensureLoaded()` resolves, `masterData.error` is set, `openStatusIds` is `[]`, and the preset degrades to *assigned-to-me, all statuses*. **Correct degradation:** the scope the route promises is preserved and only the refinement is lost. `masterData.error` already surfaces through the existing banner; do not add a second one.
- **A shared link like `/my-tickets?status=5,6`** → the URL wins, so the recipient sees resolved and closed only, and `my-tickets-include-done` renders **ticked** (the getter finds terminal ids). Test 9.
- **`/my-tickets?assignee=unassigned`** → honoured. The route's *default* is "mine"; it is not a hard scope, and no criterion says otherwise. `clearAll()` still returns to the preset, which is the way back.
- **An agent with zero assigned tickets** → `tickets-empty` reads **"You have no open tickets."**, and with the toggle on, **"You have no tickets."** Not Story 19's "No tickets on this page.", which would be a lie about paging.
- **A failed request on `/my-tickets`** → `tickets-error` shows and `tickets-empty` stays hidden, because Story 19's empty state already tests `!store.error`. **Do not reintroduce `AdminUsersView.vue:54`'s bug** while editing the empty-state text.
- **Toggling `my-tickets-include-done` on page 7** → `applyFilters()` resets to page 1 (Story 20's task 6). Correct: page 7 of the open set rarely exists in the wider one.
- **Hand-picking `Resolved` in `TicketFilterBar`, then unticking the toggle** → the selection is replaced by the five open ids, not narrowed. Documented in task 4 and pinned by test 10.
- **The badge after claiming a ticket** → refreshed by task 6's `stats.load()`. After a **status change** it goes stale until the next sign-in, because TM-38 does not exist yet — recorded as a forward constraint, not hidden.
- **The badge on the login screen** → the header is `v-if="auth.isAuthenticated"`, and `auth.clear()` now clears the stats store, so no count survives a sign-out.
- **A stats request that 403s or fails** → `stats.data` stays `null`, `myOpenCount` is `0`, and the badge simply does not render. The nav link still works. **No error UI in the header** — a broken badge must not break navigation.
- **`stats.load()` racing with itself** (header watcher plus `DashboardView` mount on a hard load of `/`) → both resolve to the same payload; the store has no `latestRequest` guard and does not need one, because the response is idempotent and unordered writes of equal data are indistinguishable.
- **Back/forward between `/tickets` and `/my-tickets`** → the `route.fullPath` watcher fires on every history move, so each screen re-hydrates from what its URL says. The `syncing` guard stops the resulting `replace` from double-loading.

---

## Test Plan

**All frontend. No backend test is added or changed** — if `php artisan test` output moves, something is wrong with the diff.

### `frontend/src/lib/ticketQuery.spec.ts` (Story 20's file; extend)

1. `presetFor([1,2,3,4,7])` returns `assignedTo: 'me'`, `statusIds: [1,2,3,4,7]`, and every other field at its `EMPTY_QUERY_STATE` value.
2. `presetFor([])` returns `assignedTo: 'me'` with `statusIds: []` — the master-data-failure shape.
3. `presetFor(ids)` **copies** the array: mutating the result's `statusIds` does not change the input, and two calls do not share an array.
4. `fromQuery({})` deep-equals `EMPTY_QUERY_STATE` but is **not the same object** — the aliasing guard from task 1.

### `frontend/src/stores/tickets.spec.ts` (Story 19/20's file; extend)

5. `clearAll()` with `preset === null` resets to `EMPTY_QUERY_STATE` — **the `/tickets` regression**, asserting this story changed nothing there.
6. `clearAll()` with a preset set resets to the preset, including `assignedTo === 'me'` and the five status ids.
7. `clearAll()` does not alias the preset: after clearing, pushing onto `store.statusIds` leaves `store.preset.statusIds` untouched.
8. `assign()` and `claim()` each call `useStatsStore().load()` once on success and **not** on failure. (Whichever of Stories 26/27 has landed; skip and record the other.)

### `frontend/src/views/TicketListView.spec.ts` (Story 19/20's file; extend)

Mount with a memory-history router carrying both routes and a stubbed `masterData`.

9. Mounting at `/my-tickets?status=5,6` takes the `fromQuery` branch: the request carries `status_id: [5,6]`, `assignedTo` is **not** forced to `'me'`, and `my-tickets-include-done` is **ticked**.
10. **AC3, both directions.** Ticking the toggle requests with **no** `status_id`; unticking requests the five open ids — **including when the user had hand-picked `[5]` first**, which must become the five open ids and not `[]`.
11. Mounting at bare `/my-tickets` requests `assigned_to: 'me'` **and** the five non-terminal `status_id`s, and issues **exactly one** request (the `syncing` guard).
12. **The measured failure mode.** Mount at `/tickets`, then `router.push('/my-tickets')`, then assert the preset was applied and a second request went out. **Delete the `route.fullPath` watcher and this is the test that fails** — note that in the test's docblock.
13. Mounting at bare `/my-tickets` with an **unresolved** `ensureLoaded()` still requests five status ids — the `await` guard. Resolve the stub after mount and assert the single request carries them.
14. `my-tickets-include-done` is **absent** on `/tickets`, and `tickets-empty` there still reads "No tickets on this page."
15. On `/my-tickets` with an empty result, `tickets-empty` reads "You have no open tickets."; with the toggle on, "You have no tickets."
16. On `/my-tickets` with a failed request, `tickets-error` renders and `tickets-empty` does **not** — the `AdminUsersView` bug guard.

### `frontend/src/stores/stats.spec.ts` (Story 25's file; extend)

17. `clear()` nulls `data` and `error`.
18. `useAuthStore().clear()` clears the stats store — asserted through the auth store, so the wiring is covered and not just the method.

### `frontend/src/App.spec.ts` (new)

19. The badge renders `mine_open` when it is `> 0`, and `nav-my-tickets-count` is **absent** when it is `0` or when `stats.data` is `null`.
20. `nav-my-tickets` links to `/my-tickets` and is present for an **admin** as well as an agent.
21. `stats.load()` is called when `isAuthenticated` flips false → true, and **not** while unauthenticated.
22. A rejected `stats.load()` leaves the nav rendered and the badge absent — no error UI in the header.

---

## Verification Steps

1. **Gate:** from `frontend/`, confirm Stories 19, 20 and 25 have landed — `ls src/views/TicketListView.vue src/views/DashboardView.vue src/components/TicketFilterBar.vue src/lib/ticketQuery.ts src/stores/stats.ts`. **Stop if any is missing.**
2. **Frontend checks:** `npm run lint`, `npm run typecheck`, `npm test`. Expect **+22 tests** across one new and four extended spec files, and **zero** change to the backend suite.
3. **Prove test 12 earns its place:** delete the `watch(() => route.fullPath, hydrateFromRoute)` line, re-run `npx vitest run src/views/TicketListView.spec.ts`, confirm the navigation test **fails**, restore.
4. **Prove test 13 earns its place:** remove the `await masterData.ensureLoaded()`, re-run the same file, confirm the cold-load test **fails** with an empty `status_id`, restore.
5. **Prove test 5 earns its place:** make `clearAll()` reset unconditionally to the preset, re-run, confirm the `/tickets` regression **fails**, restore.
6. **Formatting:** `npx prettier --check` over task 9's list.
7. **By hand**, `npm run dev`, signed in as an **agent** who holds a mix of open and resolved tickets:
   - The header shows **My tickets** with a badge equal to the dashboard's "My open tickets" card. *(AC4 — the two must agree; if they do not, `openStatusIds` and `mine_open` disagree on "open".)*
   - Click **My tickets** from the dashboard → the URL becomes `/my-tickets?assignee=me&status=<five ids>`, the assignee select reads **Me**, and **no `Resolved` or `Closed` row is in the table**. *(AC1.)*
   - Go to **Tickets**, then click **My tickets** again → **the preset still applies.** *(The measured no-remount path — this is the one to check twice.)*
   - Tick **Include resolved and closed** → resolved and closed rows appear, the URL drops `status`, and the pager resets to page 1. *(AC3.)* Untick → they disappear again.
   - Pick `Resolved` by hand in the filter bar → the toggle **ticks itself**. Untick it → the filter becomes the five open statuses, not empty.
   - Click **Clear all** → you are back at the preset, **not** at the whole queue. Then do the same on `/tickets` → you get the whole queue. *(The two must differ.)*
   - Sort by Priority, page to 2, then reload → the URL restores exactly that view.
   - Paste `/my-tickets?status=5,6` in a fresh tab → resolved and closed only, toggle ticked.
   - Claim an unassigned ticket (Story 27) → **the badge goes up by one without a reload.** Assign a ticket away as an admin (Story 26) → the affected agent's badge is correct on their next navigation.
   - Sign out → sign in as a **different** agent → the badge shows the new person's number, never the previous one's.
   - As an **admin**: the My tickets link is present, the badge is **absent**, and `/my-tickets` renders "You have no open tickets."
   - Stop `php artisan serve`, reload `/my-tickets` → `tickets-error`, **no** empty-state text underneath, and the nav still works with no badge.
8. **Regression:** `/tickets` behaves exactly as Stories 19 and 20 shipped it — no toggle, "No tickets on this page." when empty, `Clear all` clears to nothing, every filter and sort intact. Then open the dashboard and click all three cards to confirm none of them changed.

---

## Done Criteria

- [ ] `/my-tickets` exists as route name `my-tickets` with `meta: { scope: 'mine' }`, needs no role, and renders **`TicketListView.vue`** — **no `MyTicketsView.vue` and no `TicketTable.vue` were created**.
- [ ] A bare `/my-tickets` applies `assigned_to=me` **and** the five non-terminal status ids, in **one** request.
- [ ] The preset applies when navigating `/tickets` → `/my-tickets`, where the component is **not remounted** — proven by a test that fails when the `route.fullPath` watcher is removed.
- [ ] The preset waits for `masterData.ensureLoaded()`, proven by a test that fails when the `await` is removed.
- [ ] A `/my-tickets?…` URL always wins over the preset.
- [ ] `my-tickets-include-done` reveals and re-hides resolved and closed tickets, is derived from `statusIds` rather than held in its own ref, and is absent on `/tickets`.
- [ ] `Clear all` resets to the **preset** on `/my-tickets` and to **nothing** on `/tickets`, with no array aliasing between the two.
- [ ] `TicketFilterBar.vue` was **not modified**, and every `data-testid` Story 19 defined is unchanged — the only new ids are `my-tickets-include-done`, `nav-my-tickets` and `nav-my-tickets-count`.
- [ ] The empty state reads "You have no open tickets." / "You have no tickets." on `/my-tickets` and is still suppressed when `store.error` is set.
- [ ] `nav-my-tickets` is in the header for both roles; the count badge renders only when `mine_open > 0`, refreshes after an assign and after a claim, and **is never polled**.
- [ ] `stats.clear()` exists and `auth.clear()` calls it, so one user's count never survives into another's session.
- [ ] `masterData` exposes `openStatusIds` and `terminalStatusIds` derived from **`is_terminal`, not `bucket`**, and Story 25's `DashboardView` computed was left alone with the reason recorded.
- [ ] **No backend file changed** — no migration, route, controller, request, resource, policy, seeder, API-contract edit or backend test.
- [ ] The PR description records: the header-instead-of-sidebar substitution for AC4, the two derivations of "open", and that **TM-38 must also refresh the stats** when it lands.
- [ ] `lint`, `typecheck` and `prettier --check` clean on the touched files; **+22 frontend tests** over the baseline, with four existing spec files extended rather than duplicated.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 29 (TM-34, reassign or unassign with a reason).**
