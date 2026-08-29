import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getHealth } from '../api/health'
import type { HealthResponse } from '../api/health'
import { useHealthStore } from '../stores/health'
import HealthView from './HealthView.vue'

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
const mountHealthView = () =>
  mount(HealthView, { global: { plugins: [createPinia()] } })

describe('HealthView', () => {
  beforeEach(() => vi.mocked(getHealth).mockReset())

  it('renders status, version, and database check', async () => {
    vi.mocked(getHealth).mockResolvedValue(healthyResponse)
    const wrapper = mountHealthView()
    await flushPromises()
    expect(wrapper.get('[data-testid="health-status"]').text()).toBe('ok')
    expect(wrapper.get('[data-testid="health-database"]').text()).toBe(
      'reachable',
    )
    expect(wrapper.text()).toContain('0.1.0')
  })

  it('renders probe message for failed database check', async () => {
    vi.mocked(getHealth).mockResolvedValue({
      ...healthyResponse,
      status: 'degraded',
      checks: { database: { ok: false, error: 'unavailable' } },
    })
    const wrapper = mountHealthView()
    await flushPromises()
    expect(wrapper.get('[data-testid="health-database"]').text()).toBe(
      'unavailable',
    )
    expect(wrapper.find('[data-testid="health-error"]').exists()).toBe(false)
  })

  it('renders error when API is unreachable', async () => {
    vi.mocked(getHealth).mockResolvedValue(healthyResponse)
    const wrapper = mountHealthView()
    const health = useHealthStore()
    health.loading = false
    health.error = 'Network Error'
    health.data = null
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[data-testid="health-error"]').text()).toBe(
      'Network Error',
    )
    expect(wrapper.find('[data-testid="health-result"]').exists()).toBe(false)
  })
})
