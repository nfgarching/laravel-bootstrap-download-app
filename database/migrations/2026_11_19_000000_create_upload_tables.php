<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Öffentlicher Schlüssel der internen Anwendung (X25519, Base64).
        // Uploads werden damit versiegelt; das Portal kann sie danach nicht mehr lesen.
        Schema::table('api_clients', function (Blueprint $table) {
            $table->text('upload_public_key')->nullable();
        });

        // Upload-Anforderung der Kanzlei: persönlicher Link an eine Adresse
        Schema::create('upload_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 100)->nullable();
            $table->string('subject', 200);
            $table->text('message')->nullable();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->unsignedInteger('max_files')->default(10);
            $table->unsignedInteger('failed_attempts')->default(0);
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });

        // Eingegangene Dateien – verschlüsselt im Vault, Schlüssel versiegelt
        Schema::create('uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('api_client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('upload_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('batch')->index();
            $table->string('sender_email');
            $table->string('sender_name')->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->string('name');
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('path')->nullable();
            $table->text('sealed_key')->nullable();
            $table->string('status', 20)->default('received')->index();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();
        });

        // Bestehende Clients mit allen bisherigen Fähigkeiten erhalten die neuen dazu
        foreach (DB::table('api_clients')->get() as $client) {
            $abilities = json_decode((string) $client->abilities, true) ?: [];

            if (in_array('*', $abilities, true)
                || array_diff(['shipments:write', 'shipments:read', 'events:read'], $abilities) === []) {
                $abilities = array_values(array_unique([...$abilities, 'uploads:write', 'uploads:read']));
                DB::table('api_clients')->where('id', $client->id)->update(['abilities' => json_encode($abilities)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('uploads');
        Schema::dropIfExists('upload_requests');

        Schema::table('api_clients', function (Blueprint $table) {
            $table->dropColumn('upload_public_key');
        });
    }
};
