import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getTicketStats } from '../api/stats'
import type { TicketStats } from '../api/stats'
import type { UserRole } from '../api/auth'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import DashboardView from './DashboardView.vue'

vi.mock('../api/stats', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicketStats: vi.fn(),
}))

function stats(scope: TicketStats['scope']): TicketStats {
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

const ROLE_FOR_SCOPE: Record<TicketStats['scope'], UserRole> = {
  all: 'admin',
  assigned: 'agent',
  authored: 'user',
}

async function mountDashboard(response: TicketStats) {
  vi.mocked(getTicketStats).mockResolvedValue(response)
  const pinia = createPinia()
  setActivePinia(pinia)
  const role = ROLE_FOR_SCOPE[response.scope]
  useAuthStore().user = {
    id: 1,
    name: 'Test User',
    email: `${role}@example.test`,
    role,
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

  it('links to ?escalated=true&assignee=me for scope assigned', async () => {
    const wrapper = await mountDashboard(stats('assigned'))
    const href = wrapper
      .get('[data-testid="stat-escalated"]')
      .attributes('href')
    expect(href).toBe('/tickets?escalated=true&assignee=me')
  })

  it('links to ?escalated=true with no assignee for scope authored', async () => {
    const wrapper = await mountDashboard(stats('authored'))
    const href = wrapper
      .get('[data-testid="stat-escalated"]')
      .attributes('href')
    expect(href).toBe('/tickets?escalated=true')
  })

  it('the escalated card label still follows scope', async () => {
    const assigned = await mountDashboard(stats('assigned'))
    expect(assigned.get('[data-testid="stat-escalated"]').text()).toContain(
      'My escalated tickets',
    )
    const authored = await mountDashboard(stats('authored'))
    expect(authored.get('[data-testid="stat-escalated"]').text()).toContain(
      'My escalated tickets',
    )
    const all = await mountDashboard(stats('all'))
    expect(all.get('[data-testid="stat-escalated"]').text()).toContain(
      'Escalated tickets',
    )
  })
})

describe('DashboardView scope-dependent sections', () => {
  beforeEach(() => {
    vi.mocked(getTicketStats).mockReset()
  })

  it('scope all: shows Queue by status and the Unassigned card', async () => {
    const wrapper = await mountDashboard(stats('all'))
    expect(wrapper.get('[data-testid="dashboard-by-status"]').text()).toContain(
      'Queue by status',
    )
    expect(wrapper.find('[data-testid="stat-unassigned"]').exists()).toBe(true)
  })

  it('scope assigned: shows My tickets by status and the Unassigned card', async () => {
    const wrapper = await mountDashboard(stats('assigned'))
    expect(wrapper.get('[data-testid="dashboard-by-status"]').text()).toContain(
      'My tickets by status',
    )
    expect(wrapper.find('[data-testid="stat-unassigned"]').exists()).toBe(true)
  })

  it('scope authored: shows My tickets by status and hides the Unassigned card', async () => {
    const wrapper = await mountDashboard(stats('authored'))
    expect(wrapper.get('[data-testid="dashboard-by-status"]').text()).toContain(
      'My tickets by status',
    )
    expect(wrapper.find('[data-testid="stat-unassigned"]').exists()).toBe(false)
  })

  it('scope authored: hides the My open tickets card (assignee: me means nothing for an end user)', async () => {
    const wrapper = await mountDashboard(stats('authored'))
    expect(wrapper.find('[data-testid="stat-mine-open"]').exists()).toBe(false)
  })

  it('scope assigned: shows the My open tickets card', async () => {
    const wrapper = await mountDashboard(stats('assigned'))
    expect(wrapper.find('[data-testid="stat-mine-open"]').exists()).toBe(true)
  })
})
