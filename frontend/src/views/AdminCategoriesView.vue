<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import CategoryBadge from '../components/CategoryBadge.vue'
import CategoryFormDialog from '../components/CategoryFormDialog.vue'
import { useCategoriesStore } from '../stores/categories'
import type { Category } from '../api/categories'
import { deleteBlockedBy } from '../api/categories'
import { errorMessage } from '../api/errors'
import CategoryDeleteDialog from '../components/CategoryDeleteDialog.vue'
const store = useCategoriesStore()
const editing = ref<Category>()
const open = ref(false)
const blocking = ref<{
  category: Category
  message: string
  ticket_count: number
  reassign_to_options: Category[]
}>()
watch(
  () => store.status,
  () => void store.load(),
)
onMounted(() => void store.load())
function edit(c: Category) {
  editing.value = c
  open.value = true
}
function create() {
  editing.value = undefined
  open.value = true
}
async function remove(c: Category) {
  if (!window.confirm('Delete category?')) return
  try {
    await store.remove(c.id)
  } catch (reason) {
    const blocked = deleteBlockedBy(reason)
    if (blocked) blocking.value = { category: c, ...blocked }
    else store.error = errorMessage(reason)
  }
}
</script>
<template>
  <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <!-- Header -->
    <div
      class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center"
    >
      <div>
        <h1
          class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
        >
          Categories
        </h1>
        <p class="mt-1 text-sm text-slate-500">
          Manage ticket categories, routing tags, and display priority order.
        </p>
      </div>

      <div class="flex items-center gap-3">
        <div class="flex items-center gap-2">
          <label class="text-xs font-semibold text-slate-500">Status:</label>
          <select
            v-model="store.status"
            data-testid="categories-status"
            class="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 shadow-2xs outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100"
          >
            <option value="">All statuses</option>
            <option value="active">Active only</option>
            <option value="inactive">Inactive only</option>
          </select>
        </div>

        <button
          data-testid="categories-new"
          @click="create"
          class="inline-flex items-center gap-1.5 rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-md shadow-indigo-500/20 transition-all hover:bg-indigo-700 active:scale-95"
        >
          <svg
            class="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2.5"
          >
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              d="M12 4v16m8-8H4"
            />
          </svg>
          <span>New category</span>
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div
      v-if="store.loading"
      data-testid="categories-loading"
      class="flex items-center justify-center rounded-2xl border border-slate-200/80 bg-white p-12 shadow-sm"
    >
      <div class="flex items-center gap-3 text-sm font-medium text-slate-500">
        <svg
          class="h-5 w-5 animate-spin text-indigo-600"
          fill="none"
          viewBox="0 0 24 24"
        >
          <circle
            class="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            stroke-width="4"
          />
          <path
            class="opacity-75"
            fill="currentColor"
            d="M4 12a8 8 0 018-8v8H4z"
          />
        </svg>
        <span>Loading categories...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-if="store.error"
      data-testid="categories-error"
      class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700 shadow-sm"
    >
      {{ store.error }}
    </div>

    <!-- Table -->
    <div
      v-if="!store.loading && store.categories.length"
      class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm"
    >
      <div class="overflow-x-auto">
        <table class="w-full text-left text-sm" data-testid="categories-table">
          <thead
            class="border-b border-slate-200 bg-slate-50/75 text-[11px] font-bold uppercase tracking-wider text-slate-500"
          >
            <tr>
              <th scope="col" class="py-3.5 pl-6 pr-3">Category</th>
              <th scope="col" class="px-3 py-3.5">Slug</th>
              <th scope="col" class="px-3 py-3.5">Description</th>
              <th scope="col" class="px-3 py-3.5">Sort Order</th>
              <th scope="col" class="py-3.5 pl-3 pr-6 text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 bg-white">
            <tr
              v-for="c in store.categories"
              :key="c.id"
              data-testid="categories-row"
              class="transition-colors hover:bg-slate-50/70"
            >
              <td class="whitespace-nowrap py-4 pl-6 pr-3">
                <CategoryBadge :category="c" />
              </td>
              <td
                class="whitespace-nowrap px-3 py-4 font-mono text-xs text-slate-500"
              >
                {{ c.slug }}
              </td>
              <td class="max-w-xs truncate px-3 py-4 text-xs text-slate-600">
                {{ c.description || '—' }}
              </td>
              <td class="whitespace-nowrap px-3 py-4">
                <input
                  type="number"
                  :value="c.sort_order"
                  @change="
                    store.saveSortOrder(
                      c,
                      Number(($event.target as HTMLInputElement).value),
                    )
                  "
                  class="w-20 rounded-lg border border-slate-200 bg-slate-50/50 px-2.5 py-1 text-xs font-semibold text-slate-700 outline-none focus:border-indigo-500 focus:bg-white"
                />
              </td>
              <td class="whitespace-nowrap py-4 pl-3 pr-6 text-right">
                <div class="inline-flex items-center gap-1.5">
                  <button
                    data-testid="categories-toggle"
                    @click="store.setActive(c, !c.is_active)"
                    class="rounded-lg px-2.5 py-1 text-xs font-medium transition-colors"
                    :class="
                      c.is_active
                        ? 'text-amber-700 hover:bg-amber-50'
                        : 'text-emerald-700 hover:bg-emerald-50'
                    "
                  >
                    {{ c.is_active ? 'Deactivate' : 'Activate' }}
                  </button>
                  <button
                    @click="edit(c)"
                    class="rounded-lg px-2.5 py-1 text-xs font-medium text-indigo-600 hover:bg-indigo-50 transition-colors"
                  >
                    Edit
                  </button>
                  <button
                    data-testid="categories-delete"
                    @click="remove(c)"
                    class="rounded-lg px-2.5 py-1 text-xs font-medium text-rose-600 hover:bg-rose-50 transition-colors"
                  >
                    Delete
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Empty State -->
    <div
      v-if="!store.loading && !store.categories.length"
      data-testid="categories-empty"
      class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-slate-200 bg-white p-12 text-center shadow-xs"
    >
      <div
        class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-50 text-slate-400"
      >
        <svg
          class="h-6 w-6"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"
          />
        </svg>
      </div>
      <p class="mt-4 text-base font-bold text-slate-900">
        No categories found.
      </p>
      <p class="mt-1 text-xs text-slate-500">
        Create categories to organize and route incoming tickets.
      </p>
    </div>

    <!-- Dialogs -->
    <CategoryFormDialog
      v-if="open"
      :category="editing"
      @saved="open = false"
      @close="open = false"
    />
    <CategoryDeleteDialog
      v-if="blocking"
      :category="blocking.category"
      :blocked="blocking"
      @close="blocking = undefined"
    />
  </main>
</template>
