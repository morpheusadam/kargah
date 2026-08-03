<?php

namespace App\Support;

/**
 * RFC 6238 time-based one-time passwords, done here rather than pulled in.
 *
 * The whole algorithm is a base32 decode, an HMAC and a modulo, and PHP ships
 * `hash_hmac`. A dependency for forty lines of arithmetic would be a package
 * with write access to the login flow, and that trade is not worth making for
 * code this short and this stable — RFC 6238 has not moved since 2011.
 *
 * SHA-1 only: it is what every authenticator app assumes when it is not told
 * otherwise, and RFC 6238's SHA-256/SHA-512 variants exist for interop cases
 * this project does not have.
 *
 * Everything here is pure: given a seed and an instant it returns a code, and
 * it touches no model and writes nothing. `tests/Unit/TotpTest.php` pins
 * `code()` against the RFC 6238 Appendix B test vectors — the ones the RFC
 * publishes precisely so nobody has to trust an implementation on its word.
 */
final class Totp
{
    /** The window every authenticator app uses. */
    public const PERIOD = 30;

    public const DIGITS = 6;

    /** 160 bits — the length RFC 6238's own SHA-1 example key uses. */
    public const SECRET_BYTES = 20;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A fresh secret, drawn from the CSPRNG and base32-encoded for display and storage. */
    public static function generateSecret(int $bytes = self::SECRET_BYTES): string
    {
        return self::encodeBase32(random_bytes($bytes));
    }

    /**
     * The code for a seed at an instant.
     *
     * Returns null for a seed that is not valid base32 rather than throwing,
     * because the caller — a settings page — should read that as "no code",
     * not crash a render.
     */
    public static function code(string $secret, ?int $timestamp = null, int $period = self::PERIOD, int $digits = self::DIGITS): ?string
    {
        $key = self::decodeBase32($secret);

        if ($key === null || $key === '') {
            return null;
        }

        $counter = intdiv($timestamp ?? now()->getTimestamp(), $period);

        // Eight bytes, big endian. `J` would be machine-endian and would give
        // the right answer on x86 by accident and the wrong one elsewhere.
        $binary = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $binary, $key, true);

        // Dynamic truncation: the low nibble of the last byte picks where in
        // the digest to read from, so every code uses a different four bytes.
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a code is valid at, or within `$window` steps either side of,
     * an instant — the ±1 step (30 s) allowance for clock drift and nothing
     * wider, so a code is not accepted for minutes either side of real time.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null, int $window = 1, int $period = self::PERIOD, int $digits = self::DIGITS): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        $now = $timestamp ?? now()->getTimestamp();

        for ($step = -$window; $step <= $window; $step++) {
            $candidate = self::code($secret, $now + ($step * $period), $period, $digits);

            if ($candidate !== null && hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `otpauth://` URI an authenticator app's own QR scanner or "enter a
     * setup key" field both accept. Kargah renders this as selectable text
     * rather than a QR image — no QR library is installed, and every
     * authenticator app takes manual entry.
     */
    public static function provisioningUri(string $secret, string $accountLabel, string $issuer = 'Kargah'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($accountLabel),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    /** Groups of four, the shape a person can retype off a screen without losing their place. */
    public static function formatForDisplay(string $secret): string
    {
        return trim(chunk_split($secret, 4, ' '));
    }

    private static function encodeBase32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $bits = str_pad($bits, (int) (ceil(strlen($bits) / 5) * 5), '0', STR_PAD_RIGHT);

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $out;
    }

    /**
     * Base32 as RFC 4648 defines it, which is what every authenticator app
     * hands out and expects back.
     *
     * Padding and spacing are stripped rather than rejected: a person copying
     * a seed out of a browser will bring both along.
     */
    private static function decodeBase32(string $secret): ?string
    {
        $clean = strtoupper(preg_replace('/[\s\-=]/', '', $secret) ?? '');

        if ($clean === '' || preg_match('/[^A-Z2-7]/', $clean) === 1) {
            return null;
        }

        $bits = '';
        foreach (str_split($clean) as $character) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32_ALPHABET, $character)), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        // A base32 string need not be a whole number of bytes; the trailing
        // bits under eight are padding and are dropped, not zero-extended.
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
