<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, validationErrors } from '../api/errors'
import type { CreateTicketPayload } from '../api/tickets'
import { listAgents, type AgentOption } from '../api/agents'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiField from '../components/ui/UiField.vue'
import UiIcon from '../components/ui/UiIcon.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'

const masterData = useMasterDataStore()
const tickets = useTicketsStore()
const router = useRouter()
const submitting = ref(false)
const message = ref('')
const agents = ref<AgentOption[]>([])
const assignedTo = ref<number>(0)

const form = reactive<CreateTicketPayload>({
  subject: '',
  description: '',
  category_id: 0,
})

const errors = reactive<Record<string, string>>({})

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
  return Object.keys(errors).length === 0
}

async function submit(): Promise<void> {
  if (submitting.value || !validate()) return
  submitting.value = true
  try {
    const payload = {
      ...form,
      priority_id: form.priority_id || undefined,
      assigned_to: assignedTo.value || undefined,
    }
    const ticket = await tickets.create(payload)
    await router.push({ name: 'ticket-detail', params: { id: ticket.id } })
  } catch (error) {
    const fields = validationErrors(error)
    Object.assign(errors, fields)
    if (!Object.keys(fields).length) message.value = errorMessage(error)
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  void masterData.ensureLoaded()
  // Optional -- a failure here must not block filing the ticket, it just
  // leaves the picker showing only "Let an administrator assign this".
  listAgents()
    .then((result) => {
      agents.value = result
    })
    .catch(() => {
      agents.value = []
    })
})
</script>

<template>
  <main class="mx-auto max-w-3xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <div>
      <RouterLink
        :to="{ name: 'tickets' }"
        class="ui-focus inline-flex items-center gap-1.5 rounded text-xs font-medium text-ink-500 transition-colors hover:text-ink-900"
      >
        <UiIcon name="arrow-left" class="h-3.5 w-3.5" />
        Back to tickets
      </RouterLink>
      <h1 class="ui-title mt-2">New ticket</h1>
      <p class="ui-subtitle">
        File a customer support request, internal incident or inquiry.
      </p>
    </div>

    <UiAlert v-if="masterData.error" data-testid="new-ticket-error">
      {{ masterData.error }}
    </UiAlert>

    <UiAlert v-if="message" data-testid="new-ticket-error">
      {{ message }}
    </UiAlert>

    <form
      data-testid="new-ticket-form"
      class="space-y-5"
      @submit.prevent="submit"
    >
      <UiPanel title="Ticket details">
        <div class="space-y-4 px-5 py-5 sm:px-6">
          <UiField
            v-slot="field"
            label="Subject"
            required
            :error="errors.subject"
          >
            <input
              v-bind="field"
              v-model="form.subject"
              data-testid="new-ticket-subject"
              type="text"
              placeholder="A one-line summary of the problem"
            />
          </UiField>

          <div class="grid gap-4 sm:grid-cols-2">
            <UiField
              v-slot="field"
              label="Category"
              required
              :error="errors.category_id"
            >
              <select
                v-bind="field"
                v-model.number="form.category_id"
                data-testid="new-ticket-category"
              >
                <option :value="0" disabled>Select a category</option>
                <option
                  v-for="category in masterData.activeCategories"
                  :key="category.id"
                  :value="category.id"
                >
                  {{ category.name }}
                </option>
              </select>
            </UiField>

            <UiField v-slot="field" label="Priority">
              <select
                v-bind="field"
                v-model.number="form.priority_id"
                data-testid="new-ticket-priority"
              >
                <option :value="undefined">Let the desk decide</option>
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
            label="Assign to"
            hint="Optional — leave this if you are not sure who should handle it."
          >
            <select
              v-bind="field"
              v-model.number="assignedTo"
              data-testid="ticket-form-agent"
            >
              <option :value="0">Let an administrator assign this</option>
              <option v-for="agent in agents" :key="agent.id" :value="agent.id">
                {{ agent.name }}
              </option>
            </select>
          </UiField>

          <UiField
            v-slot="field"
            label="Description"
            required
            :error="errors.description"
          >
            <textarea
              v-bind="field"
              v-model="form.description"
              data-testid="new-ticket-description"
              rows="7"
              placeholder="What happened, what you expected, and how to reproduce it…"
              class="resize-y"
            />
          </UiField>
          <p class="-mt-2 text-right text-[11px] text-ink-400 tabular">
            {{ form.description.length }} / 16,000
          </p>
        </div>
      </UiPanel>

      <div class="flex items-center justify-end gap-2">
        <UiButton :to="{ name: 'tickets' }">Cancel</UiButton>
        <UiButton
          variant="primary"
          type="submit"
          data-testid="new-ticket-submit"
          :loading="submitting"
        >
          {{ submitting ? 'Filing…' : 'File ticket' }}
        </UiButton>
      </div>
    </form>
  </main>
</template>
