# Serveur OAuth2 (SSO) – Symfony

Serveur d'authentification centralisé pour vos applications auto-hébergées :

```
oauth.mydomain.com   ← ce serveur : connexion, inscription, administration
app1.mydomain.com    ← vos applications, qui délèguent la connexion à oauth.mydomain.com
app2.mydomain.com
```

## Principe

- **Un seul compte par personne**, commun à toutes les applications (SSO : une fois connecté sur
  `oauth.mydomain.com`, l'utilisateur passe d'une application à l'autre sans ressaisir son mot de passe).
- **Inscription** : si quelqu'un essaie de s'inscrire (depuis n'importe quelle application) avec une
  adresse déjà utilisée, il est redirigé vers la page de connexion avec le message
  *« Vous êtes déjà inscrit avec cette adresse e-mail. Le même compte sert pour toutes les applications : connectez-vous. »*
- **Contrôle d'accès par application** : un administrateur attribue des applications à chaque utilisateur.
  Un utilisateur connecté qui ouvre une application qui ne lui est pas attribuée voit
  *« Vous n'avez pas accès à l'application X »* et n'est pas renvoyé vers l'application.
- Option par application **« Inscription ouverte »** : si elle est cochée, une personne qui crée son compte
  depuis cette application y a accès immédiatement ; sinon (par défaut) un admin doit lui donner l'accès.
- Retirer un accès (ou désactiver un compte) **révoque les jetons** en cours de l'utilisateur pour l'application.

Protocole : OAuth 2.0 *Authorization Code* (+ PKCE pour les clients publics) et *Refresh Token*, basé sur
[league/oauth2-server-bundle](https://github.com/thephpleague/oauth2-server-bundle). Les jetons d'accès sont des JWT
signés RS256 contenant l'e-mail et le nom de l'utilisateur.

## Stack

- PHP 8.4, Symfony 7.4 LTS, Doctrine ORM, PostgreSQL 16
- EasyAdmin 5 pour l'administration (`/admin`)
- Docker : image [FrankenPHP](https://frankenphp.dev) (Caddy + PHP) en **mode worker**, Docker Compose, Makefile
- Feuille de route : [ROADMAP.md](ROADMAP.md)

## Pages et endpoints

| URL | Rôle |
|---|---|
| `GET /authorize` | Point d'entrée OAuth2 (les applications y redirigent l'utilisateur) |
| `POST /token` | Échange code → jetons, rafraîchissement (appelé par le backend de l'application) |
| `GET /api/userinfo` | Infos de l'utilisateur (`Authorization: Bearer <access_token>`) |
| `GET /login`, `/register` | Connexion / inscription |
| `GET /2fa` | Saisie du code MFA reçu par e-mail |
| `GET /reset-password` | Mot de passe oublié |
| `GET /logout?redirect_uri=…` | Déconnexion globale, puis retour vers l'application |
| `GET /` | Portail : liste des applications de l'utilisateur |
| `/admin` | Administration (utilisateurs, applications, accès) – rôle `ROLE_ADMIN` |

## Docker

### Architecture

```
Internet ──HTTPS──▶ Caddy (machine hôte, certificats Let's Encrypt)
                      │  reverse_proxy 127.0.0.1:8080
                      ▼
                 ┌──────────── docker compose ─────────────┐
                 │  php       FrankenPHP (Caddy + PHP 8.4)  │
                 │            mode worker, utilisateur app  │
                 │     │                                     │
                 │  database  PostgreSQL 16                 │
                 └──────────────────────────────────────────┘
   volumes : database_data · oauth_keys (clés RSA) · oauth_uploads (logos) · caddy_data/config
```

| Fichier | Rôle |
|---|---|
| `Dockerfile` | Image multi-étapes : `frankenphp_base` → `frankenphp_dev` / `frankenphp_prod` |
| `compose.yaml` | Services communs (php + PostgreSQL), volumes |
| `compose.override.yaml` | Dev (chargé automatiquement) : code monté, Mailpit, ports configurables |
| `compose.prod.yaml` | Prod : image optimisée, `.env.docker`, écoute sur 127.0.0.1 uniquement |
| `frankenphp/` | Caddyfile interne, mode worker, `php.ini`, script de démarrage |
| `deploy/caddy/Caddyfile` | Exemple de configuration Caddy pour la machine hôte |
| `Makefile` | Raccourcis dev et prod (`make help`) |

L'image de production :
- **mode worker FrankenPHP** : Symfony reste chargé en mémoire, quelques millisecondes par requête ;
- dépendances sans outils de dev, autoload « classmap authoritative », `.env` compilé (`composer dump-env`),
  cache Symfony préchauffé à la construction, OPcache sans vérification des fichiers, APCu ;
- construction en couches (les dépendances ne sont réinstallées que si `composer.lock` change, cache Composer
  partagé entre les builds) ;
- exécution en **utilisateur non-root** (`app`), `no-new-privileges`, journaux Docker limités en taille ;
- compression zstd/brotli/gzip, en-tête `Server` masqué, jetons et codes masqués dans les journaux d'accès ;
- `HEALTHCHECK` intégré ; au démarrage : vérification des secrets obligatoires, génération des clés RSA si absentes,
  attente de PostgreSQL, **migrations appliquées automatiquement**.

### Tests

```bash
make test                 # dans le conteneur de dev (SQLite)
make test-pg              # sur la base PostgreSQL du conteneur
php bin/phpunit           # sans Docker
```

## Double authentification (MFA) par e-mail

À la connexion, après le mot de passe, un **code à 6 chiffres** est envoyé par e-mail (valable 10 minutes, à usage
unique). L'utilisateur peut cocher **« Faire confiance à cet appareil »** (coché par défaut) : ce navigateur ne
redemandera plus de code pendant `MFA_TRUSTED_DEVICE_LIFETIME` (30 jours par défaut). Un nouveau code est exigé :

- sur un nouvel appareil ou navigateur, ou après expiration de la période de confiance ;
- après un changement de mot de passe (par l'admin) ;
- après un « Déconnecter partout » ou un blocage.

À l'inscription, le code sert aussi à **vérifier l'adresse e-mail**. Protection anti-bruteforce : 5 essais par
15 minutes (par identifiant/IP, et par compte quelle que soit l'IP), puis le code est invalidé. Renvoi de code limité
à 3 par quart d'heure.

### Configuration de l'envoi (Brevo)

Variables d'environnement (`.env.docker` ou `.env.local`) :

```dotenv
# Clé API Brevo (SMTP & API > Clés API)
MAILER_DSN=brevo+api://VOTRE_CLE_API@default
# ou via SMTP : MAILER_DSN=brevo+smtp://LOGIN_SMTP:CLE_SMTP@default

# Expéditeur : une adresse déjà validée dans Brevo
MAILER_FROM_EMAIL=no-reply@mydomain.com
MAILER_FROM_NAME="Mon compte"

MFA_ENABLED=1                       # 0 pour désactiver la MFA
MFA_TRUSTED_DEVICE_LIFETIME=2592000 # secondes (30 jours)
```

L'expéditeur est appliqué à tous les e-mails dans `config/packages/mailer.yaml`, qui lit ces variables.
Pour tester l'envoi : `make prod-mailtest to=votre@adresse.fr`. En dev, les e-mails arrivent dans Mailpit.

## Mot de passe oublié

Lien **« Mot de passe oublié ? »** sur la page de connexion :

1. L'utilisateur saisit son e-mail. La réponse est toujours la même (« si un compte existe, un e-mail a été
   envoyé ») pour ne pas révéler quelles adresses sont inscrites ; rien n'est envoyé à un compte bloqué.
2. Il reçoit un lien valable **1 heure**, utilisable **une seule fois** (jeton stocké haché en base).
   Un seul e-mail par compte toutes les 15 minutes, 5 demandes par quart d'heure par IP.
3. Il choisit un nouveau mot de passe. Par sécurité, **toutes ses sessions sont fermées**, ses appareils de
   confiance oubliés et ses jetons OAuth2 révoqués ; il se reconnecte avec le nouveau mot de passe + un code MFA.

S'il venait d'une application, il y est renvoyé après sa reconnexion (si le lien est ouvert dans le même navigateur).
Durées réglables dans `config/packages/reset_password.yaml`.

## Révoquer un utilisateur

Dans `/admin` → **Utilisateurs**, deux actions (sur la liste et la fiche) :

| Action | Effet |
|---|---|
| **Déconnecter partout** | Ferme immédiatement toutes ses sessions web (y compris « rester connecté »), oublie ses appareils de confiance MFA et révoque tous ses jetons OAuth2 (access et refresh). Le compte reste actif. |
| **Bloquer immédiatement** | Idem, et désactive le compte : plus aucune connexion possible. |

En ligne de commande : `make prod-revoke email=alice@mydomain.com` (bloquer), ou
`make prod-console c="app:user:revoke alice@mydomain.com --logout-only"`. Retirer une application à un utilisateur révoque aussi ses
jetons pour cette application.

Délai d'effet côté applications : immédiat pour celles qui appellent `/api/userinfo` ou rafraîchissent leur jeton ;
pour celles qui se contentent de vérifier la signature du JWT localement, au plus la durée de vie de l'access token
(`OAUTH_ACCESS_TOKEN_TTL`, 15 minutes par défaut).

## Sécurité

- Mots de passe hachés (bcrypt/argon2 via `auto`), secrets clients hachés.
- MFA par e-mail avec appareils de confiance (voir plus haut).
- Limitation des tentatives de connexion et de code MFA (5 / 15 min), des inscriptions (5 / h par IP).
- CSRF sur la connexion, la MFA et les formulaires ; cookies de session `HttpOnly`, `SameSite=Lax`, `Secure` en HTTPS.
- En-têtes de sécurité : `Content-Security-Policy`, `X-Frame-Options: DENY` (anti-clickjacking),
  `X-Content-Type-Options`, `Referrer-Policy`. **Activez HSTS sur votre reverse proxy.**
- Révocation immédiate des sessions, access tokens courts (15 min) et refresh tokens révocables.
- Pas d'écran de consentement : les applications sont les vôtres (first-party), l'accès est décidé par l'admin.
- Redirect URIs vérifiées strictement ; PKCE obligatoire pour les clients publics ; redirection après déconnexion
  limitée aux applications déclarées.
- Logos : formats image uniquement (SVG refusé).
- `APP_SECRET` doit faire **au moins 32 caractères** (il signe les cookies d'appareil de confiance) :
  `openssl rand -hex 32`.

Prochaines fonctionnalités et idées : voir [ROADMAP.md](ROADMAP.md).
