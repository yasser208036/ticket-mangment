import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { deleteUser, listUsers, resetUserPassword } from '../api/users'
import type { AdminUser } from '../api/users'
import { useUsersStore } from './users'

vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listUsers: vi.fn(),
  deleteUser: vi.fn(),
  resetUserPassword: vi.fn(),
}))

function user(id: number): AdminUser {
  return {
    id,
    name: `User ${id}`,
    email: `user${id}@example.test`,
    role: 'agent',
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
  }
}

function page(users: AdminUser[], current = 1) {
  return {
    data: users,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: current,
      from: 1,
      last_page: current,
      path: '/admin/users',
      per_page: 15,
      to: users.length,
      total: users.length,
    },
  }
}

describe('users store — delete and password reset', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(listUsers).mockReset()
    vi.mocked(deleteUser).mockReset()
    vi.mocked(resetUserPassword).mockReset()
    vi.mocked(listUsers).mockResolvedValue(
      page([user(1)]) as unknown as Awaited<ReturnType<typeof listUsers>>,
    )
  })

  it('deletes and reloads the list', async () => {
    vi.mocked(deleteUser).mockResolvedValue()
    const store = useUsersStore()
    await store.remove(3)
    expect(deleteUser).toHaveBeenCalledWith(3, undefined)
    expect(listUsers).toHaveBeenCalledTimes(1)
  })

  it('passes the reassignment destination through', async () => {
    vi.mocked(deleteUser).mockResolvedValue()
    const store = useUsersStore()
    await store.remove(3, 7)
    expect(deleteUser).toHaveBeenCalledWith(3, 7)
  })

  it('steps back a page after deleting the only row of a later page', async () => {
    vi.mocked(deleteUser).mockResolvedValue()
    const store = useUsersStore()
    store.users = [user(9)]
    store.page = 3
    await store.remove(9)
    expect(store.page).toBe(2)
    expect(vi.mocked(listUsers).mock.calls[0][0]).toMatchObject({ page: 2 })
  })

  it('stays on page one when it was already the first page', async () => {
    vi.mocked(deleteUser).mockResolvedValue()
    const store = useUsersStore()
    store.users = [user(9)]
    await store.remove(9)
    expect(store.page).toBe(1)
  })

  it('re-throws so the view can inspect the 422', async () => {
    vi.mocked(deleteUser).mockRejectedValue(new Error('blocked'))
    const store = useUsersStore()
    await expect(store.remove(3)).rejects.toThrow('blocked')
    expect(listUsers).not.toHaveBeenCalled()
  })

  it('resets a password without reloading the list', async () => {
    vi.mocked(resetUserPassword).mockResolvedValue()
    const store = useUsersStore()
    await store.resetPassword(3, {
      password: 'brand-new-secret',
      current_password: 'admin-secret',
    })
    expect(resetUserPassword).toHaveBeenCalledWith(3, {
      password: 'brand-new-secret',
      current_password: 'admin-secret',
    })
    expect(listUsers).not.toHaveBeenCalled()
  })
})
