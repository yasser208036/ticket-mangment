<script setup lang="ts">
import { reactive, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { AdminUser } from '../api/users'
import { useUsersStore } from '../stores/users'
const props = defineProps<{ user: AdminUser }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const store = useUsersStore()
const form = reactive({ password: '', current_password: '' })
const errors = ref<Record<string, string[]>>({})
const message = ref('')
async function submit(): Promise<void> {
  errors.value = {}
  message.value = ''
  try {
    await store.resetPassword(props.user.id, {
      password: form.password,
      current_password: form.current_password,
    })
    emit('saved')
  } catch (error) {
    errors.value = validationErrors(error)
    message.value = Object.keys(errors.value).length ? '' : errorMessage(error)
  }
}
</script>
<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in fade-in duration-150"
  >
    <div
      class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl space-y-5 animate-in zoom-in-95 duration-150"
    >
      <div
        class="flex items-center justify-between border-b border-slate-100 pb-3"
      >
        <h2 class="text-lg font-bold text-slate-900">
          Reset password for {{ user.name }}
        </h2>
      </div>

      <form
        data-testid="user-password-form"
        @submit.prevent="submit"
        class="space-y-4"
      >
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >New password for {{ user.name }} *</label
          >
          <input
            v-model="form.password"
            data-testid="user-password-new"
            type="password"
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{ 'border-rose-300 ring-2 ring-rose-100': errors.password }"
          />
          <p
            v-if="errors.password"
            data-testid="user-password-error-password"
            class="text-xs font-medium text-rose-600"
          >
            {{ errors.password[0] }}
          </p>
        </div>

        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Your own password *</label
          >
          <input
            v-model="form.current_password"
            data-testid="user-password-current"
            type="password"
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{
              'border-rose-300 ring-2 ring-rose-100': errors.current_password,
            }"
          />
          <p
            v-if="errors.current_password"
            data-testid="user-password-error-current_password"
            class="text-xs font-medium text-rose-600"
          >
            {{ errors.current_password[0] }}
          </p>
          <p class="text-xs text-slate-400 italic">
            Confirming your own password keeps an unattended session from taking
            over other accounts.
          </p>
        </div>

        <p class="text-xs text-slate-500">
          {{ user.name }} will be signed out everywhere and must sign in with
          the new password. They are not emailed about this.
        </p>

        <p
          v-if="message"
          data-testid="user-password-error"
          class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
        >
          {{ message }}
        </p>

        <div
          class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
        >
          <button
            type="button"
            data-testid="user-password-cancel"
            @click="emit('close')"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
          >
            Cancel
          </button>
          <button
            data-testid="user-password-submit"
            type="submit"
            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors"
          >
            Set Password
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
