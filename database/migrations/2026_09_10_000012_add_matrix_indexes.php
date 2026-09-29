<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospects', function (Blueprint $t) {
            $t->index(['owner_user_id', 'entry_date']);
        });
        Schema::table('deals', function (Blueprint $t) {
            $t->index(['prospect_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->dropIndex(['prospect_id', 'status']);
        });
        Schema::table('prospects', function (Blueprint $t) {
            $t->dropIndex(['owner_user_id', 'entry_date']);
        });
    }
};
