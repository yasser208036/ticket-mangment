<script setup lang="ts">
import { computed } from 'vue'
import { getBadgeTheme } from '../lib/color'

const props = defineProps<{ name: string; color: string }>()
const theme = computed(() => getBadgeTheme(props.color))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-semibold tracking-tight transition-all select-none shadow-2xs"
    data-testid="color-badge"
    :style="{
      backgroundColor: theme.bg,
      color: theme.text,
      borderColor: theme.border,
    }"
  >
    <span
      class="h-1.5 w-1.5 rounded-full shrink-0"
      :style="{
        backgroundColor: theme.dot,
        boxShadow: `0 0 0 2px ${theme.glow}`,
      }"
    />
    <span>{{ name }}</span>
  </span>
</template>
