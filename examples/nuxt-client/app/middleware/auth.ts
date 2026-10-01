// Pages protégées : definePageMeta({ middleware: 'auth' })
export default defineNuxtRouteMiddleware((to) => {
  const { loggedIn } = useUserSession()
  if (!loggedIn.value) {
    return navigateTo(`/auth/login?redirect=${encodeURIComponent(to.fullPath)}`, { external: true })
  }
})
