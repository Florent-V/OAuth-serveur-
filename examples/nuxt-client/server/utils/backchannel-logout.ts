import type { H3Event } from 'h3'

const LOGOUT_EVENT = 'http://schemas.openid.net/event/backchannel-logout'

/**
 * Vérifie un logout token (OpenID Connect Back-Channel Logout 1.0, section 2.6) avec Web Crypto
 * et renvoie l'identifiant stable de l'utilisateur (claim « uid »).
 */
export async function verifyLogoutToken(event: H3Event, jwt: string): Promise<number> {
  const { serverUrl, clientId, publicKey, issuer } = useRuntimeConfig(event).oauth
  const [header, payload, signature] = jwt.split('.')
  if (!header || !payload || !signature || !publicKey) {
    throw new Error('jeton illisible ou clé publique absente')
  }
  const decode = (part: string) => Uint8Array.from(atob(part.replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0))
  const head = JSON.parse(new TextDecoder().decode(decode(header)))
  const claims = JSON.parse(new TextDecoder().decode(decode(payload)))

  const der = decode(publicKey.replace(/-----[A-Z ]+-----|\s/g, ''))
  const key = await crypto.subtle.importKey('spki', der, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['verify'])
  const now = Math.floor(Date.now() / 1000)
  const checks: Record<string, boolean> = {
    'algorithme RS256': head.alg === 'RS256',
    'signature': await crypto.subtle.verify('RSASSA-PKCS1-v1_5', key, decode(signature), new TextEncoder().encode(`${header}.${payload}`)),
    'émetteur (iss)': claims.iss === (issuer || serverUrl).replace(/\/$/, ''),
    'destinataire (aud)': [claims.aud].flat().includes(clientId),
    'date (iat)': typeof claims.iat === 'number' && Math.abs(now - claims.iat) < 300,
    'expiration (exp)': typeof claims.exp === 'number' && claims.exp > now,
    'événement': typeof claims.events?.[LOGOUT_EVENT] === 'object',
    'pas de nonce': claims.nonce === undefined,
    'utilisateur (uid)': Number.isInteger(claims.uid),
  }
  for (const [check, ok] of Object.entries(checks)) {
    if (!ok) {
      throw new Error(`logout token invalide : ${check}`)
    }
  }

  return claims.uid
}

// Révocations reçues : date par utilisateur, dans le stockage Nitro « data » (fichier en local,
// Redis ou autre en production via nitro.storage — à partager si l'application tourne en plusieurs instances).
const storage = () => useStorage('data')

export async function markRevoked(uid: number): Promise<void> {
  await storage().setItem(`oauth:revoked:${uid}`, Date.now())
}

export async function revokedAt(uid: number): Promise<number> {
  return Number(await storage().getItem(`oauth:revoked:${uid}`)) || 0
}
