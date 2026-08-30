<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import type { AdminUser, UserDeleteBlocked } from '../api/users'
import { useUsersStore } from '../stores/users'
import BaseDialog from './BaseDialog.vue'
const props = defineProps<{
  user: AdminUser
  /** Null when nothing blocks the delete — a plain confirmation. */
  blocked: UserDeleteBlocked | null
}>()
const emit = defineEmits<{ deleted: []; close: [] }>()
const store = useUsersStore()
const target = ref<number>()
const error = ref('')
async function confirmDelete(): Promise<void> {
  if (props.blocked && target.value === undefined) return
  try {
    await store.remove(props.user.id, target.value)
    emit('deleted')
  } catch (reason) {
    error.value = errorMessage(reason)
  }
}
</script>
<template>
  <BaseDialog testid="user-delete">
    <div class="space-y-4">
      <div class="flex items-start gap-3">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-600"
        >
          <svg
            class="h-5 w-5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
            />
          </svg>
        </div>
        <div class="space-y-1">
          <h3 class="text-base font-bold text-slate-900">
            Delete {{ user.name }}?
          </h3>
          <p
            v-if="blocked"
            data-testid="user-delete-count"
            class="text-xs font-semibold text-amber-800"
          >
            {{ blocked.ticket_count }} tickets
          </p>
        </div>
      </div>

      <p class="text-sm text-slate-600">
        <template v-if="blocked">
          Those tickets will move to the person you choose. Historical activity
          keeps its record but loses this person's name.
        </template>
        <template v-else>
          This removes the account and signs them out everywhere. Deactivating
          instead keeps their history attributed to them.
        </template>
      </p>

      <p
        v-if="error"
        data-testid="user-delete-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ error }}
      </p>

      <div
        v-if="blocked && blocked.reassign_to_options.length"
        class="space-y-1.5 pt-2"
      >
        <label class="text-xs font-semibold text-slate-700"
          >Move their tickets to:</label
        >
        <select
          v-model="target"
          data-testid="user-delete-target"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        >
          <option :value="undefined" disabled>Select a user</option>
          <option
            v-for="option in blocked.reassign_to_options"
            :key="option.id"
            :value="option.id"
          >
            {{ option.name }}
          </option>
        </select>
      </div>

      <p
        v-else-if="blocked"
        class="rounded-lg bg-amber-50 p-2.5 text-xs font-medium text-amber-800"
      >
        {{ blocked.message }}
      </p>

      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          data-testid="user-delete-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          v-if="!blocked || blocked.reassign_to_options.length"
          data-testid="user-delete-confirm"
          type="button"
          @click="confirmDelete"
          :disabled="blocked !== null && target === undefined"
          class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors disabled:opacity-50"
        >
          {{ blocked ? 'Reassign & Delete' : 'Delete User' }}
        </button>
      </div>
    </div>
  </BaseDialog>
</template>
