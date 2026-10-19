<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Models\LokasiPersediaan;
use App\Models\PencairanKartu;
use App\Models\Penjualan;
use App\Models\ReturPenjualan;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PENCAIRAN BELANJA KARTU KE TOKO (D3, mingguan).
 *
 * Pendapatannya sudah dibukukan saat tutup shift (D Titipan Uang Saku / K Pendapatan). Yang
 * tersisa hanya MEMINDAHKAN UANGNYA dari rekening penampung titipan santri ke kas/bank toko:
 *
 *     D Kas/Bank toko / K Rekening penampung titipan      (Pindah Buku)
 *
 * Sebesar struk kartu selesai (shift sudah ditutup) sampai tanggal itu dikurangi retur kartu
 * yang belum diperhitungkan. Tiap struk & retur hanya bisa dicairkan SEKALI (bertanda
 * `id_pencairan`, dikunci saat dihitung). Batal = jurnal dibalik, tandanya dilepas.
 */
class PencairanKartuService
{
    /**
     * Struk & retur kartu yang menunggu dicairkan.
     *
     * @return array{penjualan:\Illuminate\Support\Collection,retur:\Illuminate\Support\Collection,belanja:string,kembali:string,total:string}
     */
    public function menunggu(string $kodeLokasi, string $sampai, bool $kunci = false): array
    {
        $jual = Penjualan::where('kode_lokasi', $kodeLokasi)->where('metode', 'dompet')->where('status', 'selesai')
            ->whereNull('id_pencairan')->whereDate('waktu', '<=', $sampai)
            // Hanya shift yang SUDAH ditutup: jurnal pendapatannya sudah ada.
            ->whereHas('shift', fn ($s) => $s->where('status', '!=', 'buka'))
            ->when($kunci, fn ($q) => $q->lockForUpdate())->get(['id_penjualan', 'total']);
        $retur = ReturPenjualan::where('kode_lokasi', $kodeLokasi)->where('metode', 'dompet')
            ->whereNull('id_pencairan')->whereDate('waktu', '<=', $sampai)
            ->whereHas('shift', fn ($s) => $s->where('status', '!=', 'buka'))
            ->when($kunci, fn ($q) => $q->lockForUpdate())->get(['id_retur', 'total']);

        $belanja = Money::of($jual->sum('total'));
        $kembali = Money::of($retur->sum('total'));

        return ['penjualan' => $jual, 'retur' => $retur, 'belanja' => $belanja, 'kembali' => $kembali, 'total' => Money::sub($belanja, $kembali)];
    }

    public function cairkan(string $kodeLokasi, string $sampai, string $coaSumber, string $coaTujuan, int $idPengguna): PencairanKartu
    {
        $lokasi = LokasiPersediaan::find($kodeLokasi) ?? throw new AppException(404, 'Toko tidak ditemukan.');
        foreach ([$coaSumber => 'Rekening sumber', $coaTujuan => 'Kas/bank toko'] as $coa => $label) {
            if (! BankAccount::where('kode_coa', $coa)->where('status', 'aktif')->exists()) {
                throw new AppException(422, "{$label} harus rekening kas/bank yang aktif.");
            }
        }
        if ($coaSumber === $coaTujuan) {
            throw new AppException(422, 'Rekening sumber dan tujuan tidak boleh sama.');
        }

        return DB::transaction(function () use ($lokasi, $sampai, $coaSumber, $coaTujuan, $idPengguna) {
            $m = $this->menunggu($lokasi->kode, $sampai, kunci: true);
            if (! Money::gtZero($m['total'])) {
                throw new AppException(422, "Tidak ada belanja kartu {$lokasi->nama} yang menunggu dicairkan sampai tanggal itu.");
            }

            $base = DocNumber::docBase('PCK', $sampai);
            $nomor = DocNumber::nextDocNumber($base, PencairanKartu::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor'));
            $ket = "Pencairan belanja kartu {$lokasi->nama} s.d. ".\Illuminate\Support\Carbon::parse($sampai)->format('d/m/Y')." ({$nomor})";

            $entry = PostingService::postJournal([
                'referensi' => $nomor,
                'tanggal' => now()->toDateString(),
                'kode_unit' => $lokasi->kode_unit,
                'keterangan' => $ket,
                'sumber_modul' => PencairanKartu::SUMBER,
                'id_sumber' => $nomor,
                'id_pengguna' => $idPengguna,
                'lines' => [
                    ['kode_coa' => $coaTujuan, 'debet' => $m['total'], 'kredit' => '0', 'keterangan' => $ket],
                    ['kode_coa' => $coaSumber, 'debet' => '0', 'kredit' => $m['total'], 'keterangan' => $ket],
                ],
            ]);

            $doc = PencairanKartu::create([
                'nomor' => $nomor, 'kode_lokasi' => $lokasi->kode, 'sampai_tanggal' => $sampai,
                'total_belanja' => $m['belanja'], 'total_retur' => $m['kembali'], 'total' => $m['total'],
                'kode_coa_sumber' => $coaSumber, 'kode_coa_tujuan' => $coaTujuan, 'status' => 'aktif',
                'journal_entry_id' => $entry->id, 'dibuat_oleh' => $idPengguna,
            ]);
            Penjualan::whereIn('id_penjualan', $m['penjualan']->pluck('id_penjualan'))->update(['id_pencairan' => $doc->id_pencairan]);
            ReturPenjualan::whereIn('id_retur', $m['retur']->pluck('id_retur'))->update(['id_pencairan' => $doc->id_pencairan]);

            return $doc;
        });
    }

    public function batal(int $id, string $alasan, int $idPengguna): PencairanKartu
    {
        $doc = PencairanKartu::find($id) ?? throw new AppException(404, 'Pencairan tidak ditemukan.');
        if ($doc->status === 'batal') {
            throw new AppException(409, 'Pencairan ini sudah dibatalkan.');
        }

        return DB::transaction(function () use ($doc, $alasan, $idPengguna) {
            if ($doc->journal_entry_id && JournalEntry::whereKey($doc->journal_entry_id)->where('status', 'aktif')->exists()) {
                ReversalService::reverseJournalEntry($doc->journal_entry_id, ['id_pengguna' => $idPengguna, 'keteranganPrefix' => "Batal ({$alasan}) — "]);
            }
            Penjualan::where('id_pencairan', $doc->id_pencairan)->update(['id_pencairan' => null]);
            ReturPenjualan::where('id_pencairan', $doc->id_pencairan)->update(['id_pencairan' => null]);
            $doc->update(['status' => 'batal', 'alasan_batal' => $alasan]);

            return $doc;
        });
    }
}
