<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { TicketDetail, UpdateTicketPayload } from '../api/tickets'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'
import BaseDialog from './BaseDialog.vue'

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
  <BaseDialog testid="ticket-edit-dialog" max-width="lg" scrollable>
    <div class="flex items-start gap-3 border-b border-slate-100 p-6 pb-4">
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
            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
          />
        </svg>
      </div>
      <h3 class="text-base font-bold text-slate-900">Edit ticket</h3>
    </div>

    <div class="space-y-4 overflow-y-auto p-6">
      <p
        v-if="message"
        data-testid="ticket-edit-message"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ message }}
      </p>

      <!-- Subject -->
      <div class="space-y-1.5">
        <label class="text-xs font-semibold text-slate-700">Subject</label>
        <input
          data-testid="ticket-edit-subject"
          v-model="form.subject"
          type="text"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
          :class="{
            'border-rose-300 ring-2 ring-rose-100': errors.subject,
          }"
        />
        <span
          v-if="errors.subject"
          data-testid="ticket-edit-error-subject"
          class="text-xs font-medium text-rose-600"
        >
          {{ errors.subject }}
        </span>
      </div>

      <!-- Category & Priority -->
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="space-y-1.5">
          <label class="text-xs font-semibold text-slate-700">Category</label>
          <select
            data-testid="ticket-edit-category"
            v-model.number="form.category_id"
            class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{
              'border-rose-300 ring-2 ring-rose-100': errors.category_id,
            }"
          >
            <!--
                A ticket whose category was deactivated after filing still
                shows it here, disabled, so saving an unrelated field does
                not silently move the ticket into whatever the select would
                otherwise default to.
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
          <span
            v-if="errors.category_id"
            data-testid="ticket-edit-error-category_id"
            class="text-xs font-medium text-rose-600"
          >
            {{ errors.category_id }}
          </span>
        </div>

        <div class="space-y-1.5">
          <label class="text-xs font-semibold text-slate-700">Priority</label>
          <select
            data-testid="ticket-edit-priority"
            v-model.number="form.priority_id"
            class="h-10 w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{
              'border-rose-300 ring-2 ring-rose-100': errors.priority_id,
            }"
          >
            <option
              v-for="priority in masterData.priorities"
              :key="priority.id"
              :value="priority.id"
            >
              {{ priority.name }}
            </option>
          </select>
          <span
            v-if="errors.priority_id"
            data-testid="ticket-edit-error-priority_id"
            class="text-xs font-medium text-rose-600"
          >
            {{ errors.priority_id }}
          </span>
        </div>
      </div>

      <!-- Description -->
      <div class="space-y-1.5">
        <div class="flex items-center justify-between">
          <label class="text-xs font-semibold text-slate-700"
            >Description</label
          >
          <span class="text-[11px] text-slate-400">
            {{ form.description.length }} / 16,000
          </span>
        </div>
        <textarea
          data-testid="ticket-edit-description"
          v-model="form.description"
          rows="8"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 p-3.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
          :class="{
            'border-rose-300 ring-2 ring-rose-100': errors.description,
          }"
        />
        <span
          v-if="errors.description"
          data-testid="ticket-edit-error-description"
          class="text-xs font-medium text-rose-600"
        >
          {{ errors.description }}
        </span>
      </div>
    </div>

    <!-- Actions -->
    <div
      class="flex items-center justify-end gap-3 border-t border-slate-100 p-6 pt-4"
    >
      <button
        data-testid="ticket-edit-cancel"
        type="button"
        @click="close"
        class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
      >
        Cancel
      </button>
      <button
        data-testid="ticket-edit-submit"
        type="button"
        :disabled="store.saving"
        @click="submit"
        class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors disabled:opacity-50"
      >
        {{ store.saving ? 'Saving...' : 'Save changes' }}
      </button>
    </div>
  </BaseDialog>
</template>
