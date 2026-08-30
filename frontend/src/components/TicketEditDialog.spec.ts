import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getTicket, updateTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import { useMasterDataStore } from '../stores/masterData'
import TicketEditDialog from './TicketEditDialog.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicket: vi.fn(),
  updateTicket: vi.fn(),
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

function mountDialog(ticketOverride: TicketDetail = ticket) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useMasterDataStore().categories = [
    {
      id: 1,
      name: 'Hardware',
      slug: 'hardware',
      color: '#111',
      is_active: true,
      sort_order: 1,
    } as never,
    {
      id: 2,
      name: 'Software',
      slug: 'software',
      color: '#222',
      is_active: true,
      sort_order: 2,
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
    {
      id: 3,
      name: 'High',
      slug: 'high',
      level: 3,
      color: '#F97316',
      is_default: false,
    },
  ]
  mounted = mount(TicketEditDialog, {
    props: { ticket: ticketOverride },
    global: { plugins: [pinia] },
  })
  return mounted
}

// The dialog renders via <Teleport to="body">, so its content lands as a
// sibling of the wrapper's own root in the real DOM, not a descendant --
// query document.body directly rather than the wrapper.
function body() {
  return new DOMWrapper(document.body)
}

describe('TicketEditDialog', () => {
  beforeEach(() => {
    vi.mocked(updateTicket).mockReset()
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicket).mockResolvedValue(ticket)
  })

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('pre-populates all four fields from the ticket prop', () => {
    mountDialog()

    expect(
      (
        body().get('[data-testid="ticket-edit-subject"]')
          .element as HTMLInputElement
      ).value,
    ).toBe(ticket.subject)
    expect(
      (
        body().get('[data-testid="ticket-edit-description"]')
          .element as HTMLTextAreaElement
      ).value,
    ).toBe(ticket.description)
    expect(
      (
        body().get('[data-testid="ticket-edit-category"]')
          .element as HTMLSelectElement
      ).value,
    ).toBe(String(ticket.category.id))
    expect(
      (
        body().get('[data-testid="ticket-edit-priority"]')
          .element as HTMLSelectElement
      ).value,
    ).toBe(String(ticket.priority.id))
  })

  it('submit sends only the changed fields and emits saved', async () => {
    vi.mocked(updateTicket).mockResolvedValue({
      ...ticket,
      subject: 'New subject',
    })
    const wrapper = mountDialog()

    await body()
      .get('[data-testid="ticket-edit-subject"]')
      .setValue('New subject')
    await body().get('[data-testid="ticket-edit-submit"]').trigger('click')
    await flushPromises()

    expect(updateTicket).toHaveBeenCalledWith(1, { subject: 'New subject' })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('a 422 maps field errors and does not emit saved', async () => {
    vi.mocked(updateTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: { errors: { subject: ['That subject is already in use.'] } },
      } as never),
    )
    const wrapper = mountDialog()

    await body()
      .get('[data-testid="ticket-edit-subject"]')
      .setValue('Changed subject')
    await body().get('[data-testid="ticket-edit-submit"]').trigger('click')
    await flushPromises()

    expect(body().get('[data-testid="ticket-edit-error-subject"]').text()).toBe(
      'That subject is already in use.',
    )
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('cancel with no changes emits close immediately, with no confirm prompt', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm')
    const wrapper = mountDialog()

    await body().get('[data-testid="ticket-edit-cancel"]').trigger('click')

    expect(confirmSpy).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
    confirmSpy.mockRestore()
  })

  it('cancel while dirty asks for confirmation, and only closes if confirmed', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = mountDialog()

    await body().get('[data-testid="ticket-edit-subject"]').setValue('Changed')
    await body().get('[data-testid="ticket-edit-cancel"]').trigger('click')
    expect(confirmSpy).toHaveBeenCalled()
    expect(wrapper.emitted('close')).toBeUndefined()

    confirmSpy.mockReturnValue(true)
    await body().get('[data-testid="ticket-edit-cancel"]').trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
    confirmSpy.mockRestore()
  })

  it('prevents an unload while dirty', async () => {
    mountDialog()
    await body().get('[data-testid="ticket-edit-subject"]').setValue('Changed')

    const event = new Event('beforeunload', { cancelable: true })
    window.dispatchEvent(event)

    expect(event.defaultPrevented).toBe(true)
  })

  it('a category deactivated since filing still renders as the selected option', () => {
    mountDialog({
      ...ticket,
      category: {
        id: 99,
        name: 'Retired',
        slug: 'retired',
        color: '#999',
        is_active: false,
        sort_order: 9,
      } as never,
    })

    const select = body().get('[data-testid="ticket-edit-category"]')
      .element as HTMLSelectElement
    expect(select.value).toBe('99')
    expect(
      body().get('option[value="99"]').attributes('disabled'),
    ).toBeDefined()
  })
})
