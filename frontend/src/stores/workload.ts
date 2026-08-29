import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getWorkload } from '../api/workload'
import type { Workload } from '../api/workload'
import { errorMessage } from '../api/errors'
export const useWorkloadStore = defineStore('workload', () => {
  const data = ref<Workload | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)
  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      data.value = await getWorkload()
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
