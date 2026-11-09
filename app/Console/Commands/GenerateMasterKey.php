<?php

namespace App\Console\Commands;

use App\Services\Vault;
use Illuminate\Console\Command;

class GenerateMasterKey extends Command
{
    protected $signature = 'portal:key';

    protected $description = 'Erzeugt einen Hauptschlüssel für den Dateispeicher (PORTAL_MASTER_KEY)';

    public function handle(): int
    {
        $this->line('PORTAL_MASTER_KEY='.Vault::generateMasterKey());
        $this->newLine();
        $this->warn('Diesen Wert in die .env eintragen und sicher verwahren.');
        $this->warn('Geht er verloren, sind alle gespeicherten Dateien unlesbar. Wird er ersetzt, ebenfalls.');

        return self::SUCCESS;
    }
}
