<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Tests\TestCase;

/**
 * Receiving mail that was pushed at us rather than fetched.
 *
 * Cloudflare Email Routing hands each message to a Worker, which posts the raw
 * RFC822 to `/mail/inbound`. The properties worth proving are the ones that
 * decide whether that is safe to expose on a public host:
 *
 * 1. The secret is the whole of the authentication, so a caller without it gets
 *    nothing — and cannot tell the endpoint apart from one that does not exist.
 * 2. The same message delivered twice is one row. A sending server that never
 *    sees our 2xx *will* deliver again, so this is not a hypothetical.
 * 3. A failure a retry cannot fix is accepted, not rejected. The Worker turns a
 *    non-2xx into an SMTP rejection, which is how an unparseable message becomes
 *    days of retries and a bounce for a mailbox that works.
 *
 * Nothing here touches the network or webklex's IMAP client — `Message::fromString()`
 * parses a string, which is the entire point of choosing it.
 */
class InboundMailTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'a-shared-secret-only-the-worker-holds';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 10:00:00');

        config(['mailbox.inbound.secret' => self::SECRET]);
    }

    public function test_a_posted_message_becomes_an_email_on_the_inbound_account(): void
    {
        $account = MailAccount::factory()->inbound()->create(['email' => 'info@bineret.com']);

        $this->send()->assertNoContent();

        $email = Email::sole();

        $this->assertSame($account->id, $email->mail_account_id);
        $this->assertSame('<first@sender.example>', '<'.$email->message_id.'>');
        $this->assertSame('Quote for the extension', $email->subject);
        $this->assertSame('ada@sender.example', $email->from_email);
        $this->assertSame('Ada Lovelace', $email->from_name);
        $this->assertSame(['info@bineret.com'], $email->to);
        $this->assertStringContainsString('rough idea of cost', (string) $email->body_text);
        $this->assertSame('INBOX', $email->folder);

        // Pushed mail has no UID: nothing polls it and no cursor resumes
        // against it. `message_id` is what keeps it unique.
        $this->assertSame(0, $email->uid);

        // A thread is opened for it, and its counters are computed rather than
        // left at the zero the row is created with.
        $this->assertSame(1, EmailThread::sole()->message_count);
    }

    public function test_the_same_message_twice_is_one_row(): void
    {
        MailAccount::factory()->inbound()->create();

        $this->send()->assertNoContent();
        $this->send()->assertNoContent();

        $this->assertSame(1, Email::count());
        $this->assertSame(1, EmailThread::count());
        $this->assertSame(1, EmailThread::sole()->message_count);
    }

    public function test_a_reply_joins_the_thread_of_the_message_it_answers(): void
    {
        MailAccount::factory()->inbound()->create();

        $this->send()->assertNoContent();
        $this->send($this->reply())->assertNoContent();

        $this->assertSame(2, Email::count());
        $this->assertSame(1, EmailThread::count());
        $this->assertSame(2, EmailThread::sole()->message_count);
    }

    public function test_a_caller_without_the_secret_gets_nothing(): void
    {
        MailAccount::factory()->inbound()->create();

        $this->send(server: ['HTTP_X_INBOUND_SECRET' => 'not-the-secret'])->assertNotFound();
        $this->send(server: ['HTTP_X_INBOUND_SECRET' => null])->assertNotFound();

        $this->assertSame(0, Email::count());
    }

    /**
     * An install that has configured no secret has deployed no Worker either.
     * Leaving the route open in that state would let a stranger write rows into
     * the owner's inbox, so an unset secret closes it rather than matching the
     * empty header a curious caller sends.
     */
    public function test_an_unconfigured_secret_closes_the_endpoint(): void
    {
        config(['mailbox.inbound.secret' => null]);

        MailAccount::factory()->inbound()->create();

        $this->send()->assertNotFound();
        $this->send(server: ['HTTP_X_INBOUND_SECRET' => ''])->assertNotFound();

        $this->assertSame(0, Email::count());
    }

    /**
     * The status code is the protocol: the Worker rejects anything that is not
     * 2xx, and an SMTP rejection makes the *sending* server try again. Nothing
     * about an unparseable body improves on the tenth attempt, so it is taken
     * off the sender's hands and dropped here.
     */
    public function test_a_body_no_retry_can_fix_is_accepted_rather_than_rejected(): void
    {
        MailAccount::factory()->inbound()->create();

        $this->send('')->assertOk();

        $this->assertSame(0, Email::count());
    }

    /**
     * The one failure that *is* transient. Mail arriving before the account
     * exists — or while it is switched off — is held on the sender's queue
     * instead of being accepted into a system with nowhere to put it.
     */
    public function test_mail_with_no_active_inbound_account_is_held_for_retry(): void
    {
        MailAccount::factory()->inbound()->inactive()->create();

        $this->send()->assertStatus(503);

        $this->assertSame(0, Email::count());
    }

    /**
     * An IMAP account is not a destination for pushed mail. Two accounts exist
     * here and only one of them can receive, which is what stops a message
     * being filed against a mailbox that would then be re-synced over it.
     */
    public function test_an_imap_account_is_never_used_for_pushed_mail(): void
    {
        MailAccount::factory()->create(['email' => 'info@bineret.com']);

        $this->send()->assertStatus(503);

        $this->assertSame(0, Email::count());
    }

    /**
     * The recipient decides the account where more than one exists, so an
     * install receiving for two domains files each under its own.
     */
    public function test_the_envelope_recipient_chooses_between_inbound_accounts(): void
    {
        MailAccount::factory()->inbound()->create(['email' => 'other@example.com']);
        $wanted = MailAccount::factory()->inbound()->create(['email' => 'info@bineret.com']);

        $this->send()->assertNoContent();

        $this->assertSame($wanted->id, Email::sole()->mail_account_id);
    }

    /**
     * The scheduled IMAP command must never pick an inbound account up. It has
     * no host to open, so a tick that took one would spend a connection attempt
     * to learn what `kind` already says and then write the refusal to
     * `last_error`, where the owner reads it as a mailbox that has broken.
     */
    public function test_inbound_accounts_are_not_offered_to_the_imap_sync(): void
    {
        $polled = MailAccount::factory()->create();
        MailAccount::factory()->inbound()->create();

        $due = MailAccount::query()->dueForSync()->get();

        $this->assertSame([$polled->id], $due->pluck('id')->all());
    }

    /**
     * Post a message the way the Worker does: a raw RFC822 body, not a form.
     *
     * `call()` rather than `post()`, because `post()` builds the body from an
     * array of parameters and there is no array here — the request *is* the
     * message, which is what `Message::fromString()` is handed verbatim.
     *
     * @param  array<string, string|null>  $server
     */
    private function send(?string $body = null, array $server = []): \Illuminate\Testing\TestResponse
    {
        $headers = array_merge([
            'CONTENT_TYPE' => 'message/rfc822',
            'HTTP_X_INBOUND_SECRET' => self::SECRET,
            'HTTP_X_MAIL_TO' => 'info@bineret.com',
            'HTTP_X_MAIL_FROM' => 'ada@sender.example',
        ], $server);

        return $this->call(
            'POST',
            route('mail.inbound'),
            [],
            [],
            [],
            array_filter($headers, fn (?string $value): bool => $value !== null),
            $body ?? $this->message(),
        );
    }

    private function message(): string
    {
        return implode("\r\n", [
            'Message-ID: <first@sender.example>',
            'Date: Fri, 7 Aug 2026 09:45:00 +0000',
            'From: Ada Lovelace <ada@sender.example>',
            'To: info@bineret.com',
            'Subject: Quote for the extension',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Could you send a rough idea of cost for the side return?',
            '',
        ]);
    }

    private function reply(): string
    {
        return implode("\r\n", [
            'Message-ID: <second@sender.example>',
            'In-Reply-To: <first@sender.example>',
            'Date: Fri, 7 Aug 2026 11:20:00 +0000',
            'From: Ada Lovelace <ada@sender.example>',
            'To: info@bineret.com',
            'Subject: Re: Quote for the extension',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Adding that the party wall notice is already served.',
            '',
        ]);
    }
}
