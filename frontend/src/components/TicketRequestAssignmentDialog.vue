<script setup lang="ts">
import { computed, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'

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
  <BaseDialog testid="ticket-request-assignment-dialog">
    <div class="space-y-4">
      <!-- Header -->
      <div class="flex items-start gap-3">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600"
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
              d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m1.586-9.414a2 2 0 112.828 2.828L12.828 15H10v-2.828l8.586-8.586z"
            />
          </svg>
        </div>
        <div class="space-y-1">
          <h3 class="text-base font-bold text-slate-900">
            Request this ticket
          </h3>
          <p class="text-xs text-slate-500">
            An administrator will review your request and assign it.
          </p>
        </div>
      </div>

      <!-- Note -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between">
          <label class="text-xs font-semibold text-slate-700"
            >Note (optional)</label
          >
          <span class="text-[11px] text-slate-400">
            {{ note.length }} / 500
          </span>
        </div>
        <textarea
          v-model="note"
          data-testid="ticket-request-assignment-note"
          rows="4"
          placeholder="Why should this be assigned to you?"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
          :class="{ 'border-rose-300 ring-2 ring-rose-100': tooLong }"
        />
        <p
          v-if="tooLong"
          data-testid="ticket-request-assignment-hint"
          class="text-xs text-rose-600"
        >
          Keep the note to 500 characters or fewer.
        </p>
      </div>

      <p
        v-if="error || Object.keys(errors).length"
        data-testid="ticket-request-assignment-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ errors.note?.[0] ?? errors.ticket?.[0] ?? error }}
      </p>

      <!-- Actions -->
      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          data-testid="ticket-request-assignment-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          data-testid="ticket-request-assignment-confirm"
          :disabled="tooLong || store.requestingAssignment"
          @click="confirm"
          class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors disabled:opacity-50"
        >
          Request
        </button>
      </div>
    </div>
  </BaseDialog>
</template>
