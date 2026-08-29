import client from './client'

export interface HealthCheck {
  ok: boolean
  error?: string
}

export interface HealthResponse {
  status: 'ok' | 'degraded'
  app: string
  environment: string
  version: string
  api: string
  time: string
  checks: Record<string, HealthCheck>
}

export async function getHealth(): Promise<HealthResponse> {
  const { data } = await client.get<HealthResponse>('/health', {
    validateStatus: (status) => status === 200 || status === 503,
  })
  return data
}
