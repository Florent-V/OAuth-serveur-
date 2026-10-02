/**
 * Au début de chaque requête (page ou API) d'un utilisateur connecté :
 *
 * 1. Back-channel logout : si le serveur OAuth2 a révoqué l'utilisateur après l'ouverture de la session,
 *    la session est fermée immédiatement.
 * 2. Renouvellement des jetons si l'access token arrive à expiration ; s'il est refusé (accès retiré,
 *    compte bloqué…), la session est fermée. C'est aussi le filet de sécurité si une notification se perd.
 *
 * Le renouvellement doit avoir lieu ici, sur la requête de la page elle-même : un useFetch('/api/…')
 * exécuté pendant le rendu serveur est un appel interne, dont le cookie de session mis à jour n'est PAS
 * renvoyé au navigateur (qui garderait un refresh token déjà consommé). Les appels internes de la même
 * page réutilisent les jetons obtenus (voir pendingRefreshes).
 */
export default defineEventHandler(async (event) => {
  // Toutes les requêtes, y compris /api/_auth/session (useUserSession côté navigateur),
  // sauf les routes de connexion et les fichiers statiques
  if (/^\/(auth\/|_nuxt\/|__nuxt)/.test(event.path)) {
    return
  }
  const session = await getUserSession(event)
  if (!session.user || !session.secure) {
    return
  }
  if ((session.loggedInAt ?? 0) <= await revokedAt(session.user.id)) {
    await endSession(event)
    return
  }
  // En cas d'échec, la session est fermée : la page s'affiche déconnectée
  await getAccessToken(event).catch(() => {})
})
