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
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiIcon from '../components/ui/UiIcon.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPanel from '../components/ui/UiPanel.vue'
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
  <main class="mx-auto max-w-7xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <RouterLink
      :to="{ name: 'tickets' }"
      class="ui-focus inline-flex items-center gap-1.5 rounded text-xs font-medium text-ink-500 transition-colors hover:text-ink-900"
    >
      <UiIcon name="arrow-left" class="h-3.5 w-3.5" />
      Back to tickets
    </RouterLink>

    <UiLoadingPanel
      v-if="store.detailLoading"
      shape="card"
      label="Loading ticket"
      testid="ticket-loading"
      :rows="4"
    />

    <UiEmptyState
      v-else-if="store.detailNotFound"
      icon="alert-triangle"
      title="Ticket not found"
      description="It may have been deleted."
      testid="ticket-not-found"
    >
      <UiButton
        variant="primary"
        :to="{ name: 'tickets' }"
        data-testid="ticket-not-found-back"
      >
        Back to tickets
      </UiButton>
    </UiEmptyState>

    <UiEmptyState
      v-else-if="store.escalatedOutOfView"
      icon="alert-triangle"
      title="Ticket escalated"
      description="It has been reassigned to an administrator and is no longer in your queue."
      testid="ticket-escalated-away"
    >
      <UiButton
        variant="primary"
        :to="{ name: 'tickets' }"
        data-testid="ticket-escalated-away-back"
      >
        Back to tickets
      </UiButton>
    </UiEmptyState>

    <UiAlert v-else-if="store.detailError" data-testid="ticket-error">
      {{ store.detailError }}
    </UiAlert>

    <article
      v-else-if="store.current"
      data-testid="ticket-detail"
      class="space-y-5"
    >
      <div class="ui-card ui-card-pad">
        <div
          class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start"
        >
          <div class="min-w-0 space-y-2.5">
            <div class="flex items-center gap-2.5">
              <span class="font-mono text-xs font-medium text-ink-500">
                {{ store.current.reference }}
              </span>
              <span
                v-if="store.current.reopen_count > 0"
                data-testid="ticket-reopen-count"
                class="ui-chip"
              >
                Reopened {{ store.current.reopen_count }}
                {{ store.current.reopen_count === 1 ? 'time' : 'times' }}
              </span>
            </div>

            <h1 class="ui-title">{{ store.current.subject }}</h1>

            <div class="flex flex-wrap items-center gap-1.5">
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
          </div>

          <TicketActionToolbar
            :ticket="store.current"
            class="shrink-0"
            @assign="assignOpen = true"
            @edit="editOpen = true"
            @escalate="escalateOpen = true"
            @status="statusOpen = true"
            @request-assignment="requestAssignmentOpen = true"
            @delete="deleteOpen = true"
          />
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

      <UiAlert
        v-if="store.current.resolution"
        tone="success"
        title="Resolution"
        data-testid="ticket-resolution"
      >
        <p data-testid="ticket-resolution-note" class="whitespace-pre-wrap">
          {{ store.current.resolution.note }}
        </p>
        <p class="mt-1 text-xs">
          <span data-testid="ticket-resolution-by">
            {{ store.current.resolution.by?.name || 'System' }}
          </span>
          <template v-if="store.current.resolution.at">
            ·
            <span :title="store.current.resolution.at">
              {{ relativeAge(store.current.resolution.at) }}
            </span>
          </template>
        </p>
      </UiAlert>

      <UiAlert
        v-if="store.current.escalation_level > 0"
        tone="warning"
        data-testid="ticket-escalation"
      >
        <p data-testid="ticket-escalation-level" class="font-semibold">
          Escalated Ticket (Level {{ store.current.escalation_level }})
        </p>
        <p class="mt-0.5 whitespace-pre-wrap">
          {{ store.current.escalation_reason }}
          {{ store.current.escalated_by?.name }}
        </p>
      </UiAlert>

      <UiAlert
        v-if="store.current.my_pending_assignment_request"
        tone="info"
        data-testid="ticket-pending-assignment-request"
      >
        You asked for this ticket. An administrator is reviewing it.
      </UiAlert>

      <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
          <UiPanel title="Description">
            <p
              class="whitespace-pre-wrap px-5 py-4 text-sm leading-relaxed text-ink-800 sm:px-6"
            >
              {{ store.current.description }}
            </p>
          </UiPanel>

          <UiPanel title="Milestones">
            <dl
              class="grid divide-y divide-line sm:grid-cols-3 sm:divide-x sm:divide-y-0"
            >
              <div class="px-5 py-4 sm:px-6">
                <dt class="ui-eyebrow">First responded</dt>
                <dd
                  v-if="store.current.first_responded_at"
                  data-testid="ticket-first-responded"
                  class="mt-1 text-sm font-medium text-ink-900"
                  :title="store.current.first_responded_at"
                >
                  {{ relativeAge(store.current.first_responded_at) }}
                </dd>
                <dd v-else class="mt-1 text-sm text-ink-400">Not yet</dd>
              </div>
              <div class="px-5 py-4 sm:px-6">
                <dt class="ui-eyebrow">Resolved</dt>
                <dd
                  v-if="store.current.resolved_at"
                  data-testid="ticket-resolved"
                  class="mt-1 text-sm font-medium text-ink-900"
                  :title="store.current.resolved_at"
                >
                  {{ relativeAge(store.current.resolved_at) }}
                </dd>
                <dd v-else class="mt-1 text-sm text-ink-400">Pending</dd>
              </div>
              <div class="px-5 py-4 sm:px-6">
                <dt class="ui-eyebrow">Closed</dt>
                <dd
                  v-if="store.current.closed_at"
                  data-testid="ticket-closed"
                  class="mt-1 text-sm font-medium text-ink-900"
                  :title="store.current.closed_at"
                >
                  {{ relativeAge(store.current.closed_at) }}
                </dd>
                <dd v-else class="mt-1 text-sm text-ink-400">Open</dd>
              </div>
            </dl>
          </UiPanel>
        </div>

        <div class="space-y-5">
          <UiPanel title="Requester">
            <div class="flex items-center gap-3 px-5 py-4 sm:px-6">
              <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-ink-100 text-sm font-semibold text-ink-600"
                aria-hidden="true"
              >
                {{ store.current.requester.name.charAt(0).toUpperCase() }}
              </span>
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-ink-900">
                  {{ store.current.requester.name }}
                </p>
                <p class="truncate text-xs text-ink-500">
                  {{ store.current.requester.email }}
                </p>
              </div>
            </div>
          </UiPanel>

          <UiPanel title="Assignment &amp; audit">
            <dl class="divide-y divide-line text-xs">
              <div
                class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
              >
                <dt class="text-ink-500">Assignee</dt>
                <dd class="text-right font-medium text-ink-900">
                  {{ store.current.assignee?.name || 'Unassigned' }}
                </dd>
              </div>
              <div
                class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
              >
                <dt class="text-ink-500">Creator</dt>
                <dd class="text-right font-medium text-ink-900">
                  {{ store.current.creator?.name }}
                </dd>
              </div>
              <div
                class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
              >
                <dt class="text-ink-500">Created</dt>
                <dd
                  class="text-right font-medium text-ink-900"
                  :title="store.current.created_at"
                >
                  {{ relativeAge(store.current.created_at) }}
                </dd>
              </div>
              <div
                class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
              >
                <dt class="text-ink-500">Updated</dt>
                <dd
                  class="text-right font-medium text-ink-900"
                  :title="store.current.updated_at"
                >
                  {{ relativeAge(store.current.updated_at) }}
                </dd>
              </div>
            </dl>
          </UiPanel>
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
