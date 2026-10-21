<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KETELITIAN NILAI PERSEDIAAN (audit 21 Okt 2026, Paket 4). Harga satuan lapisan FIFO dulu disimpan 2 desimal &
 * terpotong: 3 DUS @50.000 (72 pcs) → 2.083,33/pcs → 149.999,76, sehingga Rp 0,24 tertinggal di akun Persediaan
 * selamanya, dan selisih kecil seperti itu menumpuk di ±2.800 barang.
 *
 *  • `nilai_sisa` = nilai rupiah yang masih tersisa di lapisan itu. Pengambilan sebagian = porsinya (dibulatkan),
 *    pengambilan TERAKHIR = sisa nilainya persis → selama hidup lapisan, nilai keluar = nilai masuk, tanpa sisa.
 *  • `harga_satuan` menjadi 6 desimal (hanya untuk tampilan & rincian).
 * Isian awal: lapisan yang belum tersentuh = nilai mutasi masuknya (persis); yang sudah terpakai sebagian = sisa
 * kuantiti × harga lama (perilaku lama, selisih sen-nya tetap seperti sebelum migrasi ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE lapisan_persediaan ALTER COLUMN harga_satuan TYPE numeric(18,6)');
        DB::statement('ALTER TABLE lapisan_persediaan ADD COLUMN nilai_sisa numeric(18,2) NOT NULL DEFAULT 0');
        DB::statement('UPDATE lapisan_persediaan l SET nilai_sisa = CASE
                WHEN l.kuantiti_sisa = l.kuantiti_awal THEN COALESCE((SELECT m.nilai FROM mutasi_persediaan m WHERE m.id = l.mutasi_id), ROUND(l.kuantiti_sisa * l.harga_satuan, 2))
                ELSE ROUND(l.kuantiti_sisa * l.harga_satuan, 2) END');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE lapisan_persediaan DROP COLUMN nilai_sisa');
        DB::statement('ALTER TABLE lapisan_persediaan ALTER COLUMN harga_satuan TYPE numeric(18,2)');
    }
};
