<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import UiButton from './components/ui/UiButton.vue'
import UiIcon from './components/ui/UiIcon.vue'
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

const ROLE_LABELS: Record<string, string> = {
  admin: 'Administrator',
  agent: 'Support Agent',
  user: 'Requester',
}
const roleLabel = computed(
  () => ROLE_LABELS[auth.user?.role ?? ''] ?? auth.user?.role,
)

// One list, rendered twice — the desktop bar and the mobile drawer used to
// carry two hand-maintained copies of the same seven links, and a link added
// to one of them silently went missing from the other.
type NavLink = { to: string; label: string; testid: string }

const mainLinks = computed<NavLink[]>(() => [
  { to: 'tickets', label: 'Tickets', testid: 'nav-tickets' },
  ...(auth.isEndUser
    ? [{ to: 'new-ticket', label: 'New ticket', testid: 'nav-new-ticket' }]
    : []),
])

const adminLinks = computed<NavLink[]>(() =>
  auth.isAdmin
    ? [
        {
          to: 'admin-categories',
          label: 'Categories',
          testid: 'nav-categories',
        },
        { to: 'admin-users', label: 'Users', testid: 'nav-users' },
        { to: 'admin-workload', label: 'Workload', testid: 'nav-workload' },
        {
          to: 'admin-assignment-requests',
          label: 'Requests',
          testid: 'nav-assignment-requests',
        },
      ]
    : [],
)

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
    class="flex min-h-screen flex-col bg-canvas font-sans text-ink-900 antialiased"
  >
    <header
      v-if="auth.isAuthenticated"
      class="sticky top-0 z-40 border-b border-line bg-surface/85 backdrop-blur-md"
    >
      <div
        class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-2.5 sm:px-6 lg:px-8"
      >
        <div class="flex min-w-0 items-center gap-7">
          <RouterLink
            :to="{ name: 'home' }"
            class="ui-focus flex shrink-0 items-center gap-2 rounded-md text-[15px] font-semibold tracking-tight text-ink-900"
          >
            <span
              class="flex h-7 w-7 items-center justify-center rounded-lg bg-gradient-to-br from-brand-600 to-accent-600 text-white shadow-sm shadow-brand-600/25"
            >
              <UiIcon name="bolt" class="h-4 w-4" :stroke-width="2" />
            </span>
            Deskflow
          </RouterLink>

          <nav class="hidden items-center gap-0.5 text-sm md:flex">
            <RouterLink
              v-for="link in [...mainLinks, ...adminLinks]"
              :key="link.testid"
              :to="{ name: link.to }"
              :data-testid="link.testid"
              class="ui-focus rounded-md px-2.5 py-1.5 font-medium text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900"
              active-class="bg-brand-50 text-brand-700"
            >
              {{ link.label }}
            </RouterLink>
          </nav>
        </div>

        <div class="flex items-center gap-2">
          <div class="hidden items-center gap-2 sm:flex">
            <span
              class="flex h-7 w-7 items-center justify-center rounded-full bg-ink-100 text-[11px] font-semibold text-ink-600"
              aria-hidden="true"
            >
              {{ userInitials }}
            </span>
            <span class="flex flex-col leading-tight">
              <span
                class="text-xs font-medium text-ink-900"
                data-testid="session-user"
              >
                {{ auth.user?.name }}
              </span>
              <span class="text-[11px] text-ink-500">{{ roleLabel }}</span>
            </span>
          </div>

          <RouterLink
            :to="{ name: 'account-password' }"
            class="ui-focus hidden rounded-md px-2 py-1.5 text-xs font-medium text-ink-600 transition-colors hover:bg-ink-100 hover:text-ink-900 sm:block"
            active-class="bg-brand-50 text-brand-700"
            data-testid="nav-password"
          >
            Password
          </RouterLink>

          <UiButton
            size="sm"
            icon="logout"
            data-testid="sign-out"
            @click="signOut"
          >
            <span class="hidden sm:inline">Sign out</span>
          </UiButton>

          <button
            type="button"
            class="ui-focus inline-flex h-8 w-8 items-center justify-center rounded-md border border-line-strong text-ink-600 transition-colors hover:bg-ink-50 md:hidden"
            aria-label="Toggle menu"
            :aria-expanded="mobileMenuOpen"
            @click="mobileMenuOpen = !mobileMenuOpen"
          >
            <UiIcon
              :name="mobileMenuOpen ? 'close' : 'menu'"
              class="h-4.5 w-4.5"
            />
          </button>
        </div>
      </div>

      <div
        v-if="mobileMenuOpen"
        class="border-t border-line bg-surface px-4 py-3 md:hidden"
      >
        <nav class="grid gap-0.5 text-sm">
          <RouterLink
            v-for="link in mainLinks"
            :key="link.testid"
            :to="{ name: link.to }"
            :data-testid="link.testid"
            class="ui-focus rounded-md px-2.5 py-2 font-medium text-ink-700 hover:bg-ink-100"
            active-class="bg-brand-50 text-brand-700"
          >
            {{ link.label }}
          </RouterLink>

          <template v-if="adminLinks.length">
            <span class="ui-eyebrow mt-3 px-2.5 pb-1">Admin</span>
            <RouterLink
              v-for="link in adminLinks"
              :key="link.testid"
              :to="{ name: link.to }"
              :data-testid="link.testid"
              class="ui-focus rounded-md px-2.5 py-2 font-medium text-ink-700 hover:bg-ink-100"
              active-class="bg-brand-50 text-brand-700"
            >
              {{ link.label }}
            </RouterLink>
          </template>

          <div class="my-2 border-t border-line" />
          <RouterLink
            :to="{ name: 'account-password' }"
            class="ui-focus rounded-md px-2.5 py-2 font-medium text-ink-700 hover:bg-ink-100"
            active-class="bg-brand-50 text-brand-700"
            data-testid="nav-password"
          >
            Change password
          </RouterLink>
        </nav>
      </div>
    </header>

    <main class="flex-1">
      <RouterView />
    </main>
  </div>
</template>
