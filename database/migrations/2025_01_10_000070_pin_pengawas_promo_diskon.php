<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WAFI MART — PIN PENGAWAS, PROMO TERJADWAL, DISKON MANUAL (keputusan user 19 Okt 2026:
 * diskon manual SELALU butuh PIN pengawas; pendapatan dicatat BERSIH).
 *
 *  • `pin_pengawas` — PIN pemegang hak shift-kasir/ubah, diketik di perangkat kasir untuk
 *    menyetujui diskon (dan retur, menyusul). Dikunci 15 menit sesudah 5 kali salah.
 *  • Promo di master barang: harga promo + periode. Berlaku untuk SATUAN DASAR saja.
 *  • `penjualan_detail.harga_normal` / `promo` / `diskon` dan `penjualan.diskon_struk`
 *    + siapa yang menyetujui & alasannya. `penjualan.total` tetap = yang dibayar pembeli,
 *    jadi jurnal ringkas shift (pendapatan bersih) tak berubah sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pin_pengawas', function (Blueprint $table) {
            $table->unsignedInteger('id_pengguna')->primary();
            $table->string('pin_hash');
            $table->unsignedSmallInteger('gagal')->default(0);
            $table->timestamp('terkunci_sampai')->nullable();
            $table->timestamps();

            $table->foreign('id_pengguna')->references('id_pengguna')->on('users')->cascadeOnDelete();
        });

        Schema::table('inventory', function (Blueprint $table) {
            $table->decimal('harga_promo', 18, 2)->nullable()->after('harga_jual');
            $table->date('promo_mulai')->nullable()->after('harga_promo');
            $table->date('promo_selesai')->nullable()->after('promo_mulai');
        });

        Schema::table('penjualan_detail', function (Blueprint $table) {
            // Harga master sebelum promo; `harga_satuan` = harga yang berlaku (sesudah promo).
            $table->decimal('harga_normal', 18, 2)->nullable();
            $table->boolean('promo')->default(false);
            // Potongan manual baris ini (nominal); `total` = kuantiti × harga_satuan − diskon.
            $table->decimal('diskon', 18, 2)->default(0);
        });

        Schema::table('penjualan', function (Blueprint $table) {
            $table->decimal('diskon_struk', 18, 2)->default(0)->after('total');
            $table->unsignedInteger('disetujui_oleh')->nullable();
            $table->string('alasan_diskon')->nullable();
            $table->foreign('disetujui_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('penjualan', function (Blueprint $table) {
            $table->dropForeign(['disetujui_oleh']);
            $table->dropColumn(['diskon_struk', 'disetujui_oleh', 'alasan_diskon']);
        });
        Schema::table('penjualan_detail', function (Blueprint $table) {
            $table->dropColumn(['harga_normal', 'promo', 'diskon']);
        });
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropColumn(['harga_promo', 'promo_mulai', 'promo_selesai']);
        });
        Schema::dropIfExists('pin_pengawas');
    }
};
