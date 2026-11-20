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

    // Impressum (§ 5 DDG) und Datenschutzerklärung (Art. 13 DSGVO), im Fuß jeder Seite verlinkt.
    'imprint_url' => env('PORTAL_IMPRINT_URL'),
    'privacy_url' => env('PORTAL_PRIVACY_URL'),

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

    /*
    |--------------------------------------------------------------------------
    | Empfängerkonten
    |--------------------------------------------------------------------------
    |
    | Ein Konto entsteht nur aus einem gültigen Link heraus (keine freie
    | Registrierung). Bestätigung der Adresse per Code, danach Passkey oder
    | Passwort plus Authenticator-App. Das Portal verschickt dafür E-Mails
    | (MAIL_* in der .env).
    |
    */

    'accounts' => [
        'enabled' => (bool) env('PORTAL_ACCOUNTS', true),
        'code_ttl_minutes' => 15,
        'code_attempts' => 5,
        'login_attempts' => 5,          // je E-Mail-Adresse und IP in 15 Minuten
        'password_min_length' => 12,
        'inactive_months' => (int) env('PORTAL_ACCOUNT_INACTIVE_MONTHS', 24),
        'inactive_notice_days' => 28,   // Hinweis vor dem Löschen
    ],

    /*
    |--------------------------------------------------------------------------
    | Passkeys (WebAuthn)
    |--------------------------------------------------------------------------
    |
    | rp_id ist der Hostname, an den die Passkeys gebunden sind. Wird er später
    | geändert, funktionieren bestehende Passkeys nicht mehr.
    |
    */

    'webauthn' => [
        'rp_id' => env('PORTAL_WEBAUTHN_RP_ID', parse_url((string) env('PORTAL_PUBLIC_URL', env('APP_URL', '')), PHP_URL_HOST) ?: ''),
        'rp_name' => env('PORTAL_SENDER_NAME', env('APP_NAME', 'Dokumentenportal')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rückkanal: Uploads an die Kanzlei
    |--------------------------------------------------------------------------
    |
    | Dateien werden beim Eingang mit dem öffentlichen Schlüssel der internen
    | Anwendung versiegelt (portal:… / share:keygen). Das Portal kann sie danach
    | nicht mehr lesen und liefert sie auch niemandem aus, nur der API zur Abholung.
    |
    */

    'uploads' => [
        'enabled' => (bool) env('PORTAL_UPLOADS', true),
        'max_file_mb' => (int) env('PORTAL_UPLOAD_MAX_FILE_MB', 200),
        'max_files_per_request' => 20,
        'request_expires_days' => (int) env('PORTAL_UPLOAD_REQUEST_DAYS', 14),
        // Uploads aus dem Konto ohne Anforderung: Tageslimit je Konto
        'account_daily_mb' => (int) env('PORTAL_UPLOAD_ACCOUNT_DAILY_MB', 1024),
        // Hinweis-Mail an die Kanzlei bei jedem Eingang (leer = keine)
        'notify_email' => env('PORTAL_NOTIFY_EMAIL'),
        // Nicht abgeholte Uploads werden danach gelöscht
        'keep_days' => (int) env('PORTAL_UPLOAD_KEEP_DAYS', 30),
        // Erlaubte Dateiendungen (Positivliste)
        'extensions' => [
            'pdf', 'jpg', 'jpeg', 'png', 'gif', 'heic', 'heif', 'tif', 'tiff', 'webp',
            'doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv',
            'ppt', 'pptx', 'odp', 'eml', 'msg', 'zip', 'xml',
        ],
        // Inhaltstypen, die unabhängig von der Endung abgelehnt werden
        'blocked_mime_types' => [
            'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib',
            'application/x-msdownload', 'application/x-mach-binary', 'application/x-elf',
            'application/java-archive', 'application/x-sh', 'text/x-shellscript',
        ],
    ],

];
