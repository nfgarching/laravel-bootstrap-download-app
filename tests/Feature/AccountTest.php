<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\ApiClient;
use App\Models\ShipmentFile;
use App\Services\AccountMailer;
use App\Services\Vault;
use App\Support\RequestSigner;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Empfängerkonto: Einrichtung aus dem Link mit Passwort und Authenticator-App,
 * Abruf im Konto, Anmeldung, Wiederherstellungscode, Benachrichtigung,
 * "nur nach Anmeldung". Passkeys brauchen ein echtes Gerät; getestet sind
 * hier nur die Zugangsregeln der Passkey-Endpunkte.
 */
class AccountTest extends TestCase
{
    use RefreshDatabase;

    private string $vaultPath;

    private int $clock;

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
            'portal.webauthn.rp_id' => 'portal.test',
            'portal.accounts.enabled' => true,
        ]);

        $this->app->forgetInstance(Vault::class);

        // E-Mails abfangen, um den Bestätigungscode zu lesen
        $this->mailer = new class extends AccountMailer
        {
            public array $codes = [];

            public array $notified = [];

            public array $security = [];

            public function code(string $email, string $code): void
            {
                $this->codes[] = $code;
            }

            public function newShipment(Account $account): void
            {
                $this->notified[] = $account->email;
            }

            public function securityNotice(Account $account, string $what): void
            {
                $this->security[] = $what;
            }
        };
        $this->app->instance(AccountMailer::class, $this->mailer);

        ApiClient::create([
            'name' => 'Test',
            'token_hash' => hash('sha256', 'pk_test'),
            'hmac_secret' => 'sk_test',
            'abilities' => ApiClient::ABILITIES,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->vaultPath);

        parent::tearDown();
    }

    public function test_setup_with_password_and_app_then_use_account(): void
    {
        [$token, $shipmentId] = $this->releasedShipment('Vertrag', 'Inhalt A');

        // Abrufseite bietet die Einrichtung an
        $this->get("/d/{$token}")->assertOk()->assertSee('Zugang einrichten');

        // Einrichtung starten: Code geht an die Empfängeradresse
        $this->post("/d/{$token}/konto")->assertRedirect(route('account.setup'));
        $this->assertCount(1, $this->mailer->codes);

        $this->post(route('account.setup.code'), ['code' => '000000'])->assertRedirect();
        $this->post(route('account.setup.code'), ['code' => $this->mailer->codes[0]])->assertRedirect(route('account.setup'));

        $this->get(route('account.setup'))->assertOk()->assertSee('Authenticator-App');
        $secret = session('portal.setup')['totp_secret'];

        $this->post(route('account.setup.password'), [
            'password' => 'ein-sicheres-Passwort',
            'password_confirmation' => 'ein-sicheres-Passwort',
            'code' => Totp::code($secret, Totp::currentStep()),
        ])->assertRedirect(route('account.recovery-codes'));

        $this->assertAuthenticated();
        $account = Account::firstOrFail();
        $this->assertSame('mandant@example.com', $account->email);
        $this->assertTrue($account->hasPasswordLogin());

        $codes = session('portal.recovery_codes');
        $this->assertCount(Account::RECOVERY_CODE_COUNT, $codes);
        $this->get(route('account.recovery-codes'))->assertOk()->assertSee($codes[0]);
        $this->get(route('account.recovery-codes'))->assertRedirect(route('account.security'));  // nur einmal

        // Konto zeigt die Sendung; Abruf ohne Passwort des Links
        $this->get(route('account.index'))->assertOk()->assertSee('Vertrag');

        $recipientId = $this->api('GET', "/api/v1/shipments/{$shipmentId}")->json('data.recipients.0.id');
        $this->assertTrue($this->api('GET', "/api/v1/shipments/{$shipmentId}")->json('data.recipients.0.has_account'));

        $this->get(route('account.shipment', ['recipient' => $recipientId]))->assertOk()->assertSee('Vertrag.pdf');

        $file = ShipmentFile::firstOrFail();
        $download = $this->post(route('account.file', ['recipient' => $recipientId, 'file' => $file->uuid]))->assertOk();
        $this->assertSame('Inhalt A', $download->streamedContent());

        // Link führt den angemeldeten Empfänger ins Konto
        $this->get("/d/{$token}")->assertRedirect(route('account.shipment', ['recipient' => $recipientId]));

        // Abmelden, erneut anmelden – derselbe App-Code gilt nicht zweimal
        $this->post(route('account.logout'))->assertRedirect(route('account.login'));
        $this->assertGuest();

        $this->post(route('account.login.password'), [
            'email' => 'mandant@example.com', 'password' => 'falsch-falsch-falsch', 'code' => Totp::code($secret, Totp::currentStep() + 1),
        ])->assertRedirect();
        $this->assertGuest();

        $this->post(route('account.login.password'), [
            'email' => 'Mandant@Example.com', 'password' => 'ein-sicheres-Passwort', 'code' => Totp::code($secret, Totp::currentStep() + 1),
        ])->assertRedirect(route('account.index'));
        $this->assertAuthenticated();

        // Wiederherstellungscode
        $this->post(route('account.logout'));
        $this->post(route('account.recovery.login'), ['email' => 'mandant@example.com', 'code' => strtolower($codes[1])])
            ->assertRedirect(route('account.security'));
        $this->assertAuthenticated();
        $this->assertSame(Account::RECOVERY_CODE_COUNT - 1, $account->fresh()->remainingRecoveryCodes());
        $this->assertNotEmpty($this->mailer->security);

        $this->post(route('account.logout'));
        $this->post(route('account.recovery.login'), ['email' => 'mandant@example.com', 'code' => $codes[1]]);
        $this->assertGuest();   // verbraucht
    }

    public function test_account_holder_is_notified_and_require_login_is_enforced(): void
    {
        Account::create(['email' => 'mandant@example.com', 'webauthn_handle' => bin2hex(random_bytes(16))]);

        [$token] = $this->releasedShipment('Neu', 'x', ['require_login' => true]);

        $this->assertSame(['mandant@example.com'], $this->mailer->notified);

        // Link allein reicht nicht
        $this->get("/d/{$token}")->assertOk()->assertSee('Anmeldung erforderlich')->assertDontSee('Neu.pdf');
        $file = ShipmentFile::firstOrFail();
        $this->post("/d/{$token}/files/{$file->uuid}")->assertRedirect("/d/{$token}");

        // Einrichtung für eine bestehende Adresse führt zur Anmeldung
        $this->post("/d/{$token}/konto")->assertRedirect(route('account.login'));
    }

    public function test_foreign_shipments_and_passkey_endpoints_are_protected(): void
    {
        [, $shipmentId] = $this->releasedShipment('Fremd', 'x', [], 'andere@example.com');
        $recipientId = $this->api('GET', "/api/v1/shipments/{$shipmentId}")->json('data.recipients.0.id');

        // Passkey-Registrierung ohne bestätigte Einrichtung
        $this->postJson(route('account.setup.passkey.options'))->assertForbidden();

        $account = Account::create(['email' => 'mandant@example.com', 'webauthn_handle' => bin2hex(random_bytes(16))]);
        $this->actingAs($account);

        $this->get(route('account.shipment', ['recipient' => $recipientId]))->assertNotFound();

        // Optionen für einen weiteren Passkey
        $this->postJson(route('account.passkey.options'))
            ->assertOk()
            ->assertJsonPath('publicKey.rp.id', 'portal.test')
            ->assertJsonPath('publicKey.attestation', 'none');
    }

    /**
     * @return array{0: string, 1: string} Link-Token, Sendungs-ID
     */
    private function releasedShipment(string $name, string $content, array $recipient = [], string $email = 'mandant@example.com'): array
    {
        $id = $this->api('POST', '/api/v1/shipments', [
            'subject' => $name,
            'recipients' => [array_merge(['email' => $email, 'password' => true], $recipient)],
        ])->assertCreated()->json('data.id');

        $uri = "/api/v1/shipments/{$id}/files";
        $this->call('POST', $uri, [], [], [], $this->signed('POST', $uri, hash('sha256', $content)) + [
            'HTTP_X_FILENAME' => rawurlencode($name.'.pdf'),
            'CONTENT_TYPE' => 'application/octet-stream',
        ], $content)->assertCreated();

        $url = $this->api('POST', "/api/v1/shipments/{$id}/release")->assertOk()->json('data.recipients.0.url');

        return [substr($url, strlen('https://portal.test/d/')), $id];
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
