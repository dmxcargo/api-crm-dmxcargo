<?php

namespace App\Sales\Application;

use App\Sales\Domain\TargetPeriod;
use App\Sales\Domain\TargetRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class MetricsService
{
    public function __construct(private TargetRepository $targets) {}

    /** @return array{from: string, to: string} */
    public static function bounds(TargetPeriod $type, string $period): array
    {
        if ($type === TargetPeriod::MONTH) {
            [$y, $m] = explode('-', $period);
            $from = sprintf('%04d-%02d-01', $y, $m);
            $to = date('Y-m-t', strtotime($from));
        } else {
            $from = "$period-01-01";
            $to = "$period-12-31";
        }

        return ['from' => $from, 'to' => $to];
    }

    public function dashboard(?string $ownerId, array $filters = []): array
    {
        $tz = config('dmx.business_timezone');
        $start = Carbon::today($tz)->utc();
        $end = Carbon::tomorrow($tz)->utc();
        $scoped = function () use ($ownerId, $filters) {
            $q = DB::table('prospects')->whereNull('prospects.deleted_at')->whereNull('prospects.archived_at');
            $this->scopeProspects($q, $ownerId, $filters);

            return $q;
        };
        $fuToday = DB::table('follow_ups')->join('prospects', 'prospects.id', '=', 'follow_ups.prospect_id')
            ->whereNull('prospects.deleted_at')->whereNull('follow_ups.completed_at')
            ->when($ownerId, fn ($q) => $q->where('prospects.owner_user_id', $ownerId));
        $fuOverdue = clone $fuToday;
        $sums = DB::table('prospects')->whereNull('deleted_at')
            ->when($ownerId, fn ($q) => $q->where('owner_user_id', $ownerId))
            ->selectRaw('coalesce(sum(potential_value),0) as potential')->first();
        $deals = DB::table('deals')->join('prospects', 'prospects.id', '=', 'deals.prospect_id')
            ->whereNull('prospects.deleted_at')
            ->when($ownerId, fn ($q) => $q->whereRaw('coalesce(deals.closing_owner_id, prospects.owner_user_id) = ?', [$ownerId]))
            ->selectRaw("coalesce(sum(quotation_value),0) as quotation, coalesce(sum(case when deals.status='WON' then closing_value else 0 end),0) as closing")->first();

        $stageQ = $scoped();
        $priorityQ = $scoped();

        return ['activeProspect' => $scoped()->count(),
            'byStage' => $stageQ->selectRaw('stage, count(*) as count')->groupBy('stage')->orderBy('stage')->get()->all(),
            'byPriority' => $priorityQ->selectRaw('priority, count(*) as count')->groupBy('priority')->orderBy('priority')->get()->all(),
            'followUpsToday' => $fuToday->whereBetween('follow_ups.scheduled_at', [$start, $end])->count(),
            'followUpsOverdue' => $fuOverdue->where('follow_ups.scheduled_at', '<', $start)->count(),
            'potentialValue' => (string) $sums->potential,
            'quotationValue' => (string) $deals->quotation,
            'closingValue' => (string) $deals->closing];
    }

    public function perOwner(string $ownerId, TargetPeriod $type, string $period): array
    {
        ['from' => $from, 'to' => $to] = self::bounds($type, $period);
        $target = $this->targets->existing($ownerId, $type, $period);
        $actual = (string) DB::table('deals')->join('prospects', 'prospects.id', '=', 'deals.prospect_id')
            ->whereNull('prospects.deleted_at')
            ->whereRaw('coalesce(deals.closing_owner_id, prospects.owner_user_id) = ?', [$ownerId])
            ->where('deals.status', 'WON')->whereBetween('deals.closing_date', [$from, $to])
            ->selectRaw('coalesce(sum(deals.closing_value),0) as v')->value('v');
        $denom = DB::table('prospects')->whereNull('deleted_at')->where('owner_user_id', $ownerId)
            ->whereBetween('entry_date', [$from, $to])->distinct()->count('id');
        $numer = $denom === 0 ? 0 : DB::table('prospects')->whereNull('deleted_at')->where('owner_user_id', $ownerId)
            ->whereBetween('entry_date', [$from, $to])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('deals')
                ->whereColumn('deals.prospect_id', 'prospects.id')->where('deals.status', 'WON'))
            ->distinct()->count('prospects.id');
        $tz = config('dmx.business_timezone');
        $today = Carbon::today($tz)->utc();
        $acts = DB::table('activities')->join('prospects', 'prospects.id', '=', 'activities.prospect_id')
            ->whereNull('prospects.deleted_at')->where('prospects.owner_user_id', $ownerId)
            ->whereBetween('activities.activity_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('type, count(*) as c, coalesce(sum(case when answered then 1 else 0 end),0) as answered')->groupBy('type')->get();
        $overdue = DB::table('follow_ups')->join('prospects', 'prospects.id', '=', 'follow_ups.prospect_id')
            ->whereNull('prospects.deleted_at')->where('prospects.owner_user_id', $ownerId)
            ->whereNull('follow_ups.completed_at')->where('follow_ups.scheduled_at', '<', $today)->count();

        $prio = DB::table('prospects')->whereNull('deleted_at')->where('owner_user_id', $ownerId)
            ->whereBetween('entry_date', [$from, $to])
            ->selectRaw("coalesce(sum(case when priority = 'HOT' then 1 else 0 end), 0) as hot_count, coalesce(sum(case when priority = 'WARM' then 1 else 0 end), 0) as warm_count, coalesce(sum(case when priority = 'COLD' then 1 else 0 end), 0) as cold_count")->first();
        $fuCount = DB::table('follow_ups')->join('prospects', 'prospects.id', '=', 'follow_ups.prospect_id')
            ->whereNull('prospects.deleted_at')->where('prospects.owner_user_id', $ownerId)
            ->whereBetween('follow_ups.scheduled_at', [$from.' 00:00:00', $to.' 23:59:59'])->count();

        return ['ownerUserId' => $ownerId,
            'targetValue' => $target?->targetValue,
            'actualValue' => $actual,
            'achievement' => $target && (float) $target->targetValue > 0
                ? round((float) $actual / (float) $target->targetValue * 100, 2) : null,
            'closingRate' => $denom === 0 ? null : round($numer / $denom * 100, 2),
            'totalProspect' => $denom,
            'closingCount' => $numer,
            'hotCount' => (int) ($prio->hot_count ?? 0),
            'warmCount' => (int) ($prio->warm_count ?? 0),
            'coldCount' => (int) ($prio->cold_count ?? 0),
            'followUpCount' => $fuCount,
            'activities' => $acts->all(),
            'overdueFollowUps' => $overdue];
    }

    public function salesMatrix(int $fromYear = 2025, int $toYear = 2030, ?string $ownerId = null): array
    {
        $from = "$fromYear-01-01";
        $to = "$toYear-12-31";
        $fromTs = "$from 00:00:00";
        $toTs = "$to 23:59:59";

        $salesUsers = DB::table('users')
            ->join('user_roles', 'users.id', '=', 'user_roles.user_id')
            ->where('user_roles.role_code', 'SALES')
            ->when($ownerId, fn ($q) => $q->where('users.id', $ownerId))
            ->orderBy('users.name')
            ->select('users.id', 'users.name')
            ->get();

        // 7 query agregat untuk seluruh horizon; jumlah query tetap walau tahun/sales bertambah.
        // 1. Prospek + prioritas per tahun.
        $prosByYear = DB::table('prospects')->whereNull('deleted_at')
            ->when($ownerId, fn ($q) => $q->where('owner_user_id', $ownerId))
            ->whereBetween('entry_date', [$from, $to])
            ->selectRaw("extract(year from entry_date)::int as y, count(*)::int as denom, "
                ."coalesce(sum(case when priority = 'HOT' then 1 else 0 end), 0)::int as hot_count, "
                ."coalesce(sum(case when priority = 'WARM' then 1 else 0 end), 0)::int as warm_count, "
                ."coalesce(sum(case when priority = 'COLD' then 1 else 0 end), 0)::int as cold_count")
            ->groupByRaw('extract(year from entry_date)')
            ->get()->keyBy(fn ($r) => (int) $r->y);

        // 2. Closing (distinct prospek ber-deal WON) per tahun.
        $wonByYear = DB::table('prospects')->whereNull('deleted_at')
            ->when($ownerId, fn ($q) => $q->where('owner_user_id', $ownerId))
            ->whereBetween('entry_date', [$from, $to])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('deals')
                ->whereColumn('deals.prospect_id', 'prospects.id')->where('deals.status', 'WON'))
            ->selectRaw('extract(year from entry_date)::int as y, count(distinct prospects.id)::int as numer')
            ->groupByRaw('extract(year from entry_date)')
            ->pluck('numer', 'y');

        // 3. Follow-up terjadwal per tahun.
        $fuByYear = DB::table('follow_ups')->join('prospects', 'prospects.id', '=', 'follow_ups.prospect_id')
            ->whereNull('prospects.deleted_at')
            ->when($ownerId, fn ($q) => $q->where('prospects.owner_user_id', $ownerId))
            ->whereBetween('follow_ups.scheduled_at', [$fromTs, $toTs])
            ->selectRaw('extract(year from follow_ups.scheduled_at)::int as y, count(*)::int as c')
            ->groupByRaw('extract(year from follow_ups.scheduled_at)')
            ->pluck('c', 'y');

        // 4. Aktivitas per tahun + tipe.
        $actsByYear = [];
        foreach (DB::table('activities')->join('prospects', 'prospects.id', '=', 'activities.prospect_id')
            ->whereNull('prospects.deleted_at')
            ->when($ownerId, fn ($q) => $q->where('prospects.owner_user_id', $ownerId))
            ->whereBetween('activities.activity_at', [$fromTs, $toTs])
            ->selectRaw('extract(year from activities.activity_at)::int as y, type, count(*)::int as c')
            ->groupByRaw('extract(year from activities.activity_at), type')->get() as $a) {
            $actsByYear[(int) $a->y][$a->type] = (int) $a->c;
        }

        // 5. Prospek + prioritas per (owner, tahun).
        $prosByOwnerYear = [];
        foreach (DB::table('prospects')->whereNull('deleted_at')
            ->whereBetween('entry_date', [$from, $to])
            ->selectRaw("owner_user_id, extract(year from entry_date)::int as y, count(*)::int as denom, "
                ."coalesce(sum(case when priority = 'HOT' then 1 else 0 end), 0)::int as hot_count, "
                ."coalesce(sum(case when priority = 'WARM' then 1 else 0 end), 0)::int as warm_count, "
                ."coalesce(sum(case when priority = 'COLD' then 1 else 0 end), 0)::int as cold_count")
            ->groupByRaw('owner_user_id, extract(year from entry_date)')->get() as $r) {
            $prosByOwnerYear[$r->owner_user_id][(int) $r->y] = $r;
        }

        // 6. Closing per (owner, tahun).
        $wonByOwnerYear = [];
        foreach (DB::table('prospects')->whereNull('deleted_at')
            ->whereBetween('entry_date', [$from, $to])
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('deals')
                ->whereColumn('deals.prospect_id', 'prospects.id')->where('deals.status', 'WON'))
            ->selectRaw('owner_user_id, extract(year from entry_date)::int as y, count(distinct prospects.id)::int as numer')
            ->groupByRaw('owner_user_id, extract(year from entry_date)')->get() as $r) {
            $wonByOwnerYear[$r->owner_user_id][(int) $r->y] = (int) $r->numer;
        }

        // 7. Call per (owner, tahun).
        $callsByOwnerYear = [];
        foreach (DB::table('activities')->join('prospects', 'prospects.id', '=', 'activities.prospect_id')
            ->whereNull('prospects.deleted_at')
            ->where('activities.type', 'CALL')
            ->whereBetween('activities.activity_at', [$fromTs, $toTs])
            ->selectRaw('prospects.owner_user_id, extract(year from activities.activity_at)::int as y, count(*)::int as c')
            ->groupByRaw('prospects.owner_user_id, extract(year from activities.activity_at)')->get() as $r) {
            $callsByOwnerYear[$r->owner_user_id][(int) $r->y] = (int) $r->c;
        }

        $summaryYears = [];
        $perSalesYears = [];

        for ($yr = $fromYear; $yr <= $toYear; $yr++) {
            // 1. Ringkasan per tahun.
            $p = $prosByYear[$yr] ?? null;
            $denom = $p ? (int) $p->denom : 0;
            $numer = (int) ($wonByYear[$yr] ?? 0);

            $closingRate = $denom === 0 ? 0.0 : round($numer / $denom * 100, 2);

            $summaryYears[] = [
                'year' => $yr,
                'totalProspect' => $denom,
                'hotCount' => $p ? (int) $p->hot_count : 0,
                'warmCount' => $p ? (int) $p->warm_count : 0,
                'coldCount' => $p ? (int) $p->cold_count : 0,
                'closingCount' => $numer,
                'closingRate' => $closingRate,
                'followUpCount' => (int) ($fuByYear[$yr] ?? 0),
                'visitCount' => (int) ($actsByYear[$yr]['VISIT'] ?? 0),
                'callCount' => (int) ($actsByYear[$yr]['CALL'] ?? 0),
            ];

            // 2. Rincian per sales.
            foreach ($salesUsers as $u) {
                $sp = $prosByOwnerYear[$u->id][$yr] ?? null;
                $sDenom = $sp ? (int) $sp->denom : 0;
                $sNumer = (int) ($wonByOwnerYear[$u->id][$yr] ?? 0);

                $sClosingRate = $sDenom === 0 ? 0.0 : round($sNumer / $sDenom * 100, 2);

                $perSalesYears[] = [
                    'year' => $yr,
                    'salesId' => $u->id,
                    'salesName' => $u->name,
                    'totalProspect' => $sDenom,
                    'hotCount' => $sp ? (int) $sp->hot_count : 0,
                    'warmCount' => $sp ? (int) $sp->warm_count : 0,
                    'coldCount' => $sp ? (int) $sp->cold_count : 0,
                    'closingCount' => $sNumer,
                    'closingRate' => $sClosingRate,
                    'callCount' => (int) ($callsByOwnerYear[$u->id][$yr] ?? 0),
                ];
            }
        }

        $managementNotes = [
            'Closing Rate = Closing ÷ Total Prospek pada tahun tersebut.',
            'Follow Up dan Visit/Survey dihitung berdasarkan tanggal aktivitas yang terisi.',
            'Data sumber saat ini belum memiliki kolom Marketing/Sales pada Log Survey dan Follow Up historis, sehingga Visit/FU diatribusikan pada level ringkasan tahunan.',
            'Jangan menganggap Closing Rate sebagai true conversion funnel per lead sampai setiap aktivitas terhubung ke ID Prospek yang unik.',
            'Data tanggal anomali (mis. 2206/0206) dipisahkan di sheet QC DATA dan TIDAK diubah otomatis.'
        ];

        return [
            'summaryYears' => $summaryYears,
            'perSalesYears' => $perSalesYears,
            'managementNotes' => $managementNotes,
        ];
    }

    /** @return string[] */
    public function ownersInScope(?string $ownerId, TargetPeriod $type, string $period): array
    {
        ['from' => $from, 'to' => $to] = self::bounds($type, $period);
        if ($ownerId) {
            return [$ownerId];
        }
        $fromProspects = DB::table('prospects')->whereNull('deleted_at')->whereBetween('entry_date', [$from, $to])
            ->distinct()->pluck('owner_user_id')->all();
        $fromTargets = DB::table('sales_targets')->where('period_type', $type->value)->where('period', $period)
            ->distinct()->pluck('user_id')->all();
        $fromClosings = DB::table('deals')->where('status', 'WON')->whereBetween('closing_date', [$from, $to])
            ->whereNotNull('closing_owner_id')->distinct()->pluck('closing_owner_id')->all();

        return array_values(array_unique([...$fromProspects, ...$fromTargets, ...$fromClosings]));
    }

    public function names(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')->all();
    }

    private function scopeProspects($q, ?string $ownerId, array $filters): void
    {
        if ($ownerId) {
            $q->where('prospects.owner_user_id', $ownerId);
        }
        foreach (['stage' => 'stage', 'sourceCode' => 'source_code', 'customerType' => 'customer_type', 'priority' => 'priority'] as $in => $col) {
            if (! empty($filters[$in])) {
                $q->where("prospects.$col", $filters[$in]);
            }
        }
        if (! empty($filters['from'])) {
            $q->whereDate('prospects.entry_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $q->whereDate('prospects.entry_date', '<=', $filters['to']);
        }
    }
}
