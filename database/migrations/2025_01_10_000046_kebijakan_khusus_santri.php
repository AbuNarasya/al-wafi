<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KEBIJAKAN KHUSUS SANTRI — keringanan, potongan, beasiswa, dan sejenisnya.
 *
 * Sebelum ini keringanan hanya punya SATU bentuk: `santri.nominal_spp`, sebuah
 * angka yang ditimpa begitu saja tanpa alasan, tanpa pemberi izin, tanpa masa
 * berlaku, dan tanpa selembar surat pun. Keringanan untuk uang pangkal dan
 * daftar ulang malah tak punya tempat sama sekali.
 *
 * Satu tabel untuk semuanya, dibedakan oleh `perilaku` — kosakata yang sudah
 * dipakai seluruh aplikasi untuk menyaring jenis biaya. Menambah bentuk
 * kebijakan berikutnya karena itu tak perlu tabel baru.
 *
 * DUA SURAT WAJIB sebelum statusnya boleh menjadi `disetujui`: surat permohonan
 * dari wali dan surat persetujuan dari yayasan. Keduanya menempel lewat modul
 * lampiran yang sudah ada, dengan jenis dokumen berbeda supaya bisa dibedakan.
 *
 * BUKAN termasuk di sini: potongan gelombang beserta realisasinya di
 * `potongan_uang_pangkal`. Itu aturan HARGA yang berlaku otomatis bagi semua
 * pendaftar satu gelombang — tak seorang pun memohonkannya, jadi menuntut surat
 * permohonan untuknya hanya akan melahirkan ratusan berkas kosong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kebijakan_khusus', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_santri');

            $table->enum('jenis', ['keringanan', 'potongan', 'beasiswa', 'lainnya'])->default('keringanan');
            // Perilaku biaya yang terdampak — kosakata yang sama dengan
            // jenis_biaya & tagihan_santri.
            $table->string('perilaku');

            // nominal        → kurangi sebesar `besaran`
            // persen         → kurangi `besaran` persen
            // nominal_khusus → GANTI tarifnya dengan `besaran` (inilah bentuk
            //                  lama `santri.nominal_spp`)
            $table->enum('cara', ['nominal', 'persen', 'nominal_khusus'])->default('nominal_khusus');
            $table->decimal('besaran', 18, 2)->default(0);

            $table->string('tahun_ajaran')->nullable();
            $table->date('berlaku_mulai')->nullable();
            $table->date('berlaku_sampai')->nullable();

            $table->text('alasan');
            $table->enum('status', ['diajukan', 'disetujui', 'ditolak', 'berakhir'])->default('diajukan');

            $table->unsignedInteger('diajukan_oleh')->nullable();
            $table->timestamp('diajukan_pada')->nullable();
            $table->unsignedInteger('diputus_oleh')->nullable();
            $table->timestamp('diputus_pada')->nullable();
            $table->text('catatan_keputusan')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('diajukan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->foreign('diputus_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['id_santri', 'perilaku']);
            $table->index('status');
        });

        // Satu kebijakan BERLAKU per (santri, perilaku, tahun ajaran). Dua
        // kebijakan hidup untuk sel yang sama berarti penagihan harus menebak
        // mana yang menang — dan tebakan itu akan berbeda tiap kali.
        // COALESCE dipakai karena dua NULL dianggap berbeda oleh unique index.
        Schema::getConnection()->statement(
            "CREATE UNIQUE INDEX kebijakan_khusus_berlaku
             ON kebijakan_khusus (id_santri, perilaku, COALESCE(tahun_ajaran, '-'))
             WHERE status = 'disetujui'"
        );

        // Pindahkan nominal khusus SPP yang sudah ada. Tanpa ini, keringanan
        // yang sudah dijanjikan ke wali akan hilang diam-diam pada penagihan
        // berikutnya — kerusakan yang tak akan terlihat sampai tagihannya
        // terbit dengan angka penuh.
        foreach (DB::table('santri')->whereNotNull('nominal_spp')->get(['id', 'nominal_spp', 'keterangan_spp']) as $s) {
            DB::table('kebijakan_khusus')->insert([
                'id_santri' => $s->id,
                'jenis' => 'keringanan',
                'perilaku' => 'spp',
                'cara' => 'nominal_khusus',
                'besaran' => $s->nominal_spp,
                'alasan' => $s->keterangan_spp ?: 'Dipindahkan dari nominal khusus SPP (sebelum modul Kebijakan Khusus ada).',
                'status' => 'disetujui',
                'diajukan_pada' => now(),
                'diputus_pada' => now(),
                'catatan_keputusan' => 'Dipindahkan otomatis saat migrasi; surat-suratnya belum terlampir.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('kebijakan_khusus');
    }
};
