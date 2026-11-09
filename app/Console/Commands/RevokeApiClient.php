<?php

namespace App\Console\Commands;

use App\Models\ApiClient;
use Illuminate\Console\Command;

class RevokeApiClient extends Command
{
    protected $signature = 'portal:client:revoke {id? : Nummer des API-Clients; ohne Angabe wird die Liste gezeigt}';

    protected $description = 'Sperrt einen API-Client oder listet alle Clients auf';

    public function handle(): int
    {
        $id = $this->argument('id');

        if ($id === null) {
            $this->table(
                ['#', 'Name', 'Fähigkeiten', 'Zuletzt benutzt', 'Gesperrt'],
                ApiClient::orderBy('id')->get()->map(fn (ApiClient $client) => [
                    $client->id,
                    $client->name,
                    implode(', ', $client->abilities ?? []),
                    $client->last_used_at?->toDateTimeString() ?? '–',
                    $client->revoked_at?->toDateTimeString() ?? '–',
                ])->all(),
            );

            return self::SUCCESS;
        }

        $client = ApiClient::find($id);

        if ($client === null) {
            $this->error('API-Client nicht gefunden.');

            return self::FAILURE;
        }

        $client->forceFill(['revoked_at' => now()])->save();

        $this->info("API-Client #{$client->id} „{$client->name}“ ist gesperrt.");

        return self::SUCCESS;
    }
}
