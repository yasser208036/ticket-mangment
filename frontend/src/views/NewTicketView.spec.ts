import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createTicket } from '../api/tickets'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import NewTicketView from './NewTicketView.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  createTicket: vi.fn(),
}))

async function mountForm() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 1,
    name: 'Agent',
    email: 'agent@example.test',
    role: 'agent',
    is_active: true,
    created_at: '2026-08-25T00:00:00Z',
  }
  useMasterDataStore().categories = [
    {
      id: 3,
      name: 'Hardware',
      slug: 'hardware',
      color: '#111',
      is_active: true,
      sort_order: 1,
    } as never,
  ]
  useMasterDataStore().priorities = [
    {
      id: 2,
      name: 'Medium',
      slug: 'medium',
      level: 2,
      color: '#F59E0B',
      is_default: true,
    },
  ]
  useMasterDataStore().statuses = [
    {
      id: 1,
      name: 'New',
      slug: 'new',
      bucket: 'open',
      color: '#3B82F6',
      is_default: true,
      is_terminal: false,
      sort_order: 10,
    },
  ]
  const router = createAppRouter(createMemoryHistory())
  await router.push('/tickets/new')
  await router.isReady()
  const wrapper = mount(NewTicketView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return { wrapper, router }
}

async function fillValidForm(
  wrapper: Awaited<ReturnType<typeof mountForm>>['wrapper'],
): Promise<void> {
  await wrapper
    .get('[data-testid="new-ticket-requester-name"]')
    .setValue('Jane Doe')
  await wrapper
    .get('[data-testid="new-ticket-requester-email"]')
    .setValue('jane@example.test')
  await wrapper
    .get('[data-testid="new-ticket-subject"]')
    .setValue('Printer jam')
  await wrapper
    .get('[data-testid="new-ticket-description"]')
    .setValue('It is stuck badly.')
  await wrapper.get('[data-testid="new-ticket-category"]').setValue('3')
}

describe('NewTicketView', () => {
  beforeEach(() => {
    vi.mocked(createTicket).mockReset()
  })

  it('rejects an empty submit with all five client-side errors and sends no request', async () => {
    const { wrapper } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    expect(createTicket).not.toHaveBeenCalled()
    expect(
      wrapper.get('[data-testid="new-ticket-error-requester.name"]').text(),
    ).toBe('Name is required.')
    expect(wrapper.text()).toContain('Valid email is required.')
    expect(wrapper.text()).toContain('Subject is required.')
    expect(wrapper.text()).toContain('Description is required.')
    expect(wrapper.text()).toContain('Category is required.')
  })

  it('submits the expected payload and navigates to the new ticket', async () => {
    vi.mocked(createTicket).mockResolvedValue({ id: 42 } as never)
    const { wrapper, router } = await mountForm()
    await fillValidForm(wrapper)
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(createTicket).toHaveBeenCalledWith(
      expect.objectContaining({
        subject: 'Printer jam',
        description: 'It is stuck badly.',
        category_id: 3,
        priority_id: undefined,
        requester: expect.objectContaining({
          name: 'Jane Doe',
          email: 'jane@example.test',
        }),
      }),
    )
    expect(router.currentRoute.value.fullPath).toBe('/tickets/42')
  })

  it('calls createTicket once and disables Submit on a double click', async () => {
    let resolveCreate: (value: { id: number }) => void = () => {}
    vi.mocked(createTicket).mockReturnValue(
      new Promise((resolve) => {
        resolveCreate = resolve as never
      }),
    )
    const { wrapper } = await mountForm()
    await fillValidForm(wrapper)

    const form = wrapper.get('[data-testid="new-ticket-form"]')
    await form.trigger('submit')
    await form.trigger('submit')

    expect(createTicket).toHaveBeenCalledTimes(1)
    expect(
      wrapper.get('[data-testid="new-ticket-submit"]').attributes('disabled'),
    ).toBeDefined()
    resolveCreate({ id: 1 })
    await flushPromises()
  })

  it('merges a 422 response into field errors and shows no generic banner', async () => {
    vi.mocked(createTicket).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: {
          errors: { subject: ['That subject is already in use recently.'] },
        },
      } as never),
    )
    const { wrapper } = await mountForm()
    await fillValidForm(wrapper)
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('That subject is already in use recently.')
    expect(wrapper.find('[data-testid="new-ticket-error"]').exists()).toBe(
      false,
    )
  })

  it('shows a generic banner for a non-422 failure', async () => {
    vi.mocked(createTicket).mockRejectedValue(new Error('offline'))
    const { wrapper } = await mountForm()
    await fillValidForm(wrapper)
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="new-ticket-error"]').text()).toBe(
      'The API is unreachable.',
    )
  })
})
