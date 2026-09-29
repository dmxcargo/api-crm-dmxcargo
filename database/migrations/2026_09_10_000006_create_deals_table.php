<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('account_name', 200);
            $t->string('phone_raw', 50)->nullable();
            $t->string('phone_normalized', 30)->nullable();
            $t->string('email', 254)->nullable();
            $t->string('city', 100)->nullable();
            $t->string('province', 100)->nullable();
            $t->string('industry_code', 40)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('industry_code')->references('code')->on('industries')->restrictOnDelete();
            $t->index('phone_normalized');
            $t->index('account_name');
        });

        Schema::create('deals', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('prospect_id');
            $t->uuid('customer_id')->nullable();
            $t->string('deal_number', 40)->nullable()->unique();
            $t->string('quotation_number', 80)->nullable();
            $t->decimal('potential_value', 18, 2)->nullable();
            $t->decimal('quotation_value', 18, 2)->nullable();
            $t->decimal('closing_value', 18, 2)->nullable();
            $t->date('closing_date')->nullable();
            $t->string('status', 10)->default('OPEN');
            $t->string('lost_reason_code', 40)->nullable();
            $t->string('payment_status', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestampsTz();
            $t->foreign('prospect_id')->references('id')->on('prospects')->restrictOnDelete();
            $t->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $t->foreign('lost_reason_code')->references('code')->on('lost_reasons')->restrictOnDelete();
            $t->index(['status', 'closing_date']);
        });
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_status CHECK (status IN ('OPEN','WON','LOST'))");
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_money CHECK (potential_value IS NULL OR potential_value >= 0)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_quotation_money CHECK (quotation_value IS NULL OR quotation_value >= 0)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_closing_money CHECK (closing_value IS NULL OR closing_value >= 0)');
        DB::statement('ALTER TABLE deals ADD CONSTRAINT deals_version CHECK (version > 0)');
        DB::statement("ALTER TABLE deals ADD CONSTRAINT deals_payment CHECK (payment_status IS NULL OR payment_status IN ('BELUM_DITAGIH','INVOICE','TERTAGIH','LUNAS','OVERDUE'))");
        DB::statement("CREATE UNIQUE INDEX deals_one_open ON deals (prospect_id) WHERE status = 'OPEN'");
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
        Schema::dropIfExists('customers');
    }
};
