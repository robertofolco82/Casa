#!/usr/bin/env bash
# Prepara la cartella da caricare su Hostinger.
# Uso:  ./deploy/build.sh   →  produce dist/
set -euo pipefail

RADICE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$RADICE/dist"

rm -rf "$DIST"
mkdir -p "$DIST/api"

# L'app è un file solo, senza build: diventa la home del sito.
cp "$RADICE/app/casa.html" "$DIST/index.html"

# Il ponte verso Claude. config.php NON si copia: lo crea Roberto sul server,
# così la chiave non passa da questa macchina né dal repository.
cp "$RADICE/deploy/api/claude.php"         "$DIST/api/claude.php"
cp "$RADICE/deploy/api/config.example.php" "$DIST/api/config.example.php"

# Nega l'accesso web a config.php anche se qualcuno indovinasse l'indirizzo.
cat > "$DIST/api/.htaccess" <<'HTACCESS'
<FilesMatch "^config\.php$">
  Require all denied
</FilesMatch>
HTACCESS

echo "Pronto in  $DIST"
echo
echo "Da caricare su Hostinger dentro public_html/:"
find "$DIST" -type f | sed "s|$DIST|  .|"
echo
echo "Poi, sul server: copia api/config.example.php in api/config.php"
echo "e scrivici dentro la chiave e il codice d'accesso."
