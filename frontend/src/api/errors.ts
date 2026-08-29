import axios from 'axios'
export function isUnauthorized(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 401
}
export function isNotFound(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 404
}
export function validationErrors(error: unknown): Record<string, string[]> {
  if (!axios.isAxiosError(error) || error.response?.status !== 422) return {}
  const data = error.response.data as
    { errors?: Record<string, string[]> } | undefined
  return data?.errors ?? {}
}
export function errorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string } | undefined
    if (error.response?.status === 429)
      return 'Too many attempts. Try again in a minute.'
    if (error.response?.status === 403)
      return 'You do not have permission to do that.'
    if (data?.message) return data.message
  }
  return 'The API is unreachable.'
}
