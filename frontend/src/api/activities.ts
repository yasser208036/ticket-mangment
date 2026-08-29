import client from './client'
import type { Paginated } from './pagination'

export interface ActivityActor {
  id: number
  name: string
}

export interface TicketActivity {
  id: number
  event: string
  field: string | null
  old_value: string | null
  new_value: string | null
  from_label: string | null
  to_label: string | null
  meta: Record<string, unknown>
  actor: ActivityActor | null
  created_at: string
}

export async function listTicketActivities(
  ticketId: number,
  page = 1,
): Promise<Paginated<TicketActivity>> {
  const { data } = await client.get<Paginated<TicketActivity>>(
    `/tickets/${ticketId}/activities`,
    { params: { page } },
  )
  return data
}

export async function addTicketNote(
  ticketId: number,
  body: string,
): Promise<TicketActivity> {
  const { data } = await client.post<{ data: TicketActivity }>(
    `/tickets/${ticketId}/notes`,
    { body },
  )
  return data.data
}
