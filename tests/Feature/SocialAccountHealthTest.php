<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\FakePublisher;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * An account that cannot publish has to look like one.
 *
 * This is a defect found by using the application rather than by reading it: an
 * Instagram account whose token Meta had invalidated kept showing `Connected` on
 * the accounts page and kept counting toward "4 of 4 ready to publish", while
 * every post to it failed. The failure *was* recorded — on each `post_targets`
 * row, which is where you look only if you already suspect something.
 *
 * It matters more now than it did when it was found. A person pressing publish
 * sees the red row in front of them. A curated post goes out at ten at night with
 * nobody watching, and an account silently failing for a fortnight is precisely
 * the shape of failure an unattended daily publisher produces.
 */
class SocialAccountHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 09:00:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_failed_publish_marks_the_account_and_not_only_the_target(): void
    {
        $account = $this->account();
        $target = $this->target($account);

        $this->driverThatFails('The access token has been invalidated.');

        app(PostPublisher::class)->publishTarget($target->fresh());

        $account->refresh();

        // The whole defect in two assertions: the account now says what is wrong
        // with it, on the page somebody actually opens.
        $this->assertStringContainsString('invalidated', (string) $account->last_error);
        $this->assertNotNull($account->last_checked_at);
    }

    public function test_the_credential_is_never_rewritten_while_recording_the_failure(): void
    {
        $account = $this->account();
        $encrypted = $account->getRawOriginal('credentials_encrypted');

        $this->driverThatFails('Refused.');

        app(PostPublisher::class)->publishTarget($this->target($account)->fresh());

        // Written with a bare update() rather than a save() on a hydrated model:
        // a save would rewrite every column it holds, including the one whose
        // accessor decrypts and re-encrypts. The stored bytes must not move.
        $this->assertSame($encrypted, $account->fresh()->getRawOriginal('credentials_encrypted'));
    }

    public function test_a_successful_publish_does_not_clear_a_warning_somebody_else_recorded(): void
    {
        $account = $this->account();
        $account->forceFill(['last_error' => 'This token expires in three days.'])->save();

        app(Publishing::class)->swap(new FakePublisher(Networks::LINKEDIN));

        app(PostPublisher::class)->publishTarget($this->target($account)->fresh());

        // `RefreshTokens` and `SyncNotifications` own clearing this column. One
        // target happening to work does not mean the expiry they recorded has
        // stopped being true.
        $this->assertNotNull($account->fresh()->last_error);
    }

    private function account(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::LINKEDIN)->create([
            'credentials' => ['member_urn' => 'urn:li:person:x', 'access_token' => 'token'],
            'is_active' => true,
            'last_error' => null,
        ]);
    }

    private function target(SocialAccount $account): PostTarget
    {
        $post = Post::factory()->create(['status' => Post::SCHEDULED]);

        return PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => PostTarget::PENDING,
        ]);
    }

    private function driverThatFails(string $message): void
    {
        app(Publishing::class)->swap(
            (new FakePublisher(Networks::LINKEDIN))->failWith($message),
        );
    }
}
