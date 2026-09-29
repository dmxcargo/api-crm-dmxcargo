<?php

namespace App\Sales\Presentation;

use App\Sales\Application\CommitImportJob;
use App\Sales\Application\ManageImports;
use App\Sales\Application\ValidateImportJob;
use App\Sales\Domain\ImportJobStatus;
use App\Sales\Domain\ImportRepository;
use App\Sales\Infrastructure\ImportJobRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class ImportController
{
    public function store(ApiRequest $r, ManageImports $service)
    {
        Gate::authorize('sales.all');
        $job = $service->create($r->user()->id, $r->validated()['fileName'], $r->validated()['content']);

        return response()->json(['data' => $job->publicData()], 201);
    }

    public function batches(ApiRequest $r, ImportJobRecord $import, ManageImports $service)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->append($r->user()->id, $import->id, $r->validated()['content'])->publicData()]);
    }

    public function validate(ApiRequest $r, ImportJobRecord $import, ImportRepository $imports)
    {
        Gate::authorize('sales.all');
        $job = $imports->findJob($import->id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if (! in_array($job->status, [ImportJobStatus::DRAFT, ImportJobStatus::READY], true)) {
            throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat divalidasi.', 409);
        }
        ValidateImportJob::dispatch($import->id, $r->user()->id);

        return response()->json(['message' => 'Validasi import sedang berjalan.', 'data' => ['id' => $import->id]], 202);
    }

    public function show(ImportJobRecord $import, ManageImports $service, ImportRepository $imports)
    {
        Gate::authorize('sales.all');
        $job = $imports->findJob($import->id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);

        return response()->json(['data' => [...$job->publicData(), 'reconciliation' => $service->reconcile($job->id)]]);
    }

    public function rows(ApiRequest $r, ImportJobRecord $import, ImportRepository $repo)
    {
        Gate::authorize('sales.all');
        $page = $r->integer('page', 1);
        $size = min(100, max(1, $r->integer('pageSize', 25)));
        $result = $repo->page($import->id, $r->input('status'), $page, $size);

        return response()->json(['data' => collect($result['rows'])->map(fn ($row) => $row->publicData()),
            'page' => $page, 'pageSize' => $size, 'totalItems' => $result['total'],
            'totalPages' => (int) ceil($result['total'] / $size)]);
    }

    public function errors(ImportJobRecord $import, ManageImports $service)
    {
        Gate::authorize('sales.all');

        return response($service->errorsCsv($import->id), 200, ['Content-Type' => 'text/csv']);
    }

    public function review(ApiRequest $r, ImportJobRecord $import, ManageImports $service)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->review($r->user()->id, $import->id, $r->validated()['reviews'])->publicData()]);
    }

    public function commit(ApiRequest $r, ImportJobRecord $import, ImportRepository $imports)
    {
        Gate::authorize('sales.all');
        $job = $imports->findJob($import->id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        if (! in_array($job->status, [ImportJobStatus::READY, ImportJobStatus::COMMITTING], true)) {
            throw new BusinessRule('IMPORT_LOCKED', 'Job tidak dalam status yang dapat di-commit.', 409);
        }
        CommitImportJob::dispatch($import->id, $r->user()->id);

        return response()->json(['message' => 'Commit import sedang berjalan.', 'data' => ['id' => $import->id]], 202);
    }

    public function cancel(ImportJobRecord $import, ManageImports $service, ApiRequest $r)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->cancel($r->user()->id, $import->id)->publicData()]);
    }
}
