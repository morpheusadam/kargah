<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

/**
 * `timezone`, `locale`, `date_format` and `bio` are plain preferences and are
 * mass-assignable. `two_factor_secret_encrypted` and
 * `two_factor_recovery_codes_encrypted` are deliberately absent from both this
 * list and `$fillable` — see the two virtual attributes below for why, and
 * `project-guaid/DECISIONS.md` for the mutator idiom that looks identical to
 * this one and stores the column in clear text.
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'locale', 'date_format', 'bio', 'colour_blind_mode'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret_encrypted', 'two_factor_recovery_codes_encrypted'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            // Whether label chips carry a pattern overlay as well as a colour —
            // see `Modules\Project\Support\Palette::pattern()`. Global, not
            // per-board: the same person is colour-blind on every board.
            'colour_blind_mode' => 'boolean',
        ];
    }

    /**
     * Two letters for an avatar chip. Same rule as Core's Customer, because a
     * person should read the same whichever table they came out of.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1).mb_substr($parts[count($parts) - 1], 0, 1));
        }

        return mb_strtoupper(mb_substr((string) $this->name, 0, 2));
    }

    /* Two-factor authentication ---------------------------------------------
     *
     * The pattern is `Modules\Data\Models\Credential`'s: the plaintext is
     * never an attribute in its own right. Reading `two_factor_secret`
     * decrypts on the spot, writing it encrypts on the spot, and the
     * ciphertext columns are `$hidden` besides — `Attribute::make(set: fn
     * ($v) => ['col' => $v])`, the form Laravel's own documentation shows,
     * merges the closure's return value straight into the raw attribute
     * array with no cast applied, and stores the column in clear text. This
     * encrypts inside the setter instead, so there is no cast to forget.
     */

    protected function twoFactorSecret(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decryptColumn('two_factor_secret_encrypted'),
            set: fn (?string $value): array => ['two_factor_secret_encrypted' => $this->encryptColumn($value)],
        )->withoutObjectCaching();
    }

    /**
     * The recovery codes, as the list of `Hash::make()` digests stored
     * against them — never the plaintext, which is shown once at generation
     * and kept nowhere. The list is wrapped in `Crypt::encryptString()` as
     * well as hashed: a bcrypt digest cannot be reversed, but the column name
     * promises `NoSecretsInHtmlTest` an encrypted column, and encrypting the
     * whole blob at rest costs nothing extra to be exactly that.
     *
     * @return list<string>
     */
    protected function twoFactorRecoveryCodes(): Attribute
    {
        return Attribute::make(
            get: function (): array {
                $json = $this->decryptColumn('two_factor_recovery_codes_encrypted');

                if ($json === null) {
                    return [];
                }

                $decoded = json_decode($json, true);

                return is_array($decoded) ? array_values($decoded) : [];
            },
            set: fn (array $value): array => [
                'two_factor_recovery_codes_encrypted' => $value === []
                    ? null
                    : $this->encryptColumn(json_encode(array_values($value))),
            ],
        )->withoutObjectCaching();
    }

    /**
     * Two-factor is "on" only once a code has actually been checked against
     * the stored secret. A secret written by `startTwoFactorEnrollment()` and
     * never confirmed must read as off, or an abandoned setup would lock the
     * owner out believing a second factor protects an account that never
     * proved it could produce one.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * Try a recovery code against every stored digest, and burn it on a
     * match — each code works once, the same as any code Kargah does not
     * reuse. Returns whether it matched.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $code = trim($code);

        if ($code === '') {
            return false;
        }

        $hashes = $this->two_factor_recovery_codes;
        $remaining = [];
        $matched = false;

        foreach ($hashes as $hash) {
            if (! $matched && Hash::check($code, $hash)) {
                $matched = true;

                continue;
            }

            $remaining[] = $hash;
        }

        if (! $matched) {
            return false;
        }

        $this->two_factor_recovery_codes = $remaining;
        $this->save();

        return true;
    }

    /**
     * Ten fresh, single-use codes, and the digests to store against them.
     * Generation lives here rather than in `App\Support\Totp` because it has
     * nothing to do with RFC 6238 — it is the same CSPRNG-and-alphabet shape
     * `Modules\Platform\Models\ApplicationPassword::generateSecret()` uses for
     * the same reason: lower case and digits only, nothing a shell or a typo
     * has to fight with.
     *
     * @return array{plaintext: list<string>, hashed: list<string>}
     */
    public static function generateRecoveryCodes(int $count = 10): array
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $last = strlen($alphabet) - 1;

        $plaintext = [];

        for ($i = 0; $i < $count; $i++) {
            $code = '';

            for ($group = 0; $group < 2; $group++) {
                if ($group > 0) {
                    $code .= '-';
                }

                for ($char = 0; $char < 5; $char++) {
                    $code .= $alphabet[random_int(0, $last)];
                }
            }

            $plaintext[] = $code;
        }

        return [
            'plaintext' => $plaintext,
            'hashed' => array_map(static fn (string $code): string => Hash::make($code), $plaintext),
        ];
    }

    private function decryptColumn(string $column): ?string
    {
        $stored = $this->attributes[$column] ?? null;

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // A row written under a rotated APP_KEY cannot be read. Reading
            // "is two-factor set up" should not crash a settings page over
            // it — `hasTwoFactorEnabled()` and the enrolment flow both treat
            // this the same as no secret at all.
            return null;
        }
    }

    private function encryptColumn(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : Crypt::encryptString($value);
    }
}
