<script setup lang="ts">
defineProps<{ title?: string; eyebrow?: string }>()
</script>

<template>
  <section class="ui-card overflow-hidden">
    <header v-if="title || $slots.actions" class="ui-card-head">
      <h2 class="ui-section-title">{{ title }}</h2>
      <slot name="actions">
        <span v-if="eyebrow" class="ui-eyebrow">{{ eyebrow }}</span>
      </slot>
    </header>
    <slot />
  </section>
</template>
