<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Öffentliche Adresse
    |--------------------------------------------------------------------------
    |
    | Basis-URL für die Download-Links, die die API zurückgibt. Die API kann
    | später über einen anderen Weg (z. B. WireGuard) angesprochen werden als
    | die öffentliche Seite; die Links zeigen immer auf diese Adresse.
    |
    */

    'public_url' => rtrim((string) env('PORTAL_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Absender
    |--------------------------------------------------------------------------
    |
    | Name, der auf den Abrufseiten als Absender erscheint.
    |
    */

    'sender_name' => env('PORTAL_SENDER_NAME', env('APP_NAME', 'Dokumentenportal')),

    // Zeitzone, in der Fristen auf den Abrufseiten angezeigt werden.
    'display_timezone' => env('PORTAL_DISPLAY_TIMEZONE', 'Europe/Berlin'),

    /*
    |--------------------------------------------------------------------------
    | Hauptschlüssel
    |--------------------------------------------------------------------------
    |
    | 32 Byte, Base64-kodiert (php artisan portal:key). Mit ihm werden die
    | Dateischlüssel umhüllt. Er gehört weder in die Datenbank noch ins Repo.
    | Entweder direkt als Umgebungsvariable oder als Pfad zu einer Datei
    | (z. B. Docker Secret).
    |
    */

    'master_key' => env('PORTAL_MASTER_KEY'),
    'master_key_file' => env('PORTAL_MASTER_KEY_FILE'),

    /*
    |--------------------------------------------------------------------------
    | Dateispeicher
    |--------------------------------------------------------------------------
    |
    | Verzeichnis für die verschlüsselten Dateien. Liegt außerhalb des Webroots.
    |
    */

    'vault_path' => env('PORTAL_VAULT_PATH', storage_path('app/vault')),

    'max_file_size' => (int) env('PORTAL_MAX_FILE_SIZE', 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Fristen und Grenzen
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'expires_days' => (int) env('PORTAL_EXPIRES_DAYS', 14),
        'max_expires_days' => (int) env('PORTAL_MAX_EXPIRES_DAYS', 90),
        'max_downloads' => (int) env('PORTAL_MAX_DOWNLOADS', 5),
    ],

    // Entwürfe, die nie freigegeben wurden, werden nach so vielen Stunden gelöscht.
    'draft_ttl_hours' => (int) env('PORTAL_DRAFT_TTL_HOURS', 24),

    // Metadaten abgelaufener Sendungen und Protokolleinträge werden so lange aufbewahrt.
    'retention_days' => (int) env('PORTAL_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Passwortschutz der Links
    |--------------------------------------------------------------------------
    */

    'password' => [
        'attempts_per_hour' => (int) env('PORTAL_PASSWORD_ATTEMPTS_PER_HOUR', 5),
        'lock_after' => (int) env('PORTAL_PASSWORD_LOCK_AFTER', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | API-Signatur
    |--------------------------------------------------------------------------
    |
    | Zulässige Abweichung des Zeitstempels in Sekunden.
    |
    */

    'signature_tolerance' => (int) env('PORTAL_SIGNATURE_TOLERANCE', 300),

];
