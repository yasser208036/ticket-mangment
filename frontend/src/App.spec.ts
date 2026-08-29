import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getTicketStats } from './api/stats'
import { createAppRouter } from './router'
import { useAuthStore } from './stores/auth'
import App from './App.vue'

vi.mock('./api/stats', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getTicketStats: vi.fn(),
}))

async function mountApp(role: 'admin' | 'agent') {
  vi.mocked(getTicketStats).mockResolvedValue({
    scope: role === 'admin' ? 'all' : 'own',
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
    name: role === 'admin' ? 'Admin' : 'Agent',
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
})
