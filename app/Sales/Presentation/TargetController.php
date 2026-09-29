<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ManageTargets;
use App\Sales\Domain\TargetRepository;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class TargetController
{
    public function index(ApiRequest $r, TargetRepository $targets)
    {
        Gate::authorize('targets.read');
        $userId = $r->user()->permits('sales.all') ? $r->input('userId') : $r->user()->id;
        $list = $targets->list($userId, $r->input('periodType'), $r->input('period'));

        return response()->json(['data' => collect($list)->map(fn ($t) => $t->publicData())]);
    }

    public function store(ApiRequest $r, ManageTargets $service)
    {
        Gate::authorize('targets.manage');

        return response()->json(['data' => $service->create($r->user()->id, $r->validated())->publicData()], 201);
    }

    public function update(ApiRequest $r, string $target, ManageTargets $service)
    {
        Gate::authorize('targets.manage');

        return response()->json(['data' => $service->update($r->user()->id, $target, $r->validated())->publicData()]);
    }
}
