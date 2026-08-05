<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gelombang berdiri sendiri sebagai MASTER; potongannya menjadi MATRIKS.
 *
 * Sebelumnya satu baris memuat gelombang, jenjang, potongan, periode, DAN masa
 * berlaku sekaligus. Akibatnya nama, periode, dan masa berlaku terduplikasi di
 * tiap jenjang: memperpanjang satu gelombang berarti menyunting sebanyak jumlah
 * jenjangnya, dan yang terlewat menghasilkan gelombang yang sama berperiode
 * berbeda-beda — tanpa gejala apa pun di layar.
 *
 * Sesudah ini:
 *   • `gelombang`            → identitas & waktu (nama, periode, masa berlaku, status)
 *   • `potongan_gelombang`   → murni sel matriks (gelombang × jenjang → nominal)
 *
 * Kolom Umum (kode_jenjang kosong) ikut dihapus, sama seperti yang dilakukan
 * pada matriks tarif biaya: jenjang selalu diketahui saat menagih, jadi cadangan
 * itu tak pernah dibutuhkan untuk menemukan potongan — ia hanya menagihkan angka
 * dari sel yang tampak kosong. Nilainya DIMATERIALKAN dulu ke tiap jenjang.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gelombang', function (Blueprint $table) {
            $table->increments('id');
            $table->string('tahun_ajaran');
            $table->string('kode', 50);
            $table->string('nama');
            $table->date('berlaku_mulai')->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->integer('masa_berlaku_hari')->default(7);
            $table->string('status')->default('aktif'); // aktif | arsip
            $table->text('keterangan')->nullable();
            $table->timestamps();

            $table->foreign('tahun_ajaran')->references('kode')->on('tahun_ajaran')->cascadeOnDelete();
            $table->unique(['tahun_ajaran', 'kode']);
        });

        DB::transaction(function () {
            $this->pindahkanKeMaster();
            $this->materialkanKolomUmum();

            // Sesudah materialisasi, tiap sel WAJIB bertulang jenjang.
            DB::table('potongan_gelombang')->whereNull('kode_jenjang')->delete();
        });

        Schema::table('potongan_gelombang', function (Blueprint $table) {
            // Pindah ke master: waktu adalah sifat GELOMBANG, bukan sifat sel.
            $table->dropColumn(['masa_berlaku_hari', 'berlaku_mulai', 'berlaku_sampai', 'aktif']);
        });

        DB::statement('ALTER TABLE potongan_gelombang ALTER COLUMN kode_jenjang SET NOT NULL');
    }

    /**
     * Satu baris master per (T.A, kode gelombang). Periode & masa berlaku
     * diambil dari baris pertama yang menyebutnya — bila antar jenjang sempat
     * berbeda, yang paling longgar yang dipakai supaya tak ada gelombang yang
     * mendadak menyempit masa berlakunya.
     */
    private function pindahkanKeMaster(): void
    {
        $sumber = DB::table('potongan_gelombang')
            ->select('tahun_ajaran', 'gelombang')
            ->selectRaw('min(berlaku_mulai) as mulai')
            ->selectRaw('max(berlaku_sampai) as sampai')
            ->selectRaw('max(masa_berlaku_hari) as hari')
            ->selectRaw('bool_or(aktif) as ada_yang_aktif')
            ->groupBy('tahun_ajaran', 'gelombang')
            ->get();

        foreach ($sumber as $g) {
            DB::table('gelombang')->insert([
                'tahun_ajaran' => $g->tahun_ajaran,
                'kode' => $g->gelombang,
                // Nama bawaan = kodenya sendiri; kode di sini memang sudah
                // berupa teks yang dibaca manusia ("Pameran ICE").
                'nama' => $g->gelombang,
                'berlaku_mulai' => $g->mulai,
                'berlaku_sampai' => $g->sampai,
                'masa_berlaku_hari' => $g->hari ?: 7,
                'status' => $g->ada_yang_aktif ? 'aktif' : 'arsip',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Sel Umum (tanpa jenjang) disalin ke tiap jenjang yang belum punya selnya sendiri. */
    private function materialkanKolomUmum(): void
    {
        $jenjang = DB::table('jenjang')->pluck('kode');
        $umum = DB::table('potongan_gelombang')->whereNull('kode_jenjang')->get();

        foreach ($umum as $u) {
            foreach ($jenjang as $kode) {
                $adaSendiri = DB::table('potongan_gelombang')
                    ->where('tahun_ajaran', $u->tahun_ajaran)
                    ->where('gelombang', $u->gelombang)
                    ->where('kode_jenjang', $kode)
                    ->exists();
                if ($adaSendiri) {
                    continue; // sel khusus menang, persis aturan lama
                }

                DB::table('potongan_gelombang')->insert([
                    'tahun_ajaran' => $u->tahun_ajaran,
                    'gelombang' => $u->gelombang,
                    'kode_jenjang' => $kode,
                    'potongan' => $u->potongan,
                    'masa_berlaku_hari' => $u->masa_berlaku_hari,
                    'aktif' => $u->aktif,
                    'berlaku_mulai' => $u->berlaku_mulai,
                    'berlaku_sampai' => $u->berlaku_sampai,
                    'keterangan' => $u->keterangan,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('potongan_gelombang', function (Blueprint $table) {
            $table->integer('masa_berlaku_hari')->default(7);
            $table->date('berlaku_mulai')->nullable();
            $table->date('berlaku_sampai')->nullable();
            $table->boolean('aktif')->default(true);
        });
        DB::statement('ALTER TABLE potongan_gelombang ALTER COLUMN kode_jenjang DROP NOT NULL');

        // Kembalikan waktu dari master ke tiap sel supaya skema lama utuh lagi.
        DB::statement('UPDATE potongan_gelombang p SET masa_berlaku_hari = g.masa_berlaku_hari,
            berlaku_mulai = g.berlaku_mulai, berlaku_sampai = g.berlaku_sampai,
            aktif = (g.status = \'aktif\')
            FROM gelombang g
            WHERE g.tahun_ajaran = p.tahun_ajaran AND g.kode = p.gelombang');

        Schema::dropIfExists('gelombang');
    }
};
