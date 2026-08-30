import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory } from 'vue-router'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createTicket } from '../api/tickets'
import { listAgents } from '../api/agents'
import { createAppRouter } from '../router'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import NewTicketView from './NewTicketView.vue'

vi.mock('../api/tickets', async (loadOriginal) => ({
  ...(await loadOriginal()),
  createTicket: vi.fn(),
}))

vi.mock('../api/agents', async (loadOriginal) => ({
  ...(await loadOriginal()),
  listAgents: vi.fn(),
}))

async function mountForm() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().user = {
    id: 1,
    name: 'Dana Requester',
    email: 'dana@example.test',
    role: 'user',
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
    vi.mocked(listAgents).mockReset()
    vi.mocked(listAgents).mockResolvedValue([{ id: 9, name: 'Alan Turing' }])
  })

  it('renders no requester fields', async () => {
    const { wrapper } = await mountForm()
    expect(
      wrapper.find('[data-testid="new-ticket-requester-name"]').exists(),
    ).toBe(false)
    expect(
      wrapper.find('[data-testid="new-ticket-requester-email"]').exists(),
    ).toBe(false)
  })

  it('rejects an empty submit with client-side errors and sends no request', async () => {
    const { wrapper } = await mountForm()
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    expect(createTicket).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Subject is required.')
    expect(wrapper.text()).toContain('Description is required.')
    expect(wrapper.text()).toContain('Category is required.')
  })

  it('submits the expected payload with no requester key and navigates to the new ticket', async () => {
    vi.mocked(createTicket).mockResolvedValue({ id: 42 } as never)
    const { wrapper, router } = await mountForm()
    await fillValidForm(wrapper)
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(createTicket).toHaveBeenCalledWith({
      subject: 'Printer jam',
      description: 'It is stuck badly.',
      category_id: 3,
      priority_id: undefined,
      assigned_to: undefined,
    })
    expect(router.currentRoute.value.fullPath).toBe('/tickets/42')
  })

  it('includes assigned_to only when an agent is chosen', async () => {
    vi.mocked(createTicket).mockResolvedValue({ id: 42 } as never)
    const { wrapper } = await mountForm()
    await flushPromises()
    await fillValidForm(wrapper)
    await wrapper.get('[data-testid="ticket-form-agent"]').setValue('9')
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()

    expect(createTicket).toHaveBeenCalledWith(
      expect.objectContaining({ assigned_to: 9 }),
    )
  })

  it('leaves the form usable when listing agents fails', async () => {
    vi.mocked(listAgents).mockRejectedValue(new Error('offline'))
    vi.mocked(createTicket).mockResolvedValue({ id: 42 } as never)
    const { wrapper } = await mountForm()
    await flushPromises()

    const select = wrapper.get('[data-testid="ticket-form-agent"]')
    expect(select.findAll('option')).toHaveLength(1)

    await fillValidForm(wrapper)
    await wrapper.get('[data-testid="new-ticket-form"]').trigger('submit')
    await flushPromises()
    expect(createTicket).toHaveBeenCalled()
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
