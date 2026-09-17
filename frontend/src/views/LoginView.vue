<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import UiAlert from '../components/ui/UiAlert.vue'
import UiButton from '../components/ui/UiButton.vue'
import UiField from '../components/ui/UiField.vue'
import UiIcon from '../components/ui/UiIcon.vue'
import { safeRedirect } from '../router/guards'
import { useAuthStore } from '../stores/auth'
import { errorMessage } from '../api/errors'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const email = ref('')
const password = ref('')
const error = ref<string | null>(null)
const submitting = ref(false)

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null
  try {
    await auth.login(email.value, password.value)
    await router.replace(safeRedirect(route.query.redirect))
  } catch (caughtError) {
    error.value = errorMessage(caughtError)
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main
    class="flex min-h-screen items-center justify-center bg-canvas px-4 py-12"
  >
    <section class="w-full max-w-sm">
      <div class="mb-7 flex flex-col items-center text-center">
        <span
          class="flex h-10 w-10 items-center justify-center rounded-xl bg-ink-900 text-white"
        >
          <UiIcon name="bolt" class="h-5 w-5" :stroke-width="2" />
        </span>
        <h1 class="mt-4 text-xl font-semibold tracking-tight text-ink-900">
          Sign in to Deskflow
        </h1>
        <p class="mt-1 text-sm text-ink-500">
          Manage your support tickets and queue.
        </p>
      </div>

      <div class="ui-card ui-card-pad">
        <form class="space-y-4" @submit.prevent="submit">
          <UiField v-slot="field" label="Email">
            <input
              v-bind="field"
              v-model="email"
              type="email"
              autocomplete="username"
              required
              placeholder="you@deskflow.app"
              data-testid="login-email"
            />
          </UiField>

          <UiField v-slot="field" label="Password">
            <input
              v-bind="field"
              v-model="password"
              type="password"
              autocomplete="current-password"
              required
              placeholder="••••••••"
              data-testid="login-password"
            />
          </UiField>

          <UiAlert v-if="error" data-testid="login-error">{{ error }}</UiAlert>

          <UiButton
            class="w-full"
            variant="primary"
            type="submit"
            :loading="submitting"
            data-testid="login-submit"
          >
            {{ submitting ? 'Signing in…' : 'Sign in' }}
          </UiButton>
        </form>
      </div>
    </section>
  </main>
</template>
