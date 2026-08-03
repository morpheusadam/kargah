<?php

namespace Modules\Data\Support;

/**
 * RFC 6238 time-based one-time passwords, done here rather than pulled in.
 *
 * The whole algorithm is a base32 decode, an HMAC and a modulo, and PHP ships
 * `hash_hmac`. A dependency for thirty lines of arithmetic would be a package
 * with write access to the one part of Kargah that handles second factors, and
 * that trade is not worth making for code this short and this stable — RFC 6238
 * has not moved since 2011.
 *
 * Everything here is pure: given a seed and an instant it returns a code, and it
 * touches no model. The clock is only read when no instant is given, and it is
 * read through `now()` rather than `time()` so a test can freeze it — a code
 * that rolls halfway through an assertion is a test that fails one run in
 * thirty and gets marked flaky rather than fixed.
 */
final class Totp
{
    /** The window every authenticator app uses. */
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * The code for a seed at an instant.
     *
     * Returns null for a seed that is not valid base32, because a mistyped seed
     * is a normal thing for a person to paste and it should read as "no code"
     * on the page rather than throwing out of a list render.
     */
    public static function code(string $seed, ?int $timestamp = null, int $period = self::PERIOD, int $digits = self::DIGITS): ?string
    {
        $key = self::decodeBase32($seed);

        if ($key === null || $key === '') {
            return null;
        }

        $counter = intdiv($timestamp ?? now()->getTimestamp(), $period);

        // Eight bytes, big endian. `J` would be machine-endian and would give
        // the right answer on x86 by accident and the wrong one elsewhere.
        $binary = pack('N*', 0, $counter);

        $hash = hash_hmac('sha1', $binary, $key, true);

        // Dynamic truncation: the low nibble of the last byte picks where in the
        // digest to read from, so every code uses a different four bytes of it.
        $offset = ord($hash[19]) & 0x0F;

        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Seconds left before the current code rolls over. */
    public static function secondsRemaining(?int $timestamp = null, int $period = self::PERIOD): int
    {
        return $period - (($timestamp ?? now()->getTimestamp()) % $period);
    }

    public static function isValidSeed(string $seed): bool
    {
        $decoded = self::decodeBase32($seed);

        return $decoded !== null && $decoded !== '';
    }

    /**
     * Base32 as RFC 4648 defines it, which is what every provider hands out.
     *
     * Padding and spacing are stripped rather than rejected: providers print
     * seeds in groups of four with `=` on the end, and a person copying one out
     * of a browser will bring both along.
     */
    private static function decodeBase32(string $seed): ?string
    {
        $clean = strtoupper(preg_replace('/[\s\-=]/', '', $seed) ?? '');

        if ($clean === '' || preg_match('/[^A-Z2-7]/', $clean) === 1) {
            return null;
        }

        $bits = '';
        foreach (str_split($clean) as $character) {
            $bits .= str_pad(decbin(strpos(self::BASE32, $character)), 5, '0', STR_PAD_LEFT);
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
