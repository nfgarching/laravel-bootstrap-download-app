<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiClient;
use App\Models\Upload;
use App\Services\AccountMailer;
use App\Services\Vault;
use App\Support\RequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rückkanal: Anforderung → Upload über den Link (mit Passwort) → Abholung,
 * Entschlüsselung mit dem privaten Schlüssel der internen Anwendung → Quittung.
 */
class UploadTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultPath;

    private int $clock;

    private string $keypair;

    private object $mailer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vaultPath = sys_get_temp_dir().'/portal-vault-'.bin2hex(random_bytes(6));
        $this->clock = time();

        config([
            'portal.vault_path' => $this->vaultPath,
            'portal.master_key' => Vault::generateMasterKey(),
            'portal.public_url' => 'https://portal.test',
            'portal.uploads.enabled' => true,
        ]);

        $this->app->forgetInstance(Vault::class);

        $this->mailer = new class extends AccountMailer
        {
            public array $receipts = [];

            public function uploadReceipt(string $email, iterable $uploads): void
            {
                $this->receipts[] = $email;
            }

            public function newShipment(Account $account): void {}
        };
        $this->app->instance(AccountMailer::class, $this->mailer);

        ApiClient::create([
            'name' => 'Test',
            'token_hash' => hash('sha256', 'pk_test'),
            'hmac_secret' => 'sk_test',
            'abilities' => ApiClient::ABILITIES,
        ]);

        $this->keypair = sodium_crypto_box_keypair();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultPath);

        parent::tearDown();
    }

    public function test_request_upload_fetch_and_ack(): void
    {
        // Ohne hinterlegten Schlüssel keine Anforderung
        $this->api('POST', '/api/v1/upload-requests', ['subject' => 'Lohnabrechnungen', 'email' => 'm@example.com'])->assertStatus(409);

        $this->registerKey();

        $created = $this->api('POST', '/api/v1/upload-requests', [
            'reference' => '26/0001',
            'subject' => 'Lohnabrechnungen 2025',
            'message' => 'Bitte alle zwölf Monate.',
            'email' => 'M@Example.com',
            'password' => true,
            'max_files' => 3,
        ])->assertCreated();

        $token = substr($created->json('data.url'), strlen('https://portal.test/u/'));
        $password = $created->json('data.password');
        $requestId = $created->json('data.id');

        // Passwortseite, dann Formular
        $this->get("/u/{$token}")->assertOk()->assertDontSee('Lohnabrechnungen 2025');
        $this->post("/u/{$token}/unlock", ['password' => $password])->assertRedirect("/u/{$token}");
        $this->get("/u/{$token}")->assertOk()->assertSee('Lohnabrechnungen 2025')->assertSee('Bitte alle zwölf Monate.');

        // Nicht erlaubter Dateityp: nichts wird gespeichert
        $this->post("/u/{$token}", ['files' => [UploadedFile::fake()->createWithContent('tool.exe', 'MZ')]])
            ->assertRedirect("/u/{$token}");
        $this->assertSame(0, Upload::count());

        // Upload
        $content = "%PDF-1.4\n".random_bytes(Vault::CHUNK + 500);
        $this->post("/u/{$token}", [
            'files' => [UploadedFile::fake()->createWithContent('Januar.pdf', $content)],
            'message' => 'Rest folgt',
        ])->assertRedirect("/u/{$token}")->assertSessionHas('portal_receipt');

        $this->assertSame(['m@example.com'], $this->mailer->receipts);

        // Mehr als erlaubt
        $this->post("/u/{$token}", ['files' => [
            UploadedFile::fake()->createWithContent('a.pdf', '%PDF a'),
            UploadedFile::fake()->createWithContent('b.pdf', '%PDF b'),
            UploadedFile::fake()->createWithContent('c.pdf', '%PDF c'),
        ]])->assertRedirect()->assertSessionHas('portal_error');
        $this->assertSame(1, Upload::count());

        // Abholung
        $list = $this->api('GET', '/api/v1/uploads')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame($requestId, $list[0]['request_id']);
        $this->assertSame('26/0001', $list[0]['reference']);
        $this->assertSame('Rest folgt', $list[0]['message']);
        $this->assertSame(hash('sha256', $content), $list[0]['sha256']);

        $encrypted = $this->api('GET', "/api/v1/uploads/{$list[0]['id']}/content")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('%PDF-1.4', $encrypted);

        $fileKey = sodium_crypto_box_seal_open(base64_decode($list[0]['sealed_key']), $this->keypair);
        $this->assertNotFalse($fileKey);
        $this->assertSame($content, $this->decrypt($encrypted, $fileKey));

        // Quittung löscht
        $path = $this->vaultPath.'/'.Upload::first()->path;
        $this->assertFileExists($path);
        $this->api('POST', "/api/v1/uploads/{$list[0]['id']}/ack")->assertOk()->assertJsonPath('data.status', 'fetched');
        $this->assertFileDoesNotExist($path);
        $this->assertSame([], $this->api('GET', '/api/v1/uploads')->json('data'));

        // Anforderung schließen
        $this->api('DELETE', "/api/v1/upload-requests/{$requestId}")->assertOk()->assertJsonPath('data.status', 'closed');
        $this->get("/u/{$token}")->assertNotFound();
    }

    public function test_account_holder_uploads_without_request_and_unfetched_uploads_expire(): void
    {
        $this->registerKey();

        // Eine Sendung an die Adresse legt fest, an wen freie Uploads gehen
        $id = $this->api('POST', '/api/v1/shipments', [
            'subject' => 'Entwurf', 'recipients' => [['email' => 'm@example.com']],
        ])->json('data.id');
        $uri = "/api/v1/shipments/{$id}/files";
        $this->call('POST', $uri, [], [], [], $this->signed('POST', $uri, hash('sha256', 'x')) + [
            'HTTP_X_FILENAME' => 'a.pdf', 'CONTENT_TYPE' => 'application/octet-stream',
        ], 'x')->assertCreated();
        $this->api('POST', "/api/v1/shipments/{$id}/release")->assertOk();

        $account = Account::create(['email' => 'm@example.com', 'webauthn_handle' => bin2hex(random_bytes(16))]);
        $this->actingAs($account);

        $this->get(route('account.upload'))->assertOk()->assertSee('Betreff');

        $this->post(route('account.upload.store'), [
            'subject' => 'Kündigungsschreiben',
            'files' => [UploadedFile::fake()->createWithContent('Kuendigung.pdf', '%PDF-1.4 k')],
        ])->assertRedirect(route('account.upload'));

        $upload = Upload::firstOrFail();
        $this->assertSame('Kündigungsschreiben', $upload->subject);
        $this->assertSame($account->id, $upload->account_id);

        $this->travel(31)->days();
        $this->artisan('portal:prune')->assertSuccessful();

        $this->assertSame(Upload::STATUS_EXPIRED, $upload->fresh()->status);
        $this->assertNull($upload->fresh()->path);
    }

    private function registerKey(): void
    {
        $this->api('PUT', '/api/v1/client/upload-key', [
            'public_key' => base64_encode(sodium_crypto_box_publickey($this->keypair)),
        ])->assertOk();
    }

    private function decrypt(string $data, string $key): string
    {
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(substr($data, 0, 24), $key);
        $offset = 24;
        $out = '';
        $block = Vault::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;

        while ($offset < strlen($data)) {
            [$plain] = sodium_crypto_secretstream_xchacha20poly1305_pull($state, substr($data, $offset, $block));
            $out .= $plain;
            $offset += $block;
        }

        return $out;
    }

    private function api(string $method, string $uri, ?array $payload = null): TestResponse
    {
        $body = $payload === null ? '' : json_encode($payload);

        return $this->call($method, $uri, [], [], [], $this->signed($method, $uri, hash('sha256', $body)) + [
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function signed(string $method, string $uri, string $hash): array
    {
        $timestamp = (string) $this->clock++;

        return [
            'HTTP_AUTHORIZATION' => 'Bearer pk_test',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_TIMESTAMP' => $timestamp,
            'HTTP_X_CONTENT_SHA256' => $hash,
            'HTTP_X_SIGNATURE' => RequestSigner::sign('sk_test', $method, $uri, $timestamp, $hash),
        ];
    }
}
