<script setup lang="ts">
import UiButton from '../components/ui/UiButton.vue'
import UiIcon from '../components/ui/UiIcon.vue'
</script>

<template>
  <main
    class="mx-auto flex min-h-[70vh] max-w-md items-center justify-center px-4 py-12"
  >
    <div class="ui-card w-full p-8 text-center">
      <span
        class="mx-auto flex h-11 w-11 items-center justify-center rounded-full bg-amber-50 text-amber-600"
      >
        <UiIcon name="lock" class="h-5 w-5" />
      </span>
      <p class="ui-eyebrow mt-4">Error 403</p>
      <h1 class="mt-1 text-lg font-semibold tracking-tight text-ink-900">
        Not authorised
      </h1>
      <p
        data-testid="forbidden-message"
        class="mt-2 text-sm leading-relaxed text-ink-600"
      >
        Your account does not have access to that screen. Ask an administrator
        to check your role.
      </p>
      <UiButton
        class="mt-6"
        variant="primary"
        icon="arrow-left"
        to="/"
        data-testid="forbidden-home"
      >
        Back to the app
      </UiButton>
    </div>
  </main>
</template>
