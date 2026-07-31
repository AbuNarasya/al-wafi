<?php

namespace App\Services\Modules;

use App\Models\JadwalPerubahanSantri;
use App\Models\RiwayatTingkat;
use App\Models\Santri;

/**
 * DI MANA SANTRI INI PADA SEBUAH TAHUN AJARAN — jenjang & tingkatnya.
 *
 * Satu pertanyaan yang dipakai dua penagihan, dan dulu keduanya menjawabnya
 * dengan cara yang sama-sama keliru: memakai `santri.kode_jenjang` &
 * `santri.tingkat`, yaitu keadaan SEKARANG, untuk tagihan yang bertahun ajaran
 * LAIN.
 *
 *  • DAFTAR ULANG T.A depan — ditagih pada Juni, saat kenaikannya baru
 *    ditetapkan dan belum berlaku. Memakai tingkat sekarang membuat seluruh
 *    angkatan tertolak "masih di tingkat 1, jalankan Kenaikan Tingkat lebih
 *    dulu" — padahal petugas baru saja menjalankannya.
 *  • SPP SUSULAN untuk periode tahun lalu — memakai jenjang sekarang membuat
 *    santri yang sudah naik ke SMP ditagih dengan tarif DAN akun SMP untuk
 *    bulan ketika ia masih di SDTQ.
 *
 * Jawabannya sudah tersimpan, tinggal dibaca — dua arah dari hari ini:
 *
 *  1. `riwayat_tingkat`          — tahun yang sudah/sedang dijalani (fakta).
 *  2. `jadwal_perubahan_santri`  — tahun yang akan datang (rencana yang sudah
 *     ditetapkan). Hanya yang `siap`/`diterapkan`: `menunggu_ppsb` belum tentu
 *     jadi, dan tingkat tujuannya pun belum ditentukan.
 *  3. keadaan sekarang           — cadangan terakhir, untuk tahun yang tak
 *     tercatat di keduanya (mis. data lama sebelum riwayat dibuat).
 *
 * Fakta didahulukan atas rencana: begitu sebuah tahun benar-benar dijalani,
 * riwayatnyalah yang berlaku — bukan rencana yang mungkin sudah usang.
 */
class PenempatanSantriService
{
    /**
     * @return array{kode_jenjang:?string, tingkat:?int, asal:string}
     */
    public function pada(Santri $santri, ?string $tahunAjaran): array
    {
        if (! $tahunAjaran) {
            return $this->sekarang($santri);
        }

        return $this->massal([$santri], $tahunAjaran)[$santri->id];
    }

    /**
     * Bentuk MASSAL — dua kueri untuk seluruh daftar, bukan dua per santri.
     * Penagihan daftar ulang & pratinjau SPP sama-sama memutar ratusan baris.
     *
     * @param  iterable<Santri>  $santri
     * @return array<int,array{kode_jenjang:?string, tingkat:?int, asal:string}>
     */
    public function massal(iterable $santri, string $tahunAjaran): array
    {
        $baris = collect($santri);
        $id = $baris->pluck('id')->all();
        if ($id === []) {
            return [];
        }

        $riwayat = RiwayatTingkat::whereIn('id_santri', $id)
            ->where('tahun_ajaran', $tahunAjaran)
            ->get(['id_santri', 'kode_jenjang', 'tingkat'])->keyBy('id_santri');

        $jadwal = JadwalPerubahanSantri::whereIn('id_santri', $id)
            ->where('tahun_ajaran', $tahunAjaran)
            ->whereIn('status', ['siap', 'diterapkan'])
            ->get(['id_santri', 'kode_jenjang_tujuan', 'tingkat_tujuan'])->keyBy('id_santri');

        $hasil = [];
        foreach ($baris as $s) {
            if ($r = $riwayat->get($s->id)) {
                $hasil[$s->id] = ['kode_jenjang' => $r->kode_jenjang, 'tingkat' => $r->tingkat,
                    'asal' => 'riwayat tingkat'];

                continue;
            }
            // Tingkat tujuan bisa null (siklus lanjutan yang tingkatnya belum
            // ditentukan) — jangan jadikan itu "tingkat kosong", jatuhkan saja
            // ke keadaan sekarang supaya penjaga di hilir tetap bekerja.
            $j = $jadwal->get($s->id);
            if ($j && $j->tingkat_tujuan !== null) {
                $hasil[$s->id] = ['kode_jenjang' => $j->kode_jenjang_tujuan ?: $s->kode_jenjang,
                    'tingkat' => (int) $j->tingkat_tujuan, 'asal' => 'perubahan terjadwal'];

                continue;
            }

            $hasil[$s->id] = $this->sekarang($s);
        }

        return $hasil;
    }

    /** @return array{kode_jenjang:?string, tingkat:?int, asal:string} */
    private function sekarang(Santri $s): array
    {
        return ['kode_jenjang' => $s->kode_jenjang,
            'tingkat' => $s->tingkat === null ? null : (int) $s->tingkat,
            'asal' => 'keadaan sekarang'];
    }
}
