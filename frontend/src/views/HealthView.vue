<script setup lang="ts">
import { onMounted } from 'vue'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiLoadingPanel from '../components/ui/UiLoadingPanel.vue'
import UiPageHeader from '../components/ui/UiPageHeader.vue'
import UiPanel from '../components/ui/UiPanel.vue'
import { useHealthStore } from '../stores/health'

const health = useHealthStore()

onMounted(() => {
  void health.load()
})
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-5 px-4 py-7 sm:px-6 lg:px-8">
    <UiPageHeader
      title="API health"
      description="Diagnostics, database connectivity and environment."
    >
      <template #actions>
        <UiButton
          icon="refresh"
          :loading="health.loading"
          @click="health.load()"
        >
          Re-check
        </UiButton>
      </template>
    </UiPageHeader>

    <UiLoadingPanel
      v-if="health.loading"
      shape="card"
      label="Checking API health"
      testid="health-loading"
      :rows="4"
    />

    <UiAlert v-else-if="health.error" title="API connection error">
      <span class="font-mono text-xs" data-testid="health-error">
        {{ health.error }}
      </span>
    </UiAlert>

    <UiPanel v-else-if="health.data" title="Live status">
      <template #actions>
        <div class="flex items-center gap-2.5">
          <span
            data-testid="health-status"
            class="ui-chip uppercase"
            :class="
              health.data.status === 'ok'
                ? 'good border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'bad border-rose-200 bg-rose-50 text-rose-700'
            "
          >
            <span class="relative flex h-1.5 w-1.5" aria-hidden="true">
              <span
                v-if="health.data.status === 'ok'"
                class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"
              />
              <span
                class="relative inline-flex h-1.5 w-1.5 rounded-full"
                :class="
                  health.data.status === 'ok' ? 'bg-emerald-500' : 'bg-rose-500'
                "
              />
            </span>
            {{ health.data.status }}
          </span>
          <span class="font-mono text-xs text-ink-400">
            v{{ health.data.version }}
          </span>
        </div>
      </template>

      <dl data-testid="health-result" class="divide-y divide-line text-xs">
        <div
          class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
        >
          <dt class="text-ink-500">Application</dt>
          <dd class="text-right font-medium text-ink-900">
            {{ health.data.app }} {{ health.data.version }} ({{
              health.data.environment
            }})
          </dd>
        </div>

        <div
          class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
        >
          <dt class="text-ink-500">API gateway</dt>
          <dd class="text-right font-mono font-medium text-ink-900">
            {{ health.data.api }}
          </dd>
        </div>

        <div
          class="flex items-center justify-between gap-3 px-5 py-2.5 sm:px-6"
        >
          <dt class="text-ink-500">Database connection</dt>
          <dd
            data-testid="health-database"
            class="ui-chip"
            :class="
              health.data.checks.database?.ok
                ? 'good border-emerald-200 bg-emerald-50 text-emerald-700'
                : 'bad border-rose-200 bg-rose-50 text-rose-700'
            "
          >
            {{
              health.data.checks.database?.ok
                ? 'reachable'
                : (health.data.checks.database?.error ?? 'unavailable')
            }}
          </dd>
        </div>

        <div
          class="flex items-baseline justify-between gap-3 px-5 py-2.5 sm:px-6"
        >
          <dt class="text-ink-500">Last checked at</dt>
          <dd class="text-right font-mono text-ink-600">
            {{ health.data.time }}
          </dd>
        </div>
      </dl>
    </UiPanel>
  </main>
</template>
