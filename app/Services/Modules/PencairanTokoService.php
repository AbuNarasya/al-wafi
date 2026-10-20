<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\PencairanToko;
use App\Models\TransaksiDompetToko;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PENCAIRAN KE WAFI MART — sisi ERP Al Wafi, Fase 3c-3 (mingguan, manual; keputusan user 20 Okt 2026).
 *
 * Belanja kartu santri di Wafi Mart sudah menjadi hutang lewat jurnal malam (D Titipan Uang Saku / K Hutang ke Wafi
 * Mart). Pencairan MELUNASI hutang itu: D Hutang ke Wafi Mart / K Bank, sebesar transaksi penghubung yang SUDAH
 * dijurnal & belum dicairkan s.d. tanggal itu (potong − kembalikan). Tiap transaksi hanya sekali (`id_pencairan_toko`).
 *
 * Wafi Mart mencatat penerimaannya sendiri dengan nomor yang sama & mengabari lewat penghubung (`tandaiDiterima`);
 * sejak itu pencairan tak bisa dibatalkan di sini.
 */
class PencairanTokoService
{
    /** @return array{transaksi:Collection, total:string, belum_dijurnal:int} */
    public function menunggu(string $sampai, bool $kunci = false): array
    {
        $t = TransaksiDompetToko::where('status', 'berhasil')->whereNotNull('dijurnal_pada')->whereNull('id_pencairan_toko')
            ->whereDate('tanggal', '<=', $sampai)->when($kunci, fn ($q) => $q->lockForUpdate())->get(['id', 'jenis', 'nominal']);
        $total = $t->reduce(fn ($s, $x) => $x->jenis === 'potong' ? Money::add($s, $x->nominal) : Money::sub($s, $x->nominal), '0');

        return [
            'transaksi' => $t, 'total' => $total,
            // Belum dijurnal = belum jadi hutang → belum bisa dicairkan (jurnal malam, atau tombol di layar).
            'belum_dijurnal' => TransaksiDompetToko::where('status', 'berhasil')->whereNull('dijurnal_pada')
                ->whereDate('tanggal', '<=', $sampai)->count(),
        ];
    }

    public function cairkan(string $sampai, string $kodeCoaBank, int $idPengguna): PencairanToko
    {
        if (! BankAccount::where('kode_coa', $kodeCoaBank)->where('status', 'aktif')->exists()) {
            throw new AppException(422, 'Pilih rekening kas/bank yang aktif.');
        }
        $hutang = CompanySettings::query()->value('kode_coa_hutang_toko')
            ?? throw new AppException(422, 'Akun "Hutang ke Wafi Mart" belum ditetapkan di Pengaturan Perusahaan.');

        return DB::transaction(function () use ($sampai, $kodeCoaBank, $idPengguna, $hutang) {
            $m = $this->menunggu($sampai, kunci: true);
            if (! Money::gtZero($m['total'])) {
                throw new AppException(422, 'Tidak ada belanja kartu yang menunggu dicairkan sampai tanggal itu.');
            }
            $base = DocNumber::docBase('PWM', now());
            $nomor = DocNumber::nextDocNumber($base, PencairanToko::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor'));
            $ket = 'Pencairan belanja kartu santri ke Wafi Mart s.d. '.Carbon::parse($sampai)->format('d/m/Y')." ({$nomor})";

            $entry = PostingService::postJournal([
                'referensi' => $nomor, 'tanggal' => now()->toDateString(), 'keterangan' => $ket,
                'sumber_modul' => PencairanToko::SUMBER, 'id_sumber' => $nomor, 'id_pengguna' => $idPengguna,
                'lines' => [
                    ['kode_coa' => $hutang, 'debet' => $m['total'], 'kredit' => '0', 'keterangan' => $ket],
                    ['kode_coa' => $kodeCoaBank, 'debet' => '0', 'kredit' => $m['total'], 'keterangan' => $ket],
                ],
            ]);
            $doc = PencairanToko::create([
                'nomor' => $nomor, 'sampai_tanggal' => $sampai, 'jumlah_transaksi' => $m['transaksi']->count(), 'total' => $m['total'],
                'kode_coa_bank' => $kodeCoaBank, 'status' => 'aktif', 'journal_entry_id' => $entry->id, 'dibuat_oleh' => $idPengguna,
            ]);
            TransaksiDompetToko::whereIn('id', $m['transaksi']->pluck('id'))->update(['id_pencairan_toko' => $doc->id]);

            return $doc;
        });
    }

    public function batal(int $id, string $alasan, int $idPengguna): PencairanToko
    {
        return DB::transaction(function () use ($id, $alasan, $idPengguna) {
            $doc = PencairanToko::lockForUpdate()->find($id) ?? throw new AppException(404, 'Pencairan tidak ditemukan.');
            if ($doc->status === 'batal') {
                throw new AppException(409, 'Pencairan ini sudah dibatalkan.');
            }
            if ($doc->diterima_toko_pada) {
                throw new AppException(409, 'Wafi Mart sudah mencatat penerimaan pencairan ini — tak bisa dibatalkan sepihak.');
            }
            if ($doc->journal_entry_id && JournalEntry::whereKey($doc->journal_entry_id)->where('status', 'aktif')->exists()) {
                ReversalService::reverseJournalEntry($doc->journal_entry_id, ['id_pengguna' => $idPengguna, 'keteranganPrefix' => "Batal ({$alasan}) — "]);
            }
            TransaksiDompetToko::where('id_pencairan_toko', $doc->id)->update(['id_pencairan_toko' => null]);
            $doc->update(['status' => 'batal', 'alasan_batal' => $alasan]);

            return $doc;
        });
    }

    /** Untuk penghubung: pencairan 90 hari terakhir. */
    public function daftarUntukToko(): array
    {
        return PencairanToko::where('created_at', '>=', now()->subDays(90))->orderByDesc('id')->get()
            ->map(fn (PencairanToko $p) => [
                'nomor' => $p->nomor, 'tanggal' => $p->created_at->toDateString(), 'sampai_tanggal' => $p->sampai_tanggal->toDateString(),
                'jumlah_transaksi' => $p->jumlah_transaksi, 'total' => Money::of($p->total), 'status' => $p->status,
                'diterima' => (bool) $p->diterima_toko_pada,
            ])->all();
    }

    /** Wafi Mart mengabari uangnya sudah dicatat masuk. Idempoten. */
    public function tandaiDiterima(string $nomor, string|int|float $total): array
    {
        return DB::transaction(function () use ($nomor, $total) {
            $doc = PencairanToko::where('nomor', $nomor)->lockForUpdate()->first() ?? throw new AppException(404, "Pencairan {$nomor} tidak ditemukan.");
            if ($doc->status !== 'aktif') {
                throw new AppException(409, "Pencairan {$nomor} sudah dibatalkan di ERP Al Wafi.");
            }
            if (! Money::eq($doc->total, $total)) {
                throw new AppException(409, "Nilai pencairan {$nomor} tidak cocok (Al Wafi Rp ".number_format((float) $doc->total, 0, ',', '.').').');
            }
            $doc->diterima_toko_pada ??= now();
            $doc->save();

            return ['nomor' => $doc->nomor, 'diterima' => true];
        });
    }
}
