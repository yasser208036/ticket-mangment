import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { login as apiLogin, logout as apiLogout, me } from '../api/auth'
import { isUnauthorized } from '../api/errors'
import type { AuthUser } from '../api/auth'
import { clearToken, getToken, setToken } from '../api/token'
import { useMasterDataStore } from './masterData'
import { useWorkloadStore } from './workload'
import { useStatsStore } from './stats'

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(getToken())
  const user = ref<AuthUser | null>(null)
  let hydration: Promise<void> | null = null
  const isAuthenticated = computed(() => user.value !== null)
  const isAdmin = computed(() => user.value?.role === 'admin')

  async function login(email: string, password: string): Promise<void> {
    const response = await apiLogin(email, password)
    setToken(response.token)
    token.value = response.token
    user.value = response.user
  }

  async function hydrate(): Promise<void> {
    if (token.value === null || user.value !== null) return
    const pending =
      hydration ??
      (hydration = (async () => {
        try {
          user.value = await me()
        } catch (error) {
          if (isUnauthorized(error)) clear()
        }
      })().finally(() => {
        hydration = null
      }))
    await pending
  }

  async function logout(): Promise<void> {
    try {
      await apiLogout()
    } finally {
      clear()
    }
  }

  function clear(): void {
    useMasterDataStore().clear()
    useWorkloadStore().clear()
    useStatsStore().clear()
    token.value = null
    user.value = null
    hydration = null
    clearToken()
  }

  return {
    token,
    user,
    isAuthenticated,
    isAdmin,
    login,
    hydrate,
    logout,
    clear,
  }
})
