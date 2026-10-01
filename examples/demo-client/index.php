<?php

/**
 * Application de démonstration : un client OAuth2 minimal, sans dépendance, pour tester le serveur
 * de bout en bout et servir d'exemple d'intégration (voir docs/integrer-une-application.md).
 *
 * Lancement : make demo   (ou : php -S 127.0.0.1:8081 examples/demo-client/index.php)
 *
 * Variables d'environnement :
 *   OAUTH_PUBLIC_URL    URL du serveur vue par le navigateur      (http://localhost:8080)
 *   OAUTH_INTERNAL_URL  URL du serveur vue par ce backend         (identique par défaut ; http://php:8080 sous Docker)
 *   CLIENT_ID           client ID de l'application                (demo)
 *   CLIENT_SECRET       client secret (vide = client public, PKCE seul)
 *   REDIRECT_URI        callback déclaré dans l'admin              (http://localhost:8081/callback)
 *   OAUTH_PUBLIC_KEY    clé publique du serveur pour vérifier le JWT localement (facultatif)
 *
 * NE PAS utiliser tel quel en production : pas de gestion d'erreurs réseau avancée, pas de HTTPS.
 */

declare(strict_types=1);

$env = static fn (string $name, string $default = ''): string => (string) (getenv($name) ?: $default);

$publicUrl = rtrim($env('OAUTH_PUBLIC_URL', 'http://localhost:8080'), '/');
$internalUrl = rtrim($env('OAUTH_INTERNAL_URL', $publicUrl), '/');
$clientId = $env('CLIENT_ID', 'demo');
$clientSecret = $env('CLIENT_SECRET');
$redirectUri = $env('REDIRECT_URI', 'http://localhost:8081/callback');
$publicKeyFile = $env('OAUTH_PUBLIC_KEY');
$homeUrl = substr($redirectUri, 0, (int) strrpos($redirectUri, '/')).'/';

session_name('DEMOSESSID');
session_start();

/** Encodage base64url (RFC 7636). */
function b64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** Appel POST de serveur à serveur vers /token. */
function postToken(string $url, array $params): array
{
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
        'content' => http_build_query($params),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $context);

    return \is_string($body) ? (json_decode($body, true) ?? ['error' => 'invalid_response', 'error_description' => $body])
        : ['error' => 'network_error', 'error_description' => 'Serveur OAuth2 injoignable : '.$url];
}

/** Appel GET avec le jeton d'accès. Retourne [code HTTP, données]. */
function getWithToken(string $url, string $accessToken): array
{
    $context = stream_context_create(['http' => [
        'header' => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $context);
    preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? '', $m);

    return [(int) ($m[1] ?? 0), \is_string($body) ? json_decode($body, true) : null];
}

/** Décode le JWT et vérifie sa signature RS256 si la clé publique est disponible. */
function inspectJwt(string $jwt, string $publicKeyFile): array
{
    $parts = explode('.', $jwt);
    if (3 !== \count($parts)) {
        return ['claims' => [], 'signature' => 'jeton illisible'];
    }
    $decode = static fn (string $p): string => (string) base64_decode(strtr($p, '-_', '+/'));
    $claims = json_decode($decode($parts[1]), true) ?: [];
    $signature = 'non vérifiée (OAUTH_PUBLIC_KEY non défini)';
    if ('' !== $publicKeyFile && is_readable($publicKeyFile)) {
        $ok = 1 === openssl_verify($parts[0].'.'.$parts[1], $decode($parts[2]), (string) file_get_contents($publicKeyFile), \OPENSSL_ALGO_SHA256);
        $signature = $ok ? 'valide (clé publique du serveur)' : 'INVALIDE';
    }

    return ['claims' => $claims, 'signature' => $signature];
}

function storeTokens(array $tokens): void
{
    $_SESSION['tokens'] = [
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'] ?? null,
        'expires_at' => time() + (int) ($tokens['expires_in'] ?? 0),
    ];
}

function h(mixed $value): string
{
    return htmlspecialchars(\is_string($value) ? $value : (string) json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), \ENT_QUOTES);
}

function redirect(string $url): never
{
    header('Location: '.$url, true, 302);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

switch ($path) {
    // 1. Redirection vers le serveur, avec state (anti-CSRF) et PKCE
    case '/login':
        $_SESSION['oauth_state'] = b64url(random_bytes(16));
        $_SESSION['pkce_verifier'] = b64url(random_bytes(48));
        redirect($publicUrl.'/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'profile email',
            'state' => $_SESSION['oauth_state'],
            'code_challenge' => b64url(hash('sha256', $_SESSION['pkce_verifier'], true)),
            'code_challenge_method' => 'S256',
        ]));

    // 2. Retour du serveur : vérification du state, puis échange du code (serveur à serveur)
    case parse_url($redirectUri, \PHP_URL_PATH):
        $expectedState = $_SESSION['oauth_state'] ?? null;
        unset($_SESSION['oauth_state']);
        if (isset($_GET['error'])) {
            $_SESSION['flash'] = 'Erreur renvoyée par le serveur : '.$_GET['error'].' – '.($_GET['error_description'] ?? '');
            redirect('/');
        }
        if (null === $expectedState || !hash_equals($expectedState, (string) ($_GET['state'] ?? ''))) {
            $_SESSION['flash'] = 'State invalide : requête refusée (protection CSRF).';
            redirect('/');
        }
        $tokens = postToken($internalUrl.'/token', array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => (string) ($_GET['code'] ?? ''),
            'code_verifier' => $_SESSION['pkce_verifier'] ?? '',
        ]));
        unset($_SESSION['pkce_verifier']);
        if (!isset($tokens['access_token'])) {
            $_SESSION['flash'] = 'Échange du code refusé : '.json_encode($tokens, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
            redirect('/');
        }
        storeTokens($tokens);
        // Identité de l'utilisateur : on interroge /api/userinfo une fois, puis on garde son « id » en session
        [, $_SESSION['user']] = getWithToken($internalUrl.'/api/userinfo', $tokens['access_token']);
        session_regenerate_id(true);
        redirect('/');

    // 3. Renouvellement du jeton d'accès avec le refresh token (le refresh token est remplacé)
    case '/refresh':
        $refresh = $_SESSION['tokens']['refresh_token'] ?? null;
        if (null === $refresh) {
            redirect('/');
        }
        $tokens = postToken($internalUrl.'/token', array_filter([
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
        ]));
        if (isset($tokens['access_token'])) {
            storeTokens($tokens);
            $_SESSION['flash'] = 'Jetons renouvelés.';
        } else {
            // Accès retiré, utilisateur bloqué ou refresh token expiré : on déconnecte localement
            unset($_SESSION['tokens'], $_SESSION['user']);
            $_SESSION['flash'] = 'Renouvellement refusé ('.($tokens['error'] ?? '?').') : reconnectez-vous.';
        }
        redirect('/');

    // 4. Déconnexion locale puis globale (SSO)
    case '/logout':
        session_destroy();
        redirect($publicUrl.'/logout?'.http_build_query(['redirect_uri' => $homeUrl]));
}

// Page d'accueil
$tokens = $_SESSION['tokens'] ?? null;
$userinfo = null;
$jwt = null;
if (null !== $tokens) {
    $jwt = inspectJwt($tokens['access_token'], $publicKeyFile);
    $userinfo = getWithToken($internalUrl.'/api/userinfo', $tokens['access_token']);
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Application de démonstration</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 860px; margin: 2rem auto; padding: 0 1rem; color: #1f2937; background: #fafafa; }
        h1 { font-size: 1.5rem; } h2 { font-size: 1.1rem; margin-top: 2rem; }
        .btn { display: inline-block; padding: .55rem 1rem; border-radius: .4rem; background: #2563eb; color: #fff; text-decoration: none; margin: .2rem .4rem .2rem 0; }
        .btn.secondary { background: #6b7280; }
        .flash { padding: .75rem 1rem; background: #fef3c7; border-radius: .4rem; }
        pre { background: #111827; color: #e5e7eb; padding: 1rem; border-radius: .4rem; overflow-x: auto; font-size: .85rem; }
        table { border-collapse: collapse; } td { padding: .25rem .75rem .25rem 0; vertical-align: top; }
        .ok { color: #047857; } .ko { color: #b91c1c; }
    </style>
</head>
<body>
<h1>Application de démonstration</h1>
<p>Client <code><?= h($clientId) ?></code> (<?= '' === $clientSecret ? 'client public + PKCE' : 'client confidentiel + PKCE' ?>)
   — serveur <code><?= h($publicUrl) ?></code></p>

<?php if ($flash) { ?><p class="flash"><?= h($flash) ?></p><?php } ?>

<?php if (null === $tokens) { ?>
    <p>Vous n'êtes pas connecté à cette application.</p>
    <p><a class="btn" href="/login">Se connecter avec le serveur OAuth2</a></p>
<?php } else { ?>
    <p>Connecté en tant que <strong><?= h($_SESSION['user']['name'] ?? $jwt['claims']['name'] ?? '?') ?></strong>
       (<?= h($_SESSION['user']['email'] ?? $jwt['claims']['email'] ?? '?') ?>, id <?= h((string) ($_SESSION['user']['id'] ?? '?')) ?>).</p>
    <p>
        <a class="btn" href="/">Rappeler /api/userinfo</a>
        <a class="btn" href="/refresh">Renouveler les jetons</a>
        <a class="btn secondary" href="/logout">Se déconnecter (partout)</a>
    </p>

    <h2>Jeton d'accès (JWT)</h2>
    <table>
        <tr><td>Signature</td><td class="<?= str_starts_with($jwt['signature'], 'valide') ? 'ok' : '' ?>"><?= h($jwt['signature']) ?></td></tr>
        <tr><td>Expire</td><td><?= isset($jwt['claims']['exp']) ? date('H:i:s', (int) $jwt['claims']['exp']).' ('.max(0, (int) $jwt['claims']['exp'] - time()).' s)' : '?' ?></td></tr>
    </table>
    <pre><?= h($jwt['claims']) ?></pre>

    <h2>Réponse de /api/userinfo</h2>
    <p class="<?= 200 === $userinfo[0] ? 'ok' : 'ko' ?>">HTTP <?= $userinfo[0] ?>
        <?= 200 === $userinfo[0] ? '' : '— accès retiré, compte bloqué ou jeton expiré : renouvelez ou reconnectez-vous' ?></p>
    <pre><?= h($userinfo[1]) ?></pre>
<?php } ?>
</body>
</html>
