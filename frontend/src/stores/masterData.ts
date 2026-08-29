import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { listCategories, type Category } from '../api/categories'
import { listPriorities, type Priority } from '../api/priorities'
import { listStatuses, type Status } from '../api/statuses'
const fallback = { name: 'Unknown', color: '#6B7280', is_active: false }
export const useMasterDataStore = defineStore('masterData', () => {
  const categories = ref<Category[]>([])
  const priorities = ref<Priority[]>([])
  const statuses = ref<Status[]>([])
  const error = ref<string | null>(null)
  let loading: Promise<void> | null = null
  async function refresh(): Promise<void> {
    const results = await Promise.allSettled([
      listCategories(),
      listPriorities(),
      listStatuses(),
    ])
    const [categoryResult, priorityResult, statusResult] = results
    if (categoryResult.status === 'fulfilled')
      categories.value = categoryResult.value
    if (priorityResult.status === 'fulfilled')
      priorities.value = priorityResult.value
    if (statusResult.status === 'fulfilled') statuses.value = statusResult.value
    error.value = results.some((r) => r.status === 'rejected')
      ? 'Unable to load master data.'
      : null
  }
  async function ensureLoaded(): Promise<void> {
    if (
      loading ||
      (categories.value.length &&
        priorities.value.length &&
        statuses.value.length)
    )
      return loading ?? Promise.resolve()
    loading = refresh().finally(() => {
      loading = null
    })
    await loading
  }
  function clear(): void {
    categories.value = []
    priorities.value = []
    statuses.value = []
    error.value = null
  }
  function categoryBadge(id: number) {
    const category = categories.value.find((entry) => entry.id === id)
    return category
      ? {
          name: category.name,
          color: category.color,
          is_active: category.is_active,
        }
      : fallback
  }
  function priorityById(id: number) {
    return priorities.value.find((entry) => entry.id === id)
  }
  function statusById(id: number) {
    return statuses.value.find((entry) => entry.id === id)
  }
  const activeCategories = computed(() =>
    categories.value.filter((entry) => entry.is_active),
  )
  const defaultPriority = computed(
    () => priorities.value.find((entry) => entry.is_default) ?? null,
  )
  return {
    categories,
    priorities,
    statuses,
    error,
    refresh,
    ensureLoaded,
    clear,
    categoryBadge,
    priorityById,
    statusById,
    activeCategories,
    defaultPriority,
  }
})
