<script setup lang="ts">
import { computed } from 'vue'
import { readableTextColor } from '../lib/color'
import type { Category } from '../api/categories'
const props = defineProps<{
  category: Pick<Category, 'name' | 'color' | 'is_active'>
}>()
const textColor = computed(() => readableTextColor(props.category.color))
</script>
<template>
  <span
    class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium tracking-tight shadow-xs transition-opacity"
    :class="{ 'opacity-55 saturate-50': !category.is_active }"
    data-testid="category-badge"
    :style="{ backgroundColor: category.color, color: textColor }"
    :data-inactive="category.is_active ? undefined : 'true'"
  >
    {{ category.name }}
  </span>
</template>
