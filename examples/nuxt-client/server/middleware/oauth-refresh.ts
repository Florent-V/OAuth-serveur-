/**
 * Renouvelle les jetons au début de chaque requête (page ou API) si l'access token expire bientôt.
 *
 * Indispensable avec le rendu serveur : un useFetch('/api/…') exécuté pendant le rendu est un appel
 * interne, dont le cookie de session mis à jour n'est PAS renvoyé au navigateur. Sans ce middleware,
 * le navigateur garderait l'ancien refresh token (déjà consommé) et l'utilisateur serait déconnecté.
 * Ici, le renouvellement a lieu sur la requête de la page elle-même : le nouveau cookie part avec la page,
 * et les appels internes de la même page réutilisent les jetons obtenus (voir pendingRefreshes).
 */
export default defineEventHandler(async (event) => {
  if (/^\/(auth\/|_nuxt\/|api\/_auth\/|__nuxt)/.test(event.path)) {
    return
  }
  const session = await getUserSession(event)
  if (!session.user || !session.secure) {
    return
  }
  // En cas d'échec (accès retiré, compte bloqué…), la session est fermée : la page s'affiche déconnectée
  await getAccessToken(event).catch(() => {})
})
