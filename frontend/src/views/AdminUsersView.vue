<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import UserDeleteDialog from '../components/UserDeleteDialog.vue'
import UserFormDialog from '../components/UserFormDialog.vue'
import UserPasswordDialog from '../components/UserPasswordDialog.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiIcon from '../components/ui/UiIcon.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import UiPagination from '../components/ui/UiPagination.vue'
import { errorMessage } from '../api/errors'
import { deleteBlockedBy } from '../api/users'
import { useAuthStore } from '../stores/auth'
import { useUsersStore } from '../stores/users'
import type { AdminUser, UserDeleteBlocked } from '../api/users'
const store = useUsersStore()
const auth = useAuthStore()
const editing = ref<AdminUser | undefined>()
const open = ref(false)
const deleting = ref<AdminUser | undefined>()
const blocked = ref<UserDeleteBlocked | null>(null)
const resetting = ref<AdminUser | undefined>()
const notice = ref('')
let timer: ReturnType<typeof setTimeout> | undefined
watch([() => store.search, () => store.status], () => {
  clearTimeout(timer)
  timer = setTimeout(
    () =>
      void store.applyFilters({ search: store.search, status: store.status }),
    300,
  )
})
onMounted(() => void store.load())
function createUser(): void {
  editing.value = undefined
  open.value = true
}
function editUser(user: AdminUser): void {
  editing.value = user
  open.value = true
}
function isSelf(user: AdminUser): boolean {
  return user.id === auth.user?.id
}
/**
 * Two phases, as the categories screen does it: try the delete, and only open
 * the dialog with a destination picker if the server says one is needed. An
 * unblocked delete never shows a choice that does not exist.
 */
async function deleteUser(user: AdminUser): Promise<void> {
  notice.value = ''
  deleting.value = user
  blocked.value = null
  try {
    await store.remove(user.id)
    deleting.value = undefined
    notice.value = `${user.name} was deleted.`
  } catch (reason) {
    const details = deleteBlockedBy(reason)
    if (details) {
      blocked.value = details
      return
    }
    deleting.value = undefined
    store.error = errorMessage(reason)
  }
}
function resetPassword(user: AdminUser): void {
  notice.value = ''
  resetting.value = user
}
function passwordWasReset(): void {
  notice.value = `${resetting.value?.name} was signed out everywhere and needs the new password.`
  resetting.value = undefined
}
</script>

<template>
  <main class="mx-auto max-w-7xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader
      title="Users and agents"
      description="Manage accounts, support agents and access."
    >
      <template #actions>
        <UiButton
          variant="primary"
          icon="plus"
          data-testid="users-new"
          @click="createUser"
        >
          New user
        </UiButton>
      </template>
    </UiPageHeader>

    <div class="ui-card flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
      <div class="relative flex flex-1 items-center">
        <UiIcon
          name="search"
          class="pointer-events-none absolute left-3 text-ink-400"
        />
        <input
          v-model="store.search"
          data-testid="users-search"
          type="search"
          placeholder="Search users by name or email…"
          aria-label="Search users"
          class="ui-input pl-9"
        />
      </div>
      <select
        v-model="store.status"
        data-testid="users-status"
        aria-label="Filter by status"
        class="ui-select sm:w-auto"
      >
        <option value="">All statuses</option>
        <option value="active">Active only</option>
        <option value="inactive">Inactive only</option>
      </select>
    </div>

    <UiAlert v-if="notice" tone="success" data-testid="users-notice">
      {{ notice }}
    </UiAlert>

    <UiAlert v-if="store.error" data-testid="users-error">
      {{ store.error }}
    </UiAlert>

    <UiLoadingPanel
      v-if="store.loading"
      label="Loading users"
      testid="users-loading"
    />

    <div v-else-if="store.users.length" class="ui-card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="ui-table" data-testid="users-table">
          <thead class="ui-thead">
            <tr>
              <th scope="col" class="ui-th">User</th>
              <th scope="col" class="ui-th">Email</th>
              <th scope="col" class="ui-th">Role</th>
              <th scope="col" class="ui-th">Status</th>
              <th scope="col" class="ui-th text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="ui-tbody">
            <tr
              v-for="user in store.users"
              :key="user.id"
              data-testid="users-row"
              class="ui-tr"
            >
              <td class="ui-td whitespace-nowrap font-medium text-ink-900">
                <span class="flex items-center gap-2.5">
                  <span
                    class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600"
                    aria-hidden="true"
                  >
                    {{ user.name.charAt(0).toUpperCase() }}
                  </span>
                  {{ user.name }}
                </span>
              </td>
              <td class="ui-td whitespace-nowrap text-xs text-ink-600">
                {{ user.email }}
              </td>
              <td class="ui-td whitespace-nowrap">
                <span
                  class="ui-chip capitalize"
                  :class="user.role === 'admin' && 'ui-chip-brand'"
                >
                  {{ user.role }}
                </span>
              </td>
              <td class="ui-td whitespace-nowrap text-xs">
                <span
                  class="flex items-center gap-1.5"
                  :class="user.is_active ? 'text-ink-700' : 'text-ink-400'"
                >
                  <span
                    class="h-1.5 w-1.5 rounded-full"
                    :class="user.is_active ? 'bg-emerald-500' : 'bg-ink-300'"
                    aria-hidden="true"
                  />
                  {{ user.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="ui-td whitespace-nowrap text-right">
                <span class="inline-flex items-center gap-1">
                  <UiButton size="sm" variant="ghost" @click="editUser(user)">
                    Edit
                  </UiButton>
                  <!-- Absent, not disabled, on your own row: both actions are
                       irreversible, and a greyed-out Delete on yourself only
                       invites someone to look for the way to enable it. -->
                  <UiButton
                    v-if="!isSelf(user)"
                    size="sm"
                    variant="ghost"
                    data-testid="users-reset-password"
                    @click="resetPassword(user)"
                  >
                    Reset password
                  </UiButton>
                  <UiButton
                    v-if="!isSelf(user)"
                    size="sm"
                    variant="danger-quiet"
                    data-testid="users-delete"
                    @click="void deleteUser(user)"
                  >
                    Delete
                  </UiButton>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <UiPagination
        :meta="store.meta"
        noun="users"
        testid-prefix="users"
        @go="void store.goToPage($event)"
      />
    </div>

    <UiEmptyState
      v-else
      icon="users"
      title="No users found."
      description="Try adjusting your search, or create a new user."
      testid="users-empty"
    />

    <UserFormDialog
      v-if="open"
      :user="editing"
      @saved="open = false"
      @close="open = false"
    />

    <UserDeleteDialog
      v-if="deleting && blocked"
      data-testid="users-delete-dialog"
      :user="deleting"
      :blocked="blocked"
      @deleted="deleting = undefined"
      @close="deleting = undefined"
    />

    <UserPasswordDialog
      v-if="resetting"
      data-testid="users-password-dialog"
      :user="resetting"
      @saved="passwordWasReset"
      @close="resetting = undefined"
    />
  </main>
</template>
