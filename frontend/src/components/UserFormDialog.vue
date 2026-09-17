<script setup lang="ts">
import { reactive, ref } from 'vue'
import { computed } from 'vue'
import { useAuthStore } from '../stores/auth'
import { useUsersStore } from '../stores/users'
import { validationErrors, errorMessage } from '../api/errors'
import type { UserRole } from '../api/auth'
import type { AdminUser, CreateUserPayload } from '../api/users'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'
const props = defineProps<{ user?: AdminUser }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const store = useUsersStore()
const auth = useAuthStore()
const editingSelf = computed(() => props.user?.id === auth.user?.id)
const form = reactive({
  name: props.user?.name ?? '',
  email: props.user?.email ?? '',
  password: '',
  role: props.user?.role ?? ('agent' as UserRole),
  is_active: props.user?.is_active ?? true,
})
const errors = ref<Record<string, string[]>>({})
const message = ref('')
async function submit(): Promise<void> {
  try {
    if (props.user)
      await store.update(props.user.id, {
        name: form.name,
        email: form.email,
        role: form.role,
        is_active: form.is_active,
      })
    else await store.create(form as CreateUserPayload)
    emit('saved')
  } catch (error) {
    errors.value = validationErrors(error)
    message.value = Object.keys(errors.value).length ? '' : errorMessage(error)
  }
}
</script>
<template>
  <BaseDialog
    max-width="lg"
    :title="user ? 'Edit user' : 'New user'"
    icon="users"
    @close="emit('close')"
  >
    <form
      id="user-form"
      data-testid="user-form"
      class="space-y-4"
      @submit.prevent="submit"
    >
      <UiField v-slot="field" label="Full name" required :error="errors.name">
        <input
          v-bind="field"
          v-model="form.name"
          data-testid="user-form-name"
          type="text"
          placeholder="e.g. Alex Morgan"
        />
      </UiField>

      <UiField
        v-slot="field"
        label="Email address"
        required
        :error="errors.email"
      >
        <input
          v-bind="field"
          v-model="form.email"
          data-testid="user-form-email"
          type="email"
          placeholder="e.g. alex@deskflow.app"
        />
      </UiField>

      <UiField
        v-if="!user"
        v-slot="field"
        label="Initial password"
        required
        :error="errors.password"
      >
        <input
          v-bind="field"
          v-model="form.password"
          data-testid="user-form-password"
          type="password"
          placeholder="••••••••"
        />
      </UiField>

      <div class="grid gap-4 sm:grid-cols-2">
        <UiField v-slot="field" label="Role">
          <select
            v-bind="field"
            v-model="form.role"
            :disabled="editingSelf"
            data-testid="user-form-role"
          >
            <option value="user">Requester</option>
            <option value="agent">Support Agent</option>
            <option value="admin">Administrator</option>
          </select>
        </UiField>

        <label
          class="flex items-center gap-2 self-end pb-2 text-sm text-ink-700"
        >
          <input
            v-model="form.is_active"
            type="checkbox"
            :disabled="editingSelf"
            data-testid="user-form-active"
            class="ui-focus h-4 w-4 rounded border-line-strong text-brand-600 disabled:opacity-50"
          />
          Active account
        </label>
      </div>

      <p v-if="editingSelf" class="ui-hint">
        You cannot modify your own role or active status.
      </p>

      <UiAlert v-if="message" data-testid="user-form-error">{{
        message
      }}</UiAlert>
    </form>

    <template #footer>
      <UiButton data-testid="user-form-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        variant="primary"
        type="submit"
        form="user-form"
        data-testid="user-form-submit"
      >
        Save user
      </UiButton>
    </template>
  </BaseDialog>
</template>
