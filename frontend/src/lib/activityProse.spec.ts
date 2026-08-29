import { describe, expect, it } from 'vitest'
import type { TicketActivity } from '../api/activities'
import { activityReason, activitySentence, noteBody } from './activityProse'

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

describe('activitySentence', () => {
  it('renders "created this ticket" for a created event', () => {
    expect(activitySentence(activity({ event: 'created' }))).toBe(
      'Ahmed created this ticket',
    )
  })

  it('renders a category change with both labels', () => {
    const sentence = activitySentence(
      activity({
        event: 'category_changed',
        from_label: 'Hardware',
        to_label: 'Software',
      }),
    )
    expect(sentence).toBe('Ahmed changed category from Hardware to Software')
  })

  it('renders status_changed with no map entry required, the intake example sentence', () => {
    const sentence = activitySentence(
      activity({
        event: 'status_changed',
        from_label: 'Open',
        to_label: 'In Progress',
      }),
    )
    expect(sentence).toBe('Ahmed changed status from Open to In Progress')
  })

  it('renders an _added event with no named case, updated with field, claimed, and an unknown future event', () => {
    expect(activitySentence(activity({ event: 'attachment_added' }))).toBe(
      'Ahmed added attachment',
    )
    expect(
      activitySentence(activity({ event: 'updated', field: 'category_id' })),
    ).toBe('Ahmed updated category')
    expect(activitySentence(activity({ event: 'claimed' }))).toBe(
      'Ahmed claimed',
    )
    expect(activitySentence(activity({ event: 'some_future_event' }))).toBe(
      'Ahmed some future event',
    )
  })

  it('begins with "System" when actor is null', () => {
    expect(
      activitySentence(activity({ event: 'created', actor: null })),
    ).toMatch(/^System /)
  })

  it('omits the from/to clause when only to_label is present', () => {
    const sentence = activitySentence(
      activity({ event: 'assigned', from_label: null, to_label: 'Omar' }),
    )
    expect(sentence).not.toContain(' from ')
    expect(sentence).not.toContain(' to ')
  })
})

describe('activityReason', () => {
  it('returns the string when meta.reason is a real string', () => {
    expect(
      activityReason(activity({ meta: { reason: 'Because it broke' } })),
    ).toBe('Because it broke')
  })

  it('returns null for an empty string', () => {
    expect(activityReason(activity({ meta: { reason: '' } }))).toBeNull()
  })

  it('returns null when the key is missing', () => {
    expect(activityReason(activity({ meta: {} }))).toBeNull()
  })

  it('returns null for a non-string value', () => {
    expect(activityReason(activity({ meta: { reason: 42 } }))).toBeNull()
  })
})

describe('note_added prose', () => {
  it('reads "added an internal note", not the generic "added note"', () => {
    expect(activitySentence(activity({ event: 'note_added' }))).toBe(
      'Ahmed added an internal note',
    )
  })

  it('reads "System added an internal note" when actor is null', () => {
    expect(
      activitySentence(activity({ event: 'note_added', actor: null })),
    ).toBe('System added an internal note')
  })
})

describe('noteBody', () => {
  it('returns the string for a note_added activity with meta.note', () => {
    expect(
      noteBody(activity({ event: 'note_added', meta: { note: 'cb 3pm' } })),
    ).toBe('cb 3pm')
  })

  it('returns null when meta.note is missing, empty, or not a string', () => {
    expect(noteBody(activity({ event: 'note_added', meta: {} }))).toBeNull()
    expect(
      noteBody(activity({ event: 'note_added', meta: { note: '' } })),
    ).toBeNull()
    expect(
      noteBody(activity({ event: 'note_added', meta: { note: 42 } })),
    ).toBeNull()
  })

  it('returns null for a non-note_added event that happens to carry meta.note', () => {
    expect(
      noteBody(activity({ event: 'updated', meta: { note: 'not a note' } })),
    ).toBeNull()
  })
})
