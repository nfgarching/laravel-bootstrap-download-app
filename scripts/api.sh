#!/usr/bin/env bash
#
# Signierter Aufruf der Portal-API mit curl – zum Testen ohne das Client-Package.
#
#   export PORTAL_URL=https://download.example.com
#   export PORTAL_API_TOKEN=pk_...
#   export PORTAL_API_SECRET=sk_...
#
#   scripts/api.sh POST   /api/v1/shipments '{"subject":"Test","recipients":[{"email":"a@example.com","password":true}]}'
#   scripts/api.sh UPLOAD /api/v1/shipments/<id>/files ./vertrag.pdf
#   scripts/api.sh POST   /api/v1/shipments/<id>/release
#   scripts/api.sh GET    /api/v1/shipments/<id>
#   scripts/api.sh GET    '/api/v1/events?after=0'
#   scripts/api.sh DELETE /api/v1/shipments/<id>
#
# Benötigt: bash, curl, openssl, sha256sum.

set -euo pipefail

: "${PORTAL_URL:?PORTAL_URL setzen}"
: "${PORTAL_API_TOKEN:?PORTAL_API_TOKEN setzen}"
: "${PORTAL_API_SECRET:?PORTAL_API_SECRET setzen}"

method="${1:?Methode fehlt (GET, POST, PATCH, DELETE, UPLOAD)}"
path="${2:?Pfad fehlt, z. B. /api/v1/shipments}"
argument="${3:-}"
timestamp="${PORTAL_TIMESTAMP:-$(date +%s)}"

# HMAC-SHA256 über: METHODE \n PFAD \n ZEITSTEMPEL \n SHA256-DES-INHALTS
sign() {
    printf '%s\n%s\n%s\n%s' "$1" "$path" "$timestamp" "$2" \
        | openssl dgst -sha256 -hmac "$PORTAL_API_SECRET" -r \
        | cut -d' ' -f1
}

urlencode() {
    local LC_ALL=C string="$1" out="" char i
    for ((i = 0; i < ${#string}; i++)); do
        char="${string:i:1}"
        case "$char" in
            [a-zA-Z0-9.~_-]) out+="$char" ;;
            *) out+=$(printf '%%%02X' "'$char") ;;
        esac
    done
    printf '%s' "$out"
}

common=(
    --silent --show-error
    --header "Authorization: Bearer ${PORTAL_API_TOKEN}"
    --header "Accept: application/json"
    --header "X-Timestamp: ${timestamp}"
)

if [ "${PORTAL_DRY_RUN:-}" = "1" ]; then
    hash=$(printf '%s' "$argument" | sha256sum | cut -d' ' -f1)
    echo "content-sha256: ${hash}"
    echo "signature:      $(sign "$method" "$hash")"
    exit 0
fi

if [ "$method" = "UPLOAD" ]; then
    file="${argument:?Datei fehlt}"
    hash=$(sha256sum "$file" | cut -d' ' -f1)

    curl "${common[@]}" --request POST \
        --header "Content-Type: application/octet-stream" \
        --header "X-Filename: $(urlencode "$(basename "$file")")" \
        --header "X-Content-SHA256: ${hash}" \
        --header "X-Signature: $(sign POST "$hash")" \
        --data-binary "@${file}" \
        "${PORTAL_URL}${path}"
else
    hash=$(printf '%s' "$argument" | sha256sum | cut -d' ' -f1)

    curl "${common[@]}" --request "$method" \
        --header "Content-Type: application/json" \
        --header "X-Content-SHA256: ${hash}" \
        --header "X-Signature: $(sign "$method" "$hash")" \
        --data-binary "$argument" \
        "${PORTAL_URL}${path}"
fi

echo
