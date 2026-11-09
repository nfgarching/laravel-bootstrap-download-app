<?php

namespace App\Services;

use LengthException;
use RuntimeException;

/**
 * Verschlüsselter Dateispeicher.
 *
 * Jede Datei bekommt einen eigenen Schlüssel und wird im Stream-Verfahren
 * (libsodium secretstream, XChaCha20-Poly1305) verschlüsselt. Der Dateischlüssel
 * wird mit dem Hauptschlüssel umhüllt (secretbox) und so in der Datenbank
 * gespeichert. Der Hauptschlüssel liegt außerhalb von Datenbank und Webroot.
 *
 * Dateiformat: 24 Byte Stream-Header, danach Blöcke zu je CHUNK Byte Klartext
 * plus 17 Byte Authentifizierung. Der letzte Block trägt das FINAL-Kennzeichen;
 * fehlt es, gilt die Datei als abgeschnitten.
 *
 * Die Klasse hängt nicht von Laravel ab und lässt sich für sich allein testen.
 */
class Vault
{
    public const CHUNK = 65536;

    private ?string $resolvedMasterKey = null;

    public function __construct(
        private readonly string $root,
        private readonly ?string $masterKey = null,
        private readonly ?string $masterKeyFile = null,
    ) {}

    public static function generateMasterKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function generateFileKey(): string
    {
        return sodium_crypto_secretstream_xchacha20poly1305_keygen();
    }

    /**
     * Umhüllt einen Dateischlüssel mit dem Hauptschlüssel (Base64: Nonce + Chiffrat).
     */
    public function wrapKey(string $fileKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($fileKey, $nonce, $this->masterKey()));
    }

    public function unwrapKey(string $wrapped): string
    {
        $raw = base64_decode($wrapped, true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Dateischlüssel ist beschädigt.');
        }

        $key = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->masterKey(),
        );

        if ($key === false) {
            throw new RuntimeException('Dateischlüssel lässt sich mit dem Hauptschlüssel nicht öffnen.');
        }

        return $key;
    }

    /**
     * Verschlüsselt einen Datenstrom in den Speicher.
     *
     * @param  resource  $input
     * @return array{size: int, sha256: string} Größe und SHA-256 des Klartexts
     *
     * @throws LengthException wenn $maxBytes überschritten wird
     */
    public function encryptStream($input, string $relativePath, string $fileKey, ?int $maxBytes = null): array
    {
        $target = $this->absolutePath($relativePath);
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Speicherverzeichnis lässt sich nicht anlegen.');
        }

        $temporary = $target.'.part';
        $output = fopen($temporary, 'wb');

        if ($output === false) {
            throw new RuntimeException('Datei lässt sich nicht schreiben.');
        }

        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($fileKey);
            $this->write($output, $header);

            $hash = hash_init('sha256');
            $size = 0;
            $buffer = $this->readChunk($input, self::CHUNK);

            do {
                // Einen Block vorauslesen, um den letzten Block zu erkennen.
                $next = $this->readChunk($input, self::CHUNK);
                $final = $next === '';

                $size += strlen($buffer);

                if ($maxBytes !== null && $size > $maxBytes) {
                    throw new LengthException('Datei überschreitet die zulässige Größe.');
                }

                hash_update($hash, $buffer);

                $this->write($output, sodium_crypto_secretstream_xchacha20poly1305_push(
                    $state,
                    $buffer,
                    '',
                    $final
                        ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                        : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE,
                ));

                $buffer = $next;
            } while (! $final);

            fclose($output);
            $output = null;

            if (! rename($temporary, $target)) {
                throw new RuntimeException('Datei lässt sich nicht ablegen.');
            }

            return ['size' => $size, 'sha256' => hash_final($hash)];
        } finally {
            if (is_resource($output)) {
                fclose($output);
            }

            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Entschlüsselt eine Datei blockweise und übergibt jeden Klartextblock an $sink.
     *
     * @param  callable(string): void  $sink
     */
    public function decryptStream(string $relativePath, string $fileKey, callable $sink): void
    {
        $input = @fopen($this->absolutePath($relativePath), 'rb');

        if ($input === false) {
            throw new RuntimeException('Datei ist nicht vorhanden.');
        }

        try {
            $header = $this->readChunk($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);

            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Datei ist beschädigt.');
            }

            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $fileKey);
            $blockSize = self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
            $finished = false;

            while (! $finished) {
                $block = $this->readChunk($input, $blockSize);

                if ($block === '') {
                    throw new RuntimeException('Datei ist unvollständig.');
                }

                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $block);

                if ($result === false) {
                    throw new RuntimeException('Datei ist beschädigt oder wurde verändert.');
                }

                [$plain, $tag] = $result;
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;

                if ($plain !== '') {
                    $sink($plain);
                }
            }
        } finally {
            fclose($input);
        }
    }

    public function exists(string $relativePath): bool
    {
        return is_file($this->absolutePath($relativePath));
    }

    public function delete(string $relativePath): void
    {
        $path = $this->absolutePath($relativePath);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Löscht ein Verzeichnis samt Inhalt (eine Ebene: die Dateien einer Sendung).
     */
    public function deleteDirectory(string $relativePath): void
    {
        $directory = $this->absolutePath($relativePath);

        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($directory.'/'.$entry)) {
                @unlink($directory.'/'.$entry);
            }
        }

        @rmdir($directory);
    }

    private function absolutePath(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
            throw new RuntimeException('Ungültiger Speicherpfad.');
        }

        return rtrim($this->root, '/').'/'.$relativePath;
    }

    /**
     * Liest genau $length Byte, weniger nur am Ende des Stroms.
     *
     * @param  resource  $stream
     */
    private function readChunk($stream, int $length): string
    {
        $data = stream_get_contents($stream, $length);

        return $data === false ? '' : $data;
    }

    /**
     * @param  resource  $stream
     */
    private function write($stream, string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($stream, substr($data, $written));

            if ($result === false || $result === 0) {
                throw new RuntimeException('Schreibfehler im Dateispeicher.');
            }

            $written += $result;
        }
    }

    private function masterKey(): string
    {
        if ($this->resolvedMasterKey !== null) {
            return $this->resolvedMasterKey;
        }

        $encoded = $this->masterKey;

        if (($encoded === null || $encoded === '') && $this->masterKeyFile) {
            $encoded = is_readable($this->masterKeyFile) ? trim((string) file_get_contents($this->masterKeyFile)) : null;
        }

        $key = $encoded ? base64_decode($encoded, true) : false;

        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                'Hauptschlüssel fehlt oder ist ungültig. PORTAL_MASTER_KEY setzen (php artisan portal:key).'
            );
        }

        return $this->resolvedMasterKey = $key;
    }
}
