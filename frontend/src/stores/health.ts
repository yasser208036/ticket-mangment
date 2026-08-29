import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getHealth } from '../api/health'
import type { HealthResponse } from '../api/health'

export const useHealthStore = defineStore('health', () => {
  const data = ref<HealthResponse | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function load(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      data.value = await getHealth()
    } catch (caughtError) {
      data.value = null
      error.value =
        caughtError instanceof Error
          ? caughtError.message
          : 'The API is unreachable.'
    } finally {
      loading.value = false
    }
  }

  return { data, error, loading, load }
})
