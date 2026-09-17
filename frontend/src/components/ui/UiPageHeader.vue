<script setup lang="ts">
defineProps<{ title: string; description?: string }>()
</script>

<template>
  <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
    <div class="min-w-0">
      <div class="flex items-center gap-2.5">
        <h1 class="ui-title">{{ title }}</h1>
        <slot name="badge" />
      </div>
      <p v-if="description" class="ui-subtitle">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex shrink-0 items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
