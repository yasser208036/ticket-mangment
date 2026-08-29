import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import type { RouteLocationNormalized } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { login } from '../api/auth'
import type { AuthUser } from '../api/auth'
import { useAuthStore } from '../stores/auth'
import { createAppRouter } from './index'
import { authGuard, createUnauthorizedHandler, safeRedirect } from './guards'

vi.mock('../api/auth', async (loadOriginal) => ({
  ...(await loadOriginal()),
  login: vi.fn(),
  logout: vi.fn(),
  me: vi.fn(),
}))
const agent: AuthUser = {
  id: 1,
  name: 'Agent',
  email: 'a@e.test',
  role: 'agent',
  is_active: true,
  created_at: 'x',
}

describe('route guards', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  it('preserves intended private destination', async () => {
    const router = createAppRouter(createMemoryHistory())
    expect(
      await authGuard(
        router.resolve('/admin/users') as RouteLocationNormalized,
      ),
    ).toEqual({
      name: 'login',
      query: { redirect: '/admin/users' },
    })
  })

  it('sends agent to forbidden', async () => {
    vi.mocked(login).mockResolvedValue({
      token: 'x',
      token_type: 'Bearer',
      user: agent,
    })
    await useAuthStore().login('a', 'p')
    const router = createAppRouter(createMemoryHistory())
    expect(
      await authGuard(
        router.resolve('/admin/users') as RouteLocationNormalized,
      ),
    ).toEqual({
      name: 'forbidden',
    })
  })

  it('sanitises redirect targets', () => {
    expect(safeRedirect('/admin/users')).toBe('/admin/users')
    for (const unsafe of [
      '//evil.com',
      '/\\evil.com',
      'https://evil.com',
      undefined,
      ['/a'],
    ])
      expect(safeRedirect(unsafe)).toBe('/')
  })

  it('redirects only once for concurrent unauthorized responses', () => {
    const router = createAppRouter(createMemoryHistory())
    const replace = vi.spyOn(router, 'replace')
    const handler = createUnauthorizedHandler(router)
    handler()
    handler()
    expect(replace).toHaveBeenCalledTimes(1)
  })
})
