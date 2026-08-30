export interface EventDescriptor {
  color: string
  path: string
}

const FALLBACK: EventDescriptor = {
  color: '#6B7280',
  path: 'M8 1a7 7 0 100 14A7 7 0 008 1zm0 3.2a.9.9 0 110 1.8.9.9 0 010-1.8zm.8 3.3v4.3H7.2V7.5h1.6z',
}

// One entry per event that has an established meta shape. An event with no
// entry renders through FALLBACK -- adding a bespoke entry later is a single
// line and requires no other change to the timeline (see activityProse.ts).
const DESCRIPTORS: Record<string, EventDescriptor> = {
  created: { color: '#0F766E', path: 'M8 2v12M2 8h12' },
  category_changed: { color: '#7C3AED', path: 'M2 4h5l2 2h5v6H2z' },
  status_changed: { color: '#2563EB', path: 'M2 8h12M8 2l6 6-6 6' },
  updated: { color: '#0891B2', path: 'M2 12l1-4 9-9 3 3-9 9-4 1z' },
  assigned: {
    color: '#059669',
    path: 'M8 2a3 3 0 100 6 3 3 0 000-6zM2 14c0-3 3-5 6-5s6 2 6 5',
  },
  claimed: {
    color: '#059669',
    path: 'M8 2a3 3 0 100 6 3 3 0 000-6zM2 14c0-3 3-5 6-5s6 2 6 5',
  },
  unassigned: {
    color: '#B45309',
    path: 'M8 2a3 3 0 100 6 3 3 0 000-6zM2 14c0-3 3-5 6-5s6 2 6 5M2 2l12 12',
  },
  reopened: { color: '#2563EB', path: 'M13 8A5 5 0 113 8h2M3 8l-2-2m2 2l-2 2' },
  escalated: { color: '#DC2626', path: 'M8 12V4M4 8l4-4 4 4' },
  stale: { color: '#78716C', path: 'M8 4v4l3 2' },
  deleted: { color: '#DC2626', path: 'M4 4h8M6 4V2h4v2M5 4l1 10h4l1-10' },
  note_added: { color: '#EA580C', path: 'M3 2h7l3 3v9H3zM9 2v4h4' },
  assignment_requested: {
    color: '#4F46E5',
    path: 'M8 2a3 3 0 100 6 3 3 0 000-6zM2 14c0-3 3-5 6-5s6 2 6 5',
  },
  assignment_request_declined: {
    color: '#B45309',
    path: 'M8 2a3 3 0 100 6 3 3 0 000-6zM2 14c0-3 3-5 6-5s6 2 6 5M2 2l12 12',
  },
}

export function eventDescriptor(event: string): EventDescriptor {
  return DESCRIPTORS[event] ?? FALLBACK
}
