<script setup lang="ts">
import { useTicketsStore } from '../stores/tickets'
import TicketTimelineEntry from './TicketTimelineEntry.vue'

const store = useTicketsStore()
</script>

<template>
  <section
    class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm"
    data-testid="ticket-timeline"
  >
    <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">
      History
    </h3>
    <p
      v-if="store.activitiesLoading"
      class="mt-3 text-sm text-slate-500"
      data-testid="timeline-loading"
    >
      Loading history...
    </p>
    <p
      v-else-if="store.activitiesError"
      class="mt-3 text-sm text-rose-600"
      data-testid="timeline-error"
    >
      {{ store.activitiesError }}
    </p>
    <p
      v-else-if="!store.activities.length"
      class="mt-3 text-sm text-slate-400 italic"
      data-testid="timeline-empty"
    >
      Nothing has happened to this ticket yet.
    </p>
    <template v-else>
      <ol class="mt-3" data-testid="timeline-list">
        <TicketTimelineEntry
          v-for="activity in store.activities"
          :key="activity.id"
          :activity="activity"
        />
      </ol>
      <p
        v-if="store.activitiesMeta && store.activitiesMeta.last_page > 1"
        class="mt-3 text-xs text-slate-400"
        data-testid="timeline-truncated"
      >
        Showing the {{ store.activities.length }} most recent of
        {{ store.activitiesMeta.total }} entries.
      </p>
    </template>
  </section>
</template>
