<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Accounting\Database\Seeders\AccountingDatabaseSeeder;
use Modules\Blog\Database\Seeders\BlogDatabaseSeeder;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Data\Database\Seeders\DataDatabaseSeeder;
use Modules\Mailbox\Database\Seeders\MailboxDatabaseSeeder;
use Modules\Mailbox\Database\Seeders\MailboxSendingSeeder;
use Modules\Project\Database\Seeders\ProjectDatabaseSeeder;
use Modules\Social\Database\Seeders\SocialDatabaseSeeder;

/**
 * Core first, then every feature module.
 *
 * The order is the module dependency order, not alphabetical: a card that
 * belongs to a customer needs the customer to exist. Every seeder called from
 * here is idempotent, because this runs from the deploy script and a deploy
 * that duplicates the client list is a bad afternoon.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // The owner's own sign-in. `firstOrCreate` matches on the email, so the
        // second array is create-only: running this against an install where the
        // password has since been changed does not reset it.
        //
        // ⚠️ The address has to be one `⚡login` will accept — that form validates
        // `required|string|email`, so a bare `admin` is refused before it reaches
        // the database and could never sign in. This is a local development
        // credential and is deliberately trivial; it is not fit for an install
        // reachable from anywhere but this machine.
        //
        // `Modules\Mailbox`'s `OWNER_EMAIL` is deliberately *not* this value. That
        // one is the freelancer's mail address in seeded demo messages — the
        // address correspondence is addressed to — and the two only ever looked
        // like one thing because they used to be the same string.
        User::query()->firstOrCreate(
            ['email' => 'admin@admin.com'],
            ['name' => 'Nima Fazlipour', 'password' => 'admin'],
        );

        $this->call([
            CoreDatabaseSeeder::class,
            ProjectDatabaseSeeder::class,
            AccountingDatabaseSeeder::class,
            MailboxDatabaseSeeder::class,
            MailboxSendingSeeder::class,
            DataDatabaseSeeder::class,
            SocialDatabaseSeeder::class,
            // After Social's, not beside it: this one hangs a teaser target on
            // whichever short-form account already exists and creates none of
            // its own, so run before it the teaser is simply absent.
            BlogDatabaseSeeder::class,
        ]);
    }
}
