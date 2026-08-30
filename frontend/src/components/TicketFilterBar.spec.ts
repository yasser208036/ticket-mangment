import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { listTickets } from '../api/tickets'
import { listUsers } from '../api/users'
import type { Paginated } from '../api/pagination'
import type { TicketListItem } from '../api/tickets'
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

function emptyPage(): Paginated<TicketListItem> {
  return {
    data: [],
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: null,
      last_page: 1,
      path: '/tickets',
      per_page: 15,
      to: 0,
      total: 0,
    },
  }
}

function seedMasterData(): void {
  useMasterDataStore().statuses = [
    {
      id: 1,
      name: 'New',
      slug: 'new',
      bucket: 'open',
      color: '#3B82F6',
      is_default: true,
      is_terminal: false,
      sort_order: 10,
    },
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
}

function mountBar(role: 'agent' | 'admin' | 'user' = 'agent') {
  const pinia = createPinia()
  setActivePinia(pinia)
  seedMasterData()
  useAuthStore().user = {
    id: 1,
    name: 'U',
    email: 'u@example.test',
    role,
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  vi.mocked(listTickets).mockResolvedValue(emptyPage())
  const wrapper = mount(TicketFilterBar, { global: { plugins: [pinia] } })
  return { wrapper, tickets: useTicketsStore() }
}

describe('TicketFilterBar', () => {
  beforeEach(() => {
    vi.mocked(listTickets).mockReset()
    vi.mocked(listUsers).mockReset()
    vi.mocked(listUsers).mockResolvedValue(emptyPage() as never)
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('clicking a status chip sets statusIds and reloads', async () => {
    const { wrapper, tickets } = mountBar()
    // The filter-status div contains chip buttons; click the first one (id=1)
    const chip = wrapper.get('[data-testid="filter-status"]').find('button')
    await chip.trigger('click')

    expect(tickets.statusIds).toEqual([1])
    expect(listTickets).toHaveBeenCalledWith(
      expect.objectContaining({ status_id: [1] }),
    )
  })

  it('selecting escalated="true" sets escalated and reloads; back to "" clears it', async () => {
    const { wrapper, tickets } = mountBar()
    const select = wrapper.get('[data-testid="filter-escalated"]')

    await select.setValue('true')
    expect(tickets.escalated).toBe(true)
    expect(listTickets).toHaveBeenLastCalledWith(
      expect.objectContaining({ escalated: true }),
    )

    await select.setValue('')
    expect(tickets.escalated).toBeNull()
    expect(listTickets).toHaveBeenLastCalledWith(
      expect.not.objectContaining({ escalated: expect.anything() }),
    )
  })

  it('changing sort and direction calls setSort with both values', async () => {
    const { wrapper, tickets } = mountBar()

    await wrapper.get('[data-testid="filter-sort"]').setValue('priority')
    expect(tickets.sort).toBe('priority')
    expect(tickets.direction).toBe('desc')

    await wrapper.get('[data-testid="filter-direction"]').setValue('asc')
    expect(tickets.sort).toBe('priority')
    expect(tickets.direction).toBe('asc')

    expect(listTickets).toHaveBeenLastCalledWith(
      expect.objectContaining({ sort: 'priority', direction: 'asc' }),
    )
  })

  it('debounces the search box by 300ms before calling applySearch', async () => {
    vi.useFakeTimers()
    const { wrapper, tickets } = mountBar()

    await wrapper.get('[data-testid="filter-search"]').setValue('  printer  ')
    await vi.advanceTimersByTimeAsync(299)
    expect(listTickets).not.toHaveBeenCalled()

    await vi.advanceTimersByTimeAsync(1)
    expect(tickets.q).toBe('printer')
    expect(listTickets).toHaveBeenCalledWith(
      expect.objectContaining({ q: 'printer' }),
    )
  })

  it('Clear disables when nothing is active and re-enables once a chip is selected', async () => {
    const { wrapper } = mountBar()

    expect(
      wrapper.get('[data-testid="filter-clear"]').attributes('disabled'),
    ).toBeDefined()

    // Click the first status chip
    await wrapper
      .get('[data-testid="filter-status"]')
      .find('button')
      .trigger('click')

    expect(
      wrapper.get('[data-testid="filter-clear"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('Clear resets every field to the empty state and reloads', async () => {
    const { wrapper, tickets } = mountBar()
    // Select a status chip and a priority chip
    await wrapper
      .get('[data-testid="filter-status"]')
      .find('button')
      .trigger('click')
    await wrapper
      .get('[data-testid="filter-priority"]')
      .find('button')
      .trigger('click')
    await wrapper.get('[data-testid="filter-sort"]').setValue('priority')
    vi.mocked(listTickets).mockClear()

    await wrapper.get('[data-testid="filter-clear"]').trigger('click')
    await flushPromises()

    expect(tickets.statusIds).toEqual([])
    expect(tickets.priorityIds).toEqual([])
    expect(tickets.q).toBe('')
    expect(tickets.sort).toBe('created_at')
    expect(tickets.direction).toBe('desc')
    expect(listTickets).toHaveBeenCalled()
  })

  it('loads the real user list only for an admin', async () => {
    mountBar('admin')
    await flushPromises()
    expect(listUsers).toHaveBeenCalled()
  })

  it('does not load users for an agent, and the assignee select offers only Anyone/Me/Unassigned', async () => {
    const { wrapper } = mountBar('agent')
    await flushPromises()
    expect(listUsers).not.toHaveBeenCalled()
    expect(
      wrapper.findAll('[data-testid="filter-assignee"] option'),
    ).toHaveLength(3)
  })

  it('renders no assignee control for an end user', async () => {
    const { wrapper } = mountBar('user')
    await flushPromises()
    expect(listUsers).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="filter-assignee"]').exists()).toBe(false)
  })
})
