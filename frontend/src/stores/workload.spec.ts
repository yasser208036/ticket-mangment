import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getWorkload } from '../api/workload'
import type { Workload } from '../api/workload'
import { useWorkloadStore } from './workload'
import { useAuthStore } from './auth'

vi.mock('../api/workload', () => ({ getWorkload: vi.fn() }))

const workload: Workload = {
  average_open: 4.1,
  band: 2,
  priorities: [],
  open_status_ids: [1, 2, 3, 4, 5],
  agents: [],
}

describe('workload store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(getWorkload).mockReset()
  })

  it('fills data on a successful load', async () => {
    vi.mocked(getWorkload).mockResolvedValue(workload)
    const store = useWorkloadStore()
    await store.load()
    expect(store.data).toEqual(workload)
    expect(store.error).toBeNull()
    expect(store.loading).toBe(false)
  })

  it('sets error, nulls data and clears loading on a rejection', async () => {
    vi.mocked(getWorkload).mockRejectedValue(new Error('Network Error'))
    const store = useWorkloadStore()
    await store.load()
    expect(store.error).toBe('The API is unreachable.')
    expect(store.data).toBeNull()
    expect(store.loading).toBe(false)
  })

  it('clear() nulls data and error', async () => {
    vi.mocked(getWorkload).mockResolvedValue(workload)
    const store = useWorkloadStore()
    await store.load()
    store.clear()
    expect(store.data).toBeNull()
    expect(store.error).toBeNull()
  })

  it('auth store clear() clears the workload store', async () => {
    vi.mocked(getWorkload).mockResolvedValue(workload)
    const store = useWorkloadStore()
    await store.load()
    useAuthStore().clear()
    expect(store.data).toBeNull()
    expect(store.error).toBeNull()
  })
})
