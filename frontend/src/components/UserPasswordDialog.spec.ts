import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import type { InternalAxiosRequestConfig } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { resetUserPassword } from '../api/users'
import type { AdminUser } from '../api/users'
import UserPasswordDialog from './UserPasswordDialog.vue'

vi.mock('../api/users', async (loadOriginal) => ({
  ...(await loadOriginal()),
  resetUserPassword: vi.fn(),
}))

const target: AdminUser = {
  id: 3,
  name: 'Agent Smith',
  email: 'smith@example.test',
  role: 'agent',
  is_active: true,
  created_at: '2026-01-01T00:00:00Z',
}

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

// The dialog is teleported into the real document.body, which -- unlike a
// wrapper's own detached root -- survives past the end of a test unless
// explicitly unmounted; each mount is tracked here so afterEach can remove it
// and leave a clean body for the next test.
let mounted: ReturnType<typeof mount> | undefined

function render() {
  mounted = mount(UserPasswordDialog, { props: { user: target } })
  return mounted
}

// Query document.body directly rather than the wrapper, since the dialog's
// content lands as a sibling of the wrapper's own root in the real DOM.
function body() {
  return new DOMWrapper(document.body)
}

async function fillAndSubmit() {
  await body().get('[data-testid="user-password-new"]').setValue('new-secret-1')
  await body()
    .get('[data-testid="user-password-current"]')
    .setValue('admin-secret')
  await body().get('[data-testid="user-password-form"]').trigger('submit')
  await flushPromises()
}

describe('UserPasswordDialog', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.mocked(resetUserPassword).mockReset().mockResolvedValue()
  })

  afterEach(() => {
    mounted?.unmount()
    mounted = undefined
  })

  it('starts with both fields empty', () => {
    render()
    expect(
      body().get<HTMLInputElement>('[data-testid="user-password-new"]').element
        .value,
    ).toBe('')
    expect(
      body().get<HTMLInputElement>('[data-testid="user-password-current"]')
        .element.value,
    ).toBe('')
  })

  it('submits the new password and the admin own password', async () => {
    const wrapper = render()
    await fillAndSubmit()
    expect(resetUserPassword).toHaveBeenCalledWith(3, {
      password: 'new-secret-1',
      current_password: 'admin-secret',
    })
    expect(wrapper.emitted('saved')).toHaveLength(1)
  })

  it('renders a wrong own-password error under that field', async () => {
    vi.mocked(resetUserPassword).mockRejectedValue(
      failure(422, {
        message: 'The password is incorrect.',
        errors: { current_password: ['The password is incorrect.'] },
      }),
    )
    const wrapper = render()
    await fillAndSubmit()
    expect(
      body().get('[data-testid="user-password-error-current_password"]').text(),
    ).toBe('The password is incorrect.')
    expect(
      body().find('[data-testid="user-password-error-password"]').exists(),
    ).toBe(false)
    expect(wrapper.emitted('saved')).toBeUndefined()
  })

  it('renders a weak new password under the new-password field', async () => {
    vi.mocked(resetUserPassword).mockRejectedValue(
      failure(422, {
        message: 'Invalid.',
        errors: {
          password: ['The password field must be at least 8 characters.'],
        },
      }),
    )
    render()
    await fillAndSubmit()
    expect(
      body().get('[data-testid="user-password-error-password"]').text(),
    ).toBe('The password field must be at least 8 characters.')
  })

  it('renders a 403 as a form-level message', async () => {
    vi.mocked(resetUserPassword).mockRejectedValue(
      failure(403, { message: 'This action is unauthorized.' }),
    )
    render()
    await fillAndSubmit()
    expect(body().get('[data-testid="user-password-error"]').text()).toBe(
      'You do not have permission to do that.',
    )
  })

  it('cancels without calling the API', async () => {
    const wrapper = render()
    await body().get('[data-testid="user-password-cancel"]').trigger('click')
    expect(resetUserPassword).not.toHaveBeenCalled()
    expect(wrapper.emitted('close')).toHaveLength(1)
  })
})
