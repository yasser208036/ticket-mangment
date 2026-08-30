import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  assignTicket,
  changeTicketStatus,
  deleteTicket,
  escalateTicket,
  getTicket,
  updateTicket,
} from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import { requestAssignment } from '../api/assignmentRequests'
import { getTicketStats } from '../api/stats'
import { listTicketActivities } from '../api/activities'
import { listUsers } from '../api/users'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import TicketDetailView from './TicketDetailView.vue'

vi.mock('../api/assignmentRequests', async (loadOriginal) => ({
  ...(await loadOriginal()),
  requestAssignment: vi.fn(),
}))
vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicket: vi.fn(),
  changeTicketStatus: vi.fn(),
  assignTicket: vi.fn(),
  escalateTicket: vi.fn(),
  updateTicket: vi.fn(),
  deleteTicket: vi.fn(),
}))
vi.mock('../api/stats', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicketStats: vi.fn(),
}))
vi.mock('../api/activities', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listTicketActivities: vi.fn(),
}))
vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listUsers: vi.fn(),
}))

const baseTicket: TicketDetail = {
  id: 1,
  reference: 'TKT-2026-000001',
  subject: 'Printer jam',
  description: 'It is stuck.',
  requester: {
    id: 1,
    name: 'Req',
    email: 'req@example.test',
    phone: null,
    company: null,
  },
  category: {
    id: 1,
    name: 'Hardware',
    slug: 'hardware',
    color: '#111',
    is_active: true,
    sort_order: 1,
  } as never,
  priority: {
    id: 2,
    name: 'Medium',
    slug: 'medium',
    level: 2,
    color: '#F59E0B',
    is_default: true,
  },
  status: {
    id: 1,
    name: 'New',
    slug: 'new',
    bucket: 'open',
    color: '#3B82F6',
    is_default: true,
    is_terminal: false,
    sort_order: 10,
  },
  assignee: null,
  creator: null,
  escalation_level: 0,
  escalated_at: null,
  first_responded_at: null,
  resolved_at: null,
  closed_at: null,
  created_at: '2026-08-25T00:00:00Z',
  updated_at: '2026-08-25T00:00:00Z',
  escalated_by: null,
  escalation_reason: null,
  allowed_transitions: [
    {
      id: 2,
      name: 'Open',
      slug: 'open',
      bucket: 'open',
      color: '#6366F1',
      is_default: false,
      is_terminal: false,
      sort_order: 20,
    },
    {
      id: 4,
      name: 'Pending',
      slug: 'pending',
      bucket: 'pending',
      color: '#F59E0B',
      is_default: false,
      is_terminal: false,
      sort_order: 40,
    },
  ],
  resolution: null,
  reopen_count: 0,
  my_pending_assignment_request: false,
  can: {
    update: true,
    assign: true,
    request_assignment: true,
    change_status: true,
    escalate: true,
    delete: true,
    add_note: true,
  },
}

async function mountDetail(ticket: TicketDetail) {
  vi.mocked(getTicket).mockResolvedValue(ticket)
  vi.mocked(listTicketActivities).mockResolvedValue({
    data: [],
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: null,
      last_page: 1,
      path: '',
      per_page: 20,
      to: null,
      total: 0,
    },
  })
  vi.mocked(getTicketStats).mockResolvedValue({
    scope: 'all',
    total: 0,
    unassigned: 0,
    escalated: 0,
    mine_open: 0,
    by_status: [],
    by_priority: [],
  })
  const pinia = createPinia()
  setActivePinia(pinia)
  // The router's auth guard redirects an unauthenticated visitor to /login,
  // which would strip the :id param this view reads.
  useAuthStore().user = {
    id: 1,
    name: 'Agent',
    email: 'agent@example.test',
    role: 'agent',
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  const router = createAppRouter(createMemoryHistory())
  await router.push('/tickets/1')
  await router.isReady()
  const wrapper = mount(TicketDetailView, {
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return wrapper
}

describe('TicketDetailView with every permission false', () => {
  it('renders no action buttons and no note composer', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      can: {
        update: false,
        assign: false,
        request_assignment: false,
        change_status: false,
        escalate: false,
        delete: false,
        add_note: false,
      },
    })

    for (const action of [
      'action-edit',
      'action-assign',
      'action-status',
      'action-escalate',
      'action-delete',
    ]) {
      expect(wrapper.find(`[data-testid="${action}"]`).exists()).toBe(false)
    }
    expect(wrapper.find('[data-testid="note-composer"]').exists()).toBe(false)
  })
})

describe('TicketDetailView escalation', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
    vi.mocked(changeTicketStatus).mockReset()
  })

  it('is absent on first render, opens on click, and closes on close', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      false,
    )

    await wrapper.get('[data-testid="action-escalate"]').trigger('click')
    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      true,
    )

    await body().get('[data-testid="ticket-escalate-cancel"]').trigger('click')
    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      false,
    )
  })

  it('renders the escalation banner with the level, and nothing at level 0', async () => {
    const escalated = await mountDetail({
      ...baseTicket,
      escalation_level: 2,
      escalation_reason: 'Needs a specialist.',
    })
    expect(
      escalated.get('[data-testid="ticket-escalation-level"]').text(),
    ).toBe('Escalated Ticket (Level 2)')

    const fresh = await mountDetail(baseTicket)
    expect(fresh.find('[data-testid="ticket-escalation"]').exists()).toBe(false)
  })

  it('renders an escalation reason containing markup as literal text', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      escalation_level: 1,
      escalation_reason: '<script>alert(1)</script>',
    })
    expect(wrapper.text()).toContain('<script>alert(1)</script>')
    expect(wrapper.find('script').exists()).toBe(false)
  })
})

describe('TicketDetailView assignment requests', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
    vi.mocked(requestAssignment).mockReset()
  })

  it('renders the pending banner when my_pending_assignment_request is true', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      my_pending_assignment_request: true,
    })
    expect(
      wrapper
        .find('[data-testid="ticket-pending-assignment-request"]')
        .exists(),
    ).toBe(true)
  })

  it('renders no pending banner when my_pending_assignment_request is false', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(
      wrapper
        .find('[data-testid="ticket-pending-assignment-request"]')
        .exists(),
    ).toBe(false)
  })

  it('the request-assignment dialog is absent on first render and opens on click', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(
      body().find('[data-testid="ticket-request-assignment-dialog"]').exists(),
    ).toBe(false)

    await wrapper
      .get('[data-testid="action-request-assignment"]')
      .trigger('click')
    expect(
      body().find('[data-testid="ticket-request-assignment-dialog"]').exists(),
    ).toBe(true)
  })
})

describe('TicketDetailView status change', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
    vi.mocked(changeTicketStatus).mockReset()
  })

  it('the status dialog is absent on first render, opens on click, and closes on close', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(body().find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      false,
    )

    await wrapper.get('[data-testid="action-status"]').trigger('click')
    expect(body().find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      true,
    )

    await body().get('[data-testid="ticket-status-cancel"]').trigger('click')
    expect(body().find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      false,
    )
  })

  /**
   * The dialog is mounted under `v-if="assignOpen && store.current"`, so a
   * refresh that nulls `current` first unmounts it mid-submit and Vue drops
   * its `assigned` emit -- leaving it open and blank. `changeStatus` already
   * documents this hazard; `assign` has to avoid it the same way.
   */
  /** Same unmount-mid-submit hazard as the assign dialog; see that test. */
  it('after a confirmed escalation, the dialog closes and the level is shown', async () => {
    const wrapper = await mountDetail(baseTicket)
    const escalated = { ...baseTicket, escalation_level: 1 }
    vi.mocked(escalateTicket).mockResolvedValue(escalated)
    vi.mocked(getTicket).mockResolvedValue(escalated)
    vi.mocked(listTicketActivities).mockClear()

    await wrapper.get('[data-testid="action-escalate"]').trigger('click')
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()

    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      false,
    )
    // The escalate writes escalated + assigned activity rows; same refetch
    // requirement as assign and status change.
    expect(listTicketActivities).toHaveBeenCalledWith(baseTicket.id)
  })

  /**
   * An agent escalating a ticket they hold reassigns it to an admin, so the
   * refetch right after a successful escalate 403s for the escalating agent.
   * That must read as success (the escalate already happened), not as a
   * failure banner in a dialog that already closed.
   */
  it('a 403 on the post-escalate refetch is reported as success, not an error', async () => {
    const wrapper = await mountDetail(baseTicket)
    vi.mocked(escalateTicket).mockResolvedValue({
      ...baseTicket,
      escalation_level: 1,
    })
    vi.mocked(getTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 403,
      } as never),
    )

    await wrapper.get('[data-testid="action-escalate"]').trigger('click')
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()

    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-testid="ticket-error"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-testid="ticket-escalated-away"]').text(),
    ).toContain('Ticket escalated')
  })

  it('after a confirmed assign, the dialog closes and the assignee is shown', async () => {
    const agent = { id: 7, name: 'Nadia' }
    vi.mocked(listUsers).mockResolvedValue({
      data: [
        {
          ...agent,
          email: 'nadia@example.test',
          role: 'agent',
          is_active: true,
          created_at: '2026-08-25T00:00:00Z',
        },
      ],
      meta: { current_page: 1, last_page: 1, per_page: 100, total: 1 },
    } as never)
    const wrapper = await mountDetail(baseTicket)
    vi.mocked(assignTicket).mockResolvedValue({
      ...baseTicket,
      assignee: agent,
    })
    vi.mocked(getTicket).mockResolvedValue({ ...baseTicket, assignee: agent })
    vi.mocked(listTicketActivities).mockClear()

    await wrapper.get('[data-testid="action-assign"]').trigger('click')
    await flushPromises()
    const select = body().get('[data-testid="ticket-assign-select"]')
    ;(select.element as HTMLSelectElement).selectedIndex = 1
    await select.trigger('change')
    await body().get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(body().find('[data-testid="ticket-assign-dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-testid="ticket-detail"]').text()).toContain(
      'Nadia',
    )
    // The assign writes a new activity row; the timeline must refetch or it
    // shows a stale history until the next full page load.
    expect(listTicketActivities).toHaveBeenCalledWith(baseTicket.id)
  })

  it('after a confirmed change, the dialog closes and the status badge shows the new status', async () => {
    const wrapper = await mountDetail(baseTicket)
    vi.mocked(changeTicketStatus).mockResolvedValue({
      ...baseTicket,
      status: baseTicket.allowed_transitions[0],
    })
    vi.mocked(getTicket).mockResolvedValue({
      ...baseTicket,
      status: baseTicket.allowed_transitions[0],
    })
    vi.mocked(listTicketActivities).mockClear()

    await wrapper.get('[data-testid="action-status"]').trigger('click')
    await body()
      .get('[data-testid="ticket-status-select"]')
      .setValue(String(baseTicket.allowed_transitions[0].id))
    await body().get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(body().find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-testid="ticket-detail"]').text()).toContain(
      baseTicket.allowed_transitions[0].name,
    )
    // The status change writes a new activity row; same refetch requirement
    // as assign.
    expect(listTicketActivities).toHaveBeenCalledWith(baseTicket.id)
  })
})

describe('TicketDetailView resolution', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
  })

  it('renders the resolution block, its note and the author name when resolved', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      resolution: {
        note: 'Replaced the failed PSU.',
        at: '2026-08-27T00:00:00Z',
        by: { id: 2, name: 'Sam' },
      },
    })
    expect(wrapper.get('[data-testid="ticket-resolution-note"]').text()).toBe(
      'Replaced the failed PSU.',
    )
    expect(wrapper.get('[data-testid="ticket-resolution-by"]').text()).toBe(
      'Sam',
    )
  })

  it('renders no resolution block when resolution is null', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(wrapper.find('[data-testid="ticket-resolution"]').exists()).toBe(
      false,
    )
  })

  it('renders "System" when the resolution author is null', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      resolution: { note: 'Cleared automatically.', at: null, by: null },
    })
    expect(wrapper.get('[data-testid="ticket-resolution-by"]').text()).toBe(
      'System',
    )
  })

  it('renders a note containing markup as literal text', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      resolution: {
        note: '<script>alert(1)</script>',
        at: null,
        by: null,
      },
    })
    expect(wrapper.text()).toContain('<script>alert(1)</script>')
    expect(wrapper.find('script').exists()).toBe(false)
  })

  it('shows the resolved, closed and first-responded lines once their timestamps are set', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      first_responded_at: '2026-08-25T01:00:00Z',
      resolved_at: '2026-08-26T00:00:00Z',
      closed_at: '2026-08-27T00:00:00Z',
    })
    expect(
      wrapper.find('[data-testid="ticket-first-responded"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-testid="ticket-resolved"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="ticket-closed"]').exists()).toBe(true)
  })
})

describe('TicketDetailView reopen badge and count', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
  })

  it('renders the Reopened badge when the current status is Reopened', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      status: { ...baseTicket.status, slug: 'reopened', name: 'Reopened' },
    })
    expect(wrapper.find('[data-testid="ticket-reopened-badge"]').exists()).toBe(
      true,
    )
  })

  it('renders no badge when the current status is not Reopened', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(wrapper.find('[data-testid="ticket-reopened-badge"]').exists()).toBe(
      false,
    )
  })

  it('renders the count and no badge once the ticket has moved on from Reopened', async () => {
    const wrapper = await mountDetail({
      ...baseTicket,
      status: {
        ...baseTicket.status,
        slug: 'in-progress',
        name: 'In Progress',
      },
      reopen_count: 2,
    })
    expect(wrapper.get('[data-testid="ticket-reopen-count"]').text()).toBe(
      'Reopened 2 times',
    )
    expect(wrapper.find('[data-testid="ticket-reopened-badge"]').exists()).toBe(
      false,
    )
  })

  it('reads "Reopened 1 time" in the singular', async () => {
    const wrapper = await mountDetail({ ...baseTicket, reopen_count: 1 })
    expect(wrapper.get('[data-testid="ticket-reopen-count"]').text()).toBe(
      'Reopened 1 time',
    )
  })

  it('renders no reopen-count element when the count is zero', async () => {
    const wrapper = await mountDetail({ ...baseTicket, reopen_count: 0 })
    expect(wrapper.find('[data-testid="ticket-reopen-count"]').exists()).toBe(
      false,
    )
  })
})

// TicketEditDialog and TicketDeleteDialog render via <Teleport to="body">, so
// their content is a sibling of the app root in the real DOM, not a
// descendant of `wrapper` -- it must be queried from document.body directly.
function body() {
  return new DOMWrapper(document.body)
}

describe('TicketDetailView edit and delete dialogs', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
    vi.mocked(updateTicket).mockReset()
    vi.mocked(deleteTicket).mockReset()
    useMasterDataStore().categories = [
      {
        id: 1,
        name: 'Hardware',
        slug: 'hardware',
        color: '#111',
        is_active: true,
        sort_order: 1,
      } as never,
    ]
    useMasterDataStore().priorities = [
      {
        id: 2,
        name: 'Medium',
        slug: 'medium',
        level: 2,
        color: '#F59E0B',
        is_default: true,
      },
    ]
  })

  it('the edit dialog is absent on first render, opens on click, and closes on close', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(body().find('[data-testid="ticket-edit-dialog"]').exists()).toBe(
      false,
    )

    await wrapper.get('[data-testid="action-edit"]').trigger('click')
    expect(body().find('[data-testid="ticket-edit-dialog"]').exists()).toBe(
      true,
    )

    await body().get('[data-testid="ticket-edit-cancel"]').trigger('click')
    expect(body().find('[data-testid="ticket-edit-dialog"]').exists()).toBe(
      false,
    )
  })

  it('after a confirmed edit, the dialog closes and the updated subject is shown', async () => {
    const wrapper = await mountDetail(baseTicket)
    vi.mocked(updateTicket).mockResolvedValue({
      ...baseTicket,
      subject: 'Corrected subject',
    })
    vi.mocked(getTicket).mockResolvedValue({
      ...baseTicket,
      subject: 'Corrected subject',
    })

    vi.mocked(listTicketActivities).mockClear()
    await wrapper.get('[data-testid="action-edit"]').trigger('click')
    await body()
      .get('[data-testid="ticket-edit-subject"]')
      .setValue('Corrected subject')
    await body().get('[data-testid="ticket-edit-submit"]').trigger('click')
    await flushPromises()

    expect(body().find('[data-testid="ticket-edit-dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-testid="ticket-detail"]').text()).toContain(
      'Corrected subject',
    )
    // A saved edit writes an "updated" activity row -- the Timeline must
    // refresh, the same way it does after assign/escalate/status-change.
    expect(listTicketActivities).toHaveBeenCalledWith(baseTicket.id)
  })

  it('the delete dialog is absent on first render, opens on click, and closes on cancel', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(body().find('[data-testid="ticket-delete-dialog"]').exists()).toBe(
      false,
    )

    await wrapper.get('[data-testid="action-delete"]').trigger('click')
    expect(body().find('[data-testid="ticket-delete-dialog"]').exists()).toBe(
      true,
    )

    await body().get('[data-testid="ticket-delete-cancel"]').trigger('click')
    expect(body().find('[data-testid="ticket-delete-dialog"]').exists()).toBe(
      false,
    )
    expect(deleteTicket).not.toHaveBeenCalled()
  })

  it('a confirmed delete navigates to the ticket list', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = {
      id: 1,
      name: 'Admin',
      email: 'admin@example.test',
      role: 'admin',
      is_active: true,
      created_at: '2026-08-25T00:00:00Z',
    }
    vi.mocked(getTicket).mockResolvedValue(baseTicket)
    vi.mocked(listTicketActivities).mockResolvedValue({
      data: [],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        from: null,
        last_page: 1,
        path: '',
        per_page: 20,
        to: null,
        total: 0,
      },
    })
    vi.mocked(getTicketStats).mockResolvedValue({
      scope: 'all',
      total: 0,
      unassigned: 0,
      escalated: 0,
      mine_open: 0,
      by_status: [],
      by_priority: [],
    })
    vi.mocked(deleteTicket).mockResolvedValue(undefined)
    const router = createAppRouter(createMemoryHistory())
    await router.push('/tickets/1')
    await router.isReady()
    const wrapper = mount(TicketDetailView, {
      global: { plugins: [pinia, router] },
    })
    await flushPromises()

    await wrapper.get('[data-testid="action-delete"]').trigger('click')
    await body().get('[data-testid="ticket-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.name).toBe('tickets')
  })
})
