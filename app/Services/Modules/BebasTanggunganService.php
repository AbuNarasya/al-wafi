<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DompetSantri;
use App\Models\Santri;
use App\Models\TabunganSantri;
use App\Models\TagihanSantri;
use App\Support\Money;

/**
 * BEBAS TANGGUNGAN — pemeriksaan sebelum santri lulus atau keluar.
 *
 * Sebelum ini tak ada pemeriksaan apa pun. Santri bisa diluluskan sambil
 * menyisakan piutang yang tak akan pernah tertagih, DAN menyisakan saldo dompet
 * yang menggantung di neraca selamanya sebagai liabilitas yang tak pernah bisa
 * diselesaikan. Dua-duanya baru ketahuan bertahun-tahun kemudian, saat angkanya
 * sudah terlalu besar untuk ditelusuri.
 *
 * Yang diperiksa DUA ARAH — itulah yang sering terlewat:
 *   • yang santri masih HUTANG  → tagihan bersisa
 *   • yang pesantren masih TITIP → saldo dompet & tabungan
 *
 * Dompet WALI sengaja tidak ikut: ia milik keluarga, bukan milik santri yang
 * lulus, dan adik-adiknya mungkin masih bersekolah.
 */
class BebasTanggunganService
{
    /**
     * @return array{
     *     santri:Santri, bersih:bool,
     *     tagihan:array{baris:list<array>,total:string},
     *     titipan:array{dompet:string,tabungan:string,total:string}
     * }
     */
    public function periksa(int $idSantri): array
    {
        $santri = Santri::find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }

        $tagihan = TagihanSantri::with('jenis:kode,nama')
            ->where('id_santri', $idSantri)
            ->whereNotIn('status', ['lunas', 'batal'])
            ->where('sisa', '>', 0)
            ->orderBy('jatuh_tempo')
            ->get();

        $totalTagihan = $tagihan->reduce(fn ($s, $t) => Money::add($s, $t->sisa), '0');

        $dompet = Money::of(DompetSantri::where('id_santri', $idSantri)->value('saldo') ?? 0);
        $tabungan = Money::of(TabunganSantri::where('id_santri', $idSantri)->value('saldo') ?? 0);
        $totalTitipan = Money::add($dompet, $tabungan);

        return [
            'santri' => $santri,
            'bersih' => Money::isZero($totalTagihan) && Money::isZero($totalTitipan),
            'tagihan' => [
                'baris' => $tagihan->map(fn ($t) => [
                    'id' => $t->id,
                    'nama' => $t->jenis?->nama ?? $t->kode_jenis,
                    'periode' => $t->periode,
                    'jatuh_tempo' => $t->jatuh_tempo?->format('d/m/Y'),
                    'sisa' => Money::of($t->sisa),
                ])->all(),
                'total' => Money::of($totalTagihan),
            ],
            'titipan' => ['dompet' => $dompet, 'tabungan' => $tabungan, 'total' => Money::of($totalTitipan)],
        ];
    }

    /**
     * GERBANG kepergian — menahan TITIPAN yang belum dikembalikan.
     *
     * Sengaja hanya titipan, BUKAN tagihan. Tagihan sudah punya jalan
     * keluarnya sendiri: `SantriService::keluarkanSantriAktif()` membalik
     * akrual uang pangkal & perlengkapan yang bersisa, dan itu perilaku yang
     * memang dirancang begitu. Menahan kepergian karena tagihan berarti
     * melawan rancangan yang sudah ada — dan menahan santri yang justru sedang
     * keluar karena tak sanggup membayar.
     *
     * Titipan lain ceritanya: ia uang WALI yang dipegang pesantren, tak punya
     * jalan keluar mana pun sebelum modul penarikan ada, dan kalau ditinggalkan
     * ia menggantung di neraca selamanya sebagai liabilitas yang tak pernah
     * bisa diselesaikan.
     *
     * Tetap bukan larangan mutlak: kadang walinya memang tak bisa dihubungi
     * lagi. Jalan lewatnya menuntut ALASAN TERTULIS — dan alasan itulah yang
     * akan dibaca saat saldonya dipersoalkan.
     */
    public function assertTitipanDikembalikan(int $idSantri, bool $abaikan = false, ?string $alasan = null): array
    {
        $hasil = $this->periksa($idSantri);

        if (! Money::gtZero($hasil['titipan']['total'])) {
            return $hasil;
        }

        if ($abaikan) {
            if (trim((string) $alasan) === '') {
                throw new AppException(422,
                    'Melepas santri yang titipannya belum dikembalikan boleh, tetapi alasannya wajib ditulis.');
            }

            return $hasil;
        }

        throw new AppException(422, $this->pesan($hasil));
    }

    /** Kalimat penolakan yang menyebutkan angkanya, bukan sekadar "belum bebas". */
    public function pesan(array $hasil): string
    {
        $rincian = [];
        if (Money::gtZero($hasil['titipan']['dompet'])) {
            $rincian[] = 'dompet '.$this->rp($hasil['titipan']['dompet']);
        }
        if (Money::gtZero($hasil['titipan']['tabungan'])) {
            $rincian[] = 'tabungan '.$this->rp($hasil['titipan']['tabungan']);
        }

        return "Santri \"{$hasil['santri']->nama}\" masih menitipkan "
            .$this->rp($hasil['titipan']['total']).' ('.implode(' + ', $rincian).') '
            .'yang belum dikembalikan ke wali. Kembalikan dulu lewat menu Dompet & Tabungan '
            .'(Tarik / Kembalikan Titipan), atau lepaskan dengan alasan tertulis.';
    }

    /**
     * Daftar santri yang MENDEKATI kepergian beserta keadaan tanggungannya —
     * dipakai layar Bebas Tanggungan supaya petugas bisa membereskannya jauh
     * sebelum hari kelulusan, bukan pada hari-H saat semua terburu-buru.
     *
     * @param  list<string>  $status
     */
    public function daftar(array $status = ['aktif'], ?string $kodeJenjang = null, bool $hanyaBermasalah = true): array
    {
        $santri = Santri::query()
            ->whereIn('status', $status)
            ->when($kodeJenjang, fn ($q) => $q->where('kode_jenjang', $kodeJenjang))
            ->orderBy('nama')
            ->limit(500)
            ->get(['id', 'nama', 'nis', 'kode_jenjang', 'tingkat', 'status']);

        $out = [];
        foreach ($santri as $s) {
            $h = $this->periksa($s->id);
            if ($hanyaBermasalah && $h['bersih']) {
                continue;
            }
            $out[] = [
                'santri' => $s,
                'bersih' => $h['bersih'],
                'tagihan' => $h['tagihan']['total'],
                'jumlah_tagihan' => count($h['tagihan']['baris']),
                'titipan' => $h['titipan']['total'],
            ];
        }

        return $out;
    }

    private function rp(string $v): string
    {
        return 'Rp '.number_format((float) $v, 0, ',', '.');
    }
}
