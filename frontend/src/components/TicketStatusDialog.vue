<script setup lang="ts">
import { computed, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import { REOPENED_SLUG, RESOLVED_SLUG } from '../api/statuses'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const store = useTicketsStore()

const selected = ref<number | undefined>(undefined)
const resolution = ref('')
const reason = ref('')
const error = ref('')
const errors = ref<Record<string, string[]>>({})

const selectedStatus = computed(() =>
  props.ticket.allowed_transitions.find(
    (status) => status.id === selected.value,
  ),
)
const needsResolution = computed(
  () => selectedStatus.value?.slug === RESOLVED_SLUG,
)
const needsReason = computed(() => selectedStatus.value?.slug === REOPENED_SLUG)
// Courtesy checks only -- the server owns 10-5000 for both and still enforces
// it if either is bypassed. Neither ref is cleared when the selection
// changes: an agent who picks Resolved or Reopened, types something, and
// glances at another option first should not lose it.
const noteTooShort = computed(
  () => needsResolution.value && resolution.value.trim().length < 10,
)
const reasonTooShort = computed(
  () => needsReason.value && reason.value.trim().length < 10,
)

async function confirm(): Promise<void> {
  if (selected.value === undefined) return
  errors.value = {}
  error.value = ''
  try {
    await store.changeStatus(props.ticket.id, {
      status_id: selected.value,
      ...(needsResolution.value ? { resolution: resolution.value } : {}),
      ...(needsReason.value ? { reason: reason.value } : {}),
    })
    emit('changed')
  } catch (caughtError) {
    // AC5: the server's own message, not a generic one. validationErrors first
    // -- errorMessage() would return the envelope's top-level `message`, which
    // for a 422 is Laravel's summary, not TicketWorkflow's sentence.
    errors.value = validationErrors(caughtError)
    error.value = Object.keys(errors.value).length
      ? ''
      : errorMessage(caughtError)
  }
}
</script>

<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in fade-in duration-150"
    data-testid="ticket-status-dialog"
  >
    <div
      class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl space-y-4 animate-in zoom-in-95 duration-150"
    >
      <h3 class="text-base font-bold text-slate-900">Change status</h3>

      <div v-if="ticket.allowed_transitions.length" class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700">New status</label>
        <select
          v-model.number="selected"
          data-testid="ticket-status-select"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        >
          <option :value="undefined" disabled>Select a status</option>
          <option
            v-for="status in ticket.allowed_transitions"
            :key="status.id"
            :value="status.id"
          >
            {{ status.name }}
          </option>
        </select>
      </div>
      <p
        v-else
        data-testid="ticket-status-empty"
        class="text-sm text-slate-500"
      >
        No status changes are available from here.
      </p>

      <div v-if="needsResolution" class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700">Resolution</label>
        <textarea
          v-model="resolution"
          data-testid="ticket-status-resolution"
          rows="3"
          placeholder="How was this resolved?"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
        <p
          v-if="noteTooShort"
          data-testid="ticket-status-resolution-hint"
          class="text-xs text-slate-500"
        >
          At least 10 characters.
        </p>
        <p
          v-if="errors.resolution"
          data-testid="ticket-status-resolution-error"
          class="text-xs font-medium text-rose-600"
        >
          {{ errors.resolution[0] }}
        </p>
      </div>

      <div v-if="needsReason" class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700">Reason</label>
        <textarea
          v-model="reason"
          data-testid="ticket-status-reason"
          rows="3"
          placeholder="What brought this ticket back?"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
        <p
          v-if="reasonTooShort"
          data-testid="ticket-status-reason-hint"
          class="text-xs text-slate-500"
        >
          At least 10 characters.
        </p>
        <p
          v-if="errors.reason"
          data-testid="ticket-status-reason-error"
          class="text-xs font-medium text-rose-600"
        >
          {{ errors.reason[0] }}
        </p>
      </div>

      <p
        v-if="error || errors.status_id"
        data-testid="ticket-status-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ errors.status_id?.[0] ?? error }}
      </p>

      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          data-testid="ticket-status-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          data-testid="ticket-status-confirm"
          :disabled="
            selected === undefined ||
            noteTooShort ||
            reasonTooShort ||
            store.changingStatus
          "
          @click="confirm"
          class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors disabled:opacity-50"
        >
          Confirm
        </button>
      </div>
    </div>
  </div>
</template>
