<script setup lang="ts">
import { computed } from 'vue'
import UiIcon, { type IconName } from './UiIcon.vue'

const props = withDefaults(
  defineProps<{
    tone?: 'error' | 'success' | 'warning' | 'info'
    /** Bolded first line; the slot carries the detail underneath it. */
    title?: string
  }>(),
  { tone: 'error' },
)

const TONES: Record<string, { box: string; icon: IconName; mark: string }> = {
  error: {
    box: 'border-rose-200 bg-rose-50 text-rose-800',
    icon: 'alert',
    mark: 'text-rose-500',
  },
  success: {
    box: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    icon: 'check-circle',
    mark: 'text-emerald-600',
  },
  warning: {
    box: 'border-amber-200 bg-amber-50 text-amber-800',
    icon: 'alert-triangle',
    mark: 'text-amber-600',
  },
  info: {
    box: 'border-brand-200 bg-brand-50 text-brand-800',
    icon: 'alert',
    mark: 'text-brand-600',
  },
}

const tone = computed(() => TONES[props.tone])
</script>

<template>
  <div
    class="flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-sm"
    :class="tone.box"
    role="alert"
  >
    <UiIcon :name="tone.icon" class="mt-0.5" :class="tone.mark" />
    <div class="min-w-0 flex-1">
      <p v-if="title" class="font-semibold">{{ title }}</p>
      <div :class="title ? 'mt-0.5 opacity-90' : ''"><slot /></div>
    </div>
  </div>
</template>
