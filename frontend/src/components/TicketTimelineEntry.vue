<script setup lang="ts">
import { computed } from 'vue'
import type { TicketActivity } from '../api/activities'
import { eventDescriptor } from '../lib/activityEvents'
import {
  activityReason,
  activitySentence,
  noteBody,
} from '../lib/activityProse'
import { tintedBadge } from '../lib/color'
import { relativeAge } from '../lib/relativeTime'

const props = defineProps<{ activity: TicketActivity }>()
const descriptor = computed(() => eventDescriptor(props.activity.event))
const marker = computed(() => tintedBadge(descriptor.value.color))
const isSystem = computed(() => props.activity.actor === null)
const body = computed(() => noteBody(props.activity))
</script>

<template>
  <li
    class="relative flex gap-3 py-2.5"
    data-testid="timeline-entry"
    :data-event="activity.event"
    :data-system="isSystem ? 'true' : undefined"
  >
    <span
      class="z-10 mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border bg-surface"
      data-testid="timeline-icon"
      :style="marker"
      aria-hidden="true"
    >
      <svg viewBox="0 0 16 16" width="11" height="11">
        <path
          :d="descriptor.path"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
        />
      </svg>
    </span>
    <div class="min-w-0 flex-1">
      <p
        class="text-sm text-ink-800"
        :class="isSystem ? 'italic text-ink-500' : 'font-medium'"
        data-testid="timeline-sentence"
      >
        {{ activitySentence(activity) }}
      </p>
      <p
        v-if="body"
        class="mt-1.5 whitespace-pre-wrap rounded-md border border-line bg-sunken px-2.5 py-1.5 text-xs text-ink-700"
        data-testid="timeline-note"
      >
        {{ body }}
      </p>
      <p
        v-if="activity.field && activity.from_label && activity.to_label"
        class="mt-0.5 text-xs text-ink-500"
        data-testid="timeline-values"
      >
        {{ activity.from_label }} &rarr; {{ activity.to_label }}
      </p>
      <p
        v-if="activityReason(activity)"
        class="mt-0.5 text-xs italic text-ink-500"
        data-testid="timeline-reason"
      >
        {{ activityReason(activity) }}
      </p>
    </div>
    <time
      class="shrink-0 whitespace-nowrap text-xs text-ink-400"
      data-testid="timeline-age"
      :datetime="activity.created_at"
      :title="activity.created_at"
      >{{ relativeAge(activity.created_at) }}</time
    >
  </li>
</template>
