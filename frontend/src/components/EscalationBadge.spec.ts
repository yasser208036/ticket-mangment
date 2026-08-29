import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import EscalationBadge from './EscalationBadge.vue'

describe('EscalationBadge', () => {
  it('renders nothing at level 0', () => {
    const wrapper = mount(EscalationBadge, { props: { level: 0 } })
    expect(wrapper.find('[data-testid="escalation-badge"]').exists()).toBe(
      false,
    )
  })

  it('renders "Escalated" with no × at level 1', () => {
    const wrapper = mount(EscalationBadge, { props: { level: 1 } })
    const badge = wrapper.get('[data-testid="escalation-badge"]')
    expect(badge.text()).toBe('Escalated')
    expect(badge.text()).not.toContain('×')
    expect(badge.attributes('data-level')).toBe('1')
  })

  it('renders "Escalated ×2" at level 2', () => {
    const badge = mount(EscalationBadge, { props: { level: 2 } }).get(
      '[data-testid="escalation-badge"]',
    )
    expect(badge.text()).toBe('Escalated ×2')
    expect(badge.attributes('data-level')).toBe('2')
  })

  it('renders different background colours for level 1 and level 2', () => {
    const one = mount(EscalationBadge, { props: { level: 1 } }).get(
      '[data-testid="escalation-badge"]',
    )
    const two = mount(EscalationBadge, { props: { level: 2 } }).get(
      '[data-testid="escalation-badge"]',
    )
    expect(one.attributes('style')).not.toBe(two.attributes('style'))
  })

  it('renders "Escalated ×12" with no clamping', () => {
    const badge = mount(EscalationBadge, { props: { level: 12 } }).get(
      '[data-testid="escalation-badge"]',
    )
    expect(badge.text()).toBe('Escalated ×12')
  })
})
