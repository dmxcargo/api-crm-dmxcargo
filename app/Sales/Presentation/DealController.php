<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ManageDeals;
use App\Sales\Application\ManagePayments;
use App\Sales\Infrastructure\DealRecord;
use App\Sales\Infrastructure\EloquentDeals;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class DealController
{
    public function store(ApiRequest $r, ProspectRecord $prospect, ManageDeals $service)
    {
        Gate::authorize('update', $prospect);
        if ($prospect->deleted_at !== null) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return response()->json(['data' => $service->create($r->user()->id, $prospect->id, $r->validated())->publicData()], 201);
    }

    public function update(ApiRequest $r, DealRecord $deal, ManageDeals $service, EloquentDeals $deals)
    {
        $this->scope($deal);

        return response()->json(['data' => $service->update($r->user()->id, $deal->id, $r->validated())->publicData()]);
    }

    public function won(ApiRequest $r, DealRecord $deal, ManageDeals $service)
    {
        $this->scope($deal);

        return response()->json(['data' => $service->won($r->user()->id, $deal->id, $r->validated())->publicData()]);
    }

    public function lost(ApiRequest $r, DealRecord $deal, ManageDeals $service)
    {
        $this->scope($deal);

        return response()->json(['data' => $service->lost($r->user()->id, $deal->id, $r->validated())->publicData()]);
    }

    public function candidates(DealRecord $deal, ManageDeals $service, EloquentDeals $deals)
    {
        $this->scope($deal);

        return response()->json(['data' => collect($service->customerCandidates($deal->id))->map(fn ($c) => $c->publicData())]);
    }

    public function payment(ApiRequest $r, DealRecord $deal, ManagePayments $service)
    {
        Gate::authorize('payments.manage');
        $prospect = ProspectRecord::find($deal->prospect_id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        Gate::authorize('view', $prospect);
        [$updated] = $service->change($r->user()->id, $deal->id, $r->validated()['paymentStatus']);

        return response()->json(['data' => $updated->publicData()]);
    }

    public function history(DealRecord $deal)
    {
        $this->scope($deal);
        $rows = DB::table('payment_status_histories')->where('deal_id', $deal->id)->orderBy('created_at')->orderBy('id')->get();

        return response()->json(['data' => collect($rows)->map(fn ($h) => ['id' => $h->id, 'fromStatus' => $h->from_status,
            'toStatus' => $h->to_status, 'actorUserId' => $h->actor_user_id, 'createdAt' => $h->created_at])]);
    }

    public function index(ProspectRecord $prospect, EloquentDeals $deals)
    {
        $this->scope($prospect);
        $rows = DealRecord::where('prospect_id', $prospect->id)->orderByDesc('created_at')->get();

        return response()->json(['data' => $rows->map(fn ($d) => $deals->map($d)->publicData())]);
    }

    private function scope(DealRecord|ProspectRecord $record): void
    {
        $prospect = $record instanceof ProspectRecord ? $record
            : ProspectRecord::find($record->prospect_id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($prospect->deleted_at !== null) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
        Gate::authorize('update', $prospect);
    }
}
