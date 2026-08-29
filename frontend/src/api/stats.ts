import client from './client'
import type { StatusBucket } from './statuses'
export interface StatusCount {
  id: number
  name: string
  slug: string
  color: string
  bucket: StatusBucket
  is_terminal: boolean
  count: number
}
export interface PriorityCount {
  id: number
  name: string
  slug: string
  color: string
  level: number
  count: number
}
export interface TicketStats {
  scope: 'own' | 'all'
  total: number
  unassigned: number
  escalated: number
  mine_open: number
  by_status: StatusCount[]
  by_priority: PriorityCount[]
}
export async function getTicketStats(): Promise<TicketStats> {
  const { data } = await client.get<{ data: TicketStats }>('/tickets/stats')
  return data.data
}
