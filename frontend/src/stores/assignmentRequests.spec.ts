import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import {
  approveAssignmentRequest,
  declineAssignmentRequest,
  listAssignmentRequests,
} from '../api/assignmentRequests'
import type { AssignmentRequest } from '../api/assignmentRequests'
import { useAssignmentRequestsStore } from './assignmentRequests'

vi.mock('../api/assignmentRequests', () => ({
  listAssignmentRequests: vi.fn(),
  approveAssignmentRequest: vi.fn(),
  declineAssignmentRequest: vi.fn(),
}))

const sample: AssignmentRequest = {
  id: 1,
  status: 'pending',
  note: null,
  decision_note: null,
  decided_at: null,
  ticket: { id: 5 } as never,
  requester: { id: 2, name: 'Alan Turing' },
  decided_by: null,
  created_at: '2026-08-29T00:00:00Z',
}

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

describe('assignmentRequests store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(listAssignmentRequests).mockReset()
    vi.mocked(approveAssignmentRequest).mockReset()
    vi.mocked(declineAssignmentRequest).mockReset()
  })

  it('fills items on a successful load', async () => {
    vi.mocked(listAssignmentRequests).mockResolvedValue(page([sample]))
    const store = useAssignmentRequestsStore()
    await store.load()
    expect(store.items).toEqual([sample])
    expect(store.error).toBeNull()
    expect(store.loading).toBe(false)
  })

  it('sets error, empties items and clears loading on a rejection', async () => {
    vi.mocked(listAssignmentRequests).mockRejectedValue(new Error('offline'))
    const store = useAssignmentRequestsStore()
    await store.load()
    expect(store.error).toBe('The API is unreachable.')
    expect(store.items).toEqual([])
    expect(store.loading).toBe(false)
  })

  it('approve calls the API then reloads the list', async () => {
    vi.mocked(listAssignmentRequests)
      .mockResolvedValueOnce(page([sample]))
      .mockResolvedValueOnce(page([]))
    vi.mocked(approveAssignmentRequest).mockResolvedValue({
      ...sample,
      status: 'approved',
    })
    const store = useAssignmentRequestsStore()
    await store.load()
    await store.approve(1)
    expect(approveAssignmentRequest).toHaveBeenCalledWith(1)
    expect(store.items).toEqual([])
  })

  it('decline calls the API with the note then reloads the list', async () => {
    vi.mocked(listAssignmentRequests)
      .mockResolvedValueOnce(page([sample]))
      .mockResolvedValueOnce(page([]))
    vi.mocked(declineAssignmentRequest).mockResolvedValue({
      ...sample,
      status: 'declined',
    })
    const store = useAssignmentRequestsStore()
    await store.load()
    await store.decline(1, 'Not the right fit.')
    expect(declineAssignmentRequest).toHaveBeenCalledWith(
      1,
      'Not the right fit.',
    )
    expect(store.items).toEqual([])
  })
})
