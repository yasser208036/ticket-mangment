import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { getTicket } from '../api/tickets'
import type { TicketDetail } from '../api/tickets'
import { requestAssignment } from '../api/assignmentRequests'
import TicketRequestAssignmentDialog from './TicketRequestAssignmentDialog.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicket: vi.fn(),
}))
vi.mock('../api/assignmentRequests', async (loadOriginal) => ({
  ...(await loadOriginal()),
  requestAssignment: vi.fn(),
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
  mounted = mount(TicketRequestAssignmentDialog, {
    props: { ticket },
    global: { plugins: [createPinia()] },
  })
  return mounted
}

// Query document.body directly rather than the wrapper, since the dialog's
// content lands as a sibling of the wrapper's own root in the real DOM.
function body() {
  return new DOMWrapper(document.body)
}

describe('TicketRequestAssignmentDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(requestAssignment).mockReset()
    vi.mocked(getTicket).mockReset()
    vi.mocked(getTicket).mockResolvedValue(ticket)
  })

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('confirming with no note calls requestAssignment with undefined and emits requested', async () => {
    vi.mocked(requestAssignment).mockResolvedValue({
      id: 1,
      status: 'pending',
      note: null,
      decision_note: null,
      decided_at: null,
      ticket,
      requester: { id: 2, name: 'Agent' },
      decided_by: null,
      created_at: '2026-08-25T00:00:00Z',
    })
    const wrapper = mountDialog()
    await body()
      .get('[data-testid="ticket-request-assignment-confirm"]')
      .trigger('click')
    await flushPromises()

    expect(requestAssignment).toHaveBeenCalledWith(1, undefined)
    expect(wrapper.emitted('requested')).toHaveLength(1)
  })

  it('confirming with a note passes it through', async () => {
    vi.mocked(requestAssignment).mockResolvedValue({
      id: 1,
      status: 'pending',
      note: 'I know this one.',
      decision_note: null,
      decided_at: null,
      ticket,
      requester: { id: 2, name: 'Agent' },
      decided_by: null,
      created_at: '2026-08-25T00:00:00Z',
    })
    mountDialog()
    await body()
      .get('[data-testid="ticket-request-assignment-note"]')
      .setValue('I know this one.')
    await body()
      .get('[data-testid="ticket-request-assignment-confirm"]')
      .trigger('click')
    await flushPromises()

    expect(requestAssignment).toHaveBeenCalledWith(1, 'I know this one.')
  })

  it('blocks submit and shows a hint over 500 characters', async () => {
    mountDialog()
    await body()
      .get('[data-testid="ticket-request-assignment-note"]')
      .setValue('a'.repeat(501))
    expect(
      body()
        .get('[data-testid="ticket-request-assignment-confirm"]')
        .attributes('disabled'),
    ).toBeDefined()
    expect(
      body().find('[data-testid="ticket-request-assignment-hint"]').exists(),
    ).toBe(true)

    await body()
      .get('[data-testid="ticket-request-assignment-confirm"]')
      .trigger('click')
    expect(requestAssignment).not.toHaveBeenCalled()
  })

  it('renders a 422 under ticket in the error element', async () => {
    vi.mocked(requestAssignment).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: { errors: { ticket: ['This ticket already has an assignee.'] } },
      } as never),
    )
    mountDialog()
    await body()
      .get('[data-testid="ticket-request-assignment-confirm"]')
      .trigger('click')
    await flushPromises()
    expect(
      body().get('[data-testid="ticket-request-assignment-error"]').text(),
    ).toBe('This ticket already has an assignee.')
  })

  it('cancel emits close and issues no request', async () => {
    const wrapper = mountDialog()
    await body()
      .get('[data-testid="ticket-request-assignment-cancel"]')
      .trigger('click')
    expect(wrapper.emitted('close')).toHaveLength(1)
    expect(requestAssignment).not.toHaveBeenCalled()
  })
})
