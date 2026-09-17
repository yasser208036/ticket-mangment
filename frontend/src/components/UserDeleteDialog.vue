<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import type { AdminUser, UserDeleteBlocked } from '../api/users'
import { useUsersStore } from '../stores/users'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'
const props = defineProps<{
  user: AdminUser
  /** Null when nothing blocks the delete — a plain confirmation. */
  blocked: UserDeleteBlocked | null
}>()
const emit = defineEmits<{ deleted: []; close: [] }>()
const store = useUsersStore()
const target = ref<number>()
const error = ref('')
async function confirmDelete(): Promise<void> {
  if (props.blocked && target.value === undefined) return
  try {
    await store.remove(props.user.id, target.value)
    emit('deleted')
  } catch (reason) {
    error.value = errorMessage(reason)
  }
}
</script>
<template>
  <BaseDialog
    testid="user-delete"
    :title="`Delete ${user.name}?`"
    icon="alert-triangle"
    tone="danger"
    @close="emit('close')"
  >
    <template #subtitle>
      <p
        v-if="blocked"
        data-testid="user-delete-count"
        class="mt-0.5 text-xs text-amber-700"
      >
        {{ blocked.ticket_count }} tickets
      </p>
    </template>

    <p class="text-sm text-ink-600">
      <template v-if="blocked">
        Those tickets will move to the person you choose. Historical activity
        keeps its record but loses this person's name.
      </template>
      <template v-else>
        This removes the account and signs them out everywhere. Deactivating
        instead keeps their history attributed to them.
      </template>
    </p>

    <UiAlert v-if="error" data-testid="user-delete-error">{{ error }}</UiAlert>

    <UiField
      v-if="blocked && blocked.reassign_to_options.length"
      v-slot="field"
      label="Move their tickets to"
    >
      <select v-bind="field" v-model="target" data-testid="user-delete-target">
        <option :value="undefined" disabled>Select a user</option>
        <option
          v-for="option in blocked.reassign_to_options"
          :key="option.id"
          :value="option.id"
        >
          {{ option.name }}
        </option>
      </select>
    </UiField>

    <UiAlert v-else-if="blocked" tone="warning">{{ blocked.message }}</UiAlert>

    <template #footer>
      <UiButton data-testid="user-delete-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        v-if="!blocked || blocked.reassign_to_options.length"
        variant="danger"
        data-testid="user-delete-confirm"
        :disabled="blocked !== null && target === undefined"
        @click="confirmDelete"
      >
        {{ blocked ? 'Reassign and delete' : 'Delete user' }}
      </UiButton>
    </template>
  </BaseDialog>
</template>
