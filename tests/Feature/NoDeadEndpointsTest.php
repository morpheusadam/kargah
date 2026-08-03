<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Nothing is routed that nobody wrote.
 *
 * `nwidart/laravel-modules` scaffolds an `apiResource` and a placeholder
 * controller into every new module. Kargah has no API, so those routes went out
 * behind `auth:sanctum` pointing at controllers whose `index`, `create`, `show`
 * and `edit` render views that were never created — thirty endpoints across six
 * modules that answer an authenticated request with a 500.
 *
 * They were removed rather than left lying, because dead surface area is worse
 * than missing surface area: it is undocumented, untested, and the first thing
 * anyone poking at the application finds. This test is what stops
 * `php artisan module:make` quietly putting them back the next time a module is
 * added.
 */
class NoDeadEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_module_ships_the_scaffolded_api_resource(): void
    {
        $scaffolded = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route): string => $route->uri())
            ->filter(fn (string $uri): bool => str_starts_with($uri, 'api/v1/'))
            ->values()
            ->all();

        $this->assertSame(
            [],
            $scaffolded,
            "These are nwidart's scaffolding, not an API anybody wrote:\n".implode("\n", $scaffolded),
        );
    }

    /**
     * Every route a signed-in user can reach answers.
     *
     * A 500 is a bug; a 404 or a redirect is a decision. This walks the real
     * routing table rather than a hand-kept list, so a route added without a
     * working target is caught by the route existing, not by somebody
     * remembering to add it here.
     */
    public function test_every_get_route_answers_rather_than_erroring(): void
    {
        $this->actingAs(User::factory()->create());

        $skipped = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            // Routes taking a parameter need a real record to be meaningful and
            // are covered by their own module's tests.
            if (str_contains($uri, '{') || str_starts_with($uri, '_') || $uri === 'up') {
                $skipped[] = $uri;

                continue;
            }

            $response = $this->get('/'.ltrim($uri, '/'));

            $this->assertLessThan(
                500,
                $response->getStatusCode(),
                '/'.$uri.' answered '.$response->getStatusCode().'.',
            );
        }

        $this->assertNotEmpty($skipped, 'Nothing was skipped, so this test is not walking the table it thinks it is.');
    }
}
