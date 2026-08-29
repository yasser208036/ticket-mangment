import client from './client'
import type { Category } from './categories'
import type { Priority } from './priorities'
import type { Status } from './statuses'
import type { Paginated } from './pagination'
export interface Requester {
  id: number
  name: string
  email: string
  phone: string | null
  company: string | null
}
export interface TicketStaff {
  id: number
  name: string
}
export interface TicketPermissions {
  update: boolean
  assign: boolean
  claim: boolean
  change_status: boolean
  escalate: boolean
  delete: boolean
  add_note: boolean
}
export interface Ticket {
  id: number
  reference: string
  subject: string
  description: string
  requester: Requester
  category: Category
  priority: Priority
  status: Status
  assignee: TicketStaff | null
  creator: TicketStaff | null
  escalation_level: number
  escalated_at: string | null
  first_responded_at: string | null
  resolved_at: string | null
  closed_at: string | null
  created_at: string
  updated_at: string
}
export interface TicketResolution {
  note: string
  at: string | null
  by: TicketStaff | null
}
export interface TicketDetail extends Ticket {
  escalated_by: TicketStaff | null
  escalation_reason: string | null
  can: TicketPermissions
  allowed_transitions: Status[]
  resolution: TicketResolution | null
  reopen_count: number
}
export type TicketListItem = Omit<Ticket, 'description'>
export type TicketSort =
  'created_at' | 'updated_at' | 'priority' | 'relevance' | 'escalated_at'
export type TicketDirection = 'asc' | 'desc'
export type AssigneeFilter = 'me' | 'unassigned' | number
export interface TicketListQuery {
  q?: string
  page?: number
  per_page?: number
  status_id?: number[]
  priority_id?: number[]
  category_id?: number[]
  assigned_to?: AssigneeFilter
  escalated?: boolean
  sort?: TicketSort
  direction?: TicketDirection
}
export interface CreateTicketPayload {
  requester: {
    name: string
    email: string
    phone?: string | null
    company?: string | null
  }
  subject: string
  description: string
  category_id: number
  priority_id?: number
}
export async function createTicket(
  payload: CreateTicketPayload,
): Promise<Ticket> {
  const { data } = await client.post<{ data: Ticket }>('/tickets', payload)
  return data.data
}
export async function getTicket(id: number): Promise<TicketDetail> {
  const { data } = await client.get<{ data: TicketDetail }>(`/tickets/${id}`)
  return data.data
}

export async function assignTicket(
  id: number,
  assignedTo: number | null,
  reason?: string,
): Promise<Ticket> {
  const { data } = await client.post<{ data: Ticket }>(
    `/tickets/${id}/assign`,
    { assigned_to: assignedTo, ...(reason ? { reason } : {}) },
  )
  return data.data
}

export async function claimTicket(id: number): Promise<Ticket> {
  const { data } = await client.post<{ data: Ticket }>(`/tickets/${id}/claim`)
  return data.data
}

export async function escalateTicket(
  id: number,
  reason: string,
): Promise<Ticket> {
  const { data } = await client.post<{ data: Ticket }>(
    `/tickets/${id}/escalate`,
    { reason },
  )
  return data.data
}
export interface ChangeStatusPayload {
  status_id: number
  resolution?: string
  reason?: string
}
export async function changeTicketStatus(
  id: number,
  payload: ChangeStatusPayload,
): Promise<Ticket> {
  const { data } = await client.post<{ data: Ticket }>(
    `/tickets/${id}/status`,
    payload,
  )
  return data.data
}

export async function listTickets(
  query: TicketListQuery = {},
): Promise<Paginated<TicketListItem>> {
  const { data } = await client.get<Paginated<TicketListItem>>('/tickets', {
    params: query,
  })
  return data
}

export async function updateTicket(
  id: number,
  payload: Partial<
    Pick<
      CreateTicketPayload,
      'subject' | 'description' | 'category_id' | 'priority_id'
    >
  >,
): Promise<TicketDetail> {
  const { data } = await client.patch<{ data: TicketDetail }>(
    `/tickets/${id}`,
    payload,
  )
  return data.data
}

export async function deleteTicket(id: number): Promise<void> {
  await client.delete(`/tickets/${id}`)
}
