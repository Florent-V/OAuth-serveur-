import type { H3Event } from 'h3'

export interface OAuthTokens {
  token_type: string
  expires_in: number
  access_token: string
  refresh_token: string
}

export interface OAuthUserinfo {
  id: number
  sub: string
  email?: string
  name?: string
}

/** Chaîne aléatoire encodée en base64url (Web Crypto : Node.js, Bun, Deno, edge…). */
export function randomString(bytes: number): string {
  return base64url(crypto.getRandomValues(new Uint8Array(bytes)))
}

/** code_challenge PKCE (S256) = BASE64URL(SHA256(code_verifier)). */
export async function pkceChallenge(verifier: string): Promise<string> {
  return base64url(new Uint8Array(await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier))))
}

function base64url(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/** Appel serveur à serveur vers /token (le client secret ne quitte jamais le serveur Nuxt). */
export function requestTokens(event: H3Event, params: Record<string, string>): Promise<OAuthTokens> {
  const { serverUrl, clientId, clientSecret } = useRuntimeConfig(event).oauth
  return $fetch<OAuthTokens>(`${serverUrl}/token`, {
    method: 'POST',
    body: new URLSearchParams({ client_id: clientId, client_secret: clientSecret, ...params }),
  })
}

export function fetchUserinfo(event: H3Event, accessToken: string): Promise<OAuthUserinfo> {
  const { serverUrl } = useRuntimeConfig(event).oauth
  return $fetch<OAuthUserinfo>(`${serverUrl}/api/userinfo`, {
    headers: { Authorization: `Bearer ${accessToken}` },
  })
}

export function tokensForSession(tokens: OAuthTokens) {
  const lifetime = tokens.expires_in * 1000
  return {
    accessToken: tokens.access_token,
    refreshToken: tokens.refresh_token,
    // Renouvellement 30 s avant l'expiration (à mi-vie si le jeton est très court)
    refreshAt: Date.now() + Math.max(lifetime - 30_000, lifetime / 2),
  }
}

/**
 * Ferme la session de l'utilisateur.
 *
 * clearUserSession() envoie un cookie vide au navigateur, mais h3 relit ensuite la session depuis le cookie
 * de la requête en cours : sans la suite, le reste de cette requête (rendu de la page, /api/_auth/session…)
 * verrait encore l'utilisateur connecté. On retire donc aussi le cookie de la requête.
 */
export async function endSession(event: H3Event): Promise<void> {
  await clearUserSession(event)
  const name = useRuntimeConfig(event).session?.name || 'nuxt-session'
  event.node.req.headers.cookie = (event.node.req.headers.cookie ?? '')
    .split(';')
    .filter((cookie: string) => !cookie.trim().startsWith(`${name}=`))
    .join(';')
}

// Renouvellements en cours, par refresh token : deux requêtes simultanées partagent le même appel
// (le refresh token n'est utilisable qu'une fois ; un second appel échouerait et déconnecterait l'utilisateur).
const pendingRefreshes = new Map<string, Promise<OAuthTokens>>()

/**
 * Access token valide pour la session courante, renouvelé s'il arrive à expiration.
 * Si le renouvellement est refusé (accès retiré, compte bloqué, « déconnecter partout »…),
 * la session est fermée et une erreur 401 est renvoyée.
 */
export async function getAccessToken(event: H3Event): Promise<string> {
  const { secure } = await requireUserSession(event)
  if (!secure) {
    throw createError({ statusCode: 401, message: 'Session invalide' })
  }
  if (Date.now() < secure.refreshAt) {
    return secure.accessToken
  }

  let refresh = pendingRefreshes.get(secure.refreshToken)
  if (!refresh) {
    refresh = requestTokens(event, { grant_type: 'refresh_token', refresh_token: secure.refreshToken })
      .finally(() => setTimeout(() => pendingRefreshes.delete(secure.refreshToken), 10_000))
    pendingRefreshes.set(secure.refreshToken, refresh)
  }

  try {
    const tokens = await refresh
    // Rotation : on enregistre le NOUVEAU refresh token
    await setUserSession(event, { secure: tokensForSession(tokens) })
    return tokens.access_token
  }
  catch {
    await endSession(event)
    throw createError({ statusCode: 401, message: 'Session expirée, reconnectez-vous' })
  }
}
