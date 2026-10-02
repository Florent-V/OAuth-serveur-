# Exemple : application Nuxt (SSR) connectée au serveur OAuth2

Application Nuxt 4 qui délègue la connexion au serveur, en **client confidentiel** : le serveur Nitro de Nuxt
échange le code, garde les jetons et le client secret ; le navigateur ne reçoit qu'un cookie de session chiffré.
Explications : [docs/adapter-une-application.md](../../docs/adapter-une-application.md#1-nuxt-ssr).

| Fichier | Rôle |
|---|---|
| `server/routes/auth/login.get.ts` | Redirection vers `/authorize` (state, PKCE, page de retour) |
| `server/routes/auth/callback.get.ts` | Vérification du state, échange du code, ouverture de la session |
| `server/routes/auth/logout.get.ts` | Déconnexion locale puis globale |
| `server/utils/oauth.ts` | Appels à `/token` et `/api/userinfo`, renouvellement des jetons (`getAccessToken`) |
| `server/routes/auth/backchannel-logout.post.ts` | Réception des logout tokens (back-channel logout) |
| `server/utils/backchannel-logout.ts` | Vérification des logout tokens (Web Crypto) et stockage des révocations |
| `server/middleware/oauth-session.ts` | À chaque requête : session révoquée ? jeton à renouveler ? |
| `server/api/me.get.ts` | Exemple d'API protégée qui utilise l'access token |
| `app/middleware/auth.ts` | Middleware de route : pages réservées aux utilisateurs connectés |
| `app/pages/` | Accueil (public) et profil (protégé) |

## Lancer en local

1. Serveur OAuth2 démarré (`make start` à la racine du dépôt) et application déclarée en **client confidentiel** :
   ```bash
   make console c='app:application:create "Nuxt" --id=nuxt --home-url=http://localhost:3000/ --redirect-uri=http://localhost:3000/auth/callback --backchannel-logout-uri=http://host.docker.internal:3000/auth/backchannel-logout'
   make grant email=moi@example.com app=nuxt
   ```
2. Configuration :
   ```bash
   cp .env.example .env     # renseignez NUXT_OAUTH_CLIENT_SECRET, NUXT_SESSION_PASSWORD (openssl rand -hex 32)
                            # et NUXT_OAUTH_PUBLIC_KEY (sortie de : make public-key)
   npm install
   npm run dev              # http://localhost:3000
   ```

En production : `npm run build` puis `node .output/server/index.mjs`, avec les mêmes variables d'environnement
(`NUXT_OAUTH_SERVER_URL=https://oauth.mydomain.com`, redirect URI en `https://`).
