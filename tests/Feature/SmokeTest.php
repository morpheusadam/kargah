<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every page must render for an authenticated user, and the login page must
 * render for a guest. This is the gate that catches a broken Blade file or a
 * component that was renamed but not re-registered.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_url_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign in', false)
            ->assertSee('Keep me signed in', false)
            // dark is the default, applied before first paint
            ->assertSee('data-kt-theme-mode="dark"', false);
    }

    public function test_guests_cannot_reach_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public static function pageProvider(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'core notifications' => ['/notifications'],
            'boards' => ['/projects'],
            'archive' => ['/projects/archive'],
            'board settings' => ['/projects/client-work/settings'],
            'invoices' => ['/accounting/invoices'],
            'invoice-create' => ['/accounting/invoices/create'],
            'invoice-edit' => ['/accounting/invoices/1/edit'],
            'invoice-show' => ['/accounting/invoices/1'],
            'recurring' => ['/accounting/recurring'],
            'expenses' => ['/accounting/expenses'],
            'expense-create' => ['/accounting/expenses/create'],
            'expense-edit' => ['/accounting/expenses/1/edit'],
            'clients' => ['/accounting/clients'],
            'client-show' => ['/accounting/clients/1'],
            'reports' => ['/accounting/reports'],
            'inbox' => ['/mail/inbox'],
            'campaigns' => ['/mail/campaigns'],
            'campaign create' => ['/mail/campaigns/create'],
            'campaign report' => ['/mail/campaigns/1'],
            'campaign edit' => ['/mail/campaigns/1/edit'],
            'contacts' => ['/mail/contacts'],
            'contact import' => ['/mail/contacts/import'],
            'suppression' => ['/mail/suppression'],
            'providers' => ['/mail/providers'],
            'provider edit' => ['/mail/providers/brevo/edit'],
            'files' => ['/data/files'],
            'passwords' => ['/data/passwords'],
            'credential create' => ['/data/passwords/create'],
            'links' => ['/data/links'],
            'link create' => ['/data/links/create'],
            'repos' => ['/data/repos'],
            'repo show' => ['/data/repos/1'],
            'backups' => ['/data/backups'],
            'backup show' => ['/data/backups/1'],
            'notifications' => ['/social/notifications'],
            'publish' => ['/social/publish'],
            'social calendar' => ['/social/calendar'],
            'social posts' => ['/social/posts'],
            'social post' => ['/social/posts/1'],
            'accounts' => ['/social/accounts'],
            'account connect' => ['/social/accounts/connect'],
            'application passwords' => ['/settings/application-passwords'],
            'assistant settings' => ['/settings/assistant'],
            'settings notifications' => ['/settings/notifications'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function test_authenticated_pages_render(string $url): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($url)
            ->assertOk();
    }
}
