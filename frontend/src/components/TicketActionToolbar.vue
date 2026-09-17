<script setup lang="ts">
import UiButton from './ui/UiButton.vue'
import type { IconName } from './ui/UiIcon.vue'
import type { TicketDetail } from '../api/tickets'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{
  assign: []
  delete: []
  edit: []
  escalate: []
  status: []
  'request-assignment': []
}>()

type Action = {
  event: keyof typeof handlers
  can: boolean
  label: string
  icon: IconName
  variant?: 'secondary' | 'danger-quiet'
}

const handlers = {
  edit: () => emit('edit'),
  assign: () => emit('assign'),
  'request-assignment': () => emit('request-assignment'),
  status: () => emit('status'),
  escalate: () => emit('escalate'),
  delete: () => emit('delete'),
}

// Six buttons that differed only in label, icon and permission flag. The order
// is deliberate: the everyday actions first, the irreversible one last.
const actions = (): Action[] => [
  { event: 'edit', can: props.ticket.can.update, label: 'Edit', icon: 'edit' },
  {
    event: 'assign',
    can: props.ticket.can.assign,
    label: 'Assign',
    icon: 'user-plus',
  },
  {
    event: 'request-assignment',
    can: props.ticket.can.request_assignment,
    label: 'Request this ticket',
    icon: 'user-plus',
  },
  {
    event: 'status',
    can: props.ticket.can.change_status,
    label: 'Change status',
    icon: 'refresh',
  },
  {
    event: 'escalate',
    can: props.ticket.can.escalate,
    label: 'Escalate',
    icon: 'trend-up',
  },
  {
    event: 'delete',
    can: props.ticket.can.delete,
    label: 'Delete',
    icon: 'trash',
    variant: 'danger-quiet',
  },
]
</script>

<template>
  <div class="flex flex-wrap items-center gap-1.5">
    <UiButton
      v-for="action in actions().filter((a) => a.can)"
      :key="action.event"
      size="sm"
      :icon="action.icon"
      :variant="action.variant ?? 'secondary'"
      :data-testid="`action-${action.event}`"
      @click="handlers[action.event]()"
    >
      {{ action.label }}
    </UiButton>
  </div>
</template>
