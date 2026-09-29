<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Follow-up yang dibuat sebelum sinkronisasi otomatis Next FU
     * (ManageFollowUps::refreshNextFollowUp) membuat prospects.next_follow_up_at
     * kosong padahal task terbukanya ada. Isi dengan jadwal terbuka paling
     * awal per prospek. Hanya menyentuh yang masih NULL agar isian manual
     * (mis. via aktivitas) tidak tertimpa.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE prospects p
            SET next_follow_up_at = sub.min_at
            FROM (
                SELECT prospect_id, MIN(scheduled_at) AS min_at
                FROM follow_ups
                WHERE completed_at IS NULL
                GROUP BY prospect_id
            ) sub
            WHERE p.id = sub.prospect_id
              AND p.next_follow_up_at IS NULL
              AND p.deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        // Backfill data satu arah — tidak dibalikkan agar jadwal yang sudah
        // dihitung ulang setelah migrasi tidak ikut terhapus.
    }
};
