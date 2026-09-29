<?php

namespace App\Sales\Presentation;

use App\Sales\Application\MetricsService;
use App\Sales\Domain\TargetPeriod;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class DashboardController
{
    public function board(ApiRequest $r, MetricsService $metrics)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $owner = $r->user()->permits('sales.all') ? $r->input('owner') : $r->user()->id;

        return response()->json(['data' => $metrics->dashboard($owner, $r->only(
            ['stage', 'sourceCode', 'customerType', 'priority', 'from', 'to']))]);
    }

    public function performance(ApiRequest $r, MetricsService $metrics)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $type = TargetPeriod::from($r->input('periodType'));
        $period = (string) $r->input('period');
        $valid = $type === TargetPeriod::MONTH
            ? preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)
            : preg_match('/^\d{4}$/', $period);
        if (! $valid) {
            throw new BusinessRule('INVALID_PERIOD', 'Format periode tidak valid. Gunakan YYYY-MM atau YYYY.', 422);
        }
        $privileged = $r->user()->permits('sales.all');
        $owners = $privileged && $r->input('groupBy', 'sales') === 'sales'
            ? $metrics->ownersInScope($r->input('owner'), $type, $r->input('period'))
            : [$privileged ? ($r->input('owner') ?? $r->user()->id) : $r->user()->id];
        $names = $metrics->names($owners);
        $rows = [];
        foreach ($owners as $ownerId) {
            $rows[] = [...$metrics->perOwner($ownerId, $type, $r->input('period')),
                'ownerName' => $names[$ownerId] ?? $ownerId];
        }

        return response()->json(['data' => $rows]);
    }

    public function annual(ApiRequest $r, MetricsService $metrics)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        if (! preg_match('/^\d{4}$/', (string) $r->input('year'))) {
            throw new BusinessRule('INVALID_PERIOD', 'Format tahun tidak valid. Gunakan YYYY.', 422);
        }
        $privileged = $r->user()->permits('sales.all');
        $owners = $privileged
            ? $metrics->ownersInScope($r->input('owner'), TargetPeriod::YEAR, $r->input('year'))
            : [$r->user()->id];
        $names = $metrics->names($owners);
        $perSales = [];
        $totals = ['totalProspect' => 0, 'closingCount' => 0, 'actualValue' => 0.0, 'targetValue' => 0.0,
            'hasTarget' => false, 'calls' => 0, 'callsAnswered' => 0, 'visits' => 0, 'overdueFollowUps' => 0,
            'hotCount' => 0, 'warmCount' => 0, 'coldCount' => 0, 'followUpCount' => 0];
        foreach ($owners as $ownerId) {
            $m = $metrics->perOwner($ownerId, TargetPeriod::YEAR, $r->input('year'));
            $perSales[] = [...$m, 'ownerName' => $names[$ownerId] ?? $ownerId];
            $totals['totalProspect'] += $m['totalProspect'];
            $totals['closingCount'] += $m['closingCount'];
            $totals['hotCount'] += $m['hotCount'] ?? 0;
            $totals['warmCount'] += $m['warmCount'] ?? 0;
            $totals['coldCount'] += $m['coldCount'] ?? 0;
            $totals['followUpCount'] += $m['followUpCount'] ?? 0;
            $totals['actualValue'] += (float) $m['actualValue'];
            if ($m['targetValue'] !== null) {
                $totals['targetValue'] += (float) $m['targetValue'];
                $totals['hasTarget'] = true;
            }
            $totals['overdueFollowUps'] += $m['overdueFollowUps'];
            foreach ($m['activities'] as $a) {
                if ($a->type === 'CALL') {
                    $totals['calls'] += (int) $a->c;
                    $totals['callsAnswered'] += (int) $a->answered;
                } elseif ($a->type === 'VISIT') {
                    $totals['visits'] += (int) $a->c;
                }
            }
        }
        $totals['actualValue'] = (string) $totals['actualValue'];
        $totals['targetValue'] = $totals['hasTarget'] ? (string) $totals['targetValue'] : null;
        $totals['achievement'] = $totals['hasTarget'] && $totals['targetValue'] > 0
            ? round($totals['actualValue'] / $totals['targetValue'] * 100, 2) : null;
        $totals['closingRate'] = $totals['totalProspect'] === 0 ? null
            : round($totals['closingCount'] / $totals['totalProspect'] * 100, 2);
        unset($totals['hasTarget']);

        return response()->json(['data' => ['year' => $r->input('year'), 'totals' => $totals, 'perSales' => $perSales]]);
    }

    public function annualMatrix(ApiRequest $r, MetricsService $metrics)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $fromYear = (int) $r->input('fromYear', 2025);
        $toYear = (int) $r->input('toYear', 2030);
        if ($fromYear < 2000 || $toYear > 2100 || $fromYear > $toYear) {
            throw new BusinessRule('INVALID_PERIOD', 'Rentang tahun tidak valid. Harus antara 2000 dan 2100.', 422);
        }
        $privileged = $r->user()->permits('sales.all');
        $owner = $privileged ? $r->input('owner') : $r->user()->id;

        return response()->json(['data' => $metrics->salesMatrix($fromYear, $toYear, $owner)]);
    }
}
