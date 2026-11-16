<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Setzt einen Empfängerzugang zurück, z. B. wenn Passkey und Wiederherstellungs-
 * codes verloren sind. Vorher die Person auf anderem Weg identifizieren (Telefon)!
 *
 * Der Zugang wird gelöscht; Sendungen bleiben über die Adresse erreichbar.
 * Mit dem nächsten Link kann die Person einen neuen Zugang einrichten.
 */
class ResetAccount extends Command
{
    protected $signature = 'portal:account:reset {email : E-Mail-Adresse des Zugangs}';

    protected $description = 'Setzt einen Empfängerzugang zurück (löscht ihn); Neueinrichtung über den nächsten Link';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $account = Account::where('email', $email)->first();

        if ($account === null) {
            $this->error("Kein Zugang für {$email}.");

            return self::FAILURE;
        }

        $this->line("Zugang:        {$account->email}");
        $this->line('Eingerichtet:  '.$account->created_at->format('d.m.Y H:i'));
        $this->line('Letzte Anmeldung: '.($account->last_login_at?->format('d.m.Y H:i') ?? '–'));
        $this->line('Passkeys:      '.$account->passkeys()->count());
        $this->line('Passwort+App:  '.($account->hasPasswordLogin() ? 'ja' : 'nein'));

        if (! $this->confirm('Person auf anderem Weg identifiziert und Zugang jetzt löschen?')) {
            return self::FAILURE;
        }

        $account->delete();

        // Laufende Sitzungen dieses Zugangs beenden
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $account->id)->delete();
        }

        $this->info('Zugang gelöscht. Die Person kann über den nächsten Link einen neuen Zugang einrichten.');

        return self::SUCCESS;
    }
}
