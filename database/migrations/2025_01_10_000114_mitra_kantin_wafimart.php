<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MITRA KANTIN WAFI MART — Paket 2, sisi instans Wafi Mart (keputusan user 9 Nov 2026).
 *
 * Penyewa lahan di area Wafi Mart (mis. Kantin Basah) berjualan ke santri dengan kartu RFID lewat layar kasir
 * mitra di HP-nya sendiri. Uangnya dipotong dari dompet santri di ERP Al Wafi (penghubung, asal `kantin`).
 *
 *  • `mitra` — data usaha, rekening tujuan penarikan, masa sewa (hanya KETERANGAN; sewa tahunan tak ditagih sistem).
 *  • `menu_mitra` — daftar menu & harga, diinput kepala Wafi Mart (bukan mitra). Tanpa stok.
 *  • `penjualan_mitra` (+ rincian) — satu baris per bayar kartu; nomornya sekaligus nomor rujukan ke ERP Al Wafi
 *    dan DISIMPAN SEBELUM memanggil penghubung (status `menunggu`), supaya jawaban yang hilang selalu bisa dipastikan.
 *  • `users.id_mitra` — akun mitra: hanya bisa membuka layar /mitra miliknya sendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mitra', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode', 20)->unique();
            $table->string('nama', 100);
            $table->string('pemilik', 100)->nullable();
            $table->string('telepon', 30)->nullable();
            $table->string('bank_nama', 50)->nullable();
            $table->string('bank_rekening', 40)->nullable();
            $table->string('bank_atas_nama', 100)->nullable();
            $table->date('sewa_mulai')->nullable();
            $table->date('sewa_sampai')->nullable();
            $table->text('keterangan_sewa')->nullable();
            $table->string('status', 10)->default('aktif');
            $table->unsignedInteger('dibuat_oleh')->nullable();
            $table->foreign('dibuat_oleh')->references('id_pengguna')->on('users')->nullOnDelete();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE mitra ADD CONSTRAINT mitra_status_check CHECK (status IN ('aktif', 'nonaktif'))");

        Schema::create('menu_mitra', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_mitra');
            $table->foreign('id_mitra')->references('id')->on('mitra');
            $table->string('nama', 100);
            $table->decimal('harga', 14, 2);
            $table->boolean('aktif')->default(true);
            $table->timestamps();
        });
        DB::statement('CREATE UNIQUE INDEX menu_mitra_nama_unik ON menu_mitra (id_mitra, lower(nama))');
        DB::statement('ALTER TABLE menu_mitra ADD CONSTRAINT menu_mitra_harga_check CHECK (harga > 0)');

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('id_mitra')->nullable();
            $table->foreign('id_mitra')->references('id')->on('mitra');
        });

        Schema::create('penjualan_mitra', function (Blueprint $table) {
            $table->increments('id');
            $table->string('nomor', 64)->unique();
            $table->unsignedInteger('id_mitra');
            $table->foreign('id_mitra')->references('id')->on('mitra');
            $table->unsignedInteger('id_kasir');
            $table->foreign('id_kasir')->references('id_pengguna')->on('users');
            $table->timestamp('waktu');
            $table->decimal('total', 14, 2);
            $table->string('uid_kartu', 100);
            $table->string('nama_santri', 150)->nullable();
            $table->decimal('sisa_saldo', 14, 2)->nullable();
            // menunggu → berhasil | gagal | belum_pasti ; berhasil → batal (dikembalikan ke santri)
            $table->string('status', 15)->default('menunggu');
            $table->string('pesan', 255)->nullable();
            $table->string('kunci_klien', 64)->nullable();
            $table->string('ref_dompet_batal', 64)->nullable()->unique();
            $table->string('alasan_batal', 255)->nullable();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->unsignedInteger('dibatalkan_oleh')->nullable();
            $table->foreign('dibatalkan_oleh')->references('id_pengguna')->on('users');
            $table->timestamps();
            $table->index(['id_mitra', 'waktu']);
        });
        DB::statement("ALTER TABLE penjualan_mitra ADD CONSTRAINT penjualan_mitra_status_check CHECK (status IN ('menunggu', 'berhasil', 'gagal', 'belum_pasti', 'batal'))");
        DB::statement('CREATE UNIQUE INDEX penjualan_mitra_kunci_unik ON penjualan_mitra (id_kasir, kunci_klien) WHERE kunci_klien IS NOT NULL');

        Schema::create('penjualan_mitra_rincian', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_penjualan_mitra');
            $table->foreign('id_penjualan_mitra')->references('id')->on('penjualan_mitra')->cascadeOnDelete();
            $table->unsignedInteger('id_menu')->nullable();
            $table->foreign('id_menu')->references('id')->on('menu_mitra')->nullOnDelete();
            // Snapshot: nama & harga saat transaksi — perubahan menu sesudahnya tak mengubah struk lama.
            $table->string('nama', 100);
            $table->decimal('harga', 14, 2);
            $table->unsignedSmallInteger('jumlah');
            $table->decimal('subtotal', 14, 2);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penjualan_mitra_rincian');
        Schema::dropIfExists('penjualan_mitra');
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['id_mitra']);
            $table->dropColumn('id_mitra');
        });
        Schema::dropIfExists('menu_mitra');
        Schema::dropIfExists('mitra');
    }
};
