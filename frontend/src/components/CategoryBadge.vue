<script setup lang="ts">
import { computed } from 'vue'
import { solidBadge } from '../lib/color'
import type { Category } from '../api/categories'
const props = defineProps<{
  category: Pick<Category, 'name' | 'color' | 'is_active'>
}>()
const style = computed(() => solidBadge(props.category.color))
</script>
<template>
  <span
    class="inline-flex items-center whitespace-nowrap rounded-md px-2.5 py-0.5 text-xs font-semibold"
    :class="{ 'opacity-60 saturate-50': !category.is_active }"
    data-testid="category-badge"
    :style="style"
    :data-inactive="category.is_active ? undefined : 'true'"
  >
    {{ category.name }}
  </span>
</template>
