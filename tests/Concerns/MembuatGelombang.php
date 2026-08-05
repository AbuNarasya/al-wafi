<?php

namespace Tests\Concerns;

use App\Models\Gelombang;
use App\Models\PotonganGelombang;

/**
 * Fixture gelombang: master + sel matriksnya.
 *
 * Sejak gelombang dipecah menjadi MASTER (identitas & waktu) dan MATRIKS
 * (gelombang × jenjang → nominal), memasang potongan menuntut dua baris. Trait
 * ini menyatukannya supaya fixture tak perlu tahu bentuk tabelnya — perubahan
 * skema berikutnya cukup menyentuh berkas ini, bukan belasan test.
 */
trait MembuatGelombang
{
    /** Master gelombang saja, tanpa potongan. */
    protected function buatGelombang(string $tahunAjaran, string $kode, array $opsi = []): Gelombang
    {
        return Gelombang::updateOrCreate(
            ['tahun_ajaran' => $tahunAjaran, 'kode' => $kode],
            array_merge([
                'nama' => $kode,
                'masa_berlaku_hari' => 7,
                'status' => 'aktif',
                'berlaku_mulai' => null,
                'berlaku_sampai' => null,
            ], $opsi),
        );
    }

    /**
     * Master + satu sel potongan untuk sebuah jenjang.
     *
     * Jenjang WAJIB: tak ada lagi sel "semua jenjang". Santri tanpa jenjang
     * karena itu tak pernah dapat potongan — sama seperti tarif, yang juga
     * menuntut jenjang cocok persis.
     */
    protected function buatPotonganGelombang(
        string $tahunAjaran,
        string $kode,
        string $kodeJenjang,
        string|int|float $nominal,
        array $opsiMaster = [],
    ): PotonganGelombang {
        $this->buatGelombang($tahunAjaran, $kode, $opsiMaster);

        return PotonganGelombang::updateOrCreate(
            ['tahun_ajaran' => $tahunAjaran, 'gelombang' => $kode, 'kode_jenjang' => $kodeJenjang],
            ['potongan' => $nominal],
        );
    }
}
