<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TARIF SATUAN KELUAR DARI MASTER JENIS BIAYA.
 *
 * Menaruh `tarif_satuan`, `nama_satuan`, dan `kuota_gratis` di `jenis_biaya`
 * melanggar pembagian yang sudah berlaku di sini: master itu identitas
 * akuntansi — nama, perilaku, jenjang, akun, unit — dan BESARANNYA tinggal di
 * tabelnya sendiri. Tarif biasa sudah begitu (`tarif_biaya`), tarif kegiatan
 * juga (`tarif_tagihan_lain`); hanya tarif satuan yang menyempil di master dan
 * membuat layarnya meminta angka pada baris yang tak pernah memakainya.
 *
 * `nama_satuan` ikut pindah meski ia bukan nominal: ia tak berarti apa-apa tanpa
 * tarifnya, dan layar tarif harus bisa menulis "Rp 7.000 / kg" tanpa menengok ke
 * dua tabel berbeda.
 *
 * Nilai yang sudah terlanjur diisi DIPINDAHKAN, bukan dibuang — urutannya
 * disalin dulu, baru kolomnya dibuang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tarif_pemakaian', function (Blueprint $table) {
            $table->increments('id');
            // Satu baris per layanan; tarifnya tidak berbeda antar jenjang
            // karena jenisnya sendiri sudah berjenjang (Laundry SMP, Laundry SMA).
            $table->string('kode_jenis')->unique();
            $table->decimal('tarif_satuan', 18, 2);
            $table->string('nama_satuan');
            // Boleh kosong = tak ada jatah gratis; nol berarti sama.
            $table->decimal('kuota_gratis', 12, 2)->nullable();
            $table->timestamps();

            $table->foreign('kode_jenis')->references('kode')->on('jenis_biaya')->cascadeOnDelete();
        });

        // Pindahkan yang sudah terlanjur diisi lewat layar master.
        $now = now();
        $baris = DB::table('jenis_biaya')->whereNotNull('tarif_satuan')
            ->get(['kode', 'tarif_satuan', 'nama_satuan', 'kuota_gratis']);
        foreach ($baris as $b) {
            DB::table('tarif_pemakaian')->insert([
                'kode_jenis' => $b->kode,
                'tarif_satuan' => $b->tarif_satuan,
                // Satuan wajib di tabel baru; baris lama yang belum menyebutnya
                // diberi "satuan" apa adanya agar bisa dibetulkan lewat layarnya.
                'nama_satuan' => $b->nama_satuan ?: 'satuan',
                'kuota_gratis' => $b->kuota_gratis,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->dropColumn(['tarif_satuan', 'nama_satuan', 'kuota_gratis']);
        });
    }

    public function down(): void
    {
        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->decimal('tarif_satuan', 18, 2)->nullable();
            $table->string('nama_satuan')->nullable();
            $table->decimal('kuota_gratis', 12, 2)->nullable();
        });

        foreach (DB::table('tarif_pemakaian')->get() as $t) {
            DB::table('jenis_biaya')->where('kode', $t->kode_jenis)->update([
                'tarif_satuan' => $t->tarif_satuan,
                'nama_satuan' => $t->nama_satuan,
                'kuota_gratis' => $t->kuota_gratis,
            ]);
        }

        Schema::dropIfExists('tarif_pemakaian');
    }
};
