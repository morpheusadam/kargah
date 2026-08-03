<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Contracts\EmailReader;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\CustomerResolver;
use Modules\Project\Models\Card;
use Tests\TestCase;

/**
 * The model layer of the mailbox.
 *
 * Three of these tests are not about convenience, and would be worth keeping
 * even if everything else here were deleted: the IMAP password must not be
 * serialisable, a duplicate `message_id` must fail rather than duplicate a
 * message, and an email must reach a card through Core's link table rather than
 * through a column either module owns.
 */
class MailboxModelTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple';

    /* The secret ---------------------------------------------------------------- */

    public function test_the_imap_password_round_trips_through_encryption(): void
    {
        $account = MailAccount::factory()->create(['password' => self::PASSWORD]);

        $this->assertSame(self::PASSWORD, $account->fresh()->password);
    }

    public function test_the_password_is_ciphertext_on_disk(): void
    {
        $account = MailAccount::factory()->create(['password' => self::PASSWORD]);

        $stored = DB::table('mail_accounts')->where('id', $account->id)->value('imap_password_encrypted');

        $this->assertNotSame(self::PASSWORD, $stored);
        $this->assertStringNotContainsString(self::PASSWORD, (string) $stored);
    }

    /**
     * The `encrypted` cast decrypts on read, so `toArray()` would hand out the
     * plaintext if the column were not hidden. This is the assertion that keeps
     * it that way.
     */
    public function test_the_password_never_appears_in_to_array_or_to_json(): void
    {
        $account = MailAccount::factory()->create(['password' => self::PASSWORD]);

        $array = $account->toArray();

        $this->assertArrayNotHasKey('imap_password_encrypted', $array);
        $this->assertArrayNotHasKey('password', $array);
        $this->assertStringNotContainsString(self::PASSWORD, $account->toJson());
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($array));
    }

    /**
     * A Livewire component that puts a model in its payload, or a template that
     * dumps one for Alpine, is how a secret reaches the page source. Rendering
     * the model is the closest a model test can get to that mistake.
     */
    public function test_the_password_never_reaches_rendered_output(): void
    {
        $account = MailAccount::factory()->create(['password' => self::PASSWORD]);

        $rendered = Blade::render(
            '{{ $account->name }} {{ $account->imap_host }} {!! $account->toJson() !!}',
            ['account' => $account],
        );

        $this->assertStringContainsString($account->imap_host, $rendered);
        $this->assertStringNotContainsString(self::PASSWORD, $rendered);
    }

    /* Idempotency at the database level ------------------------------------------ */

    public function test_a_duplicate_message_id_is_refused_by_the_database(): void
    {
        $account = MailAccount::factory()->create();

        Email::factory()->for($account, 'account')->create(['message_id' => '<one@northwind.example>']);

        // The unique index is what makes the IMAP job safe to re-run, which is
        // what makes it safe to run from cron. Nothing may work around it.
        $this->expectException(UniqueConstraintViolationException::class);

        Email::factory()->for($account, 'account')->create(['message_id' => '<one@northwind.example>']);
    }

    /* Resolving a sender to a customer ------------------------------------------- */

    private function resolver(): CustomerResolver
    {
        return tap(app(CustomerResolver::class))->forget();
    }

    public function test_a_sender_resolves_to_a_customer_whatever_the_casing(): void
    {
        $customer = Customer::factory()->create(['email' => 'sam@northwind.example']);

        $resolver = $this->resolver();

        $this->assertSame($customer->id, $resolver->resolve('sam@northwind.example'));
        $this->assertSame($customer->id, $resolver->resolve('Sam@Northwind.Example'));
        $this->assertSame($customer->id, $resolver->resolve('  SAM@NORTHWIND.EXAMPLE  '));
    }

    public function test_a_stranger_resolves_to_nothing(): void
    {
        Customer::factory()->create(['email' => 'sam@northwind.example']);

        $resolver = $this->resolver();

        $this->assertNull($resolver->resolve('recruiter@somewhere.example'));
        $this->assertNull($resolver->resolve(''));
    }

    /**
     * The sync calls this once per message and a thread is one person writing
     * repeatedly, so the second lookup of an address must not reach the
     * database — including when the first one found nothing.
     */
    public function test_the_resolver_asks_the_database_once_per_address(): void
    {
        Customer::factory()->create(['email' => 'sam@northwind.example']);

        $resolver = $this->resolver();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $resolver->resolve('sam@northwind.example');
        $resolver->resolve('SAM@northwind.example');
        $resolver->resolve('nobody@elsewhere.example');
        $resolver->resolve('nobody@elsewhere.example');

        $this->assertCount(2, DB::getQueryLog());

        DB::disableQueryLog();
    }

    public function test_resolving_a_message_writes_the_customer_onto_it(): void
    {
        $customer = Customer::factory()->create(['email' => 'joris@acmestudio.example']);
        $email = Email::factory()->create(['from_email' => 'Joris@AcmeStudio.example']);

        $this->assertSame($customer->id, $this->resolver()->resolveAndAttach($email));
        $this->assertDatabaseHas('emails', ['id' => $email->id, 'customer_id' => $customer->id]);
    }

    /**
     * Somebody may have attached the message by hand. A re-sync that fails to
     * match the sender must not undo that.
     */
    public function test_a_miss_does_not_clear_a_customer_somebody_set_by_hand(): void
    {
        $customer = Customer::factory()->create(['email' => 'priya@bluepeak.example']);
        $email = Email::factory()->create([
            'from_email' => 'no-reply@newsletter.example',
            'customer_id' => $customer->id,
        ]);

        $this->assertSame($customer->id, $this->resolver()->resolveAndAttach($email));
        $this->assertDatabaseHas('emails', ['id' => $email->id, 'customer_id' => $customer->id]);
    }

    /* Reading and previewing ----------------------------------------------------- */

    public function test_the_scopes_filter_the_inbox(): void
    {
        Email::factory()->unread()->create();
        Email::factory()->read()->starred()->create();
        Email::factory()->read()->inFolder('Archive')->create();

        $this->assertSame(1, Email::query()->unread()->count());
        $this->assertSame(1, Email::query()->starred()->count());
        $this->assertSame(2, Email::query()->inFolder('INBOX')->count());
        $this->assertSame(1, Email::query()->inFolder('Archive')->count());
    }

    public function test_the_preview_flattens_the_body_to_one_line(): void
    {
        $email = Email::factory()->create([
            'body_text' => "The current retainer ends on 30 September.\n\n   Could you send   the hours used?",
        ]);

        $this->assertSame(
            'The current retainer ends on 30 September. Could you send the hours used?',
            $email->preview(),
        );
    }

    public function test_the_preview_falls_back_to_the_html_body_without_leaking_markup(): void
    {
        $email = Email::factory()->htmlOnly()->create();

        $preview = $email->preview();

        $this->assertSame('The proposal is attached. Let me know before Friday.', $preview);
        $this->assertStringNotContainsString('<', $preview);
        $this->assertStringNotContainsString('margin', $preview);
    }

    /* The link table, both ways --------------------------------------------------- */

    public function test_an_email_and_a_card_can_be_linked_and_read_back_from_either_end(): void
    {
        // No foreign key on either side: an email becomes a card through Core's
        // generic link table, which is what lets it also point at an invoice
        // without Mailbox growing a column per module.
        $email = Email::factory()->create(['subject' => 'Booking widget without a build step']);
        $card = Card::factory()->create(['title' => 'Scope the Bluepeak booking widget']);

        $email->linkTo($card, 'became');

        $this->assertTrue($email->isLinkedTo($card));
        $this->assertTrue($card->isLinkedTo($email));
        $this->assertSame($card->id, $email->linked('card')->first()->id);
        $this->assertSame($email->id, $card->linked('email')->first()->id);
        $this->assertDatabaseHas('links', ['source_type' => 'email', 'target_type' => 'card', 'relation' => 'became']);
    }

    /* The contract other modules read through -------------------------------------- */

    public function test_the_email_reader_hands_back_arrays_rather_than_models(): void
    {
        $customer = Customer::factory()->create(['email' => 'deniz@harbourfinch.example']);
        Email::factory()->create([
            'customer_id' => $customer->id,
            'subject' => 'Invoice 2026-0114 — lira equivalent',
            'body_text' => 'Our accountant has sent the invoice back.',
        ]);

        $rows = app(EmailReader::class)->forCustomer($customer->id);

        $this->assertCount(1, $rows);
        $this->assertIsArray($rows->first());
        $this->assertSame(
            ['id', 'subject', 'from_name', 'from_email', 'preview', 'received_at',
                'is_read', 'is_starred', 'has_attachments', 'folder', 'url'],
            array_keys($rows->first()),
        );
        $this->assertSame('Invoice 2026-0114 — lira equivalent', $rows->first()['subject']);
        $this->assertSame('Our accountant has sent the invoice back.', $rows->first()['preview']);
    }

    public function test_the_email_reader_counts_and_limits(): void
    {
        $customer = Customer::factory()->create(['email' => 'sam@northwind.example']);
        Email::factory()->count(5)->create(['customer_id' => $customer->id]);
        Email::factory()->count(2)->create();

        $reader = app(EmailReader::class);

        $this->assertSame(5, $reader->countForCustomer($customer->id));
        $this->assertCount(3, $reader->forCustomer($customer->id, limit: 3));
        $this->assertSame(0, $reader->countForCustomer(999_999));
    }

    /* The seeder ------------------------------------------------------------------- */

    public function test_the_seeded_inbox_resolves_its_senders_to_real_customers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sam = Customer::query()->where('email', 'sam@northwind.example')->firstOrFail();

        $this->assertGreaterThan(0, app(EmailReader::class)->countForCustomer($sam->id));

        foreach (['joris@acmestudio.example', 'priya@bluepeak.example', 'deniz@harbourfinch.example'] as $address) {
            $customer = Customer::query()->where('email', $address)->firstOrFail();

            $this->assertGreaterThan(
                0,
                Email::query()->forCustomer($customer->id)->count(),
                $address.' sent mail that resolved to nobody.',
            );
        }
    }

    public function test_seeding_twice_leaves_the_mailbox_as_the_first_run_did(): void
    {
        $this->seed(DatabaseSeeder::class);

        $counts = fn (): array => [
            'mail_accounts' => MailAccount::query()->count(),
            'email_threads' => EmailThread::query()->count(),
            'emails' => Email::query()->count(),
            'email_attachments' => EmailAttachment::query()->count(),
            'resolved' => Email::query()->whereNotNull('customer_id')->count(),
        ];

        $first = $counts();
        $ciphertext = DB::table('mail_accounts')->value('imap_password_encrypted');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, $counts(), 'The mailbox seeder is not idempotent, so every deploy duplicates the inbox.');
        $this->assertGreaterThan(30, $first['emails']);
        $this->assertGreaterThan(0, $first['resolved']);

        // Re-encrypting the same password would produce different ciphertext and
        // touch the row on every deploy, which is why the seeder only writes it
        // when the column is empty.
        $this->assertSame($ciphertext, DB::table('mail_accounts')->value('imap_password_encrypted'));
    }

    /**
     * `tap($this)->…->save()` returns the boolean from `save()`, not the model.
     * `tap()` hands the value back only when the work happens inside its
     * callback; chaining off it evaluates the chain instead. Declared
     * `: static`, that form threw a TypeError on every call — and two separate
     * pieces of work routed around it rather than calling it, which is how a
     * broken method survives to ship.
     */
    public function test_the_read_and_starred_helpers_return_the_model_they_are_declared_to(): void
    {
        $email = Email::factory()->create(['is_read' => false, 'is_starred' => false]);

        $this->assertInstanceOf(Email::class, $email->markRead());
        $this->assertInstanceOf(Email::class, $email->markStarred());

        $this->assertTrue($email->fresh()->is_read);
        $this->assertTrue($email->fresh()->is_starred);

        $this->assertFalse($email->markRead(false)->fresh()->is_read);
        $this->assertFalse($email->markStarred(false)->fresh()->is_starred);
    }
}
