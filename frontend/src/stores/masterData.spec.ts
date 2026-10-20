import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { listCategories } from '../api/categories'
import { listPriorities } from '../api/priorities'
import { listStatuses } from '../api/statuses'
import { useMasterDataStore } from './masterData'

vi.mock('../api/categories', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listCategories: vi.fn(),
}))
vi.mock('../api/priorities', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listPriorities: vi.fn(),
}))
vi.mock('../api/statuses', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listStatuses: vi.fn(),
}))

describe('masterData isLoading', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(listCategories).mockReset()
    vi.mocked(listPriorities).mockReset()
    vi.mocked(listStatuses).mockReset()
  })

  it('is true while refresh() is in flight and false after it resolves', async () => {
    let resolveCategories: (value: never[]) => void = () => {}
    vi.mocked(listCategories).mockReturnValue(
      new Promise((resolve) => {
        resolveCategories = resolve
      }),
    )
    vi.mocked(listPriorities).mockResolvedValue([])
    vi.mocked(listStatuses).mockResolvedValue([])
    const store = useMasterDataStore()

    const pending = store.refresh()
    expect(store.isLoading).toBe(true)

    resolveCategories([])
    await pending
    expect(store.isLoading).toBe(false)
  })

  it('is reset to false, with the error set, when a list request rejects', async () => {
    vi.mocked(listCategories).mockRejectedValue(new Error('timeout'))
    vi.mocked(listPriorities).mockResolvedValue([])
    vi.mocked(listStatuses).mockResolvedValue([])
    const store = useMasterDataStore()

    await store.refresh()

    expect(store.isLoading).toBe(false)
    expect(store.error).toBe('Unable to load master data.')
  })
})
