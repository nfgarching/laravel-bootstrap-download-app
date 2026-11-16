<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

class ListAccounts extends Command
{
    protected $signature = 'portal:account:list {search? : Teil der E-Mail-Adresse}';

    protected $description = 'Listet die Empfängerzugänge auf';

    public function handle(): int
    {
        $query = Account::query()->withCount('passkeys')->orderBy('email');

        if ($search = $this->argument('search')) {
            $query->where('email', 'like', '%'.strtolower($search).'%');
        }

        $this->table(
            ['E-Mail', 'Passkeys', 'Passwort+App', 'Codes', 'Eingerichtet', 'Letzte Anmeldung'],
            $query->get()->map(fn (Account $a) => [
                $a->email,
                $a->passkeys_count,
                $a->hasPasswordLogin() ? 'ja' : 'nein',
                $a->remainingRecoveryCodes(),
                $a->created_at->format('d.m.Y'),
                $a->last_login_at?->format('d.m.Y H:i') ?? '–',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
