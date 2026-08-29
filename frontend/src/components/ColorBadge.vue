<script setup lang="ts">
import { computed } from 'vue'
import { readableTextColor } from '../lib/color'
const props = defineProps<{ name: string; color: string }>()
const textColor = computed(() => readableTextColor(props.color))
</script>
<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold tracking-wide shadow-xs transition-colors"
    data-testid="color-badge"
    :style="{ backgroundColor: color, color: textColor }"
  >
    <span
      class="h-1.5 w-1.5 rounded-full opacity-80"
      :style="{ backgroundColor: textColor }"
    />
    {{ name }}
  </span>
</template>
