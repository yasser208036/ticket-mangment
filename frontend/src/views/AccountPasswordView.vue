<script setup lang="ts">
import { ref } from 'vue'
import { changePassword } from '../api/auth'
import { errorMessage, validationErrors } from '../api/errors'

const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const changed = ref(false)
const submitting = ref(false)

async function submit(): Promise<void> {
  submitting.value = true
  fieldErrors.value = {}
  error.value = null
  changed.value = false
  try {
    await changePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    currentPassword.value = ''
    password.value = ''
    passwordConfirmation.value = ''
    changed.value = true
  } catch (caughtError) {
    fieldErrors.value = validationErrors(caughtError)
    if (!Object.keys(fieldErrors.value).length)
      error.value = errorMessage(caughtError)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <div>
      <h1
        class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
      >
        Change Password
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        Update your account password. You will be signed out from your other
        devices.
      </p>
    </div>

    <!-- Success Message Banner -->
    <div
      v-if="changed"
      data-testid="password-changed"
      class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-4 text-xs font-semibold text-emerald-800 shadow-xs"
    >
      <svg
        class="h-5 w-5 shrink-0 text-emerald-600"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      </svg>
      <span
        >Password changed. You have been signed out on your other devices.</span
      >
    </div>

    <!-- Error Banner -->
    <div
      v-if="error"
      data-testid="password-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-xs font-medium text-rose-700 shadow-xs"
    >
      {{ error }}
    </div>

    <div class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm">
      <form @submit.prevent="submit" class="space-y-4">
        <!-- Current Password -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Current password</label
          >
          <input
            v-model="currentPassword"
            data-testid="password-current"
            type="password"
            autocomplete="current-password"
            required
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{
              'border-rose-300 ring-2 ring-rose-100':
                fieldErrors.current_password,
            }"
          />
          <p
            v-if="fieldErrors.current_password"
            data-testid="password-error-current_password"
            class="text-xs font-medium text-rose-600"
          >
            {{ fieldErrors.current_password[0] }}
          </p>
        </div>

        <!-- New Password -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >New password</label
          >
          <input
            v-model="password"
            data-testid="password-new"
            type="password"
            autocomplete="new-password"
            required
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{
              'border-rose-300 ring-2 ring-rose-100': fieldErrors.password,
            }"
          />
          <p
            v-if="fieldErrors.password"
            data-testid="password-error-password"
            class="text-xs font-medium text-rose-600"
          >
            {{ fieldErrors.password[0] }}
          </p>
        </div>

        <!-- Confirm Password -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Confirm password</label
          >
          <input
            v-model="passwordConfirmation"
            data-testid="password-confirmation"
            type="password"
            autocomplete="new-password"
            required
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
          />
        </div>

        <!-- Actions -->
        <div class="pt-3 border-t border-slate-100 flex justify-end">
          <button
            data-testid="password-submit"
            :disabled="submitting"
            type="submit"
            class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-60"
          >
            <svg
              v-if="submitting"
              class="h-4 w-4 animate-spin"
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
            <span>{{ submitting ? 'Saving...' : 'Change password' }}</span>
          </button>
        </div>
      </form>
    </div>
  </main>
</template>
