<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'
import { useUsersStore } from '../stores/users'
import type { TicketDirection, TicketSort } from '../api/tickets'

const auth = useAuthStore()
const masterData = useMasterDataStore()
const tickets = useTicketsStore()
const users = useUsersStore()
const search = ref(tickets.q)
let timer: ReturnType<typeof setTimeout> | undefined
watch(search, (term) => {
  clearTimeout(timer)
  timer = setTimeout(() => void tickets.applySearch(term.trim()), 300)
})
watch(
  () => tickets.q,
  (value) => {
    if (value !== search.value.trim()) search.value = value
  },
)
onBeforeUnmount(() => clearTimeout(timer))

function apply(): void {
  void tickets.applyFilters()
}
function changeEscalation(event: Event): void {
  const selected = (event.target as HTMLSelectElement).value
  tickets.escalated = selected === '' ? null : selected === 'true'
  apply()
}
function changeSort(event: Event): void {
  void tickets.setSort(
    (event.target as HTMLSelectElement).value as TicketSort,
    tickets.direction,
  )
}
function changeDirection(event: Event): void {
  void tickets.setSort(
    tickets.sort,
    (event.target as HTMLSelectElement).value as TicketDirection,
  )
}

onMounted(() => {
  if (auth.isAdmin) {
    users.perPage = 100
    void users.load()
  }
})
</script>
<template>
  <section
    aria-label="Ticket filters"
    class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-sm"
  >
    <div class="flex flex-col gap-4">
      <!-- Search row -->
      <div class="relative flex flex-1 items-center">
        <div
          class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400"
        >
          <svg
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
            />
          </svg>
        </div>
        <input
          v-model="search"
          data-testid="filter-search"
          type="search"
          placeholder="Search by reference (#REF-1001), subject, or requester..."
          aria-label="Search tickets"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 py-2.5 pl-10 pr-4 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
      </div>

      <!-- Filters Row -->
      <div class="flex flex-wrap items-center gap-3">
        <!-- Status Filter -->
        <div class="flex flex-col gap-1">
          <label
            class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >Status</label
          >
          <select
            v-model.number="tickets.statusIds"
            multiple
            data-testid="filter-status"
            @change="apply"
            class="h-20 min-w-32 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
          >
            <option
              v-for="status in masterData.statuses"
              :key="status.id"
              :value="status.id"
              class="rounded-md py-0.5"
            >
              {{ status.name }}
            </option>
          </select>
        </div>

        <!-- Priority Filter -->
        <div class="flex flex-col gap-1">
          <label
            class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >Priority</label
          >
          <select
            v-model.number="tickets.priorityIds"
            multiple
            data-testid="filter-priority"
            @change="apply"
            class="h-20 min-w-32 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
          >
            <option
              v-for="priority in masterData.priorities"
              :key="priority.id"
              :value="priority.id"
              class="rounded-md py-0.5"
            >
              {{ priority.name }}
            </option>
          </select>
        </div>

        <!-- Category Filter -->
        <div class="flex flex-col gap-1">
          <label
            class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >Category</label
          >
          <select
            v-model.number="tickets.categoryIds"
            multiple
            data-testid="filter-category"
            @change="apply"
            class="h-20 min-w-32 rounded-xl border border-slate-200 bg-white px-2.5 py-1.5 text-xs text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
          >
            <option
              v-for="category in masterData.categories"
              :key="category.id"
              :value="category.id"
              class="rounded-md py-0.5"
            >
              {{ category.name }}
            </option>
          </select>
        </div>

        <!-- Single selects group -->
        <div class="flex flex-wrap items-end gap-3 self-end">
          <!-- Assignee -->
          <div class="flex flex-col gap-1">
            <label
              class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
              >Assignee</label
            >
            <select
              v-model="tickets.assignedTo"
              data-testid="filter-assignee"
              @change="apply"
              class="h-9 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            >
              <option value="">Anyone</option>
              <option value="me">Me</option>
              <option value="unassigned">Unassigned</option>
              <option
                v-for="user in auth.isAdmin ? users.users : []"
                :key="user.id"
                :value="user.id"
              >
                {{ user.name }}
              </option>
            </select>
          </div>

          <!-- Escalated -->
          <div class="flex flex-col gap-1">
            <label
              class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
              >Escalation</label
            >
            <select
              :value="
                tickets.escalated === null ? '' : String(tickets.escalated)
              "
              data-testid="filter-escalated"
              @change="changeEscalation"
              class="h-9 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
            >
              <option value="">Any escalation</option>
              <option value="true">Escalated</option>
              <option value="false">Not escalated</option>
            </select>
          </div>

          <!-- Sort -->
          <div class="flex flex-col gap-1">
            <label
              class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
              >Sort by</label
            >
            <div class="flex items-center gap-1.5">
              <select
                :value="tickets.sort"
                data-testid="filter-sort"
                @change="changeSort"
                class="h-9 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
              >
                <option value="created_at">Created</option>
                <option value="updated_at">Updated</option>
                <option value="priority">Priority</option>
                <option value="relevance">Relevance</option>
                <option value="escalated_at">Recently escalated</option>
              </select>

              <select
                :value="tickets.direction"
                data-testid="filter-direction"
                @change="changeDirection"
                class="h-9 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
              >
                <option value="desc">Descending</option>
                <option value="asc">Ascending</option>
              </select>
            </div>
          </div>

          <!-- Clear Filters -->
          <button
            type="button"
            data-testid="filter-clear"
            :disabled="
              tickets.activeFilterCount === 0 &&
              tickets.sort === 'created_at' &&
              tickets.direction === 'desc' &&
              tickets.page === 1
            "
            @click="tickets.clearAll"
            class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 text-xs font-semibold text-slate-600 shadow-2xs transition-all hover:bg-slate-50 hover:text-slate-900 disabled:opacity-40 disabled:cursor-not-allowed"
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
                d="M6 18L18 6M6 6l12 12"
              />
            </svg>
            <span>Clear</span>
            <span
              v-if="tickets.activeFilterCount > 0"
              data-testid="filter-active-count"
              class="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-indigo-100 px-1 text-[10px] font-bold text-indigo-700"
            >
              {{ tickets.activeFilterCount }}
            </span>
          </button>
        </div>
      </div>
    </div>
  </section>
</template>
