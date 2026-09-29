<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedSmallInteger('schema_version')->default(1);
            $t->string('source_system', 40)->default('LEGACY_XLSX');
            $t->char('file_sha256', 64);
            $t->string('file_name', 255);
            $t->string('status', 20)->default('DRAFT');
            $t->unsignedInteger('total_rows')->default(0);
            $t->json('counters')->nullable();
            $t->uuid('created_by');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->index('file_sha256');
            $t->index('status');
        });
        DB::statement("ALTER TABLE import_jobs ADD CONSTRAINT import_jobs_status CHECK (status IN ('DRAFT','VALIDATING','READY','COMMITTING','DONE','CANCELLED'))");

        Schema::create('import_rows', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('job_id');
            $t->unsignedInteger('row_number');
            $t->json('raw');
            $t->json('normalized')->nullable();
            $t->string('status', 20)->default('INVALID');
            $t->json('codes')->nullable();
            $t->string('decision', 30)->nullable();
            $t->json('approved_fields')->nullable();
            $t->uuid('prospect_id')->nullable();
            $t->text('result')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('job_id')->references('id')->on('import_jobs')->cascadeOnDelete();
            $t->foreign('prospect_id')->references('id')->on('prospects')->nullOnDelete();
            $t->unique(['job_id', 'row_number']);
            $t->index(['job_id', 'status']);
        });
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_status CHECK (status IN ('VALID','WARNING','INVALID','DUPLICATE','IMPORTED','SKIPPED'))");
        DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_rows_decision CHECK (decision IS NULL OR decision IN ('SKIP','CREATE_NEW','UPDATE_EXISTING','MERGE_SELECTED_FIELDS'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_jobs');
    }
};
