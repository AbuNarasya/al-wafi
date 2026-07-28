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
 * menggerakkan stok (debit=masuk weighted-avg, kredit=keluar).
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
                'keterangan' => $input['keterangan'] ?? null,
                'sumber_modul' => 'JurnalUmum',
                'id_sumber' => $referensi,
                'id_pengguna' => $input['id_pengguna'] ?? null,
                'lines' => $lines,
            ]);

            // #6: gerakan stok — debit=stok masuk (weighted avg), kredit=keluar.
            foreach ($lines as $l) {
                if (empty($l['kode_persediaan']) || empty($l['kuantiti'])) {
                    continue;
                }
                if (Money::gtZero($l['debet'] ?? 0)) {
                    InventoryMovement::applyStockIn($l['kode_persediaan'], $l['kuantiti'], $l['debet']);
                } elseif (Money::gtZero($l['kredit'] ?? 0)) {
                    InventoryMovement::applyStockOut($l['kode_persediaan'], $l['kuantiti']);
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

            foreach ($entry->lines as $l) {
                if (! $l->kode_persediaan || ! $l->kuantiti) {
                    continue;
                }
                if (Money::gtZero($l->debet)) {
                    InventoryMovement::rollbackStockIn($l->kode_persediaan, $l->kuantiti);
                } elseif (Money::gtZero($l->kredit)) {
                    InventoryMovement::rollbackStockOut($l->kode_persediaan, $l->kuantiti);
                }
            }

            return ReversalService::reverseJournalEntry($id, [
                'tanggal' => $input['tanggal'] ?? null,
                'id_pengguna' => $input['id_pengguna'] ?? null,
            ]);
        });
    }
}
