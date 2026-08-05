<?php

namespace Tests\Concerns;

use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Models\User;

/**
 * Melunasi tagihan REGISTRASI seorang calon — syarat memperoleh potongan
 * gelombang sejak potongan tak lagi melekat otomatis pada gelombangnya.
 *
 * Sengaja menulis langsung ke tabel, BUKAN lewat PembayaranSantriService:
 * pelunasan ini cuma prasyarat, sedangkan jalur service ikut menerbitkan jurnal
 * dan menuntut rekening kas — dua hal yang tak ada urusannya dengan apa yang
 * diuji, dan akan memaksa belasan fixture menambah master yang tak mereka
 * perlukan. Test yang memang menguji ALUR pembayaran tetap memakai servicenya.
 */
trait MelunasiRegistrasi
{
    /**
     * @param  string|null  $tanggal  tanggal pelunasan; inilah yang dibandingkan
     *                                dengan periode berlaku gelombang.
     */
    protected function lunasiRegistrasi(int $idSantri, ?string $tanggal = null, ?int $idPencatat = null): void
    {
        $tagihan = TagihanSantri::where('id_santri', $idSantri)
            ->where('perilaku', 'registrasi')
            ->orderByDesc('id')->first();
        if (! $tagihan) {
            return; // tak pernah ditagih registrasi — memang tak ada yang dilunasi
        }

        PembayaranSantri::create([
            'nomor' => 'UJI-REG-'.$tagihan->id,
            'id_santri' => $idSantri,
            'id_tagihan' => $tagihan->id,
            'tanggal' => $tanggal ?: now()->toDateString(),
            'nominal' => $tagihan->nominal,
            'kode_rekening' => 'UJI',
            'status' => 'terverifikasi',
            // `dicatat_oleh` berkunci asing ke users; id 1 belum tentu ada di
            // fixture, jadi diambil pengguna mana pun yang sudah dibuat.
            'dicatat_oleh' => $idPencatat ?? User::query()->value('id_pengguna'),
        ]);

        $tagihan->update(['sisa' => '0']);
    }
}
