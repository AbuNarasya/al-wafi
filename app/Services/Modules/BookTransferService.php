<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\JournalEntry;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Modul Pindah Buku (transfer antar rekening kas/bank). Jurnal: Debit rekening
 * tujuan (kas bertambah), Kredit rekening asal (kas berkurang). Selalu SATU unit.
 */
class BookTransferService
{
    private const SUMBER = 'PindahBuku';

    /** Daftar pindah buku (entry asli saja, bukan pembalik). */
    public function list(): array
    {
        return JournalEntry::with('lines')
            ->where('sumber_modul', self::SUMBER)
            ->whereNull('reversal_of')
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($e) => $this->mapEntry($e))
            ->all();
    }

    /** Jurnal: Debit rekening tujuan, Kredit rekening asal. */
    public function create(array $input, ?int $idPengguna): array
    {
        $asal = BankAccount::with('coa')->find($input['kode_rekening_asal']);
        if (! $asal) {
            throw new AppException(400, 'Rekening asal tidak ditemukan.');
        }
        $tujuan = BankAccount::with('coa')->find($input['kode_rekening_tujuan']);
        if (! $tujuan) {
            throw new AppException(400, 'Rekening tujuan tidak ditemukan.');
        }

        $nominal = Money::of($input['nominal']);
        $lines = [
            ['kode_coa' => $tujuan->kode_coa, 'nama_coa' => $tujuan->coa->nama_coa, 'debet' => $nominal, 'kredit' => '0', 'keterangan' => "Pindah buku masuk — {$input['keterangan']}"],
            ['kode_coa' => $asal->kode_coa, 'nama_coa' => $asal->coa->nama_coa, 'debet' => '0', 'kredit' => $nominal, 'keterangan' => "Pindah buku keluar — {$input['keterangan']}"],
        ];

        return DB::transaction(function () use ($input, $idPengguna, $lines) {
            $ref = DocNumber::nextJournalRef('PB', $input['tanggal']);
            $entry = PostingService::postJournal([
                'referensi' => $ref,
                'tanggal' => $input['tanggal'],
                'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => $input['keterangan'],
                'sumber_modul' => self::SUMBER,
                'id_sumber' => $ref,
                'id_pengguna' => $idPengguna,
                'lines' => $lines,
            ]);

            return $this->mapEntry($entry);
        });
    }

    public function void(int $id, string $alasan, ?int $idPengguna): JournalEntry
    {
        $entry = JournalEntry::with('lines')->find($id);
        if (! $entry) {
            throw new AppException(404, 'Pindah buku tidak ditemukan.');
        }
        if ($entry->sumber_modul !== self::SUMBER) {
            throw new AppException(400, 'Transaksi ini bukan Pindah Buku.');
        }
        $nominal = $entry->lines->reduce(fn ($s, $l) => Money::add($s, $l->debet), '0');
        Authorization::authorizeByUser($idPengguna, $nominal);

        return ReversalService::reverseJournalEntry($id, [
            'keteranganPrefix' => "Void Pindah Buku ({$alasan}) — ",
            'id_pengguna' => $idPengguna,
        ]);
    }

    /** Ringkas satu entry Pindah Buku untuk UI. */
    private function mapEntry(JournalEntry $e): array
    {
        $debit = $e->lines->first(fn ($l) => Money::gtZero($l->debet));
        $kredit = $e->lines->first(fn ($l) => Money::gtZero($l->kredit));

        return [
            'id' => $e->id,
            'referensi' => $e->referensi,
            'tanggal' => $e->tanggal,
            'keterangan' => $e->keterangan,
            'status' => $e->status,
            'kode_unit' => $debit?->kode_unit ?? $kredit?->kode_unit,
            'kode_rekening_tujuan' => $debit?->kode_coa,
            'nama_tujuan' => $debit?->nama_coa,
            'kode_rekening_asal' => $kredit?->kode_coa,
            'nama_asal' => $kredit?->nama_coa,
            'nominal' => $debit ? Money::of($debit->debet) : '0',
        ];
    }
}
