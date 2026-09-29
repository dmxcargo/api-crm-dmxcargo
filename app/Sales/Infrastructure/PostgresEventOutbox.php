<?php

namespace App\Sales\Infrastructure;

use App\Sales\Domain\EventOutbox;
use App\Sales\Domain\IntegrationEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PostgresEventOutbox implements EventOutbox
{
    public function publish(IntegrationEvent $event): void
    {
        DB::table('outbox_events')->insert(['id' => (string) Str::uuid(), 'event_type' => $event->type,
            'entity_type' => $event->entityType, 'entity_id' => $event->entityId, 'payload' => json_encode($event->payload),
            'actor_user_id' => $event->actorId, 'schema_version' => 1, 'occurred_at' => now()]);
    }
}
