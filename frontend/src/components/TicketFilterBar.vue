<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'
import { useUsersStore } from '../stores/users'
import TicketFilterChips from './TicketFilterChips.vue'
import UiIcon from './ui/UiIcon.vue'
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

onMounted(() => {
  if (auth.isAdmin) {
    users.perPage = 100
    void users.load()
  }
})
</script>

<template>
  <section aria-label="Ticket filters" class="ui-card space-y-4 p-4">
    <div class="relative flex items-center">
      <UiIcon
        name="search"
        class="pointer-events-none absolute left-3 text-ink-400"
      />
      <input
        v-model="search"
        data-testid="filter-search"
        type="search"
        placeholder="Search by reference, subject or requester…"
        aria-label="Search tickets"
        class="ui-input pl-9"
      />
    </div>

    <div class="flex flex-wrap items-start gap-x-6 gap-y-4">
      <TicketFilterChips
        label="Status"
        testid="filter-status"
        :options="masterData.statuses"
        :selected="tickets.statusIds"
        @toggle="toggleFilter(tickets.statusIds, $event)"
      />
      <TicketFilterChips
        label="Priority"
        testid="filter-priority"
        :options="masterData.priorities"
        :selected="tickets.priorityIds"
        @toggle="toggleFilter(tickets.priorityIds, $event)"
      />
      <TicketFilterChips
        label="Category"
        testid="filter-category"
        :options="masterData.categories"
        :selected="tickets.categoryIds"
        @toggle="toggleFilter(tickets.categoryIds, $event)"
      />

      <div class="flex flex-wrap items-end gap-2.5 sm:ml-auto">
        <label v-if="!auth.isEndUser" class="flex flex-col gap-1.5">
          <span class="ui-eyebrow">Assignee</span>
          <select
            v-model="tickets.assignedTo"
            data-testid="filter-assignee"
            class="ui-select w-auto py-1.5 text-xs"
            @change="apply"
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
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="ui-eyebrow">Escalation</span>
          <select
            :value="tickets.escalated === null ? '' : String(tickets.escalated)"
            data-testid="filter-escalated"
            class="ui-select w-auto py-1.5 text-xs"
            @change="changeEscalation"
          >
            <option value="">All</option>
            <option value="true">Escalated</option>
            <option value="false">Not escalated</option>
          </select>
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="ui-eyebrow">Sort by</span>
          <select
            :value="tickets.sort"
            data-testid="filter-sort"
            class="ui-select w-auto py-1.5 text-xs"
            @change="changeSort"
          >
            <option value="created_at">Created</option>
            <option value="updated_at">Updated</option>
            <option value="priority">Priority</option>
            <option value="relevance">Relevance</option>
            <option value="escalated_at">Recently escalated</option>
          </select>
        </label>

        <label class="flex flex-col gap-1.5">
          <span class="ui-eyebrow sr-only">Direction</span>
          <select
            :value="tickets.direction"
            data-testid="filter-direction"
            class="ui-select w-auto py-1.5 text-xs"
            @change="changeDirection"
          >
            <option value="desc">Descending</option>
            <option value="asc">Ascending</option>
          </select>
        </label>

        <button
          type="button"
          data-testid="filter-clear"
          class="ui-btn ui-btn-secondary ui-btn-sm py-1.5"
          :disabled="
            tickets.activeFilterCount === 0 &&
            tickets.sort === 'created_at' &&
            tickets.direction === 'desc' &&
            tickets.page === 1
          "
          @click="tickets.clearAll"
        >
          <UiIcon name="close" class="h-3.5 w-3.5" />
          Clear
          <span
            v-if="tickets.activeFilterCount > 0"
            data-testid="filter-active-count"
            class="tabular inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-100 px-1 text-[10px] font-semibold text-brand-700"
          >
            {{ tickets.activeFilterCount }}
          </span>
        </button>
      </div>
    </div>
  </section>
</template>
