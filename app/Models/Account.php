<?php

namespace App\Models;

use App\Support\Secrets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Empfängerkonto.
 *
 * Anmeldung mit Passkey oder mit Passwort plus Einmalcode (TOTP); dazu
 * Wiederherstellungscodes. Ein Zurücksetzen per E-Mail gibt es bewusst nicht:
 * Es wäre nicht sicherer als die E-Mail selbst. Verlorene Zugänge setzt die
 * Kanzlei zurück (php artisan portal:account:reset).
 */
class Account extends Authenticatable
{
    public const RECOVERY_CODE_COUNT = 8;

    protected $fillable = ['email', 'name', 'webauthn_handle', 'notify_new_shipments'];

    protected $hidden = ['password', 'totp_secret', 'recovery_codes', 'remember_token'];

    protected function casts(): array
    {
        return [
            'totp_secret' => 'encrypted',
            'totp_last_step' => 'integer',
            'recovery_codes' => 'array',
            'notify_new_shipments' => 'boolean',
            'last_login_at' => 'datetime',
            'inactivity_notice_at' => 'datetime',
        ];
    }

    public function passkeys(): HasMany
    {
        return $this->hasMany(Passkey::class);
    }

    /** Konten haben kein "Angemeldet bleiben". */
    public function getRememberTokenName(): ?string
    {
        return null;
    }

    public function hasPasswordLogin(): bool
    {
        return $this->password !== null && $this->totp_secret !== null;
    }

    /**
     * Gültige Sendungen an die Adresse des Kontos.
     *
     * @return Builder<ShipmentRecipient>
     */
    public function recipients(): Builder
    {
        return ShipmentRecipient::query()
            ->with(['shipment.files' => fn ($q) => $q->whereNull('purged_at')])
            ->where('email', $this->email)
            ->whereNotNull('token_hash')
            ->whereNull('locked_at')
            ->whereHas('shipment', fn ($q) => $q
                ->where('status', Shipment::STATUS_RELEASED)
                ->where('expires_at', '>', now()));
    }

    /**
     * Erzeugt neue Wiederherstellungscodes, speichert nur deren Hashes und gibt
     * die Codes im Klartext zurück (werden einmal angezeigt).
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = Secrets::recoveryCode();
        }

        $this->forceFill([
            'recovery_codes' => array_map(fn (string $code) => self::hashRecoveryCode($code), $codes),
        ])->save();

        return $codes;
    }

    /** Prüft einen Wiederherstellungscode und verbraucht ihn. */
    public function useRecoveryCode(string $code): bool
    {
        $hash = self::hashRecoveryCode($code);
        $codes = $this->recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$index]);
                $this->forceFill(['recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function remainingRecoveryCodes(): int
    {
        return count($this->recovery_codes ?? []);
    }

    private static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', Secrets::normalizePassword($code));
    }
}
