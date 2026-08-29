import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { TicketDetail } from '../api/tickets'
import TicketActionToolbar from './TicketActionToolbar.vue'

const ticket: TicketDetail = {
  id: 1,
  reference: 'TKT-2026-000001',
  subject: 'Printer jam',
  description: 'It is stuck.',
  requester: {
    id: 1,
    name: 'Req',
    email: 'req@example.test',
    phone: null,
    company: null,
  },
  category: {
    id: 1,
    name: 'Hardware',
    slug: 'hardware',
    color: '#111',
    is_active: true,
    sort_order: 1,
  } as never,
  priority: {
    id: 2,
    name: 'Medium',
    slug: 'medium',
    level: 2,
    color: '#F59E0B',
    is_default: true,
  },
  status: {
    id: 1,
    name: 'New',
    slug: 'new',
    bucket: 'open',
    color: '#3B82F6',
    is_default: true,
    is_terminal: false,
    sort_order: 10,
  },
  assignee: null,
  creator: null,
  escalation_level: 0,
  escalated_at: null,
  first_responded_at: null,
  resolved_at: null,
  closed_at: null,
  created_at: '2026-08-25T00:00:00Z',
  updated_at: '2026-08-25T00:00:00Z',
  escalated_by: null,
  escalation_reason: null,
  allowed_transitions: [],
  resolution: null,
  reopen_count: 0,
  can: {
    update: true,
    assign: true,
    claim: true,
    change_status: true,
    escalate: true,
    delete: true,
    add_note: true,
  },
}

describe('TicketActionToolbar', () => {
  it('renders the escalate button enabled and emits escalate on click', async () => {
    const wrapper = mount(TicketActionToolbar, { props: { ticket } })
    const button = wrapper.get('[data-testid="action-escalate"]')
    expect(button.attributes('disabled')).toBeUndefined()

    await button.trigger('click')
    expect(wrapper.emitted('escalate')).toHaveLength(1)
  })

  it('hides the escalate button when can.escalate is false', () => {
    const wrapper = mount(TicketActionToolbar, {
      props: { ticket: { ...ticket, can: { ...ticket.can, escalate: false } } },
    })
    expect(wrapper.find('[data-testid="action-escalate"]').exists()).toBe(false)
  })

  it('the Change status button is enabled and emits status on click', async () => {
    const wrapper = mount(TicketActionToolbar, { props: { ticket } })
    const button = wrapper.get('[data-testid="action-status"]')
    expect(button.attributes('disabled')).toBeUndefined()

    await button.trigger('click')
    expect(wrapper.emitted('status')).toHaveLength(1)
  })

  it('renders the assign button enabled and emits assign on click', async () => {
    const wrapper = mount(TicketActionToolbar, { props: { ticket } })
    const button = wrapper.get('[data-testid="action-assign"]')
    expect(button.attributes('disabled')).toBeUndefined()

    await button.trigger('click')
    expect(wrapper.emitted('assign')).toHaveLength(1)
  })

  it('hides the assign button when can.assign is false', () => {
    const wrapper = mount(TicketActionToolbar, {
      props: { ticket: { ...ticket, can: { ...ticket.can, assign: false } } },
    })
    expect(wrapper.find('[data-testid="action-assign"]').exists()).toBe(false)
  })

  it('Edit is still disabled', () => {
    const wrapper = mount(TicketActionToolbar, { props: { ticket } })
    expect(
      wrapper.get('[data-testid="action-edit"]').attributes('disabled'),
    ).toBeDefined()
  })
})
