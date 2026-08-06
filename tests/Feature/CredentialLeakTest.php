<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\Post;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\InstagramPublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TelegramPublisher;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * A credential in a request URL must not survive into a failure message.
 *
 * `CommunityPublisherTest::test_a_password_never_reaches_a_failure_message()` is
 * the sibling of this file and covers the other half of the same rule: a
 * credential a network echoes back in a *response body*. This one is about the
 * request side, which is worse — the body is the network's choice, the URL is
 * always there.
 *
 * Telegram is the case that forces it. Its bot token is a path segment, so every
 * request it makes has a working credential in the URL, and Guzzle builds a
 * `ConnectionException` message by appending the whole URI. `PostPublisher`
 * writes the resulting `PublishFailed` to `post_targets.error` and the posts page
 * renders it, so before `HttpPublisher::cannotReach()` one timed-out send put the
 * bot token in the database in plaintext and printed it.
 *
 * The two publish paths are two different catch sites — `send()` for the
 * text-only JSON post and `sendMultipart()` for a captioned photo — so both are
 * exercised. Each asserts three things: the token is gone, the host is still
 * named, and the client's reason survives. The last two matter as much as the
 * first: a red row reading only "the request failed" would be leak-free and
 * useless, and the temptation to get there is why they are asserted.
 */
class CredentialLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Distinctive, and deliberately nothing like a Bot API token.
     *
     * A plausible-looking fake is blocked by GitHub push protection, and the
     * whole point of this string is that it is greppable — see
     * `SlackTumblrPublisherTest::SLACK_TOKEN` for the same shape.
     */
    private const BOT_TOKEN = 'EXAMPLE-NOT-A-REAL-BOT-TOKEN-for-tests-only';

    private const CHAT = '@kargah_workshop';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Http::preventStrayRequests();
    }

    private function telegramAccount(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::TELEGRAM)->create([
            'credentials' => ['bot_token' => self::BOT_TOKEN, 'chat_id' => self::CHAT],
            'connected_at' => now(),
        ]);
    }

    /**
     * The message Guzzle actually builds, byte for byte in shape.
     *
     * Reason, then the documentation link, then the whole effective URI —
     * including, for Telegram, the bot token in the path. Hand-built rather than
     * provoked, because a real timeout cannot be produced here: there is no CA
     * bundle in this php.ini and `preventStrayRequests()` stops the attempt.
     */
    private function timeout(string $url): ConnectionException
    {
        return new ConnectionException(
            'cURL error 28: Operation timed out after 10001 milliseconds with 0 bytes received '
            .'(see https://curl.se/libcurl/c/libcurl-errors.html) for '.$url
        );
    }

    /** @return list<MediaItem> */
    private function image(): array
    {
        $stored = app(AttachmentService::class)->attachContents(
            Post::factory()->create(),
            'jpeg-bytes-for-workshop-bench',
            'workshop-bench.jpg',
            'image/jpeg',
        );

        $item = MediaItem::fromAttachment($stored);

        $this->assertNotNull($item);

        return [$item];
    }

    private function assertLeakFree(PublishFailed $e): void
    {
        $this->assertStringNotContainsString(self::BOT_TOKEN, $e->getMessage());

        // The whole path is gone, not just the token in it: the redaction works
        // by keeping the host and dropping everything else, so a `/bot` anywhere
        // in the message means the URL came through and the next credential
        // shaped differently would too.
        $this->assertStringNotContainsString('/bot', $e->getMessage());

        $this->assertStringContainsString('api.telegram.org', $e->getMessage());
        $this->assertStringContainsString('cURL error 28', $e->getMessage());
        $this->assertStringContainsString('Operation timed out', $e->getMessage());
    }

    public function test_a_timed_out_text_send_does_not_put_the_bot_token_in_the_failure(): void
    {
        Http::fake(fn () => throw $this->timeout(
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage'
        ));

        try {
            (new TelegramPublisher)->publish($this->telegramAccount(), 'A post nobody will see.');

            $this->fail('Telegram reported a publish through a connection failure.');
        } catch (PublishFailed $e) {
            $this->assertLeakFree($e);
        }
    }

    public function test_a_timed_out_photo_upload_does_not_put_the_bot_token_in_the_failure(): void
    {
        Http::fake(fn () => throw $this->timeout(
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendPhoto'
        ));

        try {
            (new TelegramPublisher)->publish(
                $this->telegramAccount(),
                'A post nobody will see.',
                $this->image(),
            );

            $this->fail('Telegram reported a publish through a connection failure.');
        } catch (PublishFailed $e) {
            $this->assertLeakFree($e);
        }
    }

    /**
     * The redaction may not depend on recognising Guzzle's sentence.
     *
     * A client that puts the URL first, or says nothing else at all, is the case
     * where a rule written as "strip the tail after ` for `" quietly stops
     * working — and stops working by leaking. The cut is positional, so this
     * loses the reason (there is none) and keeps the host.
     */
    public function test_a_bare_url_as_the_whole_client_message_still_leaks_nothing(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'https://api.telegram.org/bot'.self::BOT_TOKEN.'/sendMessage timed out'
        ));

        try {
            (new TelegramPublisher)->publish($this->telegramAccount(), 'A post nobody will see.');

            $this->fail('Telegram reported a publish through a connection failure.');
        } catch (PublishFailed $e) {
            $this->assertStringNotContainsString(self::BOT_TOKEN, $e->getMessage());
            $this->assertStringContainsString('api.telegram.org', $e->getMessage());
        }
    }

    /**
     * The Meta family had the same hole in a worse place, and it is closed here.
     *
     * `MetaGraph::graphBody()` does not go through `HttpPublisher`'s catch sites —
     * it has its own — so the fix above did not reach it. All three Meta
     * `verify()` calls send the access token as a `$fields` entry on a **GET**,
     * which Guzzle appends as a query string, so the token is in the effective
     * URI exactly as Telegram's is in the path.
     *
     * 🔴 It is worse than the publish path because of where it is rendered.
     * `⚡account-connect` catches `PublishFailed` and puts `getMessage()` into
     * both the check result and a toast — so a slow credential check printed a
     * working Instagram token on screen, at the exact moment somebody is pasting
     * credentials in and likely to screenshot the page. The publish paths were
     * always safe: they are POSTs and the token rides in the body, which is
     * deliberate and documented on `MetaGraph::graphSend()`.
     *
     * Instagram stands for all three; they share the one trait and the one catch
     * site, so a second and third copy of this would assert the same line twice.
     */
    public function test_a_timed_out_meta_credential_check_does_not_print_the_access_token(): void
    {
        $token = 'EXAMPLE-NOT-A-REAL-META-TOKEN-for-tests-only';

        $account = SocialAccount::factory()->onNetwork(Networks::INSTAGRAM)->create([
            'credentials' => ['ig_user_id' => '17841400000000000', 'access_token' => $token],
            'connected_at' => now(),
        ]);

        // The token where Guzzle actually puts it on a GET — the query string.
        Http::fake(fn () => throw $this->timeout(
            'https://graph.instagram.com/v23.0/17841400000000000?fields=id%2Cusername&access_token='.$token
        ));

        try {
            (new InstagramPublisher)->verify($account);

            $this->fail('Instagram reported a verified account through a connection failure.');
        } catch (PublishFailed $e) {
            $this->assertStringNotContainsString($token, $e->getMessage());
            $this->assertStringNotContainsString('access_token', $e->getMessage());

            $this->assertStringContainsString('graph.instagram.com', $e->getMessage());
            $this->assertStringContainsString('cURL error 28', $e->getMessage());
        }
    }
}
