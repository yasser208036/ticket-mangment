<script setup lang="ts">
import { tintedBadge } from '../lib/color'
import UiIcon from './ui/UiIcon.vue'

/**
 * Status, priority and category all filter the same way — a row of toggles,
 * each carrying its own admin-chosen colour — and used to be three copies of
 * the same forty lines of markup in the filter bar.
 */
defineProps<{
  label: string
  options: { id: number; name: string; color: string }[]
  selected: number[]
  testid: string
}>()

defineEmits<{ toggle: [id: number] }>()
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <span :id="`${testid}-label`" class="ui-eyebrow">{{ label }}</span>
    <div
      :data-testid="testid"
      class="flex flex-wrap gap-1.5"
      role="group"
      :aria-labelledby="`${testid}-label`"
    >
      <button
        v-for="option in options"
        :key="option.id"
        type="button"
        class="ui-focus inline-flex select-none items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-medium transition-colors"
        :class="
          selected.includes(option.id)
            ? ''
            : 'border-line-strong bg-surface text-ink-600 hover:bg-ink-50 hover:text-ink-900'
        "
        :style="selected.includes(option.id) ? tintedBadge(option.color) : {}"
        :aria-pressed="selected.includes(option.id)"
        @click="$emit('toggle', option.id)"
      >
        <UiIcon
          v-if="selected.includes(option.id)"
          name="check"
          class="h-3 w-3"
          :stroke-width="2.5"
        />
        <span
          v-else
          class="h-1.5 w-1.5 shrink-0 rounded-full"
          :style="{ backgroundColor: option.color }"
          aria-hidden="true"
        />
        {{ option.name }}
      </button>
    </div>
  </div>
</template>
