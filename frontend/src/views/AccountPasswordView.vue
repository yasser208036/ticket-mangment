<script setup lang="ts">
import { ref } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiField from '../components/ui/UiField.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import { changePassword } from '../api/auth'
import { errorMessage, validationErrors } from '../api/errors'

const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const error = ref<string | null>(null)
const changed = ref(false)
const submitting = ref(false)

async function submit(): Promise<void> {
  submitting.value = true
  fieldErrors.value = {}
  error.value = null
  changed.value = false
  try {
    await changePassword({
      current_password: currentPassword.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    currentPassword.value = ''
    password.value = ''
    passwordConfirmation.value = ''
    changed.value = true
  } catch (caughtError) {
    fieldErrors.value = validationErrors(caughtError)
    if (!Object.keys(fieldErrors.value).length)
      error.value = errorMessage(caughtError)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader
      title="Change password"
      description="You will be signed out from your other devices."
    />

    <UiAlert v-if="changed" tone="success" data-testid="password-changed">
      Password changed. You have been signed out on your other devices.
    </UiAlert>

    <UiAlert v-if="error" data-testid="password-error">{{ error }}</UiAlert>

    <div class="ui-card ui-card-pad">
      <form class="space-y-4" @submit.prevent="submit">
        <UiField
          v-slot="field"
          label="Current password"
          required
          :error="fieldErrors.current_password"
          error-testid="password-error-current_password"
        >
          <input
            v-bind="field"
            v-model="currentPassword"
            data-testid="password-current"
            type="password"
            autocomplete="current-password"
            required
            placeholder="••••••••"
          />
        </UiField>

        <UiField
          v-slot="field"
          label="New password"
          required
          :error="fieldErrors.password"
          error-testid="password-error-password"
        >
          <input
            v-bind="field"
            v-model="password"
            data-testid="password-new"
            type="password"
            autocomplete="new-password"
            required
            placeholder="••••••••"
          />
        </UiField>

        <UiField v-slot="field" label="Confirm password" required>
          <input
            v-bind="field"
            v-model="passwordConfirmation"
            data-testid="password-confirmation"
            type="password"
            autocomplete="new-password"
            required
            placeholder="••••••••"
          />
        </UiField>

        <div class="flex justify-end border-t border-line pt-4">
          <UiButton
            variant="primary"
            type="submit"
            data-testid="password-submit"
            :loading="submitting"
          >
            {{ submitting ? 'Saving…' : 'Change password' }}
          </UiButton>
        </div>
      </form>
    </div>
  </main>
</template>
