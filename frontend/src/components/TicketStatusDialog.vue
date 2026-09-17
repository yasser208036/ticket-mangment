<script setup lang="ts">
import { computed, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import { REOPENED_SLUG, RESOLVED_SLUG } from '../api/statuses'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'

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
  <BaseDialog
    testid="ticket-status-dialog"
    title="Change status"
    icon="refresh"
    @close="emit('close')"
  >
    <UiField
      v-if="ticket.allowed_transitions.length"
      v-slot="field"
      label="New status"
    >
      <select
        v-bind="field"
        v-model.number="selected"
        data-testid="ticket-status-select"
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
    </UiField>
    <p v-else data-testid="ticket-status-empty" class="text-sm text-ink-500">
      No status changes are available from here.
    </p>

    <UiField
      v-if="needsResolution"
      v-slot="field"
      label="Resolution"
      :hint="noteTooShort ? 'At least 10 characters.' : undefined"
      hint-testid="ticket-status-resolution-hint"
      :error="errors.resolution"
      error-testid="ticket-status-resolution-error"
    >
      <textarea
        v-bind="field"
        v-model="resolution"
        data-testid="ticket-status-resolution"
        rows="3"
        placeholder="How was this resolved?"
      />
    </UiField>

    <UiField
      v-if="needsReason"
      v-slot="field"
      label="Reason"
      :hint="reasonTooShort ? 'At least 10 characters.' : undefined"
      hint-testid="ticket-status-reason-hint"
      :error="errors.reason"
      error-testid="ticket-status-reason-error"
    >
      <textarea
        v-bind="field"
        v-model="reason"
        data-testid="ticket-status-reason"
        rows="3"
        placeholder="What brought this ticket back?"
      />
    </UiField>

    <UiAlert v-if="error || errors.status_id" data-testid="ticket-status-error">
      {{ errors.status_id?.[0] ?? error }}
    </UiAlert>

    <template #footer>
      <UiButton data-testid="ticket-status-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        data-testid="ticket-status-confirm"
        :disabled="selected === undefined || noteTooShort || reasonTooShort"
        :loading="store.changingStatus"
        @click="confirm"
      >
        Confirm
      </UiButton>
    </template>
  </BaseDialog>
</template>
