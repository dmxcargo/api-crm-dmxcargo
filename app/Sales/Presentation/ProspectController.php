<?php

namespace App\Sales\Presentation;

use App\Sales\Application\ManageProspects;
use App\Sales\Domain\PhoneNumber;
use App\Sales\Infrastructure\EloquentProspects;
use App\Sales\Infrastructure\ProspectRecord;
use App\Shared\Domain\BusinessRule;
use App\Shared\Domain\IdempotencyRecord;
use App\Shared\Domain\IdempotencyStore;
use App\Shared\Infrastructure\OwnedRecords;
use App\Shared\Presentation\ApiRequest;
use Illuminate\Support\Facades\Gate;

final class ProspectController
{
    public function index(ApiRequest $r, EloquentProspects $prospects, OwnedRecords $owned)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $q = $owned->scope(ProspectRecord::query(), $r->user())->whereNull('deleted_at');
        if (! $r->boolean('includeArchived')) {
            $q->whereNull('archived_at');
        }
        if ($r->filled('q')) {
            $term = '%'.str_replace(['%', '_'], '', $r->input('q')).'%';
            $q->where(fn ($w) => $w->where('account_name', 'ilike', $term)->orWhere('pic_name', 'ilike', $term)
                ->orWhere('phone_raw', 'like', $term)->orWhere('email', 'ilike', $term)->orWhere('legacy_id', 'ilike', $term));
        }
        if ($r->filled('owner') && $r->user()->permits('sales.all')) {
            $q->where('owner_user_id', $r->input('owner'));
        }
        foreach (['stage', 'priority', 'city', 'sourceCode' => 'source_code'] as $in => $col) {
            $key = is_string($in) ? $in : $col;
            if ($r->filled($key)) {
                $q->where($col, $r->input($key));
            }
        }
        if ($r->filled('dateFrom')) {
            $q->whereDate('entry_date', '>=', $r->input('dateFrom'));
        }
        if ($r->filled('dateTo')) {
            $q->whereDate('entry_date', '<=', $r->input('dateTo'));
        }
        $sorts = ['entryDate' => 'entry_date', 'accountName' => 'account_name', 'potentialValue' => 'potential_value',
            'updatedAt' => 'updated_at', 'nextFollowUpAt' => 'next_follow_up_at'];
        [$field, $dir] = array_pad(explode(':', (string) $r->input('sort', 'entryDate:desc'), 2), 2, null);
        $q->orderBy($sorts[$field] ?? 'entry_date', $dir === 'asc' ? 'asc' : 'desc')->orderBy('id');
        $p = $q->paginate($r->integer('pageSize', 25));

        return response()->json(['data' => collect($p->items())->map(fn ($row) => $prospects->map($row)->publicData()),
            'page' => $p->currentPage(), 'pageSize' => $p->perPage(), 'totalItems' => $p->total(), 'totalPages' => $p->lastPage()]);
    }

    public function show(ProspectRecord $prospect, EloquentProspects $prospects)
    {
        Gate::authorize('view', $prospect);
        $this->ensureAlive($prospect);
        $data = $prospects->map($prospect)->publicData();
        $data['contacts'] = $prospect->contacts->map(fn ($c) => $prospects->mapContact($c)->publicData())->values();

        return response()->json(['data' => $data]);
    }

    public function store(ApiRequest $r, ManageProspects $service, IdempotencyStore $keys, EloquentProspects $prospects)
    {
        Gate::authorize('create', ProspectRecord::class);
        $key = $this->idempotencyKey($r);
        $replayed = $key && $this->isReplay($keys, $r, $key);
        $account = $service->create($r->user()->id, $r->validated(), $key);

        return response()->json(['data' => $account->publicData()], $replayed ? 200 : 201)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }

    public function update(ApiRequest $r, ProspectRecord $prospect, ManageProspects $service)
    {
        Gate::authorize('update', $prospect);
        $this->ensureAlive($prospect);

        return response()->json(['data' => $service->update($r->user()->id, $prospect->id, $r->validated())->publicData()]);
    }

    public function destroy(ProspectRecord $prospect, ManageProspects $service, ApiRequest $r)
    {
        Gate::authorize('delete', $prospect);
        $this->ensureAlive($prospect);
        $service->destroy($r->user()->id, $prospect->id);

        return response()->json(['message' => 'Prospect telah dihapus.']);
    }

    public function assign(ApiRequest $r, ProspectRecord $prospect, ManageProspects $service)
    {
        Gate::authorize('assign', $prospect);
        $this->ensureAlive($prospect);

        return response()->json(['data' => $service->assign($r->user()->id, $prospect->id, $r->validated()['ownerUserId'])->publicData()]);
    }

    public function stage(ApiRequest $r, ProspectRecord $prospect, ManageProspects $service)
    {
        Gate::authorize('update', $prospect);
        $this->ensureAlive($prospect);

        return response()->json(['data' => $service->moveStage($r->user()->id, $prospect->id, $r->validated(), $r->user()->permits('sales.all'))->publicData()]);
    }

    public function archive(ApiRequest $r, ProspectRecord $prospect, ManageProspects $service)
    {
        Gate::authorize('update', $prospect);
        $this->ensureAlive($prospect);

        return response()->json(['data' => $service->archive($r->user()->id, $prospect->id, $r->boolean('archived', true))->publicData()]);
    }

    public function duplicates(ApiRequest $r, EloquentProspects $prospects)
    {
        Gate::authorize('viewAny', ProspectRecord::class);
        $list = $prospects->candidates($r->input('legacyId'), $r->input('phoneNormalized') ?? $this->normalize($r->input('phone')),
            $r->input('email'), $r->input('accountName'), $r->input('city'));
        if (! $r->user()->permits('sales.all')) {
            $mine = ProspectRecord::where('owner_user_id', $r->user()->id)->pluck('id')->all();
            $list = array_values(array_filter($list, fn ($c) => in_array($c->prospectId, $mine, true)));
        }

        return response()->json(['data' => collect($list)->map(fn ($c) => $c->publicData())]);
    }

    public function addContact(ApiRequest $r, ProspectRecord $prospect, ManageProspects $service)
    {
        Gate::authorize('update', $prospect);
        $this->ensureAlive($prospect);

        return response()->json(['data' => $service->addContact($r->user()->id, $prospect->id, $r->validated())->publicData()], 201);
    }

    public function removeContact(ProspectRecord $prospect, string $contactId, ManageProspects $service, ApiRequest $r)
    {
        Gate::authorize('update', $prospect);
        $this->ensureAlive($prospect);
        $service->removeContact($r->user()->id, $prospect->id, $contactId);

        return response()->json(['message' => 'Kontak telah dihapus.']);
    }

    private function ensureAlive(ProspectRecord $prospect): void
    {
        if ($prospect->deleted_at !== null) {
            throw new BusinessRule('NOT_FOUND', 'Data tidak ditemukan.', 404);
        }
    }

    private function normalize(?string $phone): ?string
    {
        return $phone ? PhoneNumber::normalize($phone) : null;
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
        $hit = $keys->find($key, $r->user()->id, 'prospects.store');

        return $hit && $hit->payloadHash === IdempotencyRecord::hash($r->validated()) && $hit->resourceId !== null;
    }
}
