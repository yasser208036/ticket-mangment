<script setup lang="ts">
import { reactive, ref } from 'vue'
import { validationErrors, errorMessage } from '../api/errors'
import { useCategoriesStore } from '../stores/categories'
import type { Category, CreateCategoryPayload } from '../api/categories'
import BaseDialog from './BaseDialog.vue'
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
  <BaseDialog max-width="lg">
    <div class="space-y-5">
      <div
        class="flex items-center justify-between border-b border-slate-100 pb-3"
      >
        <h2 class="text-lg font-bold text-slate-900">
          {{ category ? 'Edit category' : 'New category' }}
        </h2>
        <button
          type="button"
          @click="emit('close')"
          class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors"
        >
          <svg
            class="h-5 w-5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>
      </div>

      <form @submit.prevent="submit" class="space-y-4">
        <!-- Name -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Category Name *</label
          >
          <input
            v-model="form.name"
            data-testid="category-form-name"
            type="text"
            placeholder="e.g. Billing, Technical Support"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
            :class="{ 'border-rose-300 ring-2 ring-rose-100': errors.name }"
          />
          <p
            v-if="errors.name"
            data-testid="category-form-error-name"
            class="text-xs font-medium text-rose-600"
          >
            {{ errors.name[0] }}
          </p>
        </div>

        <!-- Description -->
        <div class="space-y-1">
          <label class="text-xs font-semibold text-slate-700"
            >Description</label
          >
          <input
            v-model="form.description"
            data-testid="category-form-description"
            type="text"
            placeholder="Brief scope of this category"
            class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
          />
        </div>

        <!-- Color & Sort Order Row -->
        <div class="grid grid-cols-2 gap-4">
          <!-- Color -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Badge Color</label
            >
            <div class="flex items-center gap-2">
              <input
                v-model="form.color"
                type="color"
                data-testid="category-form-color"
                class="h-9 w-10 cursor-pointer rounded-lg border border-slate-200 bg-white p-1"
              />
              <input
                v-model="form.color"
                type="text"
                placeholder="#6B7280"
                class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3 py-1.5 font-mono text-xs text-slate-800 outline-none focus:border-indigo-500 focus:bg-white"
              />
            </div>
          </div>

          <!-- Sort Order -->
          <div class="space-y-1">
            <label class="text-xs font-semibold text-slate-700"
              >Sort Order</label
            >
            <input
              v-model.number="form.sort_order"
              type="number"
              min="0"
              max="65535"
              data-testid="category-form-sort-order"
              class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white"
            />
          </div>
        </div>

        <!-- Active Switch -->
        <div class="flex items-center gap-2 pt-1">
          <label
            class="inline-flex cursor-pointer items-center gap-2 text-xs font-semibold text-slate-700"
          >
            <input
              v-model="form.is_active"
              type="checkbox"
              data-testid="category-form-active"
              class="h-4 w-4 rounded-md border-slate-300 text-indigo-600 focus:ring-indigo-500"
            />
            <span>Active category (available when filing tickets)</span>
          </label>
        </div>

        <!-- Slug helper if editing -->
        <p
          v-if="category"
          data-testid="category-form-slug"
          class="rounded-lg bg-slate-50 p-2 font-mono text-xs text-slate-500 border border-slate-100"
        >
          Slug: {{ category.slug }} (unchanged on rename)
        </p>

        <!-- Error Message -->
        <p
          v-if="message"
          class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
        >
          {{ message }}
        </p>

        <!-- Actions -->
        <div
          class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
        >
          <button
            type="button"
            @click="emit('close')"
            class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
          >
            Cancel
          </button>
          <button
            data-testid="category-form-submit"
            type="submit"
            class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700 transition-colors"
          >
            Save Category
          </button>
        </div>
      </form>
    </div>
  </BaseDialog>
</template>
