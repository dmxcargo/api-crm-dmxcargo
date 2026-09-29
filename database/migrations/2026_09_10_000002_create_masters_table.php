<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_sources', function (Blueprint $t) {
            $t->string('code', 40)->primary();
            $t->string('label', 100);
            $t->boolean('is_active')->default(true);
        });
        Schema::create('industries', function (Blueprint $t) {
            $t->string('code', 40)->primary();
            $t->string('label', 100);
            $t->boolean('is_active')->default(true);
        });
        Schema::create('lost_reasons', function (Blueprint $t) {
            $t->string('code', 40)->primary();
            $t->string('label', 100);
            $t->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lost_reasons');
        Schema::dropIfExists('industries');
        Schema::dropIfExists('prospect_sources');
    }
};
