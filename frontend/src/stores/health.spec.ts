import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getHealth } from '../api/health'
import type { HealthResponse } from '../api/health'
import { useHealthStore } from './health'

vi.mock('../api/health', () => ({ getHealth: vi.fn() }))
const healthyResponse: HealthResponse = {
  status: 'ok',
  app: 'Ticket Management',
  environment: 'testing',
  version: '0.1.0',
  api: 'v1',
  time: '2026-08-25T12:00:00Z',
  checks: { database: { ok: true } },
}

describe('health store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(getHealth).mockReset()
  })

  it('fills data on healthy response', async () => {
    vi.mocked(getHealth).mockResolvedValue(healthyResponse)
    const health = useHealthStore()
    await health.load()
    expect(health.data).toEqual(healthyResponse)
    expect(health.error).toBeNull()
    expect(health.loading).toBe(false)
  })

  it('treats degraded payload as data', async () => {
    vi.mocked(getHealth).mockResolvedValue({
      ...healthyResponse,
      status: 'degraded',
      checks: { database: { ok: false, error: 'unavailable' } },
    })
    const health = useHealthStore()
    await health.load()
    expect(health.data?.status).toBe('degraded')
    expect(health.error).toBeNull()
  })

  it('sets error when request fails', async () => {
    vi.mocked(getHealth).mockRejectedValue(new Error('Network Error'))
    const health = useHealthStore()
    await health.load()
    expect(health.error).toBe('Network Error')
    expect(health.data).toBeNull()
    expect(health.loading).toBe(false)
  })
})
