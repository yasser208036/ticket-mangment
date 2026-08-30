import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { escalateTicket, getTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import TicketEscalateDialog from './TicketEscalateDialog.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  escalateTicket: vi.fn(),
  getTicket: vi.fn(),
}))

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
// and leave a clean body for the next test.
let mounted: ReturnType<typeof mount> | undefined

function mountDialog(overrides: Partial<TicketDetail> = {}) {
  mounted = mount(TicketEscalateDialog, {
    props: { ticket: { ...ticket, ...overrides } },
    global: { plugins: [createPinia()] },
  })
  return mounted
}

// Query document.body directly rather than the wrapper, since the dialog's
// content lands as a sibling of the wrapper's own root in the real DOM.
function body() {
  return new DOMWrapper(document.body)
}

describe('TicketEscalateDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(escalateTicket).mockReset()
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicket).mockResolvedValue(ticket)
  })

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('disables confirm under 10 characters and enables it at 10', async () => {
    mountDialog()
    const confirm = () => body().get('[data-testid="ticket-escalate-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()
    expect(body().find('[data-testid="ticket-escalate-hint"]').exists()).toBe(
      true,
    )

    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('0123456789')
    expect(confirm().attributes('disabled')).toBeUndefined()
    expect(body().find('[data-testid="ticket-escalate-hint"]').exists()).toBe(
      false,
    )
  })

  it('confirming calls escalateTicket then getTicket, in order, and emits escalated', async () => {
    vi.mocked(escalateTicket).mockResolvedValue({
      ...ticket,
      escalation_level: 1,
    })
    const calls: string[] = []
    vi.mocked(escalateTicket).mockImplementation(async () => {
      calls.push('escalate')
      return { ...ticket, escalation_level: 1 }
    })
    vi.mocked(getTicket).mockImplementation(async () => {
      calls.push('get')
      return ticket
    })

    const wrapper = mountDialog()
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()

    expect(escalateTicket).toHaveBeenCalledWith(1, 'A good enough reason.')
    expect(calls).toEqual(['escalate', 'get'])
    expect(wrapper.emitted('escalated')).toHaveLength(1)
  })

  it('a 403 on the post-escalate refetch still emits escalated, with no error shown', async () => {
    vi.mocked(escalateTicket).mockResolvedValue({
      ...ticket,
      escalation_level: 1,
    })
    vi.mocked(getTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 403,
      } as never),
    )
    const wrapper = mountDialog()
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.emitted('escalated')).toHaveLength(1)
    expect(body().find('[data-testid="ticket-escalate-error"]').exists()).toBe(
      false,
    )
  })

  it('renders a 422 under status in the error element', async () => {
    vi.mocked(escalateTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: { status: ['A Resolved ticket cannot be escalated.'] },
        },
      } as never),
    )
    mountDialog()
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()
    expect(body().get('[data-testid="ticket-escalate-error"]').text()).toBe(
      'A Resolved ticket cannot be escalated.',
    )
    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      true,
    )
  })

  it('renders a 422 under assigned_to in the error element', async () => {
    vi.mocked(escalateTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: {
            assigned_to: [
              'There is no active administrator to escalate to. Activate an admin account first.',
            ],
          },
        },
      } as never),
    )
    mountDialog()
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()
    expect(body().get('[data-testid="ticket-escalate-error"]').text()).toBe(
      'There is no active administrator to escalate to. Activate an admin account first.',
    )
  })

  it('renders a 422 under reason in the error element', async () => {
    vi.mocked(escalateTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: {
            reason: ['The escalation reason must be at least 10 characters.'],
          },
        },
      } as never),
    )
    mountDialog()
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()
    expect(body().get('[data-testid="ticket-escalate-error"]').text()).toBe(
      'The escalation reason must be at least 10 characters.',
    )
  })

  it('falls back to errorMessage for a non-422 failure and stays mounted', async () => {
    vi.mocked(escalateTicket).mockRejectedValue(new Error('offline'))
    mountDialog()
    await body()
      .get('[data-testid="ticket-escalate-reason"]')
      .setValue('A good enough reason.')
    await body().get('[data-testid="ticket-escalate-confirm"]').trigger('click')
    await flushPromises()
    expect(body().get('[data-testid="ticket-escalate-error"]').text()).toBe(
      'The API is unreachable.',
    )
    expect(body().find('[data-testid="ticket-escalate-dialog"]').exists()).toBe(
      true,
    )
  })

  it('shows the current level when above zero and hides it at zero', () => {
    mountDialog({ escalation_level: 0 })
    expect(body().find('[data-testid="ticket-escalate-level"]').exists()).toBe(
      false,
    )
    mounted?.unmount()

    mountDialog({ escalation_level: 2 })
    expect(body().get('[data-testid="ticket-escalate-level"]').text()).toBe(
      'Currently level 2',
    )
  })

  it('cancel emits close and issues no request', async () => {
    const wrapper = mountDialog()
    await body().get('[data-testid="ticket-escalate-cancel"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(escalateTicket).not.toHaveBeenCalled()
  })
})
