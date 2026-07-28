<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationItem;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpeningBalance;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Modul Rekonsiliasi Bank (buku vs rekening koran). Item = baris jurnal akun
 * bank; cleared = muncul di koran. Penyesuaian (is_adjustment) memposting jurnal
 * koreksi (biaya admin / bagi hasil). Finalize hanya bila selisih 0.
 */
class BankReconciliationService
{
    private const SUMBER = 'RekonsiliasiBank';

    /** Saldo buku (orientasi debet — akun bank debet-normal) s/d tanggal. */
    private function saldoBukuAsOf(string $kodeCoa, string $tanggal): string
    {
        $saldo = '0';
        foreach (OpeningBalance::where('kode_coa', $kodeCoa)->get() as $o) {
            $saldo = $o->jenis_saldo === 'debet'
                ? Money::add($saldo, $o->saldo)
                : Money::sub($saldo, $o->saldo);
        }
        $sumDebet = JournalLine::where('kode_coa', $kodeCoa)
            ->whereHas('entry', fn ($q) => $q->where('tanggal', '<=', $tanggal))->sum('debet');
        $sumKredit = JournalLine::where('kode_coa', $kodeCoa)
            ->whereHas('entry', fn ($q) => $q->where('tanggal', '<=', $tanggal))->sum('kredit');

        return Money::sub(Money::add($saldo, $sumDebet), $sumKredit);
    }

    /** Nilai item orientasi debet. */
    private function itemNormal($i): string
    {
        return Money::sub($i->debet, $i->kredit);
    }

    /**
     * selisih = saldo_bank − (saldo_buku + Σadj) + Σ(item reguler belum cleared).
     * @return array{efektifBuku:string,selisih:string,clearedCount:int}
     */
    private function computeTotals(string $saldoBank, string $saldoBuku, $items): array
    {
        $sumAdj = '0';
        $sumUnclearedReg = '0';
        $clearedCount = 0;
        foreach ($items as $i) {
            if ($i->is_adjustment) {
                $sumAdj = Money::add($sumAdj, $this->itemNormal($i));
            } elseif (! $i->cleared) {
                $sumUnclearedReg = Money::add($sumUnclearedReg, $this->itemNormal($i));
            }
            if ($i->cleared) {
                $clearedCount++;
            }
        }
        $efektifBuku = Money::add($saldoBuku, $sumAdj);
        $selisih = Money::add(Money::sub($saldoBank, $efektifBuku), $sumUnclearedReg);

        return ['efektifBuku' => $efektifBuku, 'selisih' => $selisih, 'clearedCount' => $clearedCount];
    }

    /** Buat rekonsiliasi baru: snapshot saldo buku + muat baris bank belum ter-rekonsiliasi. */
    public function create(array $input, ?int $idPengguna): array
    {
        $bank = BankAccount::find($input['kode_coa']);
        if (! $bank) {
            throw new AppException(400, 'Akun bank tidak ditemukan.');
        }

        $saldoBuku = $this->saldoBukuAsOf($input['kode_coa'], $input['tanggal']);

        // Baris yang sudah cleared pada rekonsiliasi FINAL → jangan dimuat lagi.
        $reconciledIds = BankReconciliationItem::where('cleared', true)
            ->whereHas('rekonsiliasi', fn ($q) => $q->where('status', 'selesai')->where('kode_coa', $input['kode_coa']))
            ->pluck('journal_line_id')
            ->all();

        $lines = JournalLine::where('kode_coa', $input['kode_coa'])
            ->whereHas('entry', fn ($q) => $q->where('tanggal', '<=', $input['tanggal']))
            ->with(['entry:id,tanggal,referensi,keterangan'])
            ->get()
            ->sortBy([fn ($a, $b) => $a->entry->tanggal <=> $b->entry->tanggal, fn ($a, $b) => $a->id <=> $b->id]);

        $rec = DB::transaction(function () use ($input, $idPengguna, $saldoBuku, $lines, $reconciledIds) {
            $recon = BankReconciliation::create([
                'kode_coa' => $input['kode_coa'],
                'tanggal' => $input['tanggal'],
                'saldo_bank' => Money::of($input['saldo_bank']),
                'saldo_buku' => $saldoBuku,
                'status' => 'draft',
                'keterangan' => $input['keterangan'] ?? null,
                'id_pengguna' => $idPengguna,
            ]);
            foreach ($lines as $l) {
                if (in_array($l->id, $reconciledIds, true)) {
                    continue;
                }
                $recon->items()->create([
                    'journal_line_id' => $l->id,
                    'entry_id' => $l->entry_id,
                    'tanggal' => $l->entry->tanggal,
                    'keterangan' => $l->keterangan ?? $l->entry->keterangan ?? $l->entry->referensi,
                    'debet' => $l->debet,
                    'kredit' => $l->kredit,
                    'cleared' => false,
                    'is_adjustment' => false,
                ]);
            }

            return $recon;
        });

        return $this->get($rec->id);
    }

    public function get(int $id): array
    {
        $rec = BankReconciliation::with(['items' => fn ($q) => $q->orderBy('tanggal')->orderBy('id')])->find($id);
        if (! $rec) {
            throw new AppException(404, 'Rekonsiliasi tidak ditemukan.');
        }
        $bank = BankAccount::find($rec->kode_coa);
        $totals = $this->computeTotals(Money::of($rec->saldo_bank), Money::of($rec->saldo_buku), $rec->items);

        return [
            'id' => $rec->id,
            'kode_coa' => $rec->kode_coa,
            'nama_rekening' => $bank?->nama_rekening ?? $rec->kode_coa,
            'tanggal' => $rec->tanggal,
            'saldo_bank' => Money::of($rec->saldo_bank),
            'saldo_buku' => Money::of($rec->saldo_buku),
            'saldo_buku_efektif' => $totals['efektifBuku'],
            'selisih' => $totals['selisih'],
            'jumlah_cleared' => $totals['clearedCount'],
            'status' => $rec->status,
            'keterangan' => $rec->keterangan,
            'items' => $rec->items->map(fn ($i) => [
                'id' => $i->id,
                'journal_line_id' => $i->journal_line_id,
                'tanggal' => $i->tanggal,
                'keterangan' => $i->keterangan,
                'debet' => Money::of($i->debet),
                'kredit' => Money::of($i->kredit),
                'cleared' => $i->cleared,
                'is_adjustment' => $i->is_adjustment,
            ])->all(),
        ];
    }

    public function toggleItem(int $id, int $itemId, bool $cleared): array
    {
        $rec = BankReconciliation::find($id);
        if (! $rec) {
            throw new AppException(404, 'Rekonsiliasi tidak ditemukan.');
        }
        if ($rec->status !== 'draft') {
            throw new AppException(409, 'Rekonsiliasi sudah diselesaikan.');
        }
        $item = BankReconciliationItem::find($itemId);
        if (! $item || $item->id_rekonsiliasi !== $id) {
            throw new AppException(404, 'Item tidak ditemukan.');
        }
        $item->update(['cleared' => $cleared]);

        return $this->get($id);
    }

    /** Posting jurnal penyesuaian + tambah sebagai item cleared. */
    public function adjustment(int $id, array $input, ?int $idPengguna): array
    {
        $rec = BankReconciliation::find($id);
        if (! $rec) {
            throw new AppException(404, 'Rekonsiliasi tidak ditemukan.');
        }
        if ($rec->status !== 'draft') {
            throw new AppException(409, 'Rekonsiliasi sudah diselesaikan.');
        }
        $bank = BankAccount::with('coa')->find($rec->kode_coa);
        if (! $bank) {
            throw new AppException(400, 'Akun bank tidak ditemukan.');
        }
        $lawan = CoaDetail::find($input['kode_coa_lawan']);
        if (! $lawan) {
            throw new AppException(400, 'Akun lawan tidak ditemukan.');
        }

        $nominal = Money::of($input['nominal']);
        $ket = $input['keterangan'] ?? "Penyesuaian rekonsiliasi {$bank->nama_rekening}";
        $bankDebet = $input['arah'] === 'tambah';
        $lines = $bankDebet
            ? [
                ['kode_coa' => $rec->kode_coa, 'nama_coa' => $bank->coa->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket],
                ['kode_coa' => $lawan->kode_coa, 'nama_coa' => $lawan->nama_coa, 'debet' => '0', 'kredit' => $nominal, 'keterangan' => $ket],
            ]
            : [
                ['kode_coa' => $lawan->kode_coa, 'nama_coa' => $lawan->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => $ket],
                ['kode_coa' => $rec->kode_coa, 'nama_coa' => $bank->coa->nama_coa, 'debet' => '0', 'kredit' => $nominal, 'keterangan' => $ket],
            ];

        DB::transaction(function () use ($rec, $id, $idPengguna, $lines, $ket) {
            $ref = DocNumber::nextJournalRef('REK', $rec->tanggal);
            $entry = PostingService::postJournal([
                'referensi' => $ref, 'tanggal' => $rec->tanggal, 'keterangan' => $ket,
                'sumber_modul' => self::SUMBER, 'id_sumber' => (string) $id, 'id_pengguna' => $idPengguna, 'lines' => $lines,
            ]);
            $bankLine = $entry->lines->firstWhere('kode_coa', $rec->kode_coa);
            $rec->items()->create([
                'journal_line_id' => $bankLine->id,
                'entry_id' => $entry->id,
                'tanggal' => $rec->tanggal,
                'keterangan' => "[Penyesuaian] {$ket}",
                'debet' => $bankLine->debet,
                'kredit' => $bankLine->kredit,
                'cleared' => true,
                'is_adjustment' => true,
            ]);
        });

        return $this->get($id);
    }

    public function finalize(int $id): array
    {
        $rec = BankReconciliation::with('items')->find($id);
        if (! $rec) {
            throw new AppException(404, 'Rekonsiliasi tidak ditemukan.');
        }
        if ($rec->status !== 'draft') {
            throw new AppException(409, 'Rekonsiliasi sudah diselesaikan.');
        }
        $totals = $this->computeTotals(Money::of($rec->saldo_bank), Money::of($rec->saldo_buku), $rec->items);
        if (! Money::isZero($totals['selisih'])) {
            throw new AppException(422, "Belum balance — selisih {$totals['selisih']}. Tandai item cleared atau buat penyesuaian hingga selisih 0.");
        }
        $rec->update(['status' => 'selesai']);

        return $this->get($id);
    }

    /** Hapus draft: balik jurnal penyesuaian lalu hapus (items cascade). */
    public function remove(int $id, ?int $idPengguna): array
    {
        $rec = BankReconciliation::with('items')->find($id);
        if (! $rec) {
            throw new AppException(404, 'Rekonsiliasi tidak ditemukan.');
        }
        if ($rec->status !== 'draft') {
            throw new AppException(409, 'Hanya draft yang bisa dihapus.');
        }
        DB::transaction(function () use ($rec, $idPengguna, $id) {
            foreach ($rec->items as $it) {
                if ($it->is_adjustment) {
                    $entry = JournalEntry::where('id', $it->entry_id)->where('status', 'aktif')->first();
                    if ($entry) {
                        ReversalService::reverseJournalEntry($entry->id, [
                            'id_pengguna' => $idPengguna, 'keteranganPrefix' => 'Batal penyesuaian rekonsiliasi — ',
                        ]);
                    }
                }
            }
            BankReconciliation::destroy($id);
        });

        return ['ok' => true];
    }
}
