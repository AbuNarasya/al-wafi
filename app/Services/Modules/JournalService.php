<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Modul Jurnal Umum (manual). Melengkapi nama_coa, cek akun aktif, memberi nomor
 * JU-YYMM-NNNN, lalu posting (validasi balance). Baris ber-persediaan
 * menggerakkan stok lewat [[InventoryMovement]] (debit = masuk, kredit = keluar).
 *
 * Sejak persediaan memakai FIFO, baris KREDIT ber-persediaan harus bernominal
 * sama persis dengan harga pokok FIFO-nya — kalau tidak, jurnalnya ditolak.
 * Buku besar dan kartu stok tak boleh berpisah lewat pintu manual.
 */
class JournalService
{
    public function create(array $input): JournalEntry
    {
        // Lengkapi nama_coa dari master & pastikan akun valid + aktif.
        $lines = [];
        foreach ($input['lines'] as $l) {
            $coa = CoaDetail::find($l['kode_coa']);
            if (! $coa) {
                throw new AppException(400, "Akun COA {$l['kode_coa']} tidak ditemukan.");
            }
            if ($coa->status !== 'aktif') {
                throw new AppException(400, "Akun COA {$l['kode_coa']} berstatus nonaktif.");
            }
            if (! empty($l['kode_persediaan']) && ! empty($l['kuantiti'])) {
                if (! Inventory::find($l['kode_persediaan'])) {
                    throw new AppException(400, "Item persediaan {$l['kode_persediaan']} tidak ditemukan.");
                }
            }
            $l['nama_coa'] = $coa->nama_coa;
            $lines[] = $l;
        }

        return DB::transaction(function () use ($input, $lines) {
            $referensi = DocNumber::nextJournalRef('JU', $input['tanggal']);

            $entry = PostingService::postJournal([
                'referensi' => $referensi,
                'tanggal' => $input['tanggal'],
                'kode_unit' => $input['kode_unit'] ?? null,
                'kode_dana' => $input['kode_dana'] ?? null,
                'keterangan' => $input['keterangan'] ?? null,
                'sumber_modul' => 'JurnalUmum',
                'id_sumber' => $referensi,
                'id_pengguna' => $input['id_pengguna'] ?? null,
                'lines' => $lines,
            ]);

            // Gerakan stok — debit = masuk (melahirkan lapisan FIFO), kredit = keluar.
            foreach ($lines as $l) {
                if (empty($l['kode_persediaan']) || empty($l['kuantiti'])) {
                    continue;
                }
                $umum = [
                    'kode_persediaan' => $l['kode_persediaan'],
                    'kuantiti' => $l['kuantiti'],
                    'tanggal' => $input['tanggal'],
                    'sumber_modul' => 'JurnalUmum',
                    'sumber_ref' => $referensi,
                    'journal_entry_id' => $entry->id,
                    'keterangan' => $l['keterangan'] ?? ($input['keterangan'] ?? null),
                    'id_pengguna' => $input['id_pengguna'] ?? null,
                ];

                if (Money::gtZero($l['debet'] ?? 0)) {
                    InventoryMovement::masuk([...$umum, 'nilai' => $l['debet'], 'alasan' => 'pembelian']);
                } elseif (Money::gtZero($l['kredit'] ?? 0)) {
                    $mutasi = InventoryMovement::keluar([...$umum, 'alasan' => 'pemakaian']);

                    // Di bawah FIFO harga pokok DIHITUNG dari lapisan, bukan
                    // diketik. Kalau angka kreditnya berbeda, buku besar dan
                    // kartu stok akan berpisah diam-diam — jadi jurnalnya
                    // ditolak, lengkap dengan angka yang benar.
                    if (! Money::eq($mutasi->nilai, $l['kredit'])) {
                        throw new AppException(422,
                            "Baris persediaan {$l['kode_persediaan']}: harga pokok FIFO untuk {$l['kuantiti']} unit "
                            ."adalah {$mutasi->nilai}, sedangkan kredit yang diisi {$l['kredit']}. "
                            .'Samakan nominalnya, atau catat pengeluaran ini lewat menu Persediaan → Mutasi Stok.');
                    }
                }
            }

            return $entry;
        });
    }

    /** Void jurnal manual (reversal). Hanya jurnal bersumber JurnalUmum. */
    public function void(int $id, array $input): JournalEntry
    {
        $entry = JournalEntry::with('lines')->find($id);
        if (! $entry) {
            throw new AppException(404, 'Jurnal tidak ditemukan.');
        }
        if ($entry->sumber_modul !== 'JurnalUmum') {
            throw new AppException(400, "Jurnal ini berasal dari modul {$entry->sumber_modul} dan harus di-void melalui modul tersebut.");
        }
        $totalDebet = $entry->lines->reduce(fn ($s, $l) => Money::add($s, $l->debet), '0');

        return DB::transaction(function () use ($entry, $id, $input, $totalDebet) {
            Authorization::authorizeByUser($input['id_pengguna'] ?? null, $totalDebet);

            // Seluruh pergerakan stok milik jurnal ini dibatalkan sekaligus &
            // mundur, supaya lapisan FIFO pulih ke keadaan sebelum jurnalnya ada.
            InventoryMovement::batalkanDokumen('JurnalUmum', $entry->referensi, $input['id_pengguna'] ?? null);

            return ReversalService::reverseJournalEntry($id, [
                'tanggal' => $input['tanggal'] ?? null,
                'id_pengguna' => $input['id_pengguna'] ?? null,
            ]);
        });
    }
}
