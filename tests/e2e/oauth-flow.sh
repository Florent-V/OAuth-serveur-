#!/usr/bin/env bash
# Test de bout en bout du serveur, à travers l'application de démonstration (comme un navigateur).
#
#   make test-e2e        (lance la démo, crée un utilisateur de test et déroule le scénario)
#
# Usage direct : tests/e2e/oauth-flow.sh <url serveur> <url démo> <email> <mot de passe>
#   avec deux variables d'environnement :
#   E2E_CONSOLE  commande console Symfony, ex. "docker compose exec -T php php bin/console"
#   E2E_SQL      commande shell qui exécute la requête SQL reçue en $1 et affiche le résultat (lecture du code MFA),
#                ex. 'docker compose exec -T database psql -U app -d app -tAc "$1"'
set -uo pipefail

SERVER=$1 DEMO=$2 EMAIL=$3 PASSWORD=$4
console() { sh -c "$E2E_CONSOLE \"\$@\"" sh "$@"; }
sql() { sh -c "$E2E_SQL" sh "$1"; }
JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
FAILED=0

curl_() { curl -s -c "$JAR" -b "$JAR" "$@"; }
location() { curl_ -o /dev/null -w '%{redirect_url}' "$@"; }
csrf() { curl_ "$1" | grep -o 'name="_csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//'; }
check() { # check <description> <condition>
    if eval "$2"; then echo "  ✓ $1"; else echo "  ✗ $1"; FAILED=1; fi
}

echo "Scénario OAuth2 : $DEMO → $SERVER ($EMAIL)"

url=$(location "$DEMO/login")
check "la démo redirige vers /authorize avec PKCE" '[[ $url == "$SERVER/authorize?"*code_challenge=* ]]'
url=$(location "$url")
check "utilisateur non connecté : page de connexion" '[[ $url == "$SERVER/login" ]]'

# Origin : exigé par la protection CSRF de Symfony (les navigateurs l'envoient d'office)
url=$(location -H "Origin: $SERVER" -d "_username=$EMAIL&_password=$PASSWORD&_csrf_token=$(csrf "$SERVER/login")" "$SERVER/login")
check "mot de passe accepté" '[[ $url == "$SERVER/authorize?"* ]]'
url=$(location "$url")
if [[ $url == "$SERVER/2fa" ]]; then
    code=$(sql "SELECT email_auth_code FROM \"user\" WHERE email = '$EMAIL'" | tr -d '[:space:]')
    check "code MFA envoyé" '[[ $code =~ ^[0-9]{6}$ ]]'
    url=$(location -H "Origin: $SERVER" -d "_auth_code=$code&_trusted=1&_csrf_token=$(csrf "$SERVER/2fa")" "$SERVER/2fa_check")
    url=$(location "$url")
fi
check "retour vers la démo avec un code" '[[ $url == "$DEMO/callback?code="* ]]'
url=$(location "$url")
check "échange du code (state + PKCE)" '[[ $url == "$DEMO/" ]]'

page=$(curl_ "$DEMO/")
check "utilisateur connecté dans la démo" '[[ $page == *"Connecté en tant que"* ]]'
check "signature du JWT valide" '[[ $page == *"valide (clé publique du serveur)"* ]]'
check "/api/userinfo répond 200" '[[ $page == *"HTTP 200"* ]]'

curl_ -o /dev/null "$DEMO/refresh"
check "renouvellement des jetons" '[[ $(curl_ "$DEMO/") == *"Jetons renouvelés"* ]]'

console app:access:grant "$EMAIL" demo --revoke -q
page=$(curl_ "$DEMO/")
check "accès retiré : /api/userinfo refusé immédiatement" '[[ $page == *"HTTP 401"* ]]'
curl_ -o /dev/null "$DEMO/refresh"
check "accès retiré : renouvellement refusé" '[[ $(curl_ "$DEMO/") == *"Renouvellement refusé"* ]]'
console app:access:grant "$EMAIL" demo -q

url=$(location "$(location "$DEMO/login")")
check "SSO : reconnexion sans mot de passe" '[[ $url == "$DEMO/callback?code="* ]]'
curl_ -o /dev/null "$url"

url=$(location "$(location "$DEMO/logout")")
check "déconnexion globale, retour vers la démo" '[[ $url == "$DEMO/" ]]'
check "session du serveur fermée" '[[ $(location "$SERVER/") == "$SERVER/login" ]]'

if [[ $FAILED -eq 0 ]]; then echo "OK"; else echo "ÉCHEC"; fi
exit $FAILED
