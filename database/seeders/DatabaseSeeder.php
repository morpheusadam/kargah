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
        User::query()->firstOrCreate(
            ['email' => 'admin@kargah.local'],
            ['name' => 'Nima Fazlipour', 'password' => 'kargah1234'],
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
