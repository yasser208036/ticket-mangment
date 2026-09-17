<script setup lang="ts">
import { reactive, ref } from 'vue'
import { validationErrors, errorMessage } from '../api/errors'
import { useCategoriesStore } from '../stores/categories'
import type { Category, CreateCategoryPayload } from '../api/categories'
import BaseDialog from './BaseDialog.vue'
import UiAlert from './ui/UiAlert.vue'
import UiButton from './ui/UiButton.vue'
import UiField from './ui/UiField.vue'
const props = defineProps<{ category?: Category }>()
const emit = defineEmits<{ saved: []; close: [] }>()
const store = useCategoriesStore()
const form = reactive({
  name: props.category?.name ?? '',
  description: props.category?.description ?? '',
  color: props.category?.color ?? '#6B7280',
  is_active: props.category?.is_active ?? true,
  sort_order: props.category?.sort_order ?? 0,
})
const errors = ref<Record<string, string[]>>({})
const message = ref('')
async function submit() {
  try {
    const payload = { ...form, color: form.color.toUpperCase() }
    if (props.category) await store.update(props.category.id, payload)
    else await store.create(payload as CreateCategoryPayload)
    emit('saved')
  } catch (e) {
    errors.value = validationErrors(e)
    message.value = Object.keys(errors.value).length ? '' : errorMessage(e)
  }
}
</script>
<template>
  <BaseDialog
    max-width="lg"
    :title="category ? 'Edit category' : 'New category'"
    icon="tag"
    @close="emit('close')"
  >
    <form id="category-form" class="space-y-4" @submit.prevent="submit">
      <UiField
        v-slot="field"
        label="Category name"
        required
        :error="errors.name"
        error-testid="category-form-error-name"
      >
        <input
          v-bind="field"
          v-model="form.name"
          data-testid="category-form-name"
          type="text"
          placeholder="e.g. Billing, Technical Support"
        />
      </UiField>

      <UiField v-slot="field" label="Description">
        <input
          v-bind="field"
          v-model="form.description"
          data-testid="category-form-description"
          type="text"
          placeholder="Brief scope of this category"
        />
      </UiField>

      <div class="grid gap-4 sm:grid-cols-2">
        <UiField v-slot="field" label="Badge colour">
          <span class="flex items-center gap-2">
            <input
              v-model="form.color"
              type="color"
              data-testid="category-form-color"
              aria-label="Pick a badge colour"
              class="ui-focus h-9 w-10 shrink-0 cursor-pointer rounded-lg border border-line-strong bg-surface p-1"
            />
            <input
              v-bind="field"
              v-model="form.color"
              type="text"
              placeholder="#6B7280"
              class="ui-input font-mono text-xs"
            />
          </span>
        </UiField>

        <UiField v-slot="field" label="Sort order">
          <input
            v-bind="field"
            v-model.number="form.sort_order"
            type="number"
            min="0"
            max="65535"
            data-testid="category-form-sort-order"
          />
        </UiField>
      </div>

      <label class="flex items-center gap-2 text-sm text-ink-700">
        <input
          v-model="form.is_active"
          type="checkbox"
          data-testid="category-form-active"
          class="ui-focus h-4 w-4 rounded border-line-strong text-brand-600"
        />
        Active category (available when filing tickets)
      </label>

      <p
        v-if="category"
        data-testid="category-form-slug"
        class="ui-hint font-mono"
      >
        Slug: {{ category.slug }} (unchanged on rename)
      </p>

      <UiAlert v-if="message">{{ message }}</UiAlert>
    </form>

    <template #footer>
      <UiButton @click="emit('close')">Cancel</UiButton>
      <UiButton
        variant="primary"
        type="submit"
        form="category-form"
        data-testid="category-form-submit"
      >
        {{ category ? 'Save changes' : 'Create category' }}
      </UiButton>
    </template>
  </BaseDialog>
</template>
