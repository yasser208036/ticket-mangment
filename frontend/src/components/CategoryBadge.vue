<script setup lang="ts">
import { computed } from 'vue'
import { tintedBadge } from '../lib/color'
import type { Category } from '../api/categories'
const props = defineProps<{
  category: Pick<Category, 'name' | 'color' | 'is_active'>
}>()
const style = computed(() => tintedBadge(props.category.color))
</script>
<template>
  <span
    class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium"
    :class="{ 'opacity-60 saturate-50': !category.is_active }"
    data-testid="category-badge"
    :style="style"
    :data-inactive="category.is_active ? undefined : 'true'"
  >
    <span
      class="h-1.5 w-1.5 shrink-0 rounded-full"
      :style="{ backgroundColor: category.color }"
    />
    {{ category.name }}
  </span>
</template>
