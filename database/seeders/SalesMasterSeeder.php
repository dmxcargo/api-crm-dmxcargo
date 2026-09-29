<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class SalesMasterSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (['BANNER' => 'Banner', 'DATABASE_EST' => 'Database Est.', 'GOOGLE_ADS' => 'Google Ads',
                'LOKASI_MAPS' => 'Lokasi Maps', 'META_ADS' => 'Meta Ads', 'REKOMENDASI' => 'Rekomendasi'] as $code => $label) {
                DB::table('prospect_sources')->updateOrInsert(['code' => $code], ['label' => $label, 'is_active' => true]);
            }
            foreach (['MANUFAKTUR' => 'Manufaktur', 'RETAIL' => 'Retail / Perdagangan', 'ECOMMERCE' => 'E-commerce',
                'FNB' => 'F&B (Makanan & Minuman)', 'KONSTRUKSI' => 'Konstruksi & Material Bangunan', 'OTOMOTIF' => 'Otomotif',
                'FARMASI' => 'Farmasi & Kesehatan', 'TEKSTIL' => 'Tekstil & Garmen', 'ELEKTRONIK' => 'Elektronik',
                'PERTANIAN' => 'Pertanian & Perkebunan', 'LOGISTIK' => 'Logistik & Freight Forwarding (partner/subkontraktor)',
                'PEMERINTAHAN' => 'Pemerintahan / BUMN', 'LAINNYA' => 'Lainnya'] as $code => $label) {
                DB::table('industries')->updateOrInsert(['code' => $code], ['label' => $label, 'is_active' => true]);
            }
            foreach (['HARGA' => 'Harga tidak kompetitif', 'VENDOR_LAIN' => 'Klien memilih vendor cargo lain',
                'BUDGET_BATAL' => 'Budget klien dibatalkan/ditunda', 'RUTE_TAK_TERJANGKAU' => 'Rute/area tidak dapat dijangkau',
                'ARMADA_INTERNAL' => 'Klien beralih ke armada/logistik internal sendiri', 'PROYEK_BATAL' => 'Pengiriman/proyek dibatalkan oleh klien',
                'GHOSTING' => 'Prospek tidak merespons (ghosting)', 'LAYANAN_BURUK' => 'Riwayat layanan sebelumnya kurang memuaskan',
                'LAINNYA' => 'Lainnya'] as $code => $label) {
                DB::table('lost_reasons')->updateOrInsert(['code' => $code], ['label' => $label, 'is_active' => true]);
            }
        });
    }
}
