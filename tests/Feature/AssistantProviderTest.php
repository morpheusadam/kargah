<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\AnthropicDriver;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\ChatMessage;
use Modules\Platform\Services\Assistant\CompletionFailed;
use Modules\Platform\Services\Assistant\CompletionRequest;
use Modules\Platform\Services\Assistant\FakeAssistantDriver;
use Modules\Platform\Services\Assistant\GeminiDriver;
use Modules\Platform\Services\Assistant\OllamaDriver;
use Modules\Platform\Services\Assistant\OpenAiDriver;
use Modules\Platform\Services\Assistant\OpenRouterDriver;
use Modules\Platform\Support\AssistantDrivers;
use Tests\TestCase;

/**
 * The assistant's provider layer, from `project-guaid/spec/07-platform.md`.
 *
 * No test here touches the network. Every driver-mapping test fakes the HTTP
 * layer; every other test never calls a driver's `complete()` at all. No
 * driver in this module has ever been exercised against a real provider API —
 * this machine has neither a CA bundle configured nor a key for any of the
 * five, so a real call would fail with `cURL error 60` before it got the
 * chance to fail on credentials.
 */
class AssistantProviderTest extends TestCase
{
    use RefreshDatabase;

    /* The key: round-trips, never plaintext, never rendered ------------------ */

    public function test_the_key_round_trips_through_the_setter_and_is_not_stored_as_plaintext(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)
            ->create(['api_key' => 'AIzaSyPlaintextExampleValue000']);

        $raw = (string) DB::table('assistant_providers')->where('id', $provider->id)->value('api_key_encrypted');

        $this->assertNotSame('AIzaSyPlaintextExampleValue000', $raw);
        $this->assertStringNotContainsString('AIzaSyPlaintextExampleValue000', $raw);

        $this->assertSame('AIzaSyPlaintextExampleValue000', $provider->fresh()->api_key);
    }

    public function test_a_provider_with_no_key_stores_nothing(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OLLAMA)
            ->create(['api_key' => null, 'base_url' => 'http://127.0.0.1:11434']);

        $this->assertNull(DB::table('assistant_providers')->where('id', $provider->id)->value('api_key_encrypted'));
        $this->assertNull($provider->fresh()->api_key);
        $this->assertFalse($provider->fresh()->hasApiKey());
    }

    /**
     * `NoSecretsInHtmlTest` picks up a new secret column automatically only if
     * its name matches the pattern it walks the schema with. `07-platform.md`
     * already got this wrong once for `token_hash`. Proved, not assumed.
     */
    public function test_the_no_secrets_canary_pattern_covers_the_new_column(): void
    {
        $this->assertMatchesRegularExpression(
            '/(_encrypted|^secret|_secret|password|_token$|token_hash$|credentials)/i',
            'api_key_encrypted',
            'api_key_encrypted would not be walked by NoSecretsInHtmlTest, so a leak here would go undetected.',
        );

        $this->assertTrue(
            Schema::hasColumn('assistant_providers', 'api_key_encrypted'),
            'The column NoSecretsInHtmlTest is expected to plant its canary in does not exist.',
        );
    }

    public function test_the_settings_page_never_renders_the_key(): void
    {
        $user = User::factory()->create();
        AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)
            ->create(['name' => 'Gemini', 'api_key' => 'AIzaSyRealSecretValueThatMustNotLeak']);

        $this->actingAs($user)
            ->get('/settings/assistant')
            ->assertOk()
            ->assertSee('Gemini')
            ->assertDontSee('AIzaSyRealSecretValueThatMustNotLeak');
    }

    /* The registry ------------------------------------------------------------ */

    public function test_the_registry_returns_a_driver_per_provider_name(): void
    {
        $assistant = app(Assistant::class);

        $this->assertSame(AssistantDrivers::GEMINI, $assistant->driverFor(AssistantDrivers::GEMINI)->driver());
        $this->assertSame(AssistantDrivers::OPENROUTER, $assistant->driverFor(AssistantDrivers::OPENROUTER)->driver());
        $this->assertSame(AssistantDrivers::ANTHROPIC, $assistant->driverFor(AssistantDrivers::ANTHROPIC)->driver());
        $this->assertSame(AssistantDrivers::OPENAI, $assistant->driverFor(AssistantDrivers::OPENAI)->driver());
        $this->assertSame(AssistantDrivers::OLLAMA, $assistant->driverFor(AssistantDrivers::OLLAMA)->driver());
    }

    public function test_the_registry_throws_clearly_on_an_unknown_driver(): void
    {
        $assistant = app(Assistant::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mistral');

        $assistant->driverFor('mistral');
    }

    /**
     * The whole point of the factory indirection: building the registry must
     * not construct a single real driver, because a real driver's
     * constructor is the first step toward a network call this suite must
     * never make.
     */
    public function test_building_the_registry_constructs_no_real_driver(): void
    {
        Http::preventStrayRequests();

        $assistant = app(Assistant::class);

        $resolved = new \ReflectionProperty(Assistant::class, 'resolved');
        $resolved->setAccessible(true);

        $this->assertSame(
            [],
            $resolved->getValue($assistant),
            'A driver was constructed merely by resolving the registry from the container.',
        );
    }

    public function test_swapping_in_a_fake_driver_replaces_the_real_one(): void
    {
        $assistant = app(Assistant::class);
        $fake = new FakeAssistantDriver(AssistantDrivers::GEMINI);

        $assistant->swap($fake);

        $this->assertSame($fake, $assistant->driverFor(AssistantDrivers::GEMINI));
    }

    /* Ollama: no key, a base URL instead --------------------------------------- */

    public function test_ollama_configures_with_no_key_and_a_base_url(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OLLAMA)
            ->create(['base_url' => 'http://127.0.0.1:11434']);

        $this->assertFalse($provider->requiresApiKey());
        $this->assertTrue($provider->requiresBaseUrl());
        $this->assertNull($provider->api_key);
        $this->assertSame('http://127.0.0.1:11434', $provider->base_url);

        $driver = new OllamaDriver;
        $this->assertNull($driver->unavailableReason($provider));
    }

    public function test_the_page_accepts_ollama_with_no_key(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('openCreate')
            ->set('name', 'Local Llama')
            ->set('driver', AssistantDrivers::OLLAMA)
            ->set('baseUrl', 'http://127.0.0.1:11434')
            ->call('save')
            ->assertHasNoErrors();

        $provider = AssistantProvider::query()->sole();
        $this->assertNull($provider->api_key);
        $this->assertSame('http://127.0.0.1:11434', $provider->base_url);
    }

    public function test_the_page_still_requires_a_base_url_for_ollama(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('openCreate')
            ->set('name', 'Local Llama')
            ->set('driver', AssistantDrivers::OLLAMA)
            ->set('baseUrl', '')
            ->call('save')
            ->assertHasErrors('baseUrl');

        $this->assertSame(0, AssistantProvider::query()->count());
    }

    public function test_the_page_requires_a_key_for_a_cloud_provider(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('openCreate')
            ->set('name', 'Gemini')
            ->set('driver', AssistantDrivers::GEMINI)
            ->set('apiKey', '')
            ->call('save')
            ->assertHasErrors('apiKey');

        $this->assertSame(0, AssistantProvider::query()->count());
    }

    /* Driver mapping: one test per driver, all against Http::fake -------------- */

    public function test_the_gemini_driver_maps_a_faked_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'ok']], 'role' => 'model'],
                    'finishReason' => 'STOP',
                ]],
                'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 1],
            ]),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->make();

        $response = (new GeminiDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));

        $this->assertSame('ok', $response->text);
        $this->assertSame('STOP', $response->stopReason);
        $this->assertSame(5, $response->promptTokens);
        $this->assertSame(1, $response->completionTokens);
        $this->assertFalse($response->isToolCall());
    }

    public function test_the_openai_driver_maps_a_faked_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
            ]),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OPENAI)->make();

        $response = (new OpenAiDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));

        $this->assertSame('ok', $response->text);
        $this->assertSame('stop', $response->stopReason);
        $this->assertSame(5, $response->promptTokens);
    }

    public function test_the_openrouter_driver_maps_a_faked_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'openrouter.ai/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1],
            ]),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OPENROUTER)->make();

        $response = (new OpenRouterDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));

        $this->assertSame('ok', $response->text);
        $this->assertSame(3, $response->promptTokens);
    }

    public function test_the_anthropic_driver_maps_a_faked_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
            ]),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::ANTHROPIC)->make();

        $response = (new AnthropicDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));

        $this->assertSame('ok', $response->text);
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame(5, $response->promptTokens);
    }

    public function test_the_ollama_driver_maps_a_faked_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '127.0.0.1:11434/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
            ]),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OLLAMA)
            ->make(['base_url' => 'http://127.0.0.1:11434']);

        $response = (new OllamaDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));

        $this->assertSame('ok', $response->text);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '127.0.0.1:11434/v1/chat/completions')
            && ! $request->hasHeader('Authorization'));
    }

    /* A provider error is a clear failure, not an empty completion ------------- */

    public function test_a_provider_error_response_throws_rather_than_returning_an_empty_completion(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'insufficient_quota']], 429),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OPENAI)->make();

        $this->expectException(CompletionFailed::class);
        $this->expectExceptionMessage('insufficient_quota');

        (new OpenAiDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));
    }

    public function test_a_401_is_reported_as_refused_credentials_not_a_generic_failure(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Invalid API key provided']], 401),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::OPENAI)->make();

        try {
            (new OpenAiDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));
            $this->fail('Expected CompletionFailed.');
        } catch (CompletionFailed $e) {
            $this->assertStringContainsString('refused the credentials', $e->getMessage());
            $this->assertStringContainsString('Invalid API key provided', $e->getMessage());
        }
    }

    public function test_no_key_configured_is_reported_without_touching_the_network(): void
    {
        Http::preventStrayRequests();

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->unconfigured()->make();

        try {
            (new GeminiDriver)->complete($provider, new CompletionRequest([new ChatMessage('user', 'hi')]));
            $this->fail('Expected CompletionFailed.');
        } catch (CompletionFailed $e) {
            $this->assertStringContainsString('no API key configured', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    /* Exactly one default, and running it twice changes nothing ---------------- */

    public function test_making_a_provider_default_demotes_the_previous_one(): void
    {
        $first = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->default()->create();
        $second = AssistantProvider::factory()->driver(AssistantDrivers::OPENAI)->create();

        $second->makeDefault();

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, AssistantProvider::query()->where('is_default', true)->count());
    }

    public function test_making_the_same_provider_default_twice_changes_nothing(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->create();

        $provider->makeDefault();
        $updatedAt = $provider->fresh()->updated_at;

        $this->travel(1)->minute();

        $provider->fresh()->makeDefault();

        $this->assertSame($updatedAt->toDateTimeString(), $provider->fresh()->updated_at->toDateTimeString());
        $this->assertSame(1, AssistantProvider::query()->where('is_default', true)->count());
    }

    public function test_the_page_does_not_claim_success_when_making_default_changes_nothing(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->default()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('makeDefault', $provider->id)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'warning');
    }

    /* Deleting the default leaves a sane state ---------------------------------- */

    public function test_deleting_the_default_provider_promotes_another_active_one(): void
    {
        $default = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->default()->create();
        $other = AssistantProvider::factory()->driver(AssistantDrivers::OPENAI)->create();

        $default->delete();

        $this->assertNull(AssistantProvider::find($default->id));
        $this->assertTrue($other->fresh()->is_default);
        $this->assertSame(1, AssistantProvider::query()->where('is_default', true)->count());
    }

    public function test_deleting_the_only_provider_leaves_no_default(): void
    {
        $only = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->default()->create();

        $only->delete();

        $this->assertSame(0, AssistantProvider::query()->count());
        $this->assertSame(0, AssistantProvider::withTrashed()->where('is_default', true)->count());
    }

    public function test_deleting_a_provider_that_is_not_default_leaves_the_default_alone(): void
    {
        $default = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->default()->create();
        $other = AssistantProvider::factory()->driver(AssistantDrivers::OPENAI)->create();

        $other->delete();

        $this->assertTrue($default->fresh()->is_default);
    }

    /* The settings page: add, edit, delete -------------------------------------- */

    public function test_the_page_adds_a_provider(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('openCreate')
            ->set('name', 'Gemini (default)')
            ->set('driver', AssistantDrivers::GEMINI)
            ->set('apiKey', 'AIzaSyTestKey12345')
            ->call('save')
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $provider = AssistantProvider::query()->sole();
        $this->assertSame('Gemini (default)', $provider->name);
        $this->assertSame('AIzaSyTestKey12345', $provider->api_key);
        $this->assertNotSame('AIzaSyTestKey12345', $provider->getRawOriginal('api_key_encrypted'));
    }

    public function test_the_page_edits_a_provider_without_touching_the_key_when_left_blank(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->create(['api_key' => 'original-key']);
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('openEdit', $provider->id)
            ->set('name', 'Renamed')
            ->call('save');

        $provider->refresh();
        $this->assertSame('Renamed', $provider->name);
        $this->assertSame('original-key', $provider->api_key);
    }

    public function test_the_page_replaces_the_key_when_a_new_one_is_typed(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->create(['api_key' => 'original-key']);
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('openEdit', $provider->id)
            ->set('apiKey', 'brand-new-key')
            ->call('save');

        $this->assertSame('brand-new-key', $provider->fresh()->api_key);
    }

    public function test_the_page_deletes_a_provider(): void
    {
        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('delete', $provider->id)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $this->assertNull(AssistantProvider::find($provider->id));
    }

    public function test_the_page_renders(): void
    {
        AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->default()->create(['name' => 'Gemini']);
        $this->actingAs(User::factory()->create());

        $this->get('/settings/assistant')->assertOk()->assertSee('Gemini');
    }

    /* Test connection: three specifically distinguished failures --------------- */

    public function test_the_test_button_reports_no_key_without_touching_the_network(): void
    {
        Http::preventStrayRequests();

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->unconfigured()->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('test', $provider->id)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'error');

        $this->assertFalse($provider->fresh()->last_test_ok);
        $this->assertStringContainsString('no api key', strtolower((string) $provider->fresh()->last_test_error));

        Http::assertNothingSent();
    }

    public function test_the_test_button_reports_a_successful_connection(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']],
            ]),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('test', $provider->id)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'success');

        $this->assertTrue($provider->fresh()->last_test_ok);
        $this->assertNull($provider->fresh()->last_test_error);
        $this->assertNotNull($provider->fresh()->last_tested_at);
    }

    public function test_the_test_button_reports_a_provider_error_distinctly(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'API key not valid']], 400),
        ]);

        $provider = AssistantProvider::factory()->driver(AssistantDrivers::GEMINI)->create();
        $this->actingAs(User::factory()->create());

        Livewire::test('platform::assistant')
            ->call('test', $provider->id)
            ->assertDispatched('toast', fn (string $event, array $payload): bool => $payload[0]['type'] === 'error');

        $this->assertFalse($provider->fresh()->last_test_ok);
        $this->assertStringContainsString('API key not valid', (string) $provider->fresh()->last_test_error);
    }

    /* The migration -------------------------------------------------------- */

    public function test_the_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('assistant_providers'));

        $migration = require base_path('Modules/Platform/database/migrations/2026_08_03_000001_create_assistant_providers_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('assistant_providers'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('assistant_providers'));

        foreach ([
            'name', 'driver', 'model', 'api_key_encrypted', 'base_url',
            'is_active', 'is_default', 'last_tested_at', 'last_test_ok', 'last_test_error',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('assistant_providers', $column), $column.' did not come back.');
        }
    }
}
