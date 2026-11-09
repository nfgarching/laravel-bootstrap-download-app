<?php

namespace App\Providers;

use App\Services\Vault;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Vault::class, fn () => new Vault(
            (string) config('portal.vault_path'),
            config('portal.master_key'),
            config('portal.master_key_file'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
