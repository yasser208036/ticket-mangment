<script setup lang="ts">
import { reactive, ref } from 'vue'
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useUsersStore } from '../stores/users'
import { validationErrors, errorMessage } from '../api/errors'
import type { UserRole } from '../api/auth'
import type { AdminUser, CreateUserPayload } from '../api/users'
import BaseDialog from './BaseDialog.vue'
const props = defineProps<{ user?: AdminUser }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const store = useUsersStore()
const auth = useAuthStore()
const editingSelf = computed(() => props.user?.id === auth.user?.id)
const form = reactive({
  name: props.user?.name ?? '',
  email: props.user?.email ?? '',
  password: '',
  role: props.user?.role ?? ('agent' as UserRole),
  is_active: props.user?.is_active ?? true,
})
const errors = ref<Record<string, string[]>>({})
const message = ref('')
async function submit(): Promise<void> {
  try {
    if (props.user)
      await store.update(props.user.id, {
        name: form.name,
        email: form.email,
        role: form.role,
        is_active: form.is_active,
      })
    else await store.create(form as CreateUserPayload)
    emit('saved')
  } catch (error) {
    errors.value = validationErrors(error)
    message.value = Object.keys(errors.value).length ? '' : errorMessage(error)
  }
}
</script>
<template>
  <BaseDialog max-width="lg">
    <div class="space-y-5">
      <div
        class="flex items-center justify-between border-b border-slate-100 pb-3"
      >
        <h2 class="text-lg font-bold text-slate-900">
          {{ user ? 'Edit user' : 'New user' }}
        </h2>
        <button
          type="button"
          data-testid="user-form-cancel"
          @click="emit('close')"
          class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
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
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>
      </div>

      <form data-testid="user-form" @submit.prevent="submit" class="space-y-4">
        <!-- Name -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Full Name *</label
          >
          <input
            v-model="form.name"
            data-testid="user-form-name"
            type="text"
            placeholder="e.g. Alex Morgan"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{ 'border-rose-300 ring-2 ring-rose-100': errors.name }"
          />
          <p v-if="errors.name" class="text-xs font-medium text-rose-600">
            {{ errors.name[0] }}
          </p>
        </div>

        <!-- Email -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Email Address *</label
          >
          <input
            v-model="form.email"
            data-testid="user-form-email"
            type="email"
            placeholder="e.g. alex@deskflow.app"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{ 'border-rose-300 ring-2 ring-rose-100': errors.email }"
          />
          <p v-if="errors.email" class="text-xs font-medium text-rose-600">
            {{ errors.email[0] }}
          </p>
        </div>

        <!-- Password (New User only) -->
        <div v-if="!user" class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Initial Password *</label
          >
          <input
            v-model="form.password"
            data-testid="user-form-password"
            type="password"
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{ 'border-rose-300 ring-2 ring-rose-100': errors.password }"
          />
          <p v-if="errors.password" class="text-xs font-medium text-rose-600">
            {{ errors.password[0] }}
          </p>
        </div>

        <!-- Role & Status Grid -->
        <div class="grid grid-cols-2 gap-4 pt-1">
          <!-- Role -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700">Role</label>
            <select
              v-model="form.role"
              :disabled="editingSelf"
              data-testid="user-form-role"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white disabled:opacity-50"
            >
              <option value="user">Requester</option>
              <option value="agent">Support Agent</option>
              <option value="admin">Administrator</option>
            </select>
          </div>

          <!-- Active Checkbox -->
          <div class="flex items-center pt-6">
            <label
              class="inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-700"
            >
              <input
                v-model="form.is_active"
                type="checkbox"
                :disabled="editingSelf"
                data-testid="user-form-active"
                class="h-4 w-4 rounded-md border-slate-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50"
              />
              <span>Active Account</span>
            </label>
          </div>
        </div>

        <p v-if="editingSelf" class="text-xs text-slate-400 italic">
          You cannot modify your own role or active status.
        </p>

        <!-- Error message -->
        <p
          v-if="message"
          data-testid="user-form-error"
          class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
        >
          {{ message }}
        </p>

        <!-- Actions -->
        <div
          class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
        >
          <button
            type="button"
            data-testid="user-form-cancel"
            @click="emit('close')"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
          >
            Cancel
          </button>
          <button
            data-testid="user-form-submit"
            type="submit"
            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors"
          >
            Save User
          </button>
        </div>
      </form>
    </div>
  </BaseDialog>
</template>
