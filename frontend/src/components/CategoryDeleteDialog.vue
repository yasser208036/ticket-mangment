<script setup lang="ts">
import { ref } from 'vue'
import { errorMessage } from '../api/errors'
import type { Category, CategoryDeleteBlocked } from '../api/categories'
import { useCategoriesStore } from '../stores/categories'
import BaseDialog from './BaseDialog.vue'
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
  <BaseDialog>
    <div class="space-y-4">
      <!-- Warning Header -->
      <div class="flex items-start gap-3">
        <div
          class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-amber-50 text-amber-600"
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
              d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
            />
          </svg>
        </div>
        <div class="space-y-1">
          <h3 class="text-base font-bold text-slate-900">Category In Use</h3>
          <p
            data-testid="category-delete-count"
            class="text-xs font-semibold text-amber-800"
          >
            {{ blocked.ticket_count }} tickets
          </p>
        </div>
      </div>

      <p class="text-sm text-slate-600">
        {{ blocked.message }}
      </p>

      <p
        v-if="error"
        data-testid="category-delete-error"
        class="rounded-lg bg-rose-50 p-2.5 text-xs font-medium text-rose-700"
      >
        {{ error }}
      </p>

      <!-- Reassign Target Selector -->
      <div v-if="blocked.reassign_to_options.length" class="space-y-1.5 pt-2">
        <label class="text-xs font-semibold text-slate-700"
          >Reassign existing tickets to:</label
        >
        <select
          v-model="target"
          data-testid="category-delete-target"
          class="w-full rounded-xl border border-slate-200 bg-slate-50/50 px-3.5 py-2.5 text-sm text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-4 focus:ring-indigo-100"
        >
          <option :value="undefined" disabled>
            Select replacement category
          </option>
          <option
            v-for="option in blocked.reassign_to_options"
            :key="option.id"
            :value="option.id"
          >
            {{ option.name }}
          </option>
        </select>
      </div>

      <!-- Actions -->
      <div
        class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100"
      >
        <button
          data-testid="category-delete-cancel"
          type="button"
          @click="emit('close')"
          class="rounded-xl border border-slate-200 bg-white px-4 py-2 text-xs font-semibold text-slate-700 shadow-2xs hover:bg-slate-50 transition-colors"
        >
          Cancel
        </button>
        <button
          v-if="blocked.reassign_to_options.length"
          data-testid="category-delete-confirm"
          @click="confirmDelete"
          :disabled="target === undefined"
          class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-rose-700 transition-colors disabled:opacity-50"
        >
          Confirm Reassign & Delete
        </button>
      </div>
    </div>
  </BaseDialog>
</template>
