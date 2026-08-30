<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'

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
  <BaseDialog testid="ticket-delete-dialog">
    <div class="space-y-4">
      <!-- Warning Header -->
      <div class="flex items-start gap-3">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-600"
        >
          <svg
            class="h-5 w-5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
            />
          </svg>
        </div>
        <h3 class="text-base font-bold text-slate-900">Delete ticket</h3>
      </div>

      <p data-testid="ticket-delete-prompt" class="text-sm text-slate-600">
        Are you sure you want to delete ticket {{ ticket.reference }} ("{{
          ticket.subject
        }}")? It will disappear from every list right away and no one will be
        able to open or work on it again.
      </p>

      <p
        v-if="error"
        data-testid="ticket-delete-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ error }}
      </p>

      <!-- Actions -->
      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          data-testid="ticket-delete-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          data-testid="ticket-delete-confirm"
          type="button"
          :disabled="store.deleting"
          @click="confirmDelete"
          class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors disabled:opacity-50"
        >
          Delete
        </button>
      </div>
    </div>
  </BaseDialog>
</template>
