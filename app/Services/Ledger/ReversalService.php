<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\JournalEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Membalik (void) sebuah journal entry TANPA menghapus data:
 *  1. Membuat entry pembalik (debet ⇄ kredit ditukar, reversal_of → entry asal).
 *  2. Menandai entry asal status = void.
 * Keduanya atomik dalam satu transaksi.
 */
final class ReversalService
{
    /**
     * @param  array{tanggal?:string,keteranganPrefix?:string,id_pengguna?:int|null}  $opts
     */
    public static function reverseJournalEntry(int $entryId, array $opts = []): JournalEntry
    {
        return DB::transaction(function () use ($entryId, $opts) {
            $entry = JournalEntry::with('lines')->find($entryId);
            if (! $entry) {
                throw new AppException(404, 'Jurnal tidak ditemukan.');
            }
            if ($entry->status === 'void') {
                throw new AppException(409, 'Jurnal ini sudah di-void sebelumnya.');
            }

            $tanggal = $opts['tanggal'] ?? Carbon::now()->toDateString();

            // Tanggal pembalik harus di periode yang boleh dijurnal (tutup buku).
            PeriodService::assertPeriodPostable($tanggal);

            $prefix = $opts['keteranganPrefix'] ?? 'Reversal — ';

            $reversal = JournalEntry::create([
                'referensi' => $entry->referensi,
                'tanggal' => $tanggal,
                'keterangan' => $prefix.($entry->keterangan ?? $entry->referensi),
                'sumber_modul' => $entry->sumber_modul,
                'id_sumber' => $entry->id_sumber,
                'reversal_of' => $entry->id,
                'id_pengguna' => $opts['id_pengguna'] ?? null,
            ]);

            foreach ($entry->lines as $l) {
                $reversal->lines()->create([
                    'kode_coa' => $l->kode_coa,
                    'nama_coa' => $l->nama_coa,
                    // pembalik: tukar debet ⇄ kredit
                    'debet' => $l->kredit,
                    'kredit' => $l->debet,
                    'keterangan' => $prefix.($l->keterangan ?? ''),
                    // Dimensi bagian & unit ikut disalin PER BARIS agar pembalik
                    // meniadakan angka di bagian/unit yang persis sama.
                    'kode_bagian' => $l->kode_bagian,
                    'kode_unit' => $l->kode_unit,
                ]);
            }

            $entry->update(['status' => 'void']);

            // refresh() agar atribut default DB (status='aktif', created_at) termuat.
            $reversal->refresh();

            return $reversal->load('lines');
        });
    }
}
