<script setup lang="ts">
import { computed } from 'vue'
import type { RouteLocationRaw } from 'vue-router'
import UiIcon, { type IconName } from './UiIcon.vue'
import UiSpinner from './UiSpinner.vue'

const props = withDefaults(
  defineProps<{
    /** Renders a `RouterLink` instead of a `<button>` when present. */
    to?: RouteLocationRaw
    variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'danger-quiet'
    size?: 'sm' | 'md'
    /** Leading icon, replaced by a spinner while `loading`. */
    icon?: IconName
    loading?: boolean
    disabled?: boolean
    type?: 'button' | 'submit'
  }>(),
  { variant: 'secondary', size: 'md', type: 'button' },
)

const VARIANTS = {
  primary: 'ui-btn-primary',
  secondary: 'ui-btn-secondary',
  ghost: 'ui-btn-ghost',
  danger: 'ui-btn-danger',
  'danger-quiet': 'ui-btn-danger-quiet',
} as const

const classes = computed(() => [
  'ui-btn',
  VARIANTS[props.variant],
  props.size === 'sm' && 'ui-btn-sm',
])
</script>

<template>
  <component
    :is="to ? 'RouterLink' : 'button'"
    :to="to"
    :class="classes"
    :type="to ? undefined : type"
    :disabled="to ? undefined : disabled || loading"
    :aria-busy="loading || undefined"
  >
    <UiSpinner v-if="loading" :class="size === 'sm' ? 'h-3.5 w-3.5' : ''" />
    <UiIcon
      v-else-if="icon"
      :name="icon"
      :class="size === 'sm' ? 'h-3.5 w-3.5' : ''"
    />
    <slot />
  </component>
</template>
