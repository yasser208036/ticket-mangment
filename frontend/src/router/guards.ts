import { setUnauthorizedHandler } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useMasterDataStore } from '../stores/masterData'
import type {
  RouteLocationNormalized,
  RouteLocationRaw,
  Router,
} from 'vue-router'

export function safeRedirect(raw: unknown): string {
  if (
    typeof raw !== 'string' ||
    !raw.startsWith('/') ||
    raw.startsWith('//') ||
    raw.startsWith('/\\')
  )
    return '/'
  return raw
}

function loginRoute(fullPath: string): RouteLocationRaw {
  return {
    name: 'login',
    query: fullPath === '/' ? undefined : { redirect: fullPath },
  }
}

export async function authGuard(
  to: RouteLocationNormalized,
): Promise<true | RouteLocationRaw> {
  const auth = useAuthStore()
  await auth.hydrate()
  if (to.meta.public)
    return to.name === 'login' && auth.isAuthenticated ? { name: 'home' } : true
  if (!auth.isAuthenticated) return loginRoute(to.fullPath)
  if (to.meta.roles && !to.meta.roles.includes(auth.user!.role))
    return { name: 'forbidden' }
  void useMasterDataStore().ensureLoaded()
  return true
}

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
