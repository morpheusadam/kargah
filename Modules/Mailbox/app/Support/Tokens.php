<?php

namespace Modules\Mailbox\Support;

use Illuminate\Support\Facades\Log;

/**
 * The tokens a sent message carries back to Kargah.
 *
 * One goes in the unsubscribe URL, one in the `Reply-To` local part, and two
 * more in the tracking pixel and the rewritten links. All of them have to
 * survive a round trip through somebody else's mail system and come back
 * identifying exactly one row.
 *
 * They are **derived and signed** rather than random. Two reasons:
 *
 * - The IMAP side can recognise a reply address without a database lookup, and
 *   more importantly can tell a token Kargah issued from a string somebody
 *   made up. A random token would need a query to answer that, and a query
 *   that returns nothing cannot distinguish 'forged' from 'deleted'.
 * - They are stable. Re-running a chunk regenerates the same token for the
 *   same recipient, so an idempotent job does not invalidate a link that has
 *   already gone out in a message.
 *
 * The signature is an HMAC over the purpose and the row id, keyed on the
 * application key. The purpose is in the hash so an unsubscribe token cannot be
 * replayed as a reply token or the other way round; without it the two would be
 * the same string and either endpoint would accept either.
 *
 * The local part of a `Reply-To` address is what constrains the alphabet.
 * Base36 for the id and hex for the signature keep it to characters no mail
 * system rewrites, and the whole token stays inside the 64-byte local-part
 * limit and the 64-character column.
 */
final class Tokens
{
    public const UNSUBSCRIBE = 'unsubscribe';

    public const REPLY = 'reply';

    /** The tracking pixel. Names a recipient. */
    public const OPEN = 'open';

    /** The recipient half of a rewritten link. */
    public const CLICK = 'click';

    /**
     * The link half of a rewritten link, and the one token here that does not
     * name a recipient row.
     *
     * It names a `campaign_links` row instead, so that the two halves of a click
     * URL are independently verifiable and neither can be lifted out of one
     * message and pasted into another's place. The purpose in the hash is what
     * keeps them apart: a link token cannot be replayed as a recipient token
     * even though both are a base36 id and a signature.
     */
    public const LINK = 'link';

    /**
     * How much of the HMAC travels with the token.
     *
     * Twenty hex characters is 80 bits. Forging one is not a matter of guessing
     * an id — the attacker has to find a signature that verifies, and 2^80 is
     * far beyond what a public HTTP endpoint could ever be asked. Longer would
     * cost nothing here but would push a `Reply-To` local part towards the
     * length some older relays truncate.
     */
    private const SIGNATURE_LENGTH = 20;

    /** Mint the token for one row. Deterministic: the same row always gives the same token. */
    public static function for(string $purpose, int $rowId): string
    {
        $id = base_convert((string) $rowId, 10, 36);

        return $id.'-'.self::signature($purpose, $id);
    }

    /**
     * The recipient a token names, or null when it was not issued here.
     *
     * The same check as `idFrom`, under the name three of the four purposes
     * actually mean. Kept because 'which recipient is this' is what the
     * unsubscribe route and the IMAP side are asking, and reading that at the
     * call site is worth one line of delegation.
     */
    public static function recipientFrom(string $purpose, string $token): ?int
    {
        return self::idFrom($purpose, $token);
    }

    /**
     * The row a token names, or null when it was not issued here.
     *
     * Verified before it is decoded, and compared with `hash_equals` so the
     * comparison does not leak how much of a forged signature was right. Null
     * covers both 'wrong shape' and 'wrong signature', because the caller's
     * response to either is the same and telling them apart only helps whoever
     * is probing.
     */
    public static function idFrom(string $purpose, string $token): ?int
    {
        $parts = explode('-', trim($token));

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        [$id, $signature] = $parts;

        if (! hash_equals(self::signature($purpose, $id), $signature)) {
            return null;
        }

        $rowId = (int) base_convert($id, 36, 10);

        return $rowId > 0 ? $rowId : null;
    }

    /**
     * The `Reply-To` address a reply comes back to.
     *
     * The token sits in the local part after a plus, which every mail system
     * delivers to the base mailbox — so replies land in the inbox Kargah
     * already syncs, and `mailbox:sync-imap` can match one to its campaign by
     * reading the address it was sent to.
     */
    public static function replyAddress(int $recipientId, string $mailbox): string
    {
        [$local, $domain] = self::split($mailbox);

        return $local.'+'.self::for(self::REPLY, $recipientId).'@'.$domain;
    }

    /**
     * The recipient a `Reply-To` address names, or null when it carries no token
     * Kargah issued.
     *
     * Public because this is the half the IMAP side calls: it reads the
     * envelope's To or Delivered-To and asks who the reply belongs to.
     */
    public static function recipientFromAddress(string $address): ?int
    {
        [$local] = self::split($address);

        // The *last* plus, not the first. A from-address may already carry a
        // sub-address of its own — `nima+news@example.com` is a perfectly
        // ordinary thing to send from — and reading the first one would hand
        // the verifier everything after it, signature and all.
        $plus = strrpos($local, '+');

        if ($plus === false) {
            return null;
        }

        return self::recipientFrom(self::REPLY, substr($local, $plus + 1));
    }

    /**
     * @return array{0: string, 1: string} The local part and the domain.
     */
    private static function split(string $address): array
    {
        $at = strrpos($address, '@');

        if ($at === false) {
            // A configured from-address with no domain is a misconfiguration
            // rather than an exception: the send is already under way and
            // failing here would take the whole chunk down. It is logged, and
            // the resulting address is obviously wrong rather than silently
            // plausible.
            Log::warning('mailbox: "'.$address.'" is not an email address, so replies to it cannot be threaded.');

            return [$address, 'invalid.local'];
        }

        return [substr($address, 0, $at), substr($address, $at + 1)];
    }

    private static function signature(string $purpose, string $id): string
    {
        return substr(hash_hmac('sha256', $purpose.':'.$id, self::key()), 0, self::SIGNATURE_LENGTH);
    }

    /**
     * The application key, as bytes.
     *
     * Deliberately the same secret Laravel signs URLs with. A token issued
     * before the key was rotated stops verifying, which is correct — after a
     * rotation, every link Kargah ever sent should be treated as somebody
     * else's.
     */
    private static function key(): string
    {
        return (string) config('app.key');
    }
}
