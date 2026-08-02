<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The application shell: settings, the command palette, toasts and error pages.
 * Module pages are covered separately by SmokeTest.
 */
class ShellTest extends TestCase
{
    use RefreshDatabase;

    public static function settingsProvider(): array
    {
        return [
            'profile' => ['/settings/profile'],
            'security' => ['/settings/security'],
            'appearance' => ['/settings/appearance'],
            'notifications' => ['/settings/notifications'],
        ];
    }

    #[DataProvider('settingsProvider')]
    public function test_settings_pages_render(string $url): void
    {
        $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->assertSee('Settings', false);
    }

    public function test_settings_root_redirects_to_profile(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/settings')
            ->assertRedirect('/settings/profile');
    }

    public function test_settings_require_authentication(): void
    {
        $this->get('/settings/profile')->assertRedirect('/login');
    }

    public function test_shell_mounts_the_command_palette_and_toast_host(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('kargah-palette', false)
            ->assertSee('kargah-toasts', false)
            ->assertSee('Search…', false);
    }

    public function test_unknown_url_renders_the_not_found_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/this-route-does-not-exist')
            ->assertNotFound()
            ->assertSee('Nothing lives at this address', false);
    }

    public function test_error_views_compile(): void
    {
        foreach (['404', '403', '500', '419', '503'] as $code) {
            $rendered = view("errors.{$code}")->render();

            $this->assertStringContainsString($code, $rendered);
            $this->assertStringContainsString('Kargah', $rendered);
        }
    }
}
