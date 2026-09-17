<script setup lang="ts">
import { computed, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ requested: []; close: [] }>()
const store = useTicketsStore()

const note = ref('')
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const tooLong = computed(() => note.value.length > 500)

async function confirm(): Promise<void> {
  if (tooLong.value) return
  errors.value = {}
  error.value = ''
  try {
    await store.requestAssignment(props.ticket.id, note.value || undefined)
    emit('requested')
  } catch (caught) {
    errors.value = validationErrors(caught)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(caught)
  }
}
</script>

<template>
  <BaseDialog
    testid="ticket-request-assignment-dialog"
    title="Request this ticket"
    description="An administrator will review your request and assign it."
    icon="user-plus"
    @close="emit('close')"
  >
    <UiField
      v-slot="field"
      label="Note (optional)"
      :error="tooLong ? 'Keep the note to 500 characters or fewer.' : undefined"
      error-testid="ticket-request-assignment-hint"
    >
      <textarea
        v-bind="field"
        v-model="note"
        data-testid="ticket-request-assignment-note"
        rows="4"
        placeholder="Why should this be assigned to you?"
      />
    </UiField>
    <p class="-mt-2 text-right text-[11px] text-ink-400 tabular">
      {{ note.length }} / 500
    </p>

    <UiAlert
      v-if="error || Object.keys(errors).length"
      data-testid="ticket-request-assignment-error"
    >
      {{ errors.note?.[0] ?? errors.ticket?.[0] ?? error }}
    </UiAlert>

    <template #footer>
      <UiButton
        data-testid="ticket-request-assignment-cancel"
        @click="emit('close')"
      >
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        data-testid="ticket-request-assignment-confirm"
        :disabled="tooLong"
        :loading="store.requestingAssignment"
        @click="confirm"
      >
        Request
      </UiButton>
    </template>
  </BaseDialog>
</template>
