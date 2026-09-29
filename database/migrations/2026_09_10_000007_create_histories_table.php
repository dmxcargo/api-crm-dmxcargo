<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stage_histories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('prospect_id');
            $t->string('from_stage', 20)->nullable();
            $t->string('to_stage', 20);
            $t->uuid('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign('prospect_id')->references('id')->on('prospects')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['prospect_id', 'created_at']);
        });

        Schema::create('payment_status_histories', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('deal_id');
            $t->string('from_status', 20)->nullable();
            $t->string('to_status', 20);
            $t->uuid('actor_user_id');
            $t->timestampTz('created_at');
            $t->foreign('deal_id')->references('id')->on('deals')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['deal_id', 'created_at']);
        });

        Schema::table('outbox_events', function (Blueprint $t) {
            $t->integer('attempts')->default(0);
            $t->timestampTz('next_attempt_at')->nullable();
            $t->text('last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_status_histories');
        Schema::dropIfExists('stage_histories');
        Schema::table('outbox_events', function (Blueprint $t) {
            $t->dropColumn(['attempts', 'next_attempt_at', 'last_error']);
        });
    }
};
