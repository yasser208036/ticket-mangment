import { defineStore } from 'pinia'
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import {
  createUser,
  deleteUser,
  listUsers,
  resetUserPassword,
  updateUser,
} from '../api/users'
import type {
  AdminUser,
  CreateUserPayload,
  ResetPasswordPayload,
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
  // Assignable agents, kept apart from `users` on purpose: `users` is the
  // admin table's paginated, searched, sorted state, and an assignee picker
  // must not be at the mercy of whatever filter that table was left on — nor
  // may it offer admins or deactivated accounts, which /assign rejects with a
  // 422.
  const agents = ref<AdminUser[]>([])
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
  // Throws rather than filling `error`, which belongs to the admin table: the
  // caller is a dialog with its own error line, and a picker that silently came
  // back empty reads as "there are no agents".
  async function loadAgents(): Promise<void> {
    // Cleared first: on a failed reload the caller shows an error, and leaving
    // the previous fetch on screen offers agents who may since have been
    // deactivated -- selectable, then a 422 on submit.
    agents.value = []
    const response = await listUsers({
      role: 'agent',
      status: 'active',
      per_page: 100,
    })
    agents.value = response.data
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
  async function remove(id: number, reassignTo?: number): Promise<void> {
    await deleteUser(id, reassignTo)
    // Deleting the only row of page 3 otherwise leaves an empty page 3 with
    // working pagination controls, which reads as data loss.
    if (users.value.length === 1 && page.value > 1) page.value -= 1
    await load()
  }
  async function resetPassword(
    id: number,
    payload: ResetPasswordPayload,
  ): Promise<void> {
    // No reload: nothing in the list changed.
    await resetUserPassword(id, payload)
  }
  return {
    users,
    agents,
    loadAgents,
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
    remove,
    resetPassword,
  }
})
