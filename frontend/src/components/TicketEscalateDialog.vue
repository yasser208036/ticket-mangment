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
  <BaseDialog
    testid="ticket-escalate-dialog"
    title="Escalate ticket"
    icon="trend-up"
    tone="warning"
    @close="emit('close')"
  >
    <template #subtitle>
      <p
        v-if="ticket.escalation_level > 0"
        data-testid="ticket-escalate-level"
        class="mt-0.5 text-xs text-amber-700"
      >
        Currently level {{ ticket.escalation_level }}
      </p>
    </template>

    <UiField
      v-slot="field"
      label="Reason"
      :hint="tooShort ? 'At least 10 characters.' : undefined"
      hint-testid="ticket-escalate-hint"
    >
      <textarea
        v-bind="field"
        v-model="reason"
        data-testid="ticket-escalate-reason"
        rows="4"
        placeholder="Why can this not be resolved at your level?"
      />
    </UiField>

    <UiAlert
      v-if="error || Object.keys(errors).length"
      data-testid="ticket-escalate-error"
    >
      {{
        errors.reason?.[0] ??
        errors.status?.[0] ??
        errors.assigned_to?.[0] ??
        error
      }}
    </UiAlert>

    <template #footer>
      <UiButton data-testid="ticket-escalate-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        data-testid="ticket-escalate-confirm"
        :disabled="tooShort"
        :loading="store.escalating"
        @click="confirm"
      >
        Escalate
      </UiButton>
    </template>
  </BaseDialog>
</template>
