<?php

namespace App\Sales\Presentation;

use App\Sales\Application\PipelineService;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Presentation\ApiRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class PipelineController
{
    public function board(ApiRequest $r, PipelineService $pipeline)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $owner = $r->user()->permits('sales.all') ? $r->input('owner') : $r->user()->id;
        $all = $r->boolean('all');
        $dateFrom = $r->input('dateFrom');
        $dateTo = $r->input('dateTo');

        return response()->json(['data' => $pipeline->board($owner, $all, $dateFrom, $dateTo)]);
    }

    public function history(ProspectRecord $prospect)
    {
        Gate::authorize('view', $prospect);
        $rows = DB::table('stage_histories')->where('prospect_id', $prospect->id)->orderBy('created_at')->orderBy('id')->get();
        $times = $rows->map(fn ($h) => Carbon::parse($h->created_at))->values();

        return response()->json(['data' => $rows->values()->map(fn ($h, $i) => ['id' => $h->id, 'fromStage' => $h->from_stage,
            'toStage' => $h->to_stage, 'actorUserId' => $h->actor_user_id, 'createdAt' => $h->created_at,
            'durationSeconds' => ($times[$i + 1] ?? now())->diffInSeconds($times[$i])])]);
    }
}
