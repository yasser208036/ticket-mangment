import type { TicketActivity } from '../api/activities'

const SYSTEM_ACTOR = 'System'
const FIELD_LABELS: Record<string, string> = {
  category_id: 'category',
  status_id: 'status',
  priority_id: 'priority',
  assigned_to: 'assignee',
  escalation_level: 'escalation level',
}

export function actorName(activity: TicketActivity): string {
  return activity.actor?.name ?? SYSTEM_ACTOR
}

export function fieldLabel(field: string): string {
  return FIELD_LABELS[field] ?? field.replace(/_id$/, '').replace(/_/g, ' ')
}

// Derived from the shape of the event value, not from a list of events. See
// the rendering decision in the timeline plan: 'status_changed' with both
// labels yields "changed status from Open to In Progress" without an entry
// of its own.
export function eventPhrase(event: string, field: string | null): string {
  if (event === 'created') return 'created this ticket'
  if (event === 'note_added') return 'added an internal note'
  const changed = /^(.+)_changed$/.exec(event)
  if (changed) return `changed ${changed[1].replace(/_/g, ' ')}`
  const added = /^(.+)_added$/.exec(event)
  if (added) return `added ${added[1].replace(/_/g, ' ')}`
  if (event === 'updated' && field) return `updated ${fieldLabel(field)}`
  return event.replace(/_/g, ' ')
}

export function activitySentence(activity: TicketActivity): string {
  const phrase = eventPhrase(activity.event, activity.field)
  const { from_label: from, to_label: to } = activity
  const transition = from && to ? ` from ${from} to ${to}` : ''
  return `${actorName(activity)} ${phrase}${transition}`
}

export function activityReason(activity: TicketActivity): string | null {
  const reason = activity.meta.reason
  return typeof reason === 'string' && reason !== '' ? reason : null
}

// The event check matters: without it any future event that happens to carry
// a meta.note key would render a note block, and meta is a free-form object.
// Do not widen this to "any activity with a note".
export function noteBody(activity: TicketActivity): string | null {
  if (activity.event !== 'note_added') return null
  const note = activity.meta.note
  return typeof note === 'string' && note !== '' ? note : null
}
