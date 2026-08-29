import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getWorkload } from '../api/workload'
import type { Workload } from '../api/workload'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import AdminWorkloadView from './AdminWorkloadView.vue'

vi.mock('../api/workload', async (loadOriginal) => ({
  ...(await loadOriginal()),
  getWorkload: vi.fn(),
}))

function adminUser(id: number, name = 'Admin') {
  return {
    id,
    name,
    email: 'admin@example.test',
    role: 'admin' as const,
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
}

function baseWorkload(overrides: Partial<Workload> = {}): Workload {
  return {
    average_open: 4.1,
    band: 2,
    priorities: [
      { id: 1, name: 'Low', slug: 'low', color: '#10B981', level: 1 },
      { id: 2, name: 'Medium', slug: 'medium', color: '#F59E0B', level: 2 },
    ],
    open_status_ids: [10, 20, 30],
    agents: [],
    ...overrides,
  }
}

async function mountView(
  response: Workload | null,
  options: { reject?: boolean } = {},
) {
  if (options.reject)
    vi.mocked(getWorkload).mockRejectedValue(new Error('boom'))
  else vi.mocked(getWorkload).mockResolvedValue(response as Workload)
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 1,
    name: 'Admin',
    email: 'admin@example.test',
    role: 'admin',
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  const router = createAppRouter(createMemoryHistory())
  await router.push('/admin/workload')
  await router.isReady()
  const wrapper = mount(AdminWorkloadView, {
    global: { plugins: [pinia, router] },
  })
  await flushPromises()
  return wrapper
}

describe('AdminWorkloadView', () => {
  beforeEach(() => {
    vi.mocked(getWorkload).mockReset()
  })

  it('shows loading and no table while pending', async () => {
    vi.mocked(getWorkload).mockReturnValue(new Promise(() => {}))
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().user = adminUser(1)
    const router = createAppRouter(createMemoryHistory())
    await router.push('/admin/workload')
    await router.isReady()
    const wrapper = mount(AdminWorkloadView, {
      global: { plugins: [pinia, router] },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="workload-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workload"]').exists()).toBe(false)
  })

  it('shows an error and no table on failure', async () => {
    const wrapper = await mountView(null, { reject: true })

    expect(wrapper.find('[data-testid="workload-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="workload"]').exists()).toBe(false)
  })

  it('renders one row per agent with the right total and priority columns in order', async () => {
    const workload = baseWorkload({
      agents: [
        {
          user: adminUser(7, 'Agent Seven'),
          open_total: 5,
          in_average: true,
          load: 'normal',
          needs_reassignment: false,
          by_priority: [
            { priority_id: 1, count: 2 },
            { priority_id: 2, count: 3 },
          ],
        },
      ],
    })
    const wrapper = await mountView(workload)

    const row = wrapper.get('[data-testid="workload-row"]')
    expect(row.get('[data-testid="workload-row-total"]').text()).toBe('5')
    const headers = wrapper.findAll('thead th').map((th) => th.text())
    expect(headers).toEqual(['Agent', 'Total Open', 'Low', 'Medium'])
  })

  it('AC2: a high row carries load-high, a low row carries load-low, neither for null', async () => {
    const workload = baseWorkload({
      agents: [
        {
          user: adminUser(1, 'High Agent'),
          open_total: 10,
          in_average: true,
          load: 'high',
          needs_reassignment: false,
          by_priority: [],
        },
        {
          user: adminUser(2, 'Low Agent'),
          open_total: 0,
          in_average: true,
          load: 'low',
          needs_reassignment: false,
          by_priority: [],
        },
        {
          user: adminUser(3, 'Inactive Agent'),
          open_total: 1,
          in_average: false,
          load: null,
          needs_reassignment: true,
          by_priority: [],
        },
      ],
    })
    const wrapper = await mountView(workload)
    const rows = wrapper.findAll('[data-testid="workload-row"]')

    expect(rows[0]?.classes()).toContain('load-high')
    expect(rows[0]?.classes()).not.toContain('load-low')
    expect(rows[1]?.classes()).toContain('load-low')
    expect(rows[1]?.classes()).not.toContain('load-high')
    expect(rows[2]?.classes()).not.toContain('load-high')
    expect(rows[2]?.classes()).not.toContain('load-low')
  })

  it('AC3: a row link resolves to /tickets?assignee=<id>&status=<open ids>', async () => {
    const workload = baseWorkload({
      agents: [
        {
          user: adminUser(42, 'Linked Agent'),
          open_total: 3,
          in_average: true,
          load: 'normal',
          needs_reassignment: false,
          by_priority: [],
        },
      ],
    })
    const wrapper = await mountView(workload)

    const href = wrapper
      .get('[data-testid="workload-row-total"]')
      .get('a')
      .attributes('href')
    expect(href).toBe('/tickets?assignee=42&status=10,20,30')
    expect(href).not.toContain('assigned_to')
  })

  it('AC4: workload-needs-reassignment renders on a flagged row and is absent on a normal one', async () => {
    const workload = baseWorkload({
      agents: [
        {
          user: adminUser(1, 'Flagged Agent'),
          open_total: 2,
          in_average: false,
          load: null,
          needs_reassignment: true,
          by_priority: [],
        },
        {
          user: adminUser(2, 'Normal Agent'),
          open_total: 2,
          in_average: true,
          load: 'normal',
          needs_reassignment: false,
          by_priority: [],
        },
      ],
    })
    const wrapper = await mountView(workload)
    const rows = wrapper.findAll('[data-testid="workload-row"]')

    expect(
      rows[0]?.find('[data-testid="workload-needs-reassignment"]').exists(),
    ).toBe(true)
    expect(
      rows[1]?.find('[data-testid="workload-needs-reassignment"]').exists(),
    ).toBe(false)
  })

  it('renders the average and band, and "—" when average_open is null', async () => {
    const withAverage = await mountView(
      baseWorkload({ average_open: 4.1, band: 2 }),
    )
    expect(withAverage.get('[data-testid="workload-average"]').text()).toBe(
      'Average: 4.1 ± 2',
    )

    const withoutAverage = await mountView(
      baseWorkload({ average_open: null, band: null }),
    )
    expect(withoutAverage.get('[data-testid="workload-average"]').text()).toBe(
      'Average: — ± —',
    )
  })

  it('shows the empty state and no table when agents is empty', async () => {
    const wrapper = await mountView(baseWorkload({ agents: [] }))

    expect(wrapper.find('[data-testid="workload-empty"]').exists()).toBe(true)
    expect(wrapper.find('table').exists()).toBe(false)
  })
})
