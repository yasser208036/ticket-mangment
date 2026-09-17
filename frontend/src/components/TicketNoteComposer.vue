<script setup lang="ts">
import { ref } from 'vue'
import UiButton from './ui/UiButton.vue'
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
    class="ui-card ui-card-pad space-y-3"
    data-testid="note-composer"
    @submit.prevent="submit"
  >
    <label for="note-body" class="ui-section-title block">Internal note</label>
    <textarea
      id="note-body"
      v-model="body"
      data-testid="note-body"
      rows="3"
      maxlength="5000"
      placeholder="Record what you did, or what the requester said…"
      class="ui-input resize-y"
      :class="fieldError && 'ui-input-invalid'"
      aria-describedby="note-hint"
    />
    <p v-if="fieldError" class="ui-error-text" data-testid="note-error-body">
      {{ fieldError }}
    </p>
    <p v-if="message" class="ui-error-text" data-testid="note-error">
      {{ message }}
    </p>
    <div class="flex items-center justify-between gap-3">
      <p id="note-hint" class="ui-hint" data-testid="note-hint">
        Notes are internal and permanent.
      </p>
      <UiButton
        variant="primary"
        type="submit"
        data-testid="note-submit"
        :loading="store.noteSaving"
      >
        {{ store.noteSaving ? 'Saving…' : 'Add note' }}
      </UiButton>
    </div>
  </form>
</template>
