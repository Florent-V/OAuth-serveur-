// 1. Redirection vers le serveur OAuth2, avec state (anti-CSRF) et PKCE
export default defineEventHandler(async (event) => {
  const { serverUrl, clientId, redirectUri } = useRuntimeConfig(event).oauth
  const state = randomString(16)
  const verifier = randomString(48)

  // Page à rouvrir après connexion (chemin relatif uniquement : pas de redirection ouverte)
  const target = String(getQuery(event).redirect ?? '/')
  const returnTo = target.startsWith('/') && !target.startsWith('//') ? target : '/'

  // Valeurs temporaires (10 min), lues au retour sur /auth/callback
  const cookie = { httpOnly: true, secure: true, sameSite: 'lax' as const, path: '/auth', maxAge: 600 }
  setCookie(event, 'oauth_state', state, cookie)
  setCookie(event, 'oauth_verifier', verifier, cookie)
  setCookie(event, 'oauth_return_to', returnTo, cookie)

  return sendRedirect(event, `${serverUrl}/authorize?${new URLSearchParams({
    response_type: 'code',
    client_id: clientId,
    redirect_uri: redirectUri,
    scope: 'profile email',
    state,
    code_challenge: await pkceChallenge(verifier),
    code_challenge_method: 'S256',
  })}`)
})
