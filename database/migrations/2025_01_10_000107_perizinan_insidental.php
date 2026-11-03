<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERIZINAN INSIDENTAL (keputusan user 4 Nov 2026): wali mengajukan izin anak lewat portal (atau staf atas nama
 * wali) → disetujui di ERP (tingkat 1 dan/atau tingkat 2, diatur di pengaturan) → dicatat KELUAR & KEMBALI di pos.
 *
 *  - tipe_izin: lima tipe tetap; tiap tipe punya batas waktu pengajuan (jam sebelum keluar) & saklar aktif.
 *    `pulang` = boleh kembali di hari lain; `false` (Keluar Lingkungan Ma'had) = kembali di hari yang sama.
 *  - pengaturan_perizinan: tingkat persetujuan mana yang berlaku + labelnya.
 *  - perizinan: satu pengajuan; lampiran opsional di disk `local` (disajikan lewat Unggahan::sajikan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipe_izin', function (Blueprint $table) {
            $table->string('kode', 30)->primary();
            $table->string('nama', 100);
            $table->boolean('pulang')->default(true);
            $table->boolean('wajib_keperluan')->default(false);
            $table->unsignedSmallInteger('batas_jam')->default(0);   // pengajuan wali paling lambat N jam sebelum keluar
            $table->boolean('aktif')->default(true);
            $table->unsignedTinyInteger('urutan')->default(0);
            $table->timestamps();
        });
        $kini = now();
        $tipe = [
            ['kode' => 'sakit', 'nama' => 'Sakit (Izin Pulang)', 'pulang' => true, 'wajib_keperluan' => false, 'urutan' => 1],
            ['kode' => 'haji_umroh', 'nama' => 'Ibadah Haji/Umroh (Izin Pulang)', 'pulang' => true, 'wajib_keperluan' => false, 'urutan' => 2],
            ['kode' => 'keluarga', 'nama' => 'Keperluan Keluarga (Izin Pulang)', 'pulang' => true, 'wajib_keperluan' => true, 'urutan' => 3],
            ['kode' => 'lain', 'nama' => 'Lain-lain (Izin Pulang)', 'pulang' => true, 'wajib_keperluan' => true, 'urutan' => 4],
            ['kode' => 'keluar_lingkungan', 'nama' => "Keluar Lingkungan Ma'had", 'pulang' => false, 'wajib_keperluan' => true, 'urutan' => 5],
        ];
        DB::table('tipe_izin')->insert(array_map(fn ($b) => $b + ['batas_jam' => 0, 'aktif' => true, 'created_at' => $kini, 'updated_at' => $kini], $tipe));

        Schema::create('pengaturan_perizinan', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->boolean('tingkat1_aktif')->default(false);
            $table->boolean('tingkat2_aktif')->default(true);
            $table->string('label_tingkat1', 100)->default('Musyrif / Wali Asrama');
            $table->string('label_tingkat2', 100)->default('Kesantrian');
            $table->unsignedInteger('diubah_oleh')->nullable();
            $table->timestamps();
            $table->foreign('diubah_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
        DB::table('pengaturan_perizinan')->insert(['id' => 1, 'created_at' => $kini, 'updated_at' => $kini]);

        Schema::create('perizinan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 30)->unique();
            $table->unsignedInteger('id_santri');
            $table->string('kode_tipe', 30);
            $table->text('keperluan')->nullable();
            $table->timestamp('rencana_keluar');
            $table->timestamp('rencana_kembali');
            $table->string('penjemput_nama')->nullable();
            $table->string('penjemput_hubungan', 100)->nullable();
            $table->string('penjemput_hp', 30)->nullable();
            $table->string('status', 20);
            // Pengaju: wali (portal) ATAU staf atas nama wali.
            $table->unsignedInteger('id_wali')->nullable();
            $table->string('diajukan_peran', 10)->nullable();
            $table->unsignedInteger('diajukan_oleh')->nullable();
            foreach (['tingkat1', 'tingkat2', 'ditolak', 'keluar', 'kembali'] as $langkah) {
                $table->timestamp("{$langkah}_pada")->nullable();
                $table->unsignedInteger("{$langkah}_oleh")->nullable();
                $table->foreign("{$langkah}_oleh")->references('id_pengguna')->on('users')->nullOnDelete();
            }
            $table->text('tingkat1_catatan')->nullable();
            $table->text('tingkat2_catatan')->nullable();
            $table->text('alasan_tolak')->nullable();
            $table->string('keluar_cara', 10)->nullable();    // kartu | manual
            $table->string('kembali_cara', 10)->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->string('lampiran_path')->nullable();
            $table->string('lampiran_nama')->nullable();
            $table->timestamps();

            $table->foreign('id_santri')->references('id')->on('santri')->cascadeOnDelete();
            $table->foreign('kode_tipe')->references('kode')->on('tipe_izin');
            $table->foreign('id_wali')->references('id')->on('wali')->nullOnDelete();
            $table->foreign('diajukan_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->index(['id_santri', 'status']);
            $table->index(['status', 'rencana_keluar']);
        });
        DB::statement("ALTER TABLE perizinan ADD CONSTRAINT perizinan_status_check CHECK (status IN
            ('menunggu_1', 'menunggu_2', 'disetujui', 'keluar', 'kembali', 'ditolak', 'dibatalkan'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('perizinan');
        Schema::dropIfExists('pengaturan_perizinan');
        Schema::dropIfExists('tipe_izin');
    }
};
