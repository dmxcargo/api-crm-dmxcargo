<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ArchiveBuildJob;
use App\Sales\Application\ManageArchives;
use App\Sales\Domain\ArchiveRepository;
use App\Sales\Infrastructure\ArchiveJobRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class ArchiveController
{
    public function preview(ApiRequest $r, ManageArchives $service)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->preview($r->validated())]);
    }

    public function store(ApiRequest $r, ManageArchives $service)
    {
        Gate::authorize('sales.all');
        $job = $service->create($r->user()->id, $r->validated());
        ArchiveBuildJob::dispatch($job->id);

        return response()->json(['data' => $job->publicData()], 202);
    }

    public function show(ArchiveJobRecord $archive, ArchiveRepository $repos)
    {
        Gate::authorize('sales.all');
        $job = $repos->find($archive->id) ?? throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);

        return response()->json(['data' => $job->publicData()]);
    }

    public function verify(ArchiveJobRecord $archive, ManageArchives $service, ApiRequest $r)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->verify($r->user()->id, $archive->id)]);
    }

    public function copy(ArchiveJobRecord $archive, ManageArchives $service, ApiRequest $r)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->secondCopy($r->user()->id, $archive->id)->publicData()]);
    }

    public function restore(ArchiveJobRecord $archive, ManageArchives $service, ApiRequest $r)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->restore($r->user()->id, $archive->id)]);
    }

    public function staging(ApiRequest $r, ArchiveJobRecord $archive, ArchiveRepository $repos)
    {
        Gate::authorize('sales.all');
        $page = $r->integer('page', 1);
        $size = min(100, max(1, $r->integer('pageSize', 25)));
        $result = $repos->stagingPage($archive->id, $r->input('status'), $page, $size);

        return response()->json(['data' => $result['rows'], 'page' => $page, 'pageSize' => $size,
            'totalItems' => $result['total'], 'totalPages' => (int) ceil($result['total'] / $size)]);
    }

    public function purge(ApiRequest $r, ArchiveJobRecord $archive, ManageArchives $service)
    {
        Gate::authorize('sales.all');

        return response()->json(['data' => $service->purge($r->user()->id, $archive->id,
            (bool) $r->validated()['approved'])->publicData()]);
    }
}
