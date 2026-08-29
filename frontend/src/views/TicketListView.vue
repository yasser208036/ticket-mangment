<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CategoryBadge from '../components/CategoryBadge.vue'
import ColorBadge from '../components/ColorBadge.vue'
import EscalationBadge from '../components/EscalationBadge.vue'
import TicketFilterBar from '../components/TicketFilterBar.vue'
import { relativeAge } from '../lib/relativeTime'
import { fromQuery, toQuery } from '../lib/ticketQuery'
import { useTicketsStore } from '../stores/tickets'

const store = useTicketsStore()
const route = useRoute()
const router = useRouter()
const pageSizes = [15, 25, 50, 100]

function changePageSize(event: Event): void {
  void store.setPerPage(Number((event.target as HTMLSelectElement).value))
}

function queryState() {
  return {
    statusIds: store.statusIds,
    priorityIds: store.priorityIds,
    categoryIds: store.categoryIds,
    assignedTo: store.assignedTo,
    escalated: store.escalated,
    q: store.q,
    sort: store.sort,
    direction: store.direction,
    page: store.page,
  }
}
async function hydrateFromRoute(): Promise<void> {
  if (syncing) return
  Object.assign(store, fromQuery(route.query))
  await store.load()
}
function toggleSort(sort: 'priority' | 'created_at' | 'escalated_at'): void {
  const direction =
    store.sort === sort && store.direction === 'desc' ? 'asc' : 'desc'
  void store.setSort(sort, direction)
}
function ariaSort(
  sort: 'priority' | 'created_at' | 'escalated_at',
): 'ascending' | 'descending' | 'none' {
  return store.sort === sort
    ? store.direction === 'asc'
      ? 'ascending'
      : 'descending'
    : 'none'
}

let syncing = false
onMounted(async () => {
  await hydrateFromRoute()
})
watch(
  () => toQuery(queryState()),
  async (query) => {
    if (syncing) return
    syncing = true
    await router.replace({ query })
    syncing = false
  },
  { deep: true },
)
watch(() => route.fullPath, hydrateFromRoute, { deep: true })
</script>

<template>
  <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Header -->
    <div
      class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
    >
      <div class="flex items-center gap-3">
        <h1
          class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
        >
          Tickets
        </h1>
        <span
          v-if="store.meta"
          class="inline-flex items-center rounded-full bg-indigo-50 px-2.5 py-0.5 text-xs font-bold text-indigo-700"
        >
          {{ store.meta.total }} total
        </span>
      </div>

      <div class="flex items-center gap-3">
        <RouterLink
          :to="{ name: 'new-ticket' }"
          class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 transition-all hover:bg-indigo-700 active:scale-95"
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

    <!-- Filters Bar -->
    <TicketFilterBar />

    <!-- Loading State -->
    <div
      v-if="store.loading"
      data-testid="tickets-loading"
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
        <span>Loading tickets...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-else-if="store.error"
      data-testid="tickets-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm"
    >
      {{ store.error }}
    </div>

    <!-- Tickets Table View -->
    <div
      v-else-if="store.items.length"
      class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm"
    >
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" data-testid="tickets-table">
          <thead
            class="border-b border-slate-200 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-500"
          >
            <tr>
              <th scope="col" class="py-3.5 pl-6 pr-3">Reference</th>
              <th scope="col" class="px-3 py-3.5">Subject</th>
              <th scope="col" class="px-3 py-3.5">Requester</th>
              <th scope="col" class="px-3 py-3.5">Category</th>
              <th
                scope="col"
                class="px-3 py-3.5"
                :aria-sort="ariaSort('priority')"
              >
                <button
                  data-testid="sort-priority"
                  @click="toggleSort('priority')"
                  class="group inline-flex items-center gap-1.5 font-bold hover:text-slate-900"
                >
                  <span>Priority</span>
                  <svg
                    class="h-3.5 w-3.5 text-slate-400 group-hover:text-slate-700"
                    :class="{
                      'text-indigo-600 rotate-180':
                        store.sort === 'priority' && store.direction === 'asc',
                      'text-indigo-600':
                        store.sort === 'priority' && store.direction === 'desc',
                    }"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M19 9l-7 7-7-7"
                    />
                  </svg>
                </button>
              </th>
              <th
                scope="col"
                class="px-3 py-3.5"
                :aria-sort="ariaSort('escalated_at')"
              >
                <button
                  data-testid="sort-escalated_at"
                  @click="toggleSort('escalated_at')"
                  class="group inline-flex items-center gap-1.5 font-bold hover:text-slate-900"
                >
                  <span>Escalated</span>
                  <svg
                    class="h-3.5 w-3.5 text-slate-400 group-hover:text-slate-700"
                    :class="{
                      'text-indigo-600 rotate-180':
                        store.sort === 'escalated_at' &&
                        store.direction === 'asc',
                      'text-indigo-600':
                        store.sort === 'escalated_at' &&
                        store.direction === 'desc',
                    }"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M19 9l-7 7-7-7"
                    />
                  </svg>
                </button>
              </th>
              <th scope="col" class="px-3 py-3.5">Status</th>
              <th scope="col" class="px-3 py-3.5">Assignee</th>
              <th
                scope="col"
                class="py-3.5 pl-3 pr-6"
                :aria-sort="ariaSort('created_at')"
              >
                <button
                  data-testid="sort-created_at"
                  @click="toggleSort('created_at')"
                  class="group inline-flex items-center gap-1.5 font-bold hover:text-slate-900"
                >
                  <span>Age</span>
                  <svg
                    class="h-3.5 w-3.5 text-slate-400 group-hover:text-slate-700"
                    :class="{
                      'text-indigo-600 rotate-180':
                        store.sort === 'created_at' &&
                        store.direction === 'asc',
                      'text-indigo-600':
                        store.sort === 'created_at' &&
                        store.direction === 'desc',
                    }"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    stroke-width="2"
                  >
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M19 9l-7 7-7-7"
                    />
                  </svg>
                </button>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 bg-white">
            <tr
              v-for="ticket in store.items"
              :key="ticket.id"
              data-testid="tickets-row"
              class="transition-colors hover:bg-slate-50/70"
            >
              <!-- Reference Link -->
              <td
                class="whitespace-nowrap py-4 pl-6 pr-3 font-mono text-xs font-semibold"
              >
                <RouterLink
                  :to="{ name: 'ticket-detail', params: { id: ticket.id } }"
                  data-testid="tickets-reference-link"
                  class="inline-flex items-center rounded-lg bg-indigo-50/70 px-2.5 py-1 text-indigo-700 hover:bg-indigo-100 hover:text-indigo-900 transition-colors"
                >
                  {{ ticket.reference }}
                </RouterLink>
              </td>

              <!-- Subject -->
              <td
                class="max-w-xs truncate px-3 py-4 font-medium text-slate-900"
              >
                <RouterLink
                  :to="{ name: 'ticket-detail', params: { id: ticket.id } }"
                  class="hover:text-indigo-600 transition-colors"
                >
                  {{ ticket.subject }}
                </RouterLink>
              </td>

              <!-- Requester -->
              <td class="whitespace-nowrap px-3 py-4 text-slate-700">
                <div class="flex items-center gap-2">
                  <div
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-slate-100 text-[10px] font-bold text-slate-600"
                  >
                    {{ ticket.requester.name.charAt(0).toUpperCase() }}
                  </div>
                  <span class="truncate">{{ ticket.requester.name }}</span>
                </div>
              </td>

              <!-- Category -->
              <td class="whitespace-nowrap px-3 py-4">
                <CategoryBadge :category="ticket.category" />
              </td>

              <!-- Priority -->
              <td class="whitespace-nowrap px-3 py-4">
                <ColorBadge
                  :name="ticket.priority.name"
                  :color="ticket.priority.color"
                />
              </td>

              <!-- Escalated -->
              <td
                class="whitespace-nowrap px-3 py-4"
                data-testid="tickets-escalation"
              >
                <EscalationBadge :level="ticket.escalation_level" />
              </td>

              <!-- Status -->
              <td class="whitespace-nowrap px-3 py-4">
                <ColorBadge
                  :name="ticket.status.name"
                  :color="ticket.status.color"
                />
              </td>

              <!-- Assignee -->
              <td class="whitespace-nowrap px-3 py-4 text-xs">
                <span
                  v-if="ticket.assignee"
                  class="inline-flex items-center gap-1.5 font-medium text-slate-700"
                >
                  <span class="h-2 w-2 rounded-full bg-emerald-500" />
                  {{ ticket.assignee.name }}
                </span>
                <span
                  v-else
                  class="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-500"
                >
                  Unassigned
                </span>
              </td>

              <!-- Age -->
              <td
                class="whitespace-nowrap py-4 pl-3 pr-6 text-xs text-slate-500"
                data-testid="tickets-age"
                :title="ticket.created_at"
              >
                {{ relativeAge(ticket.created_at) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination / Summary Footer -->
      <div
        class="flex flex-col items-center justify-between gap-4 border-t border-slate-100 px-6 py-4 sm:flex-row"
      >
        <p
          v-if="store.meta"
          data-testid="tickets-count"
          class="text-xs text-slate-500"
        >
          Showing
          <span class="font-semibold text-slate-700">{{
            store.meta.from ?? 0
          }}</span>
          to
          <span class="font-semibold text-slate-700">{{
            store.meta.to ?? 0
          }}</span>
          of
          <span class="font-semibold text-slate-700">{{
            store.meta.total
          }}</span>
          tickets
        </p>
        <div v-else />

        <div class="flex items-center gap-3">
          <div class="flex items-center gap-1.5 text-xs text-slate-500">
            <span>Per page:</span>
            <select
              :value="store.perPage"
              data-testid="tickets-per-page"
              @change="changePageSize"
              class="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-700 shadow-2xs outline-none focus:border-indigo-500"
            >
              <option v-for="size in pageSizes" :key="size" :value="size">
                {{ size }}
              </option>
            </select>
          </div>

          <div class="flex items-center gap-1">
            <button
              data-testid="tickets-prev"
              :disabled="!store.meta || store.meta.current_page <= 1"
              @click="store.goToPage(store.page - 1)"
              class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition-all hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
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
                  d="M15 19l-7-7 7-7"
                />
              </svg>
              <span>Previous</span>
            </button>
            <button
              data-testid="tickets-next"
              :disabled="
                !store.meta || store.meta.current_page >= store.meta.last_page
              "
              @click="store.goToPage(store.page + 1)"
              class="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs transition-all hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed"
            >
              <span>Next</span>
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
                  d="M9 5l7 7-7 7"
                />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div
      v-else
      class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center shadow-xs"
    >
      <div
        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-50 text-slate-400"
      >
        <svg
          class="h-6 w-6"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
          />
        </svg>
      </div>
      <p
        class="mt-4 text-base font-bold text-slate-900"
        data-testid="tickets-empty"
      >
        No tickets on this page.
      </p>
      <p class="mt-1 text-xs text-slate-500">
        Try clearing active search/filters or file a new ticket to get started.
      </p>
    </div>
  </main>
</template>
