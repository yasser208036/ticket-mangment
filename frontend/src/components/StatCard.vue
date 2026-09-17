<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
import UiIcon from './ui/UiIcon.vue'
defineProps<{
  label: string
  count: number
  to: RouteLocationRaw
  testid: string
}>()
</script>
<template>
  <RouterLink
    :to="to"
    :data-testid="testid"
    class="ui-card ui-focus group flex items-center justify-between gap-4 p-5 transition-colors hover:border-line-strong hover:bg-sunken"
  >
    <div class="min-w-0">
      <span class="text-xs font-medium text-ink-500">{{ label }}</span>
      <strong
        :data-testid="testid + '-count'"
        class="tabular mt-1.5 block text-2xl font-semibold tracking-tight text-ink-900"
      >
        {{ count }}
      </strong>
    </div>
    <UiIcon
      name="chevron-right"
      class="h-4 w-4 text-ink-300 transition-transform group-hover:translate-x-0.5 group-hover:text-ink-500"
    />
  </RouterLink>
</template>
