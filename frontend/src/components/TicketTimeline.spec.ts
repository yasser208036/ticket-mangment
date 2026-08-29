import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { listTicketActivities } from '../api/activities'
import type { TicketActivity } from '../api/activities'
import type { Paginated } from '../api/pagination'
import { useTicketsStore } from '../stores/tickets'
import TicketTimeline from './TicketTimeline.vue'

vi.mock('../api/activities', () => ({ listTicketActivities: vi.fn() }))

function activity(overrides: Partial<TicketActivity> = {}): TicketActivity {
  return {
    id: 1,
    event: 'created',
    field: null,
    old_value: null,
    new_value: null,
    from_label: null,
    to_label: null,
    meta: {},
    actor: { id: 1, name: 'Ahmed' },
    created_at: '2026-08-27T12:00:00Z',
    ...overrides,
  }
}

function page(
  data: TicketActivity[],
  metaOverrides: Partial<Paginated<TicketActivity>['meta']> = {},
): Paginated<TicketActivity> {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length ? 1 : null,
      last_page: 1,
      path: '',
      per_page: 20,
      to: data.length,
      total: data.length,
      ...metaOverrides,
    },
  }
}

function mountTimeline() {
  const wrapper = mount(TicketTimeline, {
    global: { plugins: [createPinia()] },
  })
  return { wrapper, store: useTicketsStore() }
}

describe('TicketTimeline', () => {
  beforeEach(() => vi.mocked(listTicketActivities).mockReset())

  it('shows the loading state and no list while a request is in flight', async () => {
    let resolveRequest: (value: Paginated<TicketActivity>) => void = () => {}
    vi.mocked(listTicketActivities).mockReturnValue(
      new Promise((resolve) => {
        resolveRequest = resolve
      }),
    )
    const { wrapper, store } = mountTimeline()
    void store.loadActivities(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="timeline-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="timeline-list"]').exists()).toBe(false)
    resolveRequest(page([]))
    await flushPromises()
  })

  it("shows the store's error message on failure", async () => {
    const { wrapper, store } = mountTimeline()
    store.activitiesError = 'Could not load history.'
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="timeline-error"]').text()).toBe(
      'Could not load history.',
    )
  })

  it('shows the empty state for a ticket with no activities', async () => {
    vi.mocked(listTicketActivities).mockResolvedValue(page([]))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="timeline-empty"]').exists()).toBe(true)
  })

  it('renders one entry per activity, in payload order, with data-event set', async () => {
    const rows = [
      activity({ id: 3, event: 'escalated' }),
      activity({ id: 2, event: 'category_changed' }),
      activity({ id: 1, event: 'created' }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const entries = wrapper.findAll('[data-testid="timeline-entry"]')
    expect(entries).toHaveLength(3)
    expect(entries.map((entry) => entry.attributes('data-event'))).toEqual([
      'escalated',
      'category_changed',
      'created',
    ])
    entries.forEach((entry) => {
      expect(entry.find('[data-testid="timeline-sentence"]').exists()).toBe(
        true,
      )
    })
  })

  it('marks a system row distinctly and a user row plainly', async () => {
    const rows = [
      activity({ id: 1, actor: null }),
      activity({ id: 2, actor: { id: 5, name: 'Nadia' } }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const entries = wrapper.findAll('[data-testid="timeline-entry"]')
    expect(entries[0].attributes('data-system')).toBe('true')
    expect(entries[0].get('[data-testid="timeline-sentence"]').text()).toMatch(
      /^System/,
    )
    expect(entries[1].attributes('data-system')).toBeUndefined()
  })

  it('gives different events a different icon background colour', async () => {
    const rows = [
      activity({ id: 1, event: 'created' }),
      activity({ id: 2, event: 'category_changed' }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const icons = wrapper.findAll('[data-testid="timeline-icon"]')
    expect(icons[0].attributes('style')).not.toBe(icons[1].attributes('style'))
  })

  it('renders values for a field change and omits them otherwise', async () => {
    const rows = [
      activity({
        id: 1,
        event: 'category_changed',
        field: 'category_id',
        from_label: 'Hardware',
        to_label: 'Software',
      }),
      activity({ id: 2, event: 'created', field: null }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const entries = wrapper.findAll('[data-testid="timeline-entry"]')
    expect(entries[0].get('[data-testid="timeline-values"]').text()).toBe(
      'Hardware → Software',
    )
    expect(entries[1].find('[data-testid="timeline-values"]').exists()).toBe(
      false,
    )
  })

  it('renders a reason when present and omits it when meta is empty', async () => {
    const rows = [
      activity({ id: 1, meta: { reason: 'category_deleted' } }),
      activity({ id: 2, meta: {} }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const entries = wrapper.findAll('[data-testid="timeline-entry"]')
    expect(entries[0].get('[data-testid="timeline-reason"]').text()).toBe(
      'category_deleted',
    )
    expect(entries[1].find('[data-testid="timeline-reason"]').exists()).toBe(
      false,
    )
  })

  it('renders a <script> reason as literal text and creates no script element', async () => {
    const rows = [
      activity({ id: 1, meta: { reason: '<script>alert(1)</script>' } }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.get('[data-testid="timeline-reason"]').text()).toContain(
      '<script>',
    )
  })

  it('states the total when there is more than one page, and says nothing when there is only one', async () => {
    vi.mocked(listTicketActivities).mockResolvedValue(
      page([activity()], { last_page: 3, total: 45 }),
    )
    const truncated = mountTimeline()
    await truncated.store.loadActivities(1)
    await truncated.wrapper.vm.$nextTick()
    expect(
      truncated.wrapper.get('[data-testid="timeline-truncated"]').text(),
    ).toContain('45')

    vi.mocked(listTicketActivities).mockResolvedValue(
      page([activity()], { last_page: 1 }),
    )
    const single = mountTimeline()
    await single.store.loadActivities(1)
    await single.wrapper.vm.$nextTick()
    expect(
      single.wrapper.find('[data-testid="timeline-truncated"]').exists(),
    ).toBe(false)
  })

  it('renders a note_added entry with its body and no timeline-values', async () => {
    const rows = [
      activity({
        id: 1,
        event: 'note_added',
        meta: { note: 'Root cause was the firmware.' },
      }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.get('[data-testid="timeline-note"]').text()).toBe(
      'Root cause was the firmware.',
    )
    expect(wrapper.find('[data-testid="timeline-values"]').exists()).toBe(false)
  })

  it('renders a <script> note body as literal text and creates no script element', async () => {
    const rows = [
      activity({
        id: 1,
        event: 'note_added',
        meta: { note: '<script>alert(1)</script>' },
      }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.get('[data-testid="timeline-note"]').text()).toContain(
      '<script>',
    )
  })

  it('keeps both lines of a multi-line note in one timeline-note node', async () => {
    const rows = [
      activity({
        id: 1,
        event: 'note_added',
        meta: { note: 'line one\nline two' },
      }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const notes = wrapper.findAll('[data-testid="timeline-note"]')
    expect(notes).toHaveLength(1)
    expect(notes[0].text()).toContain('line one')
    expect(notes[0].text()).toContain('line two')
  })

  it('renders no timeline-note for a non-note entry', async () => {
    const rows = [activity({ id: 1, event: 'created' })]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="timeline-note"]').exists()).toBe(false)
  })

  it('places a new note_added entry above an older status_changed entry when ordered newest-first', async () => {
    const rows = [
      activity({ id: 2, event: 'note_added', meta: { note: 'Latest note.' } }),
      activity({ id: 1, event: 'status_changed' }),
    ]
    vi.mocked(listTicketActivities).mockResolvedValue(page(rows))
    const { wrapper, store } = mountTimeline()
    await store.loadActivities(1)
    await wrapper.vm.$nextTick()

    const entries = wrapper.findAll('[data-testid="timeline-entry"]')
    expect(entries[0].attributes('data-event')).toBe('note_added')
    expect(entries[1].attributes('data-event')).toBe('status_changed')
  })

  it('discards a stale response when a newer ticket is requested first', async () => {
    const ticketOneRow = page([activity({ id: 1, event: 'created' })])
    const ticketTwoRow = page([activity({ id: 2, event: 'escalated' })])
    let resolveFirst: (value: Paginated<TicketActivity>) => void = () => {}
    vi.mocked(listTicketActivities).mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          resolveFirst = resolve
        }),
    )
    vi.mocked(listTicketActivities).mockImplementationOnce(() =>
      Promise.resolve(ticketTwoRow),
    )
    const { wrapper, store } = mountTimeline()

    const first = store.loadActivities(1)
    const second = store.loadActivities(2)
    await second
    resolveFirst(ticketOneRow)
    await first
    await wrapper.vm.$nextTick()

    const entries = wrapper.findAll('[data-testid="timeline-entry"]')
    expect(entries).toHaveLength(1)
    expect(entries[0].attributes('data-event')).toBe('escalated')
  })
})
