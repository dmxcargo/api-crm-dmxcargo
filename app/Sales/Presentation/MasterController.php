<?php

namespace App\Sales\Presentation;

use App\Audit\Application\AuditWriter;
use App\Sales\Infrastructure\EloquentProspects;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class MasterController
{
    public function sources(EloquentProspects $prospects)
    {
        return $this->list($prospects, 'prospect_sources');
    }

    public function industries(EloquentProspects $prospects)
    {
        return $this->list($prospects, 'industries');
    }

    public function lostReasons(EloquentProspects $prospects)
    {
        return $this->list($prospects, 'lost_reasons');
    }

    public function storeSource(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit)
    {
        return $this->store($r, $prospects, $audit, 'prospect_sources');
    }

    public function storeIndustry(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit)
    {
        return $this->store($r, $prospects, $audit, 'industries');
    }

    public function storeReason(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit)
    {
        return $this->store($r, $prospects, $audit, 'lost_reasons');
    }

    public function updateSource(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit, string $code)
    {
        return $this->modify($r, $prospects, $audit, 'prospect_sources', $code);
    }

    public function updateIndustry(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit, string $code)
    {
        return $this->modify($r, $prospects, $audit, 'industries', $code);
    }

    public function updateReason(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit, string $code)
    {
        return $this->modify($r, $prospects, $audit, 'lost_reasons', $code);
    }

    private function store(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit, string $table)
    {
        Gate::authorize('sales.all');
        $data = $r->validated();
        if ($prospects->masterExists($table, $data['code'])) {
            throw new BusinessRule('DUPLICATE_DATA', 'Kode master sudah digunakan. Gunakan kode lain.', 409);
        }
        DB::table($table)->insert(['code' => $data['code'], 'label' => $data['label'], 'is_active' => true]);
        $audit->record('master.created', $r->user()->id, null, [], ['table' => $table, 'code' => $data['code']], 'master');

        return response()->json(['data' => ['code' => $data['code'], 'label' => $data['label'], 'active' => true]], 201);
    }

    private function modify(ApiRequest $r, EloquentProspects $prospects, AuditWriter $audit, string $table, string $code)
    {
        Gate::authorize('sales.all');
        $row = DB::table($table)->where('code', $code)->first() ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        $data = $r->validated();
        DB::table($table)->where('code', $code)->update(array_filter([
            'label' => $data['label'] ?? null,
            'is_active' => array_key_exists('isActive', $data) ? $data['isActive'] : null,
        ], fn ($v) => $v !== null));
        $audit->record('master.updated', $r->user()->id, null,
            ['label' => $row->label, 'active' => (bool) $row->is_active],
            ['label' => $data['label'] ?? $row->label, 'active' => $data['isActive'] ?? (bool) $row->is_active], 'master');

        return response()->json(['data' => ['code' => $code,
            'label' => $data['label'] ?? $row->label, 'active' => (bool) ($data['isActive'] ?? $row->is_active)]]);
    }

    private function list(EloquentProspects $prospects, string $table)
    {
        $all = request()->boolean('all');
        $query = DB::table($table)->orderBy('label');
        if (! $all) {
            $query->where('is_active', true);
        }

        return response()->json([
            'data' => $query->get(['code', 'label', 'is_active'])->map(fn ($m) => [
                'code' => $m->code,
                'label' => $m->label,
                'active' => (bool) $m->is_active,
            ]),
        ]);
    }

    private function guard(string $table): void
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
    }
}
