import client from './client'

/** id and name only -- see the backend's AgentController docblock. */
export interface AgentOption {
  id: number
  name: string
}

export async function listAgents(): Promise<AgentOption[]> {
  const { data } = await client.get<{ data: AgentOption[] }>('/agents')
  return data.data
}
