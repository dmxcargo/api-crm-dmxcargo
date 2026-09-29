<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\Customer;
use App\Sales\Domain\CustomerRepository;
use Illuminate\Support\Str;

final class EloquentCustomers implements CustomerRepository
{
    public function find(string $id, bool $lock = false): ?Customer
    {
        return $this->map(CustomerRecord::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($id));
    }

    public function map(?CustomerRecord $record): ?Customer
    {
        return $record ? new Customer($record->id, $record->account_name, $record->phone_raw,
            $record->phone_normalized, $record->email, $record->city, $record->province,
            $record->industry_code, $record->is_active, $record->version) : null;
    }

    public function save(Customer $customer): void
    {
        $record = CustomerRecord::find($customer->id) ?? new CustomerRecord;
        $record->id = $customer->id;
        $record->forceFill(['account_name' => $customer->accountName, 'phone_raw' => $customer->phoneRaw,
            'phone_normalized' => $customer->phoneNormalized,
            'email' => $customer->email ? strtolower(trim($customer->email)) : null,
            'city' => $customer->city, 'province' => $customer->province, 'industry_code' => $customer->industryCode,
            'is_active' => $customer->active, 'version' => $customer->version])->save();
    }

    public function candidates(?string $phoneNormalized, ?string $email, ?string $accountName): array
    {
        $q = CustomerRecord::where('is_active', true);
        $q->where(fn ($w) => $w
            ->when($phoneNormalized, fn ($x) => $x->orWhere('phone_normalized', $phoneNormalized))
            ->when($email, fn ($x) => $x->orWhere('email', strtolower(trim($email))))
            ->when($accountName, fn ($x) => $x->orWhereRaw('lower(account_name) = ?', [mb_strtolower(trim($accountName))])));
        if (! $phoneNormalized && ! $email && ! $accountName) {
            return [];
        }

        return $q->limit(5)->get()->map(fn ($r) => $this->map($r))->all();
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }
}
