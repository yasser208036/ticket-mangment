<script setup lang="ts">
import { onMounted } from 'vue'
import { useHealthStore } from '../stores/health'

const health = useHealthStore()

onMounted(() => {
  void health.load()
})
</script>

<template>
  <main class="mx-auto max-w-2xl px-4 py-8 sm:px-6 lg:px-8 space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1
          class="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl"
        >
          API Health
        </h1>
        <p class="mt-1 text-sm text-slate-500">
          System diagnostics, database connectivity, and environment status.
        </p>
      </div>

      <button
        type="button"
        :disabled="health.loading"
        @click="health.load()"
        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3.5 py-2 text-xs font-semibold text-slate-700 shadow-2xs transition-all hover:bg-slate-50 disabled:opacity-50"
      >
        <svg
          class="h-4 w-4 text-slate-500"
          :class="{ 'animate-spin': health.loading }"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
          />
        </svg>
        <span>Re-check</span>
      </button>
    </div>

    <!-- Loading State -->
    <div
      v-if="health.loading"
      data-testid="health-loading"
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
        <span>Checking API health...</span>
      </div>
    </div>

    <!-- Error State -->
    <div
      v-else-if="health.error"
      class="bad rounded-2xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700 shadow-sm"
    >
      <div class="flex items-center gap-2 font-bold text-rose-800">
        <svg
          class="h-5 w-5 text-rose-600"
          fill="none"
          viewBox="0 0 24 24"
          stroke="currentColor"
          stroke-width="2"
        >
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
          />
        </svg>
        <span>API Connection Error</span>
      </div>
      <p
        class="mt-2 text-xs font-mono text-rose-600"
        data-testid="health-error"
      >
        {{ health.error }}
      </p>
    </div>

    <!-- Health Result Card -->
    <div
      v-else-if="health.data"
      class="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm space-y-6"
    >
      <div
        class="flex items-center justify-between border-b border-slate-100 pb-4"
      >
        <div class="flex items-center gap-2.5">
          <span class="relative flex h-3 w-3">
            <span
              v-if="health.data.status === 'ok'"
              class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"
            />
            <span
              class="relative inline-flex h-3 w-3 rounded-full"
              :class="
                health.data.status === 'ok' ? 'bg-emerald-500' : 'bg-rose-500'
              "
            />
          </span>
          <span class="text-sm font-bold text-slate-900">Live Status:</span>
          <span
            data-testid="health-status"
            class="rounded-full px-2.5 py-0.5 text-xs font-bold uppercase tracking-wider"
            :class="
              health.data.status === 'ok'
                ? 'good bg-emerald-50 text-emerald-700'
                : 'bad bg-rose-50 text-rose-700'
            "
          >
            {{ health.data.status }}
          </span>
        </div>

        <span class="font-mono text-xs text-slate-400">
          v{{ health.data.version }}
        </span>
      </div>

      <dl data-testid="health-result" class="divide-y divide-slate-100 text-xs">
        <div class="flex justify-between py-3">
          <dt class="font-semibold text-slate-500">Application</dt>
          <dd class="font-medium text-slate-900">
            {{ health.data.app }} {{ health.data.version }} ({{
              health.data.environment
            }})
          </dd>
        </div>

        <div class="flex justify-between py-3">
          <dt class="font-semibold text-slate-500">API Gateway</dt>
          <dd class="font-mono font-medium text-slate-900">
            {{ health.data.api }}
          </dd>
        </div>

        <div class="flex justify-between py-3 items-center">
          <dt class="font-semibold text-slate-500">Database Connection</dt>
          <dd
            data-testid="health-database"
            class="rounded-full px-2.5 py-0.5 text-xs font-bold"
            :class="
              health.data.checks.database?.ok
                ? 'good bg-emerald-50 text-emerald-700'
                : 'bad bg-rose-50 text-rose-700'
            "
          >
            {{
              health.data.checks.database?.ok
                ? 'reachable'
                : (health.data.checks.database?.error ?? 'unavailable')
            }}
          </dd>
        </div>

        <div class="flex justify-between py-3">
          <dt class="font-semibold text-slate-500">Last Checked At</dt>
          <dd class="font-mono text-slate-600">
            {{ health.data.time }}
          </dd>
        </div>
      </dl>
    </div>
  </main>
</template>
