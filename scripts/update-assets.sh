#!/bin/sh
# Holt Bootstrap und Bootstrap Icons als fertige Dateien nach public/vendor/.
# Kein Build-Schritt im Betrieb: Die Dateien liegen im Repo und werden lokal
# ausgeliefert (CSP ohne Fremdinhalte). Versionen hier anpassen und Skript neu ausführen.
#
# Braucht Docker (Node-Image) oder ein lokales npm.
set -eu

BOOTSTRAP=5.3.8
ICONS=1.13.1

cd "$(dirname "$0")/.."
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

if command -v npm >/dev/null 2>&1; then
    (cd "$work" && npm pack --silent "bootstrap@$BOOTSTRAP" "bootstrap-icons@$ICONS" >/dev/null)
else
    docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -v "$work":/work -w /work node:22-bookworm-slim \
        npm pack --silent "bootstrap@$BOOTSTRAP" "bootstrap-icons@$ICONS" >/dev/null
fi

mkdir -p "$work/bs" "$work/bi"
tar -xzf "$work/bootstrap-$BOOTSTRAP.tgz" -C "$work/bs"
tar -xzf "$work/bootstrap-icons-$ICONS.tgz" -C "$work/bi"

rm -rf public/vendor/bootstrap public/vendor/bootstrap-icons
mkdir -p public/vendor/bootstrap public/vendor/bootstrap-icons/fonts
cp "$work/bs/package/dist/css/bootstrap.min.css" public/vendor/bootstrap/
cp "$work/bs/package/LICENSE" public/vendor/bootstrap/LICENSE
cp "$work/bi/package/font/bootstrap-icons.min.css" public/vendor/bootstrap-icons/
cp "$work/bi/package/font/fonts/bootstrap-icons.woff2" public/vendor/bootstrap-icons/fonts/
cp "$work/bi/package/LICENSE" public/vendor/bootstrap-icons/LICENSE

# Nur woff2 ausliefern (alle aktuellen Browser); Verweis auf .woff entfernen.
sed -i.bak 's#,url("\(\./\)\{0,1\}fonts/bootstrap-icons.woff?[^"]*") format("woff")##' public/vendor/bootstrap-icons/bootstrap-icons.min.css
rm -f public/vendor/bootstrap-icons/bootstrap-icons.min.css.bak

echo "Bootstrap $BOOTSTRAP und Bootstrap Icons $ICONS nach public/vendor/ kopiert."
