<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ deleted: []; close: [] }>()
const store = useTicketsStore()
const error = ref('')

async function confirmDelete(): Promise<void> {
  try {
    await store.removeTicket(props.ticket.id)
    emit('deleted')
  } catch (reason) {
    error.value = errorMessage(reason)
  }
}
</script>

<template>
  <BaseDialog
    testid="ticket-delete-dialog"
    title="Delete ticket"
    icon="trash"
    tone="danger"
    @close="emit('close')"
  >
    <p data-testid="ticket-delete-prompt" class="text-sm text-ink-600">
      Are you sure you want to delete ticket {{ ticket.reference }} ("{{
        ticket.subject
      }}")? It will disappear from every list right away and no one will be able
      to open or work on it again.
    </p>

    <UiAlert v-if="error" data-testid="ticket-delete-error">{{
      error
    }}</UiAlert>

    <template #footer>
      <UiButton data-testid="ticket-delete-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        variant="danger"
        data-testid="ticket-delete-confirm"
        :loading="store.deleting"
        @click="confirmDelete"
      >
        Delete
      </UiButton>
    </template>
  </BaseDialog>
</template>
