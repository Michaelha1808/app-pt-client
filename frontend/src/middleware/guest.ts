// Redirect logged-in users away from auth pages. Guests can still access auth pages.
export default defineNuxtRouteMiddleware(async () => {
  if (import.meta.server) return

  const { isLoggedIn, sessionReady } = useAuth()

  if (!sessionReady.value) {
    await Promise.race([
      new Promise<void>(resolve => {
        const stop = watch(sessionReady, ready => { if (ready) { stop(); resolve() } })
      }),
      new Promise<void>(resolve => setTimeout(resolve, 5000)),
    ])
  }

  if (isLoggedIn.value) return navigateTo('/home')
})
