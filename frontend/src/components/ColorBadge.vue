<script setup lang="ts">
import { computed } from 'vue'
import { solidBadge } from '../lib/color'
const props = defineProps<{ name: string; color: string }>()
const style = computed(() => solidBadge(props.color))
</script>
<template>
  <span
    class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-bold"
    data-testid="color-badge"
    :style="style"
  >
    {{ name }}
  </span>
</template>
