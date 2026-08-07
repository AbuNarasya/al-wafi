<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DompetWali;
use App\Models\JournalEntry;
use App\Models\KoreksiTagihan;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\RencanaAngsuranUangPangkal;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * KOREKSI NOMINAL TAGIHAN SANTRI — buku besar dan buku pembantu bergerak
 * BERSAMA, dalam satu transaksi.
 *
 * Sebelum ini keduanya hanya bisa dibetulkan sendiri-sendiri: jurnal
 * penyesuaian membetulkan buku besar tanpa menyentuh `tagihan_santri`, sehingga
 * neraca benar sementara wali tetap ditagih angka yang keliru. Selisihnya cuma
 * dijembatani ingatan orang, dan setahun kemudian tak ada yang bisa menjelaskan
 * kenapa rekap piutang per santri tak cocok dengan buku besar.
 *
 * BOLEH MENURUNKAN DI BAWAH YANG SUDAH DIBAYAR. Kelebihannya tidak dibiarkan
 * jadi sisa negatif — setiap laporan di sini menganggap sisa ≥ 0, dan angka
 * negatif akan muncul sebagai keanehan tanpa penjelasan. Kelebihan bayar memang
 * KEWAJIBAN yayasan kepada keluarga, jadi ia dipindahkan ke Dompet Wali sebagai
 * titipan — tercatat, bisa dipotongkan ke tagihan berikutnya, dan bisa ditarik.
 */
class KoreksiTagihanService
{
    private const SUMBER = 'KoreksiTagihan';

    /**
     * @return array{tagihan:TagihanSantri,koreksi:KoreksiTagihan,jadwal_dibatalkan:bool}
     */
    public function koreksi(int $idTagihan, string $nominalBaru, string $alasan, int $idPengguna): array
    {
        $tagihan = TagihanSantri::with(['jenis', 'santri'])->find($idTagihan);
        if (! $tagihan) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        if (trim($alasan) === '') {
            throw new AppException(422, 'Alasan koreksi wajib diisi.');
        }
        if ($tagihan->status === 'batal') {
            throw new AppException(422, 'Tagihan ini sudah dibatalkan; tak ada nominal yang bisa dikoreksi.');
        }

        $baru = Money::of($nominalBaru);
        if (! Money::gtZero($baru)) {
            throw new AppException(422, 'Nominal baru harus lebih dari nol. Menolkan tagihan bukan koreksi — itu pembatalan.');
        }

        // Sisa masih bergerak selama ada pembayaran yang belum diputuskan.
        // Mengoreksi angka yang belum diam hanya menambah kekacauan.
        $menunggu = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'menunggu_verifikasi')->count();
        if ($menunggu > 0) {
            throw new AppException(422, "Masih ada {$menunggu} pembayaran yang menunggu verifikasi keuangan. Verifikasi atau tolak dulu.");
        }

        $lama = Money::of($tagihan->nominal);
        if (Money::eq($lama, $baru)) {
            throw new AppException(422, 'Nominalnya sama dengan yang sekarang; tidak ada yang perlu dikoreksi.');
        }

        $jenis = $tagihan->jenis;
        if ($tagihan->sudah_akrual && (! $jenis?->kode_coa_piutang || ! $jenis?->kode_coa_pendapatan)) {
            throw new AppException(422, "Jenis biaya \"{$jenis?->nama}\" belum lengkap akun piutang/pendapatannya, sehingga akrualnya tak bisa disesuaikan. Lengkapi dulu di master Jenis Biaya.");
        }

        $terbayar = PembayaranSantri::where('id_tagihan', $tagihan->id)->where('status', 'terverifikasi')
            ->get(['nominal'])->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0');

        $sisaBaru = Money::sub($baru, $terbayar);
        $kelebihan = Money::lt($sisaBaru, '0') ? Money::sub('0', $sisaBaru) : '0';
        if (Money::gtZero($kelebihan)) {
            $sisaBaru = '0';
        }

        return DB::transaction(function () use ($tagihan, $jenis, $lama, $baru, $terbayar, $sisaBaru, $kelebihan, $alasan, $idPengguna) {
            $entry = $this->jurnalPenyesuaian($tagihan, $jenis, $lama, $baru, $kelebihan, $idPengguna);

            $tagihan->update([
                'nominal' => $baru,
                'sisa' => $sisaBaru,
                'status' => Money::isZero($sisaBaru)
                    ? 'lunas'
                    : (Money::gtZero($terbayar) ? 'sebagian' : 'belum_bayar'),
            ]);

            if (Money::gtZero($kelebihan)) {
                $this->pindahkanKeDompetWali($tagihan, $kelebihan, $idPengguna);
            }

            $jadwalDibatalkan = $this->batalkanJadwalAngsuran($tagihan, $lama, $baru);

            $koreksi = KoreksiTagihan::create([
                'id_tagihan' => $tagihan->id,
                'nominal_lama' => $lama, 'nominal_baru' => $baru,
                'terbayar' => $terbayar, 'kelebihan_ke_dompet' => $kelebihan,
                'alasan' => $alasan, 'journal_entry_id' => $entry?->id,
                'dikoreksi_oleh' => $idPengguna,
            ]);

            return ['tagihan' => $tagihan->refresh(), 'koreksi' => $koreksi, 'jadwal_dibatalkan' => $jadwalDibatalkan];
        });
    }

    /**
     * Jurnal penyesuaian atas selisihnya, plus reklasifikasi kelebihan bayar.
     *
     * Bertanggal HARI INI, bukan tanggal tagihan aslinya: dengan begitu tutup
     * buku bulan lalu tak perlu dibuka, dan koreksinya jatuh di periode saat ia
     * benar-benar diketahui — yang memang lebih benar secara akuntansi.
     *
     * Tagihan yang BELUM diakrualkan tak punya piutang di buku besar, jadi
     * selisihnya tak dijurnal sama sekali; yang tetap perlu hanyalah kelebihan
     * bayar, karena uangnya sudah benar-benar diterima.
     */
    private function jurnalPenyesuaian(TagihanSantri $tagihan, $jenis, string $lama, string $baru, string $kelebihan, int $idPengguna): ?JournalEntry
    {
        $lines = [];
        $selisih = Money::sub($baru, $lama);

        if ($tagihan->sudah_akrual && ! Money::isZero($selisih)) {
            $nilai = Money::gt($selisih, '0') ? $selisih : Money::sub('0', $selisih);
            $naik = Money::gt($selisih, '0');

            // Naik  : Piutang D / Pendapatan K — kewajiban wali bertambah.
            // Turun : Pendapatan D / Piutang K — pendapatan yang tak jadi.
            // `nama_coa` sengaja tak diisi — PostingService menerimanya null, dan
            // menebaknya lewat relasi yang tak ada hanya menambah kebergantungan
            // pada perilaku Eloquent yang halus.
            $lines[] = ['kode_coa' => $jenis->kode_coa_piutang,
                'debet' => $naik ? $nilai : '0', 'kredit' => $naik ? '0' : $nilai,
                'keterangan' => "Koreksi nominal {$jenis->nama}"];
            $lines[] = ['kode_coa' => $jenis->kode_coa_pendapatan,
                'debet' => $naik ? '0' : $nilai, 'kredit' => $naik ? $nilai : '0',
                'keterangan' => "Koreksi nominal {$jenis->nama}"];
        }

        if (Money::gtZero($kelebihan)) {
            // Kelebihan bayar berpindah jadi TITIPAN. Sumber debetnya berbeda:
            // bila tagihannya akrual, yang berkurang piutangnya; bila tidak,
            // pembayarannya dulu langsung diakui pendapatan, jadi pendapatan itu
            // yang harus dikembalikan.
            $sumber = $tagihan->sudah_akrual ? $jenis->kode_coa_piutang : $jenis->kode_coa_pendapatan;
            $lines[] = ['kode_coa' => $sumber, 'debet' => $kelebihan, 'kredit' => '0',
                'keterangan' => 'Kelebihan bayar dipindahkan ke Dompet Wali'];
            $lines[] = ['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'debet' => '0', 'kredit' => $kelebihan,
                'keterangan' => 'Kelebihan bayar dipindahkan ke Dompet Wali'];
        }

        if ($lines === []) {
            return null;
        }

        return PostingService::postJournal([
            'referensi' => $tagihan->santri?->nis ?? $tagihan->santri?->no_pendaftaran ?? (string) $tagihan->id,
            'tanggal' => now()->toDateString(),
            'kode_unit' => $jenis?->kode_unit,
            'sumber_modul' => self::SUMBER,
            'id_sumber' => (string) $tagihan->id,
            'id_pengguna' => $idPengguna,
            'keterangan' => "Koreksi nominal tagihan {$jenis?->nama} — {$tagihan->santri?->nama}",
            'lines' => $lines,
        ]);
    }

    /** Kelebihan bayar masuk Dompet Wali beserta barisnya di buku mutasi. */
    private function pindahkanKeDompetWali(TagihanSantri $tagihan, string $kelebihan, int $idPengguna): void
    {
        $idWali = $tagihan->santri?->id_wali;
        if (! $idWali) {
            throw new AppException(422, 'Santri ini tidak punya wali, sehingga kelebihan bayarnya tak bisa dititipkan.');
        }

        $dompet = DompetWali::firstOrCreate(['id_wali' => $idWali], ['saldo' => '0']);
        $saldoBaru = Money::add($dompet->saldo, $kelebihan);
        $dompet->update(['saldo' => $saldoBaru]);

        $base = DocNumber::docBase('DMP', now()->toDateString());
        $last = MutasiDompet::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');

        MutasiDompet::create([
            'nomor' => DocNumber::nextDocNumber($base, $last),
            'pemilik' => 'wali', 'id_dompet' => $dompet->id,
            // `distribusi_masuk`: uangnya berpindah dari kewajiban lain, bukan
            // setoran baru — kas tidak bergerak, jadi ini bukan `topup`.
            'jenis' => 'distribusi_masuk',
            'nominal' => $kelebihan, 'saldo_setelah' => $saldoBaru,
            'tanggal' => now()->toDateString(),
            'keterangan' => "Kelebihan bayar atas koreksi tagihan {$tagihan->id}",
            'status' => 'terverifikasi',
            'dicatat_oleh' => $idPengguna,
        ]);
    }

    /**
     * Jadwal angsuran yang sudah tak sepadan dengan nominal barunya.
     *
     * Dinonaktifkan, BUKAN dihitung ulang. Rencana angsuran punya
     * `disepakati_pada` dan `disepakati_oleh` — ia kesepakatan dengan wali.
     * Menulis ulang angkanya sendiri menghasilkan jadwal yang tak pernah
     * disetujui siapa pun, dengan tanggal kesepakatan lama menempel di atasnya.
     * Jadi yang lama digugurkan dan yang baru harus disusun bersama walinya.
     */
    private function batalkanJadwalAngsuran(TagihanSantri $tagihan, string $lama, string $baru): bool
    {
        $rencana = RencanaAngsuranUangPangkal::where('id_tagihan', $tagihan->id)->where('status', 'aktif')->first();
        if (! $rencana) {
            return false;
        }

        $rencana->update([
            'status' => 'digantikan',
            'alasan' => trim(($rencana->alasan ? $rencana->alasan.' | ' : '')
                ."Nominal tagihan dikoreksi {$lama} → {$baru}; jadwal termin harus disusun ulang."),
        ]);

        return true;
    }
}
