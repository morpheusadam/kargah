<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * No secret reaches the browser, from any module.
 *
 * The Data module has its own test for the vault, and it is thorough. This one
 * is deliberately different: it does not know what a credential is. It plants a
 * distinctive value in **every column in the database whose name says it holds
 * a secret**, walks every page a signed-in user can reach, and asserts none of
 * those values comes back.
 *
 * Written this way because the failure it guards against is not "somebody wrote
 * the vault badly" — that is covered. It is "somebody adds a sixth module with
 * an encrypted column and renders it without thinking", which no test scoped to
 * an existing module can catch. Four modules already hold secrets: IMAP
 * passwords, delivery-provider credentials, social tokens and the vault itself.
 *
 * A leak here is not a style complaint. Rendering a secret puts it in the
 * browser cache, in the back button, and in any proxy between the two.
 */
class NoSecretsInHtmlTest extends TestCase
{
    use RefreshDatabase;

    /** A value that cannot occur by accident and is obvious in a diff. */
    private const CANARY = 'KARGAH-CANARY-SECRET-a4f19c7b2e';

    /**
     * Columns whose *name* claims they hold something private.
     *
     * Matched by suffix rather than listed by hand, so a new module's
     * `whatever_encrypted` column is covered the day it is created rather than
     * the day somebody remembers to add it here.
     *
     * `token_hash` was added for `application_passwords`. Note it is not the
     * broader `_hash$`: `crypto_payments.tx_hash` is a blockchain transaction
     * reference, which is public by construction and is printed on the invoice
     * page on purpose. A pattern that cannot tell those two apart would fail
     * this test for doing the right thing.
     */
    private function secretColumns(): array
    {
        $found = [];

        foreach (DB::getSchemaBuilder()->getTableListing() as $table) {
            $table = str_contains($table, '.') ? explode('.', $table)[1] : $table;

            foreach (DB::getSchemaBuilder()->getColumnListing($table) as $column) {
                if (preg_match('/(_encrypted|^secret|_secret|password|_token$|token_hash$|credentials)/i', $column)) {
                    $found[] = [$table, $column];
                }
            }
        }

        return $found;
    }

    public function test_the_schema_still_has_secrets_worth_guarding(): void
    {
        // If this ever finds nothing, the test below is passing vacuously.
        $columns = $this->secretColumns();

        $this->assertNotEmpty($columns, 'No secret-bearing columns found — this guard is not guarding anything.');

        $tables = array_unique(array_column($columns, 0));

        foreach (['mail_accounts', 'credentials', 'social_accounts', 'delivery_providers', 'application_passwords'] as $expected) {
            $this->assertContains($expected, $tables, $expected.' holds a secret and is not being covered.');
        }
    }

    public function test_no_page_renders_anything_from_a_secret_column(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::query()->firstOrFail());

        // Overwrite every secret-bearing column with the canary, in the raw
        // column, bypassing casts — so the test sees whatever a page would see
        // if it read the column directly rather than through an accessor.
        // Suffixed with the row id: some of these columns are unique
        // (`campaign_recipients.unsubscribe_token` is, by design), so one
        // literal value for every row would trip the constraint rather than
        // test anything. The assertion below matches the shared prefix.
        $planted = 0;

        foreach ($this->secretColumns() as [$table, $column]) {
            $planted += DB::table($table)->update([
                $column => DB::raw("'".self::CANARY."-' || rowid"),
            ]);
        }

        $this->assertGreaterThan(0, $planted, 'Nothing was planted, so nothing is being tested.');

        $leaked = [];

        foreach ($this->reachableGetRoutes() as $uri) {
            $response = $this->get('/'.ltrim($uri, '/'));

            if ($response->getStatusCode() >= 400) {
                continue;
            }

            $body = $response->getContent();

            if (str_contains((string) $body, self::CANARY)) {
                $leaked[] = '/'.$uri;
            }
        }

        $this->assertSame(
            [],
            $leaked,
            "A secret column's contents reached the browser on:\n".implode("\n", $leaked)
            ."\n\nSecrets are revealed one at a time through a logged action, never rendered.",
        );
    }

    /**
     * Every parameterless GET route, plus the detail pages that exist in the
     * seeded database.
     *
     * @return list<string>
     */
    private function reachableGetRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (str_starts_with($uri, '_') || $uri === 'up' || str_contains($uri, 'download') || str_contains($uri, 'share')) {
                continue;
            }

            if (! str_contains($uri, '{')) {
                $uris[] = $uri;

                continue;
            }

            // One id is enough: the page either renders a secret or it does not.
            $uris[] = str_replace(
                ['{invoice}', '{client}', '{board}', '{expense}', '{campaign}', '{provider}', '{repository}', '{backup}', '{post}', '{account}', '{credential}', '{bookmark}', '{attachment}'],
                '1',
                $uri,
            );
        }

        return array_values(array_unique(array_filter($uris, fn (string $u): bool => ! str_contains($u, '{'))));
    }
}
