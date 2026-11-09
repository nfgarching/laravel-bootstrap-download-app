<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->text('hmac_secret');
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('subject', 200);
            $table->text('message')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('max_downloads');
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->timestamps();

            $table->unique(['api_client_id', 'idempotency_key']);
        });

        Schema::create('shipment_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('path');
            $table->text('wrapped_key')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });

        Schema::create('shipment_recipients', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->boolean('wants_password')->default(false);
            $table->string('password_hash')->nullable();
            $table->boolean('require_login')->default(false);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'email']);
        });

        Schema::create('file_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_recipient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();

            $table->unique(['shipment_recipient_id', 'shipment_file_id']);
        });

        // Das Protokoll verweist bewusst über UUIDs und ohne Fremdschlüssel:
        // Einträge sollen das Löschen der Sendung überdauern.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_client_id')->nullable()->index();
            $table->string('type', 50);
            $table->uuid('shipment_uuid')->nullable()->index();
            $table->uuid('recipient_uuid')->nullable();
            $table->uuid('file_uuid')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
        Schema::dropIfExists('file_downloads');
        Schema::dropIfExists('shipment_recipients');
        Schema::dropIfExists('shipment_files');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('api_clients');
    }
};
