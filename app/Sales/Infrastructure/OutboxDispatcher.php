<?php

namespace App\Sales\Infrastructure;

use Illuminate\Support\Facades\DB;

final class OutboxDispatcher
{
    public function run(int $limit = 100): int
    {
        return DB::transaction(function () use ($limit) {
            $sent = 0;
            $events = DB::table('outbox_events')->whereNull('dispatched_at')
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
                ->orderBy('occurred_at')->limit($limit)->lockForUpdate()->get();
            foreach ($events as $event) {
                DB::table('outbox_events')->where('id', $event->id)->update(['dispatched_at' => now(),
                    'attempts' => $event->attempts + 1, 'next_attempt_at' => null, 'last_error' => null]);
                $sent++;
            }

            return $sent;
        });
    }
}
