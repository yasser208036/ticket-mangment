<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import type { Category, CategoryDeleteBlocked } from '../api/categories'
import { useCategoriesStore } from '../stores/categories'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'
const props = defineProps<{
  category: Category
  blocked: CategoryDeleteBlocked
}>()
const emit = defineEmits<{ close: [] }>()
const store = useCategoriesStore()
const target = ref<number>()
const error = ref('')
async function confirmDelete() {
  if (target.value === undefined) return
  try {
    await store.remove(props.category.id, target.value)
    emit('close')
  } catch (reason) {
    error.value = errorMessage(reason)
  }
}
</script>
<template>
  <BaseDialog
    title="Category in use"
    icon="alert-triangle"
    tone="warning"
    @close="emit('close')"
  >
    <template #subtitle>
      <p
        data-testid="category-delete-count"
        class="mt-0.5 text-xs text-amber-700"
      >
        {{ blocked.ticket_count }} tickets
      </p>
    </template>

    <p class="text-sm text-ink-600">{{ blocked.message }}</p>

    <UiAlert v-if="error" data-testid="category-delete-error">
      {{ error }}
    </UiAlert>

    <UiField
      v-if="blocked.reassign_to_options.length"
      v-slot="field"
      label="Reassign existing tickets to"
    >
      <select
        v-bind="field"
        v-model="target"
        data-testid="category-delete-target"
      >
        <option :value="undefined" disabled>Select replacement category</option>
        <option
          v-for="option in blocked.reassign_to_options"
          :key="option.id"
          :value="option.id"
        >
          {{ option.name }}
        </option>
      </select>
    </UiField>

    <template #footer>
      <UiButton data-testid="category-delete-cancel" @click="emit('close')">
        Cancel
      </UiButton>
      <UiButton
        v-if="blocked.reassign_to_options.length"
        variant="danger"
        data-testid="category-delete-confirm"
        :disabled="target === undefined"
        @click="confirmDelete"
      >
        Reassign and delete
      </UiButton>
    </template>
  </BaseDialog>
</template>
