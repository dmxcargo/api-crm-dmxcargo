<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('event_type', 80);
            $t->string('entity_type', 50);
            $t->uuid('entity_id')->nullable();
            $t->jsonb('payload');
            $t->uuid('actor_user_id')->nullable();
            $t->smallInteger('schema_version')->default(1);
            $t->timestampTz('occurred_at');
            $t->timestampTz('dispatched_at')->nullable();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['dispatched_at', 'occurred_at']);
            $t->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
