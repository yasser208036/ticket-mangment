import { flushPromises, mount } from '@vue/test-utils'
import { AxiosError } from 'axios'
import { createPinia } from 'pinia'
import { describe, expect, it, vi } from 'vitest'
import { addTicketNote } from '../api/activities'
import type { TicketActivity } from '../api/activities'
import { useTicketsStore } from '../stores/tickets'
import TicketNoteComposer from './TicketNoteComposer.vue'

vi.mock('../api/activities', async (loadOriginal) => ({
  ...(await loadOriginal()),
  addTicketNote: vi.fn(),
}))

function createdActivity(
  overrides: Partial<TicketActivity> = {},
): TicketActivity {
  return {
    id: 9,
    event: 'note_added',
    field: null,
    old_value: null,
    new_value: null,
    from_label: null,
    to_label: null,
    meta: { note: 'A fresh note.' },
    actor: { id: 1, name: 'Ahmed' },
    created_at: '2026-08-27T12:00:00Z',
    ...overrides,
  }
}

function mountComposer() {
  const wrapper = mount(TicketNoteComposer, {
    props: { ticketId: 1 },
    global: { plugins: [createPinia()] },
  })
  return { wrapper, store: useTicketsStore() }
}

describe('TicketNoteComposer', () => {
  it('submits the ticket id and body, clears the textarea, and prepends the note', async () => {
    vi.mocked(addTicketNote).mockResolvedValue(createdActivity())
    const { wrapper, store } = mountComposer()

    await wrapper.get('[data-testid="note-body"]').setValue('A fresh note.')
    await wrapper.get('[data-testid="note-composer"]').trigger('submit')
    await flushPromises()

    expect(addTicketNote).toHaveBeenCalledWith(1, 'A fresh note.')
    expect(
      (wrapper.get('[data-testid="note-body"]').element as HTMLTextAreaElement)
        .value,
    ).toBe('')
    expect(store.activities[0].id).toBe(9)
  })

  it('increments activitiesMeta.total by one', async () => {
    vi.mocked(addTicketNote).mockResolvedValue(createdActivity())
    const { wrapper, store } = mountComposer()
    store.activitiesMeta = {
      current_page: 1,
      from: 1,
      last_page: 1,
      path: '',
      per_page: 20,
      to: 3,
      total: 3,
    }

    await wrapper.get('[data-testid="note-body"]').setValue('A fresh note.')
    await wrapper.get('[data-testid="note-composer"]').trigger('submit')
    await flushPromises()

    expect(store.activitiesMeta.total).toBe(4)
  })

  it('renders the server field error and keeps the textarea content on a 422', async () => {
    vi.mocked(addTicketNote).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 422,
        data: { errors: { body: ['A note needs at least 3 characters.'] } },
      } as never),
    )
    const { wrapper } = mountComposer()

    await wrapper.get('[data-testid="note-body"]').setValue('ok')
    await wrapper.get('[data-testid="note-composer"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="note-error-body"]').text()).toBe(
      'A note needs at least 3 characters.',
    )
    expect(
      (wrapper.get('[data-testid="note-body"]').element as HTMLTextAreaElement)
        .value,
    ).toBe('ok')
    expect(wrapper.find('[data-testid="note-error"]').exists()).toBe(false)
  })

  it('renders a generic error and no field error for a non-422 failure', async () => {
    vi.mocked(addTicketNote).mockRejectedValue(
      new AxiosError('e', undefined, undefined, undefined, {
        status: 500,
        data: {},
      } as never),
    )
    const { wrapper } = mountComposer()

    await wrapper.get('[data-testid="note-body"]').setValue('A real note.')
    await wrapper.get('[data-testid="note-composer"]').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-testid="note-error"]').text()).toBe(
      'The API is unreachable.',
    )
    expect(wrapper.find('[data-testid="note-error-body"]').exists()).toBe(false)
  })

  it('calls addTicketNote once and disables the button on a double submit', async () => {
    vi.mocked(addTicketNote).mockClear()
    let resolveRequest: (value: TicketActivity) => void = () => {}
    vi.mocked(addTicketNote).mockReturnValue(
      new Promise((resolve) => {
        resolveRequest = resolve
      }),
    )
    const { wrapper } = mountComposer()
    await wrapper.get('[data-testid="note-body"]').setValue('A fresh note.')

    const form = wrapper.get('[data-testid="note-composer"]')
    await form.trigger('submit')
    await form.trigger('submit')

    expect(addTicketNote).toHaveBeenCalledTimes(1)
    expect(
      wrapper.get('[data-testid="note-submit"]').attributes('disabled'),
    ).toBeDefined()

    resolveRequest(createdActivity())
    await flushPromises()
  })

  it('prepends a note without throwing when activitiesMeta is null', async () => {
    vi.mocked(addTicketNote).mockResolvedValue(createdActivity())
    const { wrapper, store } = mountComposer()
    store.activitiesMeta = null

    await wrapper.get('[data-testid="note-body"]').setValue('A fresh note.')
    await expect(
      wrapper.get('[data-testid="note-composer"]').trigger('submit'),
    ).resolves.toBeUndefined()
    await flushPromises()

    expect(store.activities).toHaveLength(1)
  })

  it('renders the permanence hint', () => {
    const { wrapper } = mountComposer()
    expect(wrapper.get('[data-testid="note-hint"]').text()).toBe(
      'Notes are internal and permanent.',
    )
  })
})
