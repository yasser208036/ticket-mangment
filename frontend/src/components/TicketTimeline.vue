<script setup lang="ts">
import { useTicketsStore } from '../stores/tickets'
import TicketTimelineEntry from './TicketTimelineEntry.vue'
import UiPanel from './ui/UiPanel.vue'

const store = useTicketsStore()
</script>

<template>
  <UiPanel title="History" data-testid="ticket-timeline">
    <div class="px-5 py-4 sm:px-6">
      <p
        v-if="store.activitiesLoading"
        class="text-sm text-ink-500"
        data-testid="timeline-loading"
      >
        Loading history…
      </p>
      <p
        v-else-if="store.activitiesError"
        class="ui-error-text text-sm"
        data-testid="timeline-error"
      >
        {{ store.activitiesError }}
      </p>
      <p
        v-else-if="!store.activities.length"
        class="text-sm text-ink-400"
        data-testid="timeline-empty"
      >
        Nothing has happened to this ticket yet.
      </p>
      <template v-else>
        <!-- The rail is drawn once behind the whole list rather than per entry,
             so it cannot leave a stub hanging below the last one. -->
        <ol
          class="relative before:absolute before:bottom-3 before:left-3 before:top-3 before:w-px before:bg-line"
          data-testid="timeline-list"
        >
          <TicketTimelineEntry
            v-for="activity in store.activities"
            :key="activity.id"
            :activity="activity"
          />
        </ol>
        <p
          v-if="store.activitiesMeta && store.activitiesMeta.last_page > 1"
          class="mt-3 text-xs text-ink-400"
          data-testid="timeline-truncated"
        >
          Showing the {{ store.activities.length }} most recent of
          {{ store.activitiesMeta.total }} entries.
        </p>
      </template>
    </div>
  </UiPanel>
</template>
