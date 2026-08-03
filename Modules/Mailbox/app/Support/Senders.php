<?php

namespace Modules\Mailbox\Support;

/**
 * The services Kargah can hand a campaign to, and everything a page needs to
 * draw one.
 *
 * A code constant rather than a config entry because none of it is an install
 * decision: the transport name is Laravel's, the composer package is the one
 * Symfony publishes for that bridge, the icon has to be a name that exists in
 * the keenicons bundle, and the credential field names are what the driver goes
 * looking for. Config holds what differs per install — how big a chunk may be,
 * how long a claim may look live — and nothing else.
 *
 * Every class string here is written out whole. Tailwind's scanner reads source
 * text, so a tone built as `'text-'.$key` would be a class nobody generated;
 * see docs/frontend-conventions.md.
 *
 * `webhook.signature` is the honest answer to 'does this provider sign its
 * callbacks', and it is what decides how `HandlesWebhooks::verify()` behaves.
 * Only Mailgun signs, with a plain HMAC that needs no network call. The other
 * three publish an IP range or tell you to put credentials in the URL, so
 * Kargah requires a shared secret of its own and refuses a callback without it
 * — a webhook that writes to the suppression list is a webhook that can silence
 * an address, and it must not be open to anyone who guesses the path.
 */
final class Senders
{
    public const SMTP = 'smtp';

    public const BREVO = 'brevo';

    public const POSTMARK = 'postmark';

    public const SES = 'ses';

    public const MAILGUN = 'mailgun';

    /** The secret every unsigned provider's callback must carry, as a `token` query parameter. */
    public const WEBHOOK_SECRET = 'webhook_secret';

    /**
     * @return array<string, array{
     *     label: string,
     *     icon: string,
     *     tone: string,
     *     transport: string,
     *     package: string|null,
     *     summary: string,
     *     requirement: string,
     *     webhook: array{signature: string|null, hint: string},
     *     credentials: array<string, array{label: string, secret: bool, placeholder: string, hint: string}>
     * }>
     */
    public static function all(): array
    {
        return [
            self::BREVO => [
                'label' => 'Brevo',
                'icon' => 'ki-paper-plane',
                'tone' => 'text-primary',
                'transport' => 'smtp',
                'package' => null,
                'summary' => 'Marketing volume on a free tier that survives a real campaign.',
                'requirement' => 'Create an SMTP key under Transactional → SMTP & API, then paste the login and the key here. The login is the account email, not the API key.',
                'webhook' => [
                    'signature' => null,
                    'hint' => 'Brevo does not sign its webhooks. Add the callback URL with its token query parameter exactly as shown; Kargah refuses a callback without it.',
                ],
                'credentials' => [
                    'username' => [
                        'label' => 'SMTP login',
                        'secret' => false,
                        'placeholder' => 'you@example.com',
                        'hint' => 'The login Brevo shows next to the key, which is usually the account email.',
                    ],
                    'password' => [
                        'label' => 'SMTP key',
                        'secret' => true,
                        'placeholder' => 'xsmtpsib-…',
                        'hint' => 'Stored encrypted. It is never rendered back into this page once saved.',
                    ],
                    self::WEBHOOK_SECRET => [
                        'label' => 'Webhook secret',
                        'secret' => true,
                        'placeholder' => 'Any long random string',
                        'hint' => 'Yours, not Brevo\'s. It goes in the callback URL and is the only thing standing between the suppression list and anyone who guesses the path.',
                    ],
                ],
            ],
            self::POSTMARK => [
                'label' => 'Postmark',
                'icon' => 'ki-rocket',
                'tone' => 'text-warning',
                'transport' => 'postmark',
                'package' => 'symfony/postmark-mailer',
                'summary' => 'Transactional mail with the best deliverability of the four and no marketing tier.',
                'requirement' => 'Create a server in Postmark, take its server API token, and use a message stream that matches the traffic — outbound for transactional, a broadcast stream for a campaign.',
                'webhook' => [
                    'signature' => null,
                    'hint' => 'Postmark does not sign its webhooks; it expects credentials in the URL. Add the callback URL with its token query parameter exactly as shown.',
                ],
                'credentials' => [
                    'token' => [
                        'label' => 'Server API token',
                        'secret' => true,
                        'placeholder' => 'Paste the server token',
                        'hint' => 'Stored encrypted. The account token will not work — this is the per-server one.',
                    ],
                    'message_stream' => [
                        'label' => 'Message stream',
                        'secret' => false,
                        'placeholder' => 'broadcast',
                        'hint' => 'Postmark refuses bulk mail on the transactional stream, so a campaign needs a broadcast one.',
                    ],
                    self::WEBHOOK_SECRET => [
                        'label' => 'Webhook secret',
                        'secret' => true,
                        'placeholder' => 'Any long random string',
                        'hint' => 'Yours, not Postmark\'s. It goes in the callback URL and is what proves a bounce report came from Postmark.',
                    ],
                ],
            ],
            self::SES => [
                'label' => 'Amazon SES',
                'icon' => 'ki-cloud',
                'tone' => 'text-info',
                'transport' => 'ses',
                'package' => 'aws/aws-sdk-php',
                'summary' => 'The cheapest at volume, and the strictest about a new account\'s reputation.',
                'requirement' => 'Create an IAM user with ses:SendRawEmail, verify the sending domain in the same region, and ask AWS to lift the sandbox before the first real campaign.',
                'webhook' => [
                    'signature' => null,
                    'hint' => 'SES reports through SNS, which signs with a certificate Kargah would have to fetch on every callback. On shared hosting that is a network call per bounce, so the shared token in the URL is what is checked instead.',
                ],
                'credentials' => [
                    'key' => [
                        'label' => 'Access key ID',
                        'secret' => false,
                        'placeholder' => 'AKIA…',
                        'hint' => 'From the IAM user you created for sending, not the root account.',
                    ],
                    'secret' => [
                        'label' => 'Secret access key',
                        'secret' => true,
                        'placeholder' => 'Paste the secret access key',
                        'hint' => 'Stored encrypted. AWS shows it once; if it is lost, rotate the key rather than hunting for it.',
                    ],
                    'region' => [
                        'label' => 'Region',
                        'secret' => false,
                        'placeholder' => 'eu-central-1',
                        'hint' => 'The region the domain was verified in. A domain verified elsewhere will be refused.',
                    ],
                    self::WEBHOOK_SECRET => [
                        'label' => 'Webhook secret',
                        'secret' => true,
                        'placeholder' => 'Any long random string',
                        'hint' => 'Yours, not Amazon\'s. It goes in the SNS subscription URL.',
                    ],
                ],
            ],
            self::MAILGUN => [
                'label' => 'Mailgun',
                'icon' => 'ki-abstract-26',
                'tone' => 'text-destructive',
                'transport' => 'mailgun',
                'package' => 'symfony/mailgun-mailer',
                'summary' => 'The only one of the four that signs its callbacks, which makes bounces cheap to trust.',
                'requirement' => 'Add the sending domain in Mailgun, take a sending API key from Settings → API keys, and copy the HTTP webhook signing key from Settings → Webhooks.',
                'webhook' => [
                    'signature' => 'hmac-sha256',
                    'hint' => 'Mailgun signs every callback with an HMAC over the timestamp and token. Kargah verifies it, so no shared secret of its own is needed.',
                ],
                'credentials' => [
                    'secret' => [
                        'label' => 'Sending API key',
                        'secret' => true,
                        'placeholder' => 'key-…',
                        'hint' => 'Stored encrypted. A domain-scoped sending key is enough; the private account key is not needed.',
                    ],
                    'domain' => [
                        'label' => 'Mailgun domain',
                        'secret' => false,
                        'placeholder' => 'mg.example.com',
                        'hint' => 'The domain as Mailgun spells it, which is often a subdomain of the one recipients see.',
                    ],
                    'endpoint' => [
                        'label' => 'API endpoint',
                        'secret' => false,
                        'placeholder' => 'api.eu.mailgun.net',
                        'hint' => 'api.mailgun.net for a US account, api.eu.mailgun.net for an EU one. The wrong one answers 401.',
                    ],
                    'signing_key' => [
                        'label' => 'Webhook signing key',
                        'secret' => true,
                        'placeholder' => 'Paste the HTTP webhook signing key',
                        'hint' => 'Stored encrypted. This is what verifies a bounce report actually came from Mailgun.',
                    ],
                ],
            ],
            self::SMTP => [
                'label' => 'Plain SMTP',
                'icon' => 'ki-abstract-25',
                'tone' => 'text-secondary-foreground',
                'transport' => 'smtp',
                'package' => null,
                'summary' => 'Any host with a mailbox. Fine for a handful, wrong for a campaign.',
                'requirement' => 'Use the host\'s own outgoing server only for small sends. Shared hosting rate-limits SMTP and one campaign through it can cost the account.',
                'webhook' => [
                    'signature' => null,
                    'hint' => 'A plain SMTP server reports nothing back, so bounces arrive as messages in the inbox rather than as callbacks. The endpoint exists so a relay that does report can be pointed at it.',
                ],
                'credentials' => [
                    'host' => [
                        'label' => 'Host',
                        'secret' => false,
                        'placeholder' => 'mail.example.com',
                        'hint' => 'The outgoing server, without a scheme.',
                    ],
                    'port' => [
                        'label' => 'Port',
                        'secret' => false,
                        'placeholder' => '587',
                        'hint' => '587 with STARTTLS is right almost everywhere; 465 is implicit TLS.',
                    ],
                    'username' => [
                        'label' => 'Username',
                        'secret' => false,
                        'placeholder' => 'you@example.com',
                        'hint' => 'Usually the full mailbox address.',
                    ],
                    'password' => [
                        'label' => 'Password',
                        'secret' => true,
                        'placeholder' => 'Mailbox password',
                        'hint' => 'Stored encrypted. It is never rendered back into this page once saved.',
                    ],
                    self::WEBHOOK_SECRET => [
                        'label' => 'Webhook secret',
                        'secret' => true,
                        'placeholder' => 'Any long random string',
                        'hint' => 'Only needed if a relay in front of this server posts bounce reports to Kargah.',
                    ],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function has(string $driver): bool
    {
        return array_key_exists($driver, self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(string $driver): ?array
    {
        return self::all()[$driver] ?? null;
    }

    /** The display name, falling back to the stored key so an unknown row still reads as something. */
    public static function label(string $driver): string
    {
        return self::all()[$driver]['label'] ?? ucfirst($driver);
    }

    /** A keenicon name that is known to exist in the bundle. */
    public static function icon(string $driver): string
    {
        return self::all()[$driver]['icon'] ?? 'ki-paper-plane';
    }

    public static function tone(string $driver): string
    {
        return self::all()[$driver]['tone'] ?? 'text-secondary-foreground';
    }

    /**
     * The composer package the Symfony bridge for this transport lives in, or
     * null when the transport ships with the framework.
     *
     * Read by the driver before it sends. None of the four bridges is installed
     * on a fresh Kargah, and a missing one has to read as 'run composer
     * require' on the provider page rather than as a class-not-found five
     * minutes into a campaign.
     */
    public static function package(string $driver): ?string
    {
        return self::all()[$driver]['package'] ?? null;
    }

    /** The credential field names this driver goes looking for. @return list<string> */
    public static function credentialFields(string $driver): array
    {
        return array_keys(self::all()[$driver]['credentials'] ?? []);
    }

    /**
     * The fields that must be present before the driver will send.
     *
     * The webhook secret is deliberately not one of them: a provider with no
     * callback configured still sends perfectly well, and refusing to send
     * because bounce reporting is unconfigured would be the wrong trade.
     *
     * @return list<string>
     */
    public static function requiredCredentialFields(string $driver): array
    {
        return array_values(array_filter(
            self::credentialFields($driver),
            fn (string $field): bool => $field !== self::WEBHOOK_SECRET,
        ));
    }

    public static function credentialLabel(string $driver, string $field): string
    {
        return self::all()[$driver]['credentials'][$field]['label'] ?? $field;
    }

    /** The signature scheme this provider offers, or null when it offers none. */
    public static function signatureScheme(string $driver): ?string
    {
        return self::all()[$driver]['webhook']['signature'] ?? null;
    }

    public static function webhookHint(string $driver): string
    {
        return self::all()[$driver]['webhook']['hint'] ?? '';
    }
}
