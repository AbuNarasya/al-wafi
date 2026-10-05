<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CoaDetail;
use App\Models\CompanySettings;
use App\Models\JournalEntry;
use App\Models\JournalLine;
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
    /** Penanda `sumber_modul` jurnal pembuka — dibaca juga oleh laporan yang memisahkan kolom "saldo awal". */
    public const SUMBER = 'SaldoAwal';

    /** Awal periode pembukuan (Pengaturan Perusahaan). */
    private function periodeAwal(): Carbon
    {
        $t = CompanySettings::query()->value('periode_awal_pembukuan');

        return $t ? Carbon::parse($t) : Carbon::create((int) now()->format('Y'), 1, 1);
    }

    /**
     * Tanggal jurnal pembuka = SEHARI SEBELUM periode pembukuan: "saldo per
     * 31 Agustus" untuk pembukuan yang dimulai 1 September.
     *
     * Laporan membaca saldo awal HANYA dari buku besar (dulu juga dari tabel
     * `opening_balances` — sehingga sesudah difinalisasi angkanya terhitung
     * dua kali). Bertanggal di hari pertama, jurnal ini akan terbaca sebagai
     * MUTASI hari itu — dan sebagai kas masuk di Arus Kas periode pertama.
     * Sehari sebelumnya, setiap laporan yang dimulai di periode pertama
     * membacanya sebagai saldo awal tanpa perlakuan khusus.
     */
    private function tanggalJurnal(): Carbon
    {
        return $this->periodeAwal()->subDay();
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
        $turunan = (new SaldoAwalTurunan)->baris();

        $totalDebet = '0';
        $totalKredit = '0';
        foreach ($rows as $r) {
            if ($r->jenis_saldo === 'debet') {
                $totalDebet = Money::add($totalDebet, $r->saldo);
            } else {
                $totalKredit = Money::add($totalKredit, $r->saldo);
            }
        }
        // Baris turunan ikut ditimbang. Kalau tidak, layar akan mengatakan
        // "sudah balance" untuk jurnal yang belum memuat separuh isinya.
        foreach ($turunan as $t) {
            if ($t['jenis_saldo'] === 'debet') {
                $totalDebet = Money::add($totalDebet, $t['saldo']);
            } else {
                $totalKredit = Money::add($totalKredit, $t['saldo']);
            }
        }

        $posted = $rows->contains(fn ($r) => $r->posted);
        $journalRef = null;
        $entryId = $rows->firstWhere('journal_entry_id', '!=', null)?->journal_entry_id;
        if ($posted && $entryId) {
            $journalRef = JournalEntry::where('id', $entryId)->value('referensi');
        }

        $jumlahBaris = $rows->count() + count($turunan);

        return [
            'rows' => $rows,
            'turunan' => $turunan,
            'catatanTurunan' => (new SaldoAwalTurunan)->catatan(),
            // Setelah difinalisasi, dokumen saldo awal masih boleh bertambah —
            // tunggakan warisan kerap baru ketemu berbulan-bulan sesudahnya.
            // Yang terbit tak diubah diam-diam; selisihnya DITUNJUKKAN, dan yang
            // memutuskan menyusun ulang tetap orang.
            'selisihTerbit' => $posted ? $this->selisihTerbit($entryId, $turunan) : null,
            'summary' => [
                'count' => $jumlahBaris,
                'countManual' => $rows->count(),
                'countTurunan' => count($turunan),
                'totalDebet' => Money::of($totalDebet),
                'totalKredit' => Money::of($totalKredit),
                'selisih' => Money::sub($totalDebet, $totalKredit),
                'balanced' => $jumlahBaris > 0 && Money::eq($totalDebet, $totalKredit),
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

        // Penjaga anti-hitung-dua-kali. Akun yang angkanya sudah datang sendiri
        // dari dokumen saldo awal tak boleh diketik lagi di sini — itu cara
        // paling gampang membuat piutang atau hutang tercatat dobel, dan
        // selisihnya baru ketahuan berbulan-bulan kemudian.
        $turunan = collect((new SaldoAwalTurunan)->baris())->firstWhere('kode_coa', $data['kode_coa']);
        if ($turunan) {
            throw new AppException(409, 'Akun "'.$turunan['nama_coa'].'" sudah terisi otomatis '
                .Money::of($turunan['saldo']).' dari '.$turunan['jumlah_dokumen'].' dokumen saldo awal ('
                .implode(', ', $turunan['sumber']).'). Mengetiknya lagi membuat angkanya terhitung dua kali — '
                .'betulkan lewat dokumennya, bukan di sini.');
        }

        return OpeningBalance::create($data);
    }

    /**
     * Selisih antara jurnal pembuka YANG SUDAH TERBIT dan keadaan dokumen saat ini.
     *
     * Baris manual terkunci sesudah finalisasi, jadi satu-satunya yang bisa
     * bergerak adalah sisi turunannya — dokumen saldo awal yang bertambah,
     * dibatalkan, atau dikoreksi sesudah jurnalnya terbit.
     *
     * @param  list<array<string,mixed>>  $turunan
     * @return list<array{kode_coa:string,nama_coa:string,terbit:string,sekarang:string,selisih:string}>
     */
    private function selisihTerbit(?int $entryId, array $turunan): array
    {
        if (! $entryId) {
            return [];
        }

        // Netto per akun: debet positif, kredit negatif. Dibandingkan sebagai
        // netto supaya akun yang berpindah sisi pun tetap terbaca selisihnya.
        $terbit = [];
        foreach (JournalLine::where('entry_id', $entryId)->get() as $l) {
            $terbit[$l->kode_coa] = Money::add($terbit[$l->kode_coa] ?? '0', Money::sub($l->debet, $l->kredit));
        }

        $sekarang = [];
        foreach (OpeningBalance::all() as $r) {
            $n = $r->jenis_saldo === 'debet' ? Money::of($r->saldo) : Money::sub('0', $r->saldo);
            $sekarang[$r->kode_coa] = Money::add($sekarang[$r->kode_coa] ?? '0', $n);
        }
        foreach ($turunan as $t) {
            $n = $t['jenis_saldo'] === 'debet' ? Money::of($t['saldo']) : Money::sub('0', $t['saldo']);
            $sekarang[$t['kode_coa']] = Money::add($sekarang[$t['kode_coa']] ?? '0', $n);
        }

        $nama = CoaDetail::whereIn('kode_coa', array_unique([...array_keys($terbit), ...array_keys($sekarang)]))
            ->pluck('nama_coa', 'kode_coa');

        $hasil = [];
        foreach (array_unique([...array_keys($terbit), ...array_keys($sekarang)]) as $kode) {
            $a = $terbit[$kode] ?? '0';
            $b = $sekarang[$kode] ?? '0';
            if (Money::eq($a, $b)) {
                continue;
            }
            $hasil[] = [
                'kode_coa' => $kode,
                'nama_coa' => $nama[$kode] ?? $kode,
                'terbit' => Money::of($a),
                'sekarang' => Money::of($b),
                'selisih' => Money::sub($b, $a),
            ];
        }

        return $hasil;
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
        $turunan = (new SaldoAwalTurunan)->baris();

        if ($rows->count() + count($turunan) < 2) {
            throw new AppException(422, 'Butuh minimal 2 akun yang saling menyeimbangkan (total Debet = total Kredit) sebelum finalisasi.');
        }

        $lines = $rows->map(fn ($r) => [
            'kode_coa' => $r->kode_coa,
            'nama_coa' => $r->coa->nama_coa,
            'debet' => $r->jenis_saldo === 'debet' ? Money::of($r->saldo) : '0',
            'kredit' => $r->jenis_saldo === 'kredit' ? Money::of($r->saldo) : '0',
            'keterangan' => 'Saldo awal',
        ])->all();

        // Baris turunan ikut terbit sebagai baris jurnal betulan. Keterangannya
        // menyebut asalnya supaya yang membaca buku besar setahun lagi tahu
        // angka ini datang dari dokumen, bukan dari ketikan seseorang.
        foreach ($turunan as $t) {
            $lines[] = [
                'kode_coa' => $t['kode_coa'],
                'nama_coa' => $t['nama_coa'],
                'debet' => $t['jenis_saldo'] === 'debet' ? Money::of($t['saldo']) : '0',
                'kredit' => $t['jenis_saldo'] === 'kredit' ? Money::of($t['saldo']) : '0',
                'keterangan' => 'Saldo awal — '.implode(', ', $t['sumber']).' ('.$t['jumlah_dokumen'].' dokumen)',
            ];
        }

        $tanggal = $this->tanggalJurnal();

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
        // Pembalik bertanggal sama dengan jurnal pembukanya, bukan hari pertama.
        $tanggal = $this->tanggalJurnal();

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
