<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dua master baru yang menggantikan daftar enum yang dulu dipaku di kode:
 *
 *  • tipe_biaya       — dulu enum jenis_biaya.tipe (registrasi|uang_pangkal|spp|lain)
 *  • sumber_informasi — dulu enum santri.sumber_informasi (medsos|iklan|…)
 *
 * KUNCI RANCANGAN pada tipe_biaya: kolom `perilaku`. Tipe biaya bukan sekadar
 * label — ia mengendalikan alur (registrasi menagih otomatis saat mendaftar,
 * uang pangkal punya potongan gelombang & angsuran, SPP terbit berkala, lain
 * ditagih manual) DAN memilah lingkup modul pembayaran. Tipe baru yang tak
 * mengaku mengikuti salah satu alur itu akan menghasilkan tagihan yang tak
 * muncul di modul mana pun. Karena itu tiap tipe WAJIB memilih perilakunya, dan
 * kode menyaring berdasarkan PERILAKU, bukan nama tipenya.
 *
 * Baris bawaan (`bawaan = true`) tak boleh dihapus/diganti kode & perilakunya —
 * seluruh alur lama bersandar pada keempat perilaku itu.
 */
return new class extends Migration
{
    private const TIPE = [
        ['kode' => 'registrasi', 'nama' => 'Registrasi', 'perilaku' => 'registrasi', 'urutan' => 1,
            'keterangan' => 'Tagihan terbit otomatis saat calon mendaftar. Ditangani modul Pembayaran Registrasi & Uang Pangkal.'],
        ['kode' => 'uang_pangkal', 'nama' => 'Uang Pangkal', 'perilaku' => 'uang_pangkal', 'urutan' => 2,
            'keterangan' => 'Ditagihkan setelah calon lulus; mengenal potongan gelombang & angsuran termin.'],
        ['kode' => 'spp', 'nama' => 'SPP', 'perilaku' => 'spp', 'urutan' => 3,
            'keterangan' => 'Terbit berkala per periode untuk santri aktif. Ditangani modul Kesantrian.'],
        ['kode' => 'lain', 'nama' => 'Lain-lain', 'perilaku' => 'lain', 'urutan' => 4,
            'keterangan' => 'Ditagihkan manual per santri (seragam, kegiatan, denda, dll.).'],
    ];

    private const SUMBER = [
        ['kode' => 'medsos', 'nama' => 'Media Sosial', 'urutan' => 1],
        ['kode' => 'iklan', 'nama' => 'Iklan', 'urutan' => 2],
        ['kode' => 'rekomendasi', 'nama' => 'Rekomendasi', 'urutan' => 3],
        ['kode' => 'website', 'nama' => 'Website', 'urutan' => 4],
        ['kode' => 'lainnya', 'nama' => 'Lainnya', 'urutan' => 5],
    ];

    public function up(): void
    {
        Schema::create('tipe_biaya', function (Blueprint $table) {
            $table->string('kode')->primary();
            $table->string('nama');
            $table->enum('perilaku', ['registrasi', 'uang_pangkal', 'spp', 'lain']);
            $table->integer('urutan')->default(0);
            $table->boolean('bawaan')->default(false);
            $table->text('keterangan')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
            $table->index('perilaku');
        });

        Schema::create('sumber_informasi', function (Blueprint $table) {
            $table->string('kode')->primary();
            $table->string('nama');
            $table->integer('urutan')->default(0);
            $table->boolean('bawaan')->default(false);
            $table->boolean('butuh_keterangan')->default(false); // "Lainnya" minta teks bebas
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });

        $now = now();
        DB::table('tipe_biaya')->insert(array_map(
            fn ($t) => $t + ['bawaan' => true, 'status' => 'aktif', 'created_at' => $now, 'updated_at' => $now],
            self::TIPE,
        ));
        DB::table('sumber_informasi')->insert(array_map(
            fn ($s) => $s + [
                'bawaan' => true,
                'butuh_keterangan' => $s['kode'] === 'lainnya',
                'status' => 'aktif',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::SUMBER,
        ));

        // Enum lama di PostgreSQL = varchar + CHECK. Cek itu harus dibuang agar
        // nilai dari master baru (di luar keempat/kelima nilai lama) diterima.
        DB::statement('ALTER TABLE jenis_biaya DROP CONSTRAINT IF EXISTS jenis_biaya_tipe_check');
        DB::statement('ALTER TABLE santri DROP CONSTRAINT IF EXISTS santri_sumber_informasi_check');

        Schema::table('jenis_biaya', function (Blueprint $table) {
            $table->foreign('tipe')->references('kode')->on('tipe_biaya')->restrictOnDelete();
        });
        Schema::table('santri', function (Blueprint $table) {
            $table->foreign('sumber_informasi')->references('kode')->on('sumber_informasi')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('jenis_biaya', fn (Blueprint $t) => $t->dropForeign(['tipe']));
        Schema::table('santri', fn (Blueprint $t) => $t->dropForeign(['sumber_informasi']));

        DB::statement("ALTER TABLE jenis_biaya ADD CONSTRAINT jenis_biaya_tipe_check CHECK (tipe IN ('registrasi','uang_pangkal','spp','lain'))");
        DB::statement("ALTER TABLE santri ADD CONSTRAINT santri_sumber_informasi_check CHECK (sumber_informasi IN ('medsos','iklan','rekomendasi','website','lainnya'))");

        Schema::dropIfExists('tipe_biaya');
        Schema::dropIfExists('sumber_informasi');
    }
};
