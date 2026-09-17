<script setup lang="ts">
withDefaults(
  defineProps<{
    /**
     * `table` mimics a header rule plus rows, `card` a block of prose. A
     * skeleton shaped like the content it replaces keeps the layout from
     * jumping when the data lands, which a centred spinner never did.
     */
    shape?: 'table' | 'card' | 'cards'
    rows?: number
    /** Announced to screen readers, which see no skeleton at all. */
    label?: string
    testid?: string
  }>(),
  { shape: 'table', rows: 5, label: 'Loading' },
)
</script>

<template>
  <div :data-testid="testid" role="status" :aria-label="label">
    <span class="sr-only">{{ label }}</span>

    <div v-if="shape === 'cards'" class="grid gap-4 sm:grid-cols-3">
      <div v-for="n in 3" :key="n" class="ui-card ui-card-pad">
        <div class="ui-skeleton h-3 w-24" />
        <div class="ui-skeleton mt-3 h-7 w-12" />
      </div>
    </div>

    <div v-else class="ui-card overflow-hidden">
      <div
        v-if="shape === 'table'"
        class="border-b border-line bg-sunken px-5 py-3 sm:px-6"
      >
        <div class="ui-skeleton h-2.5 w-32" />
      </div>
      <div class="divide-y divide-line">
        <div
          v-for="n in rows"
          :key="n"
          class="flex items-center gap-4 px-5 py-3.5 sm:px-6"
        >
          <div class="ui-skeleton h-3 w-24 shrink-0" />
          <div
            class="ui-skeleton h-3 flex-1"
            :style="{ maxWidth: `${55 + ((n * 37) % 40)}%` }"
          />
          <div class="ui-skeleton h-5 w-16 shrink-0 rounded-full" />
        </div>
      </div>
    </div>
  </div>
</template>
