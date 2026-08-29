<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const mobileMenuOpen = ref(false)

const userInitials = computed(() => {
  if (!auth.user?.name) return '?'
  return auth.user.name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2)
})

watch(
  () => route.path,
  () => {
    mobileMenuOpen.value = false
  },
)

async function signOut(): Promise<void> {
  try {
    await auth.logout()
  } finally {
    await router.replace({ name: 'login' })
  }
}
</script>

<template>
  <div
    class="min-h-screen flex flex-col bg-slate-50/60 font-sans text-slate-900 antialiased"
  >
    <header
      v-if="auth.isAuthenticated"
      class="sticky top-0 z-40 border-b border-slate-200/80 bg-white/90 backdrop-blur-md transition-all"
    >
      <div
        class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3.5 sm:px-6 lg:px-8"
      >
        <!-- Left: Logo & Nav -->
        <div class="flex items-center gap-8">
          <RouterLink
            :to="{ name: 'home' }"
            class="flex items-center gap-2.5 text-lg font-bold tracking-tight text-slate-900 transition-opacity hover:opacity-90"
          >
            <div
              class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-600 to-violet-600 text-white shadow-md shadow-indigo-500/20"
            >
              <svg
                class="h-5 w-5"
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
            <span
              class="bg-gradient-to-r from-slate-900 to-slate-700 bg-clip-text text-transparent"
              >Deskflow</span
            >
          </RouterLink>

          <nav
            class="hidden items-center gap-1 text-sm font-medium text-slate-600 md:flex"
          >
            <RouterLink
              :to="{ name: 'tickets' }"
              class="rounded-lg px-3 py-2 transition-all hover:bg-slate-100 hover:text-slate-900"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-tickets"
            >
              Tickets
            </RouterLink>
            <RouterLink
              :to="{ name: 'new-ticket' }"
              class="rounded-lg px-3 py-2 transition-all hover:bg-slate-100 hover:text-slate-900"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-new-ticket"
            >
              New ticket
            </RouterLink>

            <div v-if="auth.isAdmin" class="mx-1.5 h-4 w-px bg-slate-200" />

            <RouterLink
              v-if="auth.isAdmin"
              :to="{ name: 'admin-categories' }"
              class="rounded-lg px-3 py-2 transition-all hover:bg-slate-100 hover:text-slate-900"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-categories"
            >
              Categories
            </RouterLink>
            <RouterLink
              v-if="auth.isAdmin"
              :to="{ name: 'admin-users' }"
              class="rounded-lg px-3 py-2 transition-all hover:bg-slate-100 hover:text-slate-900"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-users"
            >
              Users
            </RouterLink>
            <RouterLink
              v-if="auth.isAdmin"
              :to="{ name: 'admin-workload' }"
              class="rounded-lg px-3 py-2 transition-all hover:bg-slate-100 hover:text-slate-900"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-workload"
            >
              Workload
            </RouterLink>
          </nav>
        </div>

        <!-- Right: Profile & Actions -->
        <div class="flex items-center gap-3">
          <div class="hidden items-center gap-2.5 sm:flex">
            <div
              class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-200 text-xs font-bold text-slate-700 ring-2 ring-white shadow-xs"
            >
              {{ userInitials }}
            </div>
            <div class="flex flex-col">
              <span
                class="text-xs font-semibold text-slate-900"
                data-testid="session-user"
              >
                {{ auth.user?.name }}
              </span>
              <span
                class="text-[10px] font-medium uppercase tracking-wider text-slate-500"
              >
                {{ auth.user?.role }}
              </span>
            </div>
          </div>

          <div class="hidden h-5 w-px bg-slate-200 sm:block" />

          <RouterLink
            :to="{ name: 'account-password' }"
            class="hidden rounded-lg px-2.5 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100 hover:text-slate-900 sm:block"
            active-class="text-indigo-600 font-semibold"
            data-testid="nav-password"
          >
            Password
          </RouterLink>

          <button
            type="button"
            @click="signOut"
            class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-xs transition-all hover:bg-slate-50 hover:text-rose-600 hover:border-rose-200 active:scale-95"
            data-testid="sign-out"
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
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
              />
            </svg>
            <span>Sign out</span>
          </button>

          <!-- Mobile menu toggle -->
          <button
            type="button"
            @click="mobileMenuOpen = !mobileMenuOpen"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-100 md:hidden"
            aria-label="Toggle menu"
          >
            <svg
              v-if="!mobileMenuOpen"
              class="h-5 w-5"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M4 6h16M4 12h16M4 18h16"
              />
            </svg>
            <svg
              v-else
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
      </div>

      <!-- Mobile drawer -->
      <div
        v-if="mobileMenuOpen"
        class="border-t border-slate-200 bg-white px-4 py-4 md:hidden shadow-lg animate-in slide-in-from-top-2"
      >
        <nav class="grid gap-1 text-sm font-medium text-slate-700">
          <RouterLink
            :to="{ name: 'tickets' }"
            class="rounded-lg px-3 py-2 hover:bg-slate-100"
            active-class="bg-indigo-50 text-indigo-700 font-semibold"
            data-testid="nav-tickets"
          >
            Tickets
          </RouterLink>
          <RouterLink
            :to="{ name: 'new-ticket' }"
            class="rounded-lg px-3 py-2 hover:bg-slate-100"
            active-class="bg-indigo-50 text-indigo-700 font-semibold"
            data-testid="nav-new-ticket"
          >
            New ticket
          </RouterLink>
          <template v-if="auth.isAdmin">
            <div class="my-1 border-t border-slate-100" />
            <span
              class="px-3 text-[10px] font-bold uppercase tracking-wider text-slate-400"
              >Admin</span
            >
            <RouterLink
              :to="{ name: 'admin-categories' }"
              class="rounded-lg px-3 py-2 hover:bg-slate-100"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-categories"
            >
              Categories
            </RouterLink>
            <RouterLink
              :to="{ name: 'admin-users' }"
              class="rounded-lg px-3 py-2 hover:bg-slate-100"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-users"
            >
              Users
            </RouterLink>
            <RouterLink
              :to="{ name: 'admin-workload' }"
              class="rounded-lg px-3 py-2 hover:bg-slate-100"
              active-class="bg-indigo-50 text-indigo-700 font-semibold"
              data-testid="nav-workload"
            >
              Workload
            </RouterLink>
          </template>
          <div class="my-1 border-t border-slate-100" />
          <RouterLink
            :to="{ name: 'account-password' }"
            class="rounded-lg px-3 py-2 hover:bg-slate-100"
            active-class="bg-indigo-50 text-indigo-700 font-semibold"
            data-testid="nav-password"
          >
            Change Password
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>
  </div>
</template>
