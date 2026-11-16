<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Empfängerkonten. Ein Konto sieht alle gültigen Sendungen an seine
        // (bestätigte) E-Mail-Adresse; eine eigene Zuordnungstabelle gibt es nicht.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name')->nullable();
            // Zufällige Kennung für WebAuthn (user.id), nicht die E-Mail-Adresse
            $table->string('webauthn_handle', 64)->unique();
            $table->string('password')->nullable();
            $table->text('totp_secret')->nullable();
            $table->unsignedBigInteger('totp_last_step')->nullable();
            $table->json('recovery_codes')->nullable();
            $table->boolean('notify_new_shipments')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('inactivity_notice_at')->nullable();
            $table->timestamps();
        });

        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->string('name', 100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
        Schema::dropIfExists('accounts');
    }
};
