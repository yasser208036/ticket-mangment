<script setup lang="ts">
import UiIcon, { type IconName } from './UiIcon.vue'

withDefaults(
  defineProps<{
    icon?: IconName
    title: string
    description?: string
    /** `data-testid` on the title, which is what tests look for. */
    testid?: string
  }>(),
  { icon: 'inbox' },
)
</script>

<template>
  <div
    class="flex flex-col items-center justify-center rounded-card border border-dashed border-line-strong bg-surface px-6 py-14 text-center"
  >
    <div
      class="flex h-11 w-11 items-center justify-center rounded-full bg-ink-50 text-ink-400"
    >
      <UiIcon :name="icon" class="h-5 w-5" />
    </div>
    <p class="mt-3.5 text-sm font-semibold text-ink-900" :data-testid="testid">
      {{ title }}
    </p>
    <p v-if="description" class="mt-1 max-w-sm text-xs text-ink-500">
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-4"><slot /></div>
  </div>
</template>
