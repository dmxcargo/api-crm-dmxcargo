<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deal yang sudah WON tapi payment_status-nya masih NULL tidak terhitung
     * di dashboard billing (masuk "Tanpa Status"). Won tanpa status bayar
     * adalah piutang baru, jadi default-kan ke BELUM_DITAGIH.
     * Hanya menyentuh baris yang masih NULL agar status manual tidak tertimpa.
     */
    public function up(): void
    {
        DB::table('deals')
            ->where('status', 'WON')
            ->whereNull('payment_status')
            ->update(['payment_status' => 'BELUM_DITAGIH']);

        DB::table('prospects')
            ->where('stage', 'WON')
            ->whereNull('payment_status')
            ->whereNull('deleted_at')
            ->update(['payment_status' => 'BELUM_DITAGIH']);
    }

    public function down(): void
    {
        // Backfill data satu arah — tidak dibalikkan agar status yang sudah
        // diproses billing setelah migrasi tidak ikut terhapus.
    }
};
