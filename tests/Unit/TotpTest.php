<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RFC 6238 time-based one-time passwords.
 *
 * `test_the_rfc_6238_test_vectors` is the one that matters: RFC 6238 Appendix
 * B publishes these six pairs precisely so an implementation can be checked
 * against a fixed, published answer rather than trusted on its word. The
 * shared secret there is the ASCII string "12345678901234567890" — this file
 * carries its base32 form, `GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ`, and the RFC's
 * own 8-digit codes so no rounding or truncation choice in `Totp::code()` can
 * hide behind the six digits Kargah actually uses at login.
 */
class TotpTest extends TestCase
{
    /** base32("12345678901234567890") — RFC 6238 Appendix B's shared SHA-1 secret. */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public static function rfc6238Vectors(): array
    {
        return [
            'T = 59' => [59, '94287082'],
            'T = 1111111109' => [1111111109, '07081804'],
            'T = 1111111111' => [1111111111, '14050471'],
            'T = 1234567890' => [1234567890, '89005924'],
            'T = 2000000000' => [2000000000, '69279037'],
            'T = 20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfc6238Vectors')]
    public function test_the_rfc_6238_test_vectors(int $timestamp, string $expected): void
    {
        $this->assertSame(
            $expected,
            Totp::code(self::RFC_SECRET, $timestamp, Totp::PERIOD, 8),
            "T = $timestamp did not match RFC 6238 Appendix B.",
        );
    }

    public function test_code_defaults_to_six_digits(): void
    {
        $code = Totp::code(self::RFC_SECRET, 59);

        $this->assertNotNull($code);
        $this->assertSame(6, strlen($code));
        // The six-digit code is the last six digits of the eight-digit one —
        // both are the same modulo arithmetic, just carried to a shorter width.
        $this->assertSame('287082', $code);
    }

    public function test_an_invalid_seed_returns_no_code_rather_than_throwing(): void
    {
        $this->assertNull(Totp::code('not valid base32!!!', 59));
        $this->assertNull(Totp::code('', 59));
    }

    /* verify() ---------------------------------------------------------------- */

    public function test_verify_accepts_the_current_code(): void
    {
        $this->assertTrue(Totp::verify(self::RFC_SECRET, '94287082', 59, 1, Totp::PERIOD, 8));
    }

    public function test_verify_accepts_one_step_of_drift_either_side(): void
    {
        // T = 59 is in the first 30 s step. The code one step later (30–59 s
        // in) and one step earlier both have to be accepted within the ±1
        // window a real authenticator app and a real clock can disagree by.
        $oneStepLater = Totp::code(self::RFC_SECRET, 59 + Totp::PERIOD, Totp::PERIOD, 8);
        $oneStepEarlier = Totp::code(self::RFC_SECRET, max(0, 59 - Totp::PERIOD), Totp::PERIOD, 8);

        $this->assertTrue(Totp::verify(self::RFC_SECRET, $oneStepLater, 59, 1, Totp::PERIOD, 8));
        $this->assertTrue(Totp::verify(self::RFC_SECRET, $oneStepEarlier, 59, 1, Totp::PERIOD, 8));
    }

    public function test_verify_rejects_two_steps_of_drift(): void
    {
        $twoStepsLater = Totp::code(self::RFC_SECRET, 59 + (2 * Totp::PERIOD), Totp::PERIOD, 8);

        $this->assertFalse(Totp::verify(self::RFC_SECRET, $twoStepsLater, 59, 1, Totp::PERIOD, 8));
    }

    public function test_verify_rejects_a_wrong_code(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '00000000', 59, 1, Totp::PERIOD, 8));
    }

    public function test_verify_rejects_an_empty_code(): void
    {
        $this->assertFalse(Totp::verify(self::RFC_SECRET, '', 59));
    }

    /* Round trip through the generator ----------------------------------------- */

    public function test_a_generated_secret_round_trips_through_code_and_verify(): void
    {
        $secret = Totp::generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);

        $code = Totp::code($secret, 1_700_000_000);

        $this->assertNotNull($code);
        $this->assertSame(6, strlen($code));
        $this->assertTrue(Totp::verify($secret, $code, 1_700_000_000));
    }

    public function test_two_generated_secrets_are_not_the_same(): void
    {
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    /* Display and the manual-entry URI ------------------------------------------ */

    public function test_format_for_display_groups_the_secret_in_fours(): void
    {
        $this->assertSame('GEZD GNBV GY3T QOJQ GEZD GNBV GY3T QOJQ', Totp::formatForDisplay(self::RFC_SECRET));
    }

    public function test_provisioning_uri_carries_the_secret_and_no_qr_dependency_is_implied(): void
    {
        $uri = Totp::provisioningUri(self::RFC_SECRET, 'nima@kargah.local', 'Kargah');

        $this->assertStringStartsWith('otpauth://totp/Kargah:nima%40kargah.local?', $uri);
        $this->assertStringContainsString('secret='.self::RFC_SECRET, $uri);
        $this->assertStringContainsString('issuer=Kargah', $uri);
    }
}
