import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import type { Pinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

/** One page of the admin user list, shaped as the API returns it. */
function agentPage(
  people: { id: number; name: string }[],
  lastPage: number,
  currentPage = 1,
) {
  return {
    data: people.map((person) => ({
      ...person,
      email: `${person.name.toLowerCase()}@example.test`,
      role: 'agent',
      is_active: true,
      created_at: '2026-08-25T00:00:00Z',
    })),
    meta: {
      current_page: currentPage,
      last_page: lastPage,
      per_page: 100,
      total: people.length * lastPage,
    },
  } as never
}

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

// The dialog is teleported into the real document.body, which -- unlike a
// wrapper's own detached root -- survives past the end of a test unless
// explicitly unmounted; each mount is tracked here so afterEach can remove it
// and leave a clean body for the next test. A test that mounts more than once
// (to compare two states) must unmount the first instance itself before
// mounting the second, or the first's teleported content leaks into it.
let mounted: ReturnType<typeof mount> | undefined

function mountDialog(
  overrides: Partial<TicketDetail> = {},
  pinia: Pinia = createPinia(),
) {
  mounted = mount(TicketAssignDialog, {
    props: { ticket: { ...ticket, ...overrides } },
    global: { plugins: [pinia] },
  })
  return mounted
}

// Query document.body directly rather than the wrapper, since the dialog's
// content lands as a sibling of the wrapper's own root in the real DOM.
function body() {
  return new DOMWrapper(document.body)
}

/**
 * Pick the first real agent. The option values are numbers bound through
 * `:value`, so they live on the element's `_value` rather than its `value`
 * attribute -- driving `selectedIndex` and firing `change` is what v-model
 * actually listens to.
 */
async function chooseAgent(): Promise<void> {
  const select = body().get('[data-testid="ticket-assign-select"]')
  ;(select.element as HTMLSelectElement).selectedIndex = 1
  await select.trigger('change')
}

/** Mount, then wait for the onMounted agent fetch to settle. */
async function mountLoaded(
  overrides: Partial<TicketDetail> = {},
  pinia?: Pinia,
) {
  const wrapper = mountDialog(overrides, pinia)
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
    vi.mocked(listUsers).mockResolvedValue(agentPage([agent], 1))
  })

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('asks only for active agents, so an admin can never be offered', async () => {
    await mountLoaded()
    expect(listUsers).toHaveBeenCalledWith({
      role: 'agent',
      status: 'active',
      page: 1,
      per_page: 100,
    })
  })

  it('caps the reason at 500 characters and passes it through as the third argument', async () => {
    const wrapper = await mountLoaded()
    const reason = body().get('[data-testid="ticket-assign-reason"]')
    expect(reason.attributes('maxlength')).toBe('500')

    await reason.setValue('Ahmed is on leave')
    await chooseAgent()
    await body().get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(assignTicket).toHaveBeenCalledWith(1, agent.id, 'Ahmed is on leave')
    expect(wrapper.emitted('assigned')).toHaveLength(1)
  })

  it('omits a blank reason rather than sending an empty string', async () => {
    await mountLoaded()
    await chooseAgent()
    await body().get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(assignTicket).toHaveBeenCalledWith(1, agent.id, undefined)
  })

  it('keeps confirm disabled until an agent is chosen', async () => {
    await mountLoaded()
    const confirm = () => body().get('[data-testid="ticket-assign-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()

    await chooseAgent()
    expect(confirm().attributes('disabled')).toBeUndefined()
  })

  /**
   * The select opens on the current assignee, so an untouched Assign would post
   * a no-op the server answers 200 to while writing nothing -- silently binning
   * the reason. Return to queue stays available; it is a real change.
   */
  it('keeps confirm disabled while the pre-selected assignee is unchanged', async () => {
    await mountLoaded({ assignee: agent })
    await body().get('[data-testid="ticket-assign-reason"]').setValue('Context')
    expect(
      body()
        .get('[data-testid="ticket-assign-confirm"]')
        .attributes('disabled'),
    ).toBeDefined()

    await body().get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()
    expect(assignTicket).not.toHaveBeenCalled()
    expect(
      body()
        .get('[data-testid="ticket-assign-unassign"]')
        .attributes('disabled'),
    ).toBeUndefined()
  })

  /**
   * 100 is the endpoint's own per_page ceiling, so a single request would drop
   * agent 101 from the picker with nothing on screen to say so.
   */
  it('pages past the per_page ceiling so no agent is missing from the picker', async () => {
    const overflow = { id: 8, name: 'Omar' }
    vi.mocked(listUsers)
      .mockResolvedValueOnce(agentPage([agent], 2, 1))
      .mockResolvedValueOnce(agentPage([overflow], 2, 2))
    await mountLoaded()

    expect(listUsers).toHaveBeenCalledTimes(2)
    expect(vi.mocked(listUsers).mock.calls[1]?.[0]).toMatchObject({ page: 2 })
    expect(
      body()
        .findAll('option')
        .map((option) => option.text()),
    ).toEqual(['Choose an agent…', 'Nadia', 'Omar'])
  })

  it('keeps the picker empty when a later page fails, not half a roster', async () => {
    vi.mocked(listUsers)
      .mockResolvedValueOnce(agentPage([agent], 2, 1))
      .mockRejectedValueOnce(new Error('offline'))
    await mountLoaded()

    expect(body().findAll('option')).toHaveLength(1)
    expect(body().get('[data-testid="ticket-assign-error"]').text()).toBe(
      'The API is unreachable.',
    )
  })

  it('drops a previously loaded agent list when a later load fails', async () => {
    // One shared store across both mounts -- the point is that `agents` outlives
    // the dialog, so a failed reopen must not keep offering the earlier fetch.
    const pinia = createPinia()
    await mountLoaded({}, pinia)
    expect(body().findAll('option')).toHaveLength(2)
    mounted?.unmount()

    vi.mocked(listUsers).mockRejectedValue(new Error('offline'))
    await mountLoaded({}, pinia)
    // Only the placeholder: a stale agent may since have been deactivated, and
    // offering them would 422 on submit.
    expect(body().findAll('option')).toHaveLength(1)
  })

  it('offers Return to queue only when the ticket is assigned', async () => {
    await mountLoaded()
    expect(body().find('[data-testid="ticket-assign-unassign"]').exists()).toBe(
      false,
    )
    mounted?.unmount()

    await mountLoaded({ assignee: agent })
    expect(body().find('[data-testid="ticket-assign-unassign"]').exists()).toBe(
      true,
    )
  })

  it('sends exactly null when returning a ticket to the queue', async () => {
    await mountLoaded({ assignee: agent })
    await body()
      .get('[data-testid="ticket-assign-reason"]')
      .setValue('Handover')
    await body().get('[data-testid="ticket-assign-unassign"]').trigger('click')
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
    await body().get('[data-testid="ticket-assign-unassign"]').trigger('click')
    await flushPromises()

    expect(body().get('[data-testid="ticket-assign-error"]').text()).toBe(
      'Keep the reason to 500 characters or fewer.',
    )
    expect(body().find('[data-testid="ticket-assign-dialog"]').exists()).toBe(
      true,
    )
    expect(wrapper.emitted('assigned')).toBeUndefined()
  })

  it('says so when the agent list cannot be loaded, rather than looking empty', async () => {
    vi.mocked(listUsers).mockRejectedValue(new Error('offline'))
    await mountLoaded()

    expect(body().get('[data-testid="ticket-assign-error"]').text()).toBe(
      'The API is unreachable.',
    )
    expect(body().findAll('option')).toHaveLength(1)
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
    await mountLoaded()
    await chooseAgent()
    await body().get('[data-testid="ticket-assign-confirm"]').trigger('click')
    await flushPromises()

    expect(body().get('[data-testid="ticket-assign-error"]').text()).toBe(
      'That user is not an active agent.',
    )
  })

  it('preselects the current assignee and names them in the header', async () => {
    await mountLoaded({ assignee: agent })
    expect(body().get('[data-testid="ticket-assign-current"]').text()).toBe(
      'Currently Nadia',
    )
    const options = body().findAll('option')
    expect((options[1]?.element as HTMLOptionElement).selected).toBe(true)
  })
})
