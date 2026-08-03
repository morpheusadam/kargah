<?php

namespace Modules\Core\Contracts;

/**
 * What other modules — and the API — may know about companies.
 *
 * `07-platform.md` names companies as part of the API surface and Core exposed
 * nothing to read them through, so `/api/v1/companies` could only have been
 * written by importing `Modules\Core\Models\Company` into Platform. That is the
 * one thing an edge module may not do. This closes the gap.
 *
 * **Arrays out, never models.** The sibling `CustomerReader` returns Eloquent
 * models, which is the single place in this codebase where "read the contracts,
 * not the models" only half held — a consumer holding a `Customer` can reach
 * every column, every relation and every mutator Core has, which is the whole
 * boundary handed back. That mistake is recorded twice in the handover and is
 * deliberately not copied here.
 *
 * `billing_name` is shaped rather than left to the caller: `Company::billingName()`
 * is "the legal name, or the trading name when there is no legal one", and a
 * consumer re-deriving that rule from `legal_name` and `name` is a second
 * definition of what goes on an invoice.
 *
 * @phpstan-type CompanyArray array{
 *     id: int, name: string, legal_name: ?string, billing_name: string,
 *     tax_number: ?string, tax_office: ?string, country: ?string, address: ?string,
 *     website: ?string, default_currency: ?string, is_domestic: bool,
 *     is_archived: bool, customer_count: int, created_at: ?string
 * }
 */
interface CompanyReader
{
    /** One company, or null when it does not exist. */
    public function find(int $id): ?array;

    /**
     * A page of companies, by name.
     *
     * `$archived` is a three-way filter and not a boolean flag: null means
     * "both", which is what a listing endpoint with no opinion should get,
     * while `false` is the active set the picker shows and `true` is the
     * archive. Collapsing it to `bool $includeArchived` cannot express "only
     * the archived ones", which is the one view a settings page needs.
     *
     * @return array{items: list<CompanyArray>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function paginate(string $search = '', ?bool $archived = null, ?string $cursor = null, int $perPage = 20): array;

    /** id => display name, for select boxes. Active companies only. */
    public function options(): array;
}
