# Tests

← [Documentation](README.md)

Le projet se teste à trois niveaux, tous automatisés et exécutés par l'intégration continue à chaque push.

| Niveau | Commande | Durée | Ce qui est vérifié |
|---|---|---|---|
| Tests fonctionnels (SQLite) | `make test` | ~10 s | 53 scénarios sur l'application Symfony (voir ci-dessous) |
| Mêmes tests sur PostgreSQL | `make test-pg` | ~15 s | idem, sur la vraie base, après les migrations |
| De bout en bout | `make test-e2e` | ~10 s | un vrai navigateur simulé, à travers l'application de démonstration, dans Docker |

## Tests fonctionnels (PHPUnit)

```bash
make test                          # tous les tests
make test c="--filter MfaTest"     # une classe
make test c="--filter testFullAuthorizationCodeFlow"
```

Sans Docker : `php bin/phpunit`. Les tests utilisent SQLite et une paire de clés RSA générée automatiquement
(`var/test-keys`) ; la base est recréée avant chaque test. Les e-mails ne partent pas : les codes MFA sont lus en
base.

| Fichier (`tests/Functional`) | Ce qui est couvert |
|---|---|
| `OAuthFlowTest` | Flux complet code → jetons → `/api/userinfo` → renouvellement ; page « accès refusé » ; révocation ; mauvais secret ; redirection après déconnexion limitée aux applications déclarées |
| `BackchannelLogoutTest` | Back-channel logout : applications prévenues lors d'un blocage (admin), d'un « Déconnecter partout » ou d'un retrait d'accès (seule l'application concernée), compte supprimé, logout token conforme à la spécification (signature RS256, `iss`, `aud`, `uid`, `events`, `typ`, durée de vie), nouvelles tentatives sans bloquer l'administration si l'application est en panne, commande de test, validation de l'URL |
| `ClientIntegrationTest` | Ce dont dépendent les applications : PKCE obligatoire pour les clients publics, rotation des refresh tokens, vérification du JWT avec la clé publique (RS256, `aud`, `exp`), scopes, authentification HTTP Basic, application désactivée |
| `MfaTest` | Envoi du code, code erroné ou expiré, anti-bruteforce (y compris depuis plusieurs IP), renvoi, appareils de confiance |
| `RegistrationTest` | Inscription, adresse déjà utilisée (« déjà inscrit »), inscription ouverte ou non |
| `ResetPasswordTest` | Mot de passe oublié complet, lien à usage unique, expiration, comptes inconnus ou bloqués, limitation |
| `RevocationTest` | « Déconnecter partout », blocage, en-têtes de sécurité |
| `AdminTest` | Accès à l'admin, gestion des utilisateurs et applications, portail |
| `BrandingTest` | Personnalisation des pages par application, envoi de logo, validations |

### Sur PostgreSQL

```bash
make test-pg
```

Crée la base `app_test` dans le conteneur PostgreSQL de dev et y lance les mêmes tests. Sans Docker :
`DATABASE_URL="postgresql://user:pass@127.0.0.1:5432/app_test?serverVersion=16" php bin/phpunit`.

## Test de bout en bout

```bash
make up          # environnement de dev démarré
make test-e2e
```

Le script `tests/e2e/oauth-flow.sh` crée un utilisateur de test, lance l'application de démonstration et joue le
parcours d'un utilisateur avec `curl`, exactement comme un navigateur (cookies, redirections, formulaires) :

```
Scénario OAuth2 : http://localhost:8081 → http://localhost:8080 (e2e-1790844138@example.test)
  ✓ la démo redirige vers /authorize avec PKCE
  ✓ utilisateur non connecté : page de connexion
  ✓ mot de passe accepté
  ✓ code MFA envoyé
  ✓ retour vers la démo avec un code
  ✓ échange du code (state + PKCE)
  ✓ utilisateur connecté dans la démo
  ✓ signature du JWT valide
  ✓ /api/userinfo répond 200
  ✓ renouvellement des jetons
  ✓ accès retiré : session de la démo fermée immédiatement (back-channel logout)
  ✓ SSO : reconnexion sans mot de passe
  ✓ déconnexion globale, retour vers la démo
  ✓ session du serveur fermée
OK
```

Il vérifie ce que les tests PHPUnit ne voient pas : l'image Docker, FrankenPHP, la base PostgreSQL, l'envoi
d'e-mail vers Mailpit, la communication entre deux conteneurs (le navigateur parle à `localhost:8080`, le backend
de la démo à `php:8080`) et un vrai client OAuth2.

## Tester à la main

L'application de démonstration permet de tout essayer dans un navigateur :
voir [Installation – développement](installation-developpement.md#4-essayer-avec-lapplication-de-démonstration).
Les e-mails arrivent dans Mailpit (<http://localhost:8025>).

## Intégration continue

`.github/workflows/ci.yml`, à chaque push et pull request :

| Job | Étapes |
|---|---|
| **Tests** | `composer validate` et `composer audit` (failles connues), lint du conteneur, des templates et du YAML, tests sur SQLite, migrations et tests sur PostgreSQL 16 |
| **Image Docker (prod)** | construction de l'image de production, taille, validation du Caddyfile, démarrage de la stack de production complète |
| **Test de bout en bout** | environnement de dev + application de démonstration + `make test-e2e` |
| **Exemple Nuxt** | vérification des types et build de `examples/nuxt-client` (testé de bout en bout lors de son écriture, voir [Adapter une application](adapter-une-application.md#1-nuxt-ssr)) |

Dependabot (`.github/dependabot.yml`) propose chaque semaine les mises à jour de l'image de base, des dépendances
PHP, de l'exemple Nuxt et des actions GitHub ; la CI les valide avant fusion.

## Écrire un test

Les tests fonctionnels héritent de `tests/Functional/FunctionalTestCase.php`, qui fournit :

- `createApplication()` / `createUser(applications: [...], roles: [...])` ;
- `login($email)` : connexion complète, code MFA compris ;
- `authorizeUrl()` : URL `/authorize` de l'application de test.

```php
public function testMonScenario(): void
{
    $application = $this->createApplication();
    $this->createUser(applications: [$application]);

    $this->client->request('GET', $this->authorizeUrl());
    $this->login();
    $this->client->followRedirect();

    self::assertResponseRedirects();
}
```
