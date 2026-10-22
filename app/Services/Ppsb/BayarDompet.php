<?php

namespace App\Services\Ppsb;

use App\Exceptions\AppException;
use App\Models\DompetWali;
use App\Models\MutasiDompet;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * MELUNASI SATU TAGIHAN DARI DOMPET WALI — inti bersama (pembayaran manual &
 * auto-debet). Menggerakkan empat sekaligus: jurnal, saldo dompet, buku mutasi,
 * sisa tagihan. Kas TIDAK bergerak — yang berkurang titipan wali (D Titipan Wali).
 * Tidak melewati verifikasi (dananya sudah diverifikasi saat top-up).
 */
final class BayarDompet
{
    /**
     * @param  TagihanSantri  $tagihan  (dengan relasi jenis)
     * @param  array{id_dompet:int,nominal:string,tanggal:string,id_pengguna:int,namaSantri:string,otomatis?:bool,catatan?:?string}  $opts
     */
    public static function bayarTagihanDariDompet(TagihanSantri $tagihan, array $opts): PembayaranSantri
    {
        // Kunci & baca ulang tagihannya: dua pembayaran serentak atas tagihan yang sama
        // (portal + loket, atau auto-debet) tak boleh sama-sama lolos memakai sisa lama.
        $segar = TagihanSantri::whereKey($tagihan->id)->lockForUpdate()->first();
        if (! $segar || ! $segar->berlaku()) {
            throw new AppException(422, __('Tagihan ini sudah dibatalkan atau dihapus.'));
        }
        $tagihan->forceFill(['sisa' => $segar->sisa, 'status' => $segar->status])->syncOriginal();

        $nominal = Money::of($opts['nominal']);
        $sisaBaru = Money::sub($tagihan->sisa, $nominal);
        if (Money::isNegative($sisaBaru)) {
            throw new AppException(422, __('Nominal melebihi sisa tagihan.'));
        }

        // SPP TIDAK BISA DICICIL dari Dompet Wali: pemotongan harus melunasi
        // seluruh sisanya. Saldo yang kurang dibiarkan utuh dan tagihannya tetap
        // menggantung, bukan dipotong separuh — cicilan dompet membuat satu
        // tagihan bulanan tersebar di banyak potongan kecil yang tak pernah
        // menutup, dan daftar Outstanding SPP jadi tak bisa dibaca.
        //
        // Ditegakkan DI SINI karena inilah satu-satunya pintu dompet: auto-debet
        // maupun tombol "bayar dari dompet" sama-sama lewat sini.
        if ($tagihan->perilaku === 'spp' && ! Money::isZero($sisaBaru)) {
            throw new AppException(422, __('SPP tidak bisa dicicil dari Dompet Wali — pemotongan harus melunasi seluruh sisa tagihan (Rp :sisa). Isi dulu dompetnya sampai cukup; begitu saldonya mencukupi, tagihan ini terpotong penuh dengan sendirinya.', [
                'sisa' => $tagihan->sisa,
            ]));
        }
        $jenis = $tagihan->jenis;
        $akunKredit = $jenis->kode_coa_pendapatan;
        if ($tagihan->sudah_akrual) {
            if (! $jenis->kode_coa_piutang) {
                throw new AppException(422, "Tagihan \"{$jenis->nama}\" sudah diakrualkan tetapi jenis biayanya tidak punya akun piutang.");
            }
            $akunKredit = $jenis->kode_coa_piutang;
        }

        $base = DocNumber::docBase('BYR', $opts['tanggal']);
        $last = PembayaranSantri::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor');
        $nomor = DocNumber::nextDocNumber($base, $last);
        $otomatis = $opts['otomatis'] ?? false;
        // Dari Portal Wali: pencatatnya wali, tak ada staf. Jurnal boleh tanpa
        // pengguna (`journal_entries.id_pengguna` nullable); keterangannya yang
        // menyebut asalnya. Verifikasi memang tak ada — dompet sudah diverifikasi
        // saat top-up, itulah sebabnya pembayaran dompet berlaku seketika.
        $wali = $opts['wali'] ?? null;
        $idPengguna = $wali ? null : ($opts['id_pengguna'] ?? null);
        $pencatat = \App\Services\PortalWali\Pencatat::kolom($idPengguna, $wali);
        $asal = $otomatis ? ' (auto-debet)' : ($wali ? ' (portal wali)' : '');

        $entry = PostingService::postJournal([
            'referensi' => $nomor, 'tanggal' => $opts['tanggal'], 'kode_unit' => $jenis->kode_unit,
            'sumber_modul' => 'PembayaranSantri', 'id_pengguna' => $idPengguna,
            'keterangan' => "{$jenis->nama} dari Dompet Wali{$asal} — {$opts['namaSantri']}",
            'lines' => [
                ['kode_coa' => DompetPolicy::COA_TITIPAN['wali'], 'debet' => $nominal, 'kredit' => '0'],
                ['kode_coa' => $akunKredit, 'debet' => '0', 'kredit' => $nominal],
            ],
        ]);

        $dompet = DompetWali::find($opts['id_dompet']);
        \App\Support\SaldoAtomik::kurangi($dompet, $nominal, 'Saldo Dompet Wali');

        $baseMutasi = DocNumber::docBase('DMP', $opts['tanggal']);
        $lastMutasi = MutasiDompet::where('nomor', 'like', $baseMutasi.'%')->orderByDesc('nomor')->value('nomor');
        MutasiDompet::create([
            'nomor' => DocNumber::nextDocNumber($baseMutasi, $lastMutasi),
            'pemilik' => 'wali', 'id_dompet' => $opts['id_dompet'], 'jenis' => 'bayar_tagihan',
            'nominal' => Money::sub('0', $nominal), 'saldo_setelah' => $dompet->saldo,
            'tanggal' => $opts['tanggal'],
            'keterangan' => "{$jenis->nama}{$asal} — {$opts['namaSantri']}",
            'journal_entry_id' => $entry->id,
        ] + $pencatat);

        $tagihan->update(['sisa' => $sisaBaru, 'status' => Money::isZero($sisaBaru) ? 'lunas' : 'sebagian']);

        return PembayaranSantri::create([
            'nomor' => $nomor, 'id_santri' => $tagihan->id_santri, 'id_tagihan' => $tagihan->id,
            'tanggal' => $opts['tanggal'], 'nominal' => $nominal, 'kode_rekening' => DompetPolicy::COA_TITIPAN['wali'],
            'sumber' => 'dompet_wali', 'metode' => $otomatis ? 'auto_debet' : ($wali ? 'portal_wali' : 'dompet_wali'),
            'catatan' => $opts['catatan'] ?? null, 'status' => 'terverifikasi',
            'diverifikasi_oleh' => $idPengguna,
            'diverifikasi_pada' => Carbon::now(), 'journal_entry_id' => $entry->id,
        ] + $pencatat);
    }
}
