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

- PHP 8.2+ (testé en 8.4), Symfony 7.4 LTS, Doctrine ORM, PostgreSQL 16
- EasyAdmin 5 pour l'administration (`/admin`)
- Docker (FrankenPHP) pour le déploiement

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

## Déploiement avec Docker

```bash
git clone <ce dépôt> oauth && cd oauth
cp .env.docker.dist .env.docker
# Remplir .env.docker : mots de passe / secrets (openssl rand -hex 32)
docker compose up -d --build

# Premier administrateur
docker compose exec oauth php bin/console app:user:create admin@mydomain.com "Admin" --admin
```

Au premier démarrage, le conteneur génère la paire de clés RSA (volume `oauth_keys`, **à sauvegarder** avec
la base) et applique les migrations.

Le service écoute sur `127.0.0.1:8080` ; placez-le derrière votre reverse proxy HTTPS. Exemple Nginx :

```nginx
server {
    listen 443 ssl http2;
    server_name oauth.mydomain.com;
    # ssl_certificate ... (Let's Encrypt)

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Port $server_port;
    }
}
```

Avec Caddy : `oauth.mydomain.com { reverse_proxy 127.0.0.1:8080 }`.

### Installation sans Docker

```bash
composer install --no-dev --optimize-autoloader
# Créer .env.local avec APP_ENV=prod, APP_SECRET, DATABASE_URL, OAUTH_PASSPHRASE, OAUTH_ENCRYPTION_KEY
php bin/console league:oauth2-server:generate-keypair
php bin/console doctrine:migrations:migrate
php bin/console app:user:create admin@mydomain.com "Admin" --admin
```

Document root : `public/`. Pensez à une tâche cron pour purger les jetons expirés :
`php bin/console league:oauth2-server:clear-expired-tokens`.

## Déclarer une application

Dans `/admin` → **Applications** → **Créer**, ou en ligne de commande :

```bash
php bin/console app:application:create "Application 1" \
    --id=app1 \
    --home-url=https://app1.mydomain.com \
    --redirect-uri=https://app1.mydomain.com/oauth/callback
```

Le **client secret** n'est affiché qu'une seule fois (il est stocké haché). Il peut être régénéré depuis l'admin.
Cochez « Client public » pour une SPA ou une application mobile (pas de secret, PKCE obligatoire).

Donner l'accès : `/admin` → **Utilisateurs** → éditer → *Applications autorisées*, ou :

```bash
php bin/console app:access:grant alice@mydomain.com app1            # donner l'accès
php bin/console app:access:grant alice@mydomain.com app1 --revoke   # retirer l'accès
```

## Personnaliser la page de connexion d'une application

Dans `/admin` → **Applications** → éditer, section **Personnalisation de la page de connexion** :

- **Logo** (PNG, JPEG, WebP ou GIF, 1 Mo max ; le SVG est refusé car il peut contenir du script),
- **Couleur principale** (boutons, liens) et **couleur de fond**,
- **Message d'accueil** affiché sous le titre.

La personnalisation s'applique aux pages de connexion, d'inscription et « accès refusé » dès que l'utilisateur
arrive depuis cette application (le serveur retrouve l'application grâce au `client_id` de la demande
`/authorize`). Le bouton **Aperçu de la page de connexion** montre le résultat sans se déconnecter.
Les logos sont stockés dans `public/uploads/logos/` (volume Docker `oauth_uploads`, à sauvegarder).

## Le callback (redirect URI)

Le « callback » est la **redirect URI** OAuth2 : l'URL de l'application vers laquelle le serveur renvoie
l'utilisateur après connexion, avec `?code=…&state=…`. Chaque application déclare ses redirect URIs dans
l'admin ; toute autre URL est refusée. L'application traite ce callback en échangeant le code contre un jeton
(voir ci-dessous).

## Brancher une application

Paramètres OAuth2 à configurer dans l'application :

| Paramètre | Valeur |
|---|---|
| Authorization URL | `https://oauth.mydomain.com/authorize` |
| Token URL | `https://oauth.mydomain.com/token` |
| User info URL | `https://oauth.mydomain.com/api/userinfo` |
| Client ID / Secret | ceux affichés à la création |
| Redirect URI | une des URI déclarées pour l'application |
| Scopes | `profile email` |

Déroulé :

1. L'application redirige l'utilisateur vers
   `https://oauth.mydomain.com/authorize?response_type=code&client_id=app1&redirect_uri=https://app1.mydomain.com/oauth/callback&scope=profile%20email&state=<aléatoire>`
2. L'utilisateur se connecte (ou s'inscrit). S'il n'a pas accès à l'application, il voit le message
   « Vous n'avez pas accès » et le flux s'arrête là.
3. S'il a accès, il est renvoyé vers `redirect_uri?code=…&state=…`.
4. Le backend de l'application échange le code :
   ```bash
   curl -X POST https://oauth.mydomain.com/token \
     -d grant_type=authorization_code -d client_id=app1 -d client_secret=... \
     -d redirect_uri=https://app1.mydomain.com/oauth/callback -d code=...
   ```
   → `access_token` (JWT, 15 min par défaut), `refresh_token` (1 mois).
5. Il récupère l'utilisateur :
   ```bash
   curl -H "Authorization: Bearer <access_token>" https://oauth.mydomain.com/api/userinfo
   # {"sub":"alice@mydomain.com","id":42,"name":"Alice","email":"alice@mydomain.com"}
   ```
   Utilisez `id` comme identifiant stable de l'utilisateur. `/api/userinfo` renvoie **403** si l'accès a été
   retiré entre-temps. Le JWT peut aussi être vérifié localement avec la clé publique
   (`config/jwt/public.pem`) : il contient `sub`, `uid`, `email`, `name`, `aud` (= client ID).

Exemple en PHP avec [league/oauth2-client](https://oauth2-client.thephpleague.com/) :

```php
$provider = new \League\OAuth2\Client\Provider\GenericProvider([
    'clientId'                => 'app1',
    'clientSecret'            => '...',
    'redirectUri'             => 'https://app1.mydomain.com/oauth/callback',
    'urlAuthorize'            => 'https://oauth.mydomain.com/authorize',
    'urlAccessToken'          => 'https://oauth.mydomain.com/token',
    'urlResourceOwnerDetails' => 'https://oauth.mydomain.com/api/userinfo',
    'scopes'                  => 'profile email',
    'scopeSeparator'          => ' ',
]);
```

Dans une application Symfony, [knpuniversity/oauth2-client-bundle](https://github.com/knpuniversity/oauth2-client-bundle)
avec le provider `generic` fonctionne directement.

**Déconnexion globale** : redirigez vers
`https://oauth.mydomain.com/logout?redirect_uri=https://app1.mydomain.com/`. La redirection n'est acceptée que
vers l'origine (schéma + domaine) d'une application déclarée.

## Développement

```bash
composer install
php bin/console league:oauth2-server:generate-keypair   # utilise OAUTH_PASSPHRASE de .env.dev
php bin/console doctrine:schema:create                  # SQLite en dev (var/data_dev.db)
php bin/console app:user:create admin@example.com Admin --admin
symfony serve   # ou : php -S 127.0.0.1:8000 -t public
```

Tests (SQLite, clés générées automatiquement) :

```bash
php bin/phpunit
```

Pour lancer les tests sur PostgreSQL : `DATABASE_URL="postgresql://…/app_test?serverVersion=16" php bin/phpunit`.

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
Pour tester l'envoi : `php bin/console mailer:test votre@adresse.fr`.

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

En ligne de commande : `php bin/console app:user:revoke alice@mydomain.com` (bloquer) ou
`app:user:revoke alice@mydomain.com --logout-only`. Retirer une application à un utilisateur révoque aussi ses
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

Pistes d'évolution : changement de mot de passe depuis le portail, journal d'audit des connexions,
OpenID Connect complet (id_token, discovery).
