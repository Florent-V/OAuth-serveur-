// 2. Retour du serveur OAuth2 : vérification du state, échange du code, ouverture de la session
export default defineEventHandler(async (event) => {
  const { redirectUri } = useRuntimeConfig(event).oauth
  const query = getQuery(event)
  const state = getCookie(event, 'oauth_state')
  const verifier = getCookie(event, 'oauth_verifier')
  const returnTo = getCookie(event, 'oauth_return_to') ?? '/'
  for (const name of ['oauth_state', 'oauth_verifier', 'oauth_return_to']) {
    deleteCookie(event, name, { path: '/auth' })
  }

  if (query.error) {
    throw createError({ statusCode: 400, message: `Connexion refusée : ${query.error}` })
  }
  if (!state || !verifier || query.state !== state) {
    throw createError({ statusCode: 400, message: 'State invalide, recommencez la connexion' })
  }

  const tokens = await requestTokens(event, {
    grant_type: 'authorization_code',
    redirect_uri: redirectUri,
    code: String(query.code ?? ''),
    code_verifier: verifier,
  }).catch(() => {
    throw createError({ statusCode: 401, message: 'Code refusé, recommencez la connexion' })
  })
  const userinfo = await fetchUserinfo(event, tokens.access_token)

  // Identifiez l'utilisateur par userinfo.id (stable) ; créez ou mettez à jour votre compte local ici.
  await replaceUserSession(event, {
    user: { id: userinfo.id, email: userinfo.email, name: userinfo.name },
    secure: tokensForSession(tokens),
    loggedInAt: Date.now(),
  })

  return sendRedirect(event, returnTo)
})
