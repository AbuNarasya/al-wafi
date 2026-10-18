<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFI MART TAHAP 2a — KASIR: SHIFT, PENJUALAN TUNAI, JURNAL RINGKAS PER SHIFT
 * (keputusan user 18 Okt 2026: jurnal ringkas per shift · akun per lokasi toko ·
 * selisih kas ke akun Selisih Kas).
 *
 *  • Akun toko dipasang di `lokasi_persediaan`: kas laci (harus terdaftar di Kas &
 *    Rekening), pendapatan penjualan, HPP, selisih kas, dan Bagian pengelola toko
 *    (akun HPP & selisih kas adalah akun beban → PostingService mewajibkan Bagian).
 *  • `shift_kasir` — buka (modal awal) → tutup (hitung uang, jurnal ringkas) →
 *    diserahkan (setoran dikonfirmasi penerima, jurnal pindah kas). Satu kasir hanya
 *    boleh punya SATU shift terbuka (indeks unik parsial).
 *  • `penjualan` + `penjualan_detail` — stok keluar FIFO saat transaksi; harga pokok
 *    tiap baris disimpan supaya jurnal HPP shift = jumlah persis kartu stoknya.
 *  • `metode` disimpan sebagai teks (bukan enum): dompet/RFID menyusul tanpa migrasi CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lokasi_persediaan', function (Blueprint $table) {
            $table->string('kode_coa_kas')->nullable();
            $table->string('kode_coa_pendapatan')->nullable();
            $table->string('kode_coa_hpp')->nullable();
            $table->string('kode_coa_selisih_kas')->nullable();
            $table->string('kode_bagian')->nullable();

            $table->foreign('kode_coa_kas')->references('kode_coa')->on('bank_accounts')->nullOnDelete();
            $table->foreign('kode_coa_pendapatan')->references('kode_coa')->on('coa_detail')->nullOnDelete();
            $table->foreign('kode_coa_hpp')->references('kode_coa')->on('coa_detail')->nullOnDelete();
            $table->foreign('kode_coa_selisih_kas')->references('kode_coa')->on('coa_detail')->nullOnDelete();
            $table->foreign('kode_bagian')->references('kode_bagian')->on('bagian')->nullOnDelete();
        });

        Schema::create('shift_kasir', function (Blueprint $table) {
            $table->increments('id_shift');
            $table->string('nomor')->unique();
            $table->string('kode_lokasi', 20);
            $table->unsignedInteger('id_kasir');
            $table->timestamp('dibuka_pada');
            $table->decimal('modal_awal', 18, 2)->default(0);
            $table->timestamp('ditutup_pada')->nullable();
            // Diisi saat tutup — potret, bukan dihitung ulang tiap dibaca.
            $table->unsignedInteger('jumlah_transaksi')->default(0);
            $table->decimal('total_tunai', 18, 2)->default(0);
            $table->decimal('total_hpp', 18, 2)->default(0);
            $table->decimal('uang_dihitung', 18, 2)->nullable();
            $table->jsonb('rincian_pecahan')->nullable();
            $table->decimal('selisih', 18, 2)->nullable();
            $table->string('catatan_tutup')->nullable();
            $table->enum('status', ['buka', 'tutup', 'diserahkan'])->default('buka');
            $table->unsignedInteger('journal_entry_id')->nullable();
            // Serah terima: setoran = uang dihitung − modal awal (modal tetap di laci).
            $table->decimal('setoran', 18, 2)->nullable();
            $table->string('kode_coa_kas_tujuan')->nullable();
            $table->unsignedInteger('diterima_oleh')->nullable();
            $table->timestamp('diterima_pada')->nullable();
            $table->unsignedInteger('journal_setoran_id')->nullable();
            $table->timestamps();

            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            $table->foreign('id_kasir')->references('id_pengguna')->on('users');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->foreign('journal_setoran_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['kode_lokasi', 'status']);
        });
        DB::statement("CREATE UNIQUE INDEX shift_kasir_satu_terbuka ON shift_kasir (id_kasir) WHERE status = 'buka'");

        Schema::create('penjualan', function (Blueprint $table) {
            $table->increments('id_penjualan');
            $table->string('nomor')->unique();
            $table->unsignedInteger('id_shift');
            $table->string('kode_lokasi', 20);
            $table->timestamp('waktu');
            $table->string('metode', 20)->default('tunai');
            $table->decimal('total', 18, 2);
            $table->decimal('bayar', 18, 2);
            $table->decimal('kembalian', 18, 2)->default(0);
            $table->decimal('hpp', 18, 2)->default(0);
            $table->enum('status', ['selesai', 'batal'])->default('selesai');
            $table->unsignedInteger('id_kasir');
            $table->string('alasan_batal')->nullable();
            $table->unsignedInteger('dibatalkan_oleh')->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->timestamps();

            $table->foreign('id_shift')->references('id_shift')->on('shift_kasir');
            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            $table->index(['id_shift', 'status']);
            $table->index('waktu');
        });

        Schema::create('penjualan_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_penjualan');
            $table->string('kode_persediaan');
            $table->string('barcode', 64)->nullable();
            $table->string('satuan', 50);
            $table->decimal('isi', 18, 4)->default(1);
            $table->decimal('kuantiti_input', 18, 4);
            // Satuan DASAR.
            $table->decimal('kuantiti', 18, 4);
            $table->decimal('harga_satuan', 18, 2);
            $table->decimal('total', 18, 2);
            $table->decimal('hpp', 18, 2)->default(0);

            $table->foreign('id_penjualan')->references('id_penjualan')->on('penjualan')->cascadeOnDelete();
            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penjualan_detail');
        Schema::dropIfExists('penjualan');
        Schema::dropIfExists('shift_kasir');
        Schema::table('lokasi_persediaan', function (Blueprint $table) {
            foreach (['kode_coa_kas', 'kode_coa_pendapatan', 'kode_coa_hpp', 'kode_coa_selisih_kas', 'kode_bagian'] as $k) {
                $table->dropForeign([$k]);
            }
            $table->dropColumn(['kode_coa_kas', 'kode_coa_pendapatan', 'kode_coa_hpp', 'kode_coa_selisih_kas', 'kode_bagian']);
        });
    }
};
