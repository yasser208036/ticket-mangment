<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAssignmentRequestsStore } from '../stores/assignmentRequests'
import { relativeAge } from '../lib/relativeTime'

const store = useAssignmentRequestsStore()
onMounted(() => void store.load())

const decliningId = ref<number | null>(null)
const declineNote = ref('')

function openDecline(id: number): void {
  decliningId.value = id
  declineNote.value = ''
}

async function confirmDecline(): Promise<void> {
  if (decliningId.value === null) return
  await store.decline(decliningId.value, declineNote.value || undefined)
  decliningId.value = null
}
</script>

<template>
  <main class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Header -->
    <div>
      <h1
        class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
      >
        Assignment Requests
      </h1>
      <p class="mt-1 text-sm text-slate-500">
        Agents asking to be assigned an unassigned ticket.
      </p>
    </div>

    <!-- Loading State -->
    <div
      v-if="store.loading"
      data-testid="assignment-requests-loading"
      class="flex items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-12 shadow-sm"
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
        <span>Loading assignment requests...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-else-if="store.error"
      data-testid="assignment-requests-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm"
    >
      {{ store.error }}
    </div>

    <!-- Empty State -->
    <div
      v-else-if="store.items.length === 0"
      data-testid="assignment-requests-empty"
      class="rounded-2xl border border-slate-200/80 bg-white p-8 text-center text-sm text-slate-500 shadow-sm"
    >
      No pending assignment requests.
    </div>

    <!-- Table -->
    <div
      v-else
      data-testid="assignment-requests-table"
      class="overflow-x-auto rounded-2xl border border-slate-200/80 bg-white shadow-sm"
    >
      <table class="min-w-full divide-y divide-slate-100">
        <thead>
          <tr
            class="text-left text-[11px] font-bold uppercase tracking-wider text-slate-500"
          >
            <th class="px-4 py-3">Ticket</th>
            <th class="px-4 py-3">Agent</th>
            <th class="px-4 py-3">Note</th>
            <th class="px-4 py-3">Age</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr
            v-for="request in store.items"
            :key="request.id"
            data-testid="assignment-request-row"
            class="text-sm"
          >
            <td class="px-4 py-3">
              <RouterLink
                :to="{
                  name: 'ticket-detail',
                  params: { id: request.ticket.id },
                }"
                class="font-semibold text-indigo-600 hover:underline"
              >
                {{ request.ticket.reference }}
              </RouterLink>
              <p class="text-xs text-slate-500">{{ request.ticket.subject }}</p>
            </td>
            <td class="px-4 py-3 text-slate-700">
              {{ request.requester.name }}
            </td>
            <td class="px-4 py-3 text-slate-600">
              {{ request.note || '—' }}
            </td>
            <td class="px-4 py-3 text-slate-500">
              {{ relativeAge(request.created_at) }}
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center justify-end gap-2">
                <button
                  data-testid="assignment-request-approve"
                  @click="store.approve(request.id)"
                  class="rounded-xl bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors"
                >
                  Approve
                </button>
                <button
                  data-testid="assignment-request-decline"
                  @click="openDecline(request.id)"
                  class="rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
                >
                  Decline
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Decline Dialog -->
    <div
      v-if="decliningId !== null"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs"
      data-testid="assignment-request-decline-dialog"
    >
      <div
        class="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl space-y-4"
      >
        <h3 class="text-base font-bold text-slate-900">Decline request</h3>
        <textarea
          v-model="declineNote"
          data-testid="assignment-request-decline-note"
          rows="3"
          placeholder="Optional note for the record..."
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        />
        <div
          class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
        >
          <button
            data-testid="assignment-request-decline-cancel"
            type="button"
            @click="decliningId = null"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
          >
            Cancel
          </button>
          <button
            data-testid="assignment-request-decline-confirm"
            @click="confirmDecline"
            class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors"
          >
            Decline
          </button>
        </div>
      </div>
    </div>
  </main>
</template>
