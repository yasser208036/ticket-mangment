<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail, UpdateTicketPayload } from '../api/tickets'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'

const props = defineProps<{ ticket: TicketDetail }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const masterData = useMasterDataStore()
const store = useTicketsStore()

const form = reactive({
  subject: props.ticket.subject,
  description: props.ticket.description,
  category_id: props.ticket.category.id,
  priority_id: props.ticket.priority.id,
})
const base = { ...form }
const errors = reactive<Record<string, string>>({})
const message = ref('')

const dirty = computed(() => JSON.stringify(form) !== JSON.stringify(base))

function clearErrors(): void {
  Object.keys(errors).forEach((key) => delete errors[key])
  message.value = ''
}

function validate(): boolean {
  clearErrors()
  if (!form.subject) errors.subject = 'Subject is required.'
  if (form.subject.length > 255) errors.subject = 'Maximum 255 characters.'
  if (!form.description) errors.description = 'Description is required.'
  if (form.description.length > 16000)
    errors.description = 'Maximum 16000 characters.'
  if (!form.category_id) errors.category_id = 'Category is required.'
  if (!form.priority_id) errors.priority_id = 'Priority is required.'
  return Object.keys(errors).length === 0
}

function close(): void {
  if (
    dirty.value &&
    !window.confirm('Discard your unsaved changes to this ticket?')
  )
    return
  emit('close')
}

async function submit(): Promise<void> {
  if (store.saving || !validate()) return
  const payload: UpdateTicketPayload = {}
  if (form.subject !== base.subject) payload.subject = form.subject
  if (form.description !== base.description)
    payload.description = form.description
  if (form.category_id !== base.category_id)
    payload.category_id = form.category_id
  if (form.priority_id !== base.priority_id)
    payload.priority_id = form.priority_id
  try {
    await store.saveTicket(props.ticket.id, payload)
    emit('saved')
  } catch (error) {
    const fields = validationErrors(error)
    Object.entries(fields).forEach(([key, value]) => {
      errors[key] = value[0]
    })
    if (!Object.keys(fields).length) message.value = errorMessage(error)
  }
}

onMounted(() => void masterData.ensureLoaded())

// onBeforeRouteLeave does not apply to a dialog -- this is what still catches
// a reload or tab close while a change is unsaved.
function warnOnUnload(event: BeforeUnloadEvent): void {
  if (dirty.value) event.preventDefault()
}
onMounted(() => window.addEventListener('beforeunload', warnOnUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', warnOnUnload))
</script>

<template>
  <BaseDialog
    testid="ticket-edit-dialog"
    max-width="lg"
    scrollable
    title="Edit ticket"
    icon="edit"
    @close="close"
  >
    <UiAlert v-if="message" data-testid="ticket-edit-message">
      {{ message }}
    </UiAlert>

    <UiField
      v-slot="field"
      label="Subject"
      :error="errors.subject"
      error-testid="ticket-edit-error-subject"
    >
      <input
        v-bind="field"
        v-model="form.subject"
        data-testid="ticket-edit-subject"
        type="text"
      />
    </UiField>

    <div class="grid gap-4 sm:grid-cols-2">
      <UiField
        v-slot="field"
        label="Category"
        :error="errors.category_id"
        error-testid="ticket-edit-error-category_id"
      >
        <select
          v-bind="field"
          v-model.number="form.category_id"
          data-testid="ticket-edit-category"
        >
          <!--
            A ticket whose category was deactivated after filing still shows it
            here, disabled, so saving an unrelated field does not silently move
            the ticket into whatever the select would otherwise default to.
          -->
          <option
            v-if="
              !masterData.activeCategories.some(
                (category) => category.id === ticket.category.id,
              )
            "
            :value="ticket.category.id"
            disabled
          >
            {{ ticket.category.name }} (inactive)
          </option>
          <option
            v-for="category in masterData.activeCategories"
            :key="category.id"
            :value="category.id"
          >
            {{ category.name }}
          </option>
        </select>
      </UiField>

      <UiField
        v-slot="field"
        label="Priority"
        :error="errors.priority_id"
        error-testid="ticket-edit-error-priority_id"
      >
        <select
          v-bind="field"
          v-model.number="form.priority_id"
          data-testid="ticket-edit-priority"
        >
          <option
            v-for="priority in masterData.priorities"
            :key="priority.id"
            :value="priority.id"
          >
            {{ priority.name }}
          </option>
        </select>
      </UiField>
    </div>

    <UiField
      v-slot="field"
      label="Description"
      :error="errors.description"
      error-testid="ticket-edit-error-description"
    >
      <textarea
        v-bind="field"
        v-model="form.description"
        data-testid="ticket-edit-description"
        rows="8"
        class="resize-y"
      />
    </UiField>
    <p class="-mt-2 text-right text-[11px] text-ink-400 tabular">
      {{ form.description.length }} / 16,000
    </p>

    <template #footer>
      <UiButton data-testid="ticket-edit-cancel" @click="close">
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        data-testid="ticket-edit-submit"
        :loading="store.saving"
        @click="submit"
      >
        {{ store.saving ? 'Saving…' : 'Save changes' }}
      </UiButton>
    </template>
  </BaseDialog>
</template>
