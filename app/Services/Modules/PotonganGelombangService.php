<?php

namespace App\Services\Modules;

use App\Models\Gelombang;
use App\Models\Jenjang;
use App\Models\PotonganGelombang;
use App\Models\TarifBiaya;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Pencarian potongan uang pangkal saat menagih.
 *
 * Dua sumber, masing-masing satu urusan:
 *   • master `gelombang`          → berlaku atau tidak pada tanggal itu
 *   • matriks `potongan_gelombang` → besarannya untuk (gelombang × jenjang)
 *
 * Jenjang harus COCOK PERSIS — tak ada lagi sel "Umum (semua jenjang)" sebagai
 * cadangan, sama seperti matriks tarif biaya. Jenjang santri selalu diketahui,
 * jadi cadangan itu tak pernah dibutuhkan untuk menemukan potongan; yang ia
 * lakukan hanyalah memotong dari sel yang di layar tampak kosong.
 */
class PotonganGelombangService
{
    /**
     * Potongan yang BERLAKU untuk (gelombang, jenjang) pada suatu tanggal.
     *
     * $gelombang kosong = santri "Tanpa Gelombang" (pindahan & kasus khusus) →
     * TIDAK PERNAH dapat potongan; pencocokan dilewati sejak awal.
     *
     * $tanggal = tanggal PELUNASAN REGISTRASI (lihat SantriService), bukan hari
     * tagihan uang pangkal dibuat: calon yang membayar tepat waktu tak boleh
     * kehilangan potongannya karena pengumuman atau penagihannya tertunda.
     */
    public function potonganAktif(?string $gelombang, ?string $kodeJenjang, ?string $tahunAjaran = null, ?string $tanggal = null): ?PotonganGelombang
    {
        if ($gelombang === null || trim($gelombang) === '' || ! $kodeJenjang || ! $tahunAjaran) {
            return null;
        }

        $master = Gelombang::where('tahun_ajaran', $tahunAjaran)->where('kode', $gelombang)->first();
        if (! $master || $master->keadaan($tanggal ?: Carbon::now()->toDateString()) !== 'berlaku') {
            return null;
        }

        return PotonganGelombang::where('tahun_ajaran', $tahunAjaran)
            ->where('gelombang', $gelombang)
            ->where('kode_jenjang', $kodeJenjang)
            ->first();
    }

    /** Masa berlaku (hari) untuk menghitung tenggat "bayar ≥50%" — milik gelombangnya. */
    public function masaBerlakuHari(string $gelombang, string $tahunAjaran): int
    {
        return (int) (Gelombang::where('tahun_ajaran', $tahunAjaran)->where('kode', $gelombang)
            ->value('masa_berlaku_hari') ?: 7);
    }

    /**
     * PERINGATAN (bukan penolakan): potongan yang ≥ uang pangkalnya akan ditolak
     * nanti oleh `SantriService::tagihkanUangPangkal`, dan tanpa isyarat di sini
     * kekeliruannya baru ketahuan saat petugas menagih santri pertama.
     *
     * Dibandingkan dengan tarif TERKECIL di antara jalur — jalur itulah yang
     * pertama tertolak; menunggu jalur termahal berarti peringatannya baru
     * berbunyi setelah kerusakannya terjadi.
     */
    public function peringatanNominal(PotonganGelombang $row): ?string
    {
        $terkecil = TarifBiaya::where('tahun_ajaran', $row->tahun_ajaran)
            ->where('kode_jenjang', $row->kode_jenjang)
            ->where('perilaku', 'uang_pangkal')
            ->where('bebas', false)
            ->whereNotNull('nominal')
            ->orderBy('nominal')->first();

        if (! $terkecil || ! Money::gte($row->potongan, $terkecil->nominal)) {
            return null;
        }

        return 'Perhatian: potongan '.Money::of($row->potongan).' pada '.$this->labelJenjang($row->kode_jenjang)
            ." T.A {$row->tahun_ajaran} tidak lebih kecil dari uang pangkalnya (".Money::of($terkecil->nominal).'), '
            .'sehingga penagihan uang pangkal akan DITOLAK. Turunkan potongannya atau naikkan tarif uang pangkalnya.';
    }

    /**
     * Jenjang disebut lewat NAMA: sejak kodenya berformat `J001`, ia tak lagi
     * bercerita apa pun bagi pembaca pesan.
     */
    private function labelJenjang(?string $kodeJenjang): string
    {
        if (! $kodeJenjang) {
            return '(tanpa jenjang)';
        }

        return 'jenjang '.(Jenjang::whereKey($kodeJenjang)->value('nama') ?: $kodeJenjang);
    }
}
