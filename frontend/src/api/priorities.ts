import client from './client'
export interface Priority {
  id: number
  name: string
  slug: string
  level: number
  color: string
  is_default: boolean
}
export async function listPriorities(): Promise<Priority[]> {
  const { data } = await client.get<{ data: Priority[] }>('/priorities')
  return data.data
}
