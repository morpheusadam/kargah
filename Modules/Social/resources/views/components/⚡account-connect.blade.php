<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Connect a network.
 *
 * Two shapes of credential live here: Telegram wants a bot token you paste in,
 * everything else wants an OAuth round trip. Both list exactly what Kargah ends
 * up being allowed to do before you hand anything over.
 */
new
#[Title('Connect an account — Kargah')]
class extends Component
{
    #[Url]
    public string $network = '';

    #[Validate('exclude_unless:network,telegram|required|min:40')]
    public string $botToken = '';

    #[Validate('exclude_unless:network,telegram|required')]
    public string $chatId = '';

    public bool $showToken = false;

    public string $testResult = '';

    /** @return array<string, array<string, mixed>> */
    public function catalogue(): array
    {
        return [
            'telegram' => [
                'label' => 'Telegram',
                'icon' => 'ki-paper-plane',
                'method' => 'token',
                'summary' => 'Post to a channel or group through a bot you own.',
                'requirement' => 'Create a bot with @BotFather, add it to the channel as an administrator, then paste its token here.',
                'permissions' => [
                    ['allowed' => true,  'text' => 'Send messages, photos and documents to the chat you name'],
                    ['allowed' => true,  'text' => 'Read delivery receipts for messages it sent'],
                    ['allowed' => false, 'text' => 'Read your personal Telegram account or its chats'],
                ],
            ],
            'linkedin' => [
                'label' => 'LinkedIn',
                'icon' => 'ki-abstract-41',
                'method' => 'oauth',
                'summary' => 'Publish to your personal feed and read the engagement back.',
                'requirement' => 'Authorising opens LinkedIn in a new tab. Nothing is stored until you come back.',
                'scopes' => [
                    ['scope' => 'openid',           'text' => 'Confirm which account authorised the connection'],
                    ['scope' => 'profile',          'text' => 'Read your name, headline and profile photo for previews'],
                    ['scope' => 'email',            'text' => 'Read the address on the account, used to label it here'],
                    ['scope' => 'w_member_social',  'text' => 'Create posts on your behalf and read their engagement'],
                ],
                'permissions' => [
                    ['allowed' => true,  'text' => 'Create posts when you press publish or when a scheduled post fires'],
                    ['allowed' => true,  'text' => 'Read impressions, reactions and comments on posts Kargah created'],
                    ['allowed' => false, 'text' => 'Read your connections, messages or feed'],
                ],
            ],
            'x' => [
                'label' => 'X',
                'icon' => 'ki-abstract-39',
                'method' => 'oauth',
                'summary' => 'Publish posts and read their metrics.',
                'requirement' => 'Uses OAuth 2.0 with PKCE. The refresh token is stored encrypted so scheduled posts keep working.',
                'scopes' => [
                    ['scope' => 'tweet.read',     'text' => 'Read posts, including the ones Kargah published'],
                    ['scope' => 'tweet.write',    'text' => 'Create and delete posts on your behalf'],
                    ['scope' => 'users.read',     'text' => 'Read your handle and profile photo for previews'],
                    ['scope' => 'offline.access', 'text' => 'Keep the connection alive so scheduled posts do not need you present'],
                ],
                'permissions' => [
                    ['allowed' => true,  'text' => 'Post at the time you scheduled, without you being logged in'],
                    ['allowed' => true,  'text' => 'Read impressions and reposts on posts Kargah created'],
                    ['allowed' => false, 'text' => 'Follow, unfollow, like or send direct messages'],
                ],
            ],
            'instagram' => [
                'label' => 'Instagram',
                'icon' => 'ki-instagram',
                'method' => 'oauth',
                'summary' => 'Publish to a professional account through the Graph API.',
                'requirement' => 'Needs a professional account linked to a Facebook page. Personal accounts cannot publish through the API.',
                'scopes' => [
                    ['scope' => 'instagram_basic',            'text' => 'Read the account handle, photo and existing media'],
                    ['scope' => 'instagram_content_publish',  'text' => 'Publish images and captions on your behalf'],
                    ['scope' => 'pages_show_list',            'text' => 'List the pages you manage so you can pick the right one'],
                    ['scope' => 'business_management',        'text' => 'Confirm the account is professional and eligible to publish'],
                ],
                'permissions' => [
                    ['allowed' => true,  'text' => 'Publish an image with a caption when you press publish'],
                    ['allowed' => true,  'text' => 'Read reach, likes and saves on posts Kargah created'],
                    ['allowed' => false, 'text' => 'Read or reply to direct messages, or post stories'],
                ],
            ],
        ];
    }

    public function with(): array
    {
        $catalogue = $this->catalogue();

        return [
            'catalogue' => $catalogue,
            'chosen' => $catalogue[$this->network] ?? null,
        ];
    }

    public function choose(string $network): void
    {
        $this->network = array_key_exists($network, $this->catalogue()) ? $network : '';
        $this->testResult = '';
        $this->resetValidation();
    }

    public function back(): void
    {
        $this->network = '';
        $this->testResult = '';
        $this->resetValidation();
    }

    public function testConnection(): void
    {
        $this->validate();

        // Sends a "Kargah is connected" message through the Bot API. Backend work.
    }

    public function authorise(string $network): void
    {
        // Redirects to the provider's consent screen and stores the token on return.
    }

    public function save(): void
    {
        $this->validate();

        // Persists the credential, encrypted. Backend work.
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Connect an account</h1>
            <p class="text-sm text-secondary-foreground mt-1">Hand over the least access a network will accept, and see it written down first.</p>
        </div>
        <a href="{{ route('social.accounts') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-arrow-left"></i> All accounts
        </a>
    </div>

    {{-- Step indicator --}}
    <div class="flex items-center gap-3 text-sm">
        <span class="inline-flex items-center gap-2 {{ $chosen ? 'text-muted-foreground' : 'text-mono font-medium' }}">
            <span class="inline-flex items-center justify-center size-6 rounded-full text-xs {{ $chosen ? 'bg-muted text-muted-foreground' : 'bg-primary text-primary-foreground' }}">1</span>
            Choose a network
        </span>
        <span class="grow h-px bg-border max-w-[80px]"></span>
        <span class="inline-flex items-center gap-2 {{ $chosen ? 'text-mono font-medium' : 'text-muted-foreground' }}">
            <span class="inline-flex items-center justify-center size-6 rounded-full text-xs {{ $chosen ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground' }}">2</span>
            Grant access
        </span>
    </div>

    @if (! $chosen)

        {{-- Step 1: chooser --}}
        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-5">
            @foreach ($catalogue as $key => $n)
                <button wire:click="choose('{{ $key }}')"
                        class="kt-card text-start hover:border-primary/40 transition-colors">
                    <div class="kt-card-content p-5 flex flex-col gap-3">
                        <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary">
                            <i class="ki-filled {{ $n['icon'] }} text-xl"></i>
                        </span>
                        <div>
                            <div class="font-semibold text-mono">{{ $n['label'] }}</div>
                            <p class="text-sm text-secondary-foreground mt-1">{{ $n['summary'] }}</p>
                        </div>
                        <span class="kt-badge kt-badge-sm kt-badge-outline self-start">
                            {{ $n['method'] === 'token' ? 'Bot token' : 'OAuth' }}
                        </span>
                    </div>
                </button>
            @endforeach
        </div>

    @else

        <div class="grid grid-cols-12 gap-5 items-start">

            {{-- Step 2: credentials --}}
            <div class="col-span-12 lg:col-span-7">
                <div class="kt-card">
                    <div class="kt-card-header">
                        <div class="flex items-center gap-2.5 min-w-0">
                            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-primary/10 text-primary shrink-0">
                                <i class="ki-filled {{ $chosen['icon'] }} text-lg"></i>
                            </span>
                            <div class="min-w-0">
                                <h3 class="kt-card-title">{{ $chosen['label'] }}</h3>
                                <p class="text-xs text-muted-foreground truncate">{{ $chosen['summary'] }}</p>
                            </div>
                        </div>
                        <button wire:click="back" class="kt-btn kt-btn-sm kt-btn-ghost shrink-0">Change</button>
                    </div>

                    <div class="kt-card-content p-5 flex flex-col gap-5">

                        <div class="flex items-start gap-2.5 rounded-lg bg-muted px-3.5 py-3">
                            <i class="ki-filled ki-information-2 text-secondary-foreground text-base mt-0.5 shrink-0"></i>
                            <p class="text-sm text-secondary-foreground">{{ $chosen['requirement'] }}</p>
                        </div>

                        @if ($chosen['method'] === 'token')

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="bot-token">Bot token</label>
                                <div class="flex items-center gap-2">
                                    <input id="bot-token"
                                           type="{{ $showToken ? 'text' : 'password' }}"
                                           class="kt-input grow @error('botToken') border-destructive @enderror"
                                           placeholder="7104932188:AAF…"
                                           wire:model="botToken">
                                    <button wire:click="$toggle('showToken')"
                                            class="kt-btn kt-btn-icon kt-btn-outline shrink-0"
                                            title="{{ $showToken ? 'Hide token' : 'Show token' }}"
                                            aria-label="{{ $showToken ? 'Hide token' : 'Show token' }}">
                                        <i class="ki-filled {{ $showToken ? 'ki-eye-slash' : 'ki-eye' }}"></i>
                                    </button>
                                </div>
                                <span class="text-xs text-muted-foreground">Stored encrypted. It is never rendered back into this page once saved.</span>
                                @error('botToken')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-col gap-1.5">
                                <label class="kt-form-label" for="chat-id">Chat ID</label>
                                <input id="chat-id" type="text"
                                       class="kt-input @error('chatId') border-destructive @enderror"
                                       placeholder="@kargah_buildlog or -1001234567890"
                                       wire:model="chatId">
                                <span class="text-xs text-muted-foreground">A public channel username, or the numeric ID for a private channel or group.</span>
                                @error('chatId')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                            </div>

                            <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                                <button wire:click="testConnection" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-outline gap-2">
                                    <span wire:loading.remove wire:target="testConnection" class="inline-flex items-center gap-2">
                                        <i class="ki-filled ki-paper-plane"></i> Send a test message
                                    </span>
                                    <span wire:loading wire:target="testConnection" class="inline-flex items-center gap-2">
                                        <i class="ki-filled ki-loading animate-spin"></i> Testing…
                                    </span>
                                </button>
                                <button wire:click="save" wire:loading.attr="disabled" class="kt-btn kt-btn-primary gap-2">
                                    <i class="ki-filled ki-check-circle"></i> Save connection
                                </button>
                            </div>

                            @if ($testResult !== '')
                                <p class="text-sm text-secondary-foreground">{{ $testResult }}</p>
                            @else
                                <p class="text-xs text-muted-foreground">
                                    The test posts one message to the chat so you can confirm the bot is actually an administrator.
                                </p>
                            @endif

                        @else

                            <div class="flex flex-col gap-2">
                                <span class="text-sm font-medium text-mono">Scopes this will request</span>
                                <div class="flex flex-col divide-y divide-border rounded-lg border border-border">
                                    @foreach ($chosen['scopes'] as $s)
                                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3.5 py-2.5">
                                            <code class="text-xs font-medium text-primary bg-primary/10 rounded px-1.5 py-0.5">{{ $s['scope'] }}</code>
                                            <span class="text-sm text-secondary-foreground grow min-w-0">{{ $s['text'] }}</span>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                                <button wire:click="authorise('{{ $network }}')" wire:loading.attr="disabled"
                                        class="kt-btn kt-btn-primary gap-2">
                                    <span wire:loading.remove wire:target="authorise" class="inline-flex items-center gap-2">
                                        <i class="ki-filled {{ $chosen['icon'] }}"></i> Authorise on {{ $chosen['label'] }}
                                    </span>
                                    <span wire:loading wire:target="authorise" class="inline-flex items-center gap-2">
                                        <i class="ki-filled ki-loading animate-spin"></i> Opening {{ $chosen['label'] }}…
                                    </span>
                                </button>
                                <span class="text-xs text-muted-foreground">Opens in a new tab. You can revoke it from {{ $chosen['label'] }} at any time.</span>
                            </div>

                        @endif

                    </div>
                </div>
            </div>

            <div class="col-span-12 lg:col-span-5 flex flex-col gap-5">

                {{-- Permissions summary --}}
                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">What Kargah will be able to do</h3>
                    </div>
                    <div class="kt-card-content p-4 flex flex-col gap-2.5">
                        @foreach ($chosen['permissions'] as $p)
                            <div class="flex items-start gap-2.5">
                                <i class="ki-filled {{ $p['allowed'] ? 'ki-check-circle text-success' : 'ki-cross-circle text-muted-foreground' }} text-base mt-0.5 shrink-0"></i>
                                <span class="text-sm {{ $p['allowed'] ? 'text-secondary-foreground' : 'text-muted-foreground' }}">{{ $p['text'] }}</span>
                            </div>
                        @endforeach
                        <p class="text-xs text-muted-foreground border-t border-border pt-3 mt-1">
                            Credentials are encrypted at rest and used only by the queue worker that sends your posts.
                        </p>
                    </div>
                </div>

                {{-- Disconnect warning --}}
                <div class="kt-card border-warning/30">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title text-warning">Before you disconnect later</h3>
                    </div>
                    <div class="kt-card-content p-4 flex flex-col gap-2.5">
                        <div class="flex items-start gap-2.5">
                            <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                            <span class="text-sm text-secondary-foreground">
                                Disconnecting {{ $chosen['label'] }} cancels every queued post that targets it. Posts already
                                published stay up on the network.
                            </span>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <i class="ki-filled ki-information-2 text-warning text-base mt-0.5 shrink-0"></i>
                            <span class="text-sm text-secondary-foreground">
                                Analytics collected so far are kept, but stop updating the moment the token is revoked.
                            </span>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    @endif
</div>
