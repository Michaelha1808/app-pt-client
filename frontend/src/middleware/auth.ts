// Allows both logged-in users AND guests. Use auth-strict for full-auth-only routes.
export default defineNuxtRouteMiddleware(async () => {
  if (import.meta.server) return

  const { isLoggedIn, isGuest, sessionReady } = useAuth()

  // Wait for plugin to finish restoreSession (with 5s safety timeout)
  if (!sessionReady.value) {
    await Promise.race([
      new Promise<void>(resolve => {
        const stop = watch(sessionReady, ready => { if (ready) { stop(); resolve() } })
      }),
      new Promise<void>(resolve => setTimeout(resolve, 5000)),
    ])
  }

  if (!isLoggedIn.value && !isGuest.value) return navigateTo('/auth/login')
})
