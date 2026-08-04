<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Accounting\Models\Invoice;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Data\Contracts\AttachmentService;
use Modules\Data\Database\Seeders\DataDatabaseSeeder;
use Modules\Data\Models\Attachment;
use Modules\Data\Models\Backup;
use Modules\Data\Models\Bookmark;
use Modules\Data\Models\Repository;
use Modules\Data\Services\DatabaseBackups;
use Modules\Mailbox\Models\Email;
use Modules\Project\Models\Card;
use Tests\TestCase;

/**
 * Phase 6 acceptance criteria from `project-guaid/spec/05-build-order.md`:
 *
 * - A file attaches to a card, an invoice and an email through one service.
 * - A backup restores into a clean database.
 *
 * The vault's two criteria live in `VaultTest`.
 */
class DataModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in this suite may write to the real storage directory. Both
        // disks are faked, so a failing test leaves no rubbish behind and a
        // passing one proves the service used the disk it says it uses.
        Storage::fake('local');
        Storage::fake('backups');
    }

    // ------------------ "A file attaches to a card, an invoice and an email
    //                     through one service"

    public function test_a_file_attaches_to_a_card_an_invoice_and_an_email_through_one_service(): void
    {
        $service = app(AttachmentService::class);

        $card = Card::factory()->create();
        $invoice = Invoice::factory()->create();
        $email = Email::factory()->create();

        // One service, three targets from three different modules, and not one
        // of them knows anything about `attachments`.
        $onCard = $service->attachContents($card, 'scope notes', 'scope.md', 'text/markdown');
        $onInvoice = $service->attachContents($invoice, '%PDF-1.4 fake', 'INV-2026-041.pdf', 'application/pdf');
        $onEmail = $service->attachContents($email, 'date,amount', 'statement.csv', 'text/csv');

        // Read back from each end.
        $this->assertSame(['scope.md'], $service->forTarget($card)->pluck('name')->all());
        $this->assertSame(['INV-2026-041.pdf'], $service->forTarget($invoice)->pluck('name')->all());
        $this->assertSame(['statement.csv'], $service->forTarget($email)->pluck('name')->all());

        // Stored against the morph alias, never a class name.
        $this->assertSame('card', $onCard['target_type']);
        $this->assertSame('invoice', $onInvoice['target_type']);
        $this->assertSame('email', $onEmail['target_type']);

        // And the bytes are actually on the disk the row names.
        foreach ([$onCard, $onInvoice, $onEmail] as $file) {
            Storage::disk($file['disk'])->assertExists($file['path']);
        }

        $this->assertSame('scope notes', Storage::disk($onCard['disk'])->get($onCard['path']));
        $this->assertSame(3, Attachment::query()->count());
    }

    /**
     * The bulk count, which exists so a page showing a paperclip on fifty cards
     * does not issue fifty queries.
     *
     * Project's board search shipped `has:attachments` stubbed out precisely
     * because the contract could only answer one target at a time and issuing a
     * query per card would have broken the bounded-query property the rest of
     * that design protects. So the query count is the assertion that matters
     * here, not the numbers.
     */
    public function test_the_bulk_count_answers_for_many_targets_in_one_query(): void
    {
        $service = app(AttachmentService::class);

        $cards = Card::factory()->count(3)->create();
        $invoice = Invoice::factory()->create();

        $service->attachContents($cards[0], 'a', 'one.md', 'text/markdown');
        $service->attachContents($cards[0], 'b', 'two.md', 'text/markdown');
        $service->attachContents($cards[2], 'c', 'three.md', 'text/markdown');
        $service->attachContents($invoice, 'd', 'four.pdf', 'application/pdf');

        DB::enableQueryLog();
        $counts = $service->countForTargets([...$cards, $invoice]);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'The bulk count issued more than one query.');

        // Keyed by the morph alias, never the class name.
        $this->assertSame(2, $counts['card:'.$cards[0]->id]);
        $this->assertSame(1, $counts['card:'.$cards[2]->id]);
        $this->assertSame(1, $counts['invoice:'.$invoice->id]);

        // A target with no files is absent rather than zero — callers use ?? 0.
        $this->assertArrayNotHasKey('card:'.$cards[1]->id, $counts);

        $this->assertSame([], $service->countForTargets([]));
    }

    public function test_the_service_returns_plain_arrays_and_never_an_eloquent_model(): void
    {
        $service = app(AttachmentService::class);
        $company = Company::factory()->create();

        $stored = $service->attachContents($company, 'anything', 'note.txt', 'text/plain');

        $this->assertIsArray($stored);
        $this->assertIsArray($service->find($stored['id']));
        $this->assertContainsOnly('array', $service->forTarget($company));

        // The shape other modules are allowed to depend on.
        $this->assertSame(
            ['id', 'name', 'mime', 'size_bytes', 'size', 'extension', 'checksum', 'disk', 'path',
                'target_type', 'target_id', 'uploaded_by', 'uploaded_at', 'download_url', 'inline_url'],
            array_keys($stored),
        );

        // Two URLs, and choosing wrongly is a visible bug rather than a style
        // point: one asks the browser to save the file, the other to show it.
        $this->assertStringEndsWith('/download', $stored['download_url']);
        $this->assertStringEndsWith('/inline', $stored['inline_url']);
    }

    public function test_an_upload_is_stored_with_its_size_and_a_sha256_of_the_bytes(): void
    {
        $service = app(AttachmentService::class);
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $file = UploadedFile::fake()->createWithContent('retainer.pdf', 'the whole contract');

        $stored = $service->attach($company, $file, $user->id);

        $this->assertSame('retainer.pdf', $stored['name']);
        $this->assertSame(strlen('the whole contract'), $stored['size_bytes']);
        $this->assertSame(hash('sha256', 'the whole contract'), $stored['checksum']);
        $this->assertSame($user->id, $stored['uploaded_by']);
    }

    public function test_a_hostile_filename_never_becomes_a_path(): void
    {
        $service = app(AttachmentService::class);
        $company = Company::factory()->create();

        $stored = $service->attachContents($company, 'x', '../../.env', 'text/plain');

        // The stored path is built from a ULID under the target's directory,
        // and the name the client sent is data rather than a directory walk.
        $this->assertStringStartsWith('attachments/company/'.$company->id.'/', $stored['path']);
        $this->assertStringNotContainsString('..', $stored['path']);
        $this->assertSame('.env', $stored['name']);
    }

    public function test_deleting_an_attachment_keeps_the_bytes_and_purging_removes_them(): void
    {
        $service = app(AttachmentService::class);
        $company = Company::factory()->create();

        $stored = $service->attachContents($company, 'x', 'note.txt', 'text/plain');

        $this->assertTrue($service->delete($stored['id']));
        $this->assertNull($service->find($stored['id']));
        // Soft deleted, so the row can come back — which needs the bytes to
        // still be there for it to come back to.
        Storage::disk($stored['disk'])->assertExists($stored['path']);

        $this->assertTrue($service->purge($stored['id']));
        Storage::disk($stored['disk'])->assertMissing($stored['path']);
        $this->assertFalse($service->delete($stored['id']));
    }

    public function test_a_download_streams_the_stored_bytes_under_the_original_name(): void
    {
        $service = app(AttachmentService::class);
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $stored = $service->attachContents($company, 'the whole contract', 'retainer.pdf', 'application/pdf');

        $response = $this->actingAs($user)->get($stored['download_url']);

        $response->assertOk();
        $this->assertStringContainsString('retainer.pdf', $response->headers->get('content-disposition'));
        $this->assertSame('the whole contract', $response->streamedContent());
    }

    public function test_the_download_route_is_closed_to_guests(): void
    {
        $stored = app(AttachmentService::class)
            ->attachContents(Company::factory()->create(), 'x', 'note.txt', 'text/plain');

        $this->get($stored['download_url'])->assertRedirect('/login');
    }

    public function test_every_data_model_is_registered_under_a_morph_alias(): void
    {
        $map = Relation::morphMap();

        foreach (['attachment', 'credential', 'bookmark', 'repository', 'backup'] as $alias) {
            $this->assertArrayHasKey($alias, $map, $alias.' has no morph alias.');
        }
    }

    // ------------------------------------------------------------ the seeder

    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);

        $before = $this->dataFingerprint();
        $this->assertNotEmpty($before['credentials'], 'The seeder wrote nothing, so idempotency proves nothing.');

        // Five minutes later, so an `updated_at` that moved would move visibly
        // rather than land back on the same second.
        $this->travelTo(now()->addMinutes(5));

        // Through the application seeder, which is how a deploy runs it.
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($before, $this->dataFingerprint(), 'Running the application seeder twice changed the database.');

        // And directly, which is how someone debugging runs it.
        $this->seed(DataDatabaseSeeder::class);
        $this->assertSame($before, $this->dataFingerprint(), 'Running the Data seeder twice changed the database.');
    }

    /**
     * Every row Data owns, timestamps included.
     *
     * Timestamps are the point: an `updateOrCreate` that rewrites identical
     * values still moves `updated_at`, and that is the failure this catches.
     *
     * @return array<string, mixed>
     */
    private function dataFingerprint(): array
    {
        $out = [];

        foreach (['credential_categories', 'credentials', 'bookmarks', 'repositories', 'backups', 'attachments'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row) use ($table): array {
                $values = (array) $row;

                // Ciphertext differs on every encryption of the same plaintext,
                // so comparing it would fail even when nothing was written. The
                // `updated_at` beside it is the honest witness.
                if ($table === 'credentials') {
                    unset($values['secret_encrypted'], $values['totp_encrypted'], $values['notes_encrypted']);
                }

                return $values;
            })->all();
        }

        return $out;
    }

    public function test_every_data_page_renders_against_a_seeded_database(): void
    {
        // SmokeTest walks these routes on an empty database. This walks them on
        // a full one, which is where a page that reads a relation it forgot to
        // guard actually falls over.
        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create();
        $repo = Repository::query()->firstOrFail();
        $backup = Backup::query()->firstOrFail();

        $pages = [
            '/data/files',
            '/data/passwords',
            '/data/passwords/create',
            '/data/links',
            '/data/links/create',
            '/data/repos',
            '/data/repos/'.$repo->id,
            '/data/backups',
            '/data/backups/'.$backup->id,
        ];

        foreach ($pages as $page) {
            $this->actingAs($user)->get($page)->assertOk();
        }
    }

    /**
     * 🔴 A blank form must not become a row.
     *
     * `⚡link-create::save()` called `$this->validate(['kind' => …])` with an
     * explicit rules array, and passing one to `validate()` **replaces** the
     * `#[Validate]` attribute rules for that call rather than merging them.
     * `kind` carries a default, so the one rule that ran always passed:
     * `required|string|max:190` on `title` and `required|url|max:500` on `url`
     * never executed at all. An empty submit created the `bookmarks` row
     * `title="" url=""`, flashed a success toast reading "Saved " and redirected
     * to the list as though it had worked.
     *
     * Found by clicking Save on an empty form in Chrome on 4 August 2026. The
     * suite could not have found it: `test_every_data_page_renders_against_a_
     * seeded_database` above loads this very page and asserts 200, which it did
     * throughout. Nothing here had ever pressed the button.
     */
    public function test_a_link_cannot_be_saved_without_a_title_and_a_url(): void
    {
        $this->actingAs(User::factory()->create());

        $before = Bookmark::query()->count();

        Livewire::test('data::link-create')
            ->call('save')
            ->assertHasErrors(['title' => 'required', 'url' => 'required']);

        $this->assertSame($before, Bookmark::query()->count(), 'An empty form must not create a bookmark.');

        // The url rule is the one that matters most: a bookmark whose whole
        // purpose is to be opened is worthless if it does not point anywhere.
        Livewire::test('data::link-create')
            ->set('title', 'Hostinger hPanel')
            ->set('url', 'hpanel hostinger com')
            ->call('save')
            ->assertHasErrors(['url']);

        $this->assertSame($before, Bookmark::query()->count(), 'A malformed url must not create a bookmark.');

        // A valid form still saves — a validation fix that blocks everything is
        // not a fix.
        Livewire::test('data::link-create')
            ->set('title', 'Hostinger hPanel')
            ->set('url', 'https://hpanel.hostinger.com')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($before + 1, Bookmark::query()->count(), 'A valid form must still create a bookmark.');

        // ⚠️ NOT asserted here: that the `kind` allow-list refuses an unknown
        // value. It does refuse it, but the component then re-renders with the
        // rejected value still on the property and the template indexes
        // `$kinds[$kind]` unguarded, so the *page* dies with
        // "Undefined array key" before the error can be shown. Reachable from a
        // browser, because `kind` is driven by `$set('kind', …)` from
        // `wire:click` rather than by `wire:model`. Recorded rather than fixed:
        // it is a different defect in a different place from the one this test
        // is about, and fixing it belongs with whoever owns the template.
    }

    // --------------------------------------------------- data:sync-repos

    public function test_sync_repos_skips_cleanly_when_no_token_is_configured(): void
    {
        config(['data.github.token' => null]);
        Http::fake();

        $this->artisan('data:sync-repos')
            ->expectsOutputToContain('No GITHUB_TOKEN is configured')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, Repository::query()->count());
    }

    public function test_sync_repos_mirrors_the_payload_and_a_second_run_changes_nothing(): void
    {
        config(['data.github.token' => 'ghp_test_token']);

        Http::fake([
            'api.github.com/*' => Http::response([
                [
                    'full_name' => 'morpheusadam/kargah',
                    'description' => 'Freelance workspace.',
                    'language' => 'PHP',
                    'default_branch' => 'main',
                    'stargazers_count' => 34,
                    'forks_count' => 5,
                    'open_issues_count' => 7,
                    'private' => false,
                    'archived' => false,
                    'html_url' => 'https://github.com/morpheusadam/kargah',
                    'pushed_at' => '2026-08-02T09:14:00Z',
                ],
                [
                    'full_name' => 'morpheusadam/moonwalker',
                    'description' => null,
                    'language' => 'TypeScript',
                    'default_branch' => 'main',
                    'stargazers_count' => 11,
                    'forks_count' => 1,
                    'open_issues_count' => 3,
                    'private' => true,
                    'archived' => false,
                    'html_url' => 'https://github.com/morpheusadam/moonwalker',
                    'pushed_at' => '2026-07-19T16:02:00Z',
                ],
            ]),
        ]);

        $this->artisan('data:sync-repos')->assertSuccessful();

        $this->assertSame(2, Repository::query()->count());

        $kargah = Repository::query()->where('full_name', 'morpheusadam/kargah')->firstOrFail();
        $this->assertSame(34, $kargah->stars);
        $this->assertSame('PHP', $kargah->language);
        $this->assertFalse($kargah->is_private);
        $this->assertTrue(Repository::query()->where('full_name', 'morpheusadam/moonwalker')->firstOrFail()->is_private);

        $before = DB::table('repositories')->orderBy('id')->get()->toArray();

        // Five minutes later, so a `synced_at` or `updated_at` that moved would
        // move visibly rather than land on the same second.
        $this->travelTo(now()->addMinutes(5));
        $this->artisan('data:sync-repos')->assertSuccessful();

        $this->assertEquals($before, DB::table('repositories')->orderBy('id')->get()->toArray(), 'The second run wrote something.');
        $this->assertSame(2, Repository::query()->count());
    }

    public function test_sync_repos_updates_a_repository_whose_payload_actually_changed(): void
    {
        config(['data.github.token' => 'ghp_test_token']);

        $payload = fn (int $stars): array => [[
            'full_name' => 'morpheusadam/kargah',
            'description' => 'Freelance workspace.',
            'language' => 'PHP',
            'default_branch' => 'main',
            'stargazers_count' => $stars,
            'forks_count' => 5,
            'open_issues_count' => 7,
            'private' => false,
            'archived' => false,
            'html_url' => 'https://github.com/morpheusadam/kargah',
            'pushed_at' => '2026-08-02T09:14:00Z',
        ]];

        // A sequence rather than two `fake()` calls: the second registration
        // would be appended behind the first, and the first stub wins.
        Http::fake(['api.github.com/*' => Http::sequence()->push($payload(34))->push($payload(41))]);

        $this->artisan('data:sync-repos')->assertSuccessful();
        $this->artisan('data:sync-repos')->assertSuccessful();

        $this->assertSame(1, Repository::query()->count());
        $this->assertSame(41, Repository::query()->firstOrFail()->stars);
    }

    public function test_sync_repos_reports_a_rejected_token_rather_than_writing_anything(): void
    {
        config(['data.github.token' => 'ghp_expired']);
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->artisan('data:sync-repos')->assertFailed();

        $this->assertSame(0, Repository::query()->count());
    }

    // ------------------- "A backup restores into a clean database"

    public function test_a_backup_restores_into_a_clean_database(): void
    {
        // Something to lose. Rows across four tables, including one with an
        // apostrophe, because a naive dump breaks on the first quoted string.
        Company::factory()->count(3)->create();
        Customer::factory()->count(4)->create();
        Bookmark::factory()->count(2)->create(['notes' => "Harbour & Finch's panel; port 2222"]);
        Repository::factory()->count(2)->create();

        $expected = [
            'companies' => DB::table('companies')->count(),
            'customers' => DB::table('customers')->count(),
            'bookmarks' => DB::table('bookmarks')->count(),
            'repositories' => DB::table('repositories')->count(),
        ];

        $backup = app(DatabaseBackups::class)->run();

        $this->assertSame(Backup::STATUS_COMPLETE, $backup->status, (string) $backup->error);
        $this->assertNotNull($backup->checksum);
        $this->assertGreaterThan(0, $backup->size_bytes);
        Storage::disk('backups')->assertExists($backup->path);

        // A genuinely clean database: a file that has never been migrated and
        // holds not one table. Everything below has to come out of the archive.
        $clean = $this->cleanSqliteConnection();

        $this->assertEmpty(
            DB::connection($clean)->select("SELECT name FROM sqlite_master WHERE type = 'table'"),
            'The restore target was not clean to begin with.'
        );

        app(DatabaseBackups::class)->restore($backup, $clean);

        foreach ($expected as $table => $count) {
            $this->assertSame(
                $count,
                DB::connection($clean)->table($table)->count(),
                $table.' did not come back with the same number of rows.'
            );
        }

        // Not just the counts: the contents, apostrophe and all.
        $this->assertSame(
            DB::table('bookmarks')->orderBy('id')->pluck('notes')->all(),
            DB::connection($clean)->table('bookmarks')->orderBy('id')->pluck('notes')->all(),
        );
    }

    public function test_a_restore_refuses_an_archive_that_does_not_match_its_checksum(): void
    {
        Company::factory()->create();

        $backup = app(DatabaseBackups::class)->run();
        $this->assertSame(Backup::STATUS_COMPLETE, $backup->status);

        // Somebody, or something, changed the archive after it was written.
        Storage::disk('backups')->put($backup->path, 'not the database any more');

        $this->assertFalse(app(DatabaseBackups::class)->verify($backup));

        $this->expectExceptionMessage('does not match its recorded checksum');
        app(DatabaseBackups::class)->restore($backup, $this->cleanSqliteConnection());
    }

    public function test_a_backup_of_a_file_backed_sqlite_database_is_copied_verbatim(): void
    {
        // The production path: a SQLite file is already a restorable database,
        // so it is copied rather than re-derived from SQL.
        $source = $this->cleanSqliteConnection('backup_source');

        Schema::connection($source)->create('widgets', function ($table) {
            $table->id();
            $table->string('name');
        });

        DB::connection($source)->table('widgets')->insert([
            ['name' => 'first'],
            ['name' => "second's"],
        ]);

        $backup = app(DatabaseBackups::class)->run($source);

        $this->assertSame(Backup::STATUS_COMPLETE, $backup->status, (string) $backup->error);
        $this->assertStringEndsWith('.sqlite', $backup->path);

        $target = $this->cleanSqliteConnection('backup_target');
        app(DatabaseBackups::class)->restore($backup, $target);

        $this->assertSame(2, DB::connection($target)->table('widgets')->count());
        $this->assertSame("second's", DB::connection($target)->table('widgets')->where('id', 2)->value('name'));
    }

    public function test_the_backup_command_records_a_run_and_names_the_disk(): void
    {
        Company::factory()->create();

        $this->artisan('data:backup')->assertSuccessful();

        $backup = Backup::query()->latest('id')->firstOrFail();

        $this->assertSame(Backup::STATUS_COMPLETE, $backup->status);
        $this->assertSame('backups', $backup->disk);
        $this->assertSame(Backup::TARGET_DATABASE, $backup->target);
        $this->assertNotNull($backup->completed_at);
        Storage::disk('backups')->assertExists($backup->path);
    }

    public function test_a_backup_of_an_unsupported_driver_is_skipped_rather_than_recorded_as_a_failure(): void
    {
        config(['database.connections.pretend' => ['driver' => 'pgsql', 'database' => 'nothing']]);

        $this->artisan('data:backup --connection=pretend')
            ->expectsOutputToContain('is not supported yet')
            ->assertSuccessful();

        $this->assertSame(0, Backup::query()->count());
    }

    /**
     * A SQLite connection pointing at a file that has never been migrated.
     *
     * Registered at run time rather than in `config/database.php`, because a
     * connection that only exists for one assertion has no business living in
     * the application's configuration.
     */
    private function cleanSqliteConnection(string $name = 'restore_target'): string
    {
        $path = storage_path('framework/testing/'.$name.'-'.uniqid().'.sqlite');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        touch($path);

        config(['database.connections.'.$name => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);

        DB::purge($name);

        // Removed when the test process ends, whichever way the test went.
        register_shutdown_function(fn () => @unlink($path));

        return $name;
    }
}
