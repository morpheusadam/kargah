<?php

namespace Modules\Platform\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Platform\Http\Middleware\AuthenticateApplicationPassword;
use Modules\Platform\Http\Middleware\RequireScope;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\AnthropicDriver;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\GeminiDriver;
use Modules\Platform\Services\Assistant\OllamaDriver;
use Modules\Platform\Services\Assistant\OpenAiDriver;
use Modules\Platform\Services\Assistant\OpenRouterDriver;
use Modules\Platform\Support\AssistantDrivers;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Platform is the edge.
 *
 * Every other module is a piece of the domain and may be depended on. Platform
 * is what the outside world reaches Kargah through — application passwords
 * today, the HTTP API, the assistant and the CLI on top of them. The boundary
 * rule is therefore the inverse of everyone else's:
 *
 * - Platform may depend on any module's `Contracts` namespace.
 * - Platform may depend on **no** module's `Models`.
 * - **Nothing may depend on Platform.**
 *
 * That is what an API gateway is, and it is the only module allowed to see all
 * the others. If something outside this directory ever imports
 * `Modules\Platform\…`, the dependency is pointing the wrong way.
 */
class PlatformServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Platform';

    protected string $nameLower = 'platform';

    /**
     * Empty on purpose. The API, the assistant and the CLI are later work; this
     * module currently ships no artisan command.
     *
     * @var string[]
     */
    protected array $commands = [];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        /*
         * The assistant driver registry, as a singleton.
         *
         * Singleton so a driver swapped in a test's setUp is the same
         * registry the settings page — and later the CLI and the tool layer
         * — resolve. Factories rather than instances, exactly as
         * `Modules\Mailbox\Providers\MailboxServiceProvider` binds
         * `Delivery`: a provider nobody asks for is never built, and a test
         * that swaps one for `FakeAssistantDriver` never constructs the real
         * driver at all — which on a machine with no CA bundle configured is
         * the difference between a clean run and `cURL error 60`.
         */
        $this->app->singleton(Assistant::class, function (): Assistant {
            $assistant = new Assistant;

            $assistant->extend(AssistantDrivers::GEMINI, fn () => new GeminiDriver);
            $assistant->extend(AssistantDrivers::OPENROUTER, fn () => new OpenRouterDriver);
            $assistant->extend(AssistantDrivers::ANTHROPIC, fn () => new AnthropicDriver);
            $assistant->extend(AssistantDrivers::OPENAI, fn () => new OpenAiDriver);
            $assistant->extend(AssistantDrivers::OLLAMA, fn () => new OllamaDriver);

            return $assistant;
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names: an activity row outlives a refactor, and a
        // fully qualified class name in `subject_type` becomes an orphan the
        // moment a model moves. Core enforces the map from `booted`, after
        // every module has had its turn — Platform's priority is 10, so this
        // runs in time.
        MorphMap::register([
            'application_password' => ApplicationPassword::class,
            'assistant_provider' => AssistantProvider::class,
        ]);

        // Registered here rather than in bootstrap/app.php: a module that needs
        // a middleware should be able to declare one, and the app shell should
        // not have to know that Platform exists. `scope` takes its argument the
        // usual way — `->middleware('scope:project:read')`.
        $router = $this->app['router'];
        $router->aliasMiddleware('app-password', AuthenticateApplicationPassword::class);
        $router->aliasMiddleware('scope', RequireScope::class);
    }
}
