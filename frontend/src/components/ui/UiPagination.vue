<script setup lang="ts">
import UiButton from './UiButton.vue'
import type { PageMeta } from '../../api/pagination'

/**
 * The "showing m–n of N" line plus prev/next, shared by every paginated
 * table. The `#extra` slot is where a screen hangs its own control (the
 * ticket list's per-page select) without this component learning about it.
 */
defineProps<{
  meta: PageMeta | null | undefined
  /** Plural noun for the summary line, e.g. "tickets". */
  noun: string
  /** Prefix for the three `data-testid`s: `-count`, `-prev`, `-next`. */
  testidPrefix: string
}>()

defineEmits<{ go: [page: number] }>()
</script>

<template>
  <div
    class="flex flex-col items-center justify-between gap-3 border-t border-line px-5 py-3 sm:flex-row sm:px-6"
  >
    <p
      v-if="meta"
      :data-testid="`${testidPrefix}-count`"
      class="tabular text-xs text-ink-500"
    >
      Showing {{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} of {{ meta.total }}
      {{ noun }}
    </p>
    <div v-else />

    <div class="flex items-center gap-3">
      <slot name="extra" />
      <div class="flex items-center gap-1">
        <UiButton
          size="sm"
          icon="chevron-left"
          :data-testid="`${testidPrefix}-prev`"
          :disabled="!meta || meta.current_page <= 1"
          @click="$emit('go', (meta?.current_page ?? 1) - 1)"
        >
          Previous
        </UiButton>
        <UiButton
          size="sm"
          :data-testid="`${testidPrefix}-next`"
          :disabled="!meta || meta.current_page >= meta.last_page"
          @click="$emit('go', (meta?.current_page ?? 1) + 1)"
        >
          Next
        </UiButton>
      </div>
    </div>
  </div>
</template>
