<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PUSH NOTIFICATION UNTUK WALI (keputusan user 21 Okt 2026). Kabar portal (`notifikasi_wali`) didorong ke ponsel
 * wali yang menyalakannya, sesuai KATEGORI yang dipilih masing-masing orang (ayah/ibu/wali):
 *   tagihan · dompet · laundry · akademik · pengajuan · pengumuman · lainnya   (App\Models\NotifikasiWali::KATEGORI)
 *
 *  • `notifikasi_wali.kategori` + `didorong_pada` (antrean push, pola sama dengan `notifications`).
 *  • `push_langganan_wali` (sudah ada sejak 000053) — langganan per PERANGKAT milik satu orang; + penjaga kirim.
 *  • `preferensi_notifikasi_wali` — kategori yang DIMATIKAN orang itu (tak ada barisnya = nyala).
 *  • `tagihan_santri.dikabarkan_pada` — penanda kabar "tagihan baru" (sapuan tiap 5 menit).
 * Yang sudah ada sebelum migrasi ini ditandai sudah didorong/dikabarkan: tak ada ledakan kabar lama.
 */
return new class extends Migration
{
    private const PERAN = ['ayah', 'ibu', 'wali'];

    private const JENIS_KATEGORI = [
        'pembayaran_santri_ditolak' => 'tagihan', 'midtrans_lunas' => 'tagihan',
        'topup_dompet_ditolak' => 'dompet',
        'tarik_tunai' => 'pengajuan', 'tarik_tabungan' => 'pengajuan',
    ];

    public function up(): void
    {
        Schema::table('notifikasi_wali', function (Blueprint $table) {
            $table->string('kategori', 20)->default('lainnya');
            $table->timestamp('didorong_pada')->nullable();
            $table->index('didorong_pada');
        });
        foreach (self::JENIS_KATEGORI as $jenis => $kategori) {
            DB::table('notifikasi_wali')->where('jenis', $jenis)->update(['kategori' => $kategori]);
        }
        DB::table('notifikasi_wali')->update(['didorong_pada' => now()]);

        // Tabelnya sudah disiapkan migrasi portal wali (000053) tanpa pernah dipakai; dilengkapi penjaga pengiriman.
        Schema::table('push_langganan_wali', function (Blueprint $table) {
            $table->timestamp('terakhir_berhasil')->nullable();
            $table->unsignedSmallInteger('gagal_beruntun')->default(0);
        });

        Schema::create('preferensi_notifikasi_wali', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('id_wali');
            $table->enum('peran', self::PERAN);
            $table->string('kategori', 20);
            $table->timestamps();

            $table->unique(['id_wali', 'peran', 'kategori']);
            $table->foreign('id_wali')->references('id')->on('wali')->cascadeOnDelete();
        });

        Schema::table('tagihan_santri', function (Blueprint $table) {
            $table->timestamp('dikabarkan_pada')->nullable();
        });
        DB::table('tagihan_santri')->update(['dikabarkan_pada' => now()]);
    }

    public function down(): void
    {
        Schema::table('tagihan_santri', fn (Blueprint $t) => $t->dropColumn('dikabarkan_pada'));
        Schema::dropIfExists('preferensi_notifikasi_wali');
        Schema::table('push_langganan_wali', fn (Blueprint $t) => $t->dropColumn(['terakhir_berhasil', 'gagal_beruntun']));
        Schema::table('notifikasi_wali', function (Blueprint $table) {
            $table->dropIndex(['didorong_pada']);
            $table->dropColumn(['kategori', 'didorong_pada']);
        });
    }
};
