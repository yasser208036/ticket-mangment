<script setup lang="ts">
import { reactive, ref } from 'vue'
import { errorMessage, validationErrors } from '../api/errors'
import type { AdminUser } from '../api/users'
import { useUsersStore } from '../stores/users'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'
const props = defineProps<{ user: AdminUser }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const store = useUsersStore()
const form = reactive({ password: '', current_password: '' })
const errors = ref<Record<string, string[]>>({})
const message = ref('')
async function submit(): Promise<void> {
  errors.value = {}
  message.value = ''
  try {
    await store.resetPassword(props.user.id, {
      password: form.password,
      current_password: form.current_password,
    })
    emit('saved')
  } catch (error) {
    errors.value = validationErrors(error)
    message.value = Object.keys(errors.value).length ? '' : errorMessage(error)
  }
}
</script>
<template>
  <BaseDialog
    :title="`Reset password for ${user.name}`"
    icon="lock"
    tone="warning"
    @close="emit('close')"
  >
    <form
      id="user-password-form"
      data-testid="user-password-form"
      class="space-y-4"
      @submit.prevent="submit"
    >
      <UiField
        v-slot="field"
        :label="`New password for ${user.name}`"
        required
        :error="errors.password"
        error-testid="user-password-error-password"
      >
        <input
          v-bind="field"
          v-model="form.password"
          data-testid="user-password-new"
          type="password"
          placeholder="••••••••"
        />
      </UiField>

      <UiField
        v-slot="field"
        label="Your own password"
        required
        hint="Confirming your own password keeps an unattended session from taking over other accounts."
        :error="errors.current_password"
        error-testid="user-password-error-current_password"
      >
        <input
          v-bind="field"
          v-model="form.current_password"
          data-testid="user-password-current"
          type="password"
          placeholder="••••••••"
        />
      </UiField>

      <p class="text-xs text-ink-500">
        {{ user.name }} will be signed out everywhere and must sign in with the
        new password. They are not emailed about this.
      </p>

      <UiAlert v-if="message" data-testid="user-password-error">
        {{ message }}
      </UiAlert>
    </form>

    <template #footer>
      <UiButton data-testid="user-password-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        type="submit"
        form="user-password-form"
        data-testid="user-password-submit"
      >
        Set password
      </UiButton>
    </template>
  </BaseDialog>
</template>
