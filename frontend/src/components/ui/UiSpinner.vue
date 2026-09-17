<script setup lang="ts">
defineProps<{ label?: string }>()
</script>

<template>
  <svg
    class="h-4 w-4 shrink-0 animate-spin"
    fill="none"
    viewBox="0 0 24 24"
    :aria-label="label"
    :aria-hidden="label ? undefined : true"
    :role="label ? 'status' : undefined"
  >
    <circle
      class="opacity-20"
      cx="12"
      cy="12"
      r="10"
      stroke="currentColor"
      stroke-width="3.5"
    />
    <path
      class="opacity-90"
      fill="currentColor"
      d="M4 12a8 8 0 018-8v3.2A4.8 4.8 0 007.2 12H4z"
    />
  </svg>
</template>
