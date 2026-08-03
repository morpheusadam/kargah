<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Models\Email;
use Modules\Platform\Services\ApplicationPasswordIssuer;
use Modules\Platform\Support\Scopes;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The HTTP API, from `project-guaid/spec/07-platform.md`.
 *
 * Covers everything built on top of the application-password layer
 * `ApplicationPasswordTest` already proves: customers (Core), invoices and
 * expenses (Accounting), a customer's emails (Mailbox). Boards, lists, cards
 * and the vault are not here — see the report for why.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function issuer(): ApplicationPasswordIssuer
    {
        return app(ApplicationPasswordIssuer::class);
    }

    /** @param  list<string>  $scopes */
    private function secretFor(User $user, array $scopes, string $name = 'API test credential'): string
    {
        return $this->issuer()->issue($user, $name, $scopes, null, $user)['secret'];
    }

    /** @return array<string, string> */
    private function basic(string $email, string $secret): array
    {
        return ['Authorization' => 'Basic '.base64_encode($email.':'.$secret)];
    }

    /* Every endpoint declares the scope it needs -------------------------------- */

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function endpointProvider(): array
    {
        return [
            'customers index' => ['GET', '/api/v1/customers', Scopes::CORE_READ],
            'customers show' => ['GET', '/api/v1/customers/1', Scopes::CORE_READ],
            'customer emails' => ['GET', '/api/v1/customers/1/emails', Scopes::MAILBOX_READ],
            'invoices index' => ['GET', '/api/v1/invoices', Scopes::ACCOUNTING_READ],
            'invoices show' => ['GET', '/api/v1/invoices/1', Scopes::ACCOUNTING_READ],
            'invoice issue' => ['POST', '/api/v1/invoices/1/issue', Scopes::ACCOUNTING_WRITE],
            'expenses index' => ['GET', '/api/v1/expenses', Scopes::ACCOUNTING_READ],
            'expenses show' => ['GET', '/api/v1/expenses/1', Scopes::ACCOUNTING_READ],
        ];
    }

    #[DataProvider('endpointProvider')]
    public function test_every_endpoint_is_unreachable_without_a_credential(string $method, string $uri, string $scope): void
    {
        $response = $this->json($method, $uri);

        $response->assertStatus(401);
        $this->assertSame('Basic realm="Kargah"', $response->headers->get('WWW-Authenticate'));
        $this->assertArrayHasKey('message', $response->json());
    }

    #[DataProvider('endpointProvider')]
    public function test_every_endpoint_is_unreachable_without_its_scope(string $method, string $uri, string $scope): void
    {
        $user = User::factory()->create();

        // Every scope except the one this endpoint asks for.
        $granted = array_values(array_diff(Scopes::all(), [$scope]));
        $secret = $this->secretFor($user, $granted);

        $response = $this->withHeaders($this->basic($user->email, $secret))->json($method, $uri);

        $response->assertStatus(403);
        $response->assertJsonPath('required.0', $scope);
        $this->assertArrayHasKey('message', $response->json());
    }

    public function test_a_plain_unauthenticated_request_never_redirects_to_a_login_page(): void
    {
        // No Accept header, no app password — the shape of a curl call, not a
        // browser's. It must answer 401, never send a browser to `/login`.
        $response = $this->get('/api/v1/invoices');

        $response->assertStatus(401);
        $this->assertNull($response->headers->get('Location'));
    }

    /* Revoked and expired credentials, on a real endpoint, not only whoami ------ */

    public function test_a_revoked_credential_is_refused_on_every_endpoint(): void
    {
        $user = User::factory()->create();
        ['credential' => $credential, 'secret' => $secret] = $this->issuer()->issue(
            $user, 'Revoked', [Scopes::ACCOUNTING_READ], null, $user,
        );

        $this->issuer()->revoke($credential, $user);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/invoices')
            ->assertStatus(401);
    }

    public function test_an_expired_credential_is_refused_on_every_endpoint(): void
    {
        $user = User::factory()->create();
        ['credential' => $credential, 'secret' => $secret] = $this->issuer()->issue(
            $user, 'Expired', [Scopes::ACCOUNTING_READ], null, $user,
        );

        $credential->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/invoices')
            ->assertStatus(401);
    }

    /* Customers, through Core\Contracts\CustomerReader --------------------------- */

    public function test_customers_index_and_show_read_through_the_contract(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['name' => 'Ada Lovelace']);
        $secret = $this->secretFor($user, [Scopes::CORE_READ]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/customers?q=Ada')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Ada Lovelace']);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Ada Lovelace')
            ->assertJsonPath('data.id', $customer->id);
    }

    public function test_a_missing_customer_answers_404_in_the_same_envelope_as_every_other_error(): void
    {
        $user = User::factory()->create();
        $secret = $this->secretFor($user, [Scopes::CORE_READ]);

        $response = $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/customers/999999');

        $response->assertStatus(404);
        $response->assertJson(['code' => 'not_found']);
        $this->assertArrayHasKey('message', $response->json());
    }

    /* A customer's emails, through Mailbox\Contracts\EmailReader ---------------- */

    public function test_customer_emails_read_through_the_contract(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        Email::factory()->count(3)->create(['customer_id' => $customer->id]);

        $secret = $this->secretFor($user, [Scopes::MAILBOX_READ]);

        $response = $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/customers/'.$customer->id.'/emails');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
        $this->assertSame(3, $response->json('total'));
    }

    public function test_emails_for_an_unknown_customer_answer_404(): void
    {
        $user = User::factory()->create();
        $secret = $this->secretFor($user, [Scopes::MAILBOX_READ]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/customers/999999/emails')
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found']);
    }

    /* Money is a string, never a number ------------------------------------------ */

    public function test_money_is_a_string_in_the_raw_response_body(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create([
            'subtotal' => '1500.000000',
            'tax_amount' => '0.000000',
            'total' => '1500.000000',
        ]);
        $secret = $this->secretFor($user, [Scopes::ACCOUNTING_READ]);

        $response = $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/invoices/'.$invoice->id);

        $response->assertOk();

        // Against the raw body, deliberately: a decoded array cannot tell a
        // JSON string from a JSON number apart, and this is the one thing a
        // float regression would look identical either way.
        $this->assertStringContainsString('"amount":"1500.000000"', $response->getContent());
        $this->assertStringContainsString('"currency":"USD"', $response->getContent());
        $this->assertStringContainsString('"formatted":"$1,500.00"', $response->getContent());
    }

    /* Cursor pagination ----------------------------------------------------------- */

    public function test_the_cursor_pages_forward_with_no_overlap_and_no_gap(): void
    {
        $user = User::factory()->create();
        Invoice::factory()->count(25)->create();
        $secret = $this->secretFor($user, [Scopes::ACCOUNTING_READ]);
        $headers = $this->basic($user->email, $secret);

        $first = $this->withHeaders($headers)->getJson('/api/v1/invoices?per_page=10');
        $first->assertOk();

        $firstIds = collect($first->json('data'))->pluck('id')->all();
        $this->assertCount(10, $firstIds);

        $cursor = $first->json('cursor.next');
        $this->assertNotNull($cursor, 'A page with more rows behind it must carry a cursor to reach them.');

        $second = $this->withHeaders($headers)->getJson('/api/v1/invoices?per_page=10&cursor='.urlencode($cursor));
        $second->assertOk();

        $secondIds = collect($second->json('data'))->pluck('id')->all();
        $this->assertCount(10, $secondIds);

        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'The second page repeated a row from the first.');

        // Ordered newest-first by id: the lowest id on the first page and the
        // highest id on the second must be consecutive, or a row was skipped.
        $this->assertSame(min($firstIds) - 1, max($secondIds), 'A row was skipped between the two pages.');
    }

    public function test_an_invalid_status_filter_answers_422_in_the_same_envelope_as_every_other_error(): void
    {
        $user = User::factory()->create();
        $secret = $this->secretFor($user, [Scopes::ACCOUNTING_READ]);

        $response = $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/invoices?status=bogus');

        $response->assertStatus(422);
        $response->assertJson(['code' => 'validation_failed']);
        $this->assertArrayHasKey('errors', $response->json());
        $this->assertArrayHasKey('message', $response->json());
    }

    /* Issuing an invoice: an explicit action, its own scope ---------------------- */

    public function test_issuing_an_invoice_requires_accounting_write_and_a_read_scoped_token_cannot(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();
        InvoiceLine::factory()->count(2)->forInvoice($invoice)->create();

        $readSecret = $this->secretFor($user, [Scopes::ACCOUNTING_READ], 'Reader');

        $this->withHeaders($this->basic($user->email, $readSecret))
            ->postJson("/api/v1/invoices/{$invoice->id}/issue")
            ->assertStatus(403)
            ->assertJsonPath('required.0', Scopes::ACCOUNTING_WRITE);

        $this->assertFalse($invoice->fresh()->isIssued());

        $writeSecret = $this->secretFor($user, [Scopes::ACCOUNTING_WRITE, Scopes::ACCOUNTING_READ], 'Writer');

        $response = $this->withHeaders($this->basic($user->email, $writeSecret))
            ->postJson("/api/v1/invoices/{$invoice->id}/issue");

        $response->assertOk();
        $this->assertTrue($response->json('data.is_issued'));
        $this->assertTrue($invoice->fresh()->isIssued());
    }

    public function test_issuing_an_already_issued_invoice_through_the_api_does_not_refreeze_it(): void
    {
        $user = User::factory()->create();
        $invoice = Invoice::factory()->create();
        InvoiceLine::factory()->count(1)->forInvoice($invoice)->create();

        $secret = $this->secretFor($user, [Scopes::ACCOUNTING_WRITE, Scopes::ACCOUNTING_READ]);
        $headers = $this->basic($user->email, $secret);

        $first = $this->withHeaders($headers)->postJson("/api/v1/invoices/{$invoice->id}/issue");
        $first->assertOk();

        $this->travel(1)->day();

        // Second run. Same rule as the revocation, the recurring-invoice
        // generator and every other write in this project: it must not move
        // anything a second time.
        $second = $this->withHeaders($headers)->postJson("/api/v1/invoices/{$invoice->id}/issue");
        $second->assertOk();

        $this->assertSame($first->json('data.sent_at'), $second->json('data.sent_at'));
        $this->assertSame($first->json('data.reporting'), $second->json('data.reporting'));
        $this->assertSame($first->json('data.total'), $second->json('data.total'));
    }

    public function test_a_missing_invoice_cannot_be_issued(): void
    {
        $user = User::factory()->create();
        $secret = $this->secretFor($user, [Scopes::ACCOUNTING_WRITE]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->postJson('/api/v1/invoices/999999/issue')
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found']);
    }

    /* Expenses ---------------------------------------------------------------- */

    public function test_expenses_index_and_show_read_through_the_contract(): void
    {
        $user = User::factory()->create();
        $expense = Expense::factory()->billable()->create(['vendor' => 'Hetzner']);
        Expense::factory()->count(4)->create();

        $secret = $this->secretFor($user, [Scopes::ACCOUNTING_READ]);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/expenses?billable=1')
            ->assertOk()
            ->assertJsonFragment(['vendor' => 'Hetzner']);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/expenses/'.$expense->id)
            ->assertOk()
            ->assertJsonPath('data.vendor', 'Hetzner')
            ->assertJsonPath('data.is_billable', true);
    }

    /* last_used_at moves on a successful call ------------------------------------ */

    public function test_last_used_at_moves_on_a_successful_api_call(): void
    {
        $user = User::factory()->create();
        ['credential' => $credential, 'secret' => $secret] = $this->issuer()->issue(
            $user, 'Tracked', [Scopes::CORE_READ], null, $user,
        );

        $this->assertNull($credential->last_used_at);

        $this->withHeaders($this->basic($user->email, $secret))
            ->getJson('/api/v1/customers')
            ->assertOk();

        $this->assertNotNull($credential->fresh()->last_used_at);
    }

    /* One envelope, whichever layer refuses -------------------------------------- */

    /**
     * A client should branch on one error skeleton, not on which layer happened
     * to refuse it.
     *
     * The two pieces of middleware refuse *before* a controller runs, so for a
     * while they were the exception: `message` and no `code`, while everything
     * built through `ApiResponse` carried both. That is exactly the kind of
     * inconsistency nobody notices until a client is written against it, so it
     * is pinned here across all five statuses rather than left to a docblock.
     */
    public function test_every_failure_answers_in_the_same_envelope(): void
    {
        $user = User::factory()->create();

        // 401 — no credential at all.
        $unauthenticated = $this->getJson('/api/v1/customers');
        $unauthenticated->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->assertSame('Basic realm="Kargah"', $unauthenticated->headers->get('WWW-Authenticate'));

        // 403 — a real credential without the scope this endpoint wants.
        $wrongScope = $this->secretFor($user, [Scopes::CORE_READ], 'Wrong scope');

        $this->withHeaders($this->basic($user->email, $wrongScope))
            ->getJson('/api/v1/invoices')
            ->assertStatus(403)
            ->assertJsonPath('code', 'insufficient_scope');

        $reader = $this->secretFor($user, Scopes::all(), 'Everything');

        // 404 — a row that does not exist.
        $this->withHeaders($this->basic($user->email, $reader))
            ->getJson('/api/v1/invoices/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        // 422 — a filter the endpoint cannot honour.
        $this->withHeaders($this->basic($user->email, $reader))
            ->getJson('/api/v1/invoices?status=not-a-status')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        // 429 — enough wrong passwords to close the door.
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $response = $this->withHeaders($this->basic($user->email, 'wrong-secret-entirely'))
                ->getJson('/api/v1/customers');

            if ($response->status() === 429) {
                break;
            }
        }

        $response->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
