<script setup lang="ts">
import { computed } from 'vue'
import { tintedBadge } from '../lib/color'
const props = defineProps<{ name: string; color: string }>()
const style = computed(() => tintedBadge(props.color))
</script>
<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium"
    data-testid="color-badge"
    :style="style"
  >
    <!-- The dot is the only full-strength sample of the colour, so a pastel
         still reads as itself once the fill behind it has been washed out. -->
    <span
      class="h-1.5 w-1.5 shrink-0 rounded-full"
      :style="{ backgroundColor: color }"
    />
    {{ name }}
  </span>
</template>
