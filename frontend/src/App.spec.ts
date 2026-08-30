import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getTicketStats } from './api/stats'
import type { UserRole } from './api/auth'
import { createAppRouter } from './router'
import { useAuthStore } from './stores/auth'
import App from './App.vue'

vi.mock('./api/stats', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicketStats: vi.fn(),
}))

async function mountApp(role: UserRole) {
  vi.mocked(getTicketStats).mockResolvedValue({
    scope:
      role === 'admin' ? 'all' : role === 'agent' ? 'assigned' : 'authored',
    total: 0,
    unassigned: 0,
    escalated: 0,
    mine_open: 0,
    by_status: [],
    by_priority: [],
  })
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 1,
    name: `${role[0]!.toUpperCase()}${role.slice(1)}`,
    email: `${role}@example.test`,
    role,
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  const router = createAppRouter(createMemoryHistory())
  await router.push('/')
  await router.isReady()
  const wrapper = mount(App, { global: { plugins: [pinia, router] } })
  await flushPromises()
  // Opens the mobile drawer too, so every count below covers both layouts.
  await wrapper.find('[aria-label="Toggle menu"]').trigger('click')
  return wrapper
}

describe('App nav', () => {
  beforeEach(() => {
    vi.mocked(getTicketStats).mockReset()
  })

  it('renders the Workload nav link for an admin', async () => {
    const wrapper = await mountApp('admin')
    expect(wrapper.find('[data-testid="nav-workload"]').exists()).toBe(true)
  })

  it('does not render the Workload nav link for an agent', async () => {
    const wrapper = await mountApp('agent')
    expect(wrapper.find('[data-testid="nav-workload"]').exists()).toBe(false)
  })

  it('shows New ticket and hides admin links for an end user', async () => {
    const wrapper = await mountApp('user')
    expect(wrapper.findAll('[data-testid="nav-new-ticket"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-testid="nav-categories"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-users"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-workload"]')).toHaveLength(0)
    expect(
      wrapper.findAll('[data-testid="nav-assignment-requests"]'),
    ).toHaveLength(0)
  })

  it('hides New ticket and admin links for an agent', async () => {
    const wrapper = await mountApp('agent')
    expect(wrapper.findAll('[data-testid="nav-new-ticket"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-categories"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-users"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-workload"]')).toHaveLength(0)
    expect(
      wrapper.findAll('[data-testid="nav-assignment-requests"]'),
    ).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-tickets"]')).toHaveLength(2)
  })

  it('hides New ticket and shows every admin link for an admin', async () => {
    const wrapper = await mountApp('admin')
    expect(wrapper.findAll('[data-testid="nav-new-ticket"]')).toHaveLength(0)
    expect(wrapper.findAll('[data-testid="nav-categories"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-testid="nav-users"]')).toHaveLength(2)
    expect(wrapper.findAll('[data-testid="nav-workload"]')).toHaveLength(2)
    expect(
      wrapper.findAll('[data-testid="nav-assignment-requests"]'),
    ).toHaveLength(2)
  })

  it('labels the role chip Requester for an end user', async () => {
    const wrapper = await mountApp('user')
    expect(wrapper.text()).toContain('Requester')
  })
})
