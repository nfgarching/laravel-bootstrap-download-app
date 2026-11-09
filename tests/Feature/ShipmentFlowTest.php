<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Shipment;
use App\Models\ShipmentFile;
use App\Services\Vault;
use App\Support\RequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Kompletter Ablauf: Sendung anlegen, Datei hochladen, freigeben, per Link
 * mit Passwort abrufen, Limit, Widerruf und Löschlauf.
 */
class ShipmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'pk_test_token';

    private string $secret = 'sk_test_secret';

    private string $vaultPath;

    private int $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultPath = sys_get_temp_dir().'/portal-vault-'.bin2hex(random_bytes(6));
        $this->clock = time();

        config([
            'portal.vault_path' => $this->vaultPath,
            'portal.master_key' => Vault::generateMasterKey(),
            'portal.public_url' => 'https://portal.test',
        ]);

        $this->app->forgetInstance(Vault::class);

        ApiClient::create([
            'name' => 'Test',
            'token_hash' => hash('sha256', $this->token),
            'hmac_secret' => $this->secret,
            'abilities' => ApiClient::ABILITIES,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultPath);

        parent::tearDown();
    }

    public function test_complete_flow_with_password(): void
    {
        $content = random_bytes(Vault::CHUNK * 2 + 123);

        $shipmentId = $this->createShipment(['password' => true]);
        $this->uploadFile($shipmentId, 'Entwurf Vertrag.pdf', $content)->assertCreated();

        $release = $this->api('POST', "/api/v1/shipments/{$shipmentId}/release")->assertOk();
        $recipient = $release->json('data.recipients.0');

        $this->assertStringStartsWith('https://portal.test/d/', $recipient['url']);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $recipient['password']);

        $linkToken = substr($recipient['url'], strlen('https://portal.test/d/'));
        $file = ShipmentFile::firstOrFail();

        // Auf der Platte liegt kein Klartext.
        $stored = file_get_contents($this->vaultPath.'/'.$file->path);
        $this->assertStringNotContainsString(substr($content, 0, 64), $stored);

        // Vor der Passworteingabe: weder Betreff noch Dateiname sichtbar.
        $this->get("/d/{$linkToken}")
            ->assertOk()
            ->assertDontSee('Entwurf Vertrag.pdf')
            ->assertDontSee('Testsendung');

        // Download ohne Passwort führt zurück zur Passwortseite.
        $this->post("/d/{$linkToken}/files/{$file->uuid}")->assertRedirect("/d/{$linkToken}");

        // Falsches Passwort.
        $this->post("/d/{$linkToken}/unlock", ['password' => 'FALSCH'])->assertRedirect("/d/{$linkToken}");

        // Richtiges Passwort, klein geschrieben und ohne Bindestriche.
        $this->post("/d/{$linkToken}/unlock", ['password' => strtolower(str_replace('-', '', $recipient['password']))])
            ->assertRedirect("/d/{$linkToken}");

        $this->get("/d/{$linkToken}")->assertOk()->assertSee('Entwurf Vertrag.pdf')->assertSee('Testsendung');

        $download = $this->post("/d/{$linkToken}/files/{$file->uuid}")->assertOk();
        $this->assertSame($content, $download->streamedContent());
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));

        // Protokoll enthält den Abruf.
        $types = collect($this->api('GET', '/api/v1/events')->assertOk()->json('data'))->pluck('type');
        $this->assertTrue($types->contains('file.downloaded'));
        $this->assertTrue($types->contains('password.failed'));

        // Status zeigt den Abruf.
        $this->api('GET', "/api/v1/shipments/{$shipmentId}")
            ->assertOk()
            ->assertJsonPath('data.recipients.0.downloads', 1)
            ->assertJsonMissingPath('data.recipients.0.url');
    }

    public function test_download_limit_is_enforced(): void
    {
        $shipmentId = $this->createShipment(['password' => false], ['max_downloads' => 1]);
        $this->uploadFile($shipmentId, 'a.txt', 'Hallo')->assertCreated();

        $url = $this->api('POST', "/api/v1/shipments/{$shipmentId}/release")->json('data.recipients.0.url');
        $linkToken = substr($url, strlen('https://portal.test/d/'));
        $file = ShipmentFile::firstOrFail();

        $first = $this->post("/d/{$linkToken}/files/{$file->uuid}")->assertOk();
        $this->assertSame('Hallo', $first->streamedContent());

        $this->post("/d/{$linkToken}/files/{$file->uuid}")->assertRedirect("/d/{$linkToken}");
    }

    public function test_link_is_locked_after_too_many_wrong_passwords(): void
    {
        config(['portal.password.attempts_per_hour' => 100, 'portal.password.lock_after' => 3]);

        $shipmentId = $this->createShipment(['password' => true]);
        $this->uploadFile($shipmentId, 'a.txt', 'Hallo')->assertCreated();

        $url = $this->api('POST', "/api/v1/shipments/{$shipmentId}/release")->json('data.recipients.0.url');
        $linkToken = substr($url, strlen('https://portal.test/d/'));

        $this->post("/d/{$linkToken}/unlock", ['password' => 'x1'])->assertRedirect();
        $this->post("/d/{$linkToken}/unlock", ['password' => 'x2'])->assertRedirect();
        $this->post("/d/{$linkToken}/unlock", ['password' => 'x3'])->assertNotFound();

        $this->get("/d/{$linkToken}")->assertNotFound();

        $this->api('GET', "/api/v1/shipments/{$shipmentId}")->assertJsonPath('data.recipients.0.locked', true);
    }

    public function test_revoke_deletes_files_and_invalidates_links(): void
    {
        $shipmentId = $this->createShipment(['password' => false]);
        $this->uploadFile($shipmentId, 'a.txt', 'Hallo')->assertCreated();

        $url = $this->api('POST', "/api/v1/shipments/{$shipmentId}/release")->json('data.recipients.0.url');
        $linkToken = substr($url, strlen('https://portal.test/d/'));
        $file = ShipmentFile::firstOrFail();

        $this->assertFileExists($this->vaultPath.'/'.$file->path);

        $this->api('DELETE', "/api/v1/shipments/{$shipmentId}")->assertOk()->assertJsonPath('data.status', 'revoked');

        $this->assertFileDoesNotExist($this->vaultPath.'/'.$file->path);
        $this->assertNull($file->fresh()->wrapped_key);
        $this->get("/d/{$linkToken}")->assertNotFound();
    }

    public function test_prune_expires_shipments(): void
    {
        $shipmentId = $this->createShipment(['password' => false]);
        $this->uploadFile($shipmentId, 'a.txt', 'Hallo')->assertCreated();
        $this->api('POST', "/api/v1/shipments/{$shipmentId}/release")->assertOk();

        $file = ShipmentFile::firstOrFail();

        $this->travel(15)->days();
        $this->artisan('portal:prune')->assertSuccessful();

        $this->assertSame(Shipment::STATUS_EXPIRED, Shipment::firstOrFail()->status);
        $this->assertFileDoesNotExist($this->vaultPath.'/'.$file->path);
    }

    public function test_api_rejects_bad_signature_replay_and_wrong_checksum(): void
    {
        $body = json_encode(['subject' => 'x', 'recipients' => [['email' => 'a@example.com']]]);

        // Ohne Anmeldung.
        $this->postJson('/api/v1/shipments', [])->assertUnauthorized();

        // Falsche Signatur.
        $headers = $this->signedHeaders('POST', '/api/v1/shipments', hash('sha256', $body));
        $headers['HTTP_X_SIGNATURE'] = str_repeat('0', 64);
        $this->call('POST', '/api/v1/shipments', [], [], [], $headers, $body)->assertUnauthorized();

        // Gültig, danach derselbe Aufruf noch einmal (Wiedereinspielen).
        $headers = $this->signedHeaders('POST', '/api/v1/shipments', hash('sha256', $body));
        $this->call('POST', '/api/v1/shipments', [], [], [], $headers, $body)->assertCreated();
        $this->call('POST', '/api/v1/shipments', [], [], [], $headers, $body)->assertUnauthorized();

        // Veralteter Zeitstempel.
        $headers = $this->signedHeaders('POST', '/api/v1/shipments', hash('sha256', $body), time() - 3600);
        $this->call('POST', '/api/v1/shipments', [], [], [], $headers, $body)->assertUnauthorized();

        // Prüfsumme passt nicht zum Dateiinhalt.
        $shipmentId = $this->createShipment(['password' => false]);
        $this->uploadFile($shipmentId, 'a.txt', 'Hallo', hash('sha256', 'etwas anderes'))->assertStatus(422);
        $this->assertSame(0, ShipmentFile::count());
    }

    public function test_idempotency_key_returns_the_same_shipment(): void
    {
        $payload = ['subject' => 'Testsendung', 'recipients' => [['email' => 'a@example.com']]];

        $first = $this->api('POST', '/api/v1/shipments', $payload, ['HTTP_IDEMPOTENCY_KEY' => 'abc'])->assertCreated();
        $second = $this->api('POST', '/api/v1/shipments', $payload, ['HTTP_IDEMPOTENCY_KEY' => 'abc'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Shipment::count());
    }

    /**
     * @param  array<string, mixed>  $recipient
     * @param  array<string, mixed>  $extra
     */
    private function createShipment(array $recipient, array $extra = []): string
    {
        return $this->api('POST', '/api/v1/shipments', array_merge([
            'reference' => '26/0001',
            'subject' => 'Testsendung',
            'recipients' => [array_merge(['email' => 'mandant@example.com'], $recipient)],
        ], $extra))->assertCreated()->json('data.id');
    }

    private function uploadFile(string $shipmentId, string $name, string $content, ?string $hash = null): TestResponse
    {
        $uri = "/api/v1/shipments/{$shipmentId}/files";

        $headers = $this->signedHeaders('POST', $uri, $hash ?? hash('sha256', $content)) + [
            'HTTP_X_FILENAME' => rawurlencode($name),
            'HTTP_X_MIME_TYPE' => 'application/pdf',
            'CONTENT_TYPE' => 'application/octet-stream',
        ];

        return $this->call('POST', $uri, [], [], [], $headers, $content);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @param  array<string, string>  $extraHeaders
     */
    private function api(string $method, string $uri, ?array $payload = null, array $extraHeaders = []): TestResponse
    {
        $body = $payload === null ? '' : json_encode($payload);

        $headers = $this->signedHeaders($method, $uri, hash('sha256', $body)) + $extraHeaders + [
            'CONTENT_TYPE' => 'application/json',
        ];

        return $this->call($method, $uri, [], [], [], $headers, $body);
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $method, string $uri, string $contentHash, ?int $timestamp = null): array
    {
        // Jeder Aufruf bekommt einen eigenen Zeitstempel, sonst wären zwei
        // gleiche Aufrufe im Test ein Wiedereinspielen.
        $timestamp = (string) ($timestamp ?? $this->clock++);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_CONTENT_SHA256' => $contentHash,
            'HTTP_X_SIGNATURE' => RequestSigner::sign($this->secret, $method, $uri, $timestamp, $contentHash),
        ];
    }
}
