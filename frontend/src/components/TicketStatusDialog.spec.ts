import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { changeTicketStatus, getTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import type { Status } from '../api/statuses'
import TicketStatusDialog from './TicketStatusDialog.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  changeTicketStatus: vi.fn(),
  getTicket: vi.fn(),
}))

const open: Status = {
  id: 2,
  name: 'Open',
  slug: 'open',
  bucket: 'open',
  color: '#6366F1',
  is_default: false,
  is_terminal: false,
  sort_order: 20,
}
const pending: Status = {
  id: 4,
  name: 'Pending',
  slug: 'pending',
  bucket: 'pending',
  color: '#F59E0B',
  is_default: false,
  is_terminal: false,
  sort_order: 40,
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
  allowed_transitions: [open, pending],
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
  return mount(TicketStatusDialog, {
    props: { ticket: { ...ticket, ...overrides } },
    global: { plugins: [createPinia()] },
  })
}

describe('TicketStatusDialog', () => {
  beforeEach(() => {
    vi.mocked(changeTicketStatus).mockReset()
    vi.mocked(getTicket).mockReset()
  })

  it('renders one option per allowed_transitions entry and no others', () => {
    const wrapper = mountDialog()
    expect(
      wrapper.findAll('[data-testid="ticket-status-select"] option'),
    ).toHaveLength(3)
  })

  it('renders ticket-status-empty and no select when allowed_transitions is empty', () => {
    const wrapper = mountDialog({ allowed_transitions: [] })
    expect(wrapper.find('[data-testid="ticket-status-empty"]').exists()).toBe(
      true,
    )
    expect(wrapper.find('[data-testid="ticket-status-select"]').exists()).toBe(
      false,
    )
  })

  it('disables confirm with nothing selected and enables it once an option is chosen', async () => {
    const wrapper = mountDialog()
    const confirm = () => wrapper.get('[data-testid="ticket-status-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('2')
    expect(confirm().attributes('disabled')).toBeUndefined()
  })

  it('confirming calls changeTicketStatus then getTicket, in order, and emits changed', async () => {
    vi.mocked(changeTicketStatus).mockResolvedValue({ ...ticket, status: open })
    vi.mocked(getTicket).mockResolvedValue({ ...ticket, status: open })
    const calls: string[] = []
    vi.mocked(changeTicketStatus).mockImplementation(async () => {
      calls.push('change')

      return { ...ticket, status: open }
    })
    vi.mocked(getTicket).mockImplementation(async () => {
      calls.push('get')

      return { ...ticket, status: open }
    })

    const wrapper = mountDialog()
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('2')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(changeTicketStatus).toHaveBeenCalledWith(1, { status_id: 2 })
    expect(calls).toEqual(['change', 'get'])
    expect(wrapper.emitted('changed')).toHaveLength(1)
  })

  it('sends resolution only when the target is Resolved, and reason only when Reopened', async () => {
    const resolved = { ...open, id: 5, name: 'Resolved', slug: 'resolved' }
    vi.mocked(changeTicketStatus).mockResolvedValue(ticket as never)
    vi.mocked(getTicket).mockResolvedValue(ticket)

    const wrapper = mountDialog({ allowed_transitions: [resolved] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('5')
    expect(
      wrapper.find('[data-testid="ticket-status-resolution"]').exists(),
    ).toBe(true)
    expect(wrapper.find('[data-testid="ticket-status-reason"]').exists()).toBe(
      false,
    )

    await wrapper
      .get('[data-testid="ticket-status-resolution"]')
      .setValue('Fixed it after replacing the part.')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(changeTicketStatus).toHaveBeenCalledWith(1, {
      status_id: 5,
      resolution: 'Fixed it after replacing the part.',
    })
  })

  it('a 422 renders the server sentence verbatim and keeps the dialog mounted', async () => {
    vi.mocked(changeTicketStatus).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: { status_id: ['A ticket cannot move from New to Resolved.'] },
        },
      } as never),
    )
    const wrapper = mountDialog()
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('2')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="ticket-status-error"]').text()).toBe(
      'A ticket cannot move from New to Resolved.',
    )
    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      true,
    )
  })

  it('a non-422 failure falls back to errorMessage and keeps the dialog mounted', async () => {
    vi.mocked(changeTicketStatus).mockRejectedValue(new Error('offline'))
    const wrapper = mountDialog()
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('2')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="ticket-status-error"]').text()).toBe(
      'The API is unreachable.',
    )
  })

  it('cancel emits close and issues no request', async () => {
    const wrapper = mountDialog()
    await wrapper.get('[data-testid="ticket-status-cancel"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(changeTicketStatus).not.toHaveBeenCalled()
  })

  it('disables confirm while the note is under 10 characters and shows the hint, then enables it at 10', async () => {
    const resolved = { ...open, id: 5, name: 'Resolved', slug: 'resolved' }
    const wrapper = mountDialog({ allowed_transitions: [resolved] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('5')
    const confirm = () => wrapper.get('[data-testid="ticket-status-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()
    expect(
      wrapper.find('[data-testid="ticket-status-resolution-hint"]').exists(),
    ).toBe(true)

    await wrapper
      .get('[data-testid="ticket-status-resolution"]')
      .setValue('0123456789')
    expect(confirm().attributes('disabled')).toBeUndefined()
    expect(
      wrapper.find('[data-testid="ticket-status-resolution-hint"]').exists(),
    ).toBe(false)
  })

  it('a non-resolving move sends no resolution key at all', async () => {
    vi.mocked(changeTicketStatus).mockResolvedValue(ticket)
    vi.mocked(getTicket).mockResolvedValue(ticket)
    const wrapper = mountDialog()
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('2')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(changeTicketStatus).toHaveBeenCalledWith(1, { status_id: 2 })
  })

  it('switching the selection away from Resolved and back keeps the typed note', async () => {
    const resolved = { ...open, id: 5, name: 'Resolved', slug: 'resolved' }
    const wrapper = mountDialog({ allowed_transitions: [pending, resolved] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('5')
    await wrapper
      .get('[data-testid="ticket-status-resolution"]')
      .setValue('A note worth keeping around.')

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('4')
    expect(
      wrapper.find('[data-testid="ticket-status-resolution"]').exists(),
    ).toBe(false)

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('5')
    expect(
      (
        wrapper.get('[data-testid="ticket-status-resolution"]')
          .element as HTMLTextAreaElement
      ).value,
    ).toBe('A note worth keeping around.')
  })

  it('a 422 on resolution renders the server sentence in the dedicated resolution error, bypassing the client check', async () => {
    const resolved = { ...open, id: 5, name: 'Resolved', slug: 'resolved' }
    vi.mocked(changeTicketStatus).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: {
            resolution: ['The resolution note must be at least 10 characters.'],
          },
        },
      } as never),
    )
    const wrapper = mountDialog({ allowed_transitions: [resolved] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('5')
    await wrapper
      .get('[data-testid="ticket-status-resolution"]')
      .setValue('Long enough to pass the client check.')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.get('[data-testid="ticket-status-resolution-error"]').text(),
    ).toBe('The resolution note must be at least 10 characters.')
    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      true,
    )
  })

  it('selecting Reopened reveals the reason field and hides the resolution field, and vice versa', async () => {
    const reopened = { ...open, id: 7, name: 'Reopened', slug: 'reopened' }
    const resolved = { ...open, id: 5, name: 'Resolved', slug: 'resolved' }
    const wrapper = mountDialog({ allowed_transitions: [reopened, resolved] })

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('7')
    expect(wrapper.find('[data-testid="ticket-status-reason"]').exists()).toBe(
      true,
    )
    expect(
      wrapper.find('[data-testid="ticket-status-resolution"]').exists(),
    ).toBe(false)

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('5')
    expect(wrapper.find('[data-testid="ticket-status-reason"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-testid="ticket-status-resolution"]').exists(),
    ).toBe(true)
  })

  it('disables confirm while the reason is under 10 characters and shows the hint, then enables it at 10', async () => {
    const reopened = { ...open, id: 7, name: 'Reopened', slug: 'reopened' }
    const wrapper = mountDialog({ allowed_transitions: [reopened] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('7')
    const confirm = () => wrapper.get('[data-testid="ticket-status-confirm"]')
    expect(confirm().attributes('disabled')).toBeDefined()
    expect(
      wrapper.find('[data-testid="ticket-status-reason-hint"]').exists(),
    ).toBe(true)

    await wrapper
      .get('[data-testid="ticket-status-reason"]')
      .setValue('0123456789')
    expect(confirm().attributes('disabled')).toBeUndefined()
    expect(
      wrapper.find('[data-testid="ticket-status-reason-hint"]').exists(),
    ).toBe(false)
  })

  it('confirming a reopen sends only a reason, with no resolution key', async () => {
    const reopened = { ...open, id: 7, name: 'Reopened', slug: 'reopened' }
    vi.mocked(changeTicketStatus).mockResolvedValue(ticket)
    vi.mocked(getTicket).mockResolvedValue(ticket)
    const wrapper = mountDialog({ allowed_transitions: [reopened] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('7')
    await wrapper
      .get('[data-testid="ticket-status-reason"]')
      .setValue('The same disk failed a second time.')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(changeTicketStatus).toHaveBeenCalledWith(1, {
      status_id: 7,
      reason: 'The same disk failed a second time.',
    })
  })

  it('switching the selection away from Reopened and back keeps the typed reason', async () => {
    const reopened = { ...open, id: 7, name: 'Reopened', slug: 'reopened' }
    const wrapper = mountDialog({ allowed_transitions: [pending, reopened] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('7')
    await wrapper
      .get('[data-testid="ticket-status-reason"]')
      .setValue('A reason worth keeping around.')

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('4')
    expect(wrapper.find('[data-testid="ticket-status-reason"]').exists()).toBe(
      false,
    )

    await wrapper.get('[data-testid="ticket-status-select"]').setValue('7')
    expect(
      (
        wrapper.get('[data-testid="ticket-status-reason"]')
          .element as HTMLTextAreaElement
      ).value,
    ).toBe('A reason worth keeping around.')
  })

  it('a 422 on reason renders the server sentence in the dedicated reason error, bypassing the client check', async () => {
    const reopened = { ...open, id: 7, name: 'Reopened', slug: 'reopened' }
    vi.mocked(changeTicketStatus).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: {
            reason: ['The reopen reason must be at least 10 characters.'],
          },
        },
      } as never),
    )
    const wrapper = mountDialog({ allowed_transitions: [reopened] })
    await wrapper.get('[data-testid="ticket-status-select"]').setValue('7')
    await wrapper
      .get('[data-testid="ticket-status-reason"]')
      .setValue('Long enough to pass the client check.')
    await wrapper.get('[data-testid="ticket-status-confirm"]').trigger('click')
    await flushPromises()

    expect(
      wrapper.get('[data-testid="ticket-status-reason-error"]').text(),
    ).toBe('The reopen reason must be at least 10 characters.')
    expect(wrapper.find('[data-testid="ticket-status-dialog"]').exists()).toBe(
      true,
    )
  })
})
