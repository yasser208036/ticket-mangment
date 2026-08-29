<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { errorMessage, validationErrors } from '../api/errors'
import type { CreateTicketPayload } from '../api/tickets'
import { useMasterDataStore } from '../stores/masterData'
import { useTicketsStore } from '../stores/tickets'

const masterData = useMasterDataStore()
const tickets = useTicketsStore()
const router = useRouter()
const submitting = ref(false)
const message = ref('')

const form = reactive<CreateTicketPayload>({
  requester: { name: '', email: '', phone: '', company: '' },
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
  if (!form.requester.name) errors['requester.name'] = 'Name is required.'
  if (!/.+@.+\..+/.test(form.requester.email))
    errors['requester.email'] = 'Valid email is required.'
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
    const payload = { ...form, priority_id: form.priority_id || undefined }
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

onMounted(() => void masterData.ensureLoaded())
</script>

<template>
  <main class="mx-auto max-w-4xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <RouterLink
          :to="{ name: 'tickets' }"
          class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 transition-colors hover:text-indigo-600 mb-2"
        >
          <svg
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M10 19l-7-7m0 0l7-7m-7 7h18"
            />
          </svg>
          <span>Back to tickets</span>
        </RouterLink>
        <h1
          class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
        >
          Create New Ticket
        </h1>
        <p class="mt-1 text-sm text-slate-500">
          File a customer support request, internal incident, or inquiry.
        </p>
      </div>
    </div>

    <!-- Loading master data -->
    <div
      v-if="masterData.categories.length === 0"
      class="flex items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-8 shadow-sm"
    >
      <div class="flex items-center gap-3 text-sm font-medium text-slate-500">
        <svg
          class="h-5 w-5 animate-spin text-indigo-600"
          fill="none"
          viewBox="0 0 24 24"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8v8H4z"
          />
        </svg>
        <span>Loading master data...</span>
      </div>
    </div>

    <!-- Error Banner -->
    <div
      v-if="masterData.error"
      data-testid="new-ticket-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm"
    >
      {{ masterData.error }}
    </div>

    <div
      v-if="message"
      data-testid="new-ticket-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm"
    >
      {{ message }}
    </div>

    <!-- Ticket Form -->
    <form
      data-testid="new-ticket-form"
      @submit.prevent="submit"
      class="space-y-6"
    >
      <!-- Requester Card -->
      <div
        class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-4"
      >
        <div class="border-b border-slate-100 pb-3">
          <h2 class="text-sm font-bold uppercase tracking-wider text-slate-900">
            Requester Information
          </h2>
          <p class="text-xs text-slate-500">
            Contact information of who reported the issue.
          </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <!-- Name -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Full Name *</label
            >
            <input
              data-testid="new-ticket-requester-name"
              v-model="form.requester.name"
              type="text"
              placeholder="e.g. Jane Doe"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
              :class="{
                'border-rose-300 ring-2 ring-rose-100':
                  errors['requester.name'],
              }"
            />
            <span
              v-if="errors['requester.name']"
              data-testid="new-ticket-error-requester.name"
              class="text-xs font-medium text-rose-600"
            >
              {{ errors['requester.name'] }}
            </span>
          </div>

          <!-- Email -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Email Address *</label
            >
            <input
              data-testid="new-ticket-requester-email"
              v-model="form.requester.email"
              type="email"
              placeholder="e.g. jane@example.com"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
              :class="{
                'border-rose-300 ring-2 ring-rose-100':
                  errors['requester.email'],
              }"
            />
            <span
              v-if="errors['requester.email']"
              class="text-xs font-medium text-rose-600"
            >
              {{ errors['requester.email'] }}
            </span>
          </div>

          <!-- Phone -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Phone (Optional)</label
            >
            <input
              data-testid="new-ticket-requester-phone"
              v-model="form.requester.phone"
              type="tel"
              placeholder="e.g. +1 (555) 000-0000"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            />
          </div>

          <!-- Company -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Company (Optional)</label
            >
            <input
              data-testid="new-ticket-requester-company"
              v-model="form.requester.company"
              type="text"
              placeholder="e.g. Acme Corp"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            />
          </div>
        </div>
      </div>

      <!-- Ticket Details Card -->
      <div
        class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-4"
      >
        <div class="border-b border-slate-100 pb-3">
          <h2 class="text-sm font-bold uppercase tracking-wider text-slate-900">
            Ticket Details
          </h2>
          <p class="text-xs text-slate-500">
            Provide the details, category, and urgency of the request.
          </p>
        </div>

        <div class="space-y-4">
          <!-- Subject -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Subject *</label
            >
            <input
              data-testid="new-ticket-subject"
              v-model="form.subject"
              type="text"
              placeholder="Brief summary of the issue..."
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
              :class="{
                'border-rose-300 ring-2 ring-rose-100': errors.subject,
              }"
            />
            <span
              v-if="errors.subject"
              class="text-xs font-medium text-rose-600"
            >
              {{ errors.subject }}
            </span>
          </div>

          <!-- Category & Priority Grid -->
          <div class="grid gap-4 sm:grid-cols-2">
            <!-- Category -->
            <div class="space-y-1">
              <label class="text-xs font-semibold text-slate-700"
                >Category *</label
              >
              <select
                data-testid="new-ticket-category"
                v-model.number="form.category_id"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
                :class="{
                  'border-rose-300 ring-2 ring-rose-100': errors.category_id,
                }"
              >
                <option :value="0">Select category</option>
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
                class="text-xs font-medium text-rose-600"
              >
                {{ errors.category_id }}
              </span>
            </div>

            <!-- Priority -->
            <div class="space-y-1">
              <label class="text-xs font-semibold text-slate-700"
                >Priority (Optional)</label
              >
              <select
                data-testid="new-ticket-priority"
                v-model.number="form.priority_id"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
              >
                <option :value="undefined">Default Priority</option>
                <option
                  v-for="priority in masterData.priorities"
                  :key="priority.id"
                  :value="priority.id"
                >
                  {{ priority.name }}
                </option>
              </select>
            </div>
          </div>

          <!-- Description -->
          <div class="space-y-1">
            <div class="flex items-center justify-between">
              <label class="text-xs font-semibold text-slate-700"
                >Description *</label
              >
              <span class="text-[11px] text-slate-400">
                {{ form.description.length }} / 16,000
              </span>
            </div>
            <textarea
              data-testid="new-ticket-description"
              v-model="form.description"
              rows="6"
              placeholder="Detailed description of the issue or steps to reproduce..."
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 p-3.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
              :class="{
                'border-rose-300 ring-2 ring-rose-100': errors.description,
              }"
            />
            <span
              v-if="errors.description"
              class="text-xs font-medium text-rose-600"
            >
              {{ errors.description }}
            </span>
          </div>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="flex items-center justify-end gap-3 pt-2">
        <RouterLink
          :to="{ name: 'tickets' }"
          class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 shadow-2xs transition-all hover:bg-slate-50"
        >
          Cancel
        </RouterLink>
        <button
          data-testid="new-ticket-submit"
          :disabled="submitting"
          type="submit"
          class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 px-6 py-2.5 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 transition-all hover:opacity-95 disabled:opacity-60"
        >
          <svg
            v-if="submitting"
            class="h-4 w-4 animate-spin"
            fill="none"
            viewBox="0 0 24 24"
          >
            <circle
              class="opacity-25"
              cx="12"
              cy="12"
              r="10"
              stroke="currentColor"
              stroke-width="4"
            />
            <path
              class="opacity-75"
              fill="currentColor"
              d="M4 12a8 8 0 018-8v8H4z"
            />
          </svg>
          <span>{{ submitting ? 'Filing...' : 'File ticket' }}</span>
        </button>
      </div>
    </form>
  </main>
</template>
