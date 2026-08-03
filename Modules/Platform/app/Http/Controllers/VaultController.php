<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Data\Contracts\VaultReader;
use Modules\Platform\Http\Middleware\AuthenticateApplicationPassword;
use Modules\Platform\Http\Resources\VaultEntryResource;
use Modules\Platform\Support\ApiResponse;

/**
 * The vault over HTTP.
 *
 * `GET /api/v1/vault`, `GET /api/v1/vault/categories`,
 * `GET /api/v1/vault/{credential}` — `data:read`, **names and metadata only**.
 * `POST /api/v1/vault/{credential}/reveal` — `data:reveal`, one field, logged.
 *
 * ---
 *
 * ## What a caller may read, and why that line and not another
 *
 * The vault is the only endpoint in this API where getting the answer wrong
 * hands somebody every password the owner has. The brief for it was explicit:
 * a list of entry names with no secret values is a defensible default;
 * returning decrypted secrets over HTTP Basic is not, *unless a dedicated scope
 * gates it*. That parenthesis is the whole decision, so here it is made
 * deliberately rather than by omission.
 *
 * **Listing is `data:read` and never decrypts anything.** Not "does not return
 * the secret" — does not decrypt it. `Modules\Data\Models\Credential` exposes
 * `secret`, `totp` and `notes` as accessors that decrypt on read, so a shape
 * built by asking "is the secret empty?" would decrypt every row of every page
 * and leave no activity entry behind. `VaultReader` reads the `_encrypted`
 * columns for emptiness instead, and this endpoint therefore costs zero
 * decryptions no matter how many times it is called. `has_secret`,
 * `has_totp` and `has_notes` say that something is stored. They do not say what,
 * and they do not say how long it is — `Credential::mask()` is a fixed twelve
 * characters for exactly that reason, and no length appears here either.
 *
 * **Revealing exists, and is gated by `data:reveal`.** Refusing to build it at
 * all was the other option and it was rejected: `Scopes::describe()` already
 * promises `data:reveal` means "decrypt a vault entry, and every reveal is
 * logged against this credential", `07-platform.md` names "credentials (list;
 * reveal separately scoped)" in the surface it asks for, and the scope was
 * split from `data:read` in the first place precisely so that this endpoint
 * could exist without the listing one implying it. A scope that gates nothing
 * is a promise the settings page makes and the API does not keep — the owner
 * ticking `data:reveal` would be handing over a power that has no effect, which
 * is worse than either honest answer.
 *
 * Four things hold it in place:
 *
 * 1. **`POST`, not `GET`.** A decrypt is an action with a side effect — it
 *    writes an activity entry and moves `last_revealed_at` — not a resource
 *    read. A GET would be cached by an intermediary, replayed by a prefetcher,
 *    and would put the entry id in every access log between here and the
 *    client. The response carries `Cache-Control: no-store` for the same
 *    reason.
 * 2. **One field per call, named in the body.** There is no `revealAll()` on
 *    the contract and no shape anywhere with a `secret` key on it. A caller
 *    that wants three fields makes three calls and writes three log lines,
 *    which is what an auditor needs to see.
 * 3. **`totp` is refused here, though `Vault` can decrypt it.** The TOTP
 *    *seed* is the second factor itself, permanently: handing it over is
 *    handing over every future code for the life of the account, where a
 *    revealed password can at least be rotated. The vault page derives codes
 *    server-side rather than sending the seed to the browser, and an API client
 *    is further away than the browser is, not closer. `secret` and `notes` are
 *    revealable; `totp` is not, and the 422 says so.
 * 4. **Every reveal names the credential that did it.** `VaultReader::reveal()`
 *    delegates to `Modules\Data\Services\Vault::reveal()`, which writes the
 *    activity entry in the same operation as the decrypt, and this controller
 *    passes the application password's name as `via`. An application password
 *    belongs to the owner, so without that the log could not tell an API reveal
 *    from somebody clicking the button on the vault page — and after an
 *    incident, knowing which credential to revoke is the entire point of having
 *    named, individually revocable credentials at all.
 *
 * What is deliberately still absent: any write. There is no create, no rotate,
 * no delete. `data:write` is not one of the twelve scopes, and a credential
 * store that a leaked API token can *edit* is a different and much worse
 * failure than one it can read.
 */
class VaultController
{
    /**
     * The fields this API will decrypt — a subset of `Vault::FIELDS`, which
     * also contains `totp`. See point 3 above.
     */
    private const REVEALABLE = ['secret', 'notes'];

    public function __construct(private readonly VaultReader $vault) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'q' => ['sometimes', 'string', 'max:200'],
            'category_id' => ['sometimes', 'integer', 'min:1'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $categoryId = $request->query('category_id');

        $page = $this->vault->entries(
            trim((string) $request->query('q', '')),
            $categoryId === null ? null : (int) $categoryId,
            $request->query('cursor'),
            (int) $request->query('per_page', 20),
        );

        return ApiResponse::page($page, VaultEntryResource::class);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->vault->categories()]);
    }

    public function show(int $credential): JsonResponse
    {
        $found = $this->vault->find($credential);

        if ($found === null) {
            return ApiResponse::notFound("Vault entry {$credential} does not exist.");
        }

        return response()->json(['data' => new VaultEntryResource($found)]);
    }

    /**
     * Decrypt one field of one entry.
     *
     * `value` is null when the field is empty, and also when the stored
     * ciphertext cannot be read under the current `APP_KEY` — the two are
     * indistinguishable from here and from the service below, and pretending
     * otherwise would mean guessing. The activity entry is written either way:
     * an attempted read is at least as interesting to an auditor as a
     * successful one.
     */
    public function reveal(Request $request, int $credential): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'field' => ['sometimes', 'string', 'in:'.implode(',', self::REVEALABLE)],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $entry = $this->vault->find($credential);

        if ($entry === null) {
            return ApiResponse::notFound("Vault entry {$credential} does not exist.");
        }

        $field = (string) $request->input('field', 'secret');

        $value = $this->vault->reveal(
            $credential,
            $field,
            $request->user(),
            // The name the owner gave this credential, so the log line says
            // which one to revoke rather than only who owns it.
            AuthenticateApplicationPassword::credential($request)?->name,
        );

        return response()->json([
            'data' => [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'field' => $field,
                'value' => $value,
            ],
        ])->withHeaders([
            // Never in a shared cache, never on disk in a proxy, never in the
            // browser's back-forward cache. This is the one response in the API
            // that carries a plaintext secret.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }
}
