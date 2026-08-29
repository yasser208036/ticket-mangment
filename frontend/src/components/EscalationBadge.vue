<script setup lang="ts">
import { computed } from 'vue'
import ColorBadge from './ColorBadge.vue'

const props = defineProps<{ level: number }>()

// The level is carried by the text as well as the colour: amber and red are
// one of the worst pairs for a colour-blind reader, so colour alone would not
// distinguish anything. Hex values match Medium's and Urgent's from
// PrioritySeeder, so the queue reads as one palette. Never "×1" -- a bare
// "Escalated" is the level-one signal, and "×" says "more than once".
const colour = computed(() => (props.level > 1 ? '#EF4444' : '#F59E0B'))
const label = computed(() =>
  props.level > 1 ? `Escalated ×${props.level}` : 'Escalated',
)
</script>

<template>
  <ColorBadge
    v-if="level > 0"
    :name="label"
    :color="colour"
    data-testid="escalation-badge"
    :data-level="level"
  />
</template>
