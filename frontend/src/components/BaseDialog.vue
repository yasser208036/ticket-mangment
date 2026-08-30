<script setup lang="ts">
withDefaults(
  defineProps<{
    /** `data-testid` on the overlay, so a test can assert the dialog is open. */
    testid?: string
    maxWidth?: 'md' | 'lg'
    /**
     * True for a dialog whose content can outgrow the viewport (the edit
     * form): the card caps at 90vh and becomes a column, so a header/footer
     * passed as separate slot children stay pinned while the middle child
     * scrolls on its own. False (the common case) just pads the card and
     * lets it size to its content.
     */
    scrollable?: boolean
  }>(),
  { maxWidth: 'md', scrollable: false },
)
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4 backdrop-blur-xs animate-in fade-in duration-150"
      :data-testid="testid"
    >
      <div
        class="w-full rounded-2xl border border-slate-200 bg-white shadow-2xl animate-in zoom-in-95 duration-150"
        :class="[
          maxWidth === 'lg' ? 'max-w-lg' : 'max-w-md',
          scrollable ? 'flex max-h-[90vh] flex-col' : 'p-6',
        ]"
      >
        <slot />
      </div>
    </div>
  </Teleport>
</template>
