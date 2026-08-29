<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import UserDeleteDialog from '../components/UserDeleteDialog.vue'
import UserFormDialog from '../components/UserFormDialog.vue'
import UserPasswordDialog from '../components/UserPasswordDialog.vue'
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
  <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Header -->
    <div
      class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
    >
      <div>
        <h1
          class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
        >
          Users & Agents
        </h1>
        <p class="mt-1 text-sm text-slate-500">
          Manage system users, support agents, and access permissions.
        </p>
      </div>

      <button
        data-testid="users-new"
        @click="createUser"
        class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 transition-all hover:bg-indigo-700 active:scale-95 self-start sm:self-auto"
      >
        <svg
          class="h-4 w-4"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2.5"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M12 4v16m8-8H4"
          />
        </svg>
        <span>New user</span>
      </button>
    </div>

    <!-- Search & Filter Ribbon -->
    <div
      class="flex flex-col sm:flex-row items-center gap-3 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm"
    >
      <div class="relative flex flex-1 w-full items-center">
        <div
          class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400"
        >
          <svg
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
            />
          </svg>
        </div>
        <input
          v-model="store.search"
          data-testid="users-search"
          type="search"
          placeholder="Search users by name or email..."
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 py-2.5 pl-10 pr-4 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
      </div>

      <div class="flex items-center gap-2 w-full sm:w-auto">
        <select
          v-model="store.status"
          data-testid="users-status"
          class="w-full sm:w-auto rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
        >
          <option value="">All statuses</option>
          <option value="active">Active only</option>
          <option value="inactive">Inactive only</option>
        </select>
      </div>
    </div>

    <!-- Loading State -->
    <div
      v-if="store.loading"
      data-testid="users-loading"
      class="flex items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-12 shadow-sm"
    >
      <div class="flex items-center gap-3 text-sm font-medium text-slate-500">
        <svg
          class="h-5 w-5 animate-spin text-indigo-600"
          fill="none"
          viewBox="0 0 24 24"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8v8H4z"
          />
        </svg>
        <span>Loading users...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-if="store.error"
      data-testid="users-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm"
    >
      {{ store.error }}
    </div>

    <!-- Users Table -->
    <div
      v-if="!store.loading && store.users.length"
      class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm"
    >
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" data-testid="users-table">
          <thead
            class="border-b border-slate-200 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-500"
          >
            <tr>
              <th scope="col" class="py-3.5 pl-6 pr-3">User</th>
              <th scope="col" class="px-3 py-3.5">Email</th>
              <th scope="col" class="px-3 py-3.5">Role</th>
              <th scope="col" class="px-3 py-3.5">Status</th>
              <th scope="col" class="py-3.5 pl-3 pr-6 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 bg-white">
            <tr
              v-for="user in store.users"
              :key="user.id"
              data-testid="users-row"
              class="transition-colors hover:bg-slate-50/70"
            >
              <td
                class="whitespace-nowrap py-4 pl-6 pr-3 font-semibold text-slate-900"
              >
                <div class="flex items-center gap-3">
                  <div
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-600"
                  >
                    {{ user.name.charAt(0).toUpperCase() }}
                  </div>
                  <span>{{ user.name }}</span>
                </div>
              </td>
              <td class="whitespace-nowrap px-3 py-4 text-xs text-slate-600">
                {{ user.email }}
              </td>
              <td class="whitespace-nowrap px-3 py-4 text-xs">
                <span
                  class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize"
                  :class="
                    user.role === 'admin'
                      ? 'bg-violet-50 text-violet-700'
                      : 'bg-blue-50 text-blue-700'
                  "
                >
                  {{ user.role }}
                </span>
              </td>
              <td class="whitespace-nowrap px-3 py-4 text-xs">
                <span
                  class="inline-flex items-center gap-1.5 font-medium"
                  :class="
                    user.is_active ? 'text-emerald-700' : 'text-slate-400'
                  "
                >
                  <span
                    class="h-2 w-2 rounded-full"
                    :class="user.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                  />
                  {{ user.is_active ? 'Active' : 'Inactive' }}
                </span>
              </td>
              <td class="whitespace-nowrap py-4 pl-3 pr-6 text-right">
                <button
                  @click="editUser(user)"
                  class="rounded-lg px-3 py-1 text-xs font-semibold text-indigo-600 hover:bg-indigo-50 transition-colors"
                >
                  Edit
                </button>
                <!-- Absent, not disabled, on your own row: both actions are
                     irreversible, and a greyed-out Delete on yourself only
                     invites someone to look for the way to enable it. -->
                <button
                  v-if="!isSelf(user)"
                  data-testid="users-reset-password"
                  @click="resetPassword(user)"
                  class="rounded-lg px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors"
                >
                  Reset password
                </button>
                <button
                  v-if="!isSelf(user)"
                  data-testid="users-delete"
                  @click="void deleteUser(user)"
                  class="rounded-lg px-3 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50 transition-colors"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Footer -->
      <div
        class="flex flex-col items-center justify-between gap-4 border-t border-slate-100 px-6 py-4 sm:flex-row"
      >
        <p
          data-testid="users-count"
          v-if="store.meta"
          class="text-xs text-slate-500"
        >
          Showing
          <span class="font-semibold text-slate-700">{{
            store.meta.from ?? 0
          }}</span>
          to
          <span class="font-semibold text-slate-700">{{
            store.meta.to ?? 0
          }}</span>
          of
          <span class="font-semibold text-slate-700">{{
            store.meta.total
          }}</span>
          users
        </p>
        <div v-else />

        <div class="flex items-center gap-2">
          <button
            data-testid="users-prev"
            :disabled="!store.meta || store.meta.current_page <= 1"
            @click="void store.goToPage(store.page - 1)"
            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition-all hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <svg
              class="h-3.5 w-3.5"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M15 19l-7-7 7-7"
              />
            </svg>
            <span>Previous</span>
          </button>
          <button
            data-testid="users-next"
            :disabled="
              !store.meta || store.meta.current_page >= store.meta.last_page
            "
            @click="void store.goToPage(store.page + 1)"
            class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition-all hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
          >
            <span>Next</span>
            <svg
              class="h-3.5 w-3.5"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 5l7 7-7 7"
              />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <p
      v-if="notice"
      data-testid="users-notice"
      class="rounded-xl bg-emerald-50 p-3 text-xs font-medium text-emerald-800"
    >
      {{ notice }}
    </p>

    <!-- Empty State -->
    <div
      v-if="!store.loading && !store.users.length"
      data-testid="users-empty"
      class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center shadow-xs"
    >
      <div
        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-50 text-slate-400"
      >
        <svg
          class="h-6 w-6"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
          />
        </svg>
      </div>
      <p class="mt-4 text-base font-bold text-slate-900">No users found.</p>
      <p class="mt-1 text-xs text-slate-500">
        Try adjusting your search or create a new user.
      </p>
    </div>

    <!-- User Form Dialog -->
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
