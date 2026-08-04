<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class SidebarTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_lists_every_module_and_submenu_item(): void
    {
        $r = $this->actingAs(User::factory()->create())->get('/dashboard');
        $r->assertOk();
        foreach (['Projects','Accounting','Mail','Data','Social','Settings'] as $group) {
            $r->assertSee($group, false);
        }
        foreach (['Boards','Archive','Estimates','Invoices','Recurring','Expenses','Clients','Reports',
                  'Inbox','Campaigns','Contacts','Providers','Suppression',
                  'Files','Passwords','Links &amp; Bots','GitHub Repos','Backups',
                  'Notifications','Publish','Calendar','Queue','Accounts'] as $item) {
            $r->assertSee($item, false);
        }
        $r->assertSee('data-kargah-accordion', false);
        $r->assertSee('__kargahSidebarBound', false);
    }

    public function test_active_group_is_expanded_on_load(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/mail/inbox')
            ->assertOk()
            ->assertSee('data-kargah-group="mail"', false)
            ->assertSee('aria-current="page"', false);
    }
}
