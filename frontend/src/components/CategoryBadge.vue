<script setup lang="ts">
import { computed } from 'vue'
import { getBadgeTheme } from '../lib/color'
import type { Category } from '../api/categories'

const props = defineProps<{
  category: Pick<Category, 'name' | 'color' | 'is_active'>
}>()

const theme = computed(() => getBadgeTheme(props.category.color))
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium tracking-tight shadow-2xs transition-all select-none"
    :class="{ 'opacity-55 saturate-50': !category.is_active }"
    data-testid="category-badge"
    :style="{
      backgroundColor: theme.bg,
      color: theme.text,
      borderColor: theme.border,
    }"
    :data-inactive="category.is_active ? undefined : 'true'"
  >
    <span
      class="h-1.5 w-1.5 rounded-xs shrink-0"
      :style="{
        backgroundColor: theme.dot,
      }"
    />
    <span>{{ category.name }}</span>
  </span>
</template>
