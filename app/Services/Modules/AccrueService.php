<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Accrue;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Accrue (jurnal akrual/penyesuaian non-kas). Debit & kredit dipilih
 * langsung. runReversal membalik accrue periode lalu di awal bulan berjalan.
 * Otorisasi level ditangani lapisan posting-approval (Fase 4), bukan di sini.
 */
class AccrueService
{
    public function create(array $input, ?int $idPengguna): Accrue
    {
        $debet = CoaDetail::find($input['kode_coa_debet']);
        if (! $debet) {
            throw new AppException(400, 'Akun debet tidak ditemukan.');
        }
        $kredit = CoaDetail::find($input['kode_coa_kredit']);
        if (! $kredit) {
            throw new AppException(400, 'Akun kredit tidak ditemukan.');
        }

        return DB::transaction(function () use ($input, $idPengguna, $debet, $kredit) {
            $base = DocNumber::docBase('ACC', $input['tanggal']);
            $last = Accrue::where('nomor_referensi', 'like', $base.'%')
                ->orderByDesc('nomor_referensi')
                ->value('nomor_referensi');
            $ref = DocNumber::nextDocNumber($base, $last);

            $rec = Accrue::create([
                'tanggal' => $input['tanggal'],
                'periode' => $input['periode'] ?? null,
                'kode_coa_debet' => $input['kode_coa_debet'],
                'nama_coa_debet' => $debet->nama_coa,
                'kode_coa_kredit' => $input['kode_coa_kredit'],
                'nama_coa_kredit' => $kredit->nama_coa,
                'nominal' => $input['nominal'],
                'kode_unit' => $input['kode_unit'] ?? null,
                'nomor_referensi' => $ref,
                'keterangan' => $input['keterangan'] ?? null,
                'status' => 'aktif',
                'id_pengguna' => $idPengguna ?? 0,
            ]);

            // Saldo awal pindahan sistem: dokumennya perlu ada supaya bisa
            // dibalik/dikonsumsi nanti, tetapi jurnalnya TIDAK boleh terbit —
            // nilainya sudah masuk lewat jurnal pembuka. Pola yang sama dipakai
            // BankLoanService lewat `posting_pencairan`.
            if (! empty($input['tanpa_jurnal'])) {
                return $rec;
            }

            PostingService::postJournal([
                'referensi' => $ref,
                'tanggal' => $input['tanggal'],
                'kode_unit' => $input['kode_unit'] ?? null,
                'keterangan' => $input['keterangan'] ?? null,
                'sumber_modul' => 'Accrue',
                'id_sumber' => (string) $rec->id_accrue,
                'id_pengguna' => $idPengguna,
                'lines' => [
                    ['kode_coa' => $input['kode_coa_debet'], 'nama_coa' => $debet->nama_coa, 'debet' => $input['nominal'], 'kredit' => '0', 'keterangan' => $input['keterangan'] ?? null, 'kode_bagian' => $input['kode_bagian'] ?? null],
                    ['kode_coa' => $input['kode_coa_kredit'], 'nama_coa' => $kredit->nama_coa, 'debet' => '0', 'kredit' => $input['nominal'], 'keterangan' => $input['keterangan'] ?? null],
                ],
            ]);

            return $rec;
        });
    }

    /** Reversal awal bulan: balik semua accrue aktif dari periode sebelum bulan berjalan. */
    public function runReversal(?int $idPengguna): array
    {
        $now = Carbon::now();
        $currentPeriod = $now->format('Y-m');
        $toReverse = Accrue::where('status', 'aktif')
            ->where('periode', '<', $currentPeriod)
            ->get();
        if ($toReverse->isEmpty()) {
            throw new AppException(422, 'Tidak ada accrue periode lalu yang perlu di-reversal.');
        }

        return DB::transaction(function () use ($toReverse, $now, $idPengguna) {
            $count = 0;
            foreach ($toReverse as $a) {
                if (! $a->nomor_referensi) {
                    continue;
                }
                $entry = JournalEntry::where('referensi', $a->nomor_referensi)
                    ->where('sumber_modul', 'Accrue')
                    ->where('status', 'aktif')
                    ->first();
                if ($entry) {
                    ReversalService::reverseJournalEntry($entry->id, [
                        'tanggal' => $now->toDateString(), 'id_pengguna' => $idPengguna, 'keteranganPrefix' => 'Reversal awal bulan — ',
                    ]);
                }
                $a->update(['status' => 'reversed']);
                $count++;
            }

            return ['reversed' => $count];
        });
    }
}
