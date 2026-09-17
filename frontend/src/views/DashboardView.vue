<script setup lang="ts">
import { computed, onMounted } from 'vue'
import StatCard from '../components/StatCard.vue'
import ColorBadge from '../components/ColorBadge.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiIcon from '../components/ui/UiIcon.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import UiPanel from '../components/ui/UiPanel.vue'
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
// URL sentinel the ticket list already parses. `scope === 'authored'` needs
// no such narrowing: an end user's ticket list is already scoped server-side
// to what they created, and `assignee: 'me'` there would mean *assigned* to
// them -- the wrong filter, and it would return nothing.
const escalatedTo = computed(() => ({
  name: 'tickets',
  query: {
    escalated: 'true',
    ...(stats.data?.scope === 'assigned' ? { assignee: 'me' } : {}),
  },
}))

const scopeLabel = computed(() =>
  stats.data?.scope === 'all' ? 'Queue' : 'My tickets',
)
</script>

<template>
  <main class="mx-auto max-w-7xl space-y-6 px-4 py-7 sm:px-6 lg:px-8">
    <UiLoadingPanel
      v-if="stats.loading"
      shape="cards"
      label="Loading dashboard metrics"
      testid="dashboard-loading"
    />

    <UiAlert
      v-else-if="stats.error"
      data-testid="dashboard-error"
      title="Failed to load dashboard"
    >
      {{ stats.error }}
    </UiAlert>

    <section v-else-if="stats.data" data-testid="dashboard" class="space-y-6">
      <UiPageHeader
        :title="`Welcome back${auth.user?.name ? `, ${auth.user.name.split(' ')[0]}` : ''}`"
        description="Queue health, active assignments and ticket distribution."
      >
        <template v-if="auth.isEndUser" #actions>
          <UiButton variant="primary" icon="plus" :to="{ name: 'new-ticket' }">
            New ticket
          </UiButton>
        </template>
      </UiPageHeader>

      <div class="grid gap-4 sm:grid-cols-3">
        <StatCard
          v-if="!auth.isEndUser"
          label="My open tickets"
          :count="stats.data.mine_open"
          :to="{
            name: 'tickets',
            query: { assignee: 'me', status: openStatusIds },
          }"
          testid="stat-mine-open"
        />
        <StatCard
          v-if="!auth.isEndUser"
          label="Unassigned"
          :count="stats.data.unassigned"
          :to="{ name: 'tickets', query: { assignee: 'unassigned' } }"
          testid="stat-unassigned"
        />
        <StatCard
          :label="
            stats.data.scope === 'all'
              ? 'Escalated tickets'
              : 'My escalated tickets'
          "
          :count="stats.data.escalated"
          :to="escalatedTo"
          testid="stat-escalated"
        />
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <UiPanel
          data-testid="dashboard-by-status"
          :title="
            scopeLabel === 'Queue' ? 'Queue by status' : 'My tickets by status'
          "
          eyebrow="Distribution"
        >
          <div class="divide-y divide-line">
            <RouterLink
              v-for="row in stats.data.by_status"
              :key="row.id"
              :to="{ name: 'tickets', query: { status: String(row.id) } }"
              class="ui-focus group flex items-center justify-between gap-4 px-5 py-2.5 transition-colors hover:bg-sunken sm:px-6"
            >
              <ColorBadge :name="row.name" :color="row.color" />
              <div class="flex items-center gap-3">
                <!-- The bar is a second reading of the same number, not a
                     separate fact, so it is decorative to assistive tech. -->
                <span
                  class="hidden h-1 w-24 overflow-hidden rounded-full bg-ink-100 sm:block"
                  aria-hidden="true"
                >
                  <span
                    class="block h-full rounded-full transition-[width] duration-500"
                    :style="{
                      width: `${Math.round((row.count / totalStatusTickets) * 100)}%`,
                      backgroundColor: row.color,
                    }"
                  />
                </span>
                <span
                  class="tabular w-8 text-right text-sm font-medium text-ink-900"
                >
                  {{ row.count }}
                </span>
                <UiIcon
                  name="chevron-right"
                  class="h-3.5 w-3.5 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-ink-500"
                />
              </div>
            </RouterLink>
          </div>
        </UiPanel>

        <UiPanel
          data-testid="dashboard-by-priority"
          title="Tickets by priority"
          eyebrow="Urgency"
        >
          <div class="divide-y divide-line">
            <RouterLink
              v-for="row in stats.data.by_priority"
              :key="row.id"
              :to="{ name: 'tickets', query: { priority: String(row.id) } }"
              class="ui-focus group flex items-center justify-between gap-4 px-5 py-2.5 transition-colors hover:bg-sunken sm:px-6"
            >
              <ColorBadge :name="row.name" :color="row.color" />
              <div class="flex items-center gap-3">
                <span
                  class="hidden h-1 w-24 overflow-hidden rounded-full bg-ink-100 sm:block"
                  aria-hidden="true"
                >
                  <span
                    class="block h-full rounded-full transition-[width] duration-500"
                    :style="{
                      width: `${Math.round((row.count / totalPriorityTickets) * 100)}%`,
                      backgroundColor: row.color,
                    }"
                  />
                </span>
                <span
                  class="tabular w-8 text-right text-sm font-medium text-ink-900"
                >
                  {{ row.count }}
                </span>
                <UiIcon
                  name="chevron-right"
                  class="h-3.5 w-3.5 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-ink-500"
                />
              </div>
            </RouterLink>
          </div>
        </UiPanel>
      </div>
    </section>
  </main>
</template>
