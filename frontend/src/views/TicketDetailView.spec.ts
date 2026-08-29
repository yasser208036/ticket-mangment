import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { assignTicket, changeTicketStatus, getTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import { getTicketStats } from '../api/stats'
import { listTicketActivities } from '../api/activities'
import { listUsers } from '../api/users'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import TicketDetailView from './TicketDetailView.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicket: vi.fn(),
  changeTicketStatus: vi.fn(),
  assignTicket: vi.fn(),
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
  can: {
    update: true,
    assign: true,
    claim: true,
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

describe('TicketDetailView escalation', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
    vi.mocked(changeTicketStatus).mockReset()
  })

  it('is absent on first render, opens on click, and closes on close', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(
      wrapper.find('[data-testid="ticket-escalate-dialog"]').exists(),
    ).toBe(false)

    await wrapper.get('[data-testid="action-escalate"]').trigger('click')
    expect(
      wrapper.find('[data-testid="ticket-escalate-dialog"]').exists(),
    ).toBe(true)

    await wrapper.get('[data-testid="ticket-escalate-cancel"]').trigger('click')
    expect(
      wrapper.find('[data-testid="ticket-escalate-dialog"]').exists(),
    ).toBe(false)
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

describe('TicketDetailView status change', () => {
  beforeEach(() => {
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(listTicketActivities).mockReset()
    vi.mocked(changeTicketStatus).mockReset()
  })

  it('the status dialog is absent on first render, opens on click, and closes on close', async () => {
    const wrapper = await mountDetail(baseTicket)
    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      false,
    )

    await wrapper.get('[data-testid="action-status"]').trigger('click')
    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      true,
    )

    await wrapper.get('[data-testid="ticket-status-cancel"]').trigger('click')
    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      false,
    )
  })

  /**
   * The dialog is mounted under `v-if="assignOpen && store.current"`, so a
   * refresh that nulls `current` first unmounts it mid-submit and Vue drops
   * its `assigned` emit -- leaving it open and blank. `changeStatus` already
   * documents this hazard; `assign` has to avoid it the same way.
   */
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

    await wrapper.get('[data-testid="action-assign"]').trigger('click')
    await flushPromises()
    const select = wrapper.get('[data-testid="ticket-assign-select"]')
    ;(select.element as HTMLSelectElement).selectedIndex = 1
    await select.trigger('change')
    await wrapper.get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="ticket-assign-dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-testid="ticket-detail"]').text()).toContain(
      'Nadia',
    )
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

    await wrapper.get('[data-testid="action-status"]').trigger('click')
    await wrapper
      .get('[data-testid="ticket-status-select"]')
      .setValue(String(baseTicket.allowed_transitions[0].id))
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-testid="ticket-detail"]').text()).toContain(
      baseTicket.allowed_transitions[0].name,
    )
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
