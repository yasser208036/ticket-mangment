<script setup lang="ts">
import { computed, onMounted } from 'vue'
import StatCard from '../components/StatCard.vue'
import ColorBadge from '../components/ColorBadge.vue'
import { useStatsStore } from '../stores/stats'
import { useAuthStore } from '../stores/auth'

const stats = useStatsStore()
const auth = useAuthStore()

onMounted(() => void stats.load())

const openStatusIds = computed(() =>
  (stats.data?.by_status ?? [])
    .filter((status) => !status.is_terminal)
    .map((status) => status.id)
    .join(','),
)

const totalStatusTickets = computed(
  () =>
    (stats.data?.by_status ?? []).reduce((acc, row) => acc + row.count, 0) || 1,
)

const totalPriorityTickets = computed(
  () =>
    (stats.data?.by_priority ?? []).reduce((acc, row) => acc + row.count, 0) ||
    1,
)

// The card's figure is scoped for an agent, so its link must be too, or a
// three-ticket card lands on a fifty-ticket list. Keyed on `scope`, not on
// the role -- the API decides, the view reports. `assignee: 'me'` is the same
// URL sentinel the ticket list already parses.
const escalatedTo = computed(() => ({
  name: 'tickets',
  query: {
    escalated: 'true',
    ...(stats.data?.scope === 'own' ? { assignee: 'me' } : {}),
  },
}))
</script>

<template>
  <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <!-- Loading State -->
    <div
      v-if="stats.loading"
      data-testid="dashboard-loading"
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
        <span>Loading dashboard metrics...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-else-if="stats.error"
      data-testid="dashboard-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm"
    >
      <div class="flex items-center gap-2 font-semibold">
        <svg
          class="h-5 w-5 text-rose-500"
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
        <span>Failed to load dashboard</span>
      </div>
      <p class="mt-1 text-rose-600">{{ stats.error }}</p>
    </div>

    <!-- Loaded Dashboard Section -->
    <section v-else-if="stats.data" data-testid="dashboard" class="space-y-8">
      <!-- Header Banner -->
      <div
        class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
      >
        <div>
          <h1
            class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
          >
            Welcome back{{
              auth.user?.name ? `, ${auth.user.name.split(' ')[0]}` : ''
            }}
          </h1>
          <p class="mt-1 text-sm text-slate-500">
            Overview of queue health, active assignments, and ticket
            distribution.
          </p>
        </div>
        <div class="flex items-center gap-3">
          <RouterLink
            :to="{ name: 'new-ticket' }"
            class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 transition-all hover:opacity-95 hover:shadow-lg active:scale-95"
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
            <span>New ticket</span>
          </RouterLink>
        </div>
      </div>

      <!-- Stat Cards Grid -->
      <div class="grid gap-5 sm:grid-cols-3">
        <StatCard
          label="My open tickets"
          :count="stats.data.mine_open"
          :to="{
            name: 'tickets',
            query: { assignee: 'me', status: openStatusIds },
          }"
          testid="stat-mine-open"
        />
        <StatCard
          label="Unassigned"
          :count="stats.data.unassigned"
          :to="{ name: 'tickets', query: { assignee: 'unassigned' } }"
          testid="stat-unassigned"
        />
        <StatCard
          :label="
            stats.data.scope === 'own'
              ? 'My escalated tickets'
              : 'Escalated tickets'
          "
          :count="stats.data.escalated"
          :to="escalatedTo"
          testid="stat-escalated"
        />
      </div>

      <!-- Breakdown Grid -->
      <div class="grid gap-6 lg:grid-cols-2">
        <!-- Status Breakdown -->
        <section
          data-testid="dashboard-by-status"
          class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm"
        >
          <div
            class="flex items-center justify-between border-b border-slate-100 pb-4"
          >
            <h2 class="text-base font-bold text-slate-900">
              {{
                stats.data.scope === 'own'
                  ? 'My tickets by status'
                  : 'Queue by status'
              }}
            </h2>
            <span
              class="text-xs font-semibold text-slate-400 uppercase tracking-wider"
            >
              Distribution
            </span>
          </div>

          <div class="mt-4 divide-y divide-slate-100">
            <RouterLink
              v-for="row in stats.data.by_status"
              :key="row.id"
              :to="{ name: 'tickets', query: { status: String(row.id) } }"
              class="group flex items-center justify-between py-3.5 transition-colors hover:bg-slate-50/80 -mx-2 px-2 rounded-xl"
            >
              <div class="flex items-center gap-3">
                <ColorBadge :name="row.name" :color="row.color" />
              </div>
              <div class="flex items-center gap-3">
                <div
                  class="hidden sm:block h-1.5 w-24 overflow-hidden rounded-full bg-slate-100"
                >
                  <div
                    class="h-full rounded-full transition-all duration-500"
                    :style="{
                      width: `${Math.round((row.count / totalStatusTickets) * 100)}%`,
                      backgroundColor: row.color,
                    }"
                  />
                </div>
                <span
                  class="inline-flex min-w-6 items-center justify-center rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors"
                >
                  {{ row.count }}
                </span>
                <svg
                  class="h-4 w-4 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500"
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
              </div>
            </RouterLink>
          </div>
        </section>

        <!-- Priority Breakdown -->
        <section
          data-testid="dashboard-by-priority"
          class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm"
        >
          <div
            class="flex items-center justify-between border-b border-slate-100 pb-4"
          >
            <h2 class="text-base font-bold text-slate-900">
              Tickets by priority
            </h2>
            <span
              class="text-xs font-semibold text-slate-400 uppercase tracking-wider"
            >
              Urgency
            </span>
          </div>

          <div class="mt-4 divide-y divide-slate-100">
            <RouterLink
              v-for="row in stats.data.by_priority"
              :key="row.id"
              :to="{ name: 'tickets', query: { priority: String(row.id) } }"
              class="group flex items-center justify-between py-3.5 transition-colors hover:bg-slate-50/80 -mx-2 px-2 rounded-xl"
            >
              <div class="flex items-center gap-3">
                <ColorBadge :name="row.name" :color="row.color" />
              </div>
              <div class="flex items-center gap-3">
                <div
                  class="hidden sm:block h-1.5 w-24 overflow-hidden rounded-full bg-slate-100"
                >
                  <div
                    class="h-full rounded-full transition-all duration-500"
                    :style="{
                      width: `${Math.round((row.count / totalPriorityTickets) * 100)}%`,
                      backgroundColor: row.color,
                    }"
                  />
                </div>
                <span
                  class="inline-flex min-w-6 items-center justify-center rounded-lg bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors"
                >
                  {{ row.count }}
                </span>
                <svg
                  class="h-4 w-4 text-slate-300 transition-transform group-hover:translate-x-0.5 group-hover:text-slate-500"
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
              </div>
            </RouterLink>
          </div>
        </section>
      </div>
    </section>
  </main>
</template>
