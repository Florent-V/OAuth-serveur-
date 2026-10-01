declare module '#auth-utils' {
  // Données visibles côté Vue (useUserSession)
  interface User {
    id: number
    email?: string
    name?: string
  }

  // Données réservées au serveur (jamais renvoyées au navigateur)
  interface SecureSessionData {
    accessToken: string
    refreshToken: string
    refreshAt: number
  }
}

export {}
