import { defineStore } from 'pinia'
import { ref } from 'vue'
import {
  approveAssignmentRequest,
  declineAssignmentRequest,
  listAssignmentRequests,
} from '../api/assignmentRequests'
import type { AssignmentRequest } from '../api/assignmentRequests'
import { errorMessage } from '../api/errors'

export const useAssignmentRequestsStore = defineStore(
  'assignmentRequests',
  () => {
    const items = ref<AssignmentRequest[]>([])
    const error = ref<string | null>(null)
    const loading = ref(false)

    async function load(): Promise<void> {
      loading.value = true
      error.value = null
      try {
        const page = await listAssignmentRequests()
        items.value = page.data
      } catch (caughtError) {
        items.value = []
        error.value = errorMessage(caughtError)
      } finally {
        loading.value = false
      }
    }

    async function approve(id: number): Promise<void> {
      await approveAssignmentRequest(id)
      await load()
    }

    async function decline(id: number, note?: string): Promise<void> {
      await declineAssignmentRequest(id, note)
      await load()
    }

    return { items, error, loading, load, approve, decline }
  },
)
