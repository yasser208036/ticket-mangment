<script setup lang="ts">
import UiIcon from './UiIcon.vue'

defineProps<{
  label: string
  active: boolean
  direction: 'asc' | 'desc'
}>()
</script>

<template>
  <button
    type="button"
    class="ui-focus group -mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 transition-colors hover:text-ink-900"
    :class="active && 'text-ink-900'"
  >
    <span>{{ label }}</span>
    <UiIcon
      name="chevron-down"
      class="h-3 w-3 transition-transform"
      :class="[
        active ? 'text-brand-600' : 'text-ink-300 group-hover:text-ink-500',
        active && direction === 'asc' && 'rotate-180',
      ]"
    />
  </button>
</template>
