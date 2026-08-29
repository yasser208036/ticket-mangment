import client from './client'
import type { AdminUser } from './users'
export type LoadBand = 'high' | 'normal' | 'low'
export interface WorkloadPriority {
  id: number
  name: string
  slug: string
  color: string
  level: number
}
export interface WorkloadCell {
  priority_id: number
  count: number
}
export interface WorkloadRow {
  user: AdminUser
  open_total: number
  in_average: boolean
  load: LoadBand | null
  needs_reassignment: boolean
  by_priority: WorkloadCell[]
}
export interface Workload {
  average_open: number | null
  band: number | null
  priorities: WorkloadPriority[]
  open_status_ids: number[]
  agents: WorkloadRow[]
}
export async function getWorkload(): Promise<Workload> {
  const { data } = await client.get<{ data: Workload }>('/admin/workload')
  return data.data
}
