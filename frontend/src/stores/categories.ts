import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { errorMessage } from '../api/errors'
import {
  createCategory,
  deleteCategory,
  listCategories,
  updateCategory,
} from '../api/categories'
import type {
  Category,
  CreateCategoryPayload,
  UpdateCategoryPayload,
} from '../api/categories'
import { useMasterDataStore } from './masterData'
export const useCategoriesStore = defineStore('categories', () => {
  const categories = ref<Category[]>([])
  const status = ref<'active' | 'inactive' | ''>('')
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0
  const activeCategories = computed(() =>
    categories.value.filter((c) => c.is_active),
  )
  const byId = computed(() => new Map(categories.value.map((c) => [c.id, c])))
  async function load() {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const rows = await listCategories(
        status.value ? { status: status.value } : {},
      )
      if (request === latestRequest) categories.value = rows
    } catch (e) {
      if (request === latestRequest) {
        error.value = errorMessage(e)
        categories.value = []
      }
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }
  async function applyFilters(next: { status?: 'active' | 'inactive' | '' }) {
    if (next.status !== undefined) status.value = next.status
    await load()
  }
  async function create(payload: CreateCategoryPayload) {
    await createCategory(payload)
    await load()
    await useMasterDataStore().refresh()
  }
  async function update(id: number, payload: UpdateCategoryPayload) {
    await updateCategory(id, payload)
    await load()
    await useMasterDataStore().refresh()
  }
  async function remove(id: number, reassignTo?: number) {
    await deleteCategory(id, reassignTo)
    await load()
    await useMasterDataStore().refresh()
  }
  async function setActive(category: Category, isActive: boolean) {
    await update(category.id, { is_active: isActive })
  }
  async function saveSortOrder(category: Category, sortOrder: number) {
    await update(category.id, { sort_order: sortOrder })
  }
  return {
    categories,
    status,
    loading,
    error,
    activeCategories,
    byId,
    load,
    applyFilters,
    create,
    update,
    remove,
    setActive,
    saveSortOrder,
  }
})
