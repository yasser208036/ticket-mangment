<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail } from '../api/tickets'
import { useTicketsStore } from '../stores/tickets'
import { useUsersStore } from '../stores/users'

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
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in fade-in duration-150"
    data-testid="ticket-assign-dialog"
  >
    <div
      class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl space-y-4 animate-in zoom-in-95 duration-150"
    >
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
              d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"
            />
          </svg>
        </div>
        <div class="space-y-1">
          <h3 class="text-base font-bold text-slate-900">Assign ticket</h3>
          <p
            data-testid="ticket-assign-current"
            class="text-xs font-semibold text-slate-500"
          >
            Currently {{ ticket.assignee?.name ?? 'unassigned' }}
          </p>
        </div>
      </div>

      <!-- Agent -->
      <div class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700">Agent</label>
        <select
          v-model="selected"
          data-testid="ticket-assign-select"
          class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        >
          <option :value="undefined">Choose an agent…</option>
          <option
            v-for="agent in users.agents"
            :key="agent.id"
            :value="agent.id"
          >
            {{ agent.name }}
          </option>
        </select>
      </div>

      <!-- Reason -->
      <div class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700"
          >Reason (optional)</label
        >
        <textarea
          v-model.trim="reason"
          data-testid="ticket-assign-reason"
          rows="3"
          maxlength="500"
          placeholder="Why is this moving?"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
      </div>

      <p
        v-if="error || Object.keys(errors).length"
        data-testid="ticket-assign-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ errors.assigned_to?.[0] ?? errors.reason?.[0] ?? error }}
      </p>

      <!-- Actions -->
      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          v-if="ticket.assignee"
          data-testid="ticket-assign-unassign"
          type="button"
          :disabled="store.assigning"
          @click="submit(null)"
          class="mr-auto rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors disabled:opacity-50"
        >
          Return to queue
        </button>
        <button
          data-testid="ticket-assign-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          data-testid="ticket-assign-confirm"
          type="button"
          :disabled="unchanged || store.assigning"
          @click="confirm"
          class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors disabled:opacity-50"
        >
          Assign
        </button>
      </div>
    </div>
  </div>
</template>
