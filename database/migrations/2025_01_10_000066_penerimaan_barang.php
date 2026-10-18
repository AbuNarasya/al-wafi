<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFI MART TAHAP 1b — PENERIMAAN BARANG (keputusan user 18 Okt 2026).
 *
 * Alur pembelian barang: PO → Penerimaan Barang → Invoice Vendor → Kas Keluar.
 * Stok bertambah saat barang DITERIMA di lokasi, bukan saat invoice datang:
 *
 *   terima  : D Persediaan (akun barang)        / K Barang Diterima Belum Ditagih
 *   invoice : D Barang Diterima Belum Ditagih   / K Hutang Usaha
 *             ± selisih harga invoice vs harga terima → akun Selisih Persediaan barang
 *
 *  • `company_settings.kode_coa_grni` — akun "Barang Diterima Belum Ditagih". Bila bagan
 *    akun mengenal kelompok 2.1.01 (hutang jangka pendek), akunnya dibuat di sini dan
 *    langsung dipasang; selain itu diisi lewat Pengaturan Perusahaan.
 *  • `purchase_order_details.qty_diterima` — progres terima per baris PO.
 *  • `invoice_details.id_po_detail` — baris PO yang ditagih, DISIMPAN. Dulu dicocokkan
 *    ulang lewat kode_coa saat void, dan bisa mengembalikan progres ke baris yang salah.
 *  • `invoice_details.id_penerimaan_detail` — baris penerimaan yang ditagih.
 *  • `invoices.kode_lokasi` — lokasi stok untuk baris barang yang dibeli TANPA penerimaan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('kode_coa_grni')->nullable();
        });

        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->decimal('qty_diterima', 18, 4)->default(0)->after('qty_invoiced');
        });

        Schema::create('penerimaan_barang', function (Blueprint $table) {
            $table->increments('id_penerimaan');
            $table->string('nomor')->unique();
            $table->date('tanggal');
            $table->unsignedInteger('id_po')->nullable();
            $table->string('kode_vendor');
            $table->string('kode_lokasi', 20);
            $table->string('kode_unit');
            $table->string('nomor_surat_jalan')->nullable();
            $table->text('keterangan')->nullable();
            $table->decimal('total', 18, 2)->default(0);
            $table->enum('status', ['aktif', 'batal'])->default('aktif');
            $table->string('alasan_batal')->nullable();
            $table->unsignedInteger('journal_entry_id')->nullable();
            $table->unsignedInteger('id_pengguna')->nullable();
            $table->timestamps();

            $table->foreign('id_po')->references('id_po')->on('purchase_orders');
            $table->foreign('kode_vendor')->references('kode_vendor')->on('vendors');
            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            $table->foreign('kode_unit')->references('kode_unit')->on('business_units');
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->nullOnDelete();
            $table->index(['kode_vendor', 'status']);
        });

        Schema::create('penerimaan_barang_detail', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_penerimaan');
            $table->unsignedInteger('id_po_detail')->nullable();
            $table->string('kode_persediaan');
            // Satuan yang dihitung petugas (DUS) beserta isinya; stok tetap satuan dasar.
            $table->string('satuan', 50);
            $table->decimal('isi', 18, 4)->default(1);
            $table->decimal('kuantiti_input', 18, 4);
            $table->decimal('harga_input', 18, 2);
            // Dalam satuan DASAR.
            $table->decimal('kuantiti', 18, 4);
            $table->decimal('total', 18, 2);
            $table->decimal('qty_ditagih', 18, 4)->default(0);
            // Nilai terima yang sudah ditagih — supaya tagihan terakhir menghabiskan sisa
            // nilai PERSIS, tanpa sisa pembulatan rupiah di akun Barang Diterima.
            $table->decimal('nilai_ditagih', 18, 2)->default(0);
            $table->string('keterangan')->nullable();

            $table->foreign('id_penerimaan')->references('id_penerimaan')->on('penerimaan_barang')->cascadeOnDelete();
            $table->foreign('id_po_detail')->references('id')->on('purchase_order_details');
            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory');
        });

        Schema::table('invoice_details', function (Blueprint $table) {
            $table->unsignedInteger('id_po_detail')->nullable();
            $table->unsignedInteger('id_penerimaan_detail')->nullable();
            // Bagian NILAI TERIMA yang dilunasi baris ini (debit GRNI) — selisihnya dengan
            // `total` adalah selisih harga. Disimpan supaya void mengembalikan angka persis.
            $table->decimal('nilai_penerimaan', 18, 2)->nullable();
            $table->foreign('id_po_detail')->references('id')->on('purchase_order_details')->nullOnDelete();
            $table->foreign('id_penerimaan_detail')->references('id')->on('penerimaan_barang_detail');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kode_lokasi', 20)->nullable();
            $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
        });

        $this->siapkanAkunGrni();
    }

    /**
     * Buat akun "Barang Diterima Belum Ditagih" di kelompok hutang jangka pendek (2.1.01)
     * bila kelompok itu ada — nomor urut berikutnya, sifat & klasifikasi arus kas ditiru
     * dari akun hutang vendor. Database yang bagan akunnya lain (atau kosong, seperti
     * database test) dibiarkan: akunnya dipilih sendiri di Pengaturan Perusahaan.
     */
    private function siapkanAkunGrni(): void
    {
        $contoh = DB::table('coa_detail')->where('kode_grup', '2.1.01')->orderBy('kode_coa')->first();
        if (! $contoh) {
            return;
        }

        $kode = DB::table('coa_detail')->where('nama_coa', 'Barang Diterima Belum Ditagih')->value('kode_coa');
        if (! $kode) {
            $terpakai = DB::table('coa_detail')->where('kode_coa', '~', '^2\.1\.01\.[0-9]{3}$')->pluck('kode_coa')
                ->map(fn ($k) => (int) substr($k, -3))->filter(fn ($n) => $n < 900)->all();
            $n = $terpakai ? max($terpakai) + 1 : 1;
            $kode = '2.1.01.'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $vendor = DB::table('coa_detail')->where('nama_coa', 'ilike', '%hutang vendor%')->first() ?? $contoh;

            DB::table('coa_detail')->insert([
                'kode_coa' => $kode,
                'nama_coa' => 'Barang Diterima Belum Ditagih',
                'kode_grup' => '2.1.01',
                'jenis_saldo' => 'kredit',
                'status' => 'aktif',
                'keterangan' => 'Penampung nilai barang yang sudah diterima gudang tetapi invoice vendornya belum datang.',
                'klasifikasi_arus_kas' => $vendor->klasifikasi_arus_kas ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('company_settings')->where('id', 1)->whereNull('kode_coa_grni')->update(['kode_coa_grni' => $kode]);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['kode_lokasi']);
            $table->dropColumn('kode_lokasi');
        });
        Schema::table('invoice_details', function (Blueprint $table) {
            $table->dropForeign(['id_po_detail']);
            $table->dropForeign(['id_penerimaan_detail']);
            $table->dropColumn(['id_po_detail', 'id_penerimaan_detail', 'nilai_penerimaan']);
        });
        Schema::dropIfExists('penerimaan_barang_detail');
        Schema::dropIfExists('penerimaan_barang');
        Schema::table('purchase_order_details', function (Blueprint $table) {
            $table->dropColumn('qty_diterima');
        });
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('kode_coa_grni');
        });
    }
};
