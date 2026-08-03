<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Modules\Core\Contracts\CompanyReader as CompanyReaderContract;
use Modules\Core\Models\Company;

class CompanyReader implements CompanyReaderContract
{
    public function find(int $id): ?array
    {
        $company = $this->query()->find($id);

        return $company === null ? null : $this->shape($company);
    }

    public function paginate(string $search = '', ?bool $archived = null, ?string $cursor = null, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $query = $this->query();

        $term = trim($search);

        if ($term !== '') {
            $like = '%'.$term.'%';

            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('legal_name', 'like', $like)
                ->orWhere('tax_number', 'like', $like));
        }

        if ($archived !== null) {
            $archived ? $query->archived() : $query->active();
        }

        $decoded = $cursor === null || $cursor === ''
            ? null
            : rescue(fn (): ?Cursor => Cursor::fromEncoded($cursor), null, false);

        // Ordered by id, not by name: a cursor paginator needs a column it can
        // compare and that is unique, and two companies may share a name.
        $paginator = $query->orderBy('id')->cursorPaginate($perPage, ['*'], 'cursor', $decoded);

        return [
            'items' => $paginator->getCollection()->map(fn (Company $company): array => $this->shape($company))->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    public function options(): array
    {
        return Company::query()
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private function query(): Builder
    {
        return Company::query()->withCount('customers');
    }

    /** @return array<string, mixed> */
    private function shape(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'billing_name' => $company->billingName(),
            'tax_number' => $company->tax_number,
            'tax_office' => $company->tax_office,
            'country' => $company->country,
            'address' => $company->address,
            'website' => $company->website,
            'default_currency' => $company->default_currency,
            'is_domestic' => (bool) $company->is_domestic,
            'is_archived' => $company->isArchived(),
            // `withCount` on the query above, so a page of twenty companies is
            // one query rather than twenty-one.
            'customer_count' => (int) ($company->customers_count ?? 0),
            'created_at' => $company->created_at?->toIso8601String(),
        ];
    }
}
