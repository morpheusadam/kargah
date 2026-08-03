<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vault entry's metadata, as `Modules\Data\Contracts\VaultReader` hands it
 * back — **never a secret**.
 *
 * This one names every field rather than passing the array through, which is
 * the opposite of what `CompanyResource` and `BoardResource` next door do, and
 * the reason is the only reason that matters here: on those two, a key Core or
 * Project adds tomorrow becomes an undecided part of this API. On this one, a
 * key Data adds tomorrow could be a decrypted password.
 *
 * So the allowlist is explicit and it is short. `has_secret`, `has_totp` and
 * `has_notes` are the whole of what this endpoint says about the contents of an
 * entry: that something is stored, not what. Revealing is
 * `POST /api/v1/vault/{id}/reveal`, a different verb behind a different scope,
 * and it never travels inside a listing.
 */
class VaultEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'name' => $this->resource['name'],
            'username' => $this->resource['username'],
            'url' => $this->resource['url'],
            'category' => $this->resource['category'],
            'company' => $this->resource['company'],
            'has_secret' => $this->resource['has_secret'],
            'has_totp' => $this->resource['has_totp'],
            'has_notes' => $this->resource['has_notes'],
            'last_revealed_at' => $this->resource['last_revealed_at'],
            'rotated_at' => $this->resource['rotated_at'],
            'created_at' => $this->resource['created_at'],
            'updated_at' => $this->resource['updated_at'],
        ];
    }
}
