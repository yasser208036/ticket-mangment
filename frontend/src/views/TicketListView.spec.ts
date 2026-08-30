import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { listTickets } from '../api/tickets'
import type { Paginated } from '../api/pagination'
import type { TicketListItem } from '../api/tickets'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import TicketListView from './TicketListView.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listTickets: vi.fn(),
}))

function ticketRow(overrides: Partial<TicketListItem> = {}): TicketListItem {
  return {
    id: 1,
    reference: 'TKT-2026-000001',
    subject: 'Printer jam',
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
    ...overrides,
  }
}

function paginated(rows: TicketListItem[]): Paginated<TicketListItem> {
  return {
    data: rows,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: rows.length ? 1 : null,
      last_page: 1,
      path: '/tickets',
      per_page: 15,
      to: rows.length,
      total: rows.length,
    },
  }
}

async function mountList(
  path = '/tickets',
  role: 'admin' | 'agent' | 'user' = 'agent',
) {
  const pinia = createPinia()
  setActivePinia(pinia)
  // The router's auth guard redirects an unauthenticated visitor to /login,
  // which would otherwise strip the query string this suite asserts on.
  useAuthStore().user = {
    id: 1,
    name: 'Agent',
    email: 'agent@example.test',
    role,
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  const router = createAppRouter(createMemoryHistory())
  await router.push(path)
  await router.isReady()
  const wrapper = mount(TicketListView, {
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return { wrapper, router }
}

describe('TicketListView New ticket button', () => {
  beforeEach(() => {
    vi.mocked(listTickets).mockReset()
    vi.mocked(listTickets).mockResolvedValue(paginated([]))
  })

  it('shows New ticket only for an end user, not an agent or admin', async () => {
    const { wrapper: userWrapper } = await mountList('/tickets', 'user')
    expect(userWrapper.text()).toContain('New ticket')

    const { wrapper: agentWrapper } = await mountList('/tickets', 'agent')
    expect(agentWrapper.text()).not.toContain('New ticket')

    const { wrapper: adminWrapper } = await mountList('/tickets', 'admin')
    expect(adminWrapper.text()).not.toContain('New ticket')
  })
})

describe('TicketListView escalation column', () => {
  beforeEach(() => {
    vi.mocked(listTickets).mockReset()
  })

  it('renders the badge for an escalated row and an empty cell at level 0', async () => {
    vi.mocked(listTickets).mockResolvedValue(
      paginated([
        ticketRow({ id: 1, escalation_level: 2 }),
        ticketRow({ id: 2, escalation_level: 0 }),
      ]),
    )
    const { wrapper } = await mountList()
    const cells = wrapper.findAll('[data-testid="tickets-escalation"]')
    expect(cells).toHaveLength(2)
    expect(cells[0].find('[data-testid="escalation-badge"]').exists()).toBe(
      true,
    )
    expect(cells[1].find('[data-testid="escalation-badge"]').exists()).toBe(
      false,
    )
  })

  it('clicking the Escalated header sorts by escalated_at and sets aria-sort', async () => {
    vi.mocked(listTickets).mockResolvedValue(paginated([ticketRow()]))
    const { wrapper } = await mountList()
    vi.mocked(listTickets).mockClear()

    const header = wrapper.get('[data-testid="sort-escalated_at"]')
    await header.trigger('click')
    await flushPromises()

    expect(listTickets).toHaveBeenCalledWith(
      expect.objectContaining({ sort: 'escalated_at', direction: 'desc' }),
    )
    expect(
      wrapper.get('[data-testid="sort-escalated_at"]').element.closest('th'),
    ).not.toBeNull()
  })

  it('hydrates the store sort from ?sort=escalated_at', async () => {
    vi.mocked(listTickets).mockResolvedValue(paginated([ticketRow()]))
    await mountList('/tickets?sort=escalated_at')
    expect(listTickets).toHaveBeenCalledWith(
      expect.objectContaining({ sort: 'escalated_at' }),
    )
  })
})

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
          assignee: { id: 5, name: 'Sam' },
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
})
