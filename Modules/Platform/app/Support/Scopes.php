<?php

namespace Modules\Platform\Support;

/**
 * The scopes an application password can carry.
 *
 * One list, in one place. The settings page renders it, the middleware
 * validates against it, and the API and the assistant will read the same
 * constants rather than each inventing a spelling — a scope string that exists
 * in two forms is a scope check that passes in one place and fails in another.
 *
 * The shape is `module:action`, so a scope names the module it reaches into and
 * nothing else can be inferred from it. Two distinctions are deliberate:
 *
 * - **`data:reveal` is not `data:read`.** Listing the vault and decrypting an
 *   entry are different powers. A token that can enumerate credential names is
 *   an inconvenience; a token that can decrypt them is the incident.
 * - **`mailbox:send` is not `mailbox:write`.** Writing a draft is reversible.
 *   Sending is not, and it costs money and reputation.
 */
final class Scopes
{
    public const CORE_READ = 'core:read';

    public const CORE_WRITE = 'core:write';

    public const PROJECT_READ = 'project:read';

    public const PROJECT_WRITE = 'project:write';

    public const ACCOUNTING_READ = 'accounting:read';

    public const ACCOUNTING_WRITE = 'accounting:write';

    public const MAILBOX_READ = 'mailbox:read';

    public const MAILBOX_SEND = 'mailbox:send';

    public const DATA_READ = 'data:read';

    public const DATA_REVEAL = 'data:reveal';

    public const SOCIAL_READ = 'social:read';

    public const SOCIAL_WRITE = 'social:write';

    /**
     * Every scope, in the order the settings page shows them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::describe());
    }

    /**
     * What each scope actually lets a credential do, in a sentence.
     *
     * The settings page prints these next to the checkbox. Somebody ticking a
     * box should not have to guess what they are handing over, and "write"
     * covers a much wider range of damage than the word suggests.
     *
     * @return array<string, string>
     */
    public static function describe(): array
    {
        return [
            self::CORE_READ => 'Read the account, companies and customers.',
            self::CORE_WRITE => 'Create and change companies and customers.',

            self::PROJECT_READ => 'Read boards, lists and cards.',
            self::PROJECT_WRITE => 'Create, change and move cards.',

            self::ACCOUNTING_READ => 'Read invoices, expenses and reports.',
            self::ACCOUNTING_WRITE => 'Draft and change invoices and expenses. Issuing stays a separate, deliberate action.',

            self::MAILBOX_READ => 'Read the inbox, threads and contacts.',
            self::MAILBOX_SEND => 'Send mail. Nothing sent can be taken back.',

            self::DATA_READ => 'List files, links and vault entries — names only, never a secret.',
            self::DATA_REVEAL => 'Decrypt a vault entry. Every reveal is logged against this credential.',

            self::SOCIAL_READ => 'Read scheduled and published posts.',
            self::SOCIAL_WRITE => 'Draft, schedule and publish posts.',
        ];
    }

    /**
     * The same list, grouped by module, for the checkbox column on the page.
     *
     * @return list<array{module: string, label: string, scopes: list<string>}>
     */
    public static function groups(): array
    {
        return [
            ['module' => 'core', 'label' => 'Account', 'scopes' => [self::CORE_READ, self::CORE_WRITE]],
            ['module' => 'project', 'label' => 'Projects', 'scopes' => [self::PROJECT_READ, self::PROJECT_WRITE]],
            ['module' => 'accounting', 'label' => 'Accounting', 'scopes' => [self::ACCOUNTING_READ, self::ACCOUNTING_WRITE]],
            ['module' => 'mailbox', 'label' => 'Mail', 'scopes' => [self::MAILBOX_READ, self::MAILBOX_SEND]],
            ['module' => 'data', 'label' => 'Data', 'scopes' => [self::DATA_READ, self::DATA_REVEAL]],
            ['module' => 'social', 'label' => 'Social', 'scopes' => [self::SOCIAL_READ, self::SOCIAL_WRITE]],
        ];
    }

    public static function isValid(string $scope): bool
    {
        return array_key_exists($scope, self::describe());
    }

    /**
     * Keep only real scopes, once each, in the canonical order.
     *
     * Order matters more than it looks: two credentials carrying the same
     * powers should store the same JSON, or every diff of the table is noise.
     *
     * @param  array<int|string, mixed>  $scopes
     * @return list<string>
     */
    public static function sanitise(array $scopes): array
    {
        $wanted = array_filter($scopes, 'is_string');

        return array_values(array_filter(
            self::all(),
            static fn (string $scope): bool => in_array($scope, $wanted, true),
        ));
    }

    /**
     * The whole badge class string for a scope. Never assembled from pieces:
     * Tailwind's scanner reads source as text and cannot see a class that only
     * exists once PHP has run.
     */
    public static function tone(string $scope): string
    {
        return match ($scope) {
            self::DATA_REVEAL, self::MAILBOX_SEND => 'kt-badge kt-badge-sm kt-badge-destructive',
            self::CORE_WRITE, self::PROJECT_WRITE, self::ACCOUNTING_WRITE, self::SOCIAL_WRITE => 'kt-badge kt-badge-sm kt-badge-warning',
            default => 'kt-badge kt-badge-sm kt-badge-outline',
        };
    }
}
