<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import CategoryBadge from '../components/CategoryBadge.vue'
import CategoryFormDialog from '../components/CategoryFormDialog.vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiEmptyState from '../components/ui/UiEmptyState.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import { useCategoriesStore } from '../stores/categories'
import type { Category } from '../api/categories'
import { deleteBlockedBy } from '../api/categories'
import { errorMessage } from '../api/errors'
import CategoryDeleteDialog from '../components/CategoryDeleteDialog.vue'
import BaseDialog from '../components/BaseDialog.vue'
const store = useCategoriesStore()
const editing = ref<Category>()
const open = ref(false)
const confirming = ref<Category>()
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
// Two steps, and neither of them `window.confirm`: the blocked case already
// opens a real dialog to pick a destination category, and a native prompt in
// front of it made the same action look like two different features.
function remove(c: Category) {
  confirming.value = c
}
async function confirmRemove() {
  const c = confirming.value
  if (!c) return
  confirming.value = undefined
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
  <main class="mx-auto max-w-7xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader
      title="Categories"
      description="Ticket categories, routing tags and display order."
    >
      <template #actions>
        <label class="flex items-center gap-1.5 text-xs text-ink-500">
          Status
          <select
            v-model="store.status"
            data-testid="categories-status"
            class="ui-select w-auto py-1.5 text-xs"
          >
            <option value="">All</option>
            <option value="active">Active only</option>
            <option value="inactive">Inactive only</option>
          </select>
        </label>
        <UiButton
          variant="primary"
          icon="plus"
          data-testid="categories-new"
          @click="create"
        >
          New category
        </UiButton>
      </template>
    </UiPageHeader>

    <UiAlert v-if="store.error" data-testid="categories-error">
      {{ store.error }}
    </UiAlert>

    <UiLoadingPanel
      v-if="store.loading"
      label="Loading categories"
      testid="categories-loading"
    />

    <div v-else-if="store.categories.length" class="ui-card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="ui-table" data-testid="categories-table">
          <thead class="ui-thead">
            <tr>
              <th scope="col" class="ui-th">Category</th>
              <th scope="col" class="ui-th">Slug</th>
              <th scope="col" class="ui-th">Description</th>
              <th scope="col" class="ui-th">Sort order</th>
              <th scope="col" class="ui-th text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="ui-tbody">
            <tr
              v-for="c in store.categories"
              :key="c.id"
              data-testid="categories-row"
              class="ui-tr"
            >
              <td class="ui-td whitespace-nowrap">
                <CategoryBadge :category="c" />
              </td>
              <td
                class="ui-td whitespace-nowrap font-mono text-xs text-ink-500"
              >
                {{ c.slug }}
              </td>
              <td class="ui-td max-w-xs truncate text-xs text-ink-600">
                {{ c.description || '—' }}
              </td>
              <td class="ui-td whitespace-nowrap">
                <input
                  type="number"
                  :value="c.sort_order"
                  :aria-label="`Sort order for ${c.name}`"
                  class="ui-input tabular w-20 py-1 text-xs"
                  @change="
                    store.saveSortOrder(
                      c,
                      Number(($event.target as HTMLInputElement).value),
                    )
                  "
                />
              </td>
              <td class="ui-td whitespace-nowrap text-right">
                <span class="inline-flex items-center gap-1">
                  <UiButton
                    size="sm"
                    variant="ghost"
                    data-testid="categories-toggle"
                    @click="store.setActive(c, !c.is_active)"
                  >
                    {{ c.is_active ? 'Deactivate' : 'Activate' }}
                  </UiButton>
                  <UiButton size="sm" variant="ghost" @click="edit(c)">
                    Edit
                  </UiButton>
                  <UiButton
                    size="sm"
                    variant="danger-quiet"
                    data-testid="categories-delete"
                    @click="remove(c)"
                  >
                    Delete
                  </UiButton>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <UiEmptyState
      v-else
      icon="tag"
      title="No categories found."
      description="Create categories to organise and route incoming tickets."
      testid="categories-empty"
    />

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
    <BaseDialog
      v-if="confirming"
      testid="categories-confirm-delete"
      title="Delete category"
      icon="trash"
      tone="danger"
      @close="confirming = undefined"
    >
      <p class="text-sm text-ink-600">
        Delete “{{ confirming.name }}”? Tickets already filed under it keep it
        until you reassign them.
      </p>
      <template #footer>
        <UiButton
          data-testid="categories-confirm-cancel"
          @click="confirming = undefined"
        >
          Cancel
        </UiButton>
        <UiButton
          variant="danger"
          data-testid="categories-confirm-delete-confirm"
          @click="confirmRemove"
        >
          Delete
        </UiButton>
      </template>
    </BaseDialog>
  </main>
</template>
