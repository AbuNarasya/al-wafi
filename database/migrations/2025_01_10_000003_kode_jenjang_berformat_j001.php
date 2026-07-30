<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KODE JENJANG jadi berformat `J001`, `J002`, … — mengikuti pola kode master lain
 * di aplikasi ini (jalur `001`, tipe biaya `001`).
 *
 * Sebabnya: kode jenjang sebelumnya diisi sama dengan namanya (`SDTQ`/`SDTQ`),
 * sehingga label "kode — nama" di layar berbunyi "SDTQ — SDTQ". Dengan kode yang
 * netral, kolom nama-lah yang memikul keterangannya dan tak ada lagi pengulangan.
 *
 * CARA MENGGANTI PRIMARY KEY yang dirujuk 7 kunci asing: baris BARU disisipkan
 * lebih dulu (kedua kode hidup berdampingan), seluruh perujuk dialihkan, baru
 * baris lama dihapus. Kunci asingnya ber-`ON UPDATE NO ACTION`, jadi UPDATE
 * langsung atas kolom kode akan ditolak.
 *
 * `nama` TIDAK diubah — kalau sekarang berbunyi "SDTQ", itu tetap nama yang
 * dibaca pemakai dan bisa disunting sendiri lewat master Jenjang.
 *
 * ⚠️ BERKAS IMPOR: kolom `kode_jenjang` pada impor santri lama kini berisi `J001`,
 * bukan `SDTQ`. Agar berkas yang sudah disiapkan tidak perlu ditulis ulang,
 * PemetaSantriLama diubah menerima NAMA jenjang sebagai pengganti kodenya.
 */
return new class extends Migration
{
    /** Kolom perujuk (selain kunci asing jenjang ke dirinya sendiri). */
    private const PERUJUK = [
        'santri' => 'kode_jenjang',
        'jenis_biaya' => 'kode_jenjang',
        'potongan_gelombang' => 'kode_jenjang',
        'target_santri' => 'kode_jenjang',
        'tarif_biaya' => 'kode_jenjang',
        'jalur_nonaktif' => 'kode_jenjang',
        // Tanpa kunci asing (snapshot), tetapi ikut dialihkan supaya tagihan lama
        // tetap tersambung ke jenjangnya.
        'tagihan_santri' => 'kode_jenjang',
    ];

    public function up(): void
    {
        $lama = DB::table('jenjang')->orderBy('urutan')->orderBy('kode')->get();
        if ($lama->isEmpty()) {
            return;
        }

        $dipakai = $lama->pluck('kode')->flip();
        $peta = [];
        $urut = 0;
        foreach ($lama as $j) {
            $baru = 'J'.str_pad((string) ++$urut, 3, '0', STR_PAD_LEFT);
            if ($j->kode === $baru) {
                continue; // sudah berformat benar & di urutan yang benar
            }
            // Bila kode tujuan ternyata sudah dipakai baris LAIN, penggantian akan
            // menabrak primary key. Lebih baik berhenti dengan pesan yang jelas
            // daripada memindahkan separuh data.
            if (isset($dipakai[$baru])) {
                throw new RuntimeException(
                    "Kode jenjang \"{$baru}\" sudah dipakai baris lain, jadi penggantian otomatis dibatalkan. "
                    ."Ganti kode jenjang secara manual lewat master Jenjang, lalu jalankan migrasi ini lagi."
                );
            }
            $peta[$j->kode] = $baru;
        }
        if ($peta === []) {
            return;
        }

        DB::transaction(function () use ($lama, $peta) {
            $now = now();

            // 1. Baris baru — `kode_jenjang_lanjutan` sengaja masih menunjuk kode
            //    LAMA, yang saat ini tetap sah karena barisnya belum dihapus.
            foreach ($lama as $j) {
                if (! isset($peta[$j->kode])) {
                    continue;
                }
                DB::table('jenjang')->insert([
                    'kode' => $peta[$j->kode],
                    'nama' => $j->nama,
                    'urutan' => $j->urutan,
                    'status' => $j->status,
                    'keterangan' => $j->keterangan,
                    'jumlah_tingkat' => $j->jumlah_tingkat,
                    'kode_jenjang_lanjutan' => $j->kode_jenjang_lanjutan,
                    'created_at' => $j->created_at ?? $now,
                    'updated_at' => $now,
                ]);
            }

            // 2. Seluruh perujuk dialihkan ke kode baru.
            foreach (self::PERUJUK as $tabel => $kolom) {
                foreach ($peta as $dari => $ke) {
                    DB::table($tabel)->where($kolom, $dari)->update([$kolom => $ke]);
                }
            }

            // 3. Rujukan jenjang-lanjutan pada baris BARU ikut dipetakan.
            foreach ($peta as $dari => $ke) {
                DB::table('jenjang')->whereIn('kode', array_values($peta))
                    ->where('kode_jenjang_lanjutan', $dari)
                    ->update(['kode_jenjang_lanjutan' => $ke]);
            }

            // 4. Baris lama dibuang. Rujukan lanjutan antar-baris lama ikut lenyap
            //    lewat nullOnDelete, dan itu tak apa — barisnya memang dihapus.
            DB::table('jenjang')->whereIn('kode', array_keys($peta))->delete();
        });
    }

    /**
     * TIDAK BISA DIBALIK: kode lama (`SDTQ`, `SMP`, …) tidak disimpan di mana pun
     * setelah barisnya dihapus, jadi tak ada yang bisa dipulihkan. Dibiarkan
     * kosong dengan sadar — melempar galat di sini hanya akan menghalangi
     * rollback migrasi lain yang datang sesudahnya.
     */
    public function down(): void {}
};
