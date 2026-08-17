<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\CompletionFailed;
use Modules\Platform\Services\Assistant\GeminiDriver;
use Modules\Platform\Support\AssistantDrivers;
use Tests\TestCase;

/**
 * A failed request must not carry the key in its error message.
 *
 * 🔴 **This is a real disclosure, pinned so it cannot recur.** Gemini
 * authenticates with `?key=` in the query string — Google's own documented shape
 * for that API rather than a choice made here — and a cURL timeout's message
 * quotes the whole URL it was handed. That message is written to
 * `post_targets.error`, printed by `social:curate-daily`, rendered on a page and
 * written to the log. On 18 August 2026 a live key was disclosed exactly that way
 * during the first server run of the daily curator, and had to be rotated.
 *
 * The redaction is in `HttpAssistantDriver` rather than in `GeminiDriver` on
 * purpose: every driver behind that class pays for one of them putting a secret in
 * a URL, and the next driver added should not have to remember. It matches
 * `Modules\Social\Services\Publishers\HttpPublisher::cannotReach()`, which already
 * does this on the publishing side — the assistant simply never got the same
 * treatment.
 *
 * Pattern-based rather than replacing the one key the call used, because a
 * `str_replace` needs the caller to hand the secret over, and the driver that
 * forgets to is the one that leaks.
 */
class AssistantSecretLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_timeout_does_not_print_the_api_key(): void
    {
        $key = 'AQ.Ab8RN6FAKEKEYFORTESTINGONLYxxxxxxxxxxxxxxxxxxxx';

        $provider = AssistantProvider::factory()->create([
            'driver' => AssistantDrivers::GEMINI,
            'api_key' => $key,
            'model' => 'gemini-2.5-flash',
            'is_active' => true,
        ]);

        // The exact shape of the message that leaked: cURL quotes the URL it was
        // given, and the URL is where Gemini's credential lives.
        Http::fake(function () use ($key) {
            throw new ConnectionException(
                'cURL error 28: Operation timed out after 25002 milliseconds with 0 bytes received '
                .'for https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='.$key,
            );
        });

        try {
            (new GeminiDriver)->complete($provider, $this->request());

            $this->fail('The driver should have reported the timeout.');
        } catch (CompletionFailed $e) {
            $this->assertStringNotContainsString($key, $e->getMessage());
            // The parameter name survives, because "the key was rejected" is
            // useful and "something was rejected" is not.
            $this->assertStringContainsString('key=[redacted]', $e->getMessage());
            // And the useful half of the message is still there.
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
    }

    public function test_a_bearer_token_in_a_message_is_redacted_too(): void
    {
        $provider = AssistantProvider::factory()->create([
            'driver' => AssistantDrivers::GEMINI,
            'api_key' => 'AQ.AbFAKE0000000000000000000000000000',
            'is_active' => true,
        ]);

        Http::fake(function () {
            throw new ConnectionException('refused with Authorization: Bearer sk-ant-secret-value-here');
        });

        try {
            (new GeminiDriver)->complete($provider, $this->request());

            $this->fail('The driver should have reported the failure.');
        } catch (CompletionFailed $e) {
            // The other shape the drivers behind this class authenticate with, so
            // the redaction covers a provider nobody told it about.
            $this->assertStringNotContainsString('sk-ant-secret-value-here', $e->getMessage());
            $this->assertStringContainsString('Bearer [redacted]', $e->getMessage());
        }
    }

    private function request(): \Modules\Platform\Services\Assistant\CompletionRequest
    {
        return new \Modules\Platform\Services\Assistant\CompletionRequest(
            messages: [new \Modules\Platform\Services\Assistant\ChatMessage('user', 'بنویس')],
        );
    }
}
