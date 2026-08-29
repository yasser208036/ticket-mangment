import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { deleteUser, listUsers } from '../api/users'
import type { AdminUser } from '../api/users'
import { useAuthStore } from '../stores/auth'
import AdminUsersView from './AdminUsersView.vue'

vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listUsers: vi.fn(),
  deleteUser: vi.fn(),
}))

function user(id: number, name: string, role: 'admin' | 'agent'): AdminUser {
  return {
    id,
    name,
    email: `${name.toLowerCase()}@example.test`,
    role,
    is_active: true,
    created_at: '2026-01-01T00:00:00Z',
  }
}

const me = user(1, 'Admin', 'admin')
const other = user(2, 'Agent', 'agent')

function failure(status: number, data: unknown): AxiosError {
  const error = new AxiosError('Failed')
  error.response = {
    data,
    status,
    statusText: 'Failed',
    headers: {},
    config: {} as InternalAxiosRequestConfig,
  }
  return error
}

async function render() {
  const wrapper = mount(AdminUsersView)
  await flushPromises()
  return wrapper
}

describe('AdminUsersView — row actions', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    useAuthStore().user = me
    vi.mocked(deleteUser).mockReset().mockResolvedValue()
    vi.mocked(listUsers)
      .mockReset()
      .mockResolvedValue({
        data: [me, other],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          current_page: 1,
          from: 1,
          last_page: 1,
          path: '/admin/users',
          per_page: 15,
          to: 2,
          total: 2,
        },
      })
  })

  it('offers reset and delete on other rows only', async () => {
    const wrapper = await render()
    expect(wrapper.findAll('[data-testid="users-delete"]')).toHaveLength(1)
    expect(
      wrapper.findAll('[data-testid="users-reset-password"]'),
    ).toHaveLength(1)
    const rows = wrapper.findAll('[data-testid="users-row"]')
    // The signed-in admin is the first row: neither control is rendered there,
    // rather than rendered and disabled.
    expect(rows[0].find('[data-testid="users-delete"]').exists()).toBe(false)
    expect(rows[1].find('[data-testid="users-delete"]').exists()).toBe(true)
  })

  it('deletes straight away when nothing blocks it', async () => {
    const wrapper = await render()
    await wrapper.get('[data-testid="users-delete"]').trigger('click')
    await flushPromises()
    expect(deleteUser).toHaveBeenCalledWith(2, undefined)
    expect(wrapper.find('[data-testid="user-delete"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="users-notice"]').text()).toContain(
      'Agent was deleted.',
    )
  })

  it('opens the reassignment dialog when the delete is blocked', async () => {
    vi.mocked(deleteUser).mockRejectedValue(
      failure(422, {
        message: 'This user still has 2 tickets.',
        ticket_count: 2,
        reassign_to_options: [me],
      }),
    )
    const wrapper = await render()
    await wrapper.get('[data-testid="users-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="user-delete-count"]').text()).toContain(
      '2',
    )
  })

  it('shows any other failure in the list error region', async () => {
    vi.mocked(deleteUser).mockRejectedValue(
      failure(422, {
        message: 'This is the last active administrator.',
        errors: { user: ['This is the last active administrator.'] },
      }),
    )
    const wrapper = await render()
    await wrapper.get('[data-testid="users-delete"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="user-delete"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="users-error"]').text()).toContain(
      'This is the last active administrator.',
    )
  })

  it('opens the password dialog for another user', async () => {
    const wrapper = await render()
    await wrapper.get('[data-testid="users-reset-password"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="user-password-form"]').exists()).toBe(
      true,
    )
  })
})
