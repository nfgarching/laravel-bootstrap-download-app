<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 Anhang B (SHA-1, Schlüssel "12345678901234567890", 8 Stellen).
 */
class TotpTest extends TestCase
{
    public function test_rfc_6238_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $vectors = [
            59 => '94287082',
            1111111109 => '07081804',
            1111111111 => '14050471',
            1234567890 => '89005924',
            2000000000 => '69279037',
            20000000000 => '65353130',
        ];

        foreach ($vectors as $time => $expected) {
            $this->assertSame($expected, Totp::code($secret, intdiv($time, 30), 8), "Zeit {$time}");
        }
    }

    public function test_verify_window_and_replay_protection(): void
    {
        $secret = Totp::generateSecret();
        $time = 1794268800;
        $step = Totp::currentStep($time);

        $this->assertSame($step, Totp::verify($secret, Totp::code($secret, $step), null, $time));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step), $step, $time), 'Wiederverwendung');
        $this->assertNotNull(Totp::verify($secret, Totp::code($secret, $step - 1), null, $time));
        $this->assertNull(Totp::verify($secret, Totp::code($secret, $step - 3), null, $time));
        $this->assertNull(Totp::verify($secret, 'abc', null, $time));
    }
}
