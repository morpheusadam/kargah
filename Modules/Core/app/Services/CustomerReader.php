<?php

namespace Modules\Core\Services;

use Illuminate\Support\Collection;
use Modules\Core\Contracts\CustomerReader as CustomerReaderContract;
use Modules\Core\Models\Customer;

class CustomerReader implements CustomerReaderContract
{
    public function find(int $id): ?Customer
    {
        return Customer::query()->with('company')->find($id);
    }

    public function findByEmail(string $email): ?Customer
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        return Customer::query()->byEmail($email)->with('company')->first();
    }

    public function search(string $term, int $limit = 20): Collection
    {
        $term = trim($term);

        return Customer::query()
            ->active()
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.$term.'%';
                $q->where(fn ($s) => $s->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->with('company')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function forCompany(int $companyId): Collection
    {
        return Customer::query()
            ->where('company_id', $companyId)
            ->active()
            ->orderBy('name')
            ->get();
    }

    public function options(): array
    {
        return Customer::query()
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
