<?php

namespace Modules\Mailbox\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\CustomerResolver;

/**
 * One mailbox and a month of correspondence in it.
 *
 * Four of the senders are the customers `CoreDatabaseSeeder` writes, so the
 * customer-resolution join is not a claim in a docblock but something you can
 * see on the client page: open Sam Okafor and his messages are there. Every
 * inbound message goes through `CustomerResolver` rather than having its
 * `customer_id` written by hand, which means the seeder exercises the same code
 * path the IMAP sync will.
 *
 * Idempotent, keyed on `message_id` — the same natural key the sync relies on,
 * and the reason a re-run of either is harmless. Two consequences follow, and
 * both are deliberate:
 *
 * - every timestamp is an offset from midnight rather than from `now()`, so the
 *   second run writes the values the first one did and no row comes back dirty;
 * - the IMAP password is written only when the column is empty. The `encrypted`
 *   cast uses a fresh IV per write, so re-encrypting the same password would
 *   produce different ciphertext and touch the row on every deploy.
 */
class MailboxDatabaseSeeder extends Seeder
{
    /** The address the mailbox belongs to; every inbound message is addressed to it. */
    private const OWNER_EMAIL = 'admin@kargah.local';

    private const OWNER_NAME = 'Nima Fazlipour';

    public function run(): void
    {
        $resolver = app(CustomerResolver::class);

        // Customers may have been added since this process started; the memo is
        // per-instance and the container hands out one instance.
        $resolver->forget();

        DB::transaction(function () use ($resolver): void {
            $account = $this->account();

            foreach ($this->threads() as $data) {
                $this->seedThread($account, $data, $resolver);
            }
        });
    }

    /**
     * The single mailbox Kargah reads.
     *
     * A plausible host and port rather than a placeholder, because the settings
     * screen has to be readable before the sync command exists, and `is_active`
     * so the scheduled job would pick it up the moment it does.
     */
    private function account(): MailAccount
    {
        $account = MailAccount::query()->updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'name' => 'Studio inbox',
                'imap_host' => 'imap.migadu.com',
                'imap_port' => 993,
                'imap_encryption' => 'ssl',
                'imap_validate_cert' => true,
                'imap_username' => self::OWNER_EMAIL,
                'default_folder' => 'INBOX',
                'is_active' => true,
                'created_by' => $this->owner()?->id,
            ],
        );

        if ($account->imap_password_encrypted === null) {
            $account->password = 'seeded-not-a-real-password';
            $account->save();
        }

        return $account;
    }

    /** Whoever this database belongs to, if anybody does yet. */
    private function owner(): ?User
    {
        return User::query()->where('email', self::OWNER_EMAIL)->first()
            ?? User::query()->first();
    }

    private function seedThread(MailAccount $account, array $data, CustomerResolver $resolver): void
    {
        // A subject is the only stable natural key a thread has — the header
        // that really identifies one belongs to its first message, and keying
        // on that would make a thread unfindable if its opener were ever
        // deleted.
        $thread = EmailThread::query()->firstOrCreate(['subject' => $data['subject']]);

        $previousMessageId = null;

        foreach ($data['messages'] as $message) {
            $email = $this->seedEmail($account, $thread, $data['subject'], $message, $previousMessageId, $resolver);

            $previousMessageId = $email->message_id;
        }

        $thread->refreshCounters();
    }

    private function seedEmail(
        MailAccount $account,
        EmailThread $thread,
        string $subject,
        array $message,
        ?string $inReplyTo,
        CustomerResolver $resolver,
    ): Email {
        $outbound = ($message['from'][1] ?? null) === self::OWNER_EMAIL;

        $email = Email::query()->updateOrCreate(
            ['message_id' => $message['id']],
            [
                'mail_account_id' => $account->id,
                'email_thread_id' => $thread->id,
                'in_reply_to' => $inReplyTo,
                'uid' => $message['uid'],
                // The opener carries the subject as written; a reply carries it
                // prefixed, exactly as a mail client would have sent it.
                'subject' => $inReplyTo === null ? $subject : 'Re: '.$subject,
                'from_name' => $message['from'][0],
                'from_email' => $message['from'][1],
                'to' => [$outbound
                    ? ['name' => $message['to'][0], 'email' => $message['to'][1]]
                    : ['name' => self::OWNER_NAME, 'email' => self::OWNER_EMAIL],
                ],
                'cc' => $message['cc'] ?? null,
                'body_text' => $message['body'] ?? null,
                'body_html' => $message['html'] ?? null,
                'has_attachments' => ($message['attachments'] ?? []) !== [],
                'is_read' => $message['read'] ?? true,
                'is_starred' => $message['starred'] ?? false,
                'folder' => $message['folder'] ?? ($outbound ? 'Sent' : 'INBOX'),
                // Anchored to midnight, so a second run writes the same value.
                'received_at' => now()->startOfDay()->subDays($message['days'])->setTimeFromTimeString($message['at']),
            ],
        );

        // The same call the sync will make, rather than a hand-written id: if
        // the resolver stops matching, the seeded inbox stops showing customers
        // and the failure is visible on the client page.
        $resolver->resolveAndAttach($email);

        foreach ($message['attachments'] ?? [] as $attachment) {
            EmailAttachment::query()->updateOrCreate(
                ['email_id' => $email->id, 'filename' => $attachment[0]],
                [
                    'mime' => $attachment[1],
                    'size_bytes' => $attachment[2],
                    'content_id' => null,
                    'part_number' => (string) ($attachment[3] ?? 2),
                    // Phase 6 fills this in, when Data actually holds the bytes.
                    'attachment_id' => null,
                ],
            );
        }

        return $email;
    }

    /**
     * Forty messages in twelve conversations.
     *
     * `days` is an offset back from today and `at` the time of day, so the
     * inbox always has something from this morning and something from last
     * month. `uid` climbs with age the way a real IMAP mailbox's does — oldest
     * message, lowest number — because the sync's high-water mark depends on
     * that being true.
     *
     * Messages sent from `admin@kargah.local` are the freelancer's own replies
     * and land in Sent; they resolve to no customer, which is correct and worth
     * seeing in the data.
     */
    private function threads(): array
    {
        $me = [self::OWNER_NAME, self::OWNER_EMAIL];

        $helen = ['Helen Vasquez', 'helen@northwind.example'];
        $sam = ['Sam Okafor', 'sam@northwind.example'];
        $joris = ['Joris Bakker', 'joris@acmestudio.example'];
        $priya = ['Priya Nandakumar', 'priya@bluepeak.example'];
        $deniz = ['Deniz Aydın', 'deniz@harbourfinch.example'];
        $marta = ['Marta Lindqvist', 'marta@orbitstudio.example'];

        return [
            [
                'subject' => 'Retainer renewal — September onwards',
                'messages' => [
                    [
                        'id' => '<northwind-retainer-01@northwind.example>', 'uid' => 4102,
                        'from' => $helen, 'days' => 12, 'at' => '09:14', 'read' => true,
                        'body' => "The current retainer ends on 30 September and I would like to have the renewal signed before the board meeting rather than after it.\n\nCould you send me the hours used since January, and whatever you think the day rate should be for the next twelve months? I would rather discuss a number you have already worked out than negotiate one on the call.",
                    ],
                    [
                        'id' => '<northwind-retainer-02@kargah.local>', 'uid' => 4108,
                        'from' => $me, 'to' => $helen, 'days' => 12, 'at' => '11:02', 'read' => true,
                        'body' => "Of course. The timesheet totals are straightforward, and I will put the rate in a one-page summary rather than a proposal document — there is nothing in the scope that has changed.\n\nOne thing worth deciding before the call: what happens to unused days. Carrying them forever is how last year ended up with a fourteen-day backlog nobody could schedule.",
                    ],
                    [
                        'id' => '<northwind-retainer-03@northwind.example>', 'uid' => 4131,
                        'from' => $helen, 'days' => 11, 'at' => '08:40', 'read' => true,
                        'body' => "Agreed on the unused days — propose something and I will take it to the board. The meeting is on the 20th, so anything that reaches me by the 18th gets read properly.\n\nSam will be on the call for the technical side.",
                    ],
                    [
                        'id' => '<northwind-retainer-04@northwind.example>', 'uid' => 4390,
                        'from' => $helen, 'days' => 3, 'at' => '16:20', 'read' => false, 'starred' => true,
                        'body' => 'Any news on the summary? I am putting the agenda together tomorrow morning and would like the renewal on it rather than under any other business.',
                    ],
                ],
            ],
            [
                'subject' => 'Analytics dashboard scope',
                'messages' => [
                    [
                        'id' => '<northwind-analytics-01@northwind.example>', 'uid' => 3820,
                        'from' => $sam, 'days' => 21, 'at' => '10:05', 'read' => true,
                        'body' => "We want the analytics dashboard scoped before the new financial year, which for us starts in October. Nothing exotic — revenue by product line, the funnel from the sign-up page, and whatever you think we are missing.\n\nWhat would you need from us to put a number on it?",
                    ],
                    [
                        'id' => '<northwind-analytics-02@kargah.local>', 'uid' => 3824,
                        'from' => $me, 'to' => $sam, 'days' => 21, 'at' => '15:30', 'read' => true,
                        'body' => "Read access to the reporting replica and an hour with whoever currently builds the monthly figures by hand. The second one matters more: the spreadsheet somebody maintains is usually the real specification.\n\nI can scope it in about three days once I have both.",
                    ],
                    [
                        'id' => '<northwind-analytics-03@northwind.example>', 'uid' => 3901,
                        'from' => $sam, 'days' => 19, 'at' => '09:20', 'read' => true,
                        'body' => "Replica access is with IT and should land this week. The spreadsheet is attached — it is worse than you are imagining, and the third tab is the one everybody actually reads.\n\nSketch of the layout we had in mind is attached too, though do not treat it as fixed.",
                        'attachments' => [
                            ['northwind-monthly-figures.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 214_688, 2],
                            ['dashboard-sketch.png', 'image/png', 486_112, 3],
                        ],
                    ],
                    [
                        'id' => '<northwind-analytics-04@northwind.example>', 'uid' => 4330,
                        'from' => $sam, 'days' => 5, 'at' => '11:45', 'read' => false,
                        'body' => 'IT have finally opened the replica. Credentials went to you in a separate message from them, not from me, so check the filters if nothing has arrived.',
                    ],
                ],
            ],
            [
                'subject' => 'Mail module — provider credentials screen',
                'messages' => [
                    [
                        'id' => '<acme-mailmodule-01@acmestudio.example>', 'uid' => 3950,
                        'from' => $joris, 'days' => 18, 'at' => '08:12', 'read' => true,
                        'body' => "The credentials store landed this morning, so the provider screen is unblocked. Before you start: we will be moving off Postmark next year, so nothing in that screen should assume a single provider.\n\nTwo fields we will definitely need that are not in the mock-up: a sending domain and a webhook secret.",
                    ],
                    [
                        'id' => '<acme-mailmodule-02@kargah.local>', 'uid' => 3956,
                        'from' => $me, 'to' => $joris, 'days' => 18, 'at' => '09:40', 'read' => true,
                        'body' => "Both noted. The screen reads its fields from the provider definition rather than hard-coding them, so adding a provider with a different set is a configuration change and not a rewrite.\n\nThe webhook secret will be write-only in the interface — shown once when generated and never rendered again.",
                    ],
                    [
                        'id' => '<acme-mailmodule-03@acmestudio.example>', 'uid' => 3988,
                        'from' => $joris, 'days' => 17, 'at' => '07:55', 'read' => true,
                        'body' => 'Write-only is right. Our last vendor put the secret in a value attribute and it ended up in the page source of every settings page anyone opened.',
                    ],
                    [
                        'id' => '<acme-mailmodule-04@acmestudio.example>', 'uid' => 4210,
                        'from' => $joris, 'days' => 9, 'at' => '13:10', 'read' => true, 'starred' => true,
                        'body' => "Reviewed the branch. The provider layer is clean and I have approved it.\n\nOne request before it merges: rate limiting should be per provider rather than global, because the limits are not the same and the strictest one would otherwise govern everything.",
                    ],
                    [
                        'id' => '<acme-mailmodule-05@acmestudio.example>', 'uid' => 4420,
                        'from' => $joris, 'days' => 2, 'at' => '08:30', 'read' => false,
                        'body' => 'Are the bounce webhooks in this release or the next one? The support team are asking, and I would rather tell them a date than a maybe.',
                    ],
                ],
            ],
            [
                'subject' => 'Booking widget without a build step',
                'messages' => [
                    [
                        'id' => '<bluepeak-widget-01@bluepeak.example>', 'uid' => 4020,
                        'from' => $priya, 'days' => 15, 'at' => '12:30', 'read' => true,
                        'body' => "Our site is a static build we inherited and nobody here can run a bundler, so whatever you write has to drop in as one script tag and one stylesheet.\n\nIt needs to take a date, a party size and a phone number, and post to the booking endpoint we already have.",
                    ],
                    [
                        'id' => '<bluepeak-widget-02@kargah.local>', 'uid' => 4025,
                        'from' => $me, 'to' => $priya, 'days' => 15, 'at' => '14:05', 'read' => true,
                        'body' => "Plain JavaScript and a single stylesheet then, with no framework and no build. It will be larger than a bundled version and that is the right trade.\n\nSend me the brand guide and the endpoint's response format and I will have something you can paste into a page by the end of next week.",
                    ],
                    [
                        'id' => '<bluepeak-widget-03@bluepeak.example>', 'uid' => 4090,
                        'from' => $priya, 'days' => 14, 'at' => '09:15', 'read' => true,
                        'body' => 'Brand guide attached. The endpoint returns a booking reference on success and a plain string on failure, which I appreciate is not ideal — we can change it if that makes your side simpler.',
                        'attachments' => [
                            ['bluepeak-brand-guide.pdf', 'application/pdf', 1_842_007, 2],
                        ],
                    ],
                    [
                        'id' => '<bluepeak-widget-04@bluepeak.example>', 'uid' => 4402,
                        'from' => $priya, 'days' => 4, 'at' => '17:40', 'read' => false, 'starred' => true,
                        'body' => 'We have been asked about the widget by a studio we work with — I passed your name to Marta at Orbit. She may well write to you directly.',
                    ],
                ],
            ],
            [
                'subject' => 'Invoice 2026-0114 — lira equivalent',
                'messages' => [
                    [
                        'id' => '<harbourfinch-invoice-01@harbourfinch.example>', 'uid' => 4180,
                        'from' => $deniz, 'days' => 10, 'at' => '07:25', 'read' => true,
                        'body' => "Our accountant has sent the invoice back. A foreign-currency invoice to a Turkish buyer has to show the central bank buying rate and the lira equivalent, with the rate date on the face of the document.\n\nThe amount is not in dispute — this is purely what the document has to say.",
                    ],
                    [
                        'id' => '<harbourfinch-invoice-02@kargah.local>', 'uid' => 4186,
                        'from' => $me, 'to' => $deniz, 'days' => 10, 'at' => '10:15', 'read' => true,
                        'body' => "Reissued and attached. It carries the TCMB buying rate for the issue date, the lira equivalent, and a note naming the source of the rate.\n\nThe rate is frozen at issue rather than recalculated when the invoice is opened, so the figure your accountant checks today is the one that will be there in a year.",
                        'attachments' => [
                            ['invoice-2026-0114.pdf', 'application/pdf', 96_430, 2],
                        ],
                    ],
                    [
                        'id' => '<harbourfinch-invoice-03@harbourfinch.example>', 'uid' => 4193,
                        'from' => $deniz, 'days' => 9, 'at' => '06:50', 'read' => true,
                        'body' => 'That is exactly what was wanted. It is with accounts payable now and should be paid on the next run.',
                    ],
                    [
                        'id' => '<harbourfinch-invoice-04@harbourfinch.example>', 'uid' => 4470,
                        'from' => $deniz, 'days' => 1, 'at' => '15:05', 'read' => false,
                        'body' => 'Payment went out this morning. Could you send future invoices to accounts directly and copy me, rather than the other way round?',
                    ],
                ],
            ],
            [
                'subject' => 'Introduction — Orbit Studio',
                'messages' => [
                    [
                        'id' => '<orbit-intro-01@orbitstudio.example>', 'uid' => 4240,
                        'from' => $marta, 'days' => 8, 'at' => '11:20', 'read' => true,
                        'body' => "Priya at Bluepeak suggested I write. We are six people, all designers, and we have no developer — every site we ship ends up maintained by whoever built it last, which is not a plan.\n\nWhat we want is someone on call for the sites we have already delivered. Is that work you take?",
                    ],
                    [
                        'id' => '<orbit-intro-02@kargah.local>', 'uid' => 4246,
                        'from' => $me, 'to' => $marta, 'days' => 8, 'at' => '12:00', 'read' => true,
                        'body' => "It is, and it is usually the most useful thing a studio your size can buy.\n\nBefore quoting anything I would want to look at two or three of the sites — how they are hosted matters more than how they are built. Would twenty minutes on a call next week suit?",
                    ],
                    [
                        'id' => '<orbit-intro-03@orbitstudio.example>', 'uid' => 4356,
                        'from' => $marta, 'days' => 6, 'at' => '09:35', 'read' => false,
                        'body' => 'Tuesday or Thursday afternoon both work here. I will send links to three sites beforehand, including the one that keeps going down.',
                    ],
                ],
            ],
            [
                'subject' => 'Scheduled maintenance on eu-west-2',
                'messages' => [
                    [
                        'id' => '<windward-maint-01@windward-hosting.example>', 'uid' => 3840,
                        'from' => ['Windward Hosting', 'noreply@windward-hosting.example'],
                        'days' => 20, 'at' => '02:15', 'read' => true, 'folder' => 'Archive',
                        'html' => '<html><head><style>body{font-family:Arial,sans-serif}</style></head><body>'
                            .'<h1>Scheduled maintenance</h1>'
                            .'<p>We will be replacing storage hardware in eu-west-2 on Sunday between 01:00 and 04:00 UTC.</p>'
                            .'<p>Instances will be migrated live. A short pause in network traffic is possible, typically under thirty seconds.</p>'
                            .'</body></html>',
                    ],
                    [
                        'id' => '<windward-maint-02@windward-hosting.example>', 'uid' => 4120,
                        'from' => ['Windward Hosting', 'noreply@windward-hosting.example'],
                        'days' => 13, 'at' => '03:00', 'read' => true, 'folder' => 'Archive',
                        'html' => '<html><body><p>Maintenance on eu-west-2 completed at 03:41 UTC. No customer-visible interruption was recorded.</p></body></html>',
                    ],
                ],
            ],
            [
                'subject' => 'Q2 self-assessment — what I need from you',
                'messages' => [
                    [
                        'id' => '<fieldstone-tax-01@fieldstone-accountants.example>', 'uid' => 3990,
                        'from' => ['Ruth Kellner', 'ruth@fieldstone-accountants.example'],
                        'days' => 16, 'at' => '09:00', 'read' => true,
                        'body' => "The checklist for the quarter is attached, along with the expenses template. The only items I do not already have are the home office share and anything you paid for personally and want to claim.\n\nThe filing deadline gives us three weeks, but the last week of the month is always the one where something turns out to be missing.",
                        'attachments' => [
                            ['self-assessment-checklist.pdf', 'application/pdf', 128_900, 2],
                            ['expenses-template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 41_220, 3],
                        ],
                    ],
                    [
                        'id' => '<fieldstone-tax-02@kargah.local>', 'uid' => 3996,
                        'from' => $me, 'to' => ['Ruth Kellner', 'ruth@fieldstone-accountants.example'],
                        'days' => 16, 'at' => '18:20', 'read' => true,
                        'body' => "The bank statements and the invoice totals are done. The home office share I will work out this week — the floor area has not changed but the number of days has.\n\nOne question: the certificate renewal is billed annually in April. Does it go in this quarter or get apportioned?",
                    ],
                    [
                        'id' => '<fieldstone-tax-03@fieldstone-accountants.example>', 'uid' => 4300,
                        'from' => ['Ruth Kellner', 'ruth@fieldstone-accountants.example'],
                        'days' => 7, 'at' => '09:05', 'read' => false, 'starred' => true,
                        'body' => 'Claim it in the quarter it was paid — apportioning a subscription that small costs more in bookkeeping than it saves. Still waiting on the home office figure.',
                    ],
                ],
            ],
            [
                'subject' => 'Domain renewal — kargah.dev expires in 30 days',
                'messages' => [
                    [
                        'id' => '<registrar-renew-01@registrar.example>', 'uid' => 3760,
                        'from' => ['Registrar billing', 'billing@registrar.example'],
                        'days' => 25, 'at' => '04:40', 'read' => true, 'folder' => 'Archive',
                        'html' => '<html><body><p>kargah.dev renews on the 14th of next month. Auto-renewal is <strong>on</strong> and the card ending 4417 will be charged.</p>'
                            .'<p>Privacy protection remains enabled at no extra cost.</p></body></html>',
                    ],
                    [
                        'id' => '<registrar-renew-02@registrar.example>', 'uid' => 4140,
                        'from' => ['Registrar billing', 'billing@registrar.example'],
                        'days' => 11, 'at' => '04:40', 'read' => false, 'folder' => 'Archive',
                        'html' => '<html><body><p>Reminder: kargah.dev renews in fourteen days. No action is required if your payment details are current.</p></body></html>',
                    ],
                ],
            ],
            [
                'subject' => 'Invoice PDF footer overlaps the last row',
                'messages' => [
                    [
                        'id' => '<northwind-pdfbug-01@northwind.example>', 'uid' => 4340,
                        'from' => $sam, 'days' => 6, 'at' => '14:50', 'read' => true,
                        'body' => "Small thing rather than an emergency: on the invoices with a lot of line items, the footer sits on top of the last row. It only happens on A4 — the same invoice on Letter is fine.\n\nThe one I noticed it on had sixteen lines.",
                    ],
                    [
                        'id' => '<northwind-pdfbug-02@kargah.local>', 'uid' => 4344,
                        'from' => $me, 'to' => $sam, 'days' => 6, 'at' => '16:10', 'read' => true,
                        'body' => 'Reproduced at fifteen lines and above. The footer is positioned absolutely and the table does not know it is there, so the fix is a bottom margin on the page rather than anything to do with the table.',
                    ],
                    [
                        'id' => '<northwind-pdfbug-03@northwind.example>', 'uid' => 4372,
                        'from' => $sam, 'days' => 5, 'at' => '08:05', 'read' => true,
                        'body' => 'Confirmed fixed on the invoice I sent over. Thank you for turning that round quickly.',
                    ],
                ],
            ],
            [
                'subject' => 'Contract for the Q3 work',
                'messages' => [
                    [
                        'id' => '<acme-contract-01@acmestudio.example>', 'uid' => 3700,
                        'from' => $joris, 'days' => 23, 'at' => '10:30', 'read' => true,
                        'body' => "Contract for the three months is attached, signed on our side. The provider work is quoted separately as agreed, so it is not in this document at all.\n\nOne clause to read twice is the one about the hand-over notes — our legal team added it and it is stricter than the last one.",
                        'attachments' => [
                            ['acme-studio-q3-contract.pdf', 'application/pdf', 302_558, 2],
                        ],
                    ],
                    [
                        'id' => '<acme-contract-02@kargah.local>', 'uid' => 3706,
                        'from' => $me, 'to' => $joris, 'days' => 23, 'at' => '12:45', 'read' => true,
                        'body' => "Read and signed. The hand-over clause is fine — the notes were going to be written anyway, and having a date attached to them is not a hardship.\n\nThe countersigned copy went back through your portal rather than by mail, as it asked for.",
                    ],
                    [
                        'id' => '<acme-contract-03@acmestudio.example>', 'uid' => 3712,
                        'from' => $joris, 'days' => 22, 'at' => '08:15', 'read' => true,
                        'body' => 'Received, thank you. Kick-off is whenever the credentials store lands — I will let you know the moment it does.',
                    ],
                ],
            ],
            [
                'subject' => 'Payment received — invoice 2026-0109',
                'messages' => [
                    [
                        'id' => '<ledgerline-payment-01@ledgerline.example>', 'uid' => 4100,
                        'from' => ['Ledgerline', 'payments@ledgerline.example'],
                        'days' => 14, 'at' => '06:05', 'read' => true, 'folder' => 'Archive',
                        'body' => 'A payment of 4,200.00 USD has been credited to your account, referenced 2026-0109. Funds are available immediately.',
                    ],
                    [
                        'id' => '<ledgerline-payment-02@ledgerline.example>', 'uid' => 4160,
                        'from' => ['Ledgerline', 'payments@ledgerline.example'],
                        'days' => 12, 'at' => '06:05', 'read' => true, 'folder' => 'Archive',
                        'body' => 'Your monthly statement is ready. Three incoming payments and one currency conversion were recorded in the period.',
                    ],
                    [
                        'id' => '<ledgerline-payment-03@ledgerline.example>', 'uid' => 4440,
                        'from' => ['Ledgerline', 'payments@ledgerline.example'],
                        'days' => 2, 'at' => '06:05', 'read' => true, 'folder' => 'Archive',
                        'body' => 'A payment of 18,650.00 TRY has been credited to your account, referenced 2026-0114. The conversion rate applied is shown on the statement.',
                    ],
                ],
            ],
        ];
    }
}
