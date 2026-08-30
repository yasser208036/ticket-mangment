<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'
import { useUsersStore } from '../stores/users'
import { readableTextColor } from '../lib/color'
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

/** Toggle an id in/out of a filter array and re-fetch */
function toggleFilter(arr: number[], id: number): void {
  const idx = arr.indexOf(id)
  if (idx === -1) {
    arr.push(id)
  } else {
    arr.splice(idx, 1)
  }
  apply()
}

/** Convenience wrapper so the template can call it without explicit import */
function getTextColor(hex: string): string {
  return readableTextColor(hex)
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
      <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
        <!-- Status Filter — toggle pill chips -->
        <div class="flex flex-col gap-1.5">
          <label
            class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >Status</label
          >
          <div data-testid="filter-status" class="flex flex-wrap gap-1.5">
            <button
              v-for="status in masterData.statuses"
              :key="status.id"
              type="button"
              @click="toggleFilter(tickets.statusIds, status.id)"
              :aria-pressed="tickets.statusIds.includes(status.id)"
              class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-all select-none"
              :class="
                tickets.statusIds.includes(status.id)
                  ? 'border-transparent shadow-sm ring-2 ring-offset-1'
                  : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'
              "
              :style="
                tickets.statusIds.includes(status.id)
                  ? {
                      backgroundColor: status.color,
                      color: getTextColor(status.color),
                      '--tw-ring-color': status.color + '55',
                    }
                  : {}
              "
            >
              <span
                class="h-1.5 w-1.5 shrink-0 rounded-full"
                :style="{
                  backgroundColor: tickets.statusIds.includes(status.id)
                    ? getTextColor(status.color)
                    : status.color,
                }"
              />
              {{ status.name }}
            </button>
          </div>
        </div>

        <!-- Priority Filter — toggle pill chips -->
        <div class="flex flex-col gap-1.5">
          <label
            class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >Priority</label
          >
          <div data-testid="filter-priority" class="flex flex-wrap gap-1.5">
            <button
              v-for="priority in masterData.priorities"
              :key="priority.id"
              type="button"
              @click="toggleFilter(tickets.priorityIds, priority.id)"
              :aria-pressed="tickets.priorityIds.includes(priority.id)"
              class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-all select-none"
              :class="
                tickets.priorityIds.includes(priority.id)
                  ? 'border-transparent shadow-sm ring-2 ring-offset-1'
                  : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'
              "
              :style="
                tickets.priorityIds.includes(priority.id)
                  ? {
                      backgroundColor: priority.color,
                      color: getTextColor(priority.color),
                      '--tw-ring-color': priority.color + '55',
                    }
                  : {}
              "
            >
              <span
                class="h-1.5 w-1.5 shrink-0 rounded-full"
                :style="{
                  backgroundColor: tickets.priorityIds.includes(priority.id)
                    ? getTextColor(priority.color)
                    : priority.color,
                }"
              />
              {{ priority.name }}
            </button>
          </div>
        </div>

        <!-- Category Filter — toggle pill chips -->
        <div class="flex flex-col gap-1.5">
          <label
            class="text-[11px] font-bold uppercase tracking-wider text-slate-500"
            >Category</label
          >
          <div data-testid="filter-category" class="flex flex-wrap gap-1.5">
            <button
              v-for="category in masterData.categories"
              :key="category.id"
              type="button"
              @click="toggleFilter(tickets.categoryIds, category.id)"
              :aria-pressed="tickets.categoryIds.includes(category.id)"
              class="inline-flex items-center rounded-md border px-2.5 py-1 text-xs font-medium tracking-tight transition-all select-none"
              :class="
                tickets.categoryIds.includes(category.id)
                  ? 'border-transparent shadow-sm ring-2 ring-offset-1'
                  : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:bg-slate-50'
              "
              :style="
                tickets.categoryIds.includes(category.id)
                  ? {
                      backgroundColor: category.color,
                      color: getTextColor(category.color),
                      '--tw-ring-color': category.color + '55',
                    }
                  : {}
              "
            >
              {{ category.name }}
            </button>
          </div>
        </div>

        <!-- Single selects group — pushed to the right on wide screens -->
        <div class="flex flex-wrap items-end gap-3 sm:ml-auto">
          <!-- Assignee -->
          <div v-if="!auth.isEndUser" class="flex flex-col gap-1">
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
              <option value="">All</option>
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
