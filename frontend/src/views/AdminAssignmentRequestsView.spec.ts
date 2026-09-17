import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  approveAssignmentRequest,
  declineAssignmentRequest,
  listAssignmentRequests,
} from '../api/assignmentRequests'
import type { AssignmentRequest } from '../api/assignmentRequests'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import AdminAssignmentRequestsView from './AdminAssignmentRequestsView.vue'

vi.mock('../api/assignmentRequests', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listAssignmentRequests: vi.fn(),
  approveAssignmentRequest: vi.fn(),
  declineAssignmentRequest: vi.fn(),
}))

function page(items: AssignmentRequest[]) {
  return {
    data: items,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: 1,
      last_page: 1,
      path: '/admin/assignment-requests',
      per_page: 15,
      to: items.length,
      total: items.length,
    },
  }
}

const sample: AssignmentRequest = {
  id: 1,
  status: 'pending',
  note: 'I have handled this before.',
  decision_note: null,
  decided_at: null,
  ticket: {
    id: 5,
    reference: 'TKT-2026-000005',
    subject: 'Printer jam',
  } as never,
  requester: { id: 2, name: 'Alan Turing' },
  decided_by: null,
  created_at: '2026-08-29T00:00:00Z',
}

async function mountView(items: AssignmentRequest[] | null) {
  if (items === null)
    vi.mocked(listAssignmentRequests).mockRejectedValue(new Error('offline'))
  else vi.mocked(listAssignmentRequests).mockResolvedValue(page(items))
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 1,
    name: 'Admin',
    email: 'admin@example.test',
    role: 'admin',
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  const router = createAppRouter(createMemoryHistory())
  await router.push('/admin/assignment-requests')
  await router.isReady()
  const wrapper = mount(AdminAssignmentRequestsView, {
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return wrapper
}

// The decline dialog renders via <Teleport to="body">, so it lands as a
// sibling of the wrapper's own root in the real DOM, not a descendant.
function body() {
  return new DOMWrapper(document.body)
}

describe('AdminAssignmentRequestsView', () => {
  beforeEach(() => {
    vi.mocked(listAssignmentRequests).mockReset()
    vi.mocked(approveAssignmentRequest).mockReset()
    vi.mocked(declineAssignmentRequest).mockReset()
  })

  it('renders a row per pending request', async () => {
    const wrapper = await mountView([sample])
    expect(
      wrapper.findAll('[data-testid="assignment-request-row"]'),
    ).toHaveLength(1)
    expect(wrapper.text()).toContain('TKT-2026-000005')
    expect(wrapper.text()).toContain('Alan Turing')
  })

  it('renders the empty state with no requests', async () => {
    const wrapper = await mountView([])
    expect(
      wrapper.find('[data-testid="assignment-requests-empty"]').exists(),
    ).toBe(true)
  })

  it('renders the error state on a rejection', async () => {
    const wrapper = await mountView(null)
    expect(
      wrapper.find('[data-testid="assignment-requests-error"]').exists(),
    ).toBe(true)
  })

  it('Approve calls the store action', async () => {
    vi.mocked(approveAssignmentRequest).mockResolvedValue({
      ...sample,
      status: 'approved',
    })
    const wrapper = await mountView([sample])
    vi.mocked(listAssignmentRequests).mockResolvedValueOnce(page([]))
    await wrapper
      .get('[data-testid="assignment-request-approve"]')
      .trigger('click')
    await flushPromises()
    expect(approveAssignmentRequest).toHaveBeenCalledWith(1)
  })

  it('Decline opens a dialog and confirming calls the store action with the note', async () => {
    vi.mocked(declineAssignmentRequest).mockResolvedValue({
      ...sample,
      status: 'declined',
    })
    const wrapper = await mountView([sample])
    vi.mocked(listAssignmentRequests).mockResolvedValueOnce(page([]))
    await wrapper
      .get('[data-testid="assignment-request-decline"]')
      .trigger('click')
    expect(
      body().find('[data-testid="assignment-request-decline-dialog"]').exists(),
    ).toBe(true)

    await body()
      .get('[data-testid="assignment-request-decline-note"]')
      .setValue('Not the right fit.')
    await body()
      .get('[data-testid="assignment-request-decline-confirm"]')
      .trigger('click')
    await flushPromises()
    expect(declineAssignmentRequest).toHaveBeenCalledWith(
      1,
      'Not the right fit.',
    )
  })
})
