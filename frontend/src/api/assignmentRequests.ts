import client from './client'
import type { Ticket, TicketStaff } from './tickets'
import type { Paginated } from './pagination'

export type AssignmentRequestStatus = 'pending' | 'approved' | 'declined'

export interface AssignmentRequest {
  id: number
  status: AssignmentRequestStatus
  note: string | null
  decision_note: string | null
  decided_at: string | null
  ticket: Ticket
  requester: TicketStaff
  decided_by: TicketStaff | null
  created_at: string
}

export interface AssignmentRequestQuery {
  status?: AssignmentRequestStatus
  page?: number
  per_page?: number
}

export async function requestAssignment(
  ticketId: number,
  note?: string,
): Promise<AssignmentRequest> {
  const { data } = await client.post<{ data: AssignmentRequest }>(
    `/tickets/${ticketId}/assignment-requests`,
    note ? { note } : {},
  )
  return data.data
}

export async function listAssignmentRequests(
  query: AssignmentRequestQuery = {},
): Promise<Paginated<AssignmentRequest>> {
  const { data } = await client.get<Paginated<AssignmentRequest>>(
    '/admin/assignment-requests',
    { params: query },
  )
  return data
}

export async function approveAssignmentRequest(
  id: number,
): Promise<AssignmentRequest> {
  const { data } = await client.post<{ data: AssignmentRequest }>(
    `/admin/assignment-requests/${id}/approve`,
  )
  return data.data
}

export async function declineAssignmentRequest(
  id: number,
  note?: string,
): Promise<AssignmentRequest> {
  const { data } = await client.post<{ data: AssignmentRequest }>(
    `/admin/assignment-requests/${id}/decline`,
    note ? { note } : {},
  )
  return data.data
}
