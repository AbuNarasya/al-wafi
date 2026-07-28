<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\BankLoan;
use App\Models\CoaDetail;
use App\Models\JournalEntry;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Modul Pinjaman/Pembiayaan Bank (syariah). Pencairan → jurnal Debit kas /
 * Kredit Hutang Bank. Angsuran dicatat lewat Kas Keluar tertaut (id_bank_loan):
 * applyPayment menaikkan pokok_terbayar. Sisa pokok = pokok_awal - pokok_terbayar.
 */
class BankLoanService
{
    private const SUMBER = 'PinjamanBank';

    /** Daftar pinjaman (sisa_pokok tersedia via accessor model). */
    /** Rekap pembiayaan dikelompokkan per bank (pokok/terbayar/sisa/margin dibayar). */
    public function rekap(): array
    {
        $loans = BankLoan::where('status', '!=', 'void')->orderBy('nama_bank')->get();

        // Margin dibayar per pembiayaan: Σ baris Kas Keluar tertaut ke akun beban margin.
        $marginPerLoan = [];
        $vouchers = \App\Models\CashOut::whereNotNull('id_bank_loan')->where('status', 'aktif')->with('details')->get();
        foreach ($vouchers as $v) {
            $loan = $loans->firstWhere('id', $v->id_bank_loan);
            if (! $loan || ! $loan->kode_coa_beban_bunga) {
                continue;
            }
            $margin = $v->details->where('kode_coa', $loan->kode_coa_beban_bunga)->reduce(fn ($a, $d) => \App\Support\Money::add($a, $d->nominal), '0');
            $marginPerLoan[$loan->id] = \App\Support\Money::add($marginPerLoan[$loan->id] ?? '0', $margin);
        }

        $perBank = [];
        foreach ($loans as $l) {
            $g = $perBank[$l->nama_bank] ?? ['nama_bank' => $l->nama_bank, 'jumlah_pinjaman' => 0, 'pokok_awal' => '0', 'pokok_terbayar' => '0', 'sisa_pokok' => '0', 'margin_dibayar' => '0'];
            $g['jumlah_pinjaman']++;
            $g['pokok_awal'] = \App\Support\Money::add($g['pokok_awal'], $l->pokok_awal);
            $g['pokok_terbayar'] = \App\Support\Money::add($g['pokok_terbayar'], $l->pokok_terbayar);
            $g['sisa_pokok'] = \App\Support\Money::add($g['sisa_pokok'], \App\Support\Money::sub($l->pokok_awal, $l->pokok_terbayar));
            $g['margin_dibayar'] = \App\Support\Money::add($g['margin_dibayar'], $marginPerLoan[$l->id] ?? '0');
            $perBank[$l->nama_bank] = $g;
        }

        return array_values($perBank);
    }

    public function list(array $f = [])
    {
        return BankLoan::query()
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->orderByDesc('id')
            ->get();
    }

    /** Detail pinjaman + riwayat angsuran (Kas Keluar tertaut). */
    public function get(int $id): array
    {
        $loan = BankLoan::find($id);
        if (! $loan) {
            throw new AppException(404, 'Pembiayaan tidak ditemukan.');
        }

        $vouchers = $loan->cashOuts()
            ->where('status', 'aktif')
            ->with(['details', 'rekening'])
            ->orderBy('tanggal')
            ->get();

        $angsuran = $vouchers->map(function ($v) use ($loan) {
            $pokok = $v->details
                ->where('kode_coa', $loan->kode_coa_hutang)
                ->reduce(fn ($a, $d) => Money::add($a, $d->nominal), '0');
            $margin = $loan->kode_coa_beban_bunga
                ? $v->details->where('kode_coa', $loan->kode_coa_beban_bunga)
                    ->reduce(fn ($a, $d) => Money::add($a, $d->nominal), '0')
                : '0';

            return [
                'kode_transaksi' => $v->kode_transaksi,
                'nomor_transaksi' => $v->nomor_transaksi,
                'tanggal' => $v->tanggal,
                'rekening' => $v->rekening?->nama_rekening ?? $v->kode_rekening,
                'keterangan' => $v->keterangan,
                'pokok' => $pokok,
                'margin' => $margin,
                'total' => Money::of($v->nominal),
            ];
        })->all();

        return array_merge($loan->toArray(), ['angsuran' => $angsuran]);
    }

    /** Daftar pinjaman baru; opsional posting jurnal pencairan (Debit kas/Kredit hutang). */
    public function create(array $input, ?int $idPengguna): BankLoan
    {
        $hutang = CoaDetail::find($input['kode_coa_hutang']);
        if (! $hutang) {
            throw new AppException(400, 'Akun Hutang Bank tidak ditemukan.');
        }
        if (! empty($input['kode_coa_beban_bunga']) && ! CoaDetail::find($input['kode_coa_beban_bunga'])) {
            throw new AppException(400, 'Akun Beban Margin tidak ditemukan.');
        }
        $rek = BankAccount::with('coa')->find($input['kode_rekening']);
        if (! $rek) {
            throw new AppException(400, 'Kas/Rekening pencairan tidak ditemukan.');
        }

        return DB::transaction(function () use ($input, $idPengguna, $hutang, $rek) {
            $loan = BankLoan::create([
                'nama_bank' => $input['nama_bank'],
                'nomor_kontrak' => $input['nomor_kontrak'] ?? null,
                'jenis_akad' => $input['jenis_akad'] ?? 'murabahah',
                'pokok_awal' => Money::of($input['pokok_awal']),
                'margin' => ! empty($input['margin']) ? Money::of($input['margin']) : null,
                'tenor_bulan' => $input['tenor_bulan'] ?? null,
                'tanggal_mulai' => $input['tanggal_mulai'],
                'tanggal_jatuh_tempo' => $input['tanggal_jatuh_tempo'] ?? null,
                'kode_coa_hutang' => $input['kode_coa_hutang'],
                'kode_coa_beban_bunga' => $input['kode_coa_beban_bunga'] ?? null,
                'kode_rekening' => $input['kode_rekening'],
                'pokok_terbayar' => '0',
                'status' => 'aktif',
                'keterangan' => $input['keterangan'] ?? null,
                'id_pengguna' => $idPengguna,
            ]);

            if (! empty($input['posting_pencairan'])) {
                $ref = DocNumber::nextJournalRef('PJB', $input['tanggal_mulai']);
                $ket = "Pencairan pembiayaan {$input['nama_bank']}"
                    .(! empty($input['nomor_kontrak']) ? " ({$input['nomor_kontrak']})" : '');
                PostingService::postJournal([
                    'referensi' => $ref,
                    'tanggal' => $input['tanggal_mulai'],
                    'keterangan' => $ket,
                    'sumber_modul' => self::SUMBER,
                    'id_sumber' => (string) $loan->id,
                    'id_pengguna' => $idPengguna,
                    'lines' => [
                        ['kode_coa' => $rek->kode_coa, 'nama_coa' => $rek->coa->nama_coa, 'debet' => Money::of($input['pokok_awal']), 'kredit' => '0', 'keterangan' => $ket],
                        ['kode_coa' => $hutang->kode_coa, 'nama_coa' => $hutang->nama_coa, 'debet' => '0', 'kredit' => Money::of($input['pokok_awal']), 'keterangan' => $ket],
                    ],
                ]);
            }

            return $loan->refresh();
        });
    }

    public function void(int $id, string $alasan, ?int $idPengguna, ?string $nama): BankLoan
    {
        $loan = BankLoan::find($id);
        if (! $loan) {
            throw new AppException(404, 'Pembiayaan tidak ditemukan.');
        }
        if ($loan->status === 'void') {
            throw new AppException(409, 'Pembiayaan sudah di-void.');
        }
        if (Money::gtZero($loan->pokok_terbayar)) {
            throw new AppException(409, 'Pembiayaan sudah diangsur; batalkan angsuran (void Kas Keluar) terlebih dahulu.');
        }

        return DB::transaction(function () use ($id, $loan, $alasan, $idPengguna, $nama) {
            $entry = JournalEntry::where('sumber_modul', self::SUMBER)
                ->where('id_sumber', (string) $id)
                ->where('status', 'aktif')
                ->first();
            if ($entry) {
                ReversalService::reverseJournalEntry($entry->id, [
                    'id_pengguna' => $idPengguna,
                    'keteranganPrefix' => "Void pembiayaan ({$alasan}) — ",
                ]);
            }
            $loan->update([
                'status' => 'void',
                'void_reason' => $alasan,
                'void_by' => $nama,
                'void_at' => Carbon::now(),
            ]);

            return $loan;
        });
    }

    /**
     * Naikkan pokok_terbayar saat diangsur lewat Kas Keluar. Dipanggil dari
     * CashOutService dalam transaksi. Guard: tidak melebihi pokok awal, aktif.
     * Set status 'lunas' bila pokok habis.
     */
    public function applyPayment(int $idBankLoan, string $pokok): BankLoan
    {
        $loan = BankLoan::find($idBankLoan);
        if (! $loan) {
            throw new AppException(400, 'Pinjaman tidak ditemukan.');
        }
        if ($loan->status === 'void') {
            throw new AppException(409, 'Pembiayaan sudah di-void.');
        }
        if (Money::lte($pokok, '0')) {
            throw new AppException(422, 'Voucher pembiayaan harus memuat baris pembayaran pokok (akun Hutang Bank) > 0.');
        }
        $sisa = Money::sub($loan->pokok_awal, $loan->pokok_terbayar);
        if (Money::gt($pokok, $sisa)) {
            throw new AppException(422, "Pembayaran pokok ({$pokok}) melebihi sisa pokok pembiayaan ({$sisa}).");
        }
        $baru = Money::add($loan->pokok_terbayar, $pokok);
        $loan->update([
            'pokok_terbayar' => $baru,
            'status' => Money::gte($baru, $loan->pokok_awal) ? 'lunas' : 'aktif',
        ]);

        return $loan;
    }

    /** Kebalikan applyPayment saat void Kas Keluar tertaut. */
    public function reversePayment(int $idBankLoan, string $pokok): void
    {
        $loan = BankLoan::find($idBankLoan);
        if (! $loan) {
            return;
        }
        $baru = Money::sub($loan->pokok_terbayar, $pokok);
        if (Money::isNegative($baru)) {
            $baru = '0';
        }
        $loan->update([
            'pokok_terbayar' => $baru,
            // pinjaman yang sudah void tetap void; selain itu kembali aktif.
            'status' => $loan->status === 'void' ? 'void' : 'aktif',
        ]);
    }
}
