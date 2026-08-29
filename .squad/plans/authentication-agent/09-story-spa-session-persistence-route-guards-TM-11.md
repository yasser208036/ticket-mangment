# Story 09 — SPA session persistence and route guards (Story: TM-11)

## Prerequisites

- **Story 03 (TM-4) is implemented.** Verified live in the working tree, not assumed: `src/main.ts` wires Pinia, the router and `setUnauthorizedHandler`; `src/api/{client,token,health}.ts`, `src/router/index.ts`, `src/stores/health.ts` and `src/views/{HealthView,LoginView}.vue` all exist; `npm run test` passes — **3 files, 11 tests** — and `npm run typecheck` (`vue-tsc -b`) exits `0`. That is this story's regression baseline.
- **Stories 07 (TM-9) and 08 (TM-10) must be _implemented_, not merely planned:** [`07-story-log-in-and-receive-an-api-token-TM-9.md`](07-story-log-in-and-receive-an-api-token-TM-9.md) and [`08-story-log-out-and-revoke-the-token-TM-10.md`](08-story-log-out-and-revoke-the-token-TM-10.md). Hard blockers — this story is a client for three endpoints that do not exist yet. Confirmed not done at planning time: `backend/routes/api.php` still has exactly one route (`GET /health`) and `backend/app/Models/User.php:18` is still `use HasFactory, Notifiable;`.

  Confirm before starting, from `backend/`:

  ```bash
  php artisan route:list --path=auth --columns=method,uri,name,middleware
  ```

  Three rows: `POST api/v1/auth/login` (`throttle:login`), `POST api/v1/auth/logout` and `GET api/v1/auth/me` (both `auth:sanctum`, `active`). **If `auth.me` is missing, stop** — acceptance criterion 1 is unimplementable without it.
- **The API must be reachable while developing.** Repo root — `docker compose up -d`; `backend/` — `php artisan serve`; `frontend/` — `npm run dev`. `vite.config.ts:14-19` proxies `/api` to `VITE_API_PROXY_TARGET` (`http://localhost:8000`), so the SPA calls same-origin paths and CORS never enters the picture in development.
- **No new dependency.** Verified against the installed tree: `pinia@4.0.3`, `vue-router@4.6.4`, `vue@3.5.41`, `axios@1.19.0`, `vitest@4.1.11`, `@vue/test-utils@2.4.11`, `jsdom@29.1.1`, `typescript@6.0.3`. **`@pinia/testing` and `pinia-plugin-persistedstate` are NOT installed** — `ls node_modules/@pinia/testing` fails — so persistence is hand-rolled on the existing `src/api/token.ts` and tests use a real Pinia the way `src/stores/health.spec.ts:11` already does. **`frontend/package.json` and `package-lock.json` must be unchanged by this story.**
- **Story 05 (TM-6) has landed, so there are four static gates, not one.** Verified live: `frontend/eslint.config.js` and `frontend/.prettierrc.json` (`{"semi": false, "singleQuote": true}`, default 80-column width) exist, and `package.json` has `lint`, `lint:fix`, `format` and `format:check`. **Write every file in this story Prettier-formatted** — no semicolons, single quotes — and finish with `npm run format && npm run lint && npm run typecheck && npm run test`. Do not add or edit a lint or format config; TM-6 owns both.

---

## Story Goal

TM-9 issues a token and TM-10 revokes it. This story is the client half: the SPA remembers who you are across a reload, refuses to render a screen you are not entitled to, and never leaves you looking at a page whose data will not load.

Audit of the five acceptance criteria against the code as it stands:

| # | Criterion | Verdict |
|---|---|---|
| 1 | A Pinia auth store holds the token and user and rehydrates on page reload | ❌ **Not met.** `src/stores/` contains only `health.ts`. The token half is nearly free — `src/api/token.ts` already reads and writes `localStorage` under `tm.token`. The user half is the hard part, and it is a **race**, not a lookup: see "The rehydration race" below. |
| 2 | Router guard redirects unauthenticated users to `/login` and preserves the intended destination | ❌ **Not met.** `src/router/index.ts` is 14 lines with three route records and **no `beforeEach` at all**. It also has no route that requires authentication, so this story has to create the concept before it can guard it. |
| 3 | Admin-only routes are blocked for agents with a clear not-authorised screen | ❌ **Not met.** No route carries a role, no `meta` key exists, and there is no not-authorised view. |
| 4 | A `401` from any request clears the store and redirects to login exactly once, without a redirect loop | ⚠️ **Half met, and the half that exists is the wrong half.** `src/api/client.ts:25-34` clears the token and calls a handler on `401`, and `main.ts:8-12` navigates to `login` when the current route is not already `login`. But there is no store to clear, and the loop guard is wrong in two ways: it compares against `router.currentRoute`, which does not change until a navigation *resolves*, so two parallel `401`s both navigate; and a `401` from `GET /auth/me` during rehydration fires it while a guard is mid-flight. |
| 5 | Logging out clears the persisted token from storage | ❌ **Not met.** `clearToken()` exists (`src/api/token.ts:19-25`) and nothing calls it except the `401` interceptor. There is no logout affordance anywhere — `src/App.vue` is three lines, `<RouterView />` and nothing else. |

Seven outcomes:

1. `src/stores/auth.ts` holds `token` and `user`, exposes `isAuthenticated` and `isAdmin`, and **never navigates** — routing decisions live in the guard.
2. A reload on any protected URL re-establishes the session from the stored token via **one** `GET /auth/me`, and the guard waits for it rather than rendering and then bouncing.
3. Routes are **protected by default**: a record is public only if it says `meta: { public: true }`. Forgetting the flag on a future route makes it private, not open.
4. An unauthenticated visit to `/admin/users` lands on `/login?redirect=/admin/users`, and signing in continues to `/admin/users`.
5. An agent visiting `/admin/users` gets a real not-authorised screen at `/forbidden`, not a blank page and not a silent no-op.
6. Two concurrent `401`s produce **one** redirect, and `/login` never redirects to itself.
7. Signing out calls `POST /auth/logout`, clears the store and removes `tm.token` from `localStorage` — even when the request itself fails.

**Not in scope:** the real dashboard that replaces `/`'s component (**TM-29**); the actual admin users screen behind `/admin/users`, which this story stubs the way TM-4 stubbed `LoginView.vue` (**TM-12**); server-side authorization, which is the only authorization that counts (**TM-13**); the password-change form (**TM-14**); a design system, an app navigation menu, or any view beyond the two this story needs; cross-tab session sync (see Edge Cases — **no story owns it**); an ESLint config (**TM-6**); and **any change under `backend/`**. Do not add a dependency — if a task seems to need one, re-read it.

---

## Product rules

### The rehydration race — why the guard has to wait

On reload, `localStorage` has the token and nothing else. The user object — and therefore the role — only exists on the server. So at the moment the router runs its first navigation, the SPA knows it *has* a session but not *whose*.

Write the guard the obvious way and every reload throws an administrator out:

```ts
router.beforeEach((to) => {
  const auth = useAuthStore()
  if (!auth.user) return { name: 'login' }   // <-- true on every reload
  ...
})
```

So the guard must `await` a hydration step, and hydration must be **memoised**, because the guard does not run once per user action. Measured during planning with an async `beforeEach` and `router.push('/')` that redirects to `/login`:

```
guardCalls 2  resolveCalls 2  landed on /login?redirect=/
```

**Returning a redirect from a guard restarts navigation and runs the guard again.** An un-memoised `GET /auth/me` inside it is therefore two requests for one page load, and on a slow link the second can resolve first. The store owns a one-shot promise:

```ts
const pending = hydration ?? (hydration = run().finally(() => { hydration = null }))
await pending
```

The promise lives as a plain `let` **inside** the `defineStore` setup function, not at module scope. Measured: a `let` declared inside a setup store is per-Pinia-instance, so `setActivePinia(createPinia())` in a spec's `beforeEach` resets it —

```
after fresh pinia, first bump returns 1
```

— whereas a module-scoped `let` would leak between tests and make the "only one request" assertion pass or fail depending on file order.

**Hydration must never reject.** The guard awaits it; an unhandled rejection inside `beforeEach` turns into a failed navigation routed through `router.onError`, which this project does not configure. It resolves, and the guard reads `isAuthenticated` afterwards.

### The token is persisted; the user is not

`localStorage` holds **only** `tm.token`. The user object is re-fetched on every page load.

A cached user goes stale in exactly the ways that matter: an admin demoted to agent keeps an admin UI until they clear their browser, and a deactivated agent keeps a rendered app while every request `401`s — TM-10's `EnsureUserIsActive` middleware makes the server the authority on both, and `GET /auth/me` exists precisely so a client holding a token can ask who it is. The stated cost: **one extra request per page load**, and the first paint waits on it. That is the trade this story is making, and "The blank first paint" below is what it costs.

`is_active` is carried on the user type because the endpoint returns it. **Nothing in the SPA may branch on it** — a client-side check on a field the client received from the server is decoration. TM-13 owns authorization.

### Protected by default

The guard treats every route as private unless the record opts out:

```ts
if (to.meta.public) { ... }        // whitelist
```

not

```ts
if (to.meta.requiresAuth) { ... }  // blacklist
```

This is an internal tool: there is no public surface beyond the sign-in form. With a blacklist, TM-12's user admin, TM-22's ticket form and TM-26's ticket detail are each one forgotten `meta` key away from being world-readable, and nothing fails — the screen just works for everyone. With a whitelist the same omission makes a route *private*, which is a bug someone notices in a second. The public list after this story is exactly one record: `/login`.

`RouteMeta` is augmented so the keys are typed. Measured, so nobody over-claims what that buys:

```
meta: { role: 'superuser' }  ->  error TS2322: Type '"superuser"' is not assignable to type '"admin" | "agent" | undefined'
meta: { publik: true }       ->  compiles cleanly
```

The augmentation catches a **wrong value** and types the read site (`to.meta.public` becomes `boolean | undefined` instead of `unknown`); it does **not** catch a **misspelled key**, because `interface RouteMeta extends Record<PropertyKey, unknown>` upstream. Do not write a comment claiming otherwise, and do not try to remove the index signature.

### One redirect, and the three endpoints that must not trigger it

Acceptance criterion 4 is two requirements wearing one sentence: *clear the store* and *redirect exactly once*. Two mechanisms, and the second has two failure modes.

**Concurrency.** `main.ts:9`'s `router.currentRoute.value.name !== 'login'` looks like a guard against a double redirect but is not: `currentRoute` updates when a navigation **resolves**, so two `401`s arriving in the same tick both see the old route and both navigate. The handler needs its own latch, reset when the navigation settles. Note that vue-router already refuses an identical navigation — measured, a duplicated `replace` resolves with a `NavigationFailure` of type `16` (`duplicated`) and does **not** throw — but that is a silent backstop, not the requirement. The test asserts `router.replace` was called **once**.

**The session endpoints.** `POST /auth/login`, `GET /auth/me` and `POST /auth/logout` all answer `401` as part of normal operation:

- `/auth/login` — already exempt (`client.ts:28`), because a `401` there is a failed sign-in, not a dead session. (TM-9 answers a bad password with `422`; a `401` here would be a server misconfiguration.)
- `/auth/me` — a `401` means the stored token is dead. It arrives **while the guard is awaiting hydration**. Letting the interceptor navigate at that moment cancels the in-flight navigation and races the guard's own redirect: two navigations for one page load, and the `?redirect=` is lost. The store handles it by clearing itself, and the guard — which is already deciding where to go — does the redirecting.
- `/auth/logout` — a `401` means the token was already revoked (TM-10 returns `401` for a replayed token, by design). `logout()` clears in a `finally` and the component navigates, so an interceptor redirect on top is a second navigation for an outcome that is already correct.

So `client.ts`'s single `LOGIN_PATH` becomes a list. The rule to write in the comment: **the interceptor rescues a request that did not expect a dead session; these three expect it and handle their own.**

### The store never navigates, the guard never fetches

One direction of dependency, so there is no cycle and no surprise:

- `src/stores/auth.ts` imports `src/api/auth.ts`. It mutates state and returns. It does **not** import the router.
- `src/router/guards.ts` imports the store. It reads state and returns a route. It does **not** call the API directly — it calls `auth.hydrate()`.
- Components call store actions and then navigate themselves.

The one navigation that does not come from a component is the `401` handler, and it lives in `guards.ts` with the guard, taking the router as an argument rather than importing it — which is also what makes it unit-testable without the app.

---

## Context — Read These Files First

1. `frontend/src/router/index.ts` — all 14 lines. Three records: `/` → `HealthView` named `home` (line 8), `/login` → `LoginView` named `login` (9), and a catch-all redirecting to `home` (10). **No `beforeEach`, no `meta` anywhere.** Task 4 rewrites this file; keep the named-route style, because every test and every redirect in this story addresses routes by name.
2. `frontend/src/main.ts` — all 14 lines. **Line 14 is `createApp(App).use(createPinia()).use(router).mount('#app')` — Pinia is installed before the router, and it must stay that way**: `app.use(router)` triggers the first navigation, which runs the guard, which calls `useAuthStore()`. Swap the two and you get *"getActivePinia() was called but there was no active Pinia"* on first paint. **Lines 6 and 8–12** are the ad-hoc unauthorized handler task 6 deletes.
3. `frontend/src/api/client.ts` — all 36 lines. **Line 5** is `const LOGIN_PATH = '/auth/login'`; **lines 6–10** are the swappable `onUnauthorized` seam; **18–23** attach `Authorization: Bearer …` from `getToken()` on every request, which is why the store never sets a header itself; **25–34** are the `401` interceptor task 5 edits. Note `baseURL` (13) is `/api/v1`, so every path in `src/api/` is written **without** the prefix, and `error.config?.url` is the relative path as passed — `src/api/client.spec.ts:48` already relies on that.
4. `frontend/src/api/token.ts` — all 25 lines. `STORAGE_KEY = 'tm.token'` (1), and all three functions wrap `localStorage` in `try/catch` because storage can be disabled. **This is the whole persistence layer** — do not add a second storage key, and do not persist the user (see Product rules). `clearToken()` on an absent key is a no-op, which is what makes the double-clear in task 5 harmless.
5. `frontend/src/api/health.ts` — all 23 lines. The API-module idiom to match in task 1: exported `interface`s for the wire shape, one `async` function per endpoint, destructure `const { data } = await client.get<T>(...)`, return `data`. Note `validateStatus` on line 20 — a per-call override, which task 1 does **not** need.
6. `frontend/src/stores/health.ts` — all 25 lines. The store idiom to match in task 2: `defineStore('name', () => { … })` setup syntax, `ref()` for state, an `async function` action, `try/catch/finally`, and an explicit object return. **Task 2's hydration promise must be a `let` inside this setup callback**, not a `ref` and not module scope — see Product rules.
7. `frontend/src/views/HealthView.vue` — all 44 lines. The view idiom: `<script setup lang="ts">`, the store in a `const`, `data-testid` attributes on every element a test asserts on (15, 16, 17, 19, 27), and a `<style scoped>` block using the `--text` / `--border` / `--mono` custom properties from `src/style.css`. Tasks 7, 8 and 9 follow this shape.
8. `frontend/src/views/LoginView.vue` — all 10 lines, a placeholder whose body is *"Authentication is not implemented yet."* Task 7 replaces it. **This file is the precedent for task 8's `AdminUsersView.vue`**: TM-4 shipped a real route with a stub component and a sentence naming the story that fills it in. Do the same rather than inventing an admin screen.
9. `frontend/src/App.vue` — all 3 lines, `<RouterView />`. Task 9 adds the minimal authenticated header that makes signing out reachable.
10. `frontend/src/stores/health.spec.ts` (30 lines) and `frontend/src/views/HealthView.spec.ts` (42 lines) — the **local test precedent**. Read both before writing a spec:
    - `setActivePinia(createPinia())` in `beforeEach` (`health.spec.ts:11`) — a real Pinia, because `@pinia/testing` is not installed.
    - `vi.mock('../api/health', () => ({ getHealth: vi.fn() }))` at module scope, then `vi.mocked(fn).mockResolvedValue(...)` per test. The specs in the Test Plan mock `../api/auth` the same way.
    - `mount(Component, { global: { plugins: [createPinia()] } })` plus `await flushPromises()` (`HealthView.spec.ts:11,18`).
    - The formatting is **Prettier's**, not hand-rolled: TM-6 landed `.prettierrc.json` (`{"semi": false, "singleQuote": true}`) and reformatted these files, so they are now one statement per line, no semicolons, single quotes, 80 columns. Write new specs that way and `npm run format` will be a no-op; write them any other way and it will rewrite your diff.
11. `frontend/src/api/client.spec.ts` — all 58 lines. The adapter-swap technique (`7`, `9-14`) is how this project tests axios behaviour without a network: `client.defaults.adapter = failingAdapter(401)`. Test Plan 6 adds two cases to the existing `describe`; the test at **45–50** (`does not call handler for login 401`) is the pattern to copy for `/auth/me` and `/auth/logout`.
12. `frontend/vite.config.ts` — all 26 lines. **Lines 21–24** are the Vitest config: `environment: 'jsdom'` and `include: ['src/**/*.spec.ts']`. New specs must live under `src/` and end in `.spec.ts` or they are silently not run. **This file needs no change.**
13. `frontend/tsconfig.app.json` — all 15 lines. `noUnusedLocals` and `noUnusedParameters` are **on** (9–10), so an unused import in a spec fails `npm run typecheck`; `erasableSyntaxOnly` is on (11), so no `enum` and no parameter properties. `include` is `src/**/*.{ts,tsx,vue}` (14), which is why the `declare module 'vue-router'` augmentation in task 4 is picked up without any config change.
14. `docs/api-contract.md` — the `POST /api/v1/auth/login` section (added by TM-9) and the `POST /api/v1/auth/logout` / `GET /api/v1/auth/me` sections (added by TM-10). **These are the source of truth for task 1's types.** Copy the field lists exactly: `token`, `token_type`, `user.{id,name,email,role,is_active,created_at}`, and note that `/auth/me` returns `{ "user": … }` — one top-level `user` key, not `data`.
15. `backend/app/Http/Resources/V1/UserResource.php` (TM-9) — confirm the field list matches task 1's `AuthUser` interface field for field. A mismatch here is the kind of bug that only shows up as `undefined` in a template.
16. `frontend/.env.example` and `frontend/.env` — both 5 lines, `VITE_API_BASE_URL=/api/v1` and `VITE_API_PROXY_TARGET=http://localhost:8000`. **Neither needs a new variable.** `src/env.d.ts` declares only `VITE_API_BASE_URL`; leave it alone.
17. Grep, after you are done: `grep -rn "localStorage" frontend/src/` must return hits **only** in `src/api/token.ts` and in specs. A component or store touching `localStorage` directly has bypassed the one module that handles storage being unavailable.

---

## Frontend Tasks

**No backend changes.** This story consumes TM-9's and TM-10's endpoints and adds nothing to them. `git status --short backend/ docs/` must be empty of new changes when this story is done.

Write the files in the order below: types, store, routing, views, wiring, tests.

### 1 — The auth API module

**Create file: `frontend/src/api/auth.ts`**

Types come from `docs/api-contract.md`, not from guesswork. `client`'s `baseURL` is `/api/v1`, so paths here omit the prefix.

```ts
import axios from 'axios'
import client from './client'

export type UserRole = 'admin' | 'agent'

/** The `user` object of both POST /auth/login and GET /auth/me. */
export interface AuthUser {
  id: number
  name: string
  email: string
  role: UserRole
  // Present because the API sends it. Never branch on it — the server decides
  // what a deactivated account may do (TM-10's middleware, TM-13's policies).
  is_active: boolean
  created_at: string
}

export interface LoginResponse {
  token: string
  token_type: string
  user: AuthUser
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const { data } = await client.post<LoginResponse>('/auth/login', { email, password })
  return data
}

/** GET /auth/me returns `{ user }`, matching the login response's shape. */
export async function me(): Promise<AuthUser> {
  const { data } = await client.get<{ user: AuthUser }>('/auth/me')
  return data.user
}

export async function logout(): Promise<void> {
  await client.post('/auth/logout')
}

/**
 * A 401 means the token is dead. Anything else — a timeout, a 502, the API not
 * running — means we cannot tell, and the difference decides whether the stored
 * token is thrown away or kept for the next attempt.
 */
export function isUnauthorized(error: unknown): boolean {
  return axios.isAxiosError(error) && error.response?.status === 401
}
```

- **`token_type` is typed but unused.** `client.ts:21` already writes the `Bearer ` prefix itself. Keeping the field documents the wire format; do not build the header from it in two places.
- **`me()` unwraps `data.user`** so no caller has to know about the envelope. Every consumer deals in `AuthUser`.
- **No `AxiosError` import.** `axios.isAxiosError` is a runtime type guard (verified present in `axios@1.19.0`) and needs no type import.
- **No `device_name`, no refresh-token call, no `/auth/register`.** None of those endpoints exist.

### 2 — The auth store

**Create file: `frontend/src/stores/auth.ts`**

Read `src/stores/health.ts` first; this matches its shape. The two subtle parts are the hydration promise's placement and the fact that `clear()` is the single definition of "no session".

```ts
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { isUnauthorized, login as apiLogin, logout as apiLogout, me } from '../api/auth'
import type { AuthUser } from '../api/auth'
import { clearToken, getToken, setToken } from '../api/token'

export const useAuthStore = defineStore('auth', () => {
  // The token survives a reload; the user does not, and is re-fetched instead of
  // being cached, so a role change or a deactivation cannot be out of date.
  const token = ref<string | null>(getToken())
  const user = ref<AuthUser | null>(null)

  // Deliberately a plain `let` inside the setup callback rather than a ref or a
  // module-level variable: it is not state anyone renders, and this scope is
  // per-Pinia-instance, so setActivePinia(createPinia()) resets it in tests.
  let hydration: Promise<void> | null = null

  // A token alone is not a session — after a reload we have one but do not yet
  // know who it belongs to, and a guard must not let that through.
  const isAuthenticated = computed(() => user.value !== null)
  const isAdmin = computed(() => user.value?.role === 'admin')

  async function login(email: string, password: string): Promise<void> {
    const response = await apiLogin(email, password)

    setToken(response.token)
    token.value = response.token
    user.value = response.user
  }

  /**
   * Resolve `user` from a stored token. Called by the router guard on every
   * navigation and memoised, because returning a redirect from a guard re-runs
   * the guard — one page load can mean two calls.
   *
   * Never rejects: the guard awaits it and reads isAuthenticated afterwards.
   */
  async function hydrate(): Promise<void> {
    if (token.value === null || user.value !== null) return

    const pending =
      hydration ??
      (hydration = (async () => {
        try {
          user.value = await me()
        } catch (error) {
          // 401: the token is dead, throw it away. Anything else: we cannot
          // tell, so keep it — a network blip must not force a new password.
          if (isUnauthorized(error)) clear()
        }
      })().finally(() => {
        hydration = null
      }))

    await pending
  }

  async function logout(): Promise<void> {
    try {
      // A 401 here means the token was already revoked, which is the outcome we
      // wanted anyway — hence finally rather than a success branch.
      await apiLogout()
    } finally {
      clear()
    }
  }

  /** The single definition of "no session": memory and storage together. */
  function clear(): void {
    token.value = null
    user.value = null
    hydration = null
    clearToken()
  }

  return { token, user, isAuthenticated, isAdmin, login, hydrate, logout, clear }
})
```

Five details that are not stylistic:

- **`isAuthenticated` is `user !== null`, not `token !== null`.** A stored token whose `/auth/me` failed with a timeout leaves `token` set and `user` null; the guard must send that person to the sign-in form rather than render a screen with no user. Keeping the token means their next attempt does not need the password if the API has recovered.
- **`hydrate()` returns early on both settled outcomes** (`user` set on success, `token` nulled on `401`), so the memo only ever matters for the in-flight case — which is why nulling it in `finally` is safe and still allows a retry after a network failure.
- **`clear()` resets `hydration`.** Without it, signing in again after a `401` would reuse a resolved promise and skip the fetch.
- **`logout()` clears in `finally`, not after `await`.** Acceptance criterion 5 says logging out clears storage; a failed request must not leave the token behind.
- **The store does not import the router and does not navigate.** See Product rules.

### 3 — The route guard, the redirect sanitiser and the 401 handler

**Create file: `frontend/src/router/guards.ts`**

All three live together because all three answer "where does the user go when the session is not what the URL assumes", and all three take the router as an argument rather than importing it, which is what makes them testable without mounting the app.

```ts
import { setUnauthorizedHandler } from '../api/client'
import { useAuthStore } from '../stores/auth'
import type { RouteLocationNormalized, RouteLocationRaw, Router } from 'vue-router'

/**
 * Reduce a `?redirect=` value to something safe to navigate to.
 *
 * vue-router does not sanitise this. Measured against vue-router 4.6.4:
 * resolve('https://evil.com') gives '/https://evil.com' — harmless, the scheme
 * is neutralised — but resolve('//evil.com') gives '//evil.com' verbatim, and a
 * browser reads a leading '//' as protocol-relative and leaves the site. Some
 * browsers treat a leading '/\' the same way.
 */
export function safeRedirect(raw: unknown): string {
  if (typeof raw !== 'string') return '/'
  if (!raw.startsWith('/') || raw.startsWith('//') || raw.startsWith('/\\')) return '/'
  return raw
}

/** `/login?redirect=/` is noise, so the query is omitted for the root. */
function loginRoute(fullPath: string): RouteLocationRaw {
  return { name: 'login', query: fullPath === '/' ? undefined : { redirect: fullPath } }
}

export async function authGuard(to: RouteLocationNormalized): Promise<true | RouteLocationRaw> {
  const auth = useAuthStore()

  // No-op unless a stored token has no user yet, i.e. the first navigation
  // after a reload. Memoised in the store — see hydrate().
  await auth.hydrate()

  if (to.meta.public) {
    // Nobody signed in has business on the sign-in form; leaving them there is
    // how a "log in again" loop starts after a successful login.
    return to.name === 'login' && auth.isAuthenticated ? { name: 'home' } : true
  }

  if (!auth.isAuthenticated) return loginRoute(to.fullPath)

  // Redirect to a real screen rather than returning false: `false` aborts the
  // navigation and leaves the user on whatever they were looking at, which for
  // a fresh page load is nothing at all.
  if (to.meta.role === 'admin' && !auth.isAdmin) return { name: 'forbidden' }

  return true
}

/**
 * What to do when any other request comes back 401: clear the session and send
 * the user to sign in — once, however many requests failed.
 *
 * The latch is not paranoia: router.currentRoute only changes when a navigation
 * RESOLVES, so two 401s in one tick both see the old route.
 */
export function createUnauthorizedHandler(router: Router): () => void {
  let redirecting = false

  return () => {
    useAuthStore().clear()

    const current = router.currentRoute.value
    if (redirecting || current.name === 'login') return

    redirecting = true
    void router.replace(loginRoute(current.fullPath)).finally(() => {
      redirecting = false
    })
  }
}

export function installAuthGuards(router: Router): void {
  router.beforeEach(authGuard)
  setUnauthorizedHandler(createUnauthorizedHandler(router))
}
```

- **`useAuthStore()` is called inside the function bodies, never at module scope.** At module scope it runs before `app.use(createPinia())` and throws *"getActivePinia() was called but there was no active Pinia"*.
- **`authGuard` returns `true | RouteLocationRaw`** rather than taking `next`. The return-value form is what makes it directly callable from a spec with a hand-built `to`.
- **`createUnauthorizedHandler` is a factory.** Each call closes over its own `redirecting`, so specs get isolation for free and there is no module-level state to reset.
- **`.finally()` on the `replace`, not `.then()`** — the latch must lift even when the navigation is aborted or duplicated. A duplicated `replace` resolves with a `NavigationFailure` (measured: type `16`) rather than rejecting, so nothing here needs a `catch`.

### 4 — The route table

**File: `frontend/src/router/index.ts`** — rewrite.

```ts
import { createRouter, createWebHistory } from 'vue-router'
import type { RouterHistory } from 'vue-router'
import AdminUsersView from '../views/AdminUsersView.vue'
import ForbiddenView from '../views/ForbiddenView.vue'
import HealthView from '../views/HealthView.vue'
import LoginView from '../views/LoginView.vue'
import { installAuthGuards } from './guards'
import type { UserRole } from '../api/auth'

// Typed reads at the guard's call sites, and a wrong VALUE is a compile error:
// `meta: { role: 'superuser' }` fails with TS2322. A misspelled KEY is NOT
// caught — RouteMeta extends Record<PropertyKey, unknown> upstream — so treat
// this as documentation plus value checking, not as a typo guard.
declare module 'vue-router' {
  interface RouteMeta {
    /** Reachable without a session. Absent means private — see guards.ts. */
    public?: boolean
    /** Minimum role. Only 'admin' is meaningful today. */
    role?: UserRole
  }
}

export function createAppRouter(history: RouterHistory = createWebHistory()) {
  const router = createRouter({
    history,
    routes: [
      // Private by default. TM-29 replaces this component with the dashboard;
      // the route stays.
      { path: '/', name: 'home', component: HealthView },
      { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
      // Reachable by anyone signed in — an agent has to be able to see it.
      { path: '/forbidden', name: 'forbidden', component: ForbiddenView },
      // TM-12 replaces the component. The meta is this story's deliverable.
      { path: '/admin/users', name: 'admin-users', component: AdminUsersView, meta: { role: 'admin' } },
      { path: '/:pathMatch(.*)*', redirect: { name: 'home' } },
    ],
  })

  installAuthGuards(router)

  return router
}

export const router = createAppRouter()

export default router
```

- **`createAppRouter(history?)` is new and exists for the tests.** The old module-level singleton is still the default export, so `main.ts` is unchanged in this respect — but a spec that imported the singleton would share accumulated history and one globally-installed `401` handler with every other spec in the run. The factory gives each spec a fresh router over `createMemoryHistory()`.
- **`/` keeps `HealthView` and becomes private.** Nothing in this product is public except signing in. TM-4's `HealthView.spec.ts` mounts the component directly and is unaffected by the route's meta.
- **`/forbidden` carries no `role`**, so the guard lets any signed-in user through and there is no loop when it redirects there. Verified reasoning, not assumption: a guard that returns a redirect runs again for the new destination — measured, `guardCalls 2` for one push.
- **`/login` must keep `meta: { public: true }`.** Without it the guard redirects `/login` to `/login`, which is the infinite loop acceptance criterion 4 forbids.
- **`installAuthGuards` is called inside the factory**, so a router built for a test is guarded exactly like the real one and nobody can forget the wiring.

### 5 — Let the session endpoints handle their own 401s

**File: `frontend/src/api/client.ts`**

Replace line 5 and the condition on line 28. Nothing else in the file changes.

```ts
// These three manage the session, so they answer 401 as part of normal
// operation and deal with it themselves: /auth/login is a failed sign-in,
// /auth/me is a dead stored token the guard is already reacting to, and
// /auth/logout was already revoked. The interceptor exists to rescue requests
// that did NOT expect a dead session; reacting here as well would clear state
// and navigate while a router guard is mid-flight.
const SESSION_PATHS = ['/auth/login', '/auth/me', '/auth/logout']
```

```ts
    if (error.response?.status === 401 && !SESSION_PATHS.includes(error.config?.url ?? '')) {
      clearToken()
      onUnauthorized()
    }
```

- **`clearToken()` stays here even though `auth.clear()` also calls it.** The interceptor guarantees storage is clean before any handler runs, including when no handler is installed; `clear()` is the store's own contract. `localStorage.removeItem` on an absent key is a no-op, so the overlap costs nothing.
- **`error.config?.url` is the relative path**, matching the strings above — `client.spec.ts:48` already depends on that.
- **Do not** widen this to a prefix match on `/auth/`. TM-14 will add `POST /auth/password`, where a `401` genuinely means "your session died mid-form" and the interceptor should react.

### 6 — Wire it up and drop the ad-hoc handler

**File: `frontend/src/main.ts`**

Delete the import on line 6 and the block on lines 8–12 — `installAuthGuards` (called from `createAppRouter`) now owns that behaviour, with a latch the old version lacked. The file becomes:

```ts
import { createPinia } from 'pinia'
import { createApp } from 'vue'
import './style.css'
import App from './App.vue'
import router from './router'

// Pinia BEFORE the router: `use(router)` starts the first navigation, the guard
// runs, and the guard calls useAuthStore(). Reversed, this throws
// "getActivePinia() was called but there was no active Pinia" on first paint.
createApp(App).use(createPinia()).use(router).mount('#app')
```

### 7 — The sign-in form

**File: `frontend/src/views/LoginView.vue`** — replace all 10 lines.

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { safeRedirect } from '../router/guards'
import { useAuthStore } from '../stores/auth'
import axios from 'axios'

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
    // TM-9 answers every rejection with the same 422 and the same message, so
    // there is nothing to interpret — show what the server said, and fall back
    // only when the request never reached it.
    error.value = messageFor(caughtError)
  } finally {
    submitting.value = false
  }
}

function messageFor(caughtError: unknown): string {
  if (axios.isAxiosError(caughtError)) {
    const data = caughtError.response?.data as { message?: string } | undefined
    if (data?.message) return data.message
    if (caughtError.response?.status === 429) return 'Too many attempts. Try again in a minute.'
  }
  return 'The API is unreachable.'
}
</script>

<template>
  <main class="panel">
    <h1>Sign in</h1>
    <form @submit.prevent="submit">
      <label for="email">Email</label>
      <input id="email" v-model="email" type="email" autocomplete="username" required data-testid="login-email" />
      <label for="password">Password</label>
      <input id="password" v-model="password" type="password" autocomplete="current-password" required data-testid="login-password" />
      <p v-if="error" class="bad" data-testid="login-error">{{ error }}</p>
      <button type="submit" :disabled="submitting" data-testid="login-submit">
        {{ submitting ? 'Signing in…' : 'Sign in' }}
      </button>
    </form>
  </main>
</template>

<style scoped>
.panel { padding: 32px; text-align: start; }
form { display: grid; gap: 8px; max-width: 320px; }
label { color: var(--text-h); font-weight: 500; }
input { padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; color: var(--text-h); background: var(--bg); }
button { margin-top: 16px; }
.bad { color: crimson; }
</style>
```

- **`router.replace`, not `push`.** The sign-in page must not sit in history behind the app, or the browser back button returns to a form the guard immediately redirects away from.
- **`safeRedirect(route.query.redirect)`** — `query.redirect` is `string | string[] | null` (a repeated query key gives an array), and the value came from the URL bar. See task 3.
- **TM-9's `422` body carries `message`**, so `messageFor` needs no per-field logic. The `429` branch exists because TM-9 throttles this endpoint to 5/minute per IP and *"Too Many Attempts."* is not a sentence to show a person.
- **`required` and `type="email"`** are convenience only. The server validates; the guard authorises.

### 8 — The two new screens

**Create file: `frontend/src/views/ForbiddenView.vue`**

```vue
<template>
  <main class="panel">
    <h1>Not authorised</h1>
    <p data-testid="forbidden-message">
      Your account does not have access to that screen. If you think it should,
      ask an administrator to check your role.
    </p>
    <RouterLink to="/" data-testid="forbidden-home">Back to the app</RouterLink>
  </main>
</template>

<style scoped>
.panel { padding: 32px; text-align: start; }
p { margin-bottom: 24px; }
</style>
```

**Create file: `frontend/src/views/AdminUsersView.vue`**

Deliberately a stub, exactly as TM-4's `LoginView.vue` was. The route's `meta: { role: 'admin' }` is what this story delivers; the screen is TM-12's.

```vue
<template>
  <main class="panel">
    <h1>Users</h1>
    <p data-testid="admin-users-placeholder">
      Managing agent accounts arrives with TM-12. This route exists so the
      admin-only guard has something real to protect.
    </p>
  </main>
</template>

<style scoped>
.panel { padding: 32px; text-align: start; }
</style>
```

### 9 — Somewhere to sign out from

**File: `frontend/src/App.vue`** — replace all 3 lines.

Acceptance criterion 5 needs a reachable logout. This is the smallest header that provides one; **no navigation links** — those belong to the stories that add screens worth linking to.

```vue
<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function signOut(): Promise<void> {
  // The store clears whether or not the request succeeds; navigating is the
  // component's job, so the store never has to know about the router.
  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <header v-if="auth.isAuthenticated" class="bar">
    <span data-testid="session-user">{{ auth.user?.name }}</span>
    <button type="button" @click="signOut" data-testid="sign-out">Sign out</button>
  </header>
  <RouterView />
</template>

<style scoped>
.bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 12px 32px; border-bottom: 1px solid var(--border); }
</style>
```

`App.vue` sits at `src/App.vue`, so the store import is `'./stores/auth'` — one level shallower than every view's `'../stores/auth'`. `npm run typecheck` catches it if you copy a view's path by reflex.

---

## Edge Cases & Failure Modes

- **The blank first paint.** The guard awaits `/auth/me` before the first `<RouterView>` render, and `client.ts:15` sets `timeout: 10_000`. With the API down, a reload shows `index.html`'s empty `#app` for up to ten seconds and then the sign-in form. Accepted deliberately: the alternative is rendering a protected screen and yanking it away, which is the *"stranded on a broken screen"* this story exists to prevent. There is no app shell to put a spinner in yet — the story that adds one (**TM-29**) should add the spinner with it. Do not shorten the global timeout to paper over this; every other request depends on it.
- **`useAuthStore()` at module scope.** In `guards.ts` or a view's module body it runs before `app.use(createPinia())` and throws *"getActivePinia() was called but there was no active Pinia"* — a blank page with one console error, no network activity, and no clue that route configuration is involved. Both call sites in task 3 are inside function bodies for this reason, and `main.ts`'s Pinia-before-router order is the other half.
- **Un-memoised hydration.** Measured: a guard that returns a redirect runs again for the new destination — `guardCalls 2` for a single `router.push('/')`. Without the one-shot promise that is two `GET /auth/me` calls per page load, and on a slow connection the later response can overwrite the earlier one. The memo only covers the in-flight window; both settled outcomes short-circuit on the first two lines of `hydrate()`.
- **The hydration promise at module scope.** It would work in the browser and quietly break the tests: `setActivePinia(createPinia())` cannot reset a module-level `let`, so "only one `/auth/me` for concurrent callers" would pass or fail depending on which spec file ran first. Measured that a `let` inside the `defineStore` setup **is** per-instance — a fresh Pinia restarts the counter at 1.
- **Two parallel `401`s.** `router.currentRoute` does not change until a navigation resolves, so both handlers see the old route and both navigate — the double redirect acceptance criterion 4 forbids. vue-router does refuse the second one (measured: a duplicated `replace` resolves with `NavigationFailure` type `16`, *"Avoided redundant navigation to current location"*, and does not throw), but that is a silent backstop; the explicit latch is the requirement, and the test asserts `replace` was called once rather than asserting on the failure object.
- **`/auth/me` returning `401` while the guard is awaiting it.** Without the `SESSION_PATHS` exemption the interceptor clears and navigates from inside the guard's own await, cancelling the in-flight navigation and racing the guard's redirect. The visible symptom is landing on `/login` with no `?redirect=`, intermittently. Task 5 is what prevents it.
- **`/auth/logout` returning `401`.** Normal: TM-10 returns `401` for a token that is already revoked. `logout()` clears in `finally` and the component navigates, so the outcome is right; the exemption stops a second navigation from arriving on top of the first.
- **Open redirect via `?redirect=`.** Measured against vue-router 4.6.4: `resolve('https://evil.com')` yields `/https://evil.com` (the scheme is neutralised, harmless) but `resolve('//evil.com')` yields `//evil.com` **verbatim**, and a browser reads a leading `//` as protocol-relative. `safeRedirect` rejects `//`, `/\` and anything not starting with `/`. **jsdom does not reproduce the browser's protocol-relative resolution** — pushing `//evil.com` under jsdom gave `http://localhost:3000//evil.com`, i.e. a same-origin path — so a test cannot demonstrate the exploit. Assert on `safeRedirect` directly; do not write a test that "proves" the router is safe, because under jsdom it would pass either way.
- **`?redirect` arriving as an array.** `?redirect=/a&redirect=/b` makes `route.query.redirect` a `string[]`. `safeRedirect`'s `typeof raw !== 'string'` returns `/`. Without that check, `router.replace(['/a','/b'])` is a type error at build time and nonsense at runtime.
- **`/login` without `meta: { public: true }`.** The guard sends `/login` to `/login`; vue-router's duplicate detection stops the literal infinite loop but the user never gets past a blank page. This is the single most consequential line in task 4's route table.
- **`role: 'admin'` on `/forbidden`.** Would make the not-authorised screen itself require admin, so an agent redirected there is redirected there again. It carries no `role` on purpose.
- **`return false` instead of `{ name: 'forbidden' }`.** Aborting a navigation leaves the user on the previous route — which, on a fresh page load, is nothing. A blank screen is not the *"clear not-authorised screen"* acceptance criterion 3 asks for.
- **A misspelled `meta` key.** `meta: { publik: true }` compiles cleanly — measured — because `RouteMeta extends Record<PropertyKey, unknown>` upstream, and the route silently becomes private. A wrong **value** *is* caught (`meta: { role: 'superuser' }` → `error TS2322`). The route-table integration test in Test Plan 5 is what actually catches a mis-keyed record.
- **Caching the user in `localStorage`.** Rejected: an admin demoted to agent would keep an admin UI until they cleared their browser, and a deactivated agent would keep a rendered app while every request `401`s. `/auth/me` costs one request per page load and cannot be stale. Do not add a second storage key.
- **Cross-tab sessions.** `token.ref` is seeded from `getToken()` once, when the store is first used. Signing out in one tab does not update another tab's store — the second tab keeps rendering until its next request `401`s, at which point the interceptor cleans up. Correct-but-late, and **no story owns fixing it**; a `window.addEventListener('storage', …)` bridge would be the way if it ever becomes a complaint. Do not add it here.
- **`localStorage` unavailable** (private mode, blocked site data). `src/api/token.ts` already swallows every throw, so `getToken()` returns `null` and `setToken()` is a no-op: sign-in works and the session lasts exactly one page load. Nothing in this story needs a new guard, and no new code may touch `localStorage` outside that module.
- **`noUnusedLocals` in specs.** `tsconfig.app.json:9-10` turns unused imports into build failures, and `include` covers `src/**/*.ts`, so a leftover import in a `.spec.ts` breaks `npm run typecheck` and therefore `npm run build` — while `npx vitest` still passes. Run both.
- **A spec outside `src/` or not ending in `.spec.ts`.** `vite.config.ts:23` is `include: ['src/**/*.spec.ts']`. Such a file is not run and not reported — it simply does not exist as far as `npm run test` is concerned.
- **Importing the router singleton in a spec.** `router/index.ts` builds one at module load and `installAuthGuards` registers a `401` handler on the shared axios client as a side effect. Two spec files importing it share history and that handler, so failures depend on file order. Use `createAppRouter(createMemoryHistory())`.
- **`meta.role` as an authorization check.** It is not one. It hides a screen; TM-13's policies decide what the API will do, and an agent who types the URL of an admin *endpoint* gets a `403` from the server regardless of what the SPA rendered. Do not let a later story treat a passing guard as permission.

---

## Test Plan

`npm run test` (`vitest run`) from `frontend/`. Baseline measured today: **3 files, 11 tests** — `src/api/client.spec.ts` (5), `src/stores/health.spec.ts` (3), `src/views/HealthView.spec.ts` (3). Match those files exactly: real Pinia via `setActivePinia(createPinia())`, `vi.mock('../api/…', () => ({ fn: vi.fn() }))` at module scope, `vi.mocked(fn).mockResolvedValue(...)` per test, `await flushPromises()` after mounting, `data-testid` selectors, and the compressed several-statements-per-line style.

1. **Create `frontend/src/stores/auth.spec.ts`** — mock `../api/auth`, `beforeEach` does `localStorage.clear(); setActivePinia(createPinia())`. Eleven tests:
   - `reads a persisted token on creation` — `localStorage.setItem('tm.token', 'abc')` **before** `useAuthStore()`, then `token` is `'abc'` and `isAuthenticated` is **`false`** (a token is not a session).
   - `login stores the token and the user` — `apiLogin` resolves a `LoginResponse`; assert `token`, `user`, `isAuthenticated` true, and `localStorage.getItem('tm.token')`.
   - `login rejects and leaves the store empty` — `apiLogin` rejects; `await expect(auth.login(...)).rejects.toThrow()`, then `user` is null and storage is empty. The view shows the message; the store must not half-commit.
   - `isAdmin is true only for the admin role` — one `admin` user, one `agent` user.
   - `hydrate does nothing without a token` — `me` not called.
   - `hydrate does nothing when the user is already known` — after `login`, `me` not called.
   - `hydrate fetches the user once for concurrent callers` — `await Promise.all([auth.hydrate(), auth.hydrate(), auth.hydrate()])` with `me` resolving after a tick; `expect(me).toHaveBeenCalledTimes(1)`. **This is the test the module-scope-vs-setup-scope decision exists for** — with a module-level promise it passes alone and fails after another spec file has run.
   - `hydrate clears the session on 401` — `me` rejects with an `AxiosError` carrying `response.status === 401`; `token` null, `user` null, storage empty, and `hydrate()` **did not reject**.
   - `hydrate keeps the token on a network failure` — `me` rejects with a plain `Error`; `user` null but `token` still set and still in storage. Pins the difference between "dead token" and "cannot tell".
   - `logout clears everything` — `apiLogout` resolves; `token`, `user` and storage all empty.
   - `logout clears even when the request fails` — `apiLogout` rejects with a `401`; storage still empty afterwards. Acceptance criterion 5's real assertion.

2. **Create `frontend/src/router/guards.spec.ts`** — three `describe` blocks. Mock `../api/auth`; `setActivePinia(createPinia())` in `beforeEach`. Build `to` objects with `router.resolve(path)` from a throwaway `createAppRouter(createMemoryHistory())` so `fullPath`, `name` and `meta` are real rather than hand-written literals.

   `authGuard` — seven tests:
   - `sends an unauthenticated visitor to login with the intended destination` — `to` is `/admin/users`; result is `{ name: 'login', query: { redirect: '/admin/users' } }`.
   - `omits the redirect query for the root` — `to` is `/`; `query` is `undefined`.
   - `lets a signed-in user through` — after `login`, a `/` navigation returns `true`.
   - `sends a signed-in user away from the login form` — returns `{ name: 'home' }`.
   - `lets an unauthenticated visitor reach the login form` — returns `true`.
   - `sends an agent to the not-authorised screen` — agent user, `to` is `/admin/users`, returns `{ name: 'forbidden' }`.
   - `lets an admin reach an admin route` — returns `true`.
   - `hydrates from a stored token before deciding` — seed `localStorage`, `me` resolves an admin, `to` is `/admin/users`: returns `true` and `me` was called once. The reload path, and the test that fails if the `await auth.hydrate()` line is dropped.

   `safeRedirect` — four tests:
   - `keeps a same-origin path` — `/admin/users`.
   - `rejects protocol-relative and backslash forms` — `//evil.com`, `/\evil.com`, `///evil.com` all give `/`.
   - `rejects anything not starting with a slash` — `https://evil.com`, `evil.com`, `javascript:alert(1)`.
   - `rejects a non-string` — `undefined`, `null`, `['/a','/b']`.

   `createUnauthorizedHandler` — four tests:
   - `clears the store` — after `login`, one call empties `user` and storage.
   - `redirects to login once for two concurrent 401s` — `vi.spyOn(router, 'replace')`; call the handler twice synchronously; `expect(replace).toHaveBeenCalledTimes(1)`.
   - `preserves the current path as the redirect target` — navigate the test router to `/admin/users` first, then assert the `query.redirect`.
   - `does not redirect when already on login` — `replace` not called.

3. **Create `frontend/src/views/LoginView.spec.ts`** — mock `../api/auth`; mount with `global: { plugins: [createPinia(), router] }` where `router` is a fresh `createAppRouter(createMemoryHistory())`. Six tests:
   - `signs in and continues to the intended destination` — `await router.push('/login?redirect=/admin/users')`, fill both inputs via `setValue`, submit, `flushPromises()`, then `router.currentRoute.value.fullPath` is `/admin/users`.
   - `defaults to the root with no redirect query` — lands on `/`.
   - `refuses a hostile redirect query` — `?redirect=//evil.com` lands on `/`, not `//evil.com`. Asserted on the resulting route, with `safeRedirect`'s own unit tests carrying the real proof (jsdom cannot reproduce the browser behaviour — see Edge Cases).
   - `shows the server's message on a rejected sign-in` — `apiLogin` rejects with a `422` whose body is `{ message: 'These credentials do not match our records.' }`; `[data-testid="login-error"]` shows exactly that.
   - `shows a friendly message when throttled` — a `429` renders the "Try again in a minute" text, not *"Too Many Attempts."*.
   - `disables the submit button while the request is in flight` — `apiLogin` returns an unresolved promise; the button has `disabled`.

4. **Create `frontend/src/api/auth.spec.ts`** — the adapter-swap technique from `client.spec.ts:7-14`, no mocking of `client`. Four tests:
   - `posts credentials to /auth/login and returns the payload` — capture `config.url` and `config.data`; assert `/auth/login` and the parsed body.
   - `unwraps the user from GET /auth/me` — adapter returns `{ user: {...} }`; `me()` resolves the bare user. Catches the envelope being read as `data` instead of `data.user`.
   - `posts to /auth/logout` — asserts the URL and the method.
   - `isUnauthorized distinguishes 401 from everything else` — a `401` `AxiosError` is `true`; a `500` `AxiosError`, a plain `Error`, `null` and a string are all `false`.

5. **Create `frontend/src/router/index.spec.ts`** — the integration test, over `createAppRouter(createMemoryHistory())` with a real guard and real components. Mock `../api/auth`; `setActivePinia(createPinia())` per test. Six tests:
   - `bounces an unauthenticated visitor from a private route to login` — `push('/admin/users')` ends on `/login?redirect=/admin/users`.
   - `renders the not-authorised screen for an agent on an admin route` — sign in as an agent, `push('/admin/users')`, then mount `RouterView` (or assert `currentRoute.value.name === 'forbidden'` and that `ForbiddenView` is the matched component) and check `[data-testid="forbidden-message"]` is present.
   - `lets an admin reach the admin route` — `currentRoute.value.name` is `admin-users`.
   - `restores a session from a stored token` — seed `localStorage`, `me` resolves; `push('/')` succeeds without touching `/login`, and `me` was called once.
   - `every route except login is private` — iterate `router.getRoutes()`; assert exactly one record has `meta.public === true` and it is `login`. **This is the test that catches a misspelled `meta` key**, which the type system does not.
   - `the catch-all lands on home` — `push('/nope/nope')` resolves to the `home` route (via `/login` when unauthenticated).

6. **Edit `frontend/src/api/client.spec.ts`** — add two cases to the existing `describe`, copying the shape of the test at lines 45–50. Two tests:
   - `does not call handler for /auth/me 401` — token still present, handler not called.
   - `does not call handler for /auth/logout 401` — same.

   Leave all five existing tests untouched; the one at 45–50 already covers `/auth/login` and must keep passing after `LOGIN_PATH` becomes `SESSION_PATHS`.

7. **No test for `ForbiddenView` or `AdminUsersView` in isolation.** Both are static templates with no logic; Test Plan 5 renders them through the router, which is the only thing worth asserting about them. `AdminUsersView` is TM-12's to test once it does something.

8. **No test for `App.vue`'s header beyond the store.** The sign-out path is `auth.logout()` plus `router.replace`, both covered above. A mount test asserting a `<button>` exists pins markup, not behaviour. (**TM-61** owns broader component coverage.)

9. **Regression.** `src/stores/health.spec.ts` (3) and `src/views/HealthView.spec.ts` (3) must pass untouched — `/` becoming private changes the route's `meta`, not the component, and both specs mount `HealthView` directly. `src/api/client.spec.ts` keeps its 5 and gains 2.

Expected total added: **42 tests** across five new files plus two added to `client.spec.ts`, taking the suite from **11** to **53**.

---

## Verification Steps

Run in this order. The working directory is stated for every command.

1. **Prerequisites are real:** `backend/` — `php artisan route:list --path=auth --columns=method,uri,name,middleware` lists `auth.login`, `auth.logout` and `auth.me`. **If `auth.me` is missing, stop.**
2. **Stack up:** repo root — `docker compose up -d && docker compose ps` (three `healthy`); `backend/` — `php artisan migrate:fresh --seed` then `php artisan serve`; `frontend/` — `npm run dev` on http://localhost:5173.
3. **Frontend typechecks:** `frontend/` — `npm run typecheck` exits `0`. It passes today; a failure here is almost always the store import path in `App.vue` or an unused import in a new spec (`noUnusedLocals`).
4. **Frontend builds, lints and is formatted:** `frontend/` — `npm run build` exits `0` (it runs `vue-tsc -b` first, so it is the gate CI will use), then `npm run format` (writes), then `npm run lint` and `npm run format:check`, both exiting `0`. TM-6 added all three; a file written in a non-Prettier shape shows up here as a rewritten diff.
5. **Frontend tests:** `frontend/` — `npm run test` exits `0` with **53 tests across 8 files**, and the three pre-existing files still report 5 / 3 / 3.
6. **Single file, for a fast loop:** `frontend/` — `npx vitest run src/router/guards.spec.ts` exits `0`.
7. **Unauthenticated redirect, in a browser:** clear site data for `localhost:5173`, then visit **http://localhost:5173/admin/users**. The URL must become **`/login?redirect=/admin/users`** and the sign-in form must render. Visit `/` instead and the URL must become `/login` with **no** query.
8. **Sign in and continue:** from `/login?redirect=/admin/users`, sign in as `admin@ticket-management.test` / `password`. You must land on **`/admin/users`** showing the TM-12 placeholder, with the header showing the admin's name and a "Sign out" button. Check DevTools → Application → Local Storage: **`tm.token`** is present, and there is **no** key holding the user object.
9. **Reload persistence:** press **F5** on `/admin/users`. You stay there. In the Network tab there is exactly **one** `GET /api/v1/auth/me` — not two. Two means the hydration memo is missing or was placed at module scope.
10. **Admin-only route blocked for an agent:** `backend/` —

    ```bash
    php artisan tinker --execute="App\Models\User::create(['name'=>'Agent Smith','email'=>'agent@ticket-management.test','password'=>'password','is_active'=>true]);"
    ```

    (`role` defaults to `agent` — TM-8's migration.) Sign out, sign in as that user, then visit `/admin/users`. You must land on **`/forbidden`** with the "Not authorised" text and a working link back to `/`. Visit `/` and it renders normally.
11. **Sign-out clears storage:** click "Sign out". You land on `/login`, `tm.token` is **gone** from Local Storage, and the Network tab shows `POST /api/v1/auth/logout` returning **`204`**. Press the browser back button — you must not get back into the app.
12. **A 401 mid-session redirects exactly once:** sign in, then revoke the token server-side without the SPA knowing —

    ```bash
    php artisan tinker --execute="Laravel\Sanctum\PersonalAccessToken::query()->delete();"
    ```

    — and click "Re-check" on the health panel. You land on `/login?redirect=/`, `tm.token` is cleared, and the Network tab shows **one** navigation, not two. Repeat with two failing requests in flight (click "Re-check" twice quickly before the first resolves): still one redirect.
13. **No loop when the API is down:** stop `php artisan serve`, then reload `/`. After the 10-second client timeout you land on `/login` and stay there — the URL must not oscillate. `tm.token` must **still be present** (a timeout is not proof the token is dead). Restart the API and reload: you are signed in again with no password.
14. **Hostile redirect is refused:** visit **`http://localhost:5173/login?redirect=//example.com`** and sign in. You must land on **`/`**. Check the address bar, not just the rendered page.
15. **Nothing else touched storage:** repo root — `grep -rn "localStorage" frontend/src/` returns hits only in `src/api/token.ts` and in `*.spec.ts` files.
16. **Regression:** repo root — `git status --short` lists **no path under `backend/`** and no change to `docs/`, `docker-compose.yml`, `README.md`, `CLAUDE.md`, `frontend/package.json`, `frontend/package-lock.json`, `frontend/vite.config.ts`, `frontend/tsconfig*.json`, `frontend/.env.example` or `frontend/src/env.d.ts`.

---

## Done Criteria

- [ ] `src/stores/auth.ts` exists as a setup store holding `token` and `user`, exposing `isAuthenticated` (`user !== null`, **not** `token !== null`), `isAdmin`, `login`, `hydrate`, `logout` and `clear`. It does **not** import the router and never navigates.
- [ ] The token is read from `localStorage` on store creation via `src/api/token.ts`; the **user is not persisted** anywhere, and `grep -rn "localStorage" frontend/src/` hits only `src/api/token.ts` and specs.
- [ ] A reload on a protected URL restores the session with **exactly one** `GET /api/v1/auth/me`, verified in the Network tab, and the hydration promise is a `let` **inside** the `defineStore` setup callback so `setActivePinia(createPinia())` resets it.
- [ ] `hydrate()` never rejects; a `401` clears the session, and a network failure or timeout leaves `token` in place.
- [ ] Routes are **private by default**: `src/router/guards.ts`'s guard checks `to.meta.public`, and a test iterating `router.getRoutes()` asserts exactly one record — `login` — carries `public: true`.
- [ ] `RouteMeta` is augmented with `public?: boolean` and `role?: UserRole`, and the plan's note that this catches a wrong **value** but not a misspelled **key** is preserved rather than overstated.
- [ ] An unauthenticated visit to `/admin/users` lands on `/login?redirect=/admin/users`; a visit to `/` lands on `/login` with **no** query; signing in continues to the preserved destination.
- [ ] `safeRedirect` rejects `//…`, `/\…`, any value without a leading `/`, and non-strings, with unit tests for each — and no test claims to demonstrate the browser exploit under jsdom.
- [ ] An agent visiting `/admin/users` lands on `/forbidden` and sees `ForbiddenView`'s text; an admin reaches `AdminUsersView`; `/forbidden` itself carries no `role` and does not loop.
- [ ] `src/api/client.ts` exempts `/auth/login`, `/auth/me` **and** `/auth/logout` from the `401` interceptor, with a comment saying why, and `client.spec.ts` gains a test for each of the two new paths while its five existing tests pass unchanged.
- [ ] `createUnauthorizedHandler` clears the store and calls `router.replace` **once** for two concurrent `401`s — asserted with a spy, not by relying on vue-router's duplicate detection — and never redirects when already on `login`.
- [ ] `main.ts` no longer contains an ad-hoc `setUnauthorizedHandler` block, keeps `createPinia()` **before** `use(router)`, and carries a comment saying why the order matters.
- [ ] `LoginView.vue` is a working form that shows the server's `422` message verbatim, renders a readable message for `429`, disables submit while in flight, and uses `router.replace` so the form is not left in history.
- [ ] `App.vue` shows the signed-in user's name and a "Sign out" button; signing out calls `POST /auth/logout`, clears `tm.token` from `localStorage` **even when the request fails**, and navigates to `/login` from the component rather than from the store.
- [ ] `router/index.ts` exports `createAppRouter(history?)` and calls `installAuthGuards` inside it, so a test router is guarded identically and no spec imports the module-level singleton.
- [ ] `npm run test` exits `0` with **53 tests across 8 files** (11 pre-existing, 42 added); `npm run typecheck`, `npm run build`, `npm run lint` and `npm run format:check` all exit `0`.
- [ ] `frontend/package.json` and `frontend/package-lock.json` are **unchanged** — no dependency was added, and `@pinia/testing` is still absent.
- [ ] No file under `backend/` or `docs/` changed; `frontend/vite.config.ts`, `tsconfig*.json`, `.env.example` and `src/env.d.ts` are untouched.
- [ ] `00-overview.md` records this story, the rehydration-race and one-redirect findings, and the two stubs later stories replace: **TM-29** owns `/`'s component and **TM-12** owns `/admin/users`'.

**STOP HERE. Report to the user and wait for confirmation before proceeding to Story 10 (TM-12).**
