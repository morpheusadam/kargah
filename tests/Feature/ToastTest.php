<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Concerns\InteractsWithToasts;
use Livewire\Component;
use Tests\TestCase;

/**
 * Notifications must be available on every page, survive a redirect, and carry
 * the right urgency — an error that vanishes in five seconds is not a warning,
 * it is a rumour.
 */
class ToastTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_toast_host_is_present_on_authenticated_pages(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('kargah-toasts', false)
            ->assertSee('aria-label="Notifications"', false);
    }

    public function test_the_toast_host_is_present_on_the_login_page(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('kargah-toasts', false);
    }

    public function test_a_flashed_toast_is_rendered_for_replay_after_a_redirect(): void
    {
        $this->actingAs(User::factory()->create())
            ->withSession(['toast' => ['type' => 'success', 'message' => 'Invoice sent']])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('kargah-flash-toast', false)
            ->assertSee('Invoice sent', false);
    }

    public function test_a_component_dispatches_a_toast_with_its_type_and_message(): void
    {
        Livewire::test(ToastProbe::class)
            ->call('succeed')
            ->assertDispatched('toast', function (string $event, array $params) {
                $payload = $params[0];

                return $payload['type'] === 'success'
                    && $payload['message'] === 'Saved'
                    && $payload['description'] === 'Two rows changed.';
            });
    }

    public function test_errors_are_given_a_longer_life_than_successes(): void
    {
        Livewire::test(ToastProbe::class)
            ->call('fail')
            ->assertDispatched('toast', function (string $event, array $params) {
                return $params[0]['type'] === 'error' && $params[0]['duration'] === 9000;
            });

        Livewire::test(ToastProbe::class)
            ->call('succeed')
            ->assertDispatched('toast', fn (string $e, array $p) => $p[0]['duration'] === null);
    }

    public function test_flashing_a_toast_writes_the_payload_the_layout_expects(): void
    {
        // Livewire's test harness runs its own request lifecycle and ages flash
        // data out of the session, so the trait is exercised directly here. The
        // layout side of the same mechanism is covered by the redirect test above.
        $probe = new ToastProbe;
        $probe->flash();

        $this->assertSame(
            ['type' => 'warning', 'message' => 'Redirected', 'description' => null],
            session('toast'),
        );
    }
}

class ToastProbe extends Component
{
    use InteractsWithToasts;

    public function succeed(): void
    {
        $this->toastSuccess('Saved', 'Two rows changed.');
    }

    public function fail(): void
    {
        $this->toastError('Could not save', 'The provider refused the request.');
    }

    public function flash(): void
    {
        $this->flashToast('warning', 'Redirected');
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
