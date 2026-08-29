import { describe, expect, it } from 'vitest'
import { eventDescriptor } from './activityEvents'

const HEX_PATTERN = /^#[0-9A-Fa-f]{6}$/

describe('eventDescriptor', () => {
  it('returns distinct colours and paths for created and category_changed', () => {
    const created = eventDescriptor('created')
    const categoryChanged = eventDescriptor('category_changed')
    expect(created.color).not.toBe(categoryChanged.color)
    expect(created.path).not.toBe(categoryChanged.path)
  })

  it('gives note_added a descriptor distinct from created, category_changed, and the fallback', () => {
    const note = eventDescriptor('note_added')
    expect(note.color).not.toBe(eventDescriptor('created').color)
    expect(note.color).not.toBe(eventDescriptor('category_changed').color)
    expect(note.color).not.toBe(
      eventDescriptor('a_truly_fictional_event').color,
    )
  })

  it('falls back to a neutral descriptor for a fictional event', () => {
    const descriptor = eventDescriptor('some_event_nobody_has_written_yet')
    expect(descriptor.path.length).toBeGreaterThan(0)
    expect(descriptor.color).toMatch(HEX_PATTERN)
  })

  it('gives every known descriptor a colour readableTextColor can consume', () => {
    for (const event of [
      'created',
      'category_changed',
      'status_changed',
      'updated',
      'assigned',
      'claimed',
      'unassigned',
      'reopened',
      'escalated',
      'stale',
      'deleted',
      'note_added',
    ]) {
      expect(eventDescriptor(event).color).toMatch(HEX_PATTERN)
    }
  })
})
