<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CategoryBadge from '../components/CategoryBadge.vue'
import ColorBadge from '../components/ColorBadge.vue'
import TicketActionToolbar from '../components/TicketActionToolbar.vue'
import TicketAssignDialog from '../components/TicketAssignDialog.vue'
import TicketDeleteDialog from '../components/TicketDeleteDialog.vue'
import TicketEditDialog from '../components/TicketEditDialog.vue'
import TicketEscalateDialog from '../components/TicketEscalateDialog.vue'
import TicketNoteComposer from '../components/TicketNoteComposer.vue'
import TicketRequestAssignmentDialog from '../components/TicketRequestAssignmentDialog.vue'
import TicketStatusDialog from '../components/TicketStatusDialog.vue'
import TicketTimeline from '../components/TicketTimeline.vue'
import { relativeAge } from '../lib/relativeTime'
import { REOPENED_SLUG } from '../api/statuses'
import { useTicketsStore } from '../stores/tickets'

const route = useRoute()
const router = useRouter()
const store = useTicketsStore()
const assignOpen = ref(false)
const editOpen = ref(false)
const escalateOpen = ref(false)
const statusOpen = ref(false)
const requestAssignmentOpen = ref(false)
const deleteOpen = ref(false)

const load = () => {
  const id = Number(route.params.id)
  void store.loadTicket(id)
  void store.loadActivities(id)
}
onMounted(load)
watch(() => route.params.id, load)

async function onDeleted(): Promise<void> {
  deleteOpen.value = false
  // replace, not push -- the deleted ticket's URL must not sit in history,
  // or Back would only land on its not-found state.
  await router.replace({ name: 'tickets' })
}
</script>

<template>
  <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Back to tickets nav -->
    <div>
      <RouterLink
        :to="{ name: 'tickets' }"
        class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition-colors hover:text-indigo-600"
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
            d="M10 19l-7-7m0 0l7-7m-7 7h18"
          />
        </svg>
        <span>Back to tickets</span>
      </RouterLink>
    </div>

    <!-- Loading State -->
    <div
      v-if="store.detailLoading"
      data-testid="ticket-loading"
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
        <span>Loading ticket details...</span>
      </div>
    </div>

    <!-- Not Found State -->
    <section
      v-else-if="store.detailNotFound"
      data-testid="ticket-not-found"
      class="flex flex-col items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-12 text-center shadow-sm"
    >
      <div
        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600"
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
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
          />
        </svg>
      </div>
      <h2 class="mt-4 text-lg font-bold text-slate-900">Ticket not found</h2>
      <p class="mt-1 text-sm text-slate-500">
        Ticket not found. It may have been deleted.
      </p>
      <RouterLink
        :to="{ name: 'tickets' }"
        data-testid="ticket-not-found-back"
        class="mt-6 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
      >
        Back to tickets
      </RouterLink>
    </section>

    <!-- Escalated Out of View State -->
    <section
      v-else-if="store.escalatedOutOfView"
      data-testid="ticket-escalated-away"
      class="flex flex-col items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-12 text-center shadow-sm"
    >
      <div
        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600"
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
            d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
          />
        </svg>
      </div>
      <h2 class="mt-4 text-lg font-bold text-slate-900">Ticket escalated</h2>
      <p class="mt-1 text-sm text-slate-500">
        It has been reassigned to an administrator and is no longer in your
        queue.
      </p>
      <RouterLink
        :to="{ name: 'tickets' }"
        data-testid="ticket-escalated-away-back"
        class="mt-6 inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700"
      >
        Back to tickets
      </RouterLink>
    </section>

    <!-- Error State -->
    <div
      v-else-if="store.detailError"
      data-testid="ticket-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm"
    >
      {{ store.detailError }}
    </div>

    <!-- Ticket Detail Article -->
    <article
      v-else-if="store.current"
      data-testid="ticket-detail"
      class="space-y-6"
    >
      <!-- Header Card -->
      <div
        class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm"
      >
        <div
          class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center"
        >
          <div class="space-y-2">
            <div class="flex flex-wrap items-center gap-2.5">
              <span
                class="font-mono text-sm font-bold text-indigo-600 bg-indigo-50 px-2.5 py-1 rounded-lg"
              >
                {{ store.current.reference }}
              </span>
              <CategoryBadge :category="store.current.category" />
              <ColorBadge
                :name="store.current.priority.name"
                :color="store.current.priority.color"
              />
              <ColorBadge
                :name="store.current.status.name"
                :color="store.current.status.color"
              />
              <ColorBadge
                v-if="store.current.status.slug === REOPENED_SLUG"
                data-testid="ticket-reopened-badge"
                name="Reopened"
                :color="store.current.status.color"
              />
            </div>
            <p
              v-if="store.current.reopen_count > 0"
              data-testid="ticket-reopen-count"
              class="text-xs text-slate-500"
            >
              Reopened {{ store.current.reopen_count }}
              {{ store.current.reopen_count === 1 ? 'time' : 'times' }}
            </p>
            <h1
              class="text-xl font-extrabold tracking-tight text-slate-900 sm:text-2xl"
            >
              {{ store.current.reference }}
            </h1>
            <h2 class="text-base font-medium text-slate-600">
              {{ store.current.subject }}
            </h2>
          </div>

          <div class="shrink-0">
            <TicketActionToolbar
              :ticket="store.current"
              @assign="assignOpen = true"
              @edit="editOpen = true"
              @escalate="escalateOpen = true"
              @status="statusOpen = true"
              @request-assignment="requestAssignmentOpen = true"
              @delete="deleteOpen = true"
            />
          </div>
        </div>
      </div>

      <TicketAssignDialog
        v-if="assignOpen && store.current"
        :ticket="store.current"
        @assigned="assignOpen = false"
        @close="assignOpen = false"
      />

      <TicketEditDialog
        v-if="editOpen && store.current"
        :ticket="store.current"
        @saved="editOpen = false"
        @close="editOpen = false"
      />

      <TicketDeleteDialog
        v-if="deleteOpen && store.current"
        :ticket="store.current"
        @deleted="onDeleted"
        @close="deleteOpen = false"
      />

      <TicketEscalateDialog
        v-if="escalateOpen && store.current"
        :ticket="store.current"
        @escalated="escalateOpen = false"
        @close="escalateOpen = false"
      />

      <TicketRequestAssignmentDialog
        v-if="requestAssignmentOpen && store.current"
        :ticket="store.current"
        @requested="requestAssignmentOpen = false"
        @close="requestAssignmentOpen = false"
      />

      <TicketStatusDialog
        v-if="statusOpen && store.current"
        :ticket="store.current"
        @changed="statusOpen = false"
        @close="statusOpen = false"
      />

      <!-- Resolution Block -->
      <section
        v-if="store.current.resolution"
        data-testid="ticket-resolution"
        class="flex items-start gap-3 rounded-2xl border border-emerald-200 bg-emerald-50/80 p-5 text-emerald-900 shadow-xs"
      >
        <svg
          class="h-5 w-5 shrink-0 text-emerald-600 mt-0.5"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <div class="space-y-1">
          <h4
            class="text-xs font-bold uppercase tracking-wider text-emerald-800"
          >
            Resolution
          </h4>
          <p data-testid="ticket-resolution-note" class="text-sm font-medium">
            {{ store.current.resolution.note }}
          </p>
          <p class="text-xs text-emerald-700">
            <span data-testid="ticket-resolution-by">{{
              store.current.resolution.by?.name || 'System'
            }}</span>
            <template v-if="store.current.resolution.at">
              ·
              <span :title="store.current.resolution.at">{{
                relativeAge(store.current.resolution.at)
              }}</span>
            </template>
          </p>
        </div>
      </section>

      <!-- Escalation Alert Banner -->
      <section
        v-if="store.current.escalation_level > 0"
        data-testid="ticket-escalation"
        class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50/80 p-5 text-amber-900 shadow-xs"
      >
        <svg
          class="h-5 w-5 shrink-0 text-amber-600 mt-0.5"
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
        <div class="space-y-1">
          <h4
            data-testid="ticket-escalation-level"
            class="text-xs font-bold uppercase tracking-wider text-amber-800"
          >
            Escalated Ticket (Level {{ store.current.escalation_level }})
          </h4>
          <p class="text-sm font-medium">
            {{ store.current.escalation_reason }}
            {{ store.current.escalated_by?.name }}
          </p>
        </div>
      </section>

      <!-- Pending Assignment Request Banner -->
      <section
        v-if="store.current.my_pending_assignment_request"
        data-testid="ticket-pending-assignment-request"
        class="flex items-start gap-3 rounded-2xl border border-indigo-200 bg-indigo-50/80 p-5 text-indigo-900 shadow-xs"
      >
        <svg
          class="h-5 w-5 shrink-0 text-indigo-600 mt-0.5"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m1.586-9.414a2 2 0 112.828 2.828L12.828 15H10v-2.828l8.586-8.586z"
          />
        </svg>
        <p class="text-sm font-medium">
          You asked for this ticket. An administrator is reviewing it.
        </p>
      </section>

      <!-- 2-Column Content Grid -->
      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Main Column (Description & Lifecycle) -->
        <div class="space-y-6 lg:col-span-2">
          <!-- Description Card -->
          <div
            class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-3"
          >
            <h3
              class="text-xs font-bold uppercase tracking-wider text-slate-400"
            >
              Description
            </h3>
            <p
              class="whitespace-pre-wrap text-sm leading-relaxed text-slate-800 font-normal"
            >
              {{ store.current.description }}
            </p>
          </div>

          <!-- Lifecycle / Response Milestones -->
          <div
            class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-4"
          >
            <h3
              class="text-xs font-bold uppercase tracking-wider text-slate-400"
            >
              Lifecycle Milestones
            </h3>
            <div class="grid gap-4 sm:grid-cols-3 text-xs">
              <div class="rounded-xl bg-slate-50 p-3.5 border border-slate-100">
                <span class="text-slate-500 font-medium">First Responded</span>
                <p
                  v-if="store.current.first_responded_at"
                  data-testid="ticket-first-responded"
                  class="mt-1 font-semibold text-slate-800"
                >
                  {{ relativeAge(store.current.first_responded_at) }}
                </p>
                <p v-else class="mt-1 text-slate-400 italic">Not yet</p>
              </div>

              <div class="rounded-xl bg-slate-50 p-3.5 border border-slate-100">
                <span class="text-slate-500 font-medium">Resolved</span>
                <p
                  v-if="store.current.resolved_at"
                  data-testid="ticket-resolved"
                  class="mt-1 font-semibold text-slate-800"
                >
                  {{ relativeAge(store.current.resolved_at) }}
                </p>
                <p v-else class="mt-1 text-slate-400 italic">Pending</p>
              </div>

              <div class="rounded-xl bg-slate-50 p-3.5 border border-slate-100">
                <span class="text-slate-500 font-medium">Closed</span>
                <p
                  v-if="store.current.closed_at"
                  data-testid="ticket-closed"
                  class="mt-1 font-semibold text-slate-800"
                >
                  {{ relativeAge(store.current.closed_at) }}
                </p>
                <p v-else class="mt-1 text-slate-400 italic">Open</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Sidebar Column (Requester, Assignee, Meta) -->
        <div class="space-y-6">
          <!-- Requester Card -->
          <div
            class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-4"
          >
            <h3
              class="text-xs font-bold uppercase tracking-wider text-slate-400"
            >
              Requester
            </h3>
            <div class="flex items-center gap-3">
              <div
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-sm font-bold text-indigo-700"
              >
                {{ store.current.requester.name.charAt(0).toUpperCase() }}
              </div>
              <div class="min-w-0">
                <p class="truncate text-sm font-bold text-slate-900">
                  {{ store.current.requester.name }}
                </p>
                <p
                  v-if="store.current.requester.company"
                  class="truncate text-xs text-slate-500"
                >
                  {{ store.current.requester.email }}
                </p>
              </div>
            </div>

            <!-- <div
              v-if="store.current.requester.phone"
              class="border-t border-slate-100 pt-3 text-xs text-slate-600"
            >
              <span class="font-medium text-slate-400">Phone: </span
              >{{ store.current.requester.phone }}
            </div> -->
          </div>

          <!-- Assignment & Creator Card -->
          <div
            class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-4 text-xs"
          >
            <h3
              class="text-xs font-bold uppercase tracking-wider text-slate-400"
            >
              Assignment & Audit
            </h3>

            <div class="space-y-3">
              <div class="flex justify-between py-1 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Assignee:</span>
                <span class="font-semibold text-slate-800">
                  Assignee: {{ store.current.assignee?.name || 'Unassigned' }}
                </span>
              </div>

              <div class="flex justify-between py-1 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Creator:</span>
                <span class="font-semibold text-slate-800">
                  Creator: {{ store.current.creator?.name }}
                </span>
              </div>

              <div class="flex justify-between py-1 border-b border-slate-100">
                <span class="text-slate-500 font-medium">Created:</span>
                <span
                  class="font-semibold text-slate-800"
                  :title="store.current.created_at"
                >
                  {{ relativeAge(store.current.created_at) }}
                </span>
              </div>

              <div class="flex justify-between py-1">
                <span class="text-slate-500 font-medium">Updated:</span>
                <span
                  class="font-semibold text-slate-800"
                  :title="store.current.updated_at"
                >
                  {{ relativeAge(store.current.updated_at) }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <TicketNoteComposer
        v-if="store.current.can.add_note"
        :ticket-id="store.current.id"
      />

      <TicketTimeline />
    </article>
  </main>
</template>
