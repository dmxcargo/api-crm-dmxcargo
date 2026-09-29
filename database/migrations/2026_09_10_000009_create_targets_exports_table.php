<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_targets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('user_id');
            $t->string('period_type', 10);
            $t->string('period', 7);
            $t->decimal('target_value', 18, 2);
            $t->uuid('created_by');
            $t->uuid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['user_id', 'period_type', 'period']);
            $t->index(['period_type', 'period']);
        });
        DB::statement("ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_period CHECK (period_type IN ('MONTH','YEAR'))");
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_value CHECK (target_value >= 0)');
        DB::statement('ALTER TABLE sales_targets ADD CONSTRAINT sales_targets_version CHECK (version > 0)');

        Schema::create('export_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type', 20);
            $t->json('filters')->nullable();
            $t->json('columns')->nullable();
            $t->string('status', 20)->default('QUEUED');
            $t->string('path', 255)->nullable();
            $t->timestampTz('expires_at')->nullable();
            $t->text('result')->nullable();
            $t->uuid('created_by');
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->index('status');
        });
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_type CHECK (type IN ('PROSPECT','PERFORMANCE'))");
        DB::statement("ALTER TABLE export_jobs ADD CONSTRAINT export_jobs_status CHECK (status IN ('QUEUED','PROCESSING','DONE','FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('sales_targets');
    }
};
