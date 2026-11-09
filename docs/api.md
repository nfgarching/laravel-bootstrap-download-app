# API des Download-Portals (v1)

Die API wird nur von der internen Anwendung benutzt. Basis: `/api/v1`, Format JSON.
Dieses Dokument ist die verbindliche Beschreibung für das Client-Package
(`laravel-bootstrap-share`), das die Signatur getrennt umsetzt.

## Authentifizierung

Jeder Aufruf trägt vier Kopfzeilen:

| Kopfzeile | Inhalt |
| --- | --- |
| `Authorization` | `Bearer <Token>` (`pk_…`, aus `php artisan portal:client:create`) |
| `X-Timestamp` | Unix-Zeit in Sekunden; darf höchstens 300 Sekunden abweichen |
| `X-Content-SHA256` | SHA-256 des Anfragekörpers, hexadezimal, klein; bei leerem Körper der Hash der leeren Zeichenkette |
| `X-Signature` | HMAC-SHA256 über die kanonische Zeichenkette, hexadezimal, klein |

Kanonische Zeichenkette (vier Zeilen, mit `\n` getrennt, ohne Zeilenende am Schluss):

```
METHODE
PFAD-MIT-QUERY
ZEITSTEMPEL
SHA256-DES-INHALTS
```

- `METHODE` in Großbuchstaben.
- `PFAD-MIT-QUERY` genau so, wie er gesendet wird, z. B. `/api/v1/events?after=10`.
- Schlüssel des HMAC ist der Signaturschlüssel (`sk_…`) als Zeichenkette.

Jede Signatur gilt genau einmal. Eine Wiederholung braucht einen neuen Zeitstempel.

Fehler: `401` bei fehlender oder falscher Anmeldung (ohne nähere Begründung),
`403` bei fehlender Fähigkeit, `429` bei zu vielen Aufrufen.

### Testvektoren

Signaturschlüssel `sk_test`, Zeitstempel `1794268800`.

| | Aufruf 1 | Aufruf 2 |
| --- | --- | --- |
| Methode | `POST` | `GET` |
| Pfad | `/api/v1/shipments` | `/api/v1/events?after=10` |
| Körper | `{"subject":"Test"}` | (leer) |
| `X-Content-SHA256` | `53e3079a7e6598bf369f85566f55601c549c83a867e087d7b0586655c25d6414` | `e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855` |
| `X-Signature` | `2b79322cf75b4ee5d4ea0787743b3cc2fb0f62e4f92765b7ea8cd50ae4495cc0` | `b21fd90f16e03ab5f4a7340540ac2dc8988aaaeaf2b99a8b66bdbcc90f0cca63` |

## Fähigkeiten

| Fähigkeit | Erlaubt |
| --- | --- |
| `shipments:write` | Sendungen anlegen, Dateien hochladen, freigeben, ändern, widerrufen |
| `shipments:read` | Status einer Sendung lesen |
| `events:read` | Protokoll abholen |

## Endpunkte

### `POST /shipments` – Sendung als Entwurf anlegen

```json
{
  "reference": "26/0001",
  "subject": "Entwurf Vereinbarung",
  "message": "Bitte prüfen Sie den Entwurf.",
  "expires_at": "2026-11-23T23:59:59+01:00",
  "max_downloads": 5,
  "recipients": [
    { "email": "mandant@example.com", "name": "Erika Muster", "password": true, "require_login": false }
  ]
}
```

- Pflicht sind `subject` und mindestens ein Empfänger mit `email`.
- `expires_at` fehlt: 14 Tage. Höchstens 90 Tage in der Zukunft.
- `max_downloads` gilt je Empfänger und je Datei. Fehlt es: 5.
- `require_login` wird gespeichert, wirkt aber erst mit den Empfängerkonten (Phase 3).
- Optionale Kopfzeile `Idempotency-Key`: Derselbe Schlüssel liefert dieselbe Sendung
  zurück (`200`) statt eine zweite anzulegen.

Antwort `201` mit dem Sendungsobjekt (siehe unten).

### `POST /shipments/{id}/files` – Datei hochladen

Der Dateiinhalt steht roh im Anfragekörper (`Content-Type: application/octet-stream`),
nicht als Formular. `X-Content-SHA256` ist damit zugleich die Prüfsumme der Datei;
stimmt sie nicht mit dem Inhalt überein, antwortet das Portal mit `422` und speichert nichts.

| Kopfzeile | Inhalt |
| --- | --- |
| `X-Filename` | Dateiname, URL-kodiert (Pflicht) |
| `X-Mime-Type` | MIME-Typ (optional, nur zur Anzeige) |

Nur möglich, solange die Sendung ein Entwurf ist. `413`, wenn die Datei zu groß ist.

### `POST /shipments/{id}/release` – Sendung freigeben

Antwort `200` mit dem Sendungsobjekt. Nur in dieser einen Antwort stehen je
Empfänger zusätzlich `url` und `password` (oder `null`). Das Portal speichert
davon ausschließlich Hashwerte; eine zweite Freigabe ist nicht möglich (`409`).

### `GET /shipments/{id}` – Status

### `PATCH /shipments/{id}` – ändern

```json
{ "expires_at": "2026-12-01T23:59:59+01:00", "max_downloads": 10, "unlock_recipients": ["<Empfänger-ID>"] }
```

Alle Felder optional. `unlock_recipients` hebt die Sperre nach zu vielen falschen Passwörtern auf.

### `DELETE /shipments/{id}` – widerrufen

Links werden sofort ungültig, Dateien und Schlüssel gelöscht.

### `GET /events?after={cursor}&limit={n}` – Protokoll

```json
{
  "data": [
    { "id": 42, "type": "file.downloaded", "shipment_id": "…", "recipient_id": "…", "file_id": "…",
      "ip": "203.0.113.0", "meta": null, "created_at": "2026-11-10T08:15:00+00:00" }
  ],
  "next_cursor": 42,
  "has_more": false
}
```

`next_cursor` merken und beim nächsten Abruf als `after` übergeben. `limit` höchstens 500.

Ereignisarten: `shipment.created`, `file.added`, `shipment.released`, `shipment.updated`,
`shipment.revoked`, `shipment.expired`, `link.opened`, `password.ok`, `password.failed`,
`link.locked`, `file.downloaded`, `download.limit_reached`.

IP-Adressen sind gekürzt (IPv4 ohne letztes Oktett, IPv6 auf /48). Zeiten sind UTC.

## Sendungsobjekt

```json
{
  "data": {
    "id": "0193a6f2-…",
    "status": "draft | released | revoked | expired",
    "reference": "26/0001",
    "subject": "Entwurf Vereinbarung",
    "message": "…",
    "max_downloads": 5,
    "expires_at": "2026-11-23T22:59:59+00:00",
    "released_at": null,
    "created_at": "…",
    "files": [
      { "id": "…", "name": "vertrag.pdf", "mime_type": "application/pdf", "size": 48213, "sha256": "…", "purged": false }
    ],
    "recipients": [
      { "id": "…", "email": "mandant@example.com", "name": "Erika Muster", "password_protected": true,
        "require_login": false, "opened_at": null, "locked": false, "failed_attempts": 0, "downloads": 0 }
    ]
  }
}
```
