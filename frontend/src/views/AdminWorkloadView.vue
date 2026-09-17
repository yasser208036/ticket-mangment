<script setup lang="ts">
import { onMounted } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiIcon from '../components/ui/UiIcon.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import { useWorkloadStore } from '../stores/workload'
const store = useWorkloadStore()
onMounted(() => void store.load())
const rowTo = (userId: number) => ({
  name: 'tickets',
  query: {
    assignee: String(userId),
    status: store.data?.open_status_ids.join(',') ?? '',
  },
})
</script>

<template>
  <main class="mx-auto max-w-7xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader
      title="Agent workload"
      description="Queue distribution, agent capacity and bottlenecks."
    />

    <UiLoadingPanel
      v-if="store.loading"
      label="Loading workload"
      testid="workload-loading"
    />

    <UiAlert v-else-if="store.error" data-testid="workload-error">
      {{ store.error }}
    </UiAlert>

    <section v-else-if="store.data" data-testid="workload" class="space-y-5">
      <div
        class="ui-card flex flex-col justify-between gap-3 p-5 sm:flex-row sm:items-center"
      >
        <div>
          <p class="ui-eyebrow">Queue balancing</p>
          <p
            data-testid="workload-average"
            class="tabular mt-1 text-lg font-semibold tracking-tight text-ink-900"
          >
            Average: {{ store.data.average_open ?? '—' }} ±
            {{ store.data.band ?? '—' }}
          </p>
        </div>
        <p class="max-w-xs text-xs text-ink-500">
          Agents above the average plus the band are marked High. Agents who
          cannot take new tickets are flagged on their row.
        </p>
      </div>

      <UiEmptyState
        v-if="!store.data.agents.length"
        icon="users"
        title="No agents yet."
        testid="workload-empty"
      />

      <div v-else class="ui-card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="ui-table">
            <thead class="ui-thead">
              <tr>
                <th scope="col" class="ui-th">Agent</th>
                <th scope="col" class="ui-th">Total Open</th>
                <th
                  v-for="priority in store.data.priorities"
                  :key="priority.id"
                  scope="col"
                  class="ui-th"
                >
                  {{ priority.name }}
                </th>
              </tr>
            </thead>
            <tbody class="ui-tbody">
              <tr
                v-for="row in store.data.agents"
                :key="row.user.id"
                data-testid="workload-row"
                class="ui-tr"
                :class="{
                  'load-high bg-rose-50/40': row.load === 'high',
                  'load-low bg-sky-50/40': row.load === 'low',
                }"
              >
                <td class="ui-td">
                  <div class="flex flex-col gap-1">
                    <span class="flex items-center gap-2.5">
                      <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-ink-100 text-xs font-semibold text-ink-600"
                        aria-hidden="true"
                      >
                        {{ row.user.name.charAt(0).toUpperCase() }}
                      </span>
                      <RouterLink
                        :to="rowTo(row.user.id)"
                        class="ui-focus rounded font-medium text-ink-900 transition-colors hover:text-brand-600"
                      >
                        {{ row.user.name }}
                      </RouterLink>
                      <span
                        v-if="row.load === 'high'"
                        class="ui-chip border-rose-200 bg-rose-50 text-rose-700"
                      >
                        High
                      </span>
                      <span
                        v-else-if="row.load === 'low'"
                        class="ui-chip border-sky-200 bg-sky-50 text-sky-700"
                      >
                        Low
                      </span>
                    </span>

                    <span
                      v-if="row.needs_reassignment"
                      data-testid="workload-needs-reassignment"
                      class="flex items-center gap-1 text-xs text-amber-700"
                    >
                      <UiIcon name="alert-triangle" class="h-3.5 w-3.5" />
                      Cannot be assigned new tickets
                    </span>
                  </div>
                </td>

                <td
                  data-testid="workload-row-total"
                  class="ui-td whitespace-nowrap"
                >
                  <RouterLink
                    :to="rowTo(row.user.id)"
                    class="ui-focus tabular inline-flex min-w-7 items-center justify-center rounded-md bg-ink-100 px-2 py-0.5 text-xs font-medium text-ink-900 transition-colors hover:bg-brand-50 hover:text-brand-700"
                  >
                    {{ row.open_total }}
                  </RouterLink>
                </td>

                <td
                  v-for="cell in row.by_priority"
                  :key="cell.priority_id"
                  class="ui-td whitespace-nowrap"
                >
                  <RouterLink
                    :to="rowTo(row.user.id)"
                    class="ui-focus tabular inline-flex min-w-6 items-center justify-center rounded-md px-1.5 py-0.5 text-xs transition-colors"
                    :class="
                      cell.count > 0
                        ? 'font-medium text-ink-900 hover:bg-brand-50 hover:text-brand-700'
                        : 'text-ink-400'
                    "
                  >
                    {{ cell.count }}
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </main>
</template>
