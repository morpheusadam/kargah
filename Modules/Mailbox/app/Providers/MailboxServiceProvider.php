<?php

namespace Modules\Mailbox\Providers;

use Modules\Core\Support\MorphMap;
use Modules\Mailbox\Contracts\EmailReader as EmailReaderContract;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\CustomerResolver;
use Modules\Mailbox\Services\EmailReader;
use Nwidart\Modules\Support\ModuleServiceProvider;

class MailboxServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Mailbox';

    protected string $nameLower = 'mailbox';

    /** @var string[] */
    protected array $commands = [];

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
    }

    public function boot(): void
    {
        parent::boot();

        // Aliases, not class names. These rows outlive refactors — see
        // Modules\Core\Support\MorphMap. `email_attachment` is absent on
        // purpose: an attachment is never linked to, activity-logged or
        // searched on its own, only through the message that carried it.
        MorphMap::register([
            'email' => Email::class,
            'email_thread' => EmailThread::class,
            'mail_account' => MailAccount::class,
        ]);
    }
}
