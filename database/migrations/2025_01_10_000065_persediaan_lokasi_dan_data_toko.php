<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WAFI MART TAHAP 1a — persediaan mengenal LOKASI, dan barang punya data toko
 * (keputusan user 18 Okt 2026: memperluas mesin FIFO yang ada, bukan membuat baru).
 *
 *  • `lokasi_persediaan` — gudang / toko / transit. Baris `L001` "Gudang Utama" lahir di
 *    sini dan menampung SELURUH stok lama: pemanggil lama (invoice, kas keluar/masuk,
 *    jurnal umum) yang tak menyebut lokasi otomatis jatuh ke sana.
 *  • `kode_lokasi` di lapisan FIFO & kartu stok → FIFO dihitung PER LOKASI: barang keluar
 *    dari Koperasi Banat hanya menggerus lapisan milik Banat.
 *  • `saldo_kuantiti_lokasi` — saldo berjalan lokasinya sendiri, supaya kartu stok bisa
 *    disaring per lokasi tanpa menghitung ulang seluruh riwayat.
 *  • Data toko di barang: kategori, merk, harga jual, stok minimum, tanda dijual di toko.
 *  • `satuan_persediaan` — konversi (1 DUS = 24 PCS). Stok SELALU dicatat dalam satuan
 *    dasar (`inventory.satuan`); satuan lain hanya cara menyebut jumlah.
 *  • `barcode_persediaan` — satu barang boleh banyak barcode, tiap barcode unik dan
 *    disimpan sebagai TEKS apa adanya (POS lama menyimpannya sebagai angka → berakhiran ".00").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lokasi_persediaan', function (Blueprint $table) {
            $table->string('kode', 20)->primary();
            $table->string('nama', 100);
            $table->enum('jenis', ['gudang', 'toko', 'transit'])->default('gudang');
            // Unit bisnis pemilik lokasi — jurnal pemakaian/opname di lokasi ini ikut unitnya.
            $table->string('kode_unit')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->unsignedSmallInteger('urutan')->default(0);
            $table->timestamps();

            $table->foreign('kode_unit')->references('kode_unit')->on('business_units')->nullOnDelete();
        });

        DB::table('lokasi_persediaan')->insert([
            'kode' => 'L001', 'nama' => 'Gudang Utama', 'jenis' => 'gudang', 'status' => 'aktif',
            'urutan' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['lapisan_persediaan', 'mutasi_persediaan'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->string('kode_lokasi', 20)->default('L001')->after('kode_persediaan');
                $table->foreign('kode_lokasi')->references('kode')->on('lokasi_persediaan');
            });
        }

        Schema::table('lapisan_persediaan', function (Blueprint $table) {
            $table->index(['kode_persediaan', 'kode_lokasi', 'tanggal', 'id'], 'lapisan_urutan_fifo_lokasi');
        });

        Schema::table('mutasi_persediaan', function (Blueprint $table) {
            $table->decimal('saldo_kuantiti_lokasi', 18, 4)->default(0)->after('saldo_nilai');
            $table->index(['kode_persediaan', 'kode_lokasi', 'tanggal', 'id'], 'kartu_stok_urutan_lokasi');
        });
        // Seluruh riwayat lama ada di satu lokasi → saldo lokasinya = saldo totalnya.
        DB::statement('UPDATE mutasi_persediaan SET saldo_kuantiti_lokasi = saldo_kuantiti');

        Schema::create('kategori_barang', function (Blueprint $table) {
            $table->string('kode', 20)->primary();
            $table->string('nama', 100);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });

        Schema::create('merk_barang', function (Blueprint $table) {
            $table->string('kode', 20)->primary();
            $table->string('nama', 100);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->string('kode_kategori', 20)->nullable()->after('satuan');
            $table->string('kode_merk', 20)->nullable()->after('kode_kategori');
            $table->decimal('harga_jual', 18, 2)->nullable()->after('harga_perolehan');
            $table->decimal('stok_minimum', 18, 4)->nullable()->after('harga_jual');
            $table->boolean('dijual_di_toko')->default(false)->after('stok_minimum');

            $table->foreign('kode_kategori')->references('kode')->on('kategori_barang')->nullOnDelete();
            $table->foreign('kode_merk')->references('kode')->on('merk_barang')->nullOnDelete();
        });

        Schema::create('satuan_persediaan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode_persediaan');
            $table->string('satuan', 50);
            // Berapa satuan DASAR dalam satu satuan ini (DUS → 24).
            $table->decimal('isi', 18, 4);
            // Harga jual per satuan ini; kosong = isi × harga jual satuan dasar.
            $table->decimal('harga_jual', 18, 2)->nullable();
            $table->timestamps();

            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory')->cascadeOnDelete();
            $table->unique(['kode_persediaan', 'satuan']);
        });

        Schema::create('barcode_persediaan', function (Blueprint $table) {
            $table->string('barcode', 64)->primary();
            $table->string('kode_persediaan');
            // Kosong = satuan dasar. Barcode dus memindai satu DUS sekaligus.
            $table->string('satuan', 50)->nullable();
            $table->timestamps();

            $table->foreign('kode_persediaan')->references('kode_persediaan')->on('inventory')->cascadeOnDelete();
            $table->index('kode_persediaan');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_persediaan');
        Schema::dropIfExists('satuan_persediaan');
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropForeign(['kode_kategori']);
            $table->dropForeign(['kode_merk']);
            $table->dropColumn(['kode_kategori', 'kode_merk', 'harga_jual', 'stok_minimum', 'dijual_di_toko']);
        });
        Schema::dropIfExists('merk_barang');
        Schema::dropIfExists('kategori_barang');
        Schema::table('mutasi_persediaan', function (Blueprint $table) {
            $table->dropIndex('kartu_stok_urutan_lokasi');
            $table->dropColumn('saldo_kuantiti_lokasi');
        });
        Schema::table('lapisan_persediaan', function (Blueprint $table) {
            $table->dropIndex('lapisan_urutan_fifo_lokasi');
        });
        foreach (['lapisan_persediaan', 'mutasi_persediaan'] as $tabel) {
            Schema::table($tabel, function (Blueprint $table) {
                $table->dropForeign(['kode_lokasi']);
                $table->dropColumn('kode_lokasi');
            });
        }
        Schema::dropIfExists('lokasi_persediaan');
    }
};
