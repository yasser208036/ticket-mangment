import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getTicketStats } from '../api/stats'
import type { TicketStats } from '../api/stats'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import DashboardView from './DashboardView.vue'

vi.mock('../api/stats', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicketStats: vi.fn(),
}))

function stats(scope: 'own' | 'all'): TicketStats {
  return {
    scope,
    total: 10,
    unassigned: 3,
    escalated: 2,
    mine_open: 4,
    by_status: [],
    by_priority: [],
  }
}

async function mountDashboard(response: TicketStats) {
  vi.mocked(getTicketStats).mockResolvedValue(response)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 1,
    name: 'Agent',
    email: 'agent@example.test',
    role: 'agent',
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  const router = createAppRouter(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const wrapper = mount(DashboardView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

describe('DashboardView escalated card link', () => {
  beforeEach(() => {
    vi.mocked(getTicketStats).mockReset()
  })

  it('links to ?escalated=true with no assignee for scope all', async () => {
    const wrapper = await mountDashboard(stats('all'))
    const href = wrapper
      .get('[data-testid="stat-escalated"]')
      .attributes('href')
    expect(href).toBe('/tickets?escalated=true')
  })

  it('links to ?escalated=true&assignee=me for scope own', async () => {
    const wrapper = await mountDashboard(stats('own'))
    const href = wrapper
      .get('[data-testid="stat-escalated"]')
      .attributes('href')
    expect(href).toBe('/tickets?escalated=true&assignee=me')
  })

  it('keeps the unassigned card link identical in both scopes', async () => {
    const own = await mountDashboard(stats('own'))
    const all = await mountDashboard(stats('all'))
    expect(own.get('[data-testid="stat-unassigned"]').attributes('href')).toBe(
      all.get('[data-testid="stat-unassigned"]').attributes('href'),
    )
  })

  it('the escalated card label still follows scope', async () => {
    const own = await mountDashboard(stats('own'))
    expect(own.get('[data-testid="stat-escalated"]').text()).toContain(
      'My escalated tickets',
    )
    const all = await mountDashboard(stats('all'))
    expect(all.get('[data-testid="stat-escalated"]').text()).toContain(
      'Escalated tickets',
    )
  })
})
