<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router'
defineProps<{
  label: string
  count: number
  to: RouteLocationRaw
  testid: string
}>()
</script>
<template>
  <RouterLink
    :to="to"
    :data-testid="testid"
    class="group relative flex flex-col justify-between overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-indigo-200 hover:shadow-md hover:shadow-slate-200/50"
  >
    <div class="flex items-start justify-between gap-4">
      <div>
        <span
          class="text-xs font-semibold uppercase tracking-wider text-slate-500"
        >
          {{ label }}
        </span>
        <strong
          :data-testid="testid + '-count'"
          class="mt-2 block text-3xl font-extrabold tracking-tight text-slate-900 group-hover:text-indigo-600 transition-colors"
        >
          {{ count }}
        </strong>
      </div>
      <div
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-50 text-slate-400 group-hover:bg-indigo-50 group-hover:text-indigo-600 transition-colors"
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
            d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"
          />
        </svg>
      </div>
    </div>
    <div
      class="mt-4 flex items-center gap-1 text-xs font-medium text-slate-400 group-hover:text-indigo-600 transition-colors"
    >
      <span>View tickets</span>
      <svg
        class="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
        stroke-width="2"
      >
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
      </svg>
    </div>
  </RouterLink>
</template>
