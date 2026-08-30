import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
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

// The dialog is teleported into the real document.body, which -- unlike a
// wrapper's own detached root -- survives past the end of a test unless
// explicitly unmounted; each mount is tracked here so afterEach can remove it
// and leave a clean body for the next test.
let mounted: ReturnType<typeof mount> | undefined

function render(withBlocked: UserDeleteBlocked | null) {
  mounted = mount(UserDeleteDialog, {
    props: { user: target, blocked: withBlocked },
  })
  return mounted
}

// Query document.body directly rather than the wrapper, since the dialog's
// content lands as a sibling of the wrapper's own root in the real DOM.
function body() {
  return new DOMWrapper(document.body)
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

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('renders the ticket count and the destination picker when blocked', () => {
    render(blocked)
    expect(body().get('[data-testid="user-delete-count"]').text()).toContain(
      '2',
    )
    expect(body().find('[data-testid="user-delete-target"]').exists()).toBe(
      true,
    )
  })

  it('keeps confirm disabled until a destination is chosen', async () => {
    render(blocked)
    const confirm = body().get('[data-testid="user-delete-confirm"]')
    expect(confirm.attributes('disabled')).toBeDefined()
    await body()
      .get('[data-testid="user-delete-target"]')
      .setValue(String(heir.id))
    expect(confirm.attributes('disabled')).toBeUndefined()
  })

  it('shows a plain confirmation with no picker when nothing blocks', () => {
    render(null)
    expect(body().find('[data-testid="user-delete-target"]').exists()).toBe(
      false,
    )
    expect(
      body().get('[data-testid="user-delete-confirm"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('deletes with the chosen destination and emits deleted', async () => {
    const wrapper = render(blocked)
    await body()
      .get('[data-testid="user-delete-target"]')
      .setValue(String(heir.id))
    await body().get('[data-testid="user-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(deleteUser).toHaveBeenCalledWith(3, 7)
    expect(wrapper.emitted('deleted')).toHaveLength(1)
  })

  it('renders a failure inside the dialog', async () => {
    vi.mocked(deleteUser).mockRejectedValue(new Error('Network Error'))
    const wrapper = render(null)
    await body().get('[data-testid="user-delete-confirm"]').trigger('click')
    await flushPromises()
    expect(body().get('[data-testid="user-delete-error"]').text()).toBe(
      'The API is unreachable.',
    )
    expect(wrapper.emitted('deleted')).toBeUndefined()
  })

  it('cancels without calling the API', async () => {
    const wrapper = render(blocked)
    await body().get('[data-testid="user-delete-cancel"]').trigger('click')
    expect(deleteUser).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
