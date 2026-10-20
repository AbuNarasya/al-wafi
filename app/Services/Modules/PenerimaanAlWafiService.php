<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\PenerimaanAlWafi;
use App\Services\Ledger\PostingService;
use App\Services\Penghubung\KlienDompetToko;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * PENERIMAAN PENCAIRAN DARI AL WAFI — sisi ERP Wafi Mart, Fase 3c-3.
 *
 * Daftar pencairan ditarik dari ERP Al Wafi lewat penghubung; staf menerima satu pencairan saat uangnya sudah masuk:
 *     D Kas/Bank Wafi Mart / K Piutang ke Al Wafi (company_settings.kode_coa_kartu_santri)
 * Nomornya sama dengan nomor PWM- di Al Wafi (satu penerimaan per pencairan). Al Wafi dikabari PALING AKHIR di dalam
 * transaksi: tak menjawab → penerimaan tergulung, ulangi (pengabaran idempoten di sana).
 */
class PenerimaanAlWafiService
{
    public function __construct(private readonly KlienDompetToko $klien) {}

    /** @return list<array<string,mixed>> pencairan dari Al Wafi + penerimaan lokalnya (bila ada). */
    public function daftar(): array
    {
        $lokal = PenerimaanAlWafi::with('penerima')->get()->keyBy('nomor_pencairan');

        return collect($this->klien->daftarPencairan())
            ->map(fn ($p) => $p + ['penerimaan' => $lokal[$p['nomor']] ?? null])->all();
    }

    public function terima(string $nomor, string $kodeCoaBank, int $idPengguna): PenerimaanAlWafi
    {
        if (! BankAccount::where('kode_coa', $kodeCoaBank)->where('status', 'aktif')->exists()) {
            throw new AppException(422, 'Pilih rekening kas/bank yang aktif.');
        }
        $piutang = CompanySettings::query()->value('kode_coa_kartu_santri')
            ?? throw new AppException(422, 'Akun "Piutang ke Al Wafi" (akun kartu santri) belum ditetapkan di Pengaturan Perusahaan.');
        $p = collect($this->klien->daftarPencairan())->firstWhere('nomor', $nomor)
            ?? throw new AppException(404, "Pencairan {$nomor} tidak ada di ERP Al Wafi.");
        if (($p['status'] ?? '') !== 'aktif') {
            throw new AppException(409, "Pencairan {$nomor} sudah dibatalkan di ERP Al Wafi.");
        }

        return DB::transaction(function () use ($p, $nomor, $kodeCoaBank, $idPengguna, $piutang) {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32('penerimaan-al-wafi:'.$nomor)]);
            if (PenerimaanAlWafi::where('nomor_pencairan', $nomor)->exists()) {
                throw new AppException(409, "Pencairan {$nomor} sudah diterima.");
            }
            $total = Money::of($p['total']);
            $ket = "Penerimaan pencairan belanja kartu santri dari Al Wafi {$nomor}";
            $entry = PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => now()->toDateString(), 'keterangan' => $ket,
                'sumber_modul' => PenerimaanAlWafi::SUMBER, 'id_sumber' => $nomor, 'id_pengguna' => $idPengguna,
                'lines' => [
                    ['kode_coa' => $kodeCoaBank, 'debet' => $total, 'kredit' => '0', 'keterangan' => $ket],
                    ['kode_coa' => $piutang, 'debet' => '0', 'kredit' => $total, 'keterangan' => $ket],
                ],
            ]);
            $doc = PenerimaanAlWafi::create([
                'nomor_pencairan' => $nomor, 'sampai_tanggal' => $p['sampai_tanggal'], 'tanggal' => now()->toDateString(),
                'total' => $total, 'kode_coa_bank' => $kodeCoaBank, 'journal_entry_id' => $entry->id, 'diterima_oleh' => $idPengguna,
            ]);
            // Paling akhir: Al Wafi menolak / tak menjawab → penerimaan & jurnalnya tergulung utuh.
            $this->klien->terimaPencairan($nomor, $total);

            return $doc;
        });
    }
}
