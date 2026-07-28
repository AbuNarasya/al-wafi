<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashIn;
use App\Models\CoaDetail;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Ledger\Authorization;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Kas Masuk (Receivable Voucher). Jurnal: Debit Kas/Bank; Kredit tiap
 * akun rincian. Baris uang_muka diakui bertahap lewat akuiPendapatan.
 */
class CashInService
{
    /** Simpan voucher + jurnal (Debit Kas/Bank; Kredit tiap akun rincian). */
    public function create(array $input, ?int $idPengguna): CashIn
    {
        $rek = BankAccount::with('coa')->find($input['kode_rekening']);
        if (! $rek) {
            throw new AppException(400, 'Kas/Rekening tidak ditemukan.');
        }
        $unit = BusinessUnit::find($input['kode_unit']);
        if (! $unit) {
            throw new AppException(400, 'Unit bisnis tidak ditemukan.');
        }

        $total = '0';
        $details = [];
        $jLines = [];
        $sales = []; // penjualan persediaan → stok keluar (setelah posting)

        foreach ($input['details'] as $l) {
            $coa = CoaDetail::find($l['kode_coa']);
            if (! $coa) {
                throw new AppException(400, "Akun COA {$l['kode_coa']} tidak ditemukan.");
            }
            $jenis = $l['jenis_kas_masuk'] ?? 'pendapatan';
            $total = Money::add($total, $l['nominal']);

            $kodePersediaan = null;
            $qty = null;
            $hargaSatuan = null;
            if (! empty($l['kode_persediaan']) && ! empty($l['kuantiti'])) {
                $item = Inventory::find($l['kode_persediaan']);
                if (! $item) {
                    throw new AppException(400, "Item persediaan {$l['kode_persediaan']} tidak ditemukan.");
                }
                $kodePersediaan = $item->kode_persediaan;
                $qty = Money::of($l['kuantiti'], 4);
                $hargaSatuan = Money::div($l['nominal'], $l['kuantiti'], 2);
                $sales[] = ['kode_persediaan' => $kodePersediaan, 'kuantiti' => $qty];
            }

            $details[] = [
                'kode_coa' => $l['kode_coa'],
                'nama_coa' => $coa->nama_coa,
                'jenis_kas_masuk' => $jenis,
                'nominal' => Money::of($l['nominal']),
                'keterangan' => $l['keterangan'] ?? null,
                'status_pengakuan' => $jenis === 'uang_muka' ? 'belum_diakui' : 'sudah_diakui',
                'kode_persediaan' => $kodePersediaan,
                'kuantiti' => $qty,
                'harga_satuan' => $hargaSatuan,
            ];
            $jLines[] = [
                'kode_coa' => $l['kode_coa'],
                'nama_coa' => $coa->nama_coa,
                'debet' => '0',
                'kredit' => $l['nominal'],
                'keterangan' => $l['keterangan'] ?? $input['keterangan'],
                'kode_bagian' => $l['kode_bagian'] ?? null,
            ];
        }
        // Baris penyeimbang: Debit Kas/Bank sebesar total.
        $jLines[] = [
            'kode_coa' => $input['kode_rekening'],
            'nama_coa' => $rek->coa->nama_coa,
            'debet' => $total,
            'kredit' => '0',
            'keterangan' => $input['keterangan'],
        ];

        return DB::transaction(function () use ($input, $idPengguna, $total, $details, $jLines, $sales) {
            $base = DocNumber::docBase('KM', $input['tanggal']);
            $last = CashIn::where('nomor_transaksi', 'like', $base.'%')
                ->orderByDesc('nomor_transaksi')
                ->value('nomor_transaksi');
            $nomor = DocNumber::nextDocNumber($base, $last);

            $rec = CashIn::create([
                'nomor_transaksi' => $nomor,
                'tanggal' => $input['tanggal'],
                'kode_unit' => $input['kode_unit'],
                'kode_rekening' => $input['kode_rekening'],
                'kode_customer' => $input['kode_customer'] ?? null,
                'referensi' => $input['referensi'] ?? null,
                'keterangan' => $input['keterangan'],
                'nominal' => $total,
                'status' => 'aktif',
                'id_pengguna' => $idPengguna ?? 0,
            ]);
            foreach ($details as $d) {
                $rec->details()->create($d);
            }

            PostingService::postJournal([
                'referensi' => $nomor,
                'tanggal' => $input['tanggal'],
                'kode_unit' => $input['kode_unit'],
                'keterangan' => $input['keterangan'],
                'sumber_modul' => 'KasMasuk',
                'id_sumber' => (string) $rec->kode_transaksi,
                'id_pengguna' => $idPengguna,
                'lines' => $jLines,
            ]);

            foreach ($sales as $s) {
                InventoryMovement::applyStockOut($s['kode_persediaan'], $s['kuantiti']);
            }

            return $rec->load('details');
        });
    }

    /** Prefix referensi jurnal ACK untuk satu baris uang muka (unik per detail). */
    public function akuiRefPrefix(string $nomorTransaksi, int $detailId): string
    {
        return "ACK-{$nomorTransaksi}-{$detailId}-";
    }

    /** Total nominal yang SUDAH diakui untuk sebuah baris uang muka (dari jurnal ACK aktif). */
    public function recognizedAmount(string $nomorTransaksi, object $detail): string
    {
        $prefix = $this->akuiRefPrefix($nomorTransaksi, $detail->id);
        $sum = JournalLine::where('kode_coa', $detail->kode_coa)
            ->whereHas('entry', function ($q) use ($prefix) {
                $q->where('referensi', 'like', $prefix.'%')
                    ->where('sumber_modul', 'PengakuanPendapatan')
                    ->where('status', 'aktif');
            })
            ->sum('debet');

        return Money::of($sum);
    }

    /**
     * Akui pendapatan dari uang muka (mendukung PARSIAL): jurnal reklasifikasi
     * Debit akun UM, Kredit Pendapatan sebesar nominal (default = sisa penuh).
     */
    public function akuiPendapatan(int $kodeTransaksi, array $input, ?int $idPengguna)
    {
        $rec = CashIn::with('details')->find($kodeTransaksi);
        if (! $rec) {
            throw new AppException(404, 'Kas Masuk tidak ditemukan.');
        }
        if ($rec->status === 'void') {
            throw new AppException(409, 'Voucher sudah di-void.');
        }

        $line = $rec->details->firstWhere('id', $input['detail_id']);
        if (! $line) {
            throw new AppException(404, 'Rincian tidak ditemukan.');
        }
        if ($line->jenis_kas_masuk !== 'uang_muka') {
            throw new AppException(400, 'Rincian ini bukan uang muka.');
        }

        $pend = CoaDetail::find($input['kode_coa_pendapatan']);
        if (! $pend) {
            throw new AppException(400, 'Akun pendapatan tidak ditemukan.');
        }

        $diakui = $this->recognizedAmount($rec->nomor_transaksi, $line);
        $sisa = Money::sub($line->nominal, $diakui);
        if (Money::lte($sisa, '0')) {
            throw new AppException(409, 'Uang muka sudah diakui penuh.');
        }

        $amount = ! empty($input['nominal']) ? Money::of($input['nominal']) : $sisa;
        if (Money::lte($amount, '0')) {
            throw new AppException(400, 'Nominal pengakuan harus > 0.');
        }
        if (Money::gt($amount, $sisa)) {
            throw new AppException(400, "Nominal melebihi sisa uang muka ({$sisa}).");
        }

        $prefix = $this->akuiRefPrefix($rec->nomor_transaksi, $line->id);
        $seq = JournalEntry::where('referensi', 'like', $prefix.'%')
            ->where('sumber_modul', 'PengakuanPendapatan')
            ->count() + 1;
        $ref = "{$prefix}{$seq}";
        $parsial = Money::lt($amount, $sisa);

        return DB::transaction(function () use ($rec, $line, $pend, $input, $idPengguna, $amount, $diakui, $ref, $parsial, $kodeTransaksi) {
            PostingService::postJournal([
                'referensi' => $ref,
                'tanggal' => Carbon::now()->toDateString(),
                'kode_unit' => $rec->kode_unit,
                'keterangan' => 'Pengakuan pendapatan'.($parsial ? ' (parsial)' : '')." dari uang muka {$rec->nomor_transaksi}",
                'sumber_modul' => 'PengakuanPendapatan',
                'id_sumber' => (string) $kodeTransaksi,
                'id_pengguna' => $idPengguna,
                'lines' => [
                    ['kode_coa' => $line->kode_coa, 'nama_coa' => $line->nama_coa, 'debet' => $amount, 'kredit' => '0'],
                    ['kode_coa' => $input['kode_coa_pendapatan'], 'nama_coa' => $pend->nama_coa, 'debet' => '0', 'kredit' => $amount],
                ],
            ]);

            $fully = Money::gte(Money::add($diakui, $amount), $line->nominal);
            $line->update(['status_pengakuan' => $fully ? 'sudah_diakui' : 'belum_diakui']);

            return $line;
        });
    }

    /** Void: reverse jurnal pengakuan (ACK) + jurnal utama, catat alasan. */
    public function void(int $kodeTransaksi, array $input, ?int $idPengguna, ?string $nama): CashIn
    {
        $rec = CashIn::with('details')->find($kodeTransaksi);
        if (! $rec) {
            throw new AppException(404, 'Kas Masuk tidak ditemukan.');
        }
        if ($rec->status === 'void') {
            throw new AppException(409, 'Kas Masuk sudah di-void.');
        }

        return DB::transaction(function () use ($rec, $input, $idPengguna, $nama, $kodeTransaksi) {
            Authorization::authorizeByUser($idPengguna, $rec->nominal);

            $tanggal = $input['tanggal'] ?? Carbon::now()->toDateString();

            // Balik SEMUA jurnal pengakuan (ACK) — termasuk parsial bertahap.
            $ackEntries = JournalEntry::where('sumber_modul', 'PengakuanPendapatan')
                ->where('id_sumber', (string) $kodeTransaksi)
                ->where('status', 'aktif')
                ->get();
            foreach ($ackEntries as $ack) {
                ReversalService::reverseJournalEntry($ack->id, [
                    'tanggal' => $tanggal, 'id_pengguna' => $idPengguna, 'keteranganPrefix' => 'Void — ',
                ]);
            }

            foreach ($rec->details as $d) {
                if ($d->jenis_kas_masuk === 'uang_muka' && $d->status_pengakuan === 'sudah_diakui') {
                    $d->update(['status_pengakuan' => 'belum_diakui']);
                }
                if ($d->kode_persediaan && $d->kuantiti) {
                    InventoryMovement::rollbackStockOut($d->kode_persediaan, $d->kuantiti);
                }
            }

            $entry = JournalEntry::where('sumber_modul', 'KasMasuk')
                ->where('id_sumber', (string) $kodeTransaksi)
                ->where('status', 'aktif')
                ->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, [
                    'tanggal' => $tanggal, 'id_pengguna' => $idPengguna, 'keteranganPrefix' => 'Void — ',
                ]);
            }

            $rec->update([
                'status' => 'void',
                'void_reason' => $input['alasan'],
                'void_by' => $nama,
                'void_at' => Carbon::now(),
            ]);

            return $rec;
        });
    }
}
