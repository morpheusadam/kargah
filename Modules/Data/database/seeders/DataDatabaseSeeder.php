<?php

namespace Modules\Data\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Modules\Core\Models\Company;
use Modules\Data\Contracts\AttachmentService;
use Modules\Data\Models\Attachment;
use Modules\Data\Models\Backup;
use Modules\Data\Models\Bookmark;
use Modules\Data\Models\Credential;
use Modules\Data\Models\CredentialCategory;
use Modules\Data\Models\Repository;

/**
 * The vault, the links, the mirrored repositories and the backup history.
 *
 * Idempotent, like every seeder here: this runs from the deploy script, and a
 * deploy that duplicates the client list — or worse, the password list — is a
 * bad afternoon. Every write is keyed on something a person would recognise: a
 * credential's name, a bookmark's URL, a repository's `provider/full_name`, the
 * instant a backup started.
 *
 * Credentials need one guard the other tables do not. Encrypting the same
 * plaintext twice produces different ciphertext, so `updateOrCreate` with a
 * secret in the update array would rewrite every row on every run and leave
 * `updated_at` marching forward for no reason. The secret is therefore compared
 * *decrypted* and written only when it genuinely differs.
 *
 * The secrets below are obviously fake and are meant to be. This seeder fills a
 * demonstration database; a real vault entry arrives through the form.
 */
class DataDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();

        $categories = $this->seedCategories();

        $this->seedCredentials($categories, $user);
        $this->seedBookmarks($user);
        $this->seedRepositories();
        $this->seedBackups();
        $this->seedAttachments($user);
    }

    /** @return array<string, CredentialCategory> */
    private function seedCategories(): array
    {
        $categories = [
            'Hosting' => 'primary',
            'Email' => 'info',
            'Development' => 'success',
            'Banking' => 'warning',
            'Domains' => 'neutral',
        ];

        $out = [];
        $position = 0;

        foreach ($categories as $name => $colour) {
            $out[$name] = CredentialCategory::query()->updateOrCreate(
                ['name' => $name],
                ['colour' => $colour, 'position' => $position++],
            );
        }

        return $out;
    }

    /** @param  array<string, CredentialCategory>  $categories */
    private function seedCredentials(array $categories, ?User $user): void
    {
        $entries = [
            [
                'name' => 'Hostinger hPanel',
                'username' => 'morph',
                'secret' => 'demo-hpanel-9Fq2Kd4Wm7',
                'url' => 'https://hpanel.hostinger.com',
                'category' => 'Hosting',
                'totp' => null,
                'notes' => 'Shared plan. The SSH port is not the default; it is on the hosting overview page.',
            ],
            [
                'name' => 'Brevo API',
                'username' => 'api-key',
                'secret' => 'demo-brevo-4Tz8Xr1Nb6',
                'url' => 'https://app.brevo.com',
                'category' => 'Email',
                'totp' => null,
                'notes' => 'Transactional key only. The campaign key is a separate entry on the same account.',
            ],
            [
                'name' => 'GitHub personal token',
                'username' => 'morpheusadam',
                'secret' => 'demo-github-6Hs3Pv9Lq2',
                'url' => 'https://github.com/settings/tokens',
                'category' => 'Development',
                // A valid base32 seed, so one entry in the vault actually shows
                // a rolling code rather than an empty slot.
                'totp' => 'JBSWY3DPEHPK3PXP',
                'notes' => 'Scoped to repo and read:org. Expires yearly — the renewal is in the calendar.',
            ],
            [
                'name' => 'Namecheap',
                'username' => 'nima@kargah.test',
                'secret' => 'demo-namecheap-2Bd7Yj5Rk8',
                'url' => 'https://ap.www.namecheap.com',
                'category' => 'Domains',
                'totp' => null,
                'notes' => null,
            ],
            [
                'name' => 'Business current account',
                'username' => '4471-0092',
                'secret' => 'demo-bank-8Nc4Wq6Ft3',
                'url' => 'https://bank.example',
                'category' => 'Banking',
                'totp' => null,
                'notes' => 'The card reader lives in the top drawer. Transfers over ten thousand need the second signatory.',
            ],
        ];

        foreach ($entries as $entry) {
            $credential = Credential::query()->firstOrNew(['name' => $entry['name']]);

            $credential->fill([
                'username' => $entry['username'],
                'url' => $entry['url'],
                'category_id' => $categories[$entry['category']]->id,
                'company_id' => null,
                'created_by' => $user?->id,
            ]);

            // Compare the plaintext, never the ciphertext: two encryptions of
            // the same value never match, so comparing stored bytes would mark
            // every row dirty on every run.
            foreach (['secret', 'totp', 'notes'] as $field) {
                if ($credential->{$field} !== $entry[$field]) {
                    $credential->{$field} = $entry[$field];
                }
            }

            // A model with nothing dirty issues no query and leaves `updated_at`
            // alone, which is what makes the second run a genuine no-op.
            $credential->save();
        }
    }

    private function seedBookmarks(?User $user): void
    {
        $bookmarks = [
            [
                'title' => 'Kargah production',
                'url' => 'https://kargah.dev',
                'kind' => Bookmark::KIND_DEPLOYED_PROJECT,
                'notes' => 'Deploys from main. The release script runs the migrations before swapping the symlink.',
                'tags' => ['laravel', 'production'],
            ],
            [
                'title' => 'Kargah staging',
                'url' => 'https://staging.kargah.dev',
                'kind' => Bookmark::KIND_DEPLOYED_PROJECT,
                'notes' => 'Same schema, seeded data. Safe to break.',
                'tags' => ['laravel', 'staging'],
            ],
            [
                'title' => 'Northwind invoices bot',
                'url' => 'https://t.me/northwind_invoices_bot',
                'kind' => Bookmark::KIND_TELEGRAM_BOT,
                'notes' => 'Posts a message when an invoice is issued. The token lives in the vault, not here.',
                'tags' => ['telegram', 'client'],
            ],
            [
                'title' => 'Kargah deploy notifier',
                'url' => 'https://t.me/kargah_deploy_bot',
                'kind' => Bookmark::KIND_TELEGRAM_BOT,
                'notes' => 'Announces a finished deployment and the commit it shipped.',
                'tags' => ['telegram', 'tool'],
            ],
            [
                'title' => 'Hostinger hPanel',
                'url' => 'https://hpanel.hostinger.com',
                'kind' => Bookmark::KIND_TOOL,
                'notes' => 'Hosting, DNS and the cron entry that runs the scheduler.',
                'tags' => ['hosting'],
            ],
            [
                'title' => 'Livewire 4 documentation',
                'url' => 'https://livewire.laravel.com/docs',
                'kind' => Bookmark::KIND_REFERENCE,
                'notes' => 'The islands chapter is the one worth re-reading before touching the board.',
                'tags' => ['docs', 'laravel'],
            ],
            [
                'title' => 'Keenicons index',
                'url' => 'https://keenthemes.com/metronic/tailwind/docs/icons/keenicons',
                'kind' => Bookmark::KIND_REFERENCE,
                'notes' => 'Check a name here before using it — a missing icon renders as nothing at all.',
                'tags' => ['docs', 'tool'],
            ],
        ];

        foreach ($bookmarks as $bookmark) {
            Bookmark::query()->updateOrCreate(
                ['url' => $bookmark['url']],
                [
                    'title' => $bookmark['title'],
                    'kind' => $bookmark['kind'],
                    'notes' => $bookmark['notes'],
                    'tags' => $bookmark['tags'],
                    'company_id' => null,
                    'created_by' => $user?->id,
                ],
            );
        }
    }

    private function seedRepositories(): void
    {
        $repositories = [
            [
                'full_name' => 'morpheusadam/kargah',
                'description' => 'Freelance workspace: boards, mail, accounting, data.',
                'language' => 'PHP',
                'stars' => 34,
                'forks' => 5,
                'open_issues' => 7,
                'pushed' => 0,
            ],
            [
                'full_name' => 'morpheusadam/moonwalker',
                'description' => 'Floating panel tooling for long-running assistant sessions.',
                'language' => 'TypeScript',
                'stars' => 11,
                'forks' => 1,
                'open_issues' => 3,
                'pushed' => 14,
            ],
            [
                'full_name' => 'morpheusadam/invoice-pdf',
                'description' => 'The A4 invoice template Kargah renders from.',
                'language' => 'PHP',
                'stars' => 2,
                'forks' => 0,
                'open_issues' => 0,
                'pushed' => 63,
            ],
        ];

        foreach ($repositories as $repository) {
            Repository::query()->updateOrCreate(
                ['provider' => 'github', 'full_name' => $repository['full_name']],
                [
                    'description' => $repository['description'],
                    'language' => $repository['language'],
                    'default_branch' => 'main',
                    'stars' => $repository['stars'],
                    'forks' => $repository['forks'],
                    'open_issues' => $repository['open_issues'],
                    'is_private' => false,
                    'is_archived' => false,
                    'html_url' => 'https://github.com/'.$repository['full_name'],
                    // Anchored to midnight, so a second run writes the same
                    // value rather than the same day at a different time.
                    'pushed_at' => now()->startOfDay()->subDays($repository['pushed']),
                    'synced_at' => now()->startOfDay(),
                ],
            );
        }
    }

    private function seedBackups(): void
    {
        // Three nights of history: a run that worked, an older one, and the
        // failure, which is the only genuinely interesting row on the page.
        $runs = [
            ['days' => 0, 'size' => 1_486_848, 'status' => Backup::STATUS_COMPLETE, 'error' => null],
            ['days' => 1, 'size' => 1_481_728, 'status' => Backup::STATUS_COMPLETE, 'error' => null],
            ['days' => 2, 'size' => null, 'status' => Backup::STATUS_FAILED, 'error' => 'The backups disk was full. Nothing was written.'],
        ];

        foreach ($runs as $run) {
            $started = now()->startOfDay()->subDays($run['days'])->setTime(3, 0);
            $path = $run['status'] === Backup::STATUS_COMPLETE
                ? 'kargah-'.$started->format('Y-m-d-His').'.sqlite'
                : null;

            Backup::query()->updateOrCreate(
                ['started_at' => $started],
                [
                    'target' => Backup::TARGET_DATABASE,
                    'disk' => (string) config('data.backups.disk', 'backups'),
                    'path' => $path,
                    'size_bytes' => $run['size'],
                    'checksum' => $path === null ? null : hash('sha256', $path),
                    'status' => $run['status'],
                    'error' => $run['error'],
                    'completed_at' => $started->copy()->addSeconds(38),
                ],
            );
        }
    }

    /**
     * A few files, attached the only way files are ever attached.
     *
     * Through the service, not through `Attachment::create()`: the seeder is
     * held to the same rule as every other caller, which is also the cheapest
     * possible check that the rule is workable.
     */
    private function seedAttachments(?User $user): void
    {
        $company = Company::query()->orderBy('id')->first();

        if ($company === null) {
            return;
        }

        $files = [
            [
                'name' => 'northwind-retainer-2026.pdf',
                'mime' => 'application/pdf',
                'body' => "Retainer agreement — Northwind Ltd.\nTwelve months from 1 September 2026, four days a month, invoiced on the first.\n",
            ],
            [
                'name' => 'rate-card-2026.pdf',
                'mime' => 'application/pdf',
                'body' => "Day rate, retainer rate and the out-of-hours multiplier for 2026.\n",
            ],
            [
                'name' => 'expenses-q2.csv',
                'mime' => 'text/csv',
                'body' => "date,vendor,category,amount\n2026-04-03,Hostinger,Hosting,119.40\n2026-05-11,Figma,Software,45.00\n",
            ],
        ];

        $service = app(AttachmentService::class);

        foreach ($files as $file) {
            // Keyed on the target and the name a person would recognise. Without
            // this a second run would write the same bytes to a fresh ULID path
            // and leave two rows where there should be one.
            $exists = Attachment::query()
                ->forTarget($company->getMorphClass(), (int) $company->getKey())
                ->where('original_name', $file['name'])
                ->exists();

            if ($exists) {
                continue;
            }

            $service->attachContents(
                target: $company,
                contents: $file['body'],
                originalName: $file['name'],
                mime: $file['mime'],
                uploadedBy: $user?->id,
            );
        }
    }
}
