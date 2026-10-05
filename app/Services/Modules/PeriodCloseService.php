<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\AccountingPeriod;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Audit\Jejak;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tutup Buku Periode (port period-close dev): status bulanan, tutup/buka bulan,
 * dan tutup/buka buku tahunan (nol-kan Pendapatan/Beban → Laba Ditahan).
 */
class PeriodCloseService
{
    private const SUMBER = 'TutupBuku';

    private function refTahun(int $tahun): string
    {
        return "TUTUP-{$tahun}";
    }

    /** Resolver kelompok root ("1".."5") tiap akun via telusur induk grup. */
    private function loadRoots(): array
    {
        $groups = CoaGroup::all(['kode_grup', 'kode_induk'])->keyBy('kode_grup');
        $acctMap = CoaDetail::all(['kode_coa', 'nama_coa', 'kode_grup'])->keyBy('kode_coa');
        $rootOf = function (string $kodeGrup) use ($groups): ?string {
            $cur = $groups->get($kodeGrup);
            $seen = [];
            while ($cur && $cur->kode_induk && ! isset($seen[$cur->kode_grup])) {
                $seen[$cur->kode_grup] = true;
                $cur = $groups->get($cur->kode_induk);
            }

            return $cur?->kode_grup;
        };

        return [$acctMap, $rootOf];
    }

    public function statusTahun(int $tahun): array
    {
        $byBulan = AccountingPeriod::where('tahun', $tahun)->get()->keyBy('bulan');
        $closing = JournalEntry::where('sumber_modul', self::SUMBER)
            ->where('referensi', $this->refTahun($tahun))->where('status', 'aktif')->first();

        $bulan = [];
        for ($i = 1; $i <= 12; $i++) {
            $p = $byBulan->get($i);
            $bulan[] = [
                'bulan' => $i,
                'status' => $p->status ?? 'open',
                'closed_at' => $p->closed_at ?? null,
                'nama_closed_by' => $p->nama_closed_by ?? null,
                'reopened_at' => $p->reopened_at ?? null,
            ];
        }

        return ['tahun' => $tahun, 'tahun_ditutup' => (bool) $closing, 'referensi_tutup_tahun' => $closing?->referensi, 'bulan' => $bulan];
    }

    public function tutupBulan(int $tahun, int $bulan, ?int $idPengguna, ?string $nama, ?string $keterangan = null): array
    {
        $existing = AccountingPeriod::where('tahun', $tahun)->where('bulan', $bulan)->first();
        if ($existing?->status === 'closed') {
            throw new AppException(409, 'Periode sudah ditutup.');
        }
        $data = ['status' => 'closed', 'closed_by' => $idPengguna, 'nama_closed_by' => $nama, 'closed_at' => now(), 'keterangan' => $keterangan];
        if ($existing) {
            $existing->update($data);
        } else {
            AccountingPeriod::create(array_merge(['tahun' => $tahun, 'bulan' => $bulan], $data));
        }

        Jejak::catat('tutup_bulan', [
            'modul' => 'period-close',
            'ref_jenis' => 'AccountingPeriod',
            'ref_id' => "{$tahun}-{$bulan}",
            'detail' => ['tahun' => $tahun, 'bulan' => $bulan, 'keterangan' => $keterangan],
            'id_pengguna' => $idPengguna,
        ]);

        return $this->statusTahun($tahun);
    }

    /**
     * Buka kembali satu bulan. HANYA boleh lewat permohonan yang disetujui —
     * lihat [[BukaPeriodeService]]. Dulu cukup sekali klik oleh seorang
     * pemegang level tertinggi, tanpa alasan dan tanpa jejak.
     */
    public function bukaBulan(int $tahun, int $bulan, ?int $idPengguna, bool $lewatPersetujuan = false): array
    {
        $this->pastikanLewatPersetujuan($lewatPersetujuan);
        $existing = AccountingPeriod::where('tahun', $tahun)->where('bulan', $bulan)->first();
        if (! $existing || $existing->status !== 'closed') {
            throw new AppException(409, 'Periode belum ditutup.');
        }
        $existing->update(['status' => 'open', 'reopened_at' => now()]);

        Jejak::catat('buka_bulan', [
            'modul' => 'period-close',
            'ref_jenis' => 'AccountingPeriod',
            'ref_id' => $existing->id ?? "{$tahun}-{$bulan}",
            'detail' => ['tahun' => $tahun, 'bulan' => $bulan],
            'id_pengguna' => $idPengguna,
        ]);

        return $this->statusTahun($tahun);
    }

    /**
     * Penjaga terakhir. Pemanggilan langsung (mis. rute lama yang tertinggal,
     * atau perintah artisan) ditolak di sini — bukan hanya disembunyikan
     * tombolnya, karena tombol yang hilang bukanlah kontrol.
     */
    private function pastikanLewatPersetujuan(bool $lewatPersetujuan): void
    {
        if (! $lewatPersetujuan) {
            throw new AppException(403,
                'Periode yang sudah ditutup hanya bisa dibuka lewat permohonan yang disetujui direktur keuangan. '
                .'Ajukan lebih dulu dari menu Tutup Buku Periode.');
        }
    }

    /** Tutup buku tahunan: nol-kan Pendapatan(4)/Beban(5), net → Laba Ditahan. */
    public function tutupTahun(int $tahun, string $kodeCoaLabaDitahan, ?int $idPengguna): array
    {
        $ld = CoaDetail::find($kodeCoaLabaDitahan);
        if (! $ld) {
            throw new AppException(400, 'Akun Laba Ditahan tidak ditemukan.');
        }
        $already = JournalEntry::where('sumber_modul', self::SUMBER)
            ->where('referensi', $this->refTahun($tahun))->where('status', 'aktif')->first();
        if ($already) {
            throw new AppException(409, 'Tahun ini sudah ditutup buku. Buka dulu untuk menutup ulang.');
        }

        $from = Carbon::create($tahun, 1, 1)->startOfDay();
        $to = Carbon::create($tahun, 12, 31)->endOfDay();
        $grouped = JournalLine::selectRaw('kode_coa, SUM(debet) as d, SUM(kredit) as k')
            ->whereHas('entry', fn ($q) => $q->whereBetween('tanggal', [$from, $to]))
            ->groupBy('kode_coa')->get();

        [$acctMap, $rootOf] = $this->loadRoots();
        $lines = [];
        $totalDebet = '0';
        $totalKredit = '0';
        foreach ($grouped as $g) {
            $acct = $acctMap->get($g->kode_coa);
            if (! $acct) {
                continue;
            }
            $root = $rootOf($acct->kode_grup);
            if ($root !== '4' && $root !== '5') {
                continue;
            }
            $md = Money::sub($g->d, $g->k); // saldo orientasi debet
            if (Money::isZero($md)) {
                continue;
            }
            if (Money::gt($md, 0)) {
                $lines[] = ['kode_coa' => $g->kode_coa, 'nama_coa' => $acct->nama_coa, 'debet' => '0', 'kredit' => Money::of($md), 'keterangan' => 'Tutup buku tahunan'];
                $totalKredit = Money::add($totalKredit, $md);
            } else {
                $neg = Money::mul($md, '-1');
                $lines[] = ['kode_coa' => $g->kode_coa, 'nama_coa' => $acct->nama_coa, 'debet' => Money::of($neg), 'kredit' => '0', 'keterangan' => 'Tutup buku tahunan'];
                $totalDebet = Money::add($totalDebet, $neg);
            }
        }
        if (! $lines) {
            throw new AppException(422, 'Tidak ada saldo Pendapatan/Beban untuk ditutup pada tahun ini.');
        }

        $laba = Money::sub($totalDebet, $totalKredit);
        if (Money::gt($laba, 0)) {
            $lines[] = ['kode_coa' => $ld->kode_coa, 'nama_coa' => $ld->nama_coa, 'debet' => '0', 'kredit' => Money::of($laba), 'keterangan' => 'Laba tahun berjalan → Laba Ditahan'];
        } elseif (Money::lt($laba, 0)) {
            $lines[] = ['kode_coa' => $ld->kode_coa, 'nama_coa' => $ld->nama_coa, 'debet' => Money::of(Money::mul($laba, '-1')), 'kredit' => '0', 'keterangan' => 'Rugi tahun berjalan → Laba Ditahan'];
        }

        $entry = PostingService::postJournal([
            'referensi' => $this->refTahun($tahun),
            'tanggal' => Carbon::create($tahun, 12, 31)->toDateString(),
            'keterangan' => "Tutup buku tahunan {$tahun}",
            'sumber_modul' => self::SUMBER,
            'id_sumber' => (string) $tahun,
            'id_pengguna' => $idPengguna,
            'lines' => $lines,
        ]);

        return ['ok' => true, 'referensi' => $entry->referensi, 'laba_rugi' => Money::of($laba), 'jumlah_baris' => count($lines)];
    }

    /** Batalkan tutup buku tahunan. Sama seperti bukaBulan: lewat permohonan saja. */
    public function bukaTahun(int $tahun, ?int $idPengguna, bool $lewatPersetujuan = false): array
    {
        $this->pastikanLewatPersetujuan($lewatPersetujuan);
        $entry = JournalEntry::where('sumber_modul', self::SUMBER)
            ->where('referensi', $this->refTahun($tahun))->where('status', 'aktif')->first();
        if (! $entry) {
            throw new AppException(409, 'Tahun ini belum ditutup buku.');
        }

        // Pembalik WAJIB bertanggal sama dengan jurnal penutupnya (31 Desember).
        // Dulu tanggalnya dibiarkan bawaan = hari ini: membuka tahun 2025 pada
        // 2026 membuat laba rugi 2025 tetap nol, dan seluruhnya muncul di 2026.
        $tanggal = Carbon::create($tahun, 12, 31)->toDateString();

        DB::transaction(function () use ($tahun, $entry, $tanggal, $idPengguna) {
            // Desember lazimnya sudah dikunci saat tahun dibuka kembali, dan
            // kunci itu akan menolak pembalik bertanggal 31 Desember. Permohonan
            // buka tahun sudah disetujui direktur keuangan, jadi Desember ikut
            // dibuka di sini — dengan jejaknya sendiri, bukan diam-diam. Satu
            // transaksi: kalau pembaliknya gagal, Desember kembali terkunci.
            $desember = AccountingPeriod::where('tahun', $tahun)->where('bulan', 12)->first();
            if ($desember && $desember->status === 'closed') {
                $desember->update(['status' => 'open', 'reopened_at' => now()]);
                Jejak::catat('buka_bulan', [
                    'modul' => 'period-close',
                    'ref_jenis' => 'AccountingPeriod',
                    'ref_id' => $desember->id,
                    'detail' => ['tahun' => $tahun, 'bulan' => 12, 'sebab' => 'ikut dibuka bersama buka tutup buku tahunan'],
                    'id_pengguna' => $idPengguna,
                ]);
            }

            ReversalService::reverseJournalEntry($entry->id, [
                'tanggal' => $tanggal,
                'id_pengguna' => $idPengguna,
                'keteranganPrefix' => 'Buka tutup buku — ',
            ]);
        });

        Jejak::catat('buka_tahun', [
            'modul' => 'period-close',
            'ref_jenis' => 'JournalEntry',
            'ref_id' => $entry->id,
            'detail' => ['tahun' => $tahun],
            'id_pengguna' => $idPengguna,
        ]);

        return ['ok' => true];
    }
}
