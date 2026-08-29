import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { login, logout, me } from '../api/auth'
import type { AuthUser } from '../api/auth'
import { useAuthStore } from './auth'

vi.mock('../api/auth', async (loadOriginal) => ({
  ...(await loadOriginal()),
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}))

const agent: AuthUser = {
  id: 1,
  name: 'Agent',
  email: 'agent@example.test',
  role: 'agent',
  is_active: true,
  created_at: '2026-08-25T00:00:00Z',
}

describe('auth store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('reads persisted token without treating it as session', () => {
    localStorage.setItem('tm.token', 'abc')
    const auth = useAuthStore()
    expect(auth.token).toBe('abc')
    expect(auth.isAuthenticated).toBe(false)
  })

  it('login stores token and user', async () => {
    vi.mocked(login).mockResolvedValue({
      token: 'abc',
      token_type: 'Bearer',
      user: agent,
    })
    const auth = useAuthStore()
    await auth.login('a', 'p')
    expect(auth.user).toEqual(agent)
    expect(localStorage.getItem('tm.token')).toBe('abc')
  })

  it('hydrates once for concurrent callers', async () => {
    localStorage.setItem('tm.token', 'abc')
    vi.mocked(me).mockResolvedValue(agent)
    const auth = useAuthStore()
    await Promise.all([auth.hydrate(), auth.hydrate(), auth.hydrate()])
    expect(me).toHaveBeenCalledTimes(1)
    expect(auth.user).toEqual(agent)
  })

  it('clears dead token on 401', async () => {
    localStorage.setItem('tm.token', 'abc')
    vi.mocked(me).mockRejectedValue(
      new AxiosError('bad', undefined, undefined, undefined, {
        status: 401,
      } as never),
    )
    const auth = useAuthStore()
    await auth.hydrate()
    expect(auth.token).toBeNull()
    expect(localStorage.getItem('tm.token')).toBeNull()
  })

  it('keeps token on network failure', async () => {
    localStorage.setItem('tm.token', 'abc')
    vi.mocked(me).mockRejectedValue(new Error('offline'))
    const auth = useAuthStore()
    await auth.hydrate()
    expect(auth.token).toBe('abc')
    expect(auth.user).toBeNull()
  })

  it('logout clears even when request fails', async () => {
    vi.mocked(login).mockResolvedValue({
      token: 'abc',
      token_type: 'Bearer',
      user: agent,
    })
    vi.mocked(logout).mockRejectedValue(new Error('offline'))
    const auth = useAuthStore()
    await auth.login('a', 'p')
    await expect(auth.logout()).rejects.toThrow()
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('tm.token')).toBeNull()
  })
})
