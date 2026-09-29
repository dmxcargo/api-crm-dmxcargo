<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->uuid('closing_owner_id')->nullable();
            $t->foreign('closing_owner_id')->references('id')->on('users')->restrictOnDelete();
            $t->index('closing_owner_id');
        });
        DB::statement("UPDATE deals SET closing_owner_id = (SELECT owner_user_id FROM prospects WHERE prospects.id = deals.prospect_id) WHERE status IN ('WON','LOST') AND closing_owner_id IS NULL");
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $t) {
            $t->dropForeign(['closing_owner_id']);
            $t->dropColumn('closing_owner_id');
        });
    }
};
