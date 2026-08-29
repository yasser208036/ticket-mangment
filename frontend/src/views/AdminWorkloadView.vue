<script setup lang="ts">
import { onMounted } from 'vue'
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
  <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Header -->
    <div>
      <h1
        class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
      >
        Agent Workload
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        Monitor queue distribution, balance agent capacity, and identify
        bottlenecks.
      </p>
    </div>

    <!-- Loading State -->
    <div
      v-if="store.loading"
      data-testid="workload-loading"
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
        <span>Loading workload analytics...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-else-if="store.error"
      data-testid="workload-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm"
    >
      {{ store.error }}
    </div>

    <!-- Workload Content -->
    <section v-else-if="store.data" data-testid="workload" class="space-y-6">
      <!-- Workload Metric Banner -->
      <div
        class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50/70 via-white to-violet-50/70 p-6 shadow-sm"
      >
        <div class="flex items-center gap-4">
          <div
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-indigo-600 text-white shadow-md shadow-indigo-500/20"
          >
            <svg
              class="h-6 w-6"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              stroke-width="2"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
              />
            </svg>
          </div>
          <div>
            <h2
              class="text-xs font-bold uppercase tracking-wider text-slate-500"
            >
              Queue Balancing Metric
            </h2>
            <p
              data-testid="workload-average"
              class="text-lg font-extrabold text-slate-900"
            >
              Average: {{ store.data.average_open ?? '—' }} ±
              {{ store.data.band ?? '—' }}
            </p>
          </div>
        </div>

        <div class="text-xs text-slate-500 max-w-xs">
          Agents above average + band threshold are marked as High Load.
          Inactive or unassignable agents are flagged.
        </div>
      </div>

      <!-- Empty State -->
      <div
        v-if="!store.data.agents.length"
        data-testid="workload-empty"
        class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center shadow-xs"
      >
        <p class="text-base font-bold text-slate-900">No agents yet.</p>
      </div>

      <!-- Workload Matrix Table -->
      <div
        v-else
        class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm"
      >
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead
              class="border-b border-slate-200 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >
              <tr>
                <th scope="col" class="py-3.5 pl-6 pr-3">Agent</th>
                <th scope="col" class="px-3 py-3.5">Total Open</th>
                <th
                  v-for="priority in store.data.priorities"
                  :key="priority.id"
                  scope="col"
                  class="px-3 py-3.5"
                >
                  {{ priority.name }}
                </th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 bg-white">
              <tr
                v-for="row in store.data.agents"
                :key="row.user.id"
                data-testid="workload-row"
                :class="{
                  'load-high bg-rose-50/30': row.load === 'high',
                  'load-low bg-sky-50/20': row.load === 'low',
                }"
                class="transition-colors hover:bg-slate-50/70"
              >
                <!-- Agent Info -->
                <td class="py-4 pl-6 pr-3">
                  <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2.5">
                      <div
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-xs font-bold text-slate-700"
                      >
                        {{ row.user.name.charAt(0).toUpperCase() }}
                      </div>
                      <RouterLink
                        :to="rowTo(row.user.id)"
                        class="font-semibold text-slate-900 hover:text-indigo-600 transition-colors"
                      >
                        {{ row.user.name }}
                      </RouterLink>
                      <span
                        v-if="row.load === 'high'"
                        class="rounded-full bg-rose-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-rose-700"
                      >
                        High
                      </span>
                      <span
                        v-else-if="row.load === 'low'"
                        class="rounded-full bg-sky-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-sky-700"
                      >
                        Low
                      </span>
                    </div>

                    <span
                      v-if="row.needs_reassignment"
                      data-testid="workload-needs-reassignment"
                      class="inline-flex items-center gap-1 text-xs font-medium text-amber-700"
                    >
                      <svg
                        class="h-3.5 w-3.5 text-amber-500"
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
                      <span>Cannot be assigned new tickets</span>
                    </span>
                  </div>
                </td>

                <!-- Total Open -->
                <td
                  data-testid="workload-row-total"
                  class="whitespace-nowrap px-3 py-4"
                >
                  <RouterLink
                    :to="rowTo(row.user.id)"
                    class="inline-flex min-w-7 items-center justify-center rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-800 hover:bg-indigo-50 hover:text-indigo-700 transition-colors"
                  >
                    {{ row.open_total }}
                  </RouterLink>
                </td>

                <!-- Priorities -->
                <td
                  v-for="cell in row.by_priority"
                  :key="cell.priority_id"
                  class="whitespace-nowrap px-3 py-4 text-xs font-medium text-slate-600"
                >
                  <RouterLink
                    :to="rowTo(row.user.id)"
                    class="inline-flex min-w-6 items-center justify-center rounded-md px-2 py-0.5 text-xs transition-colors"
                    :class="
                      cell.count > 0
                        ? 'bg-slate-50 font-bold text-slate-800 hover:bg-indigo-50 hover:text-indigo-600'
                        : 'text-slate-400'
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
