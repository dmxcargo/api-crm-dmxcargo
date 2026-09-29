<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('legacy_id', 40)->nullable()->unique();
            $t->integer('legacy_source_row')->nullable();
            $t->date('entry_date')->default(DB::raw('CURRENT_DATE'));
            $t->string('account_name', 200);
            $t->string('pic_name', 150)->nullable();
            $t->string('pic_position', 100)->nullable();
            $t->string('phone_raw', 50);
            $t->string('phone_normalized', 30)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 100)->nullable();
            $t->string('industry_code', 40)->nullable();
            $t->string('source_code', 40);
            $t->uuid('owner_user_id');
            $t->string('stage', 20)->default('NEW');
            $t->string('priority', 10);
            $t->text('last_progress')->nullable();
            $t->timestampTz('next_follow_up_at')->nullable();
            $t->string('next_action', 250)->nullable();
            $t->decimal('potential_value', 18, 2)->nullable();
            $t->string('payment_status', 20)->nullable();
            $t->string('customer_type', 10)->nullable();
            $t->text('notes')->nullable();
            $t->uuid('created_by');
            $t->uuid('updated_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampTz('archived_at')->nullable();
            $t->timestampTz('deleted_at')->nullable();
            $t->timestampsTz();
            $t->foreign('industry_code')->references('code')->on('industries')->restrictOnDelete();
            $t->foreign('source_code')->references('code')->on('prospect_sources')->restrictOnDelete();
            $t->foreign('owner_user_id')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $t->index(['owner_user_id', 'stage']);
            $t->index(['priority', 'next_follow_up_at']);
            $t->index('phone_normalized');
            $t->index('entry_date');
        });
        DB::statement("ALTER TABLE prospects ADD CONSTRAINT prospects_stage CHECK (stage IN ('NEW','FOLLOW_UP','OPPORTUNITY','QUOTATION','NEGOTIATION','CLOSING','WON','LOST','MAINTENANCE'))");
        DB::statement("ALTER TABLE prospects ADD CONSTRAINT prospects_priority CHECK (priority IN ('HOT','WARM','COLD'))");
        DB::statement("ALTER TABLE prospects ADD CONSTRAINT prospects_customer_type CHECK (customer_type IS NULL OR customer_type IN ('B2B','B2C'))");
        DB::statement("ALTER TABLE prospects ADD CONSTRAINT prospects_payment_status CHECK (payment_status IS NULL OR payment_status IN ('BELUM_DITAGIH','INVOICE','TERTAGIH','LUNAS','OVERDUE'))");
        DB::statement('ALTER TABLE prospects ADD CONSTRAINT prospects_money CHECK (potential_value IS NULL OR potential_value >= 0)');
        DB::statement('ALTER TABLE prospects ADD CONSTRAINT prospects_version CHECK (version > 0)');
        DB::statement('ALTER TABLE prospects ADD CONSTRAINT prospects_email_lower CHECK (email IS NULL OR email = lower(email))');

        Schema::create('prospect_contacts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('prospect_id');
            $t->string('name', 150);
            $t->string('position', 100)->nullable();
            $t->string('phone_raw', 50)->nullable();
            $t->string('phone_normalized', 30)->nullable();
            $t->string('email', 254)->nullable();
            $t->boolean('is_primary')->default(false);
            $t->timestampsTz();
            $t->foreign('prospect_id')->references('id')->on('prospects')->restrictOnDelete();
            $t->index('prospect_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_contacts');
        Schema::dropIfExists('prospects');
    }
};
