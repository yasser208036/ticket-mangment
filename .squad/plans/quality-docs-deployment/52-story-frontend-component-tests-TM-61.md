# Story 52 — Frontend component tests (Story: TM-61)

## Prerequisites

- None. This story touches only `frontend/`, plus one shared CI workflow line. It does not depend on Story 50 or Story 51 (both backend-only) and nothing in `frontend/` depends on their outcome.
- **The frontend is well past "stock Vite scaffold."** `frontend/src/` already has 25 `.vue` components/views, 9 Pinia stores, and 16 existing `.spec.ts` files. This story's job is narrower than a first read of the intake suggests — see the audit below.

---

## What already exists — audit before you write

| AC | Status | Evidence |
|---|---|---|
| **AC1** — TicketTable: rendering, empty state, loading state | **Partial.** There is no `TicketTable.vue` — the table is inline in `TicketListView.vue` (**lines 196–415**). `TicketListView.spec.ts` exists (**154 lines**) but its 3 tests cover only the escalation column and the escalated-sort header; **general row rendering, the empty state, and the loading state have no test.** | `src/views/TicketListView.spec.ts:107–154` |
| **AC2** — TicketFilters: filter change, clear-all | **No coverage.** There is no `TicketFilters.vue` — the component is `TicketFilterBar.vue` (**283 lines**). No `TicketFilterBar.spec.ts` exists. | `grep -rl TicketFilterBar src/**/*.spec.ts` → no output |
| **AC3** — TicketForm: validation, successful submit, double-submit guard | **No coverage.** There is no `TicketForm.vue` — the form is inline in `NewTicketView.vue` (**403 lines**). No `NewTicketView.spec.ts` exists. | `find src -iname "NewTicketView.spec.ts"` → no output |
| **AC4** — auth store: login, logout, rehydration | **Done. Do not rebuild.** `stores/auth.spec.ts` (**94 lines, 6 tests**) already covers login (`it('login stores token and user')`), logout including the failure path (`it('logout clears even when request fails')`), and rehydration three ways: concurrent-caller dedup, clearing on a dead-token 401, and preserving the token on a network failure. | `src/stores/auth.spec.ts:38,50,59,72,81` |
| **AC5** — `npm run test:unit` passes in CI | **The script does not exist.** `package.json:10` defines `"test": "vitest run"`, not `"test:unit"`. `ci.yml`'s frontend job runs `npm test` (**line 88**). The AC names a command this repo has never had. | `package.json:6–17`; `.github/workflows/ci.yml:86–88` |

**The honest shape of this story: audit and close AC4; add the missing rendering/empty/loading tests to the existing `TicketListView.spec.ts`; write two new spec files for `TicketFilterBar.vue` and `NewTicketView.vue`; add the literal `test:unit` script the AC names and point CI at it.**

---

## Decision — "TicketTable"/"TicketFilters"/"TicketForm" are the intake's names for real components with different names; no component is renamed

The intake describes three components by name. None of the three exists as a standalone file:

- **"TicketTable"** is the `<table data-testid="tickets-table">` block inside `TicketListView.vue` (**196–415**), alongside the loading (**157–184**), error (**187–193**), and empty (**508–538**) states the same view renders. **Tests go into `TicketListView.spec.ts`, extended, not a new file** — that file already exists and already mounts this exact view.
- **"TicketFilters"** is `TicketFilterBar.vue`. **New file: `TicketFilterBar.spec.ts`.**
- **"TicketForm"** is the `<form data-testid="new-ticket-form">` inside `NewTicketView.vue` (**144–400**) — there is no separate form component to extract, and this story does not extract one. **New file: `NewTicketView.spec.ts`.**

No `.vue` file is renamed or split. Do not create `TicketTable.vue`, `TicketFilters.vue`, or `TicketForm.vue` — that would be a refactor this story's ACs do not ask for and did not budget review for.

## Decision — "emits the expected query" means the shared `tickets` store's filter fields and reload call, not a Vue `emit`

`TicketFilterBar.vue` has no `defineEmits` anywhere in the file — every control writes straight into `useTicketsStore()`'s reactive fields and calls `apply()` (→ `tickets.applyFilters()`) or, for search, `tickets.applySearch()`. There is nothing to *emit* in the Vue sense. **The tests assert the store's fields after an interaction, and that the store's own load path was invoked** (spy on `applyFilters`/`setSort`/`clearAll`/`applySearch` from `stores/tickets.ts`). The actual query-string construction from that store state is `lib/ticketQuery.ts:toQuery()`, which **already has its own spec** (`ticketQuery.spec.ts`) — this story does not re-test that translation, only that `TicketFilterBar` drives the store correctly.

## Decision — `masterData` is seeded by direct assignment, not by mocking three more API modules

`TicketFilterBar` mounts, and any authenticated route's navigation guard (`router/guards.ts:38`, `void useMasterDataStore().ensureLoaded()`) fires a **background** load of categories/priorities/statuses. `ensureLoaded()` (`stores/masterData.ts:29–41`) short-circuits to a no-op the moment all three arrays are non-empty **before** it is called. Every new test in this story that needs seeded master data sets `useMasterDataStore().statuses = [...]` (etc.) directly on the store **before** mounting or navigating — exactly the pattern `TicketListView.spec.ts:89` already uses for `useAuthStore().user`. This avoids mocking `api/categories.ts`, `api/priorities.ts`, and `api/statuses.ts` in three new files for no behavioural gain.

## Decision — `npm run test:unit` is added as a real script, and CI is pointed at it

`package.json` has never had a `test:unit` script; `"test": "vitest run"` is what CI and `CLAUDE.md` both currently call. Rather than treat the AC's wording as a typo for `npm test`, this story adds the literal script the AC names — one line, harmless, and it makes the AC's own sentence true instead of "close enough":

```json
"test:unit": "vitest run",
```

placed beside `"test"` in `package.json`. `.github/workflows/ci.yml`'s **Vitest** step (**line 88**, `run: npm test`) is changed to `run: npm run test:unit` so CI runs the exact command the AC names — both scripts run the identical `vitest run`, so this changes nothing about what executes, only what it is called.

---

## Context — Read These Files First

1. `frontend/src/views/TicketListView.spec.ts` — **154 lines.** `ticketRow()` (**17–66**) and `paginated()` (**68–82**) builders, and the `mountList()` helper (**84–105**) — every new test in task 1 is a new `describe` block in this same file, reusing these three functions unedited.
2. `frontend/src/views/TicketListView.vue` — **540 lines.** Loading block **157–184** (`data-testid="tickets-loading"`); error block **187–193**; table **196–415** (row **315–412**, reference link **325–331**, age cell **405–411** with `data-testid="tickets-age"`); empty block **508–538** (`data-testid="tickets-empty"`, **line 531**); `emptyMessage` computed **46–52**; `includeDone` computed **37–45**.
3. `frontend/src/components/TicketFilterBar.vue` — **283 lines, all of it.** Search input with 300ms debounce (**13–17**, `data-testid="filter-search"`); multi-selects for status/priority/category (**93–162**, `data-testid="filter-status"`/`filter-priority`/`filter-category`); assignee (**166–189**, `filter-assignee`, admin-only user list at **182**); escalated (**191–209**, `filter-escalated`, via `changeEscalation` **30–34**); sort/direction (**211–241**, `filter-sort`/`filter-direction`); clear button (**243–277**, `filter-clear`, disabled condition **247–251**).
4. `frontend/src/stores/tickets.ts` — **283 lines.** `applyFilters()` **194–197**, `applySearch()` **198–214** (the debounce-adjacent sort-to-relevance rule), `setSort()` **215–223**, `clearAll()` **224–236** (resets to `preset` or `EMPTY_QUERY_STATE`), `activeFilterCount` **43–51**.
5. `frontend/src/views/NewTicketView.vue` — **403 lines, all of it.** `form`/`errors` reactive state (**15–22**); `validate()` (**29–41**, five client-side rules); `submit()` (**43–57**, the `submitting.value ||` guard at **44** is the double-submit guard); the form's `data-testid`s: `new-ticket-form`, `new-ticket-requester-name/-email/-phone/-company`, `new-ticket-subject`, `new-ticket-category`, `new-ticket-priority`, `new-ticket-description`, `new-ticket-submit`, `new-ticket-error` (used twice, **129** and **137** — only one renders at a time in every test scenario below), `new-ticket-error-requester.name` (**181**, the only field with its own distinct error testid).
6. `frontend/src/stores/masterData.ts` — **97 lines.** `ensureLoaded()` **29–41**'s short-circuit condition; `activeCategories` **64–66**.
7. `frontend/src/router/guards.ts` — **38 lines, all of it.** `authGuard()` **28–38**: `void useMasterDataStore().ensureLoaded()` at **37** fires on every authenticated navigation — the reason task 2 and task 3 pre-seed `masterData` before mounting.
8. `frontend/src/router/index.ts` — routes `tickets` (**61**), `my-tickets` with `meta: { scope: 'mine' }` (**63–66**), `new-ticket` (**68**).
9. `frontend/src/components/TicketNoteComposer.spec.ts:120–142` — **the double-submit idiom this story's task 3 copies**: mock the API call to return an unresolved `Promise`, trigger submit twice, assert the call count is 1 and the button carries `disabled`, then resolve and `flushPromises()`.
10. `frontend/src/stores/auth.spec.ts` — **94 lines, 6 tests, audited only.** `it('hydrates once for concurrent callers')` (**50–57**) is the rehydration test AC4 asks for.
11. `frontend/package.json:6–17` — the `scripts` block task 4 edits.
12. `.github/workflows/ci.yml:62–88` — the frontend job; **line 88** is the one line task 4 edits.
13. `frontend/src/api/tickets.ts:79` — `createTicket()`'s signature, and `CreateTicketPayload` (**67–77**) — the payload shape task 3's submit tests assert.

---

## Product rules (from story)

| Situation | Current behaviour | New behaviour |
|---|---|---|
| `TicketListView`'s loading, empty, and general row rendering | Untested | Covered |
| `TicketFilterBar` changing any filter | Untested | Store field + reload call asserted |
| `TicketFilterBar`'s search box | Untested | Debounce timing and the `relevance` sort rule asserted |
| `TicketFilterBar`'s Clear button | Untested | Resets every field to `preset`/`EMPTY_QUERY_STATE` and reloads |
| `NewTicketView`'s client-side validation | Untested | All five rules asserted, in order, with no request sent |
| `NewTicketView`'s successful submit | Untested | Correct payload, navigation to the new ticket |
| `NewTicketView`'s double submit | Untested | `createTicket` called once, button disabled meanwhile |
| `NewTicketView`'s server-side 422 | Untested | Field errors merged and rendered |
| `npm run test:unit` | **Does not exist** | Exists, and CI calls it |

---

## Implementation tasks

### 1 — Extend `TicketListView.spec.ts` (AC1)

**File: `frontend/src/views/TicketListView.spec.ts`** — add new `describe` blocks below the existing one, reusing `ticketRow()`, `paginated()`, and `mountList()` unedited.

```ts
describe('TicketListView rendering', () => {
  beforeEach(() => {
    vi.mocked(listTickets).mockReset()
  })

  it('renders one row per ticket with subject, requester, category, priority, status, and assignee', async () => {
    vi.mocked(listTickets).mockResolvedValue(
      paginated([
        ticketRow({ id: 1, subject: 'Printer jam', assignee: null }),
        ticketRow({
          id: 2,
          subject: 'VPN down',
          assignee: { id: 5, name: 'Sam', email: 'sam@example.test', role: 'agent', is_active: true, created_at: '2026-08-25T00:00:00Z' },
        }),
      ]),
    )
    const { wrapper } = await mountList()
    const rows = wrapper.findAll('[data-testid="tickets-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Printer jam')
    expect(rows[0].text()).toContain('Req')
    expect(rows[0].text()).toContain('Hardware')
    expect(rows[0].text()).toContain('Medium')
    expect(rows[0].text()).toContain('New')
    expect(rows[0].text()).toContain('Unassigned')
    expect(rows[1].text()).toContain('Sam')
  })

  it('shows the loading state before the response resolves, and the table after', async () => {
    let resolveList: (value: ReturnType<typeof paginated>) => void = () => {}
    vi.mocked(listTickets).mockReturnValue(
      new Promise((resolve) => {
        resolveList = resolve
      }),
    )
    const { wrapper } = await mountList()
    expect(wrapper.find('[data-testid="tickets-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="tickets-table"]').exists()).toBe(false)

    resolveList(paginated([ticketRow()]))
    await flushPromises()
    expect(wrapper.find('[data-testid="tickets-loading"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="tickets-table"]').exists()).toBe(true)
  })

  it('shows the default empty message with no filters applied', async () => {
    vi.mocked(listTickets).mockResolvedValue(paginated([]))
    const { wrapper } = await mountList()
    expect(wrapper.get('[data-testid="tickets-empty"]').text()).toBe(
      'No tickets on this page.',
    )
  })

  it('shows scope-specific empty messages on /my-tickets', async () => {
    useMasterDataStore().statuses = [
      { id: 1, name: 'New', slug: 'new', bucket: 'open', color: '#3B82F6', is_default: true, is_terminal: false, sort_order: 10 },
      { id: 5, name: 'Resolved', slug: 'resolved', bucket: 'done', color: '#10B981', is_default: false, is_terminal: true, sort_order: 50 },
    ]
    vi.mocked(listTickets).mockResolvedValue(paginated([]))
    const { wrapper } = await mountList('/my-tickets')
    expect(wrapper.get('[data-testid="tickets-empty"]').text()).toBe(
      'You have no open tickets.',
    )

    await wrapper.get('[data-testid="my-tickets-include-done"]').setValue(true)
    await flushPromises()
    expect(wrapper.get('[data-testid="tickets-empty"]').text()).toBe(
      'You have no tickets.',
    )
  })
})
```

Add `import { useMasterDataStore } from '../stores/masterData'` to the file's existing import block. Note `mountList()` calls `setActivePinia(pinia)` **before** `useAuthStore().user = …` (**line 88–89** of the current file) — the new `/my-tickets` test sets `useMasterDataStore().statuses` in the same place, after `setActivePinia` and before `mountList()`'s own `createPinia()`/navigation, i.e. inline in the test body immediately before calling `mountList('/my-tickets')` (the store must be set on the **same** active Pinia instance `mountList` is about to use — call `setActivePinia` once at the top of the test, assign the store field, then call a version of `mountList` that accepts an already-active pinia, or simply set the field again after `mountList` returns and re-trigger via `router.push` — resolve this ordering concretely against the real `mountList` body while implementing; the fixture values above are correct, the sequencing is the one thing to verify against the live component).

### 2 — Create `TicketFilterBar.spec.ts` (AC2)

**Create file: `frontend/src/components/TicketFilterBar.spec.ts`**

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { listTickets } from '../api/tickets'
import { listUsers } from '../api/users'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'
import TicketFilterBar from './TicketFilterBar.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listTickets: vi.fn(),
}))
vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listUsers: vi.fn(),
}))

function seedMasterData(): void {
  useMasterDataStore().statuses = [/* one row, shape from TicketListView.spec.ts's status fixture */]
  useMasterDataStore().priorities = [/* one row */]
  useMasterDataStore().categories = [/* one row */]
}

function mountBar(role: 'agent' | 'admin' = 'agent') {
  const pinia = createPinia()
  setActivePinia(pinia)
  seedMasterData()
  useAuthStore().user = { id: 1, name: 'U', email: 'u@example.test', role, is_active: true, created_at: '2026-08-25T00:00:00Z' }
  vi.mocked(listTickets).mockResolvedValue(/* empty paginated() shape */)
  const wrapper = mount(TicketFilterBar, { global: { plugins: [pinia] } })
  return { wrapper, tickets: useTicketsStore() }
}

describe('TicketFilterBar', () => {
  beforeEach(() => {
    vi.mocked(listTickets).mockReset()
    vi.mocked(listUsers).mockReset()
  })

  it('selecting a status option sets statusIds and reloads', async () => { /* select filter-status, assert tickets.statusIds and listTickets called with status_id */ })
  it('selecting escalated="true" sets escalated and reloads; back to "" clears it', async () => { /* filter-escalated */ })
  it('changing sort and direction calls setSort with both values', async () => { /* filter-sort, filter-direction */ })
  it('debounces the search box by 300ms before calling applySearch', async () => {
    vi.useFakeTimers()
    /* setValue on filter-search, advance 299ms -> not yet, advance 1ms more -> applySearch called with trimmed term */
    vi.useRealTimers()
  })
  it('Clear disables when nothing is active and re-enables once a filter is set', async () => { /* assert disabled attribute both ways */ })
  it('Clear resets every field to the empty state and reloads', async () => { /* set several fields, click filter-clear, assert tickets.statusIds/.q/.sort/.direction back to defaults */ })
  it('loads the real user list only for an admin', async () => {
    vi.mocked(listUsers).mockResolvedValue(/* paginated users */)
    mountBar('admin')
    await flushPromises()
    expect(listUsers).toHaveBeenCalled()
  })
  it('does not load users for an agent, and the assignee select offers only Anyone/Me/Unassigned', async () => {
    const { wrapper } = mountBar('agent')
    await flushPromises()
    expect(listUsers).not.toHaveBeenCalled()
    expect(wrapper.findAll('[data-testid="filter-assignee"] option')).toHaveLength(3)
  })
})
```

Fill in the fixture shapes from `TicketListView.spec.ts`'s existing `status`/`priority`/`category` object literals (**44–54** of that file) rather than inventing new ones.

### 3 — Create `NewTicketView.spec.ts` (AC3)

**Create file: `frontend/src/views/NewTicketView.spec.ts`**

```ts
import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createTicket } from '../api/tickets'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import NewTicketView from './NewTicketView.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  createTicket: vi.fn(),
}))

async function mountForm() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = { id: 1, name: 'Agent', email: 'agent@example.test', role: 'agent', is_active: true, created_at: '2026-08-25T00:00:00Z' }
  useMasterDataStore().categories = [{ id: 3, name: 'Hardware', slug: 'hardware', color: '#111', is_active: true, sort_order: 1 }]
  useMasterDataStore().priorities = [{ id: 2, name: 'Medium', slug: 'medium', level: 2, color: '#F59E0B', is_default: true }]
  useMasterDataStore().statuses = [{ id: 1, name: 'New', slug: 'new', bucket: 'open', color: '#3B82F6', is_default: true, is_terminal: false, sort_order: 10 }]
  const router = createAppRouter(createMemoryHistory())
  await router.push('/tickets/new')
  await router.isReady()
  const wrapper = mount(NewTicketView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('NewTicketView', () => {
  beforeEach(() => {
    vi.mocked(createTicket).mockReset()
  })

  it('rejects an empty submit with all five client-side errors and sends no request', async () => {
    const { wrapper } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    expect(createTicket).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="new-ticket-error-requester.name"]').text()).toBe('Name is required.')
    expect(wrapper.text()).toContain('Valid email is required.')
    expect(wrapper.text()).toContain('Subject is required.')
    expect(wrapper.text()).toContain('Description is required.')
    expect(wrapper.text()).toContain('Category is required.')
  })

  it('submits the expected payload and navigates to the new ticket', async () => {
    vi.mocked(createTicket).mockResolvedValue({ id: 42 } as never)
    const { wrapper, router } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-requester-name"]').setValue('Jane Doe')
    await wrapper.get('[data-testid="new-ticket-requester-email"]').setValue('jane@example.test')
    await wrapper.get('[data-testid="new-ticket-subject"]').setValue('Printer jam')
    await wrapper.get('[data-testid="new-ticket-description"]').setValue('It is stuck badly.')
    await wrapper.get('[data-testid="new-ticket-category"]').setValue('3')
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(createTicket).toHaveBeenCalledWith(
      expect.objectContaining({
        subject: 'Printer jam',
        description: 'It is stuck badly.',
        category_id: 3,
        priority_id: undefined,
        requester: expect.objectContaining({ name: 'Jane Doe', email: 'jane@example.test' }),
      }),
    )
    expect(router.currentRoute.value.fullPath).toBe('/tickets/42')
  })

  it('calls createTicket once and disables Submit on a double click', async () => {
    let resolveCreate: (value: { id: number }) => void = () => {}
    vi.mocked(createTicket).mockReturnValue(new Promise((resolve) => { resolveCreate = resolve as never }))
    const { wrapper } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-requester-name"]').setValue('Jane Doe')
    await wrapper.get('[data-testid="new-ticket-requester-email"]').setValue('jane@example.test')
    await wrapper.get('[data-testid="new-ticket-subject"]').setValue('Printer jam')
    await wrapper.get('[data-testid="new-ticket-description"]').setValue('It is stuck badly.')
    await wrapper.get('[data-testid="new-ticket-category"]').setValue('3')

    const form = wrapper.get('[data-testid="new-ticket-form"]')
    await form.trigger('submit')
    await form.trigger('submit')

    expect(createTicket).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-testid="new-ticket-submit"]').attributes('disabled')).toBeDefined()
    resolveCreate({ id: 1 })
    await flushPromises()
  })

  it('merges a 422 response into field errors and shows no generic banner', async () => {
    vi.mocked(createTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: { errors: { subject: ['That subject is already in use recently.'] } },
      } as never),
    )
    const { wrapper } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-requester-name"]').setValue('Jane Doe')
    await wrapper.get('[data-testid="new-ticket-requester-email"]').setValue('jane@example.test')
    await wrapper.get('[data-testid="new-ticket-subject"]').setValue('Printer jam')
    await wrapper.get('[data-testid="new-ticket-description"]').setValue('It is stuck badly.')
    await wrapper.get('[data-testid="new-ticket-category"]').setValue('3')
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('That subject is already in use recently.')
    expect(wrapper.find('[data-testid="new-ticket-error"]').exists()).toBe(false)
  })

  it('shows a generic banner for a non-422 failure', async () => {
    vi.mocked(createTicket).mockRejectedValue(new Error('offline'))
    const { wrapper } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-requester-name"]').setValue('Jane Doe')
    await wrapper.get('[data-testid="new-ticket-requester-email"]').setValue('jane@example.test')
    await wrapper.get('[data-testid="new-ticket-subject"]').setValue('Printer jam')
    await wrapper.get('[data-testid="new-ticket-description"]').setValue('It is stuck badly.')
    await wrapper.get('[data-testid="new-ticket-category"]').setValue('3')
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="new-ticket-error"]').text()).toBe('The API is unreachable.')
  })
})
```

### 4 — Add the `test:unit` script and point CI at it (AC5)

**File: `frontend/package.json`** — in `scripts` (**6–17**), add after `"test": "vitest run",`:

```json
"test:unit": "vitest run",
```

**File: `.github/workflows/ci.yml`** — line **88**, change:

```diff
-        run: npm test
+        run: npm run test:unit
```

Only the frontend job's Vitest step changes; the backend job is untouched.

### No backend changes required.

`backend/` is untouched by this story.

---

## Edge Cases & Failure Modes

- **`ensureLoaded()`'s background fetch racing a test.** `router/guards.ts:37` fires `void useMasterDataStore().ensureLoaded()` on every authenticated navigation. If a test does not pre-seed `categories`/`priorities`/`statuses` **before** the router pushes, this call proceeds to hit the real (unmocked) API modules — harmless in jsdom (it fails and sets `masterData.error`, per `stores/masterData.ts:25–27`) but wastes a request per test and can leave `masterData.error` set in ways an unrelated assertion might trip over. Always seed before `router.push`/`mount`.
- **Two `data-testid="new-ticket-error"` elements exist** (`NewTicketView.vue:129` and **:137**) — one for `masterData.error`, one for the submit-failure `message`. In every scenario above only one is ever truthy at once, so `wrapper.find(...)` (singular) is safe; do not add a test where both could be true simultaneously without switching to `findAll`.
- **`new-ticket-category`'s `setValue('3')` needs a matching `<option :value="category.id">`.** The category fixture's `id` must equal the value set, and `v-model.number` coerces the string back to a number — assert `category_id: 3` (number), not `'3'` (string), in the `createTicket` payload expectation.
- **The debounce timer leaks across tests without `vi.useRealTimers()`.** Any test using `vi.useFakeTimers()` must restore real timers before the next test, or `TicketListView.spec.ts`'s own timing-free tests (if run in the same file/process) could hang on unresolved `setTimeout`-driven code elsewhere in the suite.
- **`filter-status`/`filter-priority`/`filter-category` are native multi-selects.** `@vue/test-utils`'s `setValue()` on a `<select multiple>` needs the array form (`setValue(['1'])`) or manual `option.selected = true` + a `change` event — a plain `setValue('1')` silently does nothing on a multi-select and the test would pass for the wrong reason (nothing selected, nothing asserted). Confirm the selection actually landed in `tickets.statusIds` before asserting on `listTickets`'s call args.
- **`assertNotFound`-style assignee test depends on `auth.isAdmin`, which depends on `user.role`.** Setting `role: 'admin'` before mount, not after — `onMounted`'s `if (auth.isAdmin)` check (`TicketFilterBar.vue:49`) runs once, on mount.

---

## Test Plan

**Files created:** `frontend/src/components/TicketFilterBar.spec.ts`; `frontend/src/views/NewTicketView.spec.ts`.

**Files edited:** `frontend/src/views/TicketListView.spec.ts` (new `describe` blocks, no existing test changed); `frontend/package.json` (one script line); `.github/workflows/ci.yml` (one line).

**Order:**
1. Task 1 first — it extends a file already green, lowest risk.
2. Task 2 and task 3 can be written in parallel; neither depends on the other.
3. Task 4 last, so the new scripts/CI line only need to prove out once every new spec file exists.

---

## Verification Steps

1. **Baseline:** `npm test` from `frontend/` → all existing specs pass, unchanged.
2. **Task 1:** `npx vitest run src/views/TicketListView.spec.ts` → all tests pass, including the 4 new ones.
3. **Task 2:** `npx vitest run src/components/TicketFilterBar.spec.ts` → all tests pass. Confirm the multi-select assertion actually exercises the DOM (temporarily swap `setValue(['1'])` for `setValue('1')` on one status test and confirm it now fails — restore).
4. **Task 3:** `npx vitest run src/views/NewTicketView.spec.ts` → all tests pass. Confirm the double-submit test is real: temporarily remove the `submitting.value ||` guard from `NewTicketView.vue:44`, re-run, confirm `createTicket` is now called twice — restore.
5. **Task 4:** `npm run test:unit` from `frontend/` → runs the full suite, exit 0 (same output as `npm test`).
6. **Full suite:** `npm test` → all specs across the project pass, quote the total test count in the PR.
7. **Typecheck:** `npx vue-tsc -b` → no errors (the three new files use existing exported types only).
8. **Lint/format:** `npm run lint` and `npm run format:check` → clean.
9. **CI:** push the branch; confirm the **Frontend - ESLint + Prettier + Vitest** job's Vitest step shows `npm run test:unit` in its log and passes.
10. **Regression:** `git status` shows no change under `backend/`, and the only non-test files touched are `frontend/package.json` and `.github/workflows/ci.yml`.

---

## Done Criteria

- [ ] **AC1**: `TicketListView.spec.ts` covers general row rendering (subject, requester, category, priority, status, assignee), the loading state, and the empty state — both the default message and the two `/my-tickets` scope variants.
- [ ] **AC2**: `TicketFilterBar.spec.ts` asserts that each filter control updates the correct `tickets` store field and triggers a reload, that the search box debounces by 300ms, and that Clear resets every field and re-enables/disables correctly.
- [ ] **AC3**: `NewTicketView.spec.ts` covers all five client-side validation rules with no request sent, a successful submit with the exact payload and navigation, the double-submit guard (proven by temporarily removing it), and both a 422 field-error response and a generic-failure banner.
- [ ] **AC4**: `stores/auth.spec.ts` is audited, not rebuilt — the PR names its 6 existing tests as the evidence for login, logout, and rehydration.
- [ ] **AC5**: `package.json` has a real `test:unit` script; `ci.yml`'s frontend Vitest step calls it; CI is green.
- [ ] No `TicketTable.vue`, `TicketFilters.vue`, or `TicketForm.vue` is created — the intake's names are mapped onto `TicketListView.vue`, `TicketFilterBar.vue`, and `NewTicketView.vue` respectively, and the PR says so.
- [ ] `npx vue-tsc -b`, `npm run lint`, and `npm run format:check` all pass.
- [ ] The only non-test files changed are `frontend/package.json` and `.github/workflows/ci.yml`; nothing under `backend/` changes.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 53 (TM-62, API documentation).**
