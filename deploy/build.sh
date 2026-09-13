#!/usr/bin/env bash
# Prepara la cartella da pubblicare.
#
#   ./deploy/build.sh            →  dist/ per Hostinger (pagina + ponte PHP)
#   ./deploy/build.sh vercel     →  dist/ per Vercel   (solo la pagina:
#                                   il ponte lì è api/claude.js, che Vercel
#                                   prende dalla radice del repository e
#                                   trasforma in funzione da sé)
set -euo pipefail

DOVE="${1:-hostinger}"
RADICE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST="$RADICE/dist"

rm -rf "$DIST"
mkdir -p "$DIST"

# L'app è un file solo, senza build: diventa la home del sito.
cp "$RADICE/app/casa.html" "$DIST/index.html"

if [ "$DOVE" = "vercel" ]; then
  echo "Pronto in  $DIST  (destinazione: Vercel)"
  echo "La funzione api/claude.js resta dov'è: Vercel la pubblica da sola."
  exit 0
fi

mkdir -p "$DIST/api"

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

echo "Pronto in  $DIST  (destinazione: Hostinger)"
echo
echo "Da caricare dentro public_html/:"
find "$DIST" -type f | sed "s|$DIST|  .|"
echo
echo "Poi, sul server: copia api/config.example.php in api/config.php"
echo "e scrivici dentro la chiave e il codice d'accesso."
