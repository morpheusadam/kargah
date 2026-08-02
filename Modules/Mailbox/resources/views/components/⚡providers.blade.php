<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Delivery providers.
 *
 * Sending never goes through the host's own mail server. Each provider is a
 * driver with its own daily quota, health score and sending domain, and the
 * router picks one per batch.
 */
new
#[Title('Providers — Kargah')]
class extends Component
{
    public function with(): array
    {
        return [
            'providers' => [
                [
                    'name' => 'Brevo', 'driver' => 'brevo', 'stream' => 'Marketing',
                    'domain' => 'news.kargah.dev', 'quota' => 300, 'used' => 0,
                    'health' => 100, 'status' => 'connected', 'icon' => 'ki-paper-plane',
                ],
                [
                    'name' => 'Resend', 'driver' => 'resend', 'stream' => 'Transactional',
                    'domain' => 'tx.kargah.dev', 'quota' => 100, 'used' => 0,
                    'health' => 100, 'status' => 'connected', 'icon' => 'ki-rocket',
                ],
                [
                    'name' => 'SMTP2GO', 'driver' => 'smtp2go', 'stream' => 'Failover',
                    'domain' => 'tx.kargah.dev', 'quota' => 33, 'used' => 0,
                    'health' => 100, 'status' => 'not_configured', 'icon' => 'ki-shield-tick',
                ],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Delivery providers</h1>
            <p class="text-sm text-secondary-foreground mt-1">Sending is routed across these, never through the host.</p>
        </div>
        <button class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Add provider
        </button>
    </div>

    <div class="kt-card bg-warning/5 border-warning/30">
        <div class="kt-card-content flex items-start gap-3 p-4">
            <i class="ki-filled ki-information-2 text-warning text-lg mt-0.5 shrink-0"></i>
            <div class="text-sm text-secondary-foreground">
                <strong class="text-mono">Keep SPF under 10 DNS lookups.</strong>
                Each provider you add to a single sending domain costs 1–3 lookups. Give marketing and
                transactional traffic separate subdomains so one campaign cannot damage the other's reputation.
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        @foreach ($providers as $p)
            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $p['icon'] }} text-xl"></i>
                            </span>
                            <div>
                                <div class="font-semibold text-mono">{{ $p['name'] }}</div>
                                <div class="text-xs text-muted-foreground">{{ $p['stream'] }}</div>
                            </div>
                        </div>
                        @if ($p['status'] === 'connected')
                            <span class="kt-badge kt-badge-sm kt-badge-success">Connected</span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-outline">Not set up</span>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 text-sm">
                        <i class="ki-filled ki-map text-muted-foreground text-base"></i>
                        <span class="text-secondary-foreground truncate">{{ $p['domain'] }}</span>
                    </div>

                    <div>
                        <div class="flex items-center justify-between text-xs mb-1.5">
                            <span class="text-muted-foreground">Daily quota</span>
                            <span class="text-mono font-medium">{{ $p['used'] }} / {{ $p['quota'] }}</span>
                        </div>
                        <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                            <div class="h-full bg-primary rounded-full" style="width: {{ $p['quota'] ? min(100, ($p['used'] / $p['quota']) * 100) : 0 }}%"></div>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <span class="text-xs text-muted-foreground">Health</span>
                        <span class="text-sm font-medium {{ $p['health'] >= 80 ? 'text-success' : 'text-warning' }}">{{ $p['health'] }}%</span>
                    </div>

                    <a href="{{ route('mail.provider-edit', $p['driver']) }}" class="kt-btn kt-btn-outline w-full justify-center gap-2">
                        <i class="ki-filled ki-setting-2"></i> Configure
                    </a>

                </div>
            </div>
        @endforeach
    </div>
</div>
