<script setup lang="ts">
import { computed, useId } from 'vue'

const props = defineProps<{
  label: string
  /** Laravel returns an array per field; only the first is ever shown. */
  error?: string[] | string
  hint?: string
  required?: boolean
  /** `data-testid`s for the hint and error paragraphs, for tests to assert on. */
  hintTestid?: string
  errorTestid?: string
}>()

const id = useId()
const message = computed(() =>
  Array.isArray(props.error) ? props.error[0] : props.error,
)

/**
 * Everything the control needs, ready for a single `v-bind`. Keying it by the
 * real attribute names means a field cannot wire `aria-describedby` to a hint
 * it forgot to render, or style itself invalid while telling assistive tech
 * the opposite.
 */
const control = computed(() => ({
  id,
  class: ['ui-input', message.value && 'ui-input-invalid'],
  'aria-invalid': message.value ? true : undefined,
  'aria-describedby':
    [props.hint && `${id}-hint`, message.value && `${id}-error`]
      .filter(Boolean)
      .join(' ') || undefined,
}))
</script>

<template>
  <div class="space-y-1.5">
    <label class="ui-label" :for="id">
      {{ label }}
      <span v-if="required" class="text-rose-500" aria-hidden="true">*</span>
    </label>
    <!--
      The control is a slot rather than a wrapped `<input>` so a field can hold
      a select, a textarea or a colour picker without this component growing a
      `type` prop per case.
    -->
    <slot v-bind="control" />
    <p v-if="hint" :id="`${id}-hint`" :data-testid="hintTestid" class="ui-hint">
      {{ hint }}
    </p>
    <p
      v-if="message"
      :id="`${id}-error`"
      :data-testid="errorTestid"
      class="ui-error-text"
    >
      {{ message }}
    </p>
  </div>
</template>
