<?php

namespace Modules\Platform\Providers;

use Illuminate\Contracts\Container\Container;
use Modules\Core\Contracts\TextGenerator;
use Modules\Core\Support\MorphMap;
use Modules\Platform\Console\KargahAsk;
use Modules\Platform\Http\Middleware\AuthenticateApplicationPassword;
use Modules\Platform\Http\Middleware\RequireScope;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\AnthropicDriver;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\AssistantTextGenerator;
use Modules\Platform\Services\Assistant\GeminiDriver;
use Modules\Platform\Services\Assistant\OllamaDriver;
use Modules\Platform\Services\Assistant\OpenAiDriver;
use Modules\Platform\Services\Assistant\OpenRouterDriver;
use Modules\Platform\Services\Assistant\Tools\AccountingTotals;
use Modules\Platform\Services\Assistant\Tools\CardsDueSoon;
use Modules\Platform\Services\Assistant\Tools\CardsOverdue;
use Modules\Platform\Services\Assistant\Tools\CustomerEmails;
use Modules\Platform\Services\Assistant\Tools\ListBoards;
use Modules\Platform\Services\Assistant\Tools\ListExpenses;
use Modules\Platform\Services\Assistant\Tools\ListInvoices;
use Modules\Platform\Services\Assistant\Tools\ReadBoard;
use Modules\Platform\Services\Assistant\Tools\ReadCard;
use Modules\Platform\Services\Assistant\Tools\ReadCustomer;
use Modules\Platform\Services\Assistant\Tools\ReadInvoice;
use Modules\Platform\Services\Assistant\Tools\SearchCustomers;
use Modules\Platform\Services\Assistant\Tools\ToolRegistry;
use Modules\Platform\Services\Assistant\Tools\UnreadMailCount;
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
     * `kargah:ask` is not `platform:ask` on purpose — `07-platform.md` names it,
     * and it is the one command in Kargah a person types rather than the
     * scheduler, so it reads as the application's own verb rather than as one
     * module's.
     *
     * @var string[]
     */
    protected array $commands = [
        KargahAsk::class,
    ];

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
         * registry the settings page, `kargah:ask` and the tool layer all
         * resolve. Factories rather than instances, exactly as
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

        /*
         * Generated text, for the modules that may not know Platform exists.
         *
         * Platform is the edge module — it may depend on any other module's
         * `Contracts` and nothing may depend on it. So a feature that wants a
         * paragraph written cannot call `Assistant` directly, however much it
         * would like to, and however wasteful a second connection to somebody
         * else's API with a second key configured elsewhere would be.
         *
         * The answer is the one that already lets Social store a file without
         * knowing `Modules\Data` exists: Core owns a small interface, whoever
         * can implement it binds it, and every arrow still points at Core. Bound
         * here rather than in Core because this is where the drivers, the keys
         * and the settings page already are.
         *
         * Not a singleton. The adapter resolves its provider per call, so a
         * default changed on the settings page takes effect on the next call
         * rather than on the next deploy.
         */
        $this->app->bind(TextGenerator::class, fn ($app): AssistantTextGenerator => new AssistantTextGenerator(
            $app->make(Assistant::class),
        ));

        /*
         * The tool catalogue, as a singleton, bound the same way for the same
         * reasons — with one that bites harder here than it does for drivers.
         *
         * Every tool holds a `Modules\<X>\Contracts\…` reader resolved out of
         * the container. Constructing them all eagerly would resolve every
         * reader in five modules on every request, including the overwhelming
         * majority that never mention the assistant. So each is registered as
         * a factory under its own `NAME` constant — the name the model calls
         * it by — which is exactly why that constant exists: binding a factory
         * must not require constructing the thing to ask it what it is called.
         *
         * Read-only, deliberately. `07-platform.md` draws the line at
         * "anything that spends money or sends mail asks first", and the two
         * write methods the contracts do expose — `InvoiceReader::issue()` and
         * `CardReader::assignToCustomer()` — are on the wrong side of it or
         * are not what a model would reach for. Creating and moving a card,
         * and drafting an invoice, have no contract to go through at all.
         */
        $this->app->singleton(ToolRegistry::class, function (Container $app): ToolRegistry {
            $tools = new ToolRegistry;

            $tools->extend(SearchCustomers::NAME, fn () => $app->make(SearchCustomers::class));
            $tools->extend(ReadCustomer::NAME, fn () => $app->make(ReadCustomer::class));

            $tools->extend(ListBoards::NAME, fn () => $app->make(ListBoards::class));
            $tools->extend(ReadBoard::NAME, fn () => $app->make(ReadBoard::class));
            $tools->extend(ReadCard::NAME, fn () => $app->make(ReadCard::class));
            $tools->extend(CardsDueSoon::NAME, fn () => $app->make(CardsDueSoon::class));
            $tools->extend(CardsOverdue::NAME, fn () => $app->make(CardsOverdue::class));

            $tools->extend(ListInvoices::NAME, fn () => $app->make(ListInvoices::class));
            $tools->extend(ReadInvoice::NAME, fn () => $app->make(ReadInvoice::class));
            $tools->extend(AccountingTotals::NAME, fn () => $app->make(AccountingTotals::class));
            $tools->extend(ListExpenses::NAME, fn () => $app->make(ListExpenses::class));

            $tools->extend(CustomerEmails::NAME, fn () => $app->make(CustomerEmails::class));
            $tools->extend(UnreadMailCount::NAME, fn () => $app->make(UnreadMailCount::class));

            return $tools;
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
