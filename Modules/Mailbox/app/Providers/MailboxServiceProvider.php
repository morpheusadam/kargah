<?php

namespace Modules\Mailbox\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Mailbox\Console\DispatchSends;
use Modules\Mailbox\Console\InboundReport;
use Modules\Mailbox\Contracts\EmailReader as EmailReaderContract;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\Contact;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\CustomerResolver;
use Modules\Mailbox\Services\Delivery\BrevoMailer;
use Modules\Mailbox\Services\Delivery\Delivery;
use Modules\Mailbox\Services\Delivery\MailgunMailer;
use Modules\Mailbox\Services\Delivery\PostmarkMailer;
use Modules\Mailbox\Services\Delivery\SesMailer;
use Modules\Mailbox\Services\Delivery\SmtpMailer;
use Modules\Mailbox\Services\EmailReader;
use Modules\Mailbox\Support\Senders;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MailboxServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Mailbox';

    protected string $nameLower = 'mailbox';

    /** @var string[] */
    protected array $commands = [
        DispatchSends::class,
        InboundReport::class,
    ];

    /** @var string[] */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(EmailReaderContract::class, EmailReader::class);

        // A singleton because the resolver memoises: bound as a plain class it
        // would be rebuilt at every injection point and the memo would be
        // thrown away between messages, which is the whole cost it exists to
        // avoid.
        $this->app->singleton(CustomerResolver::class);

        /*
         * The delivery driver registry, as a singleton.
         *
         * Singleton so that a driver swapped in a test's setUp is the same
         * registry the command, the job, the webhook controller and the
         * Livewire pages resolve. Factories rather than instances so a provider
         * nobody sends through is never built — and so a test that swaps a
         * driver for a fake never constructs the real one at all, which is what
         * keeps a Symfony transport from ever being created in the suite.
         */
        $this->app->singleton(Delivery::class, function (): Delivery {
            $delivery = new Delivery;

            $delivery->extend(Senders::BREVO, fn () => new BrevoMailer);
            $delivery->extend(Senders::POSTMARK, fn () => new PostmarkMailer);
            $delivery->extend(Senders::SES, fn () => new SesMailer);
            $delivery->extend(Senders::MAILGUN, fn () => new MailgunMailer);
            $delivery->extend(Senders::SMTP, fn () => new SmtpMailer);

            return $delivery;
        });
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names. These rows outlive refactors — see
        // Modules\Core\Support\MorphMap. `email_attachment` is absent on
        // purpose: an attachment is never linked to, activity-logged or
        // searched on its own, only through the message that carried it. So are
        // `campaign_recipient` and `suppression`, for the same reason — a
        // recipient row is reached through its campaign and a suppression is an
        // address rather than a thing anything points at.
        MorphMap::register([
            'email' => Email::class,
            'email_thread' => EmailThread::class,
            'mail_account' => MailAccount::class,
            'campaign' => Campaign::class,
            'contact' => Contact::class,
            'delivery_provider' => DeliveryProvider::class,
        ]);
    }
}
