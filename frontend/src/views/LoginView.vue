<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { safeRedirect } from '../router/guards'
import { useAuthStore } from '../stores/auth'
import { errorMessage } from '../api/errors'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  try {
    await auth.login(email.value, password.value)
    await router.replace(safeRedirect(route.query.redirect))
  } catch (caughtError) {
    error.value = errorMessage(caughtError)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main
    class="flex min-h-screen items-center justify-center bg-radial from-indigo-50/50 via-slate-50 to-slate-100 px-4 py-12"
  >
    <section
      class="w-full max-w-md rounded-3xl border border-slate-200/80 bg-white/95 p-8 shadow-xl shadow-slate-200/60 backdrop-blur-sm sm:p-10"
    >
      <!-- Brand & Header -->
      <div class="mb-8 flex flex-col items-center text-center">
        <div
          class="mb-4 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-600 to-violet-600 text-white shadow-lg shadow-indigo-500/25"
        >
          <svg
            class="h-6 w-6"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2.2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M13 10V3L4 14h7v7l9-11h-7z"
            />
          </svg>
        </div>
        <p class="text-xs font-bold uppercase tracking-widest text-indigo-600">
          Deskflow Support
        </p>
        <h1
          class="mt-1 text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
        >
          Welcome back
        </h1>
        <p class="mt-1.5 text-xs text-slate-500">
          Sign in to manage your support tickets and queue.
        </p>
      </div>

      <form class="space-y-4" @submit.prevent="submit">
        <!-- Email -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700" for="email"
            >Email</label
          >
          <input
            id="email"
            v-model="email"
            type="email"
            autocomplete="username"
            required
            placeholder="you@deskflow.app"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            data-testid="login-email"
          />
        </div>

        <!-- Password -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700" for="password"
            >Password</label
          >
          <input
            id="password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            placeholder="••••••••••••"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            data-testid="login-password"
          />
        </div>

        <!-- Error Banner -->
        <div
          v-if="error"
          class="flex items-center gap-2 rounded-xl bg-rose-50 p-3 text-xs font-medium text-rose-700 border border-rose-200"
          data-testid="login-error"
        >
          <svg
            class="h-4 w-4 shrink-0 text-rose-500"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
            />
          </svg>
          <span>{{ error }}</span>
        </div>

        <!-- Submit Button -->
        <button
          class="mt-2 flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 py-3 text-sm font-semibold text-white shadow-md shadow-indigo-500/25 transition-all hover:opacity-95 disabled:opacity-60"
          type="submit"
          :disabled="submitting"
          data-testid="login-submit"
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
          <span>{{ submitting ? 'Signing in...' : 'Sign in' }}</span>
        </button>
      </form>
    </section>
  </main>
</template>
