// 3. Déconnexion locale puis globale (le serveur OAuth2 ferme sa session et renvoie vers l'application)
export default defineEventHandler(async (event) => {
  const { serverUrl, redirectUri } = useRuntimeConfig(event).oauth
  await clearUserSession(event)

  const home = `${new URL(redirectUri).origin}/`
  return sendRedirect(event, `${serverUrl}/logout?redirect_uri=${encodeURIComponent(home)}`)
})
