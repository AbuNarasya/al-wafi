<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\OpeningBalance;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Saldo Awal (port opening-balance module dev): kelola baris saldo awal per
 * akun, finalisasi jadi SATU jurnal pembuka (SA-YYMM-NNNN) yang balance, dan
 * void (reversal) untuk revisi.
 */
class OpeningBalanceService
{
    private const SUMBER = 'SaldoAwal';

    /** Tanggal jurnal pembuka = awal periode pembukuan (Pengaturan Perusahaan). */
    private function periodeAwal(): Carbon
    {
        $t = CompanySettings::query()->value('periode_awal_pembukuan');

        return $t ? Carbon::parse($t) : Carbon::create((int) now()->format('Y'), 1, 1);
    }

    private function isPosted(): bool
    {
        return OpeningBalance::where('posted', true)->exists();
    }

    private function assertDraft(): void
    {
        if ($this->isPosted()) {
            throw new AppException(409, 'Saldo awal sudah difinalisasi. Lakukan Void terlebih dahulu untuk mengubahnya.');
        }
    }

    /** Daftar baris + ringkasan keseimbangan. */
    public function state(): array
    {
        $rows = OpeningBalance::with('coa')->orderBy('kode_coa')->get();

        $totalDebet = '0';
        $totalKredit = '0';
        foreach ($rows as $r) {
            if ($r->jenis_saldo === 'debet') {
                $totalDebet = Money::add($totalDebet, $r->saldo);
            } else {
                $totalKredit = Money::add($totalKredit, $r->saldo);
            }
        }

        $posted = $rows->contains(fn ($r) => $r->posted);
        $journalRef = null;
        $entryId = $rows->firstWhere('journal_entry_id', '!=', null)?->journal_entry_id;
        if ($posted && $entryId) {
            $journalRef = JournalEntry::where('id', $entryId)->value('referensi');
        }

        return [
            'rows' => $rows,
            'summary' => [
                'count' => $rows->count(),
                'totalDebet' => Money::of($totalDebet),
                'totalKredit' => Money::of($totalKredit),
                'selisih' => Money::sub($totalDebet, $totalKredit),
                'balanced' => $rows->count() > 0 && Money::eq($totalDebet, $totalKredit),
                'posted' => $posted,
                'journalRef' => $journalRef,
            ],
        ];
    }

    public function addLine(array $data): OpeningBalance
    {
        $this->assertDraft();
        if (OpeningBalance::where('kode_coa', $data['kode_coa'])->exists()) {
            throw new AppException(409, 'Akun ini sudah ada di daftar saldo awal.');
        }

        return OpeningBalance::create($data);
    }

    public function updateLine(int $id, array $data): OpeningBalance
    {
        $this->assertDraft();
        $row = OpeningBalance::findOrFail($id);
        $row->update($data);

        return $row;
    }

    public function removeLine(int $id): void
    {
        $this->assertDraft();
        OpeningBalance::whereKey($id)->delete();
    }

    /** Finalisasi: satu jurnal pembuka balance, lalu kunci baris. */
    public function post(?int $idPengguna): JournalEntry
    {
        $this->assertDraft();
        $rows = OpeningBalance::with('coa')->get();
        if ($rows->count() < 2) {
            throw new AppException(422, 'Butuh minimal 2 akun yang saling menyeimbangkan (total Debet = total Kredit) sebelum finalisasi.');
        }

        $lines = $rows->map(fn ($r) => [
            'kode_coa' => $r->kode_coa,
            'nama_coa' => $r->coa->nama_coa,
            'debet' => $r->jenis_saldo === 'debet' ? Money::of($r->saldo) : '0',
            'kredit' => $r->jenis_saldo === 'kredit' ? Money::of($r->saldo) : '0',
            'keterangan' => 'Saldo awal',
        ])->all();

        $tanggal = $this->periodeAwal();

        return DB::transaction(function () use ($lines, $tanggal, $idPengguna) {
            $referensi = DocNumber::nextJournalRef('SA', $tanggal);
            $entry = PostingService::postJournal([
                'referensi' => $referensi,
                'tanggal' => $tanggal->toDateString(),
                'sumber_modul' => self::SUMBER,
                'keterangan' => 'Jurnal Saldo Awal',
                'id_sumber' => $referensi,
                'id_pengguna' => $idPengguna,
                'lines' => $lines,
            ]);
            OpeningBalance::where('posted', false)->update(['posted' => true, 'journal_entry_id' => $entry->id]);

            return $entry;
        });
    }

    /** Revisi: void jurnal pembuka (reversal), buka kunci baris. */
    public function void(?int $idPengguna): JournalEntry
    {
        $posted = OpeningBalance::where('posted', true)->first();
        if (! $posted?->journal_entry_id) {
            throw new AppException(409, 'Saldo awal belum difinalisasi, tidak ada yang di-void.');
        }
        $entryId = $posted->journal_entry_id;
        $tanggal = $this->periodeAwal();

        return DB::transaction(function () use ($entryId, $tanggal, $idPengguna) {
            $reversal = ReversalService::reverseJournalEntry($entryId, [
                'tanggal' => $tanggal->toDateString(),
                'id_pengguna' => $idPengguna,
                'keteranganPrefix' => 'Void Saldo Awal — ',
            ]);
            OpeningBalance::where('journal_entry_id', $entryId)->update(['posted' => false, 'journal_entry_id' => null]);

            return $reversal;
        });
    }
}
