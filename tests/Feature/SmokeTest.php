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
            ->assertSee('Sign in to Kargah', false);
    }

    public function test_guests_cannot_reach_the_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public static function pageProvider(): array
    {
        return [
            'dashboard' => ['/dashboard'],
            'boards' => ['/projects'],
            'archive' => ['/projects/archive'],
            'invoices' => ['/accounting/invoices'],
            'expenses' => ['/accounting/expenses'],
            'clients' => ['/accounting/clients'],
            'reports' => ['/accounting/reports'],
            'inbox' => ['/mail/inbox'],
            'campaigns' => ['/mail/campaigns'],
            'contacts' => ['/mail/contacts'],
            'providers' => ['/mail/providers'],
            'files' => ['/data/files'],
            'passwords' => ['/data/passwords'],
            'links' => ['/data/links'],
            'repos' => ['/data/repos'],
            'backups' => ['/data/backups'],
            'notifications' => ['/social/notifications'],
            'publish' => ['/social/publish'],
            'accounts' => ['/social/accounts'],
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
