declare module '#auth-utils' {
  // Données visibles côté Vue (useUserSession)
  interface User {
    id: number
    email?: string
    name?: string
  }

  // Date d'ouverture de la session (comparée aux révocations reçues par back-channel logout)
  interface UserSession {
    loggedInAt?: number
  }

  // Données réservées au serveur (jamais renvoyées au navigateur)
  interface SecureSessionData {
    accessToken: string
    refreshToken: string
    refreshAt: number
  }
}

export {}
