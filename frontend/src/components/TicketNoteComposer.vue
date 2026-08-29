<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import { useTicketsStore } from '../stores/tickets'

const props = defineProps<{ ticketId: number }>()
const store = useTicketsStore()
const body = ref('')
const fieldError = ref('')
const message = ref('')

async function submit(): Promise<void> {
  if (store.noteSaving) return
  fieldError.value = ''
  message.value = ''
  try {
    await store.addNote(props.ticketId, body.value)
    body.value = ''
  } catch (caughtError) {
    const fields = validationErrors(caughtError)
    fieldError.value = fields.body?.[0] ?? ''
    if (!fieldError.value) message.value = errorMessage(caughtError)
  }
}
</script>

<template>
  <form
    class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-3"
    data-testid="note-composer"
    @submit.prevent="submit"
  >
    <label
      for="note-body"
      class="text-xs font-bold uppercase tracking-wider text-slate-400"
    >
      Internal note
    </label>
    <textarea
      id="note-body"
      v-model="body"
      data-testid="note-body"
      rows="3"
      maxlength="5000"
      class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm text-slate-800 shadow-xs focus:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-100"
    />
    <p class="text-xs italic text-slate-400" data-testid="note-hint">
      Notes are internal and permanent.
    </p>
    <p
      v-if="fieldError"
      class="text-xs font-medium text-rose-600"
      data-testid="note-error-body"
    >
      {{ fieldError }}
    </p>
    <p
      v-if="message"
      class="text-xs font-medium text-rose-600"
      data-testid="note-error"
    >
      {{ message }}
    </p>
    <button
      type="submit"
      data-testid="note-submit"
      :disabled="store.noteSaving"
      class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
    >
      {{ store.noteSaving ? 'Saving...' : 'Add note' }}
    </button>
  </form>
</template>
