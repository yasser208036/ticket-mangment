<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useAssignmentRequestsStore } from '../stores/assignmentRequests'
import BaseDialog from '../components/BaseDialog.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiField from '../components/ui/UiField.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
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
  <main class="mx-auto max-w-5xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader
      title="Assignment requests"
      description="Agents asking to be assigned an unassigned ticket."
    />

    <UiLoadingPanel
      v-if="store.loading"
      label="Loading assignment requests"
      testid="assignment-requests-loading"
      :rows="3"
    />

    <UiAlert v-else-if="store.error" data-testid="assignment-requests-error">
      {{ store.error }}
    </UiAlert>

    <UiEmptyState
      v-else-if="store.items.length === 0"
      title="No pending assignment requests."
      description="When an agent asks for an unassigned ticket, it appears here."
      testid="assignment-requests-empty"
    />

    <div
      v-else
      class="ui-card overflow-hidden"
      data-testid="assignment-requests-table"
    >
      <div class="overflow-x-auto">
        <table class="ui-table">
          <thead class="ui-thead">
            <tr>
              <th scope="col" class="ui-th">Ticket</th>
              <th scope="col" class="ui-th">Agent</th>
              <th scope="col" class="ui-th">Note</th>
              <th scope="col" class="ui-th">Age</th>
              <th scope="col" class="ui-th text-right">Decision</th>
            </tr>
          </thead>
          <tbody class="ui-tbody">
            <tr
              v-for="request in store.items"
              :key="request.id"
              data-testid="assignment-request-row"
              class="ui-tr"
            >
              <td class="ui-td">
                <RouterLink
                  :to="{
                    name: 'ticket-detail',
                    params: { id: request.ticket.id },
                  }"
                  class="ui-focus rounded font-mono text-xs font-medium text-brand-600 hover:underline"
                >
                  {{ request.ticket.reference }}
                </RouterLink>
                <p class="mt-0.5 max-w-xs truncate text-xs text-ink-500">
                  {{ request.ticket.subject }}
                </p>
              </td>
              <td class="ui-td whitespace-nowrap">
                {{ request.requester.name }}
              </td>
              <td class="ui-td max-w-xs truncate text-xs text-ink-600">
                {{ request.note || '—' }}
              </td>
              <td class="ui-td tabular whitespace-nowrap text-xs text-ink-500">
                {{ relativeAge(request.created_at) }}
              </td>
              <td class="ui-td whitespace-nowrap text-right">
                <span class="inline-flex items-center gap-1.5">
                  <UiButton
                    size="sm"
                    variant="primary"
                    data-testid="assignment-request-approve"
                    @click="store.approve(request.id)"
                  >
                    Approve
                  </UiButton>
                  <UiButton
                    size="sm"
                    data-testid="assignment-request-decline"
                    @click="openDecline(request.id)"
                  >
                    Decline
                  </UiButton>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <BaseDialog
      v-if="decliningId !== null"
      testid="assignment-request-decline-dialog"
      title="Decline request"
      icon="close"
      tone="danger"
      @close="decliningId = null"
    >
      <UiField v-slot="field" label="Note (optional)">
        <textarea
          v-bind="field"
          v-model="declineNote"
          data-testid="assignment-request-decline-note"
          rows="3"
          placeholder="Optional note for the record…"
        />
      </UiField>

      <template #footer>
        <UiButton
          data-testid="assignment-request-decline-cancel"
          @click="decliningId = null"
        >
          Cancel
        </UiButton>
        <UiButton
          variant="danger"
          data-testid="assignment-request-decline-confirm"
          @click="confirmDecline"
        >
          Decline
        </UiButton>
      </template>
    </BaseDialog>
  </main>
</template>
