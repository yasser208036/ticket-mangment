<script setup lang="ts">
import { onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CategoryBadge from '../components/CategoryBadge.vue'
import ColorBadge from '../components/ColorBadge.vue'
import EscalationBadge from '../components/EscalationBadge.vue'
import TicketFilterBar from '../components/TicketFilterBar.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import UiPagination from '../components/ui/UiPagination.vue'
import UiSortButton from '../components/ui/UiSortButton.vue'
import { relativeAge } from '../lib/relativeTime'
import { fromQuery, toQuery } from '../lib/ticketQuery'
import { useAuthStore } from '../stores/auth'
import { useTicketsStore } from '../stores/tickets'

const store = useTicketsStore()
const auth = useAuthStore()
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
  <main class="mx-auto max-w-7xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader title="Tickets">
      <template #badge>
        <span v-if="store.meta" class="ui-chip tabular">
          {{ store.meta.total }} total
        </span>
      </template>
      <template v-if="auth.isEndUser" #actions>
        <UiButton variant="primary" icon="plus" :to="{ name: 'new-ticket' }">
          New ticket
        </UiButton>
      </template>
    </UiPageHeader>

    <TicketFilterBar />

    <UiLoadingPanel
      v-if="store.loading"
      label="Loading tickets"
      testid="tickets-loading"
      :rows="6"
    />

    <UiAlert v-else-if="store.error" data-testid="tickets-error">
      {{ store.error }}
    </UiAlert>

    <template v-else-if="store.items.length">
      <div class="ui-card hidden overflow-hidden md:block">
        <div class="overflow-x-auto">
          <table class="ui-table" data-testid="tickets-table">
            <thead class="ui-thead">
              <tr>
                <th scope="col" class="ui-th">Reference</th>
                <th scope="col" class="ui-th">Subject</th>
                <th scope="col" class="ui-th">Requester</th>
                <th scope="col" class="ui-th">Category</th>
                <th scope="col" class="ui-th" :aria-sort="ariaSort('priority')">
                  <UiSortButton
                    label="Priority"
                    data-testid="sort-priority"
                    :active="store.sort === 'priority'"
                    :direction="store.direction"
                    @click="toggleSort('priority')"
                  />
                </th>
                <th
                  scope="col"
                  class="ui-th"
                  :aria-sort="ariaSort('escalated_at')"
                >
                  <UiSortButton
                    label="Escalated"
                    data-testid="sort-escalated_at"
                    :active="store.sort === 'escalated_at'"
                    :direction="store.direction"
                    @click="toggleSort('escalated_at')"
                  />
                </th>
                <th scope="col" class="ui-th">Status</th>
                <th scope="col" class="ui-th">Assignee</th>
                <th
                  scope="col"
                  class="ui-th"
                  :aria-sort="ariaSort('created_at')"
                >
                  <UiSortButton
                    label="Age"
                    data-testid="sort-created_at"
                    :active="store.sort === 'created_at'"
                    :direction="store.direction"
                    @click="toggleSort('created_at')"
                  />
                </th>
              </tr>
            </thead>
            <tbody class="ui-tbody">
              <tr
                v-for="ticket in store.items"
                :key="ticket.id"
                data-testid="tickets-row"
                class="ui-tr"
              >
                <td class="ui-td whitespace-nowrap">
                  <RouterLink
                    :to="{ name: 'ticket-detail', params: { id: ticket.id } }"
                    data-testid="tickets-reference-link"
                    class="ui-focus rounded font-mono text-xs font-medium text-brand-600 hover:text-brand-700 hover:underline"
                  >
                    {{ ticket.reference }}
                  </RouterLink>
                </td>

                <td class="ui-td max-w-xs truncate font-medium text-ink-900">
                  <RouterLink
                    :to="{ name: 'ticket-detail', params: { id: ticket.id } }"
                    class="ui-focus rounded transition-colors hover:text-brand-600"
                  >
                    {{ ticket.subject }}
                  </RouterLink>
                </td>

                <td class="ui-td whitespace-nowrap">
                  <span class="flex items-center gap-2">
                    <span
                      class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-ink-100 text-[10px] font-semibold text-ink-600"
                      aria-hidden="true"
                    >
                      {{ ticket.requester.name.charAt(0).toUpperCase() }}
                    </span>
                    <span class="truncate">{{ ticket.requester.name }}</span>
                  </span>
                </td>

                <td class="ui-td whitespace-nowrap">
                  <CategoryBadge :category="ticket.category" />
                </td>

                <td class="ui-td whitespace-nowrap">
                  <ColorBadge
                    :name="ticket.priority.name"
                    :color="ticket.priority.color"
                  />
                </td>

                <td
                  class="ui-td whitespace-nowrap"
                  data-testid="tickets-escalation"
                >
                  <EscalationBadge :level="ticket.escalation_level" />
                </td>

                <td class="ui-td whitespace-nowrap">
                  <ColorBadge
                    :name="ticket.status.name"
                    :color="ticket.status.color"
                  />
                </td>

                <td class="ui-td whitespace-nowrap text-xs">
                  <span
                    v-if="ticket.assignee"
                    class="flex items-center gap-1.5"
                  >
                    <span
                      class="h-1.5 w-1.5 rounded-full bg-emerald-500"
                      aria-hidden="true"
                    />
                    {{ ticket.assignee.name }}
                  </span>
                  <span v-else class="text-ink-400">Unassigned</span>
                </td>

                <td
                  class="ui-td tabular whitespace-nowrap text-xs text-ink-500"
                  data-testid="tickets-age"
                  :title="ticket.created_at"
                >
                  {{ relativeAge(ticket.created_at) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile card list -- a narrow table pushed to 8 columns of
         overflow-x scroll reads as a wall of horizontal noise on a phone,
         so under the same breakpoint the header collapses its own nav at
         (md), tickets become a stack of cards instead. Deliberately
         distinct testids from the table's own, so both can sit in the DOM
         at once (jsdom never hides a `md:hidden` element) without doubling
         what the table's tests count. -->
      <div class="flex flex-col gap-2.5 md:hidden">
        <RouterLink
          v-for="ticket in store.items"
          :key="ticket.id"
          :to="{ name: 'ticket-detail', params: { id: ticket.id } }"
          data-testid="tickets-card"
          class="ui-card ui-focus flex flex-col gap-2 p-3.5"
        >
          <div class="flex items-center gap-2">
            <span class="font-mono text-xs font-medium text-brand-600">
              {{ ticket.reference }}
            </span>
            <span
              class="tabular ml-auto text-xs text-ink-400"
              data-testid="tickets-card-age"
              :title="ticket.created_at"
            >
              {{ relativeAge(ticket.created_at) }}
            </span>
          </div>
          <p class="text-sm font-semibold text-ink-900">{{ ticket.subject }}</p>
          <p class="text-xs text-ink-500">
            {{ ticket.requester.name }} ·
            {{ ticket.assignee?.name ?? 'Unassigned' }}
          </p>
          <div class="flex flex-wrap items-center gap-1.5">
            <CategoryBadge :category="ticket.category" />
            <ColorBadge
              :name="ticket.priority.name"
              :color="ticket.priority.color"
            />
            <ColorBadge
              :name="ticket.status.name"
              :color="ticket.status.color"
            />
            <EscalationBadge
              :level="ticket.escalation_level"
              data-testid="tickets-card-escalation"
            />
          </div>
        </RouterLink>
      </div>

      <div class="ui-card overflow-hidden">
        <UiPagination
          :meta="store.meta"
          noun="tickets"
          testid-prefix="tickets"
          @go="store.goToPage($event)"
        >
          <template #extra>
            <label class="flex items-center gap-1.5 text-xs text-ink-500">
              Per page
              <select
                :value="store.perPage"
                data-testid="tickets-per-page"
                class="ui-select w-auto py-1 text-xs"
                @change="changePageSize"
              >
                <option v-for="size in pageSizes" :key="size" :value="size">
                  {{ size }}
                </option>
              </select>
            </label>
          </template>
        </UiPagination>
      </div>
    </template>

    <UiEmptyState
      v-else
      title="No tickets on this page."
      description="Try clearing the active search and filters, or file a new ticket to get started."
      testid="tickets-empty"
    />
  </main>
</template>
