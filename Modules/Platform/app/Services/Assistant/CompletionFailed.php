<?php

namespace Modules\Platform\Services\Assistant;

/**
 * A provider was asked for a completion and did not produce one.
 *
 * Thrown rather than returned, exactly as `Modules\Accounting\Services\RateSources\RateSourceFailed`
 * and `Modules\Mailbox\Services\Delivery\SendFailed` are, so a half-parsed
 * response can never be mistaken for an answer.
 *
 * The factory methods exist because "connection failed" on its own sends
 * whoever is looking at the settings page hunting for a wrong API key when the
 * real problem might be a missing one, or a certificate bundle this machine
 * does not have. Each names a different, actionable cause:
 *
 * - `noKeyConfigured()` — the row has no key and the driver needs one. Never
 *   reaches the network.
 * - `tlsUnverified()` — the connection could not be verified. On this
 *   development machine that is `cURL error 60`, because `php.ini` sets no CA
 *   bundle; on a real host it usually means the same thing.
 * - `credentialsRejected()` — the provider was reached and said 401 or 403.
 *   The key exists and is wrong, revoked, or out of quota.
 * - `unreachable()` — any other network failure: DNS, timeout, connection
 *   refused.
 * - `providerError()` — the provider was reached and answered, but not with
 *   success — a 4xx that is not an auth failure, or a 5xx.
 * - `malformed()` — the provider answered 2xx with a body this driver's
 *   mapping does not recognise.
 */
class CompletionFailed extends \RuntimeException
{
    public static function noKeyConfigured(string $provider): self
    {
        return new self($provider.' has no API key configured, so nothing was sent.');
    }

    public static function misconfigured(string $provider, string $detail): self
    {
        return new self($provider.' is not set up to send: '.$detail.'.');
    }

    public static function tlsUnverified(string $provider, string $detail): self
    {
        return new self(
            $provider.' could not be reached because this machine could not verify its TLS certificate: '.$detail
            .'. This is a server configuration problem (no CA bundle), not a wrong API key.',
        );
    }

    public static function credentialsRejected(string $provider, string $detail): self
    {
        return new self($provider.' refused the credentials: '.$detail.'.');
    }

    public static function unreachable(string $provider, string $detail): self
    {
        return new self($provider.' could not be reached: '.$detail.'.');
    }

    public static function providerError(string $provider, string $detail): self
    {
        return new self($provider.' answered with an error: '.$detail.'.');
    }

    public static function malformed(string $provider, string $detail): self
    {
        return new self($provider.' answered with something this driver does not recognise: '.$detail.'.');
    }
}
