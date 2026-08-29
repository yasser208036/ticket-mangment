<script setup lang="ts">
import { computed } from 'vue'
import type { TicketActivity } from '../api/activities'
import { eventDescriptor } from '../lib/activityEvents'
import {
  activityReason,
  activitySentence,
  noteBody,
} from '../lib/activityProse'
import { readableTextColor } from '../lib/color'
import { relativeAge } from '../lib/relativeTime'

const props = defineProps<{ activity: TicketActivity }>()
const descriptor = computed(() => eventDescriptor(props.activity.event))
const iconColor = computed(() => readableTextColor(descriptor.value.color))
const isSystem = computed(() => props.activity.actor === null)
const body = computed(() => noteBody(props.activity))
</script>

<template>
  <li
    class="flex gap-3 border-b border-slate-100 py-3 last:border-b-0"
    data-testid="timeline-entry"
    :data-event="activity.event"
    :data-system="isSystem ? 'true' : undefined"
    :class="{
      'border-l-2 border-dashed border-slate-300 bg-slate-50/70 pl-3 -ml-3':
        isSystem,
    }"
  >
    <span
      class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
      data-testid="timeline-icon"
      :style="{ backgroundColor: descriptor.color, color: iconColor }"
      aria-hidden="true"
    >
      <svg viewBox="0 0 16 16" width="12" height="12">
        <path
          :d="descriptor.path"
          fill="none"
          stroke="currentColor"
          stroke-width="1.5"
        />
      </svg>
    </span>
    <div class="min-w-0 flex-1">
      <p
        class="text-sm font-medium text-slate-800"
        :class="{ italic: isSystem }"
        data-testid="timeline-sentence"
      >
        {{ activitySentence(activity) }}
      </p>
      <p
        v-if="body"
        class="mt-1 whitespace-pre-wrap rounded-md border-l-2 border-amber-400 bg-amber-50 px-2 py-1.5 text-xs text-slate-700"
        data-testid="timeline-note"
      >
        {{ body }}
      </p>
      <p
        v-if="activity.field && activity.from_label && activity.to_label"
        class="mt-0.5 text-xs text-slate-500"
        data-testid="timeline-values"
      >
        {{ activity.from_label }} &rarr; {{ activity.to_label }}
      </p>
      <p
        v-if="activityReason(activity)"
        class="mt-0.5 text-xs italic text-slate-500"
        data-testid="timeline-reason"
      >
        {{ activityReason(activity) }}
      </p>
    </div>
    <time
      class="shrink-0 whitespace-nowrap text-xs text-slate-400"
      data-testid="timeline-age"
      :datetime="activity.created_at"
      :title="activity.created_at"
      >{{ relativeAge(activity.created_at) }}</time
    >
  </li>
</template>
