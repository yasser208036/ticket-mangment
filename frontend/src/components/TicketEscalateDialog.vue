<script setup lang="ts">
import { computed, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ escalated: []; close: [] }>()
const store = useTicketsStore()

const reason = ref('')
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const tooShort = computed(() => reason.value.trim().length < 10)

async function confirm(): Promise<void> {
  errors.value = {}
  error.value = ''
  try {
    await store.escalate(props.ticket.id, reason.value)
    emit('escalated')
  } catch (reason_) {
    errors.value = validationErrors(reason_)
    error.value = Object.keys(errors.value).length ? '' : errorMessage(reason_)
  }
}
</script>

<template>
  <BaseDialog testid="ticket-escalate-dialog">
    <div class="space-y-4">
      <!-- Header -->
      <div class="flex items-start gap-3">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600"
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
              d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"
            />
          </svg>
        </div>
        <div class="space-y-1">
          <h3 class="text-base font-bold text-slate-900">Escalate ticket</h3>
          <p
            v-if="ticket.escalation_level > 0"
            data-testid="ticket-escalate-level"
            class="text-xs font-semibold text-amber-800"
          >
            Currently level {{ ticket.escalation_level }}
          </p>
        </div>
      </div>

      <!-- Reason -->
      <div class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700">Reason</label>
        <textarea
          v-model="reason"
          data-testid="ticket-escalate-reason"
          rows="4"
          placeholder="Why can this not be resolved at your level?"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
        <p
          v-if="tooShort"
          data-testid="ticket-escalate-hint"
          class="text-xs text-slate-500"
        >
          At least 10 characters.
        </p>
      </div>

      <p
        v-if="error || Object.keys(errors).length"
        data-testid="ticket-escalate-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{
          errors.reason?.[0] ??
          errors.status?.[0] ??
          errors.assigned_to?.[0] ??
          error
        }}
      </p>

      <!-- Actions -->
      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          data-testid="ticket-escalate-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          data-testid="ticket-escalate-confirm"
          :disabled="tooShort || store.escalating"
          @click="confirm"
          class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-amber-700 transition-colors disabled:opacity-50"
        >
          Escalate
        </button>
      </div>
    </div>
  </BaseDialog>
</template>
