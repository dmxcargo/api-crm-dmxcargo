<?php

namespace App\Audit\Presentation;

use App\Shared\Presentation\ApiRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AuditController
{
    public function __invoke(ApiRequest $r)
    {
        Gate::authorize('audit.read');

        $query = DB::table('audit_logs')
            ->when($r->filled('action'), fn ($q) => $q->where('action', 'like', '%' . $r->input('action') . '%'))
            ->when($r->filled('entityType'), fn ($q) => $q->where('entity_type', $r->input('entityType')))
            ->when($r->filled('actorUserId'), fn ($q) => $q->where('actor_user_id', $r->input('actorUserId')))
            ->when($r->filled('traceId'), fn ($q) => $q->whereRaw("trace_id::text ILIKE ?", ['%' . $r->input('traceId') . '%']))
            ->when($r->filled('dateFrom'), function ($q) use ($r) {
                try {
                    $from = Carbon::parse($r->input('dateFrom'), 'Asia/Jakarta')->setTimezone('UTC');
                    $q->where('created_at', '>=', $from);
                } catch (\Throwable) {}
            })
            ->when($r->filled('dateTo'), function ($q) use ($r) {
                try {
                    $to = Carbon::parse($r->input('dateTo'), 'Asia/Jakarta')->endOfDay()->setTimezone('UTC');
                    $q->where('created_at', '<=', $to);
                } catch (\Throwable) {}
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $pageSize = min(100, max(10, $r->integer('pageSize', 25)));
        $p = $query->paginate($pageSize);

        $data = collect($p->items())->map(fn ($a) => [
            'id' => $a->id,
            'actorUserId' => $a->actor_user_id,
            'action' => $a->action,
            'entityType' => $a->entity_type,
            'entityId' => $a->entity_id,
            'beforeData' => json_decode($a->before_data),
            'afterData' => json_decode($a->after_data),
            'ipAddress' => $a->ip_address,
            'clientVersion' => $a->client_version,
            'traceId' => $a->trace_id,
            'createdAt' => Carbon::parse($a->created_at)->utc()->toISOString(),
        ]);

        return response()->json([
            'data' => $data,
            'page' => $p->currentPage(),
            'pageSize' => $p->perPage(),
            'totalItems' => $p->total(),
            'totalPages' => $p->lastPage(),
        ]);
    }
}
