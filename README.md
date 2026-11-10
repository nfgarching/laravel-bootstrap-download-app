# laravel-bootstrap-download-app

Download-Portal: Eine interne Anwendung übergibt Dateien per API, der Empfänger ruft
sie über einen persönlichen Link ab, optional mit Passwort. Das ersetzt den
ungesicherten Versand von E-Mail-Anhängen.

Das Portal ist eine eigenständige Laravel-Anwendung für einen öffentlichen Server.
Es hat keinen Zugriff auf die interne Anwendung; alle Verbindungen gehen von innen
nach außen.

> **Stand: Phase 2, Portalseite.** Läuft im Testbetrieb (Laravel 13, PHP 8.4),
> alle Tests grün. Das Client-Package für die interne Anwendung
> (`laravel-bootstrap-share`) fehlt noch.

## Was das Portal kann

- API für Sendungen: anlegen, Dateien hochladen, freigeben, ändern, widerrufen, Protokoll abholen ([docs/api.md](docs/api.md))
- Anmeldung der API mit Token, Zeitstempel und HMAC-Signatur; jede Signatur gilt einmal
- Dateien liegen nur verschlüsselt auf dem Server (libsodium, eigener Schlüssel je Datei, umhüllt mit einem Hauptschlüssel außerhalb der Datenbank)
- Abrufseite mit persönlichem Link je Empfänger, Passwort, Abruflimit und Sperre nach Fehlversuchen
- Download einzeln oder alle Dateien als ZIP; das ZIP wird direkt aus dem verschlüsselten Speicher gestreamt, ohne Klartext-Zwischendatei
- Layout mit Bootstrap 5.3 und Bootstrap Icons, lokal ausgeliefert (`public/vendor/`, aktualisieren mit `scripts/update-assets.sh`), hell/dunkel nach Systemeinstellung
- Links auf Impressum und Datenschutz im Fuß jeder Seite (`PORTAL_IMPRINT_URL`, `PORTAL_PRIVACY_URL`)
- Stündlicher Löschlauf für abgelaufene Sendungen
- Docker-Setup hinter Traefik

Noch nicht enthalten: Empfängerkonten, Rückkanal (Upload), E-Mail-Versand durch das Portal.

Zum Layout: `laravel-bootstrap-core` ist bewusst noch nicht eingebunden. Es bringt
Livewire, Anmeldung und Registrierung mit und lädt Bootstrap vom CDN; das passt nicht
zur strikten CSP ohne Fremdinhalte und vergrößert die Angriffsfläche einer Seite ohne
Login. Die Optik entspricht den übrigen Anwendungen (Bootstrap 5.3, Petrol `#115e59`).
Über die Einbindung wird mit den Empfängerkonten (Phase 3) neu entschieden.

ZIP-Archive verwenden ZIP64 nur, wenn sie die 4-GB-Grenze erreichen können; so
lassen sie sich auch mit älteren Entpackern öffnen.

## Einrichtung mit Docker hinter Traefik

Voraussetzung: Traefik läuft bereits, hängt an einem externen Docker-Netz und hat
einen Zertifikats-Resolver.

```bash
git clone https://github.com/nfgarching/laravel-bootstrap-download-app.git
cd laravel-bootstrap-download-app
cp .env.example .env
```

In der `.env` mindestens anpassen: `APP_URL`, `PORTAL_PUBLIC_URL`, `PORTAL_HOST`,
`PORTAL_SENDER_NAME` sowie die drei `TRAEFIK_`-Werte passend zur eigenen
Traefik-Konfiguration.

```bash
docker compose build

# Beide Schlüssel erzeugen und in die .env eintragen
docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show   # -> APP_KEY
docker compose run --rm --no-deps --entrypoint php app artisan portal:key            # -> PORTAL_MASTER_KEY

chmod 600 .env
docker compose up -d

# API-Client für die interne Anwendung anlegen (Werte werden nur einmal angezeigt)
docker compose exec app php artisan portal:client:create "Kanzlei intern"
```

Der Hauptschlüssel (`PORTAL_MASTER_KEY`) muss gesichert werden. Geht er verloren
oder wird er ersetzt, sind alle gespeicherten Dateien unlesbar.

Nach dem ersten erfolgreichen `composer install` die entstandene `composer.lock`
ins Repo übernehmen, damit spätere Builds dieselben Versionen verwenden:

```bash
docker compose run --rm --no-deps --entrypoint cat app composer.lock > composer.lock
```

## Ausprobieren mit curl

`scripts/api.sh` signiert die Aufrufe.

```bash
export PORTAL_URL=https://download.example.com
export PORTAL_API_TOKEN=pk_...
export PORTAL_API_SECRET=sk_...

# 1. Sendung anlegen -> liefert die id
scripts/api.sh POST /api/v1/shipments \
  '{"reference":"Test 1","subject":"Testsendung","recipients":[{"email":"test@example.com","password":true}]}'

# 2. Datei hochladen
scripts/api.sh UPLOAD /api/v1/shipments/<id>/files ./beispiel.pdf

# 3. Freigeben -> liefert Link und Passwort (nur dieses eine Mal)
scripts/api.sh POST /api/v1/shipments/<id>/release

# 4. Link im Browser öffnen, Passwort eingeben, herunterladen

# 5. Protokoll und Status
scripts/api.sh GET '/api/v1/events?after=0'
scripts/api.sh GET /api/v1/shipments/<id>

# 6. Widerrufen
scripts/api.sh DELETE /api/v1/shipments/<id>
```

## Lokal ohne Docker

```bash
composer install
cp .env.example .env        # APP_ENV=local, APP_URL=http://localhost:8000, DB_DATABASE-Zeile entfernen,
                            # SESSION_SECURE_COOKIE=false
php artisan key:generate
php artisan portal:key      # Ausgabe in die .env eintragen
touch database/database.sqlite
php artisan migrate
php artisan test
php artisan serve
```

## Befehle

| Befehl | Zweck |
| --- | --- |
| `php artisan portal:key` | Hauptschlüssel erzeugen |
| `php artisan portal:client:create "Name"` | API-Client anlegen; mit `--ability=` auf einzelne Fähigkeiten beschränken |
| `php artisan portal:client:revoke` | API-Clients auflisten; mit Nummer: sperren |
| `php artisan portal:prune` | Löschlauf (läuft stündlich über den Scheduler) |

## Sicherheit in Kürze

- **Links:** 256 Bit Zufall; in der Datenbank steht nur der SHA-256-Hash. Jeder Empfänger hat einen eigenen Link.
- **Passwörter:** vom Portal erzeugt, Argon2id-Hash, fünf Versuche je Stunde, Sperre nach zehn Fehlversuchen.
- **Dateien:** Stream-Verschlüsselung mit XChaCha20-Poly1305; Manipulation und Abschneiden werden beim Abruf erkannt.
- **Abruf:** Der Download startet erst nach einem Klick (POST). Link-Vorschauen und Mail-Scanner verbrauchen keinen Abruf.
- **Vor der Passworteingabe** zeigt die Seite nur den Absender, weder Betreff noch Dateinamen.
- **Neutrale Fehlerseite:** Unbekannte, abgelaufene, widerrufene und gesperrte Links sind nicht unterscheidbar.
- **Kopfzeilen:** strikte Content-Security-Policy ohne Fremdinhalte, `no-referrer`, `nosniff`, HSTS, `no-store`.
- **Protokoll:** IP-Adressen werden gekürzt gespeichert.

Die Anwendung vertraut den Weiterleitungs-Kopfzeilen jedes Proxys (`trustProxies('*')`),
weil sie nur im Container hinter dem Reverse Proxy läuft. Den Port 8080 deshalb
nie direkt veröffentlichen.

Sicherheitslücken bitte nicht als öffentliches Issue melden, sondern direkt an den Betreiber des Repos.

## Lizenz

MIT
