import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { deleteTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import TicketDeleteDialog from './TicketDeleteDialog.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  deleteTicket: vi.fn(),
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

function mountDialog() {
  mounted = mount(TicketDeleteDialog, {
    props: { ticket },
    global: { plugins: [createPinia()] },
  })
  return mounted
}

// The dialog renders via <Teleport to="body">, so its content lands as a
// sibling of the wrapper's own root in the real DOM, not a descendant --
// query document.body directly rather than the wrapper.
function body() {
  return new DOMWrapper(document.body)
}

describe('TicketDeleteDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(deleteTicket).mockReset()
  })

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('the prompt names the ticket reference and subject', () => {
    mountDialog()
    const text = body().get('[data-testid="ticket-delete-prompt"]').text()
    expect(text).toContain('TKT-2026-000001')
    expect(text).toContain('Printer jam')
  })

  it('confirm deletes the ticket and emits deleted', async () => {
    vi.mocked(deleteTicket).mockResolvedValue(undefined)
    const wrapper = mountDialog()

    await body().get('[data-testid="ticket-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(deleteTicket).toHaveBeenCalledWith(1)
    expect(wrapper.emitted('deleted')).toHaveLength(1)
  })

  it('cancel emits close and issues no request', async () => {
    const wrapper = mountDialog()

    await body().get('[data-testid="ticket-delete-cancel"]').trigger('click')

    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(deleteTicket).not.toHaveBeenCalled()
  })

  it('an API error renders and does not emit deleted', async () => {
    vi.mocked(deleteTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 403,
      } as never),
    )
    const wrapper = mountDialog()

    await body().get('[data-testid="ticket-delete-confirm"]').trigger('click')
    await flushPromises()

    expect(body().get('[data-testid="ticket-delete-error"]').text()).toBe(
      'You do not have permission to do that.',
    )
    expect(wrapper.emitted('deleted')).toBeUndefined()
  })

  it('confirm is disabled while deleting', async () => {
    let resolveDelete!: () => void
    vi.mocked(deleteTicket).mockReturnValue(
      new Promise<void>((resolve) => {
        resolveDelete = resolve
      }),
    )
    const wrapper = mountDialog()

    const confirm = body().get('[data-testid="ticket-delete-confirm"]')
    await confirm.trigger('click')
    expect(
      body()
        .get('[data-testid="ticket-delete-confirm"]')
        .attributes('disabled'),
    ).toBeDefined()

    resolveDelete()
    await flushPromises()
    expect(wrapper.emitted('deleted')).toHaveLength(1)
  })
})
