import type {
  AssigneeFilter,
  TicketDirection,
  TicketSort,
} from '../api/tickets'
import type { LocationQuery, LocationQueryRaw } from 'vue-router'

export interface TicketQueryState {
  statusIds: number[]
  priorityIds: number[]
  categoryIds: number[]
  assignedTo: AssigneeFilter | ''
  escalated: boolean | null
  sort: TicketSort
  direction: TicketDirection
  page: number
  q: string
}
export const EMPTY_QUERY_STATE: TicketQueryState = {
  statusIds: [],
  priorityIds: [],
  categoryIds: [],
  assignedTo: '',
  escalated: null,
  sort: 'created_at',
  direction: 'desc',
  page: 1,
  q: '',
}
export function presetFor(openStatusIds: number[]): TicketQueryState {
  return {
    ...EMPTY_QUERY_STATE,
    assignedTo: 'me',
    statusIds: [...openStatusIds],
  }
}
const SORTS: TicketSort[] = [
  'created_at',
  'updated_at',
  'priority',
  'relevance',
  'escalated_at',
]
const DIRECTIONS: TicketDirection[] = ['asc', 'desc']

function queryText(value: LocationQuery[string]): string {
  return Array.isArray(value) ? (value[0] ?? '') : (value ?? '')
}
function ids(value: LocationQuery[string]): number[] {
  return queryText(value)
    .split(',')
    .map(Number)
    .filter((id) => Number.isInteger(id) && id > 0)
}

export function toQuery(state: TicketQueryState): LocationQueryRaw {
  return {
    ...(state.statusIds.length ? { status: state.statusIds.join(',') } : {}),
    ...(state.priorityIds.length
      ? { priority: state.priorityIds.join(',') }
      : {}),
    ...(state.categoryIds.length
      ? { category: state.categoryIds.join(',') }
      : {}),
    ...(state.assignedTo === '' ? {} : { assignee: String(state.assignedTo) }),
    ...(state.escalated === null ? {} : { escalated: String(state.escalated) }),
    ...(state.sort === 'created_at' || (state.sort === 'relevance' && state.q)
      ? {}
      : { sort: state.sort }),
    ...(state.direction === 'desc' ? {} : { direction: state.direction }),
    ...(state.page === 1 ? {} : { page: String(state.page) }),
    ...(state.q ? { q: state.q } : {}),
  }
}
export function fromQuery(query: LocationQuery): TicketQueryState {
  const sortText = queryText(query.sort)
  const directionText = queryText(query.direction)
  const assigneeText = queryText(query.assignee)
  const numericAssignee = Number(assigneeText)
  const page = Number(queryText(query.page))
  const q = String(queryText(query.q))
  const sort = SORTS.includes(sortText as TicketSort)
    ? (sortText as TicketSort)
    : q
      ? 'relevance'
      : 'created_at'
  return {
    statusIds: ids(query.status),
    priorityIds: ids(query.priority),
    categoryIds: ids(query.category),
    assignedTo:
      assigneeText === 'me' || assigneeText === 'unassigned'
        ? assigneeText
        : Number.isInteger(numericAssignee) && numericAssignee > 0
          ? numericAssignee
          : '',
    escalated:
      queryText(query.escalated) === 'true'
        ? true
        : queryText(query.escalated) === 'false'
          ? false
          : null,
    sort: sort === 'relevance' && q === '' ? 'created_at' : sort,
    direction: DIRECTIONS.includes(directionText as TicketDirection)
      ? (directionText as TicketDirection)
      : 'desc',
    page: Number.isInteger(page) && page > 0 ? page : 1,
    q,
  }
}
