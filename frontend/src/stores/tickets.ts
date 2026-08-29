import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import {
  assignTicket,
  changeTicketStatus,
  claimTicket,
  createTicket,
  escalateTicket,
  getTicket,
  listTickets,
} from '../api/tickets'
import type {
  ChangeStatusPayload,
  CreateTicketPayload,
  Ticket,
  TicketDetail,
  TicketListItem,
  AssigneeFilter,
  TicketDirection,
  TicketSort,
} from '../api/tickets'
import { addTicketNote, listTicketActivities } from '../api/activities'
import type { TicketActivity } from '../api/activities'
import type { Paginated } from '../api/pagination'
import { errorMessage, isNotFound } from '../api/errors'
import { EMPTY_QUERY_STATE } from '../lib/ticketQuery'
import { useStatsStore } from './stats'
export const useTicketsStore = defineStore('tickets', () => {
  const creating = ref(false)
  const items = ref<TicketListItem[]>([])
  const meta = ref<Paginated<TicketListItem>['meta'] | null>(null)
  const page = ref(1)
  const perPage = ref(15)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const statusIds = ref<number[]>([])
  const priorityIds = ref<number[]>([])
  const categoryIds = ref<number[]>([])
  const assignedTo = ref<AssigneeFilter | ''>('')
  const escalated = ref<boolean | null>(null)
  const q = ref('')
  const sort = ref<TicketSort>('created_at')
  const direction = ref<TicketDirection>('desc')
  const activeFilterCount = computed(
    () =>
      (statusIds.value.length ? 1 : 0) +
      (priorityIds.value.length ? 1 : 0) +
      (categoryIds.value.length ? 1 : 0) +
      (assignedTo.value === '' ? 0 : 1) +
      (escalated.value === null ? 0 : 1) +
      (q.value === '' ? 0 : 1),
  )
  let latestRequest = 0
  const current = ref<TicketDetail | null>(null)
  const detailLoading = ref(false)
  const detailError = ref<string | null>(null)
  const detailNotFound = ref(false)
  const assigning = ref(false)
  const claiming = ref(false)
  const escalating = ref(false)
  const changingStatus = ref(false)
  const activities = ref<TicketActivity[]>([])
  const activitiesMeta = ref<Paginated<TicketActivity>['meta'] | null>(null)
  const activitiesLoading = ref(false)
  const activitiesError = ref<string | null>(null)
  const noteSaving = ref(false)
  let latestActivitiesRequest = 0
  async function create(payload: CreateTicketPayload): Promise<Ticket> {
    creating.value = true
    try {
      return await createTicket(payload)
    } finally {
      creating.value = false
    }
  }
  async function loadTicket(id: number): Promise<void> {
    detailLoading.value = true
    detailError.value = null
    detailNotFound.value = false
    current.value = null
    try {
      current.value = await getTicket(id)
    } catch (caughtError) {
      if (isNotFound(caughtError)) detailNotFound.value = true
      else detailError.value = errorMessage(caughtError)
    } finally {
      detailLoading.value = false
    }
  }
  async function assign(
    id: number,
    assignedTo: number | null,
    reason?: string,
  ): Promise<void> {
    assigning.value = true
    try {
      await assignTicket(id, assignedTo, reason)
      // Re-read rather than patching `current`: the assign response omits
      // `can`, and can.claim flips the moment a ticket gains an assignee.
      // `loadTicket()` is not reused here for the same reason changeStatus()
      // avoids it -- it nulls `current` first, which momentarily fails the
      // assign dialog's `v-if="... && store.current"` guard and unmounts it
      // mid-submit, so the dialog never receives its own `assigned` emit.
      current.value = await getTicket(id)
      void useStatsStore().load()
    } finally {
      assigning.value = false
    }
  }
  async function claim(id: number): Promise<void> {
    claiming.value = true
    try {
      await claimTicket(id)
      await loadTicket(id)
      void useStatsStore().load()
    } finally {
      claiming.value = false
    }
  }
  async function escalate(id: number, reason: string): Promise<void> {
    escalating.value = true
    try {
      await escalateTicket(id, reason)
      // Re-read: escalating changes the priority, the assignee, and
      // can.escalate itself, and the response omits the last of those.
      // Not via loadTicket() -- it nulls `current` first, unmounting the
      // escalate dialog mid-confirm so its `escalated` emit is dropped. Same
      // hazard assign() and changeStatus() document.
      current.value = await getTicket(id)
      void useStatsStore().load()
    } finally {
      escalating.value = false
    }
  }
  async function changeStatus(
    id: number,
    payload: ChangeStatusPayload,
  ): Promise<void> {
    changingStatus.value = true
    try {
      await changeTicketStatus(id, payload)
      // Re-read rather than patching `current`: the status response omits
      // `can` and `allowed_transitions`, and both are stale the moment the
      // status moves -- can.escalate flips on a terminal status and the
      // legal moves change wholesale. `loadTicket()` is not reused here: it
      // nulls `current` before refetching, which momentarily fails the
      // status dialog's `v-if="... && store.current"` guard, unmounting it
      // mid-confirm so the dialog never receives its own `changed` emit.
      current.value = await getTicket(id)
      void useStatsStore().load()
    } finally {
      changingStatus.value = false
    }
  }
  async function loadActivities(id: number): Promise<void> {
    const request = ++latestActivitiesRequest
    activitiesLoading.value = true
    activitiesError.value = null
    try {
      const response = await listTicketActivities(id)
      if (request !== latestActivitiesRequest) return
      activities.value = response.data
      activitiesMeta.value = response.meta
    } catch (caughtError) {
      if (request !== latestActivitiesRequest) return
      activitiesError.value = errorMessage(caughtError)
      activities.value = []
      activitiesMeta.value = null
    } finally {
      if (request === latestActivitiesRequest) activitiesLoading.value = false
    }
  }
  async function addNote(id: number, body: string): Promise<void> {
    noteSaving.value = true
    try {
      const created = await addTicketNote(id, body)
      // Prepended, not refetched. The timeline is ordered created_at DESC,
      // id DESC and this row has the highest of both, so position one IS its
      // correct chronological position -- and no refetch means no race with
      // the latestActivitiesRequest guard.
      activities.value = [created, ...activities.value]
      if (activitiesMeta.value) activitiesMeta.value.total += 1
    } finally {
      noteSaving.value = false
    }
  }
  async function load(): Promise<void> {
    const request = ++latestRequest
    loading.value = true
    error.value = null
    try {
      const response = await listTickets({
        page: page.value,
        per_page: perPage.value,
        sort: sort.value,
        direction: direction.value,
        ...(q.value ? { q: q.value } : {}),
        ...(statusIds.value.length ? { status_id: statusIds.value } : {}),
        ...(priorityIds.value.length ? { priority_id: priorityIds.value } : {}),
        ...(categoryIds.value.length ? { category_id: categoryIds.value } : {}),
        ...(assignedTo.value === '' ? {} : { assigned_to: assignedTo.value }),
        ...(escalated.value === null ? {} : { escalated: escalated.value }),
      })
      if (request !== latestRequest) return
      items.value = response.data
      meta.value = response.meta
    } catch (caughtError) {
      if (request !== latestRequest) return
      error.value = errorMessage(caughtError)
      items.value = []
      meta.value = null
    } finally {
      if (request === latestRequest) loading.value = false
    }
  }
  async function goToPage(target: number): Promise<void> {
    page.value = target
    await load()
  }
  async function setPerPage(size: number): Promise<void> {
    perPage.value = size
    page.value = 1
    await load()
  }
  async function applyFilters(): Promise<void> {
    page.value = 1
    await load()
  }
  async function applySearch(term: string): Promise<void> {
    const startingSearch = q.value === '' && term !== ''
    q.value = term
    page.value = 1
    if (
      startingSearch &&
      sort.value === 'created_at' &&
      direction.value === 'desc'
    ) {
      sort.value = 'relevance'
    }
    if (term === '' && sort.value === 'relevance') {
      sort.value = 'created_at'
      direction.value = 'desc'
    }
    await load()
  }
  async function setSort(
    nextSort: TicketSort,
    nextDirection: TicketDirection,
  ): Promise<void> {
    sort.value = nextSort
    direction.value = nextDirection
    page.value = 1
    await load()
  }
  async function clearAll(): Promise<void> {
    const base = EMPTY_QUERY_STATE
    statusIds.value = [...base.statusIds]
    priorityIds.value = [...base.priorityIds]
    categoryIds.value = [...base.categoryIds]
    assignedTo.value = base.assignedTo
    escalated.value = base.escalated
    q.value = ''
    sort.value = base.sort
    direction.value = base.direction
    page.value = 1
    await load()
  }
  return {
    creating,
    create,
    items,
    meta,
    page,
    perPage,
    loading,
    error,
    statusIds,
    priorityIds,
    categoryIds,
    assignedTo,
    escalated,
    q,
    sort,
    direction,
    activeFilterCount,
    load,
    goToPage,
    setPerPage,
    applyFilters,
    applySearch,
    setSort,
    clearAll,
    current,
    detailLoading,
    detailError,
    detailNotFound,
    loadTicket,
    assigning,
    assign,
    claiming,
    claim,
    escalating,
    escalate,
    changingStatus,
    changeStatus,
    activities,
    activitiesMeta,
    activitiesLoading,
    activitiesError,
    loadActivities,
    noteSaving,
    addNote,
  }
})
