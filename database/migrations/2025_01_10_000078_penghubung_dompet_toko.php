<?php

use App\Support\Instans;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PENGHUBUNG DOMPET TOKO — Fase 3a (keputusan user 20 Okt 2026). Kasir Wafi Mart (instans lain) memotong
 * dompet santri di ERP Al Wafi lewat API bertanda tangan.
 *
 *  • `transaksi_dompet_toko` = log setiap potong/kembalikan. `nomor_toko` dari Wafi Mart UNIK: permintaan
 *    yang dikirim ulang (jaringan putus, kasir menekan dua kali) mengembalikan hasil yang sama — tak pernah
 *    terpotong dua kali.
 *  • Jurnalnya RINGKAS tiap malam (`dompet:jurnal-belanja-toko`): D Titipan Uang Saku / K Hutang ke Wafi Mart.
 *  • Akun hutangnya dibuat di sini bila kelompok 2.1.01 ada (pola sama dengan GRNI, migrasi 000066) —
 *    TIDAK di instans Wafi Mart (di sana yang ada justru Piutang ke Al Wafi).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_dompet_toko', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor_toko', 64)->unique();
            $table->enum('jenis', ['potong', 'kembalikan']);
            $table->string('nomor_asal', 64)->nullable();     // kembalikan → nomor_toko potongannya
            $table->unsignedInteger('id_santri');
            $table->string('uid_kartu')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->string('kode_toko', 30)->nullable();
            $table->string('nama_toko')->nullable();
            $table->string('keterangan')->nullable();
            $table->unsignedInteger('id_mutasi_dompet')->nullable();
            $table->enum('status', ['berhasil', 'dibatalkan'])->default('berhasil');
            $table->date('tanggal');
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->timestamp('dijurnal_pada')->nullable();
            $table->timestamps();

            $table->index(['tanggal', 'dijurnal_pada']);
            $table->index('nomor_asal');
            $table->foreign('id_santri')->references('id')->on('santri');
            $table->foreign('id_mutasi_dompet')->references('id')->on('mutasi_dompet')->nullOnDelete();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
        });

        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('kode_coa_hutang_toko')->nullable();
            $table->foreign('kode_coa_hutang_toko')->references('kode_coa')->on('coa_detail')->nullOnDelete();
        });

        if (Instans::adalah('wafimart') || ! DB::table('coa_groups')->where('kode_grup', '2.1.01')->exists()) {
            return;
        }
        // Nomor bebas pertama mulai 2.1.01.013 (012 lazimnya GRNI).
        $n = 13;
        while (DB::table('coa_detail')->where('kode_coa', sprintf('2.1.01.%03d', $n))->exists()) {
            $n++;
        }
        $kode = sprintf('2.1.01.%03d', $n);
        DB::table('coa_detail')->insert([
            'kode_coa' => $kode, 'nama_coa' => 'Hutang ke Wafi Mart (Belanja Kartu Santri)', 'kode_grup' => '2.1.01',
            'jenis_saldo' => 'kredit', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('company_settings')->whereNull('kode_coa_hutang_toko')->update(['kode_coa_hutang_toko' => $kode]);
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropForeign(['kode_coa_hutang_toko']);
            $table->dropColumn('kode_coa_hutang_toko');
        });
        Schema::dropIfExists('transaksi_dompet_toko');
    }
};
