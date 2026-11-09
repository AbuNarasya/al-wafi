<?php

use App\Support\Instans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SALDO & PENARIKAN MITRA KANTIN — Paket 3, sisi instans Wafi Mart (keputusan user 9 Nov 2026).
 *
 * Penjualan kartu mitra BUKAN pendapatan Wafi Mart: tiap malam dijurnal ringkas per tanggal
 *     D Piutang ke Al Wafi (company_settings.kode_coa_kartu_santri) / K Hutang ke Mitra Kantin
 * Mitra mengajukan penarikan kapan saja lewat layarnya; petugas Wafi Mart mentransfer & mengunggah bukti:
 *     D Hutang ke Mitra Kantin / K Kas-Bank  — sebesar nominal yang diajukan.
 * Biaya transfer DIPOTONG dari penarikan (keputusan user): mitra menerima nominal − biaya; bank Wafi Mart keluar
 * nominal (transfer + biaya bank), jadi jurnalnya tetap satu pasang.
 * Yang bisa ditarik hanya penjualan H+1 (sesudah rekonsiliasi malam) dikurangi penarikan yang diajukan/selesai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('kode_coa_hutang_mitra')->nullable();
            $table->foreign('kode_coa_hutang_mitra')->references('kode_coa')->on('coa_detail')->nullOnDelete();
        });

        Schema::table('penjualan_mitra', function (Blueprint $table) {
            $table->timestamp('dijurnal_pada')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::create('penarikan_mitra', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 30)->unique();
            $table->unsignedInteger('id_mitra');
            $table->foreign('id_mitra')->references('id')->on('mitra');
            $table->decimal('nominal', 14, 2);
            $table->decimal('biaya_transfer', 14, 2)->default(0);
            // diajukan → selesai | ditolak | dibatalkan (oleh mitra sebelum diproses)
            $table->string('status', 12)->default('diajukan');
            $table->string('catatan_mitra', 255)->nullable();
            // Snapshot rekening tujuan saat diajukan.
            $table->string('bank_nama', 50)->nullable();
            $table->string('bank_rekening', 40)->nullable();
            $table->string('bank_atas_nama', 100)->nullable();
            $table->unsignedInteger('diajukan_oleh');
            $table->foreign('diajukan_oleh')->references('id_pengguna')->on('users');
            $table->unsignedInteger('diproses_oleh')->nullable();
            $table->foreign('diproses_oleh')->references('id_pengguna')->on('users');
            $table->timestamp('diproses_pada')->nullable();
            $table->date('tanggal_transfer')->nullable();
            $table->string('kode_coa_bank')->nullable();
            $table->foreign('kode_coa_bank')->references('kode_coa')->on('coa_detail');
            $table->string('bukti_path')->nullable();
            $table->string('bukti_nama')->nullable();
            $table->string('alasan_tolak', 255)->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['id_mitra', 'status']);
        });
        DB::statement("ALTER TABLE penarikan_mitra ADD CONSTRAINT penarikan_mitra_status_check CHECK (status IN ('diajukan', 'selesai', 'ditolak', 'dibatalkan'))");
        DB::statement('ALTER TABLE penarikan_mitra ADD CONSTRAINT penarikan_mitra_nominal_check CHECK (nominal > 0 AND biaya_transfer >= 0 AND biaya_transfer < nominal)');

        // Akun Hutang ke Mitra Kantin — dibuat otomatis di instans Wafi Mart (bisa diganti di layar Mitra Kantin).
        if (! Instans::adalah('wafimart') || ! DB::table('coa_groups')->where('kode_grup', '2.1.01')->exists()) {
            return;
        }
        $kode = DB::table('coa_detail')->where('nama_coa', 'Hutang ke Mitra Kantin')->value('kode_coa');
        if (! $kode) {
            $n = 13;
            while (DB::table('coa_detail')->where('kode_coa', sprintf('2.1.01.%03d', $n))->exists()) {
                $n++;
            }
            $kode = sprintf('2.1.01.%03d', $n);
            DB::table('coa_detail')->insert([
                'kode_coa' => $kode, 'nama_coa' => 'Hutang ke Mitra Kantin', 'kode_grup' => '2.1.01',
                'jenis_saldo' => 'kredit', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('company_settings')->whereNull('kode_coa_hutang_mitra')->update(['kode_coa_hutang_mitra' => $kode]);
    }

    public function down(): void
    {
        Schema::dropIfExists('penarikan_mitra');
        Schema::table('penjualan_mitra', function (Blueprint $table) {
            $table->dropForeign(['journal_entry_id']);
            $table->dropColumn(['dijurnal_pada', 'journal_entry_id']);
        });
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['kode_coa_hutang_mitra']);
            $table->dropColumn('kode_coa_hutang_mitra');
        });
    }
};
