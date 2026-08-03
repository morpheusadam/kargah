<?php

namespace Modules\Data\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use InvalidArgumentException;
use Modules\Data\Models\Credential;
use Modules\Data\Support\Totp;

/**
 * Reading a secret, and the record that it was read.
 *
 * Every decryption in the application goes through `reveal()`. Not because the
 * model could not decrypt on its own — it can — but because a password manager
 * whose access is invisible is a password manager nobody can audit after an
 * incident. Putting the log write next to the decrypt is what makes the two
 * impossible to separate by accident.
 *
 * `generate()` lives here rather than in the form component so that a secret
 * created by a command, a seeder or a future API is drawn from the same pool as
 * one created by a person.
 */
class Vault
{
    /** The fields that are encrypted and therefore revealable. */
    public const FIELDS = ['secret', 'totp', 'notes'];

    /**
     * Decrypt one field of one entry, and say so in the activity log.
     *
     * Returns null when the field is empty or when the stored ciphertext cannot
     * be read under the current `APP_KEY`. The log entry is still written in
     * that case: an attempted read is exactly as interesting to an auditor as a
     * successful one, and arguably more so.
     */
    public function reveal(Credential $credential, string $field = 'secret', ?Authenticatable $causer = null): ?string
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException('A credential has no revealable field called '.$field.'.');
        }

        $value = $credential->{$field};

        activity('credential')
            ->performedOn($credential)
            ->causedBy($causer ?? auth()->user())
            ->event('credential.revealed')
            ->withProperties([
                // The field name, never the value. This table is append-only.
                'field' => $field,
                'credential' => $credential->name,
            ])
            ->log('revealed the '.$this->fieldLabel($field).' for '.$credential->name);

        // Timestamps stay put: `updated_at` means the entry changed, and reading
        // one does not change it. `last_revealed_at` is the column that answers
        // "when was this last used", and it is the one that moves.
        Credential::withoutTimestamps(
            fn () => $credential->forceFill(['last_revealed_at' => now()])->saveQuietly()
        );

        return $value;
    }

    /**
     * The current TOTP code for an entry, with the seconds it has left.
     *
     * Derived server-side on every call. Handing the seed to the browser so it
     * could run its own countdown would put the second factor in the page,
     * which is the one thing the vault exists to prevent.
     *
     * @return array{code: string, seconds: int}|null
     */
    public function totpCode(Credential $credential): ?array
    {
        $seed = $credential->totp;

        if ($seed === null) {
            return null;
        }

        $code = Totp::code($seed);

        return $code === null ? null : ['code' => $code, 'seconds' => Totp::secondsRemaining()];
    }

    /**
     * A random secret.
     *
     * `random_int` rather than `rand`: this is a password, so the generator has
     * to be the cryptographic one. Look-alike characters are excluded by
     * default because a secret is read aloud down a phone more often than
     * anyone admits, and `l` against `1` costs a support call.
     */
    public function generate(
        int $length = 20,
        bool $useUpper = true,
        bool $useDigits = true,
        bool $useSymbols = true,
        bool $avoidAmbiguous = true,
    ): string {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%^&*()-_=+[]{}<>?';

        if (! $avoidAmbiguous) {
            $lower .= 'jl';
            $upper .= 'IO';
            $digits .= '01';
        }

        $pool = $lower
            .($useUpper ? $upper : '')
            .($useDigits ? $digits : '')
            .($useSymbols ? $symbols : '');

        $length = max(8, min(64, $length));
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $pool[random_int(0, strlen($pool) - 1)];
        }

        return $out;
    }

    /**
     * A rough read on a secret's strength: length plus character variety.
     *
     * For the meter only. It is never a gate on saving — refusing to store a
     * password the provider already forced on you helps nobody.
     *
     * @return array{score: int, label: string, tone: string, text: string, width: int}
     */
    public function strength(string $value): array
    {
        if ($value === '') {
            return ['score' => 0, 'label' => 'Empty', 'tone' => 'bg-muted', 'text' => 'text-muted-foreground', 'width' => 0];
        }

        $score = 0;
        $score += mb_strlen($value) >= 12 ? 1 : 0;
        $score += mb_strlen($value) >= 20 ? 1 : 0;
        $score += preg_match('/[a-z]/', $value) && preg_match('/[A-Z]/', $value) ? 1 : 0;
        $score += preg_match('/\d/', $value) ? 1 : 0;
        $score += preg_match('/[^A-Za-z0-9]/', $value) ? 1 : 0;

        return match (true) {
            $score <= 1 => ['score' => $score, 'label' => 'Weak', 'tone' => 'bg-destructive', 'text' => 'text-destructive', 'width' => 25],
            $score === 2 => ['score' => $score, 'label' => 'Fair', 'tone' => 'bg-warning', 'text' => 'text-warning', 'width' => 50],
            $score === 3 => ['score' => $score, 'label' => 'Good', 'tone' => 'bg-info', 'text' => 'text-info', 'width' => 75],
            default => ['score' => $score, 'label' => 'Strong', 'tone' => 'bg-success', 'text' => 'text-success', 'width' => 100],
        };
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'totp' => 'TOTP seed',
            'notes' => 'notes',
            default => 'secret',
        };
    }
}
