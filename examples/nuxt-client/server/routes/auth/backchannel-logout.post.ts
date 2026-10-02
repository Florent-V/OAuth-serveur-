// 4. Back-channel logout : le serveur OAuth2 prévient (de serveur à serveur) qu'un utilisateur doit être déconnecté.
//    URL à déclarer dans l'admin du serveur : https://app1.mydomain.com/auth/backchannel-logout
export default defineEventHandler(async (event) => {
  setResponseHeader(event, 'Cache-Control', 'no-store')
  const body = await readBody<{ logout_token?: string }>(event)

  try {
    const uid = await verifyLogoutToken(event, String(body?.logout_token ?? ''))
    // Toutes les sessions de cet utilisateur ouvertes avant maintenant deviennent invalides
    await markRevoked(uid)
    return {}
  }
  catch (error) {
    setResponseStatus(event, 400)
    return { error: 'invalid_request', error_description: (error as Error).message }
  }
})
