<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('prospect_id');
            $t->string('type', 20);
            $t->uuid('actor_user_id');
            $t->uuid('owner_user_id');
            $t->timestampTz('activity_at');
            $t->boolean('answered')->nullable();
            $t->integer('duration_minutes')->nullable();
            $t->string('attendance_status', 20)->nullable();
            $t->string('completion_status', 20)->nullable();
            $t->text('notes')->nullable();
            $t->timestampTz('created_at');
            $t->foreign('prospect_id')->references('id')->on('prospects')->restrictOnDelete();
            $t->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['prospect_id', 'activity_at']);
            $t->index(['type', 'activity_at']);
        });
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_type CHECK (type IN ('CALL','WHATSAPP','EMAIL','MEETING','VISIT','NOTE'))");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_attendance CHECK (attendance_status IS NULL OR attendance_status IN ('HADIR','TIDAK_HADIR','TERJADWAL'))");
        DB::statement("ALTER TABLE activities ADD CONSTRAINT activities_completion CHECK (completion_status IS NULL OR completion_status IN ('SELESAI','BATAL','TERJADWAL'))");
        DB::statement('ALTER TABLE activities ADD CONSTRAINT activities_duration CHECK (duration_minutes IS NULL OR duration_minutes >= 0)');

        Schema::create('follow_ups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('prospect_id');
            $t->uuid('assignee_user_id');
            $t->uuid('creator_user_id');
            $t->timestampTz('scheduled_at');
            $t->timestampTz('completed_at')->nullable();
            $t->string('task_priority', 20)->default('SEDANG');
            $t->string('task_status', 20)->default('BELUM_DIMULAI');
            $t->text('description');
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('prospect_id')->references('id')->on('prospects')->restrictOnDelete();
            $t->foreign('assignee_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('creator_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->index(['assignee_user_id', 'scheduled_at']);
            $t->index(['prospect_id', 'scheduled_at']);
        });
        DB::statement("ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_priority CHECK (task_priority IN ('PENTING','SEDANG','RENDAH'))");
        DB::statement("ALTER TABLE follow_ups ADD CONSTRAINT follow_ups_status CHECK (task_status IN ('BELUM_DIMULAI','BERJALAN','SELESAI','BATAL'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
        Schema::dropIfExists('activities');
    }
};
