<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateApiClient extends Command
{
    protected $signature = 'portal:client:create
        {name : Bezeichnung, z. B. "Kanzlei intern"}
        {--ability=* : Einzelne Fähigkeiten statt aller (shipments:write, shipments:read, events:read)}';

    protected $description = 'Legt einen API-Client an und zeigt Token und Signaturschlüssel einmalig an';

    public function handle(): int
    {
        $abilities = $this->option('ability') ?: ApiClient::ABILITIES;
        $unknown = array_diff($abilities, ApiClient::ABILITIES);

        if ($unknown !== []) {
            $this->error('Unbekannte Fähigkeit: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $token = 'pk_'.Str::random(48);
        $secret = 'sk_'.Str::random(64);

        $client = ApiClient::create([
            'name' => $this->argument('name'),
            'token_hash' => hash('sha256', $token),
            'hmac_secret' => $secret,
            'abilities' => array_values($abilities),
        ]);

        $this->info("API-Client #{$client->id} „{$client->name}“ angelegt.");
        $this->newLine();
        $this->line('PORTAL_API_TOKEN='.$token);
        $this->line('PORTAL_API_SECRET='.$secret);
        $this->newLine();
        $this->warn('Beide Werte werden nur jetzt angezeigt. Das Token ist im Portal nur als Hash gespeichert.');

        return self::SUCCESS;
    }
}
