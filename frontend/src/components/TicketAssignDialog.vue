<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import { useUsersStore } from '../stores/users'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ assigned: []; close: [] }>()
const store = useTicketsStore()
const users = useUsersStore()

// `number | undefined`, never `number | ''`. ConvertEmptyStringsToNull rewrites
// an empty string to null server-side, so a select that could emit '' would
// silently unassign; the type is what makes that unreachable, and the confirm
// button stays disabled while the value is undefined.
const selected = ref<number | undefined>(props.ticket.assignee?.id)
const reason = ref('')
const error = ref('')
const errors = ref<Record<string, string[]>>({})

// The select opens on the current assignee for context, which means Assign
// would otherwise submit a no-op: the server writes no activity row for it and
// so discards any reason typed alongside, returning a 200 that looks like
// success. Nothing to assign until the choice actually changes.
const unchanged = computed(
  () =>
    selected.value === undefined ||
    selected.value === props.ticket.assignee?.id,
)

onMounted(async () => {
  try {
    await users.loadAgents()
  } catch (caughtError) {
    error.value = errorMessage(caughtError)
  }
})

// Guarded rather than `submit(selected ?? null)`: coercing an unset select to
// null would turn a mis-click on a disabled button into an unassign.
function confirm(): void {
  if (unchanged.value || selected.value === undefined) return
  void submit(selected.value)
}

async function submit(assignedTo: number | null): Promise<void> {
  errors.value = {}
  error.value = ''
  try {
    await store.assign(props.ticket.id, assignedTo, reason.value || undefined)
    emit('assigned')
  } catch (caughtError) {
    errors.value = validationErrors(caughtError)
    error.value = Object.keys(errors.value).length
      ? ''
      : errorMessage(caughtError)
  }
}
</script>

<template>
  <BaseDialog
    testid="ticket-assign-dialog"
    title="Assign ticket"
    icon="user-plus"
    @close="emit('close')"
  >
    <template #subtitle>
      <p
        data-testid="ticket-assign-current"
        class="mt-0.5 text-xs text-ink-500"
      >
        Currently {{ ticket.assignee?.name ?? 'unassigned' }}
      </p>
    </template>

    <UiField v-slot="field" label="Agent">
      <select
        v-bind="field"
        v-model="selected"
        data-testid="ticket-assign-select"
      >
        <option :value="undefined">Choose an agent…</option>
        <option v-for="agent in users.agents" :key="agent.id" :value="agent.id">
          {{ agent.name }}
        </option>
      </select>
    </UiField>

    <UiField v-slot="field" label="Reason (optional)">
      <textarea
        v-bind="field"
        v-model.trim="reason"
        data-testid="ticket-assign-reason"
        rows="3"
        maxlength="500"
        placeholder="Why is this moving?"
      />
    </UiField>

    <UiAlert
      v-if="error || Object.keys(errors).length"
      data-testid="ticket-assign-error"
    >
      {{ errors.assigned_to?.[0] ?? errors.reason?.[0] ?? error }}
    </UiAlert>

    <template #footer>
      <UiButton
        v-if="ticket.assignee"
        class="mr-auto"
        data-testid="ticket-assign-unassign"
        :loading="store.assigning"
        @click="submit(null)"
      >
        Return to queue
      </UiButton>
      <UiButton data-testid="ticket-assign-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        data-testid="ticket-assign-confirm"
        :disabled="unchanged"
        :loading="store.assigning"
        @click="confirm"
      >
        Assign
      </UiButton>
    </template>
  </BaseDialog>
</template>
