<?php

namespace App\Sales\Application;

use App\Audit\Application\AuditWriter;
use App\Sales\Domain\ProspectRepository;
use App\Sales\Domain\ProspectStage;
use App\Sales\Domain\StageTransition;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\UnitOfWork;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PipelineService
{
    public function __construct(private ProspectRepository $prospects, private UnitOfWork $transactions, private AuditWriter $audit) {}

    public function move(string $actorId, string $prospectId, array $data, bool $privileged): array
    {
        return $this->transactions->run(function () use ($actorId, $prospectId, $data, $privileged) {
            $prospect = $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            if ((int) $data['version'] !== $prospect->version) {
                throw new BusinessRule('VERSION_CONFLICT', 'Data telah diubah pengguna lain. Muat ulang data sebelum menyimpan.');
            }
            if (! StageTransition::can($prospect->stage->value, $data['stage'], $privileged)) {
                throw new BusinessRule('ILLEGAL_TRANSITION', 'Perpindahan stage tidak diizinkan dari '.$prospect->stage->value.' ke '.$data['stage'].'.', 422);
            }
            $before = $prospect->auditData();
            $prospect->stage = ProspectStage::from($data['stage']);
            $prospect->version++;
            $this->prospects->save($prospect, $actorId);
            $this->history($prospectId, $before['stage'], $prospect->stage->value, $actorId);
            $this->audit->record('prospect.stage_changed', $actorId, $prospectId, $before, $prospect->auditData(), 'prospect');

            return [$prospect, $before['stage']];
        });
    }

    public function decide(string $actorId, string $prospectId, ProspectStage $to): object
    {
        return $this->transactions->run(function () use ($actorId, $prospectId, $to) {
            $prospect = $this->prospects->find($prospectId, true) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
            $before = $prospect->auditData();
            $prospect->stage = $to;
            $prospect->version++;
            $this->prospects->save($prospect, $actorId);
            $this->history($prospectId, $before['stage'], $to->value, $actorId);
            $this->audit->record('prospect.stage_changed', $actorId, $prospectId, $before, $prospect->auditData(), 'prospect');

            return $prospect;
        });
    }

    /** @return array{stage: string, count: int, totalValue: float, closingValue: float}[] */
    public function board(?string $ownerId, bool $allStages = false, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $q = DB::table('prospects')
            ->whereNull('prospects.deleted_at')
            ->leftJoin('deals', function ($j) {
                $j->on('deals.prospect_id', '=', 'prospects.id')->where('deals.status', 'WON');
            })
            ->selectRaw('prospects.stage as stage, count(distinct prospects.id) as count, '
                .'coalesce(sum(prospects.potential_value), 0) as total_value, '
                .'coalesce(sum(deals.closing_value), 0) as closing_value');

        if ($ownerId) {
            $q->where('prospects.owner_user_id', $ownerId);
        }
        if ($dateFrom) {
            $q->where('prospects.entry_date', '>=', $dateFrom);
        }
        if ($dateTo) {
            $q->where('prospects.entry_date', '<=', $dateTo);
        }

        $rows = $q->groupBy('prospects.stage')->get();

        if (! $allStages) {
            return $rows->map(fn ($r) => [
                'stage' => $r->stage,
                'count' => (int) $r->count,
                'totalValue' => (float) $r->total_value,
                'closingValue' => (float) $r->closing_value,
            ])->all();
        }

        $byStage = $rows->keyBy('stage');
        $result = [];
        foreach (ProspectStage::cases() as $case) {
            $row = $byStage->get($case->value);
            $result[] = [
                'stage' => $case->value,
                'count' => $row ? (int) $row->count : 0,
                'totalValue' => $row ? (float) $row->total_value : 0.0,
                'closingValue' => $row ? (float) $row->closing_value : 0.0,
            ];
        }

        return $result;
    }

    public function history(string $prospectId, ?string $from, string $to, string $actorId): void
    {
        DB::table('stage_histories')->insert(['id' => (string) Str::uuid(), 'prospect_id' => $prospectId,
            'from_stage' => $from, 'to_stage' => $to, 'actor_user_id' => $actorId, 'created_at' => now()]);
    }
}
