<?php

namespace App\Shared\Infrastructure;

use App\Shared\Domain\IdempotencyRecord;
use App\Shared\Domain\IdempotencyStore;
use Illuminate\Support\Facades\DB;

final class PostgresIdempotencyStore implements IdempotencyStore
{
    public function find(string $key, string $actorId, string $route): ?IdempotencyRecord
    {
        $row = DB::table('idempotency_keys')
            ->where('key', $key)->where('actor_user_id', $actorId)->where('route', $route)
            ->where('expires_at', '>', now())->first();

        return $row ? new IdempotencyRecord($row->key, $row->actor_user_id, $row->route, $row->payload_hash, $row->resource_id) : null;
    }

    public function save(IdempotencyRecord $record, int $ttlHours): void
    {
        DB::table('idempotency_keys')->updateOrInsert(
            ['key' => $record->key, 'actor_user_id' => $record->actorId, 'route' => $record->route],
            ['payload_hash' => $record->payloadHash, 'resource_id' => $record->resourceId,
                'expires_at' => now()->addHours($ttlHours), 'created_at' => now()]
        );
    }

    public function prune(): int
    {
        return DB::table('idempotency_keys')->where('expires_at', '<=', now())->delete();
    }
}
