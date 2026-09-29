<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ExportRunJob;
use App\Sales\Application\ManageExports;
use App\Sales\Domain\ExportRepository;
use App\Sales\Domain\ExportType;
use App\Sales\Infrastructure\ExportJobRecord;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class ExportController
{
    public function storeProspects(ApiRequest $r, ManageExports $service)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $job = $service->create($r->user()->id, ExportType::PROSPECT, $r->validated(),
            $r->input('columns'), $this->scope($r));
        ExportRunJob::dispatch($job->id);

        return response()->json(['data' => $job->publicData()], 202);
    }

    public function storePerformance(ApiRequest $r, ManageExports $service)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $job = $service->create($r->user()->id, ExportType::PERFORMANCE, $r->validated(),
            $r->input('columns'), $this->scope($r));
        ExportRunJob::dispatch($job->id);

        return response()->json(['data' => $job->publicData()], 202);
    }

    public function show(ExportJobRecord $export, ExportRepository $exports)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $job = $exports->find($export->id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        $this->ensureVisible($job, request()->user()->id, request()->user()->permits('sales.all'));

        return response()->json(['data' => $job->publicData()]);
    }

    public function download(ExportJobRecord $export, ManageExports $service, ApiRequest $r)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $path = $service->downloadPath($export->id, $r->user()->id, $r->user()->permits('sales.all'));

        return response()->download($path, "export-{$export->id}.csv", ['Content-Type' => 'text/csv']);
    }

    private function scope(ApiRequest $r): ?string
    {
        return $r->user()->permits('sales.all') ? $r->input('owner') : $r->user()->id;
    }

    private function ensureVisible($job, string $requesterId, bool $privileged): void
    {
        if (! $privileged && ($job->createdBy !== $requesterId || ($job->filters['ownerScope'] ?? $requesterId) !== $requesterId)) {
            throw new BusinessRule('FORBIDDEN', 'Anda tidak memiliki izin untuk tindakan ini.', 403);
        }
    }
}
