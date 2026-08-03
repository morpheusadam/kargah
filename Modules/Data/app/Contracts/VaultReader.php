<?php

namespace Modules\Data\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What other modules — and the API — may know about the vault.
 *
 * The vault is the one part of Kargah where the boundary is not only an
 * architectural preference. `Modules\Data\Models\Credential` carries three
 * virtual attributes — `secret`, `totp`, `notes` — that decrypt on read. A
 * consumer handed that model can decrypt every one of them silently and without
 * an activity entry, which is precisely the failure `Vault::reveal()` was
 * written to make impossible. So this contract exists as much to keep the model
 * out of Platform's hands as to keep a column rename inside Data.
 *
 * **Arrays out, never models. Metadata out, never plaintext.**
 *
 * Everything on this contract except `reveal()` answers without decrypting
 * anything. `has_secret`, `has_totp` and `has_notes` are derived from whether a
 * ciphertext column is populated — never from decrypting it and checking the
 * result — so listing the vault costs no decryptions at all and a listing
 * endpoint cannot become a leak by accident.
 *
 * `reveal()` is the single decrypting method, it delegates to
 * `Modules\Data\Services\Vault::reveal()` rather than reimplementing it, and it
 * therefore writes the same activity entry every other reveal in the
 * application writes. There is deliberately no `revealAll()` and no shape with
 * a `secret` key on it: one field, one call, one log line.
 *
 * @phpstan-type VaultEntryArray array{
 *     id: int, name: string, username: ?string, url: ?string,
 *     category: ?array{id: int, name: string, colour: ?string},
 *     company: ?array{id: int, name: string},
 *     has_secret: bool, has_totp: bool, has_notes: bool,
 *     last_revealed_at: ?string, rotated_at: ?string,
 *     created_at: ?string, updated_at: ?string
 * }
 */
interface VaultReader
{
    /**
     * The fields `reveal()` will decrypt.
     *
     * Mirrors `Modules\Data\Services\Vault::FIELDS` so a consumer can validate
     * an inbound field name without importing the service — and so adding a
     * fourth encrypted column is one change rather than two.
     *
     * @return list<string>
     */
    public function revealableFields(): array;

    /** One entry's metadata, or null when it does not exist. No secret, ever. */
    public function find(int $id): ?array;

    /**
     * A page of the vault, by name — metadata only.
     *
     * Searching matches only the columns a person can already see on the page.
     * An encrypted column cannot be searched anyway, and pretending otherwise
     * is how a plaintext index gets added later.
     *
     * @return array{items: list<VaultEntryArray>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function entries(string $search = '', ?int $categoryId = null, ?string $cursor = null, int $perPage = 20): array;

    /**
     * How the vault is grouped, with how many entries sit in each.
     *
     * @return list<array{id: int, name: string, colour: ?string, entry_count: int}>
     */
    public function categories(): array;

    /**
     * Decrypt one field of one entry, and write the activity entry that says so.
     *
     * Returns null when the field is empty or the ciphertext cannot be read
     * under the current `APP_KEY`; the log entry is written either way, because
     * an attempted read is at least as interesting to an auditor as a
     * successful one. A caller that needs to distinguish "no such entry" from
     * "that field is empty" calls `find()` first — this method answers null for
     * an unknown id too, and it does not log for one, because logging a reveal
     * against a row that does not exist would put attacker-chosen ids into an
     * append-only table.
     *
     * `$via` names what did the revealing — the API passes the application
     * password's name, so a reveal through a credential is distinguishable from
     * one through the vault page even though both are caused by the same user.
     *
     * @param  'secret'|'totp'|'notes'  $field
     *
     * @throws \InvalidArgumentException when `$field` is not revealable.
     */
    public function reveal(int $id, string $field = 'secret', ?Authenticatable $causer = null, ?string $via = null): ?string;
}
