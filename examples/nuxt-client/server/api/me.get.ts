// Exemple d'API protégée : utilise l'access token (renouvelé si besoin) pour appeler /api/userinfo.
// Une réponse 401/403 signifie que l'accès a été retiré : on ferme la session.
export default defineEventHandler(async (event) => {
  const accessToken = await getAccessToken(event)

  try {
    return await fetchUserinfo(event, accessToken)
  }
  catch {
    await clearUserSession(event)
    throw createError({ statusCode: 401, message: 'Accès retiré, reconnectez-vous' })
  }
})
