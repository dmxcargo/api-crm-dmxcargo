<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ManageActivities;
use App\Sales\Infrastructure\ActivityRecord;
use App\Sales\Infrastructure\EloquentActivities;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class ActivityController
{
    public function index(ProspectRecord $prospect, EloquentActivities $activities, ApiRequest $r)
    {
        Gate::authorize('view', $prospect);
        $p = ActivityRecord::where('prospect_id', $prospect->id)
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->input('type')))
            ->orderByDesc('activity_at')->orderByDesc('id')->paginate($r->integer('pageSize', 25));

        return response()->json(['data' => collect($p->items())->map(fn ($row) => $activities->map($row)->publicData()),
            'page' => $p->currentPage(), 'pageSize' => $p->perPage(), 'totalItems' => $p->total(), 'totalPages' => $p->lastPage()]);
    }

    public function store(ApiRequest $r, ProspectRecord $prospect, ManageActivities $service)
    {
        Gate::authorize('update', $prospect);
        if ($prospect->deleted_at !== null) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return response()->json(['data' => $service->log($r->user()->id, $prospect->id, $r->validated())->publicData()], 201);
    }
}
