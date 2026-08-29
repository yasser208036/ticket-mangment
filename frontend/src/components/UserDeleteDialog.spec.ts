import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { deleteUser, listUsers } from '../api/users'
import type { AdminUser, UserDeleteBlocked } from '../api/users'
import UserDeleteDialog from './UserDeleteDialog.vue'

vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listUsers: vi.fn(),
  deleteUser: vi.fn(),
}))

const target: AdminUser = {
  id: 3,
  name: 'Departing Agent',
  email: 'departing@example.test',
  role: 'agent',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

const heir: AdminUser = { ...target, id: 7, name: 'Heir' }

const blocked: UserDeleteBlocked = {
  message: 'This user still has 2 tickets.',
  ticket_count: 2,
  reassign_to_options: [heir],
}

function render(withBlocked: UserDeleteBlocked | null) {
  return mount(UserDeleteDialog, {
    props: { user: target, blocked: withBlocked },
  })
}

describe('UserDeleteDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(deleteUser).mockReset().mockResolvedValue()
    vi.mocked(listUsers)
      .mockReset()
      .mockResolvedValue({
        data: [],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          current_page: 1,
          from: null,
          last_page: 1,
          path: '/admin/users',
          per_page: 15,
          to: null,
          total: 0,
        },
      })
  })

  it('renders the ticket count and the destination picker when blocked', () => {
    const wrapper = render(blocked)
    expect(wrapper.get('[data-testid="user-delete-count"]').text()).toContain(
      '2',
    )
    expect(wrapper.find('[data-testid="user-delete-target"]').exists()).toBe(
      true,
    )
  })

  it('keeps confirm disabled until a destination is chosen', async () => {
    const wrapper = render(blocked)
    const confirm = wrapper.get('[data-testid="user-delete-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    await wrapper
      .get('[data-testid="user-delete-target"]')
      .setValue(String(heir.id))
    expect(confirm.attributes('disabled')).toBeUndefined()
  })

  it('shows a plain confirmation with no picker when nothing blocks', () => {
    const wrapper = render(null)
    expect(wrapper.find('[data-testid="user-delete-target"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.get('[data-testid="user-delete-confirm"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('deletes with the chosen destination and emits deleted', async () => {
    const wrapper = render(blocked)
    await wrapper
      .get('[data-testid="user-delete-target"]')
      .setValue(String(heir.id))
    await wrapper.get('[data-testid="user-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteUser).toHaveBeenCalledWith(3, 7)
    expect(wrapper.emitted('deleted')).toHaveLength(1)
  })

  it('renders a failure inside the dialog', async () => {
    vi.mocked(deleteUser).mockRejectedValue(new Error('Network Error'))
    const wrapper = render(null)
    await wrapper.get('[data-testid="user-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="user-delete-error"]').text()).toBe(
      'The API is unreachable.',
    )
    expect(wrapper.emitted('deleted')).toBeUndefined()
  })

  it('cancels without calling the API', async () => {
    const wrapper = render(blocked)
    await wrapper.get('[data-testid="user-delete-cancel"]').trigger('click')
    expect(deleteUser).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
