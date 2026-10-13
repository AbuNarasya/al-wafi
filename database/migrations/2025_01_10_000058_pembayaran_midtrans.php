<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENT GATEWAY MIDTRANS — Portal Wali (keputusan user 13 Okt 2026).
 *
 * Virtual Account saja, biaya ditanggung wali. Rekening Midtrans diperlakukan
 * sebagai kas/rekening TERSENDIRI: saat lunas, D Rekening Midtrans (nominal +
 * biaya) / K Titipan (atau Pendapatan/Piutang) + K Titipan Biaya Admin; lalu
 * potongan admin Midtrans dijurnal otomatis. Pencairan ke bank = Pindah Buku.
 *
 * `transaksi_midtrans` = PESANAN. Ia lahir saat wali menekan "Bayar online" dan
 * TIDAK menyentuh buku besar; baru notifikasi lunas (diperiksa ulang ke API
 * Midtrans) yang membuat pembayaran / top-up terverifikasi beserta jurnalnya.
 *
 * Akun memakai yang sudah disemai sejak awal untuk Xendit (1.1.01.003 &
 * 2.1.01.010) — hanya namanya diganti, dan hanya bila masih nama bawaan.
 */
return new class extends Migration
{
    private const REKENING = '1.1.01.003';

    private const AKUN_BIAYA = '2.1.01.010';

    public function up(): void
    {
        Schema::create('transaksi_midtrans', function (Blueprint $table) {
            $table->increments('id');
            // order_id yang dikirim ke Midtrans — unik selamanya, juga lintas lingkungan.
            $table->string('order_id', 64)->unique();
            $table->enum('jenis', ['isi_dompet', 'bayar_tagihan']);
            $table->unsignedInteger('id_wali');
            $table->enum('peran', ['ayah', 'ibu', 'wali']);
            $table->unsignedInteger('id_tagihan')->nullable();
            $table->decimal('nominal', 18, 2);
            $table->decimal('biaya', 18, 2);
            $table->decimal('total', 18, 2);
            $table->enum('status', ['menunggu', 'lunas', 'kedaluwarsa', 'gagal', 'batal'])->default('menunggu');
            $table->string('snap_token')->nullable();
            $table->string('snap_url')->nullable();
            $table->string('transaction_id')->nullable()->unique();
            $table->string('status_midtrans', 32)->nullable();
            $table->string('bank', 32)->nullable();
            $table->string('nomor_va', 64)->nullable();
            $table->timestamp('kedaluwarsa_pada')->nullable();
            $table->timestamp('dibayar_pada')->nullable();
            $table->unsignedInteger('id_pembayaran')->nullable();
            $table->unsignedInteger('id_mutasi')->nullable();
            $table->text('catatan')->nullable();
            $table->timestamps();

            $table->foreign('id_wali')->references('id')->on('wali')->restrictOnDelete();
            $table->foreign('id_tagihan')->references('id')->on('tagihan_santri')->restrictOnDelete();
            $table->foreign('id_pembayaran')->references('id')->on('pembayaran_santri')->nullOnDelete();
            $table->foreign('id_mutasi')->references('id')->on('mutasi_dompet')->nullOnDelete();
            $table->index(['id_wali', 'status']);
            $table->index(['id_tagihan', 'status']);
        });

        // Bayar tagihan WAJIB menyebut tagihannya; isi dompet tidak.
        DB::statement("ALTER TABLE transaksi_midtrans ADD CONSTRAINT transaksi_midtrans_tagihan_check
            CHECK ((jenis = 'bayar_tagihan') = (id_tagihan IS NOT NULL))");

        // Asal top-up: 'manual' (transfer + bukti, diverifikasi staf) | 'midtrans'.
        Schema::table('mutasi_dompet', function (Blueprint $table) {
            $table->string('sumber', 20)->default('manual');
        });

        DB::table('coa_detail')->where('kode_coa', self::REKENING)->where('nama_coa', 'ilike', '%xendit%')
            ->update(['nama_coa' => 'Rekening Midtrans']);
        DB::table('coa_detail')->where('kode_coa', self::AKUN_BIAYA)->where('nama_coa', 'ilike', '%xendit%')
            ->update(['nama_coa' => 'Titipan Biaya Admin Payment Gateway']);

        // Rekening Midtrans harus berupa kas/rekening (bank_accounts) supaya bisa
        // dipindahbukukan ke bank saat dana cair. Tak tampil di portal (tanpa nomor).
        if (DB::table('coa_detail')->where('kode_coa', self::REKENING)->exists()) {
            $ada = DB::table('bank_accounts')->where('kode_coa', self::REKENING)->first();
            if (! $ada) {
                DB::table('bank_accounts')->insert([
                    'kode_coa' => self::REKENING, 'nama_rekening' => 'Rekening Midtrans', 'jenis_rekening' => 'bank',
                    'nama_bank' => 'Midtrans', 'no_rekening' => null, 'status' => 'aktif', 'tampil_di_portal' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } elseif (stripos((string) $ada->nama_rekening, 'xendit') !== false) {
                DB::table('bank_accounts')->where('kode_coa', self::REKENING)
                    ->update(['nama_rekening' => 'Rekening Midtrans', 'nama_bank' => 'Midtrans', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('mutasi_dompet', function (Blueprint $table) {
            $table->dropColumn('sumber');
        });
        Schema::dropIfExists('transaksi_midtrans');
    }
};
