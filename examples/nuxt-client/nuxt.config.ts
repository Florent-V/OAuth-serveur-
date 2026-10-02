// Exemple : application Nuxt (SSR) connectée au serveur OAuth2 — voir docs/integrer-une-application.md
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  modules: ['nuxt-auth-utils'],
  runtimeConfig: {
    // Côté serveur uniquement (jamais envoyé au navigateur). Valeurs surchargées par les variables
    // d'environnement NUXT_OAUTH_SERVER_URL, NUXT_OAUTH_CLIENT_ID, NUXT_OAUTH_CLIENT_SECRET, NUXT_OAUTH_REDIRECT_URI,
    // NUXT_OAUTH_PUBLIC_KEY (clé publique PEM du serveur) et NUXT_OAUTH_ISSUER.
    oauth: {
      serverUrl: 'https://oauth.mydomain.com',
      clientId: '',
      clientSecret: '',
      redirectUri: 'https://app1.mydomain.com/auth/callback',
      // Back-channel logout : vérification des logout tokens envoyés par le serveur
      publicKey: '',
      issuer: '', // vide = serverUrl
    },
  },
})
