<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Credential vault.
 *
 * Secrets are encrypted at rest with the app key and only ever decrypted for a
 * single reveal action — never rendered into the list markup.
 */
new
#[Title('Passwords — Kargah')]
class extends Component
{
    public string $search = '';

    public ?int $revealed = null;

    public function with(): array
    {
        return [
            'entries' => [
                ['id' => 1, 'name' => 'Hostinger hPanel', 'username' => 'morph',              'url' => 'hpanel.hostinger.com', 'category' => 'Hosting',  'updated' => '2026-07-29'],
                ['id' => 2, 'name' => 'Brevo API',        'username' => 'api-key',            'url' => 'app.brevo.com',        'category' => 'Email',    'updated' => '2026-07-25'],
                ['id' => 3, 'name' => 'GitHub PAT',       'username' => 'morpheusadam',       'url' => 'github.com',           'category' => 'Dev',      'updated' => '2026-07-20'],
            ],
        ];
    }

    public function reveal(int $id): void
    {
        $this->revealed = $this->revealed === $id ? null : $id;
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Passwords</h1>
            <p class="text-sm text-secondary-foreground mt-1">Encrypted at rest, revealed one at a time.</p>
        </div>
        <button class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Add credential
        </button>
    </div>

    <div class="kt-card">
        <div class="kt-card-header">
            <div class="kt-input max-w-[260px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search credentials…" wire:model.live.debounce.300ms="search">
            </div>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[180px]">Name</th>
                            <th class="min-w-[160px]">Username</th>
                            <th class="min-w-[180px]">Secret</th>
                            <th class="w-[120px]">Category</th>
                            <th class="w-[120px]">Updated</th>
                            <th class="w-[90px] text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $e)
                            <tr>
                                <td>
                                    <div class="font-medium text-mono">{{ $e['name'] }}</div>
                                    <div class="text-xs text-muted-foreground">{{ $e['url'] }}</div>
                                </td>
                                <td class="text-secondary-foreground">{{ $e['username'] }}</td>
                                <td>
                                    <code class="text-xs px-2 py-1 rounded bg-muted text-secondary-foreground">
                                        {{ $revealed === $e['id'] ? 'decrypted-on-demand' : '••••••••••••' }}
                                    </code>
                                </td>
                                <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $e['category'] }}</span></td>
                                <td class="text-secondary-foreground">{{ $e['updated'] }}</td>
                                <td class="text-end">
                                    <div class="flex justify-end gap-1">
                                        <button wire:click="reveal({{ $e['id'] }})" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Reveal">
                                            <i class="ki-filled {{ $revealed === $e['id'] ? 'ki-eye-slash' : 'ki-eye' }} text-sm"></i>
                                        </button>
                                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Copy">
                                            <i class="ki-filled ki-copy text-sm"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
