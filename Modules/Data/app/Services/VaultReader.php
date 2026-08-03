<?php

namespace Modules\Data\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Modules\Data\Contracts\VaultReader as VaultReaderContract;
use Modules\Data\Models\Credential;
use Modules\Data\Models\CredentialCategory;

/**
 * `VaultReader` over the real table.
 *
 * Note what `shape()` does not do: it never touches `$credential->secret`,
 * `->totp` or `->notes`. Those are decrypting accessors, and reading one to
 * find out whether it is empty would decrypt every row of every listing — with
 * no activity entry to show for it. `hasTotp()` and `hasNotes()` read the
 * ciphertext column directly for exactly that reason, and `has_secret` does the
 * same thing inline.
 */
class VaultReader implements VaultReaderContract
{
    public function __construct(private readonly Vault $vault) {}

    public function revealableFields(): array
    {
        return Vault::FIELDS;
    }

    public function find(int $id): ?array
    {
        $credential = $this->query()->find($id);

        return $credential === null ? null : $this->shape($credential);
    }

    public function entries(string $search = '', ?int $categoryId = null, ?string $cursor = null, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));

        $query = $this->query()->search($search);

        if ($categoryId !== null) {
            $query->where('category_id', $categoryId);
        }

        $decoded = $cursor === null || $cursor === ''
            ? null
            : rescue(fn (): ?Cursor => Cursor::fromEncoded($cursor), null, false);

        $paginator = $query->orderBy('id')->cursorPaginate($perPage, ['*'], 'cursor', $decoded);

        return [
            'items' => $paginator->getCollection()->map(fn (Credential $c): array => $this->shape($c))->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    public function categories(): array
    {
        return CredentialCategory::query()
            ->withCount('credentials')
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(fn (CredentialCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'colour' => $category->colour,
                'entry_count' => (int) ($category->credentials_count ?? 0),
            ])
            ->all();
    }

    public function reveal(int $id, string $field = 'secret', ?Authenticatable $causer = null, ?string $via = null): ?string
    {
        $credential = Credential::query()->find($id);

        if ($credential === null) {
            // Deliberately no log line. An id that does not exist is not a
            // reveal, and writing one would let a caller put arbitrary numbers
            // into an append-only table by guessing.
            return null;
        }

        // `Vault::reveal()`, never `$credential->{$field}`: the decrypt and the
        // activity entry are one operation, and separating them is the exact
        // failure that service exists to prevent.
        return $this->vault->reveal($credential, $field, $causer, $via);
    }

    private function query(): Builder
    {
        return Credential::query()->with(['category', 'company']);
    }

    /** @return array<string, mixed> */
    private function shape(Credential $credential): array
    {
        return [
            'id' => $credential->id,
            'name' => $credential->name,
            'username' => $credential->username,
            'url' => $credential->url,
            'category' => $credential->category === null ? null : [
                'id' => $credential->category->id,
                'name' => $credential->category->name,
                'colour' => $credential->category->colour,
            ],
            'company' => $credential->company === null ? null : [
                'id' => $credential->company->id,
                'name' => $credential->company->name,
            ],
            // Whether a ciphertext column is populated, not what is in it.
            'has_secret' => ($credential->getAttributes()['secret_encrypted'] ?? null) !== null,
            'has_totp' => $credential->hasTotp(),
            'has_notes' => $credential->hasNotes(),
            'last_revealed_at' => $credential->last_revealed_at?->toIso8601String(),
            'rotated_at' => $credential->rotated_at?->toIso8601String(),
            'created_at' => $credential->created_at?->toIso8601String(),
            'updated_at' => $credential->updated_at?->toIso8601String(),
        ];
    }
}
