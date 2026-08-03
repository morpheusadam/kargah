<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Cross-network composer.
 *
 * Write once, pick targets, publish or schedule. The shared text feeds every
 * network unless a network has been given its own copy, and each target renders
 * a live preview so the limit is something you see rather than something you
 * are told about after the fact.
 */
new
#[Title('Publish — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $body = "Shipped the drag-and-drop board in Kargah this week. Cards keep their order after a refresh, which sounds trivial until you try it without a full page reload.\n\nIt is Livewire 4 single-file components plus a thin Sortable wrapper — no SPA, no build step to babysit. Runs on a small shared host and still feels instant.\n\nWriting up how the ordering works next. Happy to share the code if anyone wants a look.";

    public array $targets = ['telegram', 'linkedin', 'x'];

    /** Per-network copy. A key exists only while that network is overridden. */
    public array $overrides = [];

    public string $schedule = 'now';

    public string $scheduledAt = '';

    /** @var array<int, array{name: string, thumb: string, kind: string, size: string}> */
    public array $media = [
        ['name' => 'board-drag-drop.png', 'thumb' => '/assets/media/images/600x400/8.jpg',  'kind' => 'Image', 'size' => '412 KB'],
        ['name' => 'order-persist.png',   'thumb' => '/assets/media/images/600x400/11.jpg', 'kind' => 'Image', 'size' => '268 KB'],
    ];

    public function with(): array
    {
        $networks = $this->networks();

        return [
            'networks' => $networks,
            'selected' => array_values(array_filter(
                $networks,
                fn (array $n): bool => in_array($n['key'], $this->targets, true),
            )),
        ];
    }

    /** @return array<int, array{key: string, label: string, icon: string, limit: int, connected: bool}> */
    public function networks(): array
    {
        return [
            ['key' => 'telegram',  'label' => 'Telegram',  'icon' => 'ki-paper-plane',        'limit' => 4096, 'connected' => true],
            ['key' => 'linkedin',  'label' => 'LinkedIn',  'icon' => 'ki-abstract-41', 'limit' => 3000, 'connected' => true],
            ['key' => 'x',         'label' => 'X',         'icon' => 'ki-abstract-39', 'limit' => 280,  'connected' => true],
            ['key' => 'instagram', 'label' => 'Instagram', 'icon' => 'ki-instagram',   'limit' => 2200, 'connected' => false],
        ];
    }

    /** The copy a given network will actually receive. */
    public function textFor(string $key): string
    {
        return $this->overrides[$key] ?? $this->body;
    }

    public function isOverridden(string $key): bool
    {
        return array_key_exists($key, $this->overrides);
    }

    /** Display name for a network key, for anything the user reads. */
    protected function labelFor(string $key): string
    {
        $network = collect($this->networks())->firstWhere('key', $key);

        return $network['label'] ?? $key;
    }

    public function toggleTarget(string $key): void
    {
        $network = collect($this->networks())->firstWhere('key', $key);
        $label = $network['label'] ?? $key;

        $this->targets = in_array($key, $this->targets, true)
            ? array_values(array_diff($this->targets, [$key]))
            : [...$this->targets, $key];

        if ($network && ! $network['connected']) {
            $this->toastWarning($label.' is not connected', 'Connect the account before publishing to it.');

            return;
        }

        in_array($key, $this->targets, true)
            ? $this->toastSuccess($label.' added', 'It is now a target for this post.')
            : $this->toastSuccess($label.' removed', 'It is no longer a target for this post.');
    }

    /** Fork this network's copy off the shared text, or fold it back in. */
    public function toggleOverride(string $key): void
    {
        $label = $this->labelFor($key);

        if ($this->isOverridden($key)) {
            unset($this->overrides[$key]);

            $this->toastSuccess($label.' follows the shared text again', 'Its own copy was discarded.');

            return;
        }

        $this->overrides[$key] = $this->body;

        $this->toastSuccess($label.' now has its own copy', 'Editing it leaves the other networks alone.');
    }

    /** Cut the overridden copy down to whatever this network allows. */
    public function trimToLimit(string $key): void
    {
        $network = collect($this->networks())->firstWhere('key', $key);

        if (! $network) {
            return;
        }

        $before = mb_strlen($this->textFor($key));

        $this->overrides[$key] = rtrim(mb_substr($this->textFor($key), 0, $network['limit']));

        $cut = $before - mb_strlen($this->overrides[$key]);

        if ($cut < 1) {
            $this->toastInfo($network['label'].' copy already fits', 'Nothing needed trimming.');

            return;
        }

        $this->toastSuccess(
            'Trimmed '.number_format($cut).' '.($cut === 1 ? 'character' : 'characters'),
            $network['label'].' copy now fits its '.number_format($network['limit']).'-character limit.',
        );
    }

    public function removeMedia(int $index): void
    {
        $name = $this->media[$index]['name'] ?? null;

        unset($this->media[$index]);
        $this->media = array_values($this->media);

        if ($name === null) {
            return;
        }

        $left = count($this->media);

        $this->toastSuccess('Removed '.$name, $left === 0
            ? 'No attachments left on this post.'
            : $left.' '.($left === 1 ? 'attachment' : 'attachments').' left on this post.');
    }

    public function attachMedia(): void
    {
        // Upload handling lands with the backend; the picker writes into $media.

        $this->toastInfo('The media picker is not wired up yet', 'Uploads arrive with the backend.');
    }

    public function submit(): void
    {
        // Queues one job per target network. Backend work.

        $this->toastInfo('Nothing was sent', 'Publishing and scheduling arrive with the backend.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Publish</h1>
            <p class="text-sm text-secondary-foreground mt-1">One post, every network you pick.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('social.calendar') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-calendar"></i> Calendar
            </a>
            <a href="{{ route('social.posts') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-questionnaire-tablet"></i> Queue
            </a>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Composer --}}
        <div class="col-span-12 lg:col-span-7 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <textarea class="kt-textarea min-h-[220px] text-sm"
                              placeholder="What are you shipping today?"
                              wire:model.live.debounce.300ms="body"></textarea>

                    {{-- Attachments --}}
                    <div class="flex flex-col gap-2">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-medium text-secondary-foreground">Attachments</span>
                            <span class="text-xs text-muted-foreground">{{ mb_strlen($body) }} characters</span>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            @forelse ($media as $i => $m)
                                <div class="relative group w-24 shrink-0" wire:key="media-{{ $i }}-{{ $m['name'] }}">
                                    <img src="{{ $m['thumb'] }}" alt="{{ $m['name'] }}"
                                         class="w-24 h-16 object-cover rounded-lg border border-border">
                                    <button wire:click="removeMedia({{ $i }})"
                                            wire:loading.attr="disabled"
                                            class="kt-btn kt-btn-icon kt-btn-sm absolute -top-2 -end-2 size-6 rounded-full bg-background border border-border text-destructive"
                                            title="Remove {{ $m['name'] }}" aria-label="Remove {{ $m['name'] }}">
                                        <i class="ki-filled ki-cross text-xs"></i>
                                    </button>
                                    <div class="text-[11px] text-muted-foreground truncate mt-1" title="{{ $m['name'] }}">{{ $m['name'] }}</div>
                                    <div class="text-[10px] text-muted-foreground">{{ $m['size'] }}</div>
                                </div>
                            @empty
                                <p class="text-xs text-muted-foreground">No attachments. Instagram needs at least one image.</p>
                            @endforelse

                            <button wire:click="attachMedia" wire:loading.attr="disabled"
                                    class="w-24 h-16 shrink-0 rounded-lg border border-dashed border-border flex flex-col items-center justify-center gap-1 text-muted-foreground hover:bg-accent/40 transition-colors"
                                    title="Add media" aria-label="Add media">
                                <i class="ki-filled ki-picture text-base"></i>
                                <span class="text-[11px]">Add</span>
                            </button>
                        </div>
                    </div>

                    <div class="border-t border-border pt-4 flex flex-wrap items-end justify-between gap-3">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="flex flex-col gap-1">
                                <label class="kt-form-label text-xs" for="publish-when">When</label>
                                <select id="publish-when" class="kt-select max-w-[180px]" wire:model.live="schedule">
                                    <option value="now">Publish now</option>
                                    <option value="later">Schedule…</option>
                                    <option value="draft">Save as draft</option>
                                </select>
                            </div>
                            @if ($schedule === 'later')
                                <div class="flex flex-col gap-1">
                                    <label class="kt-form-label text-xs" for="publish-at">Date and time</label>
                                    <input id="publish-at" type="datetime-local" class="kt-input max-w-[220px]" wire:model="scheduledAt">
                                </div>
                            @endif
                        </div>

                        <button wire:click="submit" wire:loading.attr="disabled"
                                class="kt-btn kt-btn-primary gap-2"
                                @disabled(empty($targets) || trim($body) === '')>
                            <span wire:loading.remove wire:target="submit" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-paper-plane"></i>
                                {{ $schedule === 'now' ? 'Publish' : ($schedule === 'later' ? 'Schedule' : 'Save draft') }}
                            </span>
                            <span wire:loading wire:target="submit" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Working…
                            </span>
                        </button>
                    </div>

                </div>
            </div>

            {{-- Per-network copy --}}
            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Per-network copy</h3>
                    <span class="text-xs text-muted-foreground">Fork one network without touching the rest</span>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($selected as $n)
                        @php
                            $text = $this->textFor($n['key']);
                            $forked = $this->isOverridden($n['key']);
                            $over = mb_strlen($text) - $n['limit'];
                        @endphp
                        <div class="p-4 flex flex-col gap-3" wire:key="override-{{ $n['key'] }}">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2 min-w-0">
                                    <i class="ki-filled {{ $n['icon'] }} text-base text-muted-foreground"></i>
                                    <span class="text-sm font-medium text-mono">{{ $n['label'] }}</span>
                                    <span class="text-xs {{ $over > 0 ? 'text-destructive' : 'text-muted-foreground' }}">
                                        {{ mb_strlen($text) }} / {{ number_format($n['limit']) }}
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    @if ($forked && $over > 0)
                                        <button wire:click="trimToLimit('{{ $n['key'] }}')" class="kt-btn kt-btn-sm kt-btn-outline">
                                            Trim to fit
                                        </button>
                                    @endif
                                    <button wire:click="toggleOverride('{{ $n['key'] }}')" class="kt-btn kt-btn-sm kt-btn-ghost">
                                        {{ $forked ? 'Use shared text' : 'Customise' }}
                                    </button>
                                </div>
                            </div>

                            @if ($forked)
                                <textarea class="kt-textarea min-h-[110px] text-sm {{ $over > 0 ? 'border-destructive' : '' }}"
                                          wire:model.live.debounce.300ms="overrides.{{ $n['key'] }}"
                                          aria-label="{{ $n['label'] }} copy"></textarea>
                            @else
                                <p class="text-xs text-muted-foreground">Follows the shared text above.</p>
                            @endif
                        </div>
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-element-11 text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Pick at least one network to post to.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>

        {{-- Targets and previews --}}
        <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Post to</h3>
                    <a href="{{ route('social.account-connect') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-ghost">Connect</a>
                </div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @foreach ($networks as $n)
                        @php
                            $active = in_array($n['key'], $targets, true);
                            $length = mb_strlen($this->textFor($n['key']));
                            $over = $length > $n['limit'];
                        @endphp
                        <button wire:click="toggleTarget('{{ $n['key'] }}')"
                                @disabled(! $n['connected'])
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-start transition-colors
                                       {{ $active ? 'bg-primary/10' : 'hover:bg-accent/50' }}
                                       {{ $n['connected'] ? '' : 'opacity-50 cursor-not-allowed' }}">
                            <i class="ki-filled {{ $n['icon'] }} text-lg shrink-0 {{ $active ? 'text-primary' : 'text-muted-foreground' }}"></i>
                            <span class="min-w-0 grow">
                                <span class="block text-sm font-medium text-mono">{{ $n['label'] }}</span>
                                <span class="block text-xs {{ $over ? 'text-destructive' : 'text-muted-foreground' }}">
                                    {{ $n['connected'] ? $length . ' / ' . number_format($n['limit']) : 'Not connected' }}
                                </span>
                            </span>
                            @if ($active)
                                <i class="ki-filled ki-check-circle text-primary text-base shrink-0"></i>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            @forelse ($selected as $n)
                <livewire:social::post-preview
                    :key="'preview-'.$n['key']"
                    :network="$n"
                    :body="$this->textFor($n['key'])"
                    :media="$media"
                    :overridden="$this->isOverridden($n['key'])" />
            @empty
                <div class="kt-card">
                    <div class="kt-card-content flex flex-col items-center py-12 text-center">
                        <i class="ki-filled ki-eye text-3xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">Previews appear once you select a network.</p>
                    </div>
                </div>
            @endforelse

        </div>

    </div>
</div>
