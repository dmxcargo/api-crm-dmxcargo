<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ManageFollowUps;
use App\Sales\Infrastructure\EloquentFollowUps;
use App\Sales\Infrastructure\FollowUpRecord;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Gate;

final class FollowUpController
{
    public function index(ApiRequest $r, EloquentFollowUps $followUps)
    {
        $tz = config('dmx.business_timezone');
        $start = Carbon::today($tz)->utc();
        $end = Carbon::tomorrow($tz)->utc();
        $q = FollowUpRecord::query();
        if ($r->filled('status')) {
            $q->where('task_status', $r->input('status'));
        } else {
            $q->whereNull('completed_at');
        }
        if (! $r->user()->permits('sales.all')) {
            $q->where('assignee_user_id', $r->user()->id);
        } elseif ($r->filled('assignee')) {
            $q->where('assignee_user_id', $r->input('assignee'));
        }
        if ($r->filled('prospectId')) {
            $q->where('prospect_id', $r->input('prospectId'));
        }
        if ($r->filled('priority')) {
            $q->where('task_priority', $r->input('priority'));
        }
        match ($r->input('mode', 'upcoming')) {
            'today' => $q->whereBetween('scheduled_at', [$start, $end]),
            'overdue' => $q->where('scheduled_at', '<', $start),
            'upcoming' => $q->where('scheduled_at', '>=', $start),
            default => null,
        };
        $p = $q->orderBy('scheduled_at')->orderBy('id')->paginate($r->integer('pageSize', 25));

        $names = ProspectRecord::whereIn('id', collect($p->items())->map->prospect_id->all())
            ->pluck('account_name', 'id');

        return response()->json(['data' => collect($p->items())->map(fn ($row) =>
            [...$followUps->map($row)->publicData(), 'prospectName' => $names[$row->prospect_id] ?? null]),
            'page' => $p->currentPage(), 'pageSize' => $p->perPage(), 'totalItems' => $p->total(), 'totalPages' => $p->lastPage()]);
    }

    public function store(ApiRequest $r, ProspectRecord $prospect, ManageFollowUps $service)
    {
        Gate::authorize('update', $prospect);
        if ($prospect->deleted_at !== null) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }

        return response()->json(['data' => $service->create($r->user()->id, $prospect->id, $r->validated())->publicData()], 201);
    }

    public function complete(FollowUpRecord $followUp, ManageFollowUps $service, ApiRequest $r)
    {
        $this->scope($followUp, $r);

        return response()->json(['data' => $service->complete($r->user()->id, $followUp->id)->publicData()]);
    }

    public function reschedule(ApiRequest $r, FollowUpRecord $followUp, ManageFollowUps $service)
    {
        $this->scope($followUp, $r);

        return response()->json(['data' => $service->reschedule($r->user()->id, $followUp->id, $r->validated())->publicData()]);
    }

    private function scope(FollowUpRecord $followUp, ApiRequest $r): void
    {
        $prospect = ProspectRecord::find($followUp->prospect_id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if ($prospect->deleted_at !== null) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
        Gate::authorize('update', $prospect);
    }
}
