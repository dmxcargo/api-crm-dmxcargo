<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ManageCustomers;
use App\Sales\Infrastructure\CustomerRecord;
use App\Sales\Infrastructure\DealRecord;
use App\Sales\Infrastructure\EloquentCustomers;
use App\Sales\Infrastructure\EloquentDeals;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class CustomerController
{
    public function index(ApiRequest $r, EloquentCustomers $customers)
    {
        $q = CustomerRecord::orderBy('account_name')->orderBy('id');
        if (! $r->user()->permits('sales.all')) {
            $mine = DB::table('deals')->join('prospects', 'prospects.id', '=', 'deals.prospect_id')
                ->where('prospects.owner_user_id', $r->user()->id)->whereNotNull('deals.customer_id')
                ->distinct()->pluck('deals.customer_id');
            $q->whereIn('id', $mine);
        }
        if ($r->filled('q')) {
            $term = '%'.str_replace(['%', '_'], '', $r->input('q')).'%';
            $q->where(fn ($w) => $w->where('account_name', 'ilike', $term)->orWhere('phone_raw', 'like', $term)->orWhere('email', 'ilike', $term));
        }
        $p = $q->paginate($r->integer('pageSize', 25));

        return response()->json(['data' => collect($p->items())->map(fn ($row) => $customers->map($row)->publicData()),
            'page' => $p->currentPage(), 'pageSize' => $p->perPage(), 'totalItems' => $p->total(), 'totalPages' => $p->lastPage()]);
    }

    public function show(CustomerRecord $customer, EloquentCustomers $customers, ApiRequest $r)
    {
        $this->scope($customer, $r);

        return response()->json(['data' => $customers->map($customer)->publicData()]);
    }

    public function store(ApiRequest $r, ManageCustomers $service)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->create($r->user()->id, $r->validated())->publicData()], 201);
    }

    public function update(ApiRequest $r, CustomerRecord $customer, ManageCustomers $service)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->update($r->user()->id, $customer->id, $r->validated())->publicData()]);
    }

    public function repeat(ApiRequest $r, CustomerRecord $customer, ManageCustomers $service)
    {
        $prospect = ProspectRecord::find($r->validated()['prospectId'])
            ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        Gate::authorize('update', $prospect);
        $this->scope($customer, $r);

        return response()->json(['data' => $service->repeatOrder($r->user()->id, $customer->id, $prospect->id)->publicData()], 201);
    }

    public function deals(CustomerRecord $customer, ApiRequest $r, EloquentDeals $deals)
    {
        $this->scope($customer, $r);
        $rows = DealRecord::where('customer_id', $customer->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($d) => $deals->map($d)->publicData())]);
    }

    private function scope(CustomerRecord $customer, ApiRequest $r): void
    {
        if ($r->user()->permits('sales.all')) {
            return;
        }
        $linked = DB::table('deals')->join('prospects', 'prospects.id', '=', 'deals.prospect_id')
            ->where('deals.customer_id', $customer->id)->where('prospects.owner_user_id', $r->user()->id)->exists();
        if (! $linked) {
            abort(404, 'Data tidak ditemukan.');
        }
    }
}
