<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 20)->default('PROSPECT');
            $t->json('filters')->nullable();
            $t->string('status', 20)->default('QUEUED');
            $t->json('manifest')->nullable();
            $t->string('path', 255)->nullable();
            $t->char('sha256', 64)->nullable();
            $t->string('second_copy_path', 255)->nullable();
            $t->boolean('second_copy_verified')->default(false);
            $t->boolean('purged')->default(false);
            $t->text('result')->nullable();
            $t->uuid('created_by');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->index('status');
        });
        DB::statement("ALTER TABLE archive_jobs ADD CONSTRAINT archive_jobs_status CHECK (status IN ('QUEUED','PROCESSING','READY','FAILED'))");

        Schema::create('archive_staging', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('job_id');
            $t->unsignedInteger('row_number');
            $t->json('raw');
            $t->string('status', 20)->default('STAGED');
            $t->timestampsTz();
            $t->foreign('job_id')->references('id')->on('archive_jobs')->cascadeOnDelete();
            $t->unique(['job_id', 'row_number']);
            $t->index(['job_id', 'status']);
        });
        DB::statement("ALTER TABLE archive_staging ADD CONSTRAINT archive_staging_status CHECK (status IN ('STAGED','CONFLICT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_staging');
        Schema::dropIfExists('archive_jobs');
    }
};
