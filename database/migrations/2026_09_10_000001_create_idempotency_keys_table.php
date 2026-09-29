<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->string('key', 64);
            $t->uuid('actor_user_id');
            $t->string('route', 120);
            $t->char('payload_hash', 64);
            $t->uuid('resource_id')->nullable();
            $t->timestampTz('expires_at');
            $t->timestampTz('created_at');
            $t->primary(['key', 'actor_user_id', 'route']);
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
