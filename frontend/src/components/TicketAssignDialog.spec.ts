import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { assignTicket, getTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import { listUsers } from '../api/users'
import { getTicketStats } from '../api/stats'
import TicketAssignDialog from './TicketAssignDialog.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  assignTicket: vi.fn(),
  getTicket: vi.fn(),
}))
vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listUsers: vi.fn(),
}))
vi.mock('../api/stats', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicketStats: vi.fn(),
}))

const agent = { id: 7, name: 'Nadia' }

const ticket: TicketDetail = {
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
  allowed_transitions: [],
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

function mountDialog(overrides: Partial<TicketDetail> = {}) {
  return mount(TicketAssignDialog, {
    props: { ticket: { ...ticket, ...overrides } },
    global: { plugins: [createPinia()] },
  })
}

/**
 * Pick the first real agent. The option values are numbers bound through
 * `:value`, so they live on the element's `_value` rather than its `value`
 * attribute — driving `selectedIndex` and firing `change` is what v-model
 * actually listens to.
 */
async function chooseAgent(
  wrapper: ReturnType<typeof mountDialog>,
): Promise<void> {
  const select = wrapper.get('[data-testid="ticket-assign-select"]')
  ;(select.element as HTMLSelectElement).selectedIndex = 1
  await select.trigger('change')
}

/** Mount, then wait for the onMounted agent fetch to settle. */
async function mountLoaded(overrides: Partial<TicketDetail> = {}) {
  const wrapper = mountDialog(overrides)
  await flushPromises()
  return wrapper
}

describe('TicketAssignDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(assignTicket).mockReset()
    vi.mocked(getTicket).mockReset()
    vi.mocked(listUsers).mockReset()
    vi.mocked(getTicketStats).mockReset()
    vi.mocked(getTicket).mockResolvedValue(ticket)
    vi.mocked(assignTicket).mockResolvedValue(ticket)
    vi.mocked(getTicketStats).mockRejectedValue(new Error('not under test'))
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
  })

  it('asks only for active agents, so an admin can never be offered', async () => {
    await mountLoaded()
    expect(listUsers).toHaveBeenCalledWith({
      role: 'agent',
      status: 'active',
      per_page: 100,
    })
  })

  it('caps the reason at 500 characters and passes it through as the third argument', async () => {
    const wrapper = await mountLoaded()
    const reason = wrapper.get('[data-testid="ticket-assign-reason"]')
    expect(reason.attributes('maxlength')).toBe('500')

    await reason.setValue('Ahmed is on leave')
    await chooseAgent(wrapper)
    await wrapper.get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(assignTicket).toHaveBeenCalledWith(1, agent.id, 'Ahmed is on leave')
    expect(wrapper.emitted('assigned')).toHaveLength(1)
  })

  it('omits a blank reason rather than sending an empty string', async () => {
    const wrapper = await mountLoaded()
    await chooseAgent(wrapper)
    await wrapper.get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(assignTicket).toHaveBeenCalledWith(1, agent.id, undefined)
  })

  it('keeps confirm disabled until an agent is chosen', async () => {
    const wrapper = await mountLoaded()
    const confirm = () => wrapper.get('[data-testid="ticket-assign-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()

    await chooseAgent(wrapper)
    expect(confirm().attributes('disabled')).toBeUndefined()
  })

  it('offers Return to queue only when the ticket is assigned', async () => {
    const unassigned = await mountLoaded()
    expect(
      unassigned.find('[data-testid="ticket-assign-unassign"]').exists(),
    ).toBe(false)

    const held = await mountLoaded({ assignee: agent })
    expect(held.find('[data-testid="ticket-assign-unassign"]').exists()).toBe(
      true,
    )
  })

  it('sends exactly null when returning a ticket to the queue', async () => {
    const wrapper = await mountLoaded({ assignee: agent })
    await wrapper
      .get('[data-testid="ticket-assign-reason"]')
      .setValue('Handover')
    await wrapper.get('[data-testid="ticket-assign-unassign"]').trigger('click')
    await flushPromises()

    // The second argument must be null, never '' or undefined: the server's
    // ConvertEmptyStringsToNull makes '' indistinguishable from a deliberate
    // unassign, and an absent field is a 422.
    const [, assignedTo] = vi.mocked(assignTicket).mock.calls[0] ?? []
    expect(assignedTo).toBeNull()
    expect(assignTicket).toHaveBeenCalledWith(1, null, 'Handover')
  })

  it('renders a 422 under reason and stays mounted', async () => {
    vi.mocked(assignTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: { reason: ['Keep the reason to 500 characters or fewer.'] },
        },
      } as never),
    )
    const wrapper = await mountLoaded({ assignee: agent })
    await wrapper.get('[data-testid="ticket-assign-unassign"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="ticket-assign-error"]').text()).toBe(
      'Keep the reason to 500 characters or fewer.',
    )
    expect(wrapper.find('[data-testid="ticket-assign-dialog"]').exists()).toBe(
      true,
    )
    expect(wrapper.emitted('assigned')).toBeUndefined()
  })

  it('says so when the agent list cannot be loaded, rather than looking empty', async () => {
    vi.mocked(listUsers).mockRejectedValue(new Error('offline'))
    const wrapper = await mountLoaded()

    expect(wrapper.get('[data-testid="ticket-assign-error"]').text()).toBe(
      'The API is unreachable.',
    )
    expect(wrapper.findAll('option')).toHaveLength(1)
  })

  it('renders a 422 under assigned_to', async () => {
    vi.mocked(assignTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: { assigned_to: ['That user is not an active agent.'] },
        },
      } as never),
    )
    const wrapper = await mountLoaded()
    await chooseAgent(wrapper)
    await wrapper.get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="ticket-assign-error"]').text()).toBe(
      'That user is not an active agent.',
    )
  })

  it('preselects the current assignee and names them in the header', async () => {
    const wrapper = await mountLoaded({ assignee: agent })
    expect(wrapper.get('[data-testid="ticket-assign-current"]').text()).toBe(
      'Currently Nadia',
    )
    const options = wrapper.findAll('option')
    expect((options[1]?.element as HTMLOptionElement).selected).toBe(true)
  })
})
