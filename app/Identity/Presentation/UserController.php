<?php

namespace App\Identity\Presentation;

use App\Identity\Application\ManageAccounts;
use App\Identity\Infrastructure\EloquentAccounts;
use App\Identity\Infrastructure\UserRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\IdempotencyRecord;
use App\Shared\Domain\IdempotencyStore;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class UserController
{
    public function index(ApiRequest $r, EloquentAccounts $accounts)
    {
        Gate::authorize('viewAny', UserRecord::class);
        $q = UserRecord::orderBy('id');
        if ($r->filled('q')) {
            $term = '%'.str_replace(['%', '_'], '', $r->input('q')).'%';
            $q->where(fn ($w) => $w->where('name', 'ilike', $term)
                ->orWhere('normalized_username', 'ilike', $term)
                ->orWhere('normalized_email', 'ilike', $term));
        }
        $p = $q->paginate($r->integer('pageSize', 25));

        return response()->json(['data' => collect($p->items())->map(fn ($u) => $accounts->map($u)->publicData()),
            'page' => $p->currentPage(), 'pageSize' => $p->perPage(), 'totalItems' => $p->total(), 'totalPages' => $p->lastPage()]);
    }

    public function show(UserRecord $user, EloquentAccounts $accounts)
    {
        Gate::authorize('view', $user);

        return response()->json(['data' => $accounts->map($user)->publicData()]);
    }

    public function store(ApiRequest $r, ManageAccounts $service, IdempotencyStore $keys)
    {
        Gate::authorize('create', UserRecord::class);
        $key = $this->idempotencyKey($r);
        $replayed = $key && $this->isReplay($keys, $r, $key);
        $account = $service->create($r->user()->id, $r->validated(), $key);

        return response()->json(['data' => $account->publicData()], $replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }

    public function update(ApiRequest $r, UserRecord $user, ManageAccounts $service)
    {
        Gate::authorize('update', $user);

        return response()->json(['data' => $service->update($r->user()->id, $user->id, $r->validated())->publicData()]);
    }

    public function revoke(ApiRequest $r, UserRecord $user, ManageAccounts $service)
    {
        Gate::authorize('update', $user);
        $service->revoke($r->user()->id, $user->id);

        return response()->json(['message' => 'Seluruh sesi pengguna telah dicabut.']);
    }

    private function idempotencyKey(ApiRequest $r): ?string
    {
        $key = trim((string) $r->header('Idempotency-Key', ''));
        if ($key === '') {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9\-_.]{1,64}$/', $key)) {
            throw new BusinessRule('INVALID_IDEMPOTENCY_KEY', 'Idempotency-Key tidak valid. Gunakan 1-64 karakter alfanumerik, tanda hubung, garis bawah atau titik.', 422);
        }

        return $key;
    }

    private function isReplay(IdempotencyStore $keys, ApiRequest $r, string $key): bool
    {
        $hit = $keys->find($key, $r->user()->id, 'users.store');

        return $hit && $hit->payloadHash === IdempotencyRecord::hash($r->validated()) && $hit->resourceId !== null;
    }
}
