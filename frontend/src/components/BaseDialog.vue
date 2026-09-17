<script setup lang="ts">
import { computed } from 'vue'
import UiIcon, { type IconName } from './ui/UiIcon.vue'

const props = withDefaults(
  defineProps<{
    /** `data-testid` on the overlay, so a test can assert the dialog is open. */
    testid?: string
    maxWidth?: 'md' | 'lg'
    /**
     * True for a dialog whose content can outgrow the viewport (the edit
     * form): the card caps at 90vh so the body scrolls on its own while the
     * header and footer stay pinned.
     */
    scrollable?: boolean
    title?: string
    description?: string
    icon?: IconName
    /** Tints the header icon. The footer's confirm button owns its own tone. */
    tone?: 'brand' | 'danger' | 'warning'
  }>(),
  { maxWidth: 'md', scrollable: false, tone: 'brand' },
)

defineEmits<{ close: [] }>()

const TONES = {
  brand: 'bg-brand-50 text-brand-600',
  danger: 'bg-rose-50 text-rose-600',
  warning: 'bg-amber-50 text-amber-600',
}

const toneClass = computed(() => TONES[props.tone])
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-ink-900/40 p-4 backdrop-blur-[2px]"
      :data-testid="testid"
    >
      <div
        class="flex w-full flex-col overflow-hidden rounded-xl border border-line bg-surface shadow-2xl"
        :class="[
          maxWidth === 'lg' ? 'max-w-lg' : 'max-w-md',
          scrollable && 'max-h-[90vh]',
        ]"
      >
        <header
          v-if="title"
          class="flex items-start gap-3 border-b border-line px-5 py-4"
        >
          <span
            v-if="icon"
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg"
            :class="toneClass"
          >
            <UiIcon :name="icon" />
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-ink-900">{{ title }}</h2>
            <p v-if="description" class="mt-0.5 text-xs text-ink-500">
              {{ description }}
            </p>
            <slot name="subtitle" />
          </div>
          <button
            type="button"
            class="ui-focus -mr-1 -mt-1 rounded-md p-1 text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700"
            aria-label="Close dialog"
            @click="$emit('close')"
          >
            <UiIcon name="close" />
          </button>
        </header>

        <div
          class="min-h-0 flex-1 space-y-4 px-5 py-4"
          :class="scrollable && 'overflow-y-auto'"
        >
          <slot />
        </div>

        <footer
          v-if="$slots.footer"
          class="flex items-center justify-end gap-2 border-t border-line bg-sunken px-5 py-3"
        >
          <slot name="footer" />
        </footer>
      </div>
    </div>
  </Teleport>
</template>
