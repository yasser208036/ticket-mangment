import axios from 'axios'
import type { AxiosError, InternalAxiosRequestConfig } from 'axios'
import { clearToken, getToken } from './token'

const SESSION_PATHS = ['/auth/login', '/auth/me', '/auth/logout']
let onUnauthorized: () => void = () => window.location.assign('/login')

export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler
}

export const client = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: { Accept: 'application/json' },
  timeout: 10_000,
})

client.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = getToken()

  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

client.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (
      error.response?.status === 401 &&
      !SESSION_PATHS.includes(error.config?.url ?? '')
    ) {
      clearToken()
      onUnauthorized()
    }
    return Promise.reject(error)
  },
)

export default client
