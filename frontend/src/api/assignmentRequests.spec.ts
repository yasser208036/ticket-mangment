import type { AxiosAdapter, InternalAxiosRequestConfig } from 'axios'
import { describe, expect, it } from 'vitest'
import {
  approveAssignmentRequest,
  declineAssignmentRequest,
  listAssignmentRequests,
  requestAssignment,
} from './assignmentRequests'
import type { AssignmentRequest } from './assignmentRequests'
import client from './client'

const sample: AssignmentRequest = {
  id: 1,
  status: 'pending',
  note: 'I have handled this before.',
  decision_note: null,
  decided_at: null,
  ticket: { id: 5 } as never,
  requester: { id: 2, name: 'Alan Turing' },
  decided_by: null,
  created_at: '2026-08-29T00:00:00Z',
}

function capture(
  payload: unknown,
  status = 200,
): {
  seen: () => InternalAxiosRequestConfig | undefined
} {
  let captured: InternalAxiosRequestConfig | undefined
  const adapter: AxiosAdapter = async (config) => {
    captured = config
    return { data: payload, status, statusText: 'OK', headers: {}, config }
  }
  client.defaults.adapter = adapter
  return { seen: () => captured }
}

describe('requestAssignment', () => {
  it('posts to the ticket assignment-requests route with a note', async () => {
    const request = capture({ data: sample })
    await requestAssignment(5, 'I have handled this before.')
    expect(request.seen()?.method).toBe('post')
    expect(request.seen()?.url).toBe('/tickets/5/assignment-requests')
    expect(JSON.parse(String(request.seen()?.data))).toEqual({
      note: 'I have handled this before.',
    })
  })

  it('sends an empty body when no note is given', async () => {
    const request = capture({ data: sample })
    await requestAssignment(5)
    expect(JSON.parse(String(request.seen()?.data))).toEqual({})
  })
})

describe('listAssignmentRequests', () => {
  it('calls GET /admin/assignment-requests with the query and unwraps the page', async () => {
    const page = {
      data: [sample],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        path: '/admin/assignment-requests',
        per_page: 15,
        to: 1,
        total: 1,
      },
    }
    const request = capture(page)
    const result = await listAssignmentRequests({ status: 'declined' })
    expect(request.seen()?.method).toBe('get')
    expect(request.seen()?.url).toBe('/admin/assignment-requests')
    expect(request.seen()?.params).toEqual({ status: 'declined' })
    expect(result).toEqual(page)
  })
})

describe('approveAssignmentRequest', () => {
  it('posts to the approve route and unwraps data.data', async () => {
    const request = capture({ data: sample })
    const result = await approveAssignmentRequest(1)
    expect(request.seen()?.method).toBe('post')
    expect(request.seen()?.url).toBe('/admin/assignment-requests/1/approve')
    expect(result).toEqual(sample)
  })
})

describe('declineAssignmentRequest', () => {
  it('posts to the decline route with an optional note', async () => {
    const request = capture({ data: sample })
    await declineAssignmentRequest(1, 'Not the right fit.')
    expect(request.seen()?.method).toBe('post')
    expect(request.seen()?.url).toBe('/admin/assignment-requests/1/decline')
    expect(JSON.parse(String(request.seen()?.data))).toEqual({
      note: 'Not the right fit.',
    })
  })
})
