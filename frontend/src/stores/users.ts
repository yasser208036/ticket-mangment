import { defineStore } from 'pinia'
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import { createUser, listUsers, updateUser } from '../api/users'
import type {
  AdminUser,
  CreateUserPayload,
  UpdateUserPayload,
} from '../api/users'
import type { Paginated } from '../api/pagination'
export const useUsersStore = defineStore('users', () => {
  const users = ref<AdminUser[]>([])
  const meta = ref<Paginated<AdminUser>['meta'] | null>(null)
  const search = ref('')
  const status = ref<'active' | 'inactive' | ''>('')
  const page = ref(1)
  const perPage = ref(15)
  const loading = ref(false)
  const error = ref<string | null>(null)
  let latestRequest = 0
  async function load(): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const response = await listUsers({
        search: search.value || undefined,
        status: status.value || undefined,
        page: page.value,
        per_page: perPage.value,
      })
      if (request !== latestRequest) return
      users.value = response.data
      meta.value = response.meta
    } catch (caughtError) {
      if (request !== latestRequest) return
      error.value = errorMessage(caughtError)
      users.value = []
      meta.value = null
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }
  async function applyFilters(next: {
    search?: string
    status?: 'active' | 'inactive' | ''
  }): Promise<void> {
    if (next.search !== undefined) search.value = next.search
    if (next.status !== undefined) status.value = next.status
    page.value = 1
    await load()
  }
  async function goToPage(target: number): Promise<void> {
    page.value = target
    await load()
  }
  async function create(payload: CreateUserPayload): Promise<void> {
    await createUser(payload)
    await load()
  }
  async function update(id: number, payload: UpdateUserPayload): Promise<void> {
    await updateUser(id, payload)
    await load()
  }
  return {
    users,
    meta,
    search,
    status,
    page,
    perPage,
    loading,
    error,
    load,
    applyFilters,
    goToPage,
    create,
    update,
  }
})
