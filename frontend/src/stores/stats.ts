import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getTicketStats } from '../api/stats'
import type { TicketStats } from '../api/stats'
import { errorMessage } from '../api/errors'
export const useStatsStore = defineStore('stats', () => {
  const data = ref<TicketStats | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)
  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      data.value = await getTicketStats()
    } catch (caughtError) {
      data.value = null
      error.value = errorMessage(caughtError)
    } finally {
      loading.value = false
    }
  }
  function clear(): void {
    data.value = null
    error.value = null
  }
  return { data, error, loading, load, clear }
})
