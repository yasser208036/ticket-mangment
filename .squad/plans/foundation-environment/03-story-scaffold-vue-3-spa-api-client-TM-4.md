# Story 03 — Scaffold the Vue 3 SPA with an API client (Story: TM-4)

## Prerequisites

- **Story 01 (TM-2) completed:** [`01-story-scaffold-monorepo-skeleton-TM-2.md`](01-story-scaffold-monorepo-skeleton-TM-2.md). The repository is under version control (commit `1fed355`) and the root `README.md` this story extends exists.
- **Story 02 (TM-3) must be _implemented_, not merely planned:** [`02-story-install-configure-laravel-13-api-TM-3.md`](02-story-install-configure-laravel-13-api-TM-3.md). This is a hard blocker, for two reasons:
  1. Until TM-3 installs the `pdo_mysql` extension, `GET /api/v1/health` returns **`503 degraded`**. Acceptance criterion 4 ("the SPA successfully calls `/api/v1/health` and renders the result") cannot be demonstrated against a degraded endpoint.
  2. TM-3 **changes the response shape**: `version` becomes the app version and the API version moves to a new `api` key. The `HealthResponse` interface in task 4 below is typed against the *post*-TM-3 contract. Writing it first means typing against a payload that is about to change.

  Confirm before starting: `curl -s http://localhost:8000/api/v1/health` returns `200` and the body contains an `api` field.
- **`docs/api-contract.md` is the source of truth for the response type.** TM-3 writes the field table there. Type `HealthResponse` from that document, not from a live response — a live `200` body omits `checks.database.error`, and the optional field would be missed.
- **Node.js 22+ and an installed `frontend/node_modules`.** Already present: `vite 8.2.2`, `vitest 4.1.11`, `jsdom 29.1.1`, `@vue/test-utils 2.4.11`, `axios 1.19.0`, `pinia 4.0.3`, `vue-router 4.6.4`, `vue 3.5.41`. **No new dependency is needed for this story** — everything the acceptance criteria ask for is already in `package.json`.
- No coordination needed on shared contracts, but note the ownership boundaries this story deliberately stops at: **TM-9** owns the login form and the token exchange, **TM-11** owns session persistence and route guards, **TM-6** owns the ESLint config, **TM-29** owns replacing `/` with a real dashboard. This story creates the seams they plug into and nothing more.

---

## Story Goal

Turn `frontend/` from the stock Vite scaffold into a wired Vue 3 SPA that can talk to the Laravel API with authentication headers from its first request.

Five user-visible outcomes:

1. `npm run dev` in `frontend/` serves the SPA on **port 5173** and proxies `/api/*` to the Laravel API, so every request in development is **same-origin** and CORS cannot be the reason one fails.
2. `src/api/client.ts` exports a single configured axios instance whose base URL comes from `VITE_API_BASE_URL`.
3. That instance attaches `Authorization: Bearer <token>` to every request when a token exists, and on any `401` clears the token and routes to `/login`.
4. Visiting `http://localhost:5173/` calls `GET /api/v1/health` through the proxy and renders the status, app version, API version and database check.
5. `npm test` in `frontend/` runs — there is no `test` script today and `npx vitest run` exits `1` with "No test files found" — and covers the interceptors, the store, and the view.

**Not in scope:** a login form or any real authentication (TM-9), route guards or session restore (TM-11), an ESLint config (TM-6, whose dependencies are already installed but unconfigured), a component library or design system, the dashboard (TM-29), and any change under `backend/`. Do **not** add a dependency — if a task seems to need one, re-read it.

---

## Context — Read These Files First

1. `frontend/vite.config.ts` — all 7 lines. The entire config is `plugins: [vue()]`. There is **no `server` block, no proxy, and no `test` block**. Task 2 rewrites this file.
2. `frontend/src/main.ts` — all 5 lines: `createApp(App).mount('#app')`. **Neither Pinia nor Vue Router is installed into the app**, despite both being dependencies. Task 7 fixes this.
3. `frontend/src/App.vue` — all 7 lines. Renders `<HelloWorld />` and nothing else. Task 7 replaces the body with `<RouterView />`.
4. `frontend/package.json` — all 33 lines. `scripts` is lines 6–10: `dev`, `build` (`vue-tsc -b && vite build`), `preview`. **There is no `test` script.** Dependencies are lines 11–16, devDependencies 17–32 — read both and confirm nothing needs adding.
5. `frontend/tsconfig.app.json` — all 15 lines, and then run `npx tsc -p tsconfig.app.json --showConfig` to see what it resolves to through `@vue/tsconfig/tsconfig.dom.json`. **Four resolved options constrain every line of code in this story** — read the "Type-checking constraints" section below before writing any TypeScript.
6. `frontend/tsconfig.node.json` — all 23 lines. `include` is **`["vite.config.ts"]`** only (line 22) and `types` is `["node"]` (line 6). This is why `vite.config.ts` may use `process.cwd()`, and why adding a separate `vitest.config.ts` would need this include list extended — task 2 avoids that by keeping one config file.
7. `frontend/src/components/HelloWorld.vue` — all 95 lines. Scaffold-only. It is the sole importer of `src/assets/hero.png`, `src/assets/vite.svg` and `src/assets/vue.svg` (lines 3–5) and the sole referencer of `public/icons.svg` (lines 31, 52, 60, 68, 76, 84). Verify with `grep -rn -e hero.png -e vite.svg -e vue.svg -e icons.svg src index.html`. Task 8 deletes all five files.
8. `frontend/src/style.css` — 296 lines. Read the block boundaries: `:root` tokens and the dark-mode media query are **lines 1–51**, `body` **53–55**, `h1, h2` **57–62**, `h1` **64–85**, `.counter` **87–118**, `.hero` **120–157**, `#app` **159–169**, `#center` **171–184**, `#next-steps` / `#docs` / `#next-steps ul` **185–266**, `#spacer` **268–274**, `.ticks` **276–296**. Task 8 keeps the tokens and deletes the blocks that style deleted markup.
9. `frontend/.gitignore` — all 24 lines. Note it does **not** mention `.env`; that comes from the root `.gitignore` lines 2–4. Confirmed by `git check-ignore`: **`frontend/.env` is ignored, `frontend/.env.example` is not** (the `!.env.example` negation on root line 4 covers it at any depth). Task 1 relies on this — do not add an `.env` rule to `frontend/.gitignore`.
10. `frontend/index.html` — all 13 lines. `<title>frontend</title>` on line 7 and `/favicon.svg` on line 5. `favicon.svg` **stays**; only `icons.svg` goes.
11. `backend/config/cors.php` (read-only, do not edit) — `paths` on line 17 is `['api/*', 'sanctum/csrf-cookie']`, and `allowed_origins` on lines 21–24 already lists `env('FRONTEND_URL', 'http://localhost:5173')` and `http://127.0.0.1:5173`. `supports_credentials` is `false` (line 35) because auth is a bearer token, not a cookie. **This means CORS already works — the proxy in task 2 is required by the acceptance criterion and is the better default, but it is not papering over a broken backend.** Understand which mechanism you are relying on.
12. `backend/.env.example` — `FRONTEND_URL=http://localhost:5173` (line 60) and `SANCTUM_STATEFUL_DOMAINS=localhost:5173,127.0.0.1:5173` (line 61). **These two lines are why task 2 pins the dev-server port instead of letting Vite pick the next free one.**
13. `docs/api-contract.md` — after TM-3, its `GET /api/v1/health` section carries both response shapes and the field table. Task 4's interface must match that table field-for-field, including `error` being present **only** on a failed check.
14. `README.md` (repo root) — the frontend step is **lines 58–64** and the "Environment files" section is **lines 86–90**. Task 9 edits both.
15. Run `npx vue-tsc -b` in `frontend/` and confirm it exits `0` **before** you change anything. It passes today; every task below must keep it passing.

---

## Type-checking constraints (read before writing any code)

`npm run build` runs `vue-tsc -b` first, so a type error is a build failure, and `tsconfig.app.json` includes `src/**/*.ts` — **the spec files are type-checked too**. Four resolved options bite:

| Option | Consequence for this story |
|---|---|
| `verbatimModuleSyntax: true` | A type-only import **must** use `import type`. `import { AxiosError } from 'axios'` is correct only where `AxiosError` is used as a *value* (the spec files); everywhere it is only an annotation, write `import type`. |
| `erasableSyntaxOnly: true` | No `enum`, no `namespace`, no constructor parameter properties. `status` is a string-literal union, not an enum. |
| `noUnusedLocals` / `noUnusedParameters: true` | An unused import fails the build. This is why the new `App.vue` has **no `<script>` block** — `RouterView` is registered globally by the router plugin, so importing it would be an unused local. |
| `strict: true` (with `noUncheckedIndexedAccess` **off**) | `checks.database` is typed `HealthCheck`, not `HealthCheck \| undefined`. The optional-chaining in the view is runtime defence against a malformed payload, not a type requirement. |

`types` is `["vite/client"]`, which restricts *globals* only. Explicit imports (`import { describe, it, expect } from 'vitest'`) resolve normally — do **not** add `"vitest/globals"` to the `types` array, and do not rely on global `describe`/`it`.

---

## Implementation tasks

**No backend changes required.** Nothing under `backend/` is touched; `config/cors.php` and `.env.example` are read for their values only.

### 1 — Declare the frontend environment

**Create file: `frontend/.env.example`**

Vite exposes only variables prefixed `VITE_`, and it reads `.env` — never `.env.example`. This file is the tracked template; `frontend/.env` is git-ignored by the root rule.

```dotenv
# Base URL every API request is built on. Relative in development so requests go
# through the Vite dev-server proxy (see vite.config.ts) and stay same-origin —
# the browser never preflights, so CORS cannot be why a call fails.
# In production set this to the deployed API origin, e.g. https://api.example.com/api/v1
VITE_API_BASE_URL=/api/v1

# Where the dev-server proxy forwards /api. Must match APP_URL in backend/.env.
# Only used by vite.config.ts at dev-server start; never shipped to the browser.
VITE_API_PROXY_TARGET=http://localhost:8000
```

Then create the working copy — **the dev server will not pick up a `.env.example`**:

```bash
cd frontend
cp .env.example .env
```

Verify the template stays tracked and the working copy does not:

```bash
cd /home/yasser-mohamed/ticket-mangment
git check-ignore -q frontend/.env         && echo "frontend/.env ignored — correct"
git check-ignore -q frontend/.env.example || echo "frontend/.env.example tracked — correct"
```

**Create file: `frontend/src/env.d.ts`**

`import.meta.env.VITE_API_BASE_URL` is otherwise untyped. This is Vite's documented augmentation pattern — the interfaces merge with the ones `vite/client` declares:

```ts
/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_BASE_URL: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
```

`VITE_API_PROXY_TARGET` is deliberately **absent** here. It is read by `vite.config.ts` through `loadEnv` at dev-server start and must never reach browser code; leaving it out of `ImportMetaEnv` makes an accidental `import.meta.env.VITE_API_PROXY_TARGET` a type error.

### 2 — Configure the dev-server proxy and the test runner

**File: `frontend/vite.config.ts`**

Replace all 7 lines. Three things happen here: the port is pinned, `/api` is proxied, and Vitest is configured — the `/// <reference types="vitest/config" />` line augments Vite's `UserConfig` with the `test` key, which is why `defineConfig` can still be imported from `vite` and why **no separate `vitest.config.ts` is created** (a new file would also have to be added to `tsconfig.node.json`'s `include`).

```ts
/// <reference types="vitest/config" />
import vue from '@vitejs/plugin-vue'
import { defineConfig, loadEnv } from 'vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), 'VITE_')

  return {
    plugins: [vue()],

    server: {
      // Pinned rather than "first free port". backend/.env's FRONTEND_URL and
      // SANCTUM_STATEFUL_DOMAINS both name 5173; if Vite silently moved to 5174
      // the API would start rejecting the origin with no visible cause.
      port: 5173,
      strictPort: true,

      proxy: {
        // Same-origin in development: the browser sends no preflight, so CORS
        // is never the reason a call fails. backend/config/cors.php still
        // allows :5173 directly — that is the fallback, not the mechanism.
        '/api': {
          target: env.VITE_API_PROXY_TARGET || 'http://localhost:8000',
          changeOrigin: true,
        },
      },
    },

    test: {
      // jsdom, not node — the component test mounts real DOM and client.spec.ts
      // reads localStorage.
      environment: 'jsdom',
      include: ['src/**/*.spec.ts'],
    },
  }
})
```

Only `/api` is proxied. Laravel's own `/up` probe lives at the root (`bootstrap/app.php:13`) and is **not** proxied — nothing in the SPA calls it.

**File: `frontend/package.json`**

Add three scripts to the block on lines 6–10. `test` is the one `CLAUDE.md` documents as missing:

```json
  "scripts": {
    "dev": "vite",
    "build": "vue-tsc -b && vite build",
    "preview": "vite preview",
    "test": "vitest run",
    "test:watch": "vitest",
    "typecheck": "vue-tsc -b"
  },
```

Change nothing in `dependencies` or `devDependencies`.

### 3 — Store the bearer token outside Pinia

**Create file: `frontend/src/api/token.ts`**

Read this rationale before reaching for a Pinia store: **axios interceptors are registered at module scope, which runs before `createPinia()` is installed on the app.** Calling `useTokenStore()` inside an interceptor would throw "no active Pinia" on the very first request. So the token lives in a plain module over `localStorage`, and TM-9's auth store will wrap *this* rather than replace it.

```ts
// The bearer token, deliberately NOT in a Pinia store: api/client.ts reads it
// from inside an axios interceptor, which runs at module scope — before
// createPinia() is installed in main.ts. TM-9's auth store wraps these.
const STORAGE_KEY = 'tm.token'

export function getToken(): string | null {
  try {
    return localStorage.getItem(STORAGE_KEY)
  } catch {
    // Private browsing / storage disabled. Treat as "not signed in".
    return null
  }
}

export function setToken(token: string): void {
  try {
    localStorage.setItem(STORAGE_KEY, token)
  } catch {
    // Nothing useful to do — the session simply will not survive a reload.
  }
}

export function clearToken(): void {
  try {
    localStorage.removeItem(STORAGE_KEY)
  } catch {
    // Already unreachable; nothing to clear.
  }
}
```

Every `localStorage` access is wrapped: Safari private mode and storage-disabled browsers **throw** rather than returning `null`, and an uncaught throw inside a request interceptor kills every API call.

### 4 — The axios client and its two interceptors

**Create file: `frontend/src/api/client.ts`**

```ts
import axios from 'axios'
import type { AxiosError, InternalAxiosRequestConfig } from 'axios'
import { clearToken, getToken } from './token'

/** The one request a 401 is a normal answer to — redirecting on it would loop. */
const LOGIN_PATH = '/auth/login'

// The router cannot be imported here: router → views → stores → api/client
// would be a cycle. main.ts injects the real handler instead.
let onUnauthorized: () => void = () => {
  window.location.assign('/login')
}

export function setUnauthorizedHandler(handler: () => void): void {
  onUnauthorized = handler
}

export const client = axios.create({
  // The fallback keeps a forgotten `cp .env.example .env` from producing
  // requests to a bare /health, which fails in a far more confusing way.
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: { Accept: 'application/json' },
  timeout: 10_000,
})

client.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = getToken()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

client.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    // A 401 means the token is gone or expired: drop it and send the user to
    // sign in. Never on the login request itself, or the redirect loops.
    if (error.response?.status === 401 && error.config?.url !== LOGIN_PATH) {
      clearToken()
      onUnauthorized()
    }

    return Promise.reject(error)
  },
)

export default client
```

Three details that are not stylistic:

- **`import type` on `AxiosError` and `InternalAxiosRequestConfig`.** Both are annotation-only here, and `verbatimModuleSyntax` requires the modifier. The spec file imports `AxiosError` *without* it because it constructs one.
- **The injected handler, not an imported router.** `router/index.ts` imports the views, the views use the store, the store uses this module. Importing the router back into this file closes that cycle and Vite's dev server will resolve one of the two to `undefined`.
- **`timeout: 10_000`.** Without it a hung API leaves the health view spinning forever with no error to render.

**Create file: `frontend/src/api/health.ts`**

Types live beside the call that returns them — one module per API resource. Later stories follow this shape (`src/api/tickets.ts`, `src/api/auth.ts`).

```ts
import client from './client'

/** One probed dependency. `error` is present only when `ok` is false. */
export interface HealthCheck {
  ok: boolean
  error?: string
}

/** Response of GET /api/v1/health. See docs/api-contract.md. */
export interface HealthResponse {
  status: 'ok' | 'degraded'
  app: string
  environment: string
  version: string
  api: string
  time: string
  checks: Record<string, HealthCheck>
}

export async function getHealth(): Promise<HealthResponse> {
  const { data } = await client.get<HealthResponse>('/health', {
    // 503 is a real answer from this endpoint, not a transport failure — the
    // body still says which check failed. Without this axios throws and the
    // one piece of information worth showing is discarded.
    validateStatus: (status) => status === 200 || status === 503,
  })

  return data
}
```

`status` is a string-literal union, not an `enum` — `erasableSyntaxOnly` forbids enums.

### 5 — The Pinia store

**Create file: `frontend/src/stores/health.ts`**

This is what makes Pinia genuinely wired rather than merely installed (acceptance criterion 1), and it keeps the view free of fetch logic.

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { getHealth } from '../api/health'
import type { HealthResponse } from '../api/health'

export const useHealthStore = defineStore('health', () => {
  const data = ref<HealthResponse | null>(null)
  const error = ref<string | null>(null)
  const loading = ref(false)

  async function load(): Promise<void> {
    loading.value = true
    error.value = null

    try {
      data.value = await getHealth()
    } catch (e) {
      // Reaching here means the request never completed — a 503 resolves
      // normally (see getHealth's validateStatus) and is rendered as degraded.
      data.value = null
      error.value = e instanceof Error ? e.message : 'The API is unreachable.'
    } finally {
      loading.value = false
    }
  }

  return { data, error, loading, load }
})
```

Note the split the comment records: **a `503` is data, a dropped connection is an error.** They render differently, and the test plan asserts both paths.

### 6 — Router and views

**Create file: `frontend/src/views/HealthView.vue`**

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useHealthStore } from '../stores/health'

const health = useHealthStore()

onMounted(() => {
  void health.load()
})
</script>

<template>
  <main class="panel">
    <h1>API health</h1>

    <p v-if="health.loading" data-testid="health-loading">Checking…</p>

    <p v-else-if="health.error" class="bad" data-testid="health-error">
      {{ health.error }}
    </p>

    <dl v-else-if="health.data" data-testid="health-result">
      <dt>Status</dt>
      <dd
        :class="health.data.status === 'ok' ? 'good' : 'bad'"
        data-testid="health-status"
      >
        {{ health.data.status }}
      </dd>

      <dt>App</dt>
      <dd>
        {{ health.data.app }} {{ health.data.version }} ({{ health.data.environment }})
      </dd>

      <dt>API</dt>
      <dd>{{ health.data.api }}</dd>

      <dt>Database</dt>
      <dd
        :class="health.data.checks.database?.ok ? 'good' : 'bad'"
        data-testid="health-database"
      >
        {{
          health.data.checks.database?.ok
            ? 'reachable'
            : (health.data.checks.database?.error ?? 'unavailable')
        }}
      </dd>

      <dt>Checked at</dt>
      <dd>{{ health.data.time }}</dd>
    </dl>

    <button type="button" :disabled="health.loading" @click="health.load()">
      Re-check
    </button>
  </main>
</template>

<style scoped>
.panel {
  padding: 32px;
  text-align: start;
}

dl {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 8px 24px;
  margin: 24px 0;
}

dt {
  color: var(--text);
  font-weight: 500;
}

dd {
  margin: 0;
  color: var(--text-h);
  font-family: var(--mono);
}

.good {
  color: green;
}

.bad {
  color: crimson;
}
</style>
```

The `data-testid` attributes are the handles the component test asserts on — do not assert on CSS classes, which are presentation and will change.

**Create file: `frontend/src/views/LoginView.vue`**

A placeholder route, needed because the 401 interceptor has to redirect somewhere. No `<script>` block — an unused import would fail `noUnusedLocals`.

```vue
<template>
  <main class="panel">
    <h1>Sign in</h1>
    <!--
      Placeholder. The form and the token exchange are TM-9; restoring a
      session and guarding routes is TM-11. This view exists so the 401
      interceptor in src/api/client.ts has a real destination.
    -->
    <p>Authentication is not implemented yet.</p>
  </main>
</template>

<style scoped>
.panel {
  padding: 32px;
  text-align: start;
}
</style>
```

**Create file: `frontend/src/router/index.ts`**

```ts
import { createRouter, createWebHistory } from 'vue-router'
import HealthView from '../views/HealthView.vue'
import LoginView from '../views/LoginView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    // Sprint 1 landing page: it proves the API is reachable. TM-29 replaces
    // this with the dashboard and moves the health view to its own path.
    { path: '/', name: 'home', component: HealthView },
    { path: '/login', name: 'login', component: LoginView },
    { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
  ],
})

export default router
```

`createWebHistory` (not `createWebHashHistory`) means a deep link like `/login` must be served `index.html` by the production web server. That rewrite rule belongs to TM-63's runbook; the Vite dev server does it automatically.

Both views are **statically** imported. Lazy `() => import(...)` route components are the right call once there are real screens; with two views it only adds a loading state to test.

### 7 — Install Pinia, the router, and the 401 handler

**File: `frontend/src/main.ts`**

Replace all 5 lines:

```ts
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from './api/client'

// Closes the loop the client cannot close itself: it must not import the
// router (that would be a cycle), so the destination is injected here.
setUnauthorizedHandler(() => {
  if (router.currentRoute.value.name !== 'login') {
    void router.replace({ name: 'login' })
  }
})

createApp(App).use(createPinia()).use(router).mount('#app')
```

The `name !== 'login'` guard matters even though `client.ts` already skips the login request: any *other* request that 401s while the user sits on `/login` would otherwise push a redundant navigation.

**File: `frontend/src/App.vue`**

Replace all 7 lines. **No `<script>` block** — `RouterView` is globally registered by `app.use(router)`, so importing it would be an unused local and fail the build:

```vue
<template>
  <RouterView />
</template>
```

### 8 — Remove the Vite scaffold page

The counter page is not a screen this product has, and its CSS references markup that is about to stop existing.

**Delete these five files:**

```bash
cd frontend
rm src/components/HelloWorld.vue
rm src/assets/hero.png src/assets/vite.svg src/assets/vue.svg
rm public/icons.svg
```

`public/favicon.svg` **stays** — `index.html:5` links it. Re-run the grep from context item 7 afterwards; it must return no hits outside the deleted files.

**File: `frontend/src/style.css`**

Delete the blocks that styled the deleted markup, keeping the design tokens and base typography that the new views use (`--text`, `--text-h`, `--mono`, `--border`):

| Delete | Lines | Why |
|---|---|---|
| `#social .button-icon` (nested in the dark-mode query) | 48–50 | `#social` was scaffold markup |
| `.hero` | 120–157 | styled the three deleted logo images |
| `#center`, `#next-steps`, `#docs`, `#next-steps ul` | 171–266 | scaffold page layout |
| `#spacer` | 268–274 | scaffold page layout |
| `.ticks` | 276–296 | scaffold page decoration |

**Keep** lines 1–47 and 51–119 (tokens, dark-mode query, `body`, `h1`/`h2`, `.counter`) and the `#app` block at 159–169 — but drop `text-align: center` from `#app` (line 163), which centred the scaffold's hero and is wrong for a data view. Work **bottom-up** so earlier line numbers stay valid.

**File: `frontend/index.html`**

Line 7: `<title>frontend</title>` → `<title>Ticket Management</title>`, matching `APP_NAME` in `backend/.env.example:1`.

### 9 — Document the frontend environment

**File: `README.md`** (repo root)

Two additive edits; change nothing else — the rest is TM-2's deliverable and is accurate.

In the frontend step (lines 58–64), add the env copy, which the dev server needs:

````markdown
### 3. Frontend (`frontend/`)

```bash
cd frontend
npm install
cp .env.example .env   # VITE_API_BASE_URL and the dev-proxy target
npm run dev            # http://localhost:5173
```

The dev server is pinned to **5173** with `strictPort`, because `FRONTEND_URL` and
`SANCTUM_STATEFUL_DOMAINS` in `backend/.env` both name that port. It proxies
`/api` to the Laravel API, so requests are same-origin in development and CORS
cannot be the cause of a failure.
````

In "Environment files" (lines 86–90), add `frontend/.env` to the list of git-ignored files copied from a tracked `.example` sibling.

Do **not** touch `docs/api-contract.md` — TM-3 owns it, and this story adds no endpoint.

---

## Edge Cases & Failure Modes

- **`frontend/.env` never created.** Vite reads `.env`, not `.env.example`, so `import.meta.env.VITE_API_BASE_URL` is `undefined` and the `|| '/api/v1'` fallback in `client.ts` takes over. The app still works through the proxy; the cost is that a *typo* in the variable name also falls back silently. The fallback is the lesser evil — an undefined `baseURL` sends requests to a bare `/health`, which 404s against the Vite dev server with no clue as to why.
- **Port 5173 already bound.** `strictPort: true` makes `npm run dev` **fail** instead of moving to 5174. That is the intent: a silent move breaks `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL` with a CORS error that points nowhere near the cause. Free the port, or change it here *and* in `backend/.env` together.
- **Laravel not running.** The proxy target refuses the connection, Vite returns a 500 for `/api/v1/health`, axios rejects, and the store's `catch` renders `health.error`. It must **not** show a blank panel — the `v-else-if` chain in `HealthView.vue` covers loading, error, and data, in that order.
- **API reachable but degraded (`503`).** `getHealth`'s `validateStatus` accepts it, so the store fills `data` (not `error`) and the view renders `status: degraded` with the database row showing the probe's message — `unavailable` when `APP_DEBUG=false`, the real driver message when it is `true` (`HealthController.php:51`). Confusing these two paths is the most likely bug in this story: **`503` is data, a dropped connection is an error.**
- **Redirect loop on 401.** Two guards, and both are needed. `client.ts` skips the redirect when `error.config?.url === LOGIN_PATH`, so a rejected sign-in shows a form error instead of navigating. `main.ts` skips it when the current route is already `login`, covering a background request that 401s while the user sits there. Removing either reintroduces the loop by a different route.
- **`LOGIN_PATH` drifts from TM-9's actual endpoint.** The constant is `'/auth/login'`; if TM-9 registers the route at a different path, the guard silently stops matching and a failed sign-in redirects to itself. TM-9 must update this constant when it adds the route — it is a one-line coupling, called out here so it is not discovered by a loop in the browser.
- **`localStorage` throws instead of returning `null`.** Safari private mode and storage-disabled browsers throw on access. An uncaught throw inside the *request* interceptor breaks every API call, not just auth — which is why all three functions in `token.ts` are wrapped in `try`/`catch`.
- **Pinia used before it is installed.** `useHealthStore()` is called inside `HealthView.vue`'s `<script setup>`, which runs after `app.use(createPinia())`. Calling it at module scope in `api/` or `router/` throws "no active Pinia". This is the whole reason the token lives in `token.ts` rather than a store.
- **Circular import between the router and the client.** `router → views → stores → api/health → api/client`. Importing `router` into `api/client.ts` closes the cycle and Vite resolves one side to `undefined` at runtime — usually surfacing as "Cannot read properties of undefined (reading 'replace')" on the first 401. The injected `setUnauthorizedHandler` exists to prevent exactly this; do not "simplify" it away.
- **A spec file breaks `npm run build`.** `tsconfig.app.json`'s `include` covers `src/**/*.ts`, so `vue-tsc -b` type-checks the tests. This is deliberate — it keeps the mocks honest — but it means a loose `any` or an unused import in a spec fails the production build. Run `npm run typecheck` after adding tests, not just `npm test`.
- **Deep-linking `/login` in production 404s.** `createWebHistory` needs the web server to serve `index.html` for unknown paths. The Vite dev server does this; nginx does not until configured. Out of scope here — TM-63 (deployment runbook) owns the rewrite rule, and this plan records the requirement so it is not discovered in staging.
- **`CLAUDE.md` documents a test file that has never existed.** It cites `npx vitest run src/components/HelloWorld.spec.ts`; there is no such file, and task 8 deletes the component it would have tested. Correcting `CLAUDE.md` belongs to TM-6 (code quality tooling) — do not edit it from this story, but do not treat that command as a working example either.

---

## Test Plan

`npm test` (`vitest run`) from `frontend/`, jsdom environment, `src/**/*.spec.ts`. Import `describe`/`it`/`expect`/`vi` explicitly from `vitest` — globals are not enabled. There are **no existing frontend tests** (`npx vitest run` currently exits `1` with "No test files found"), so these three files establish the pattern.

1. **Create `frontend/src/api/client.spec.ts`** — unit tests for both interceptors, driven through a stub `client.defaults.adapter` so the real interceptor chain runs without a network. Reset `localStorage` and re-install the stub in `beforeEach`. Five tests:
   - `attaches a bearer token when one is stored` — `setToken('abc123')`, issue a request, assert the captured config's `headers.Authorization` is `Bearer abc123`.
   - `sends no Authorization header when no token is stored` — assert the header is absent, **not** that it equals `Bearer null`.
   - `clears the token and calls the unauthorized handler on 401` — adapter rejects with `new AxiosError(...)` carrying `response.status = 401`; assert the request rejects, `getToken()` is `null`, and a `vi.fn()` handler installed via `setUnauthorizedHandler` was called once.
   - `does not redirect when the 401 came from the login request` — same, with `url: '/auth/login'`; assert the handler was **not** called. (The token is still cleared — assert that too, so the behaviour is pinned rather than accidental.)
   - `leaves the token alone on a non-401 error` — adapter rejects with `500`; assert `getToken()` still returns the token and the handler was not called.

   Import `AxiosError` **as a value** here (`import { AxiosError } from 'axios'`) since it is constructed — the only place in this story where the `type` modifier is wrong. Cast the stubbed response object to `AxiosResponse`; a hand-built literal will not satisfy the interface exactly, and the cast is confined to the test.

2. **Create `frontend/src/stores/health.spec.ts`** — unit tests for the store with `vi.mock('../api/health', () => ({ getHealth: vi.fn() }))`. Use `vi.mock`, **not** `vi.spyOn` on the module namespace: ESM namespace objects are read-only under Vite and the spy silently fails to bind. `setActivePinia(createPinia())` in `beforeEach`. Three tests:
   - `fills data on a healthy response` — resolve a `status: 'ok'` payload; assert `data` is set, `error` is `null`, `loading` is `false`.
   - `treats a degraded payload as data, not an error` — resolve `status: 'degraded'` with `checks.database.ok === false`; assert `error` stays `null` and `data.status` is `'degraded'`. **This is the assertion that keeps the 503 path from being mistaken for a failure.**
   - `sets error when the request never completes` — reject with `new Error('Network Error')`; assert `error` is `'Network Error'`, `data` is `null`, `loading` is `false`.

3. **Create `frontend/src/views/HealthView.spec.ts`** — component test with `mount` from `@vue/test-utils`, the same `vi.mock('../api/health', …)`, and a fresh Pinia. Three tests:
   - `renders the status, version and database check` — resolve an `ok` payload, `await flushPromises()`, assert `[data-testid="health-status"]` reads `ok`, `[data-testid="health-database"]` reads `reachable`, and the app version appears in the output.
   - `renders the probe message when the database check failed` — resolve a `degraded` payload with `checks.database = { ok: false, error: 'unavailable' }`; assert `[data-testid="health-database"]` reads `unavailable` and `[data-testid="health-error"]` does **not** exist.
   - `renders the error message when the API is unreachable` — reject; assert `[data-testid="health-error"]` exists and `[data-testid="health-result"]` does not.

4. **No test for `router/index.ts` or `main.ts`.** The router is three route records with no logic worth pinning, and `main.ts` is bootstrap wiring that the manual verification steps below cover end-to-end. Adding a test that asserts `routes.length === 3` pins the plan, not the behaviour.

5. **Regression — nothing to preserve.** `frontend/` has no existing tests to keep passing. `backend/`'s suite is untouched by this story; `composer test` must still report the counts TM-3 established.

Expected total: **11 passing tests** across three files.

---

## Verification Steps

Run in this order. Working directory is stated for every command.

1. **Backend is genuinely healthy first:** repo root — `docker compose ps` shows all three containers `healthy`; then `curl -s http://localhost:8000/api/v1/health` returns a body with `"status":"ok"` **and an `api` field**. A `503` or a missing `api` field means TM-3 is not finished — **stop**, because acceptance criterion 4 cannot be met and the `HealthResponse` type would be wrong.
2. **Install and configure:** `frontend/` — `npm install` (no lockfile change expected: this story adds no dependency, so `git diff --stat package-lock.json` must be empty) and `cp .env.example .env`.
3. **Typecheck:** `frontend/` — `npm run typecheck` exits `0`. It passes on the untouched scaffold, so any error here is from this story's code. This also type-checks the spec files.
4. **Frontend tests:** `frontend/` — `npm test` exits `0` and reports **11 passing** across `client.spec.ts`, `health.spec.ts` and `HealthView.spec.ts`. "No test files found" means the `include` glob in `vite.config.ts` does not match, or the `test` block is missing because the `/// <reference types="vitest/config" />` line was dropped.
5. **Production build:** `frontend/` — `npm run build` completes and writes `dist/`. Confirm no warning references `hero.png`, `vite.svg`, `vue.svg` or `icons.svg`; a warning naming any of them means task 8 deleted a file something still imports.
6. **Frontend runs — the health path:** `frontend/` — `npm run dev`, then open http://localhost:5173/. The page renders `Status: ok`, the app name and version, `API: v1`, and `Database: reachable`. In the browser's network tab the request is to **`http://localhost:5173/api/v1/health`** — same-origin, with **no OPTIONS preflight**. A request to `:8000` means the base URL is absolute and the proxy is being bypassed.
7. **Proxy is what is doing the work:** with the dev server running, from any shell — `curl -s http://localhost:5173/api/v1/health` returns the same JSON as step 1. This hits Vite, not Laravel, and proves the proxy independently of the browser.
8. **Port is pinned:** `frontend/` — with the dev server already running on 5173, start a second `npm run dev`. It must **fail** with a port-in-use error rather than starting on 5174. Stop the second attempt.
9. **Degraded path renders as data:** repo root — `docker compose stop mysql`, reload http://localhost:5173/. The page shows `Status: degraded` and a database row reading `unavailable` (or the driver message if `APP_DEBUG=true`) — **not** the generic "API is unreachable" error. Restore with `docker compose start mysql` and reload to confirm it returns to `ok`.
10. **Unreachable path renders as an error:** stop `php artisan serve`, reload. The page shows the `health-error` message. Restart the API.
11. **Bearer token is attached:** in the browser console run `localStorage.setItem('tm.token', 'probe-token')`, reload, and confirm the `/api/v1/health` request carries `Authorization: Bearer probe-token` in the network tab. Clear it with `localStorage.removeItem('tm.token')`. (The endpoint is unauthenticated, so the header is ignored — this verifies the interceptor, not the API.)
12. **401 redirects to login:** in the browser console, with the dev server running:

    ```js
    const { default: client } = await import('/src/api/client.ts')
    localStorage.setItem('tm.token', 'probe-token')
    client.get('/user').catch(() => {})
    ```

    Laravel has no `/api/v1/user` route yet, so this returns **404**, not 401 — the URL must be one that actually 401s. Until TM-9 adds an authenticated route, verify this step through the unit tests in `client.spec.ts` instead and record that in the completion note. Do **not** add a backend route to make this manual step work.
13. **Env files are tracked correctly:** repo root — `git check-ignore -q frontend/.env` exits `0`, and `git check-ignore -q frontend/.env.example` exits `1`.
14. **Regression — backend untouched:** repo root — `git status --short` lists no path under `backend/`. From `backend/`, `composer test` still exits `0` with the counts TM-3 established.

---

## Done Criteria

- [ ] `frontend/.env.example` is tracked and documents `VITE_API_BASE_URL` and `VITE_API_PROXY_TARGET`; `frontend/.env` exists locally and is git-ignored; `frontend/src/env.d.ts` types `VITE_API_BASE_URL` (and deliberately not the proxy target).
- [ ] `vite.config.ts` pins port **5173** with `strictPort`, proxies `/api` to `VITE_API_PROXY_TARGET` (default `http://localhost:8000`), and configures Vitest with `environment: 'jsdom'` — in **one** config file, with no `vitest.config.ts` added.
- [ ] `package.json` has `test`, `test:watch` and `typecheck` scripts, and **no dependency was added or removed** (`git diff --stat package-lock.json` is empty).
- [ ] `src/api/client.ts` exports a configured axios instance whose `baseURL` comes from `import.meta.env.VITE_API_BASE_URL`; a request interceptor attaches `Authorization: Bearer <token>`; a response interceptor clears the token and invokes an **injected** handler on 401, skipping `LOGIN_PATH`.
- [ ] `src/api/token.ts` wraps every `localStorage` access in `try`/`catch` and is **not** a Pinia store, with the module-scope reason recorded in a comment.
- [ ] `src/api/health.ts` exports `HealthResponse` matching `docs/api-contract.md` field-for-field (including `api` and optional `checks.*.error`) and `getHealth()` accepting **both 200 and 503** via `validateStatus`.
- [ ] `main.ts` installs Pinia **and** the router and wires `setUnauthorizedHandler` to `router.replace({ name: 'login' })` with an already-on-login guard; `App.vue` is `<RouterView />` with no `<script>` block.
- [ ] Routes `/` → `HealthView`, `/login` → `LoginView`, and a catch-all redirect exist; `useHealthStore` drives the view and distinguishes a **degraded payload** from an **unreachable API**.
- [ ] `HelloWorld.vue`, `src/assets/hero.png`, `src/assets/vite.svg`, `src/assets/vue.svg` and `public/icons.svg` are deleted; `public/favicon.svg` remains; `style.css` has no selector referencing deleted markup; `index.html`'s title is `Ticket Management`.
- [ ] `npm run typecheck`, `npm test` (**11 passing**) and `npm run build` all exit `0` from `frontend/`.
- [ ] http://localhost:5173/ renders status, app version, `API: v1` and the database check, with the request going to **`:5173/api/v1/health`** and **no preflight**; stopping the `mysql` container flips it to `degraded` rather than to the error state.
- [ ] Root `README.md` documents `cp .env.example .env`, the pinned port and the proxy, and lists `frontend/.env` under Environment files. `docs/api-contract.md` and everything under `backend/` are unmodified.
- [ ] Overview `00-overview.md` updated with this story.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 04 (TM-5).**
