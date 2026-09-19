<?php

namespace App\Services\Reports;

use App\Models\CoaDetail;
use App\Services\Ppsb\DompetPolicy;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * REKONSILIASI BUKU PEMBANTU ↔ BUKU BESAR.
 *
 * Tiap modul menyimpan rinciannya sendiri (tagihan per santri, invoice per
 * vendor, saldo per dompet, stok per barang) sementara buku besar hanya
 * menyimpan totalnya. Keduanya HARUS sama besar; kalau tidak, salah satu
 * jalur posting bocor — dan selama tak ada yang membandingkan, kebocoran itu
 * tak pernah ketahuan sampai laporan tahunan dipertanyakan.
 *
 * Laporan ini tidak memperbaiki apa pun. Ia hanya menaruh kedua angka
 * bersebelahan dan menyebut selisihnya. Yang WAJAR berselisih dijelaskan di
 * `catatan`, bukan disembunyikan — supaya lampu merahnya tetap berarti.
 */
class RekonsiliasiService
{
    /**
     * @return array{as_of:string,baris:array<int,array>,jumlah_selisih:int,cocok:bool}
     */
    public function ringkasan(string $asOf): array
    {
        $baris = [
            $this->piutangSantri($asOf),
            $this->hutangVendor($asOf),
            ...$this->titipanDompet($asOf),
            $this->persediaan($asOf),
            $this->asetTetap($asOf),
            $this->uangMukaOperasional($asOf),
        ];

        $jumlahSelisih = count(array_filter($baris, fn ($b) => ! $b['cocok']));

        return [
            'as_of' => $asOf,
            'baris' => $baris,
            'jumlah_selisih' => $jumlahSelisih,
            'cocok' => $jumlahSelisih === 0,
        ];
    }

    // ---- Baris-barisnya ----

    private function piutangSantri(string $asOf): array
    {
        // Hanya tagihan yang SUDAH diakrualkan yang punya baris di buku besar;
        // yang belum (cash basis / belum dijalankan) memang bukan piutang.
        $hidup = DB::table('tagihan_santri')
            ->where('sudah_akrual', true)
            ->where('status', '!=', 'batal');

        $pembantu = Money::of((clone $hidup)->sum('sisa'));
        $dariSaldoAwal = Money::of((clone $hidup)->where('saldo_awal', true)->sum('sisa'));

        $akun = DB::table('jenis_biaya')->whereNotNull('kode_coa_piutang')
            ->distinct()->pluck('kode_coa_piutang')->all();

        $catatan = Money::gtZero($dariSaldoAwal)
            ? 'Termasuk '.$this->rp($dariSaldoAwal).' tunggakan awal santri lama, yang di buku besar '
                .'masuk sekali lewat menu Saldo Awal (bukan per santri). Kalau baris ini berselisih '
                .'persis sebesar itu, yang kurang adalah jurnal pembukanya.'
            : null;

        return $this->baris('piutang-santri', 'Piutang Santri', $akun, 'debet', $pembantu, $asOf,
            'Sisa seluruh tagihan santri yang sudah diakrualkan',
            route('outstanding_spp.index'), $catatan);
    }

    private function hutangVendor(string $asOf): array
    {
        $pembantu = Money::of(
            DB::table('invoices')->where('status', '!=', 'void')->sum('sisa_hutang')
        );
        $akun = DB::table('invoices')->whereNotNull('kode_coa_hutang')
            ->distinct()->pluck('kode_coa_hutang')->all();

        return $this->baris('hutang-vendor', 'Hutang Usaha (Vendor)', $akun, 'kredit', $pembantu, $asOf,
            'Sisa hutang seluruh invoice vendor yang belum di-void',
            route('kontrol.aging_ap'));
    }

    /** @return array<int,array> */
    private function titipanDompet(string $asOf): array
    {
        $sumber = [
            'wali' => ['Titipan — Dompet Wali', 'dompet_wali'],
            'santri' => ['Titipan — Dompet Santri', 'dompet_santri'],
            'tabungan' => ['Titipan — Tabungan Santri', 'tabungan_santri'],
        ];

        $out = [];
        foreach ($sumber as $pemilik => [$label, $tabel]) {
            $out[] = $this->baris(
                "titipan-{$pemilik}",
                $label,
                [DompetPolicy::COA_TITIPAN[$pemilik]],
                'kredit',
                Money::of(DB::table($tabel)->sum('saldo')),
                $asOf,
                'Jumlah saldo seluruh '.strtolower($label),
                route('dompet.index'),
            );
        }

        return $out;
    }

    private function persediaan(string $asOf): array
    {
        // Nilai persediaan = jumlah lapisan FIFO tersisa, diambil dari kolom
        // turunannya. Sejak pemakaian & opname ikut berjurnal, baris ini
        // seharusnya SELALU cocok — selisih di sini berarti ada pergerakan stok
        // yang lolos dari [[InventoryMovement]], dan itu memang harus berbunyi.
        $pembantu = Money::of(DB::table('inventory')->sum('nilai_persediaan'));

        $akun = DB::table('inventory')->whereNotNull('kode_coa')->distinct()->pluck('kode_coa')->all();

        return $this->baris('persediaan', 'Persediaan', $akun, 'debet', $pembantu, $asOf,
            'Jumlah lapisan FIFO tersisa, seluruh barang',
            route('reports.persediaan'));
    }

    private function asetTetap(string $asOf): array
    {
        // Harga perolehan BRUTO — akumulasi penyusutan sengaja tak ikut: akun
        // akumulasinya dipilih saat menjalankan depresiasi dan tidak disimpan
        // per aset, jadi tak ada pasangan yang bisa dibandingkan dengannya.
        $pembantu = Money::of(
            DB::table('assets')->where('status', '!=', 'dilepas')->sum('harga_perolehan')
        );
        $akun = DB::table('assets')->whereNotNull('kode_coa')->distinct()->pluck('kode_coa')->all();

        return $this->baris('aset-tetap', 'Aset Tetap (harga perolehan)', $akun, 'debet', $pembantu, $asOf,
            'Harga perolehan seluruh aset yang belum dilepas',
            route('reports.aset'),
            'Hanya nilai BRUTO yang dibandingkan. Akumulasi penyusutan tak bisa dicocokkan '
            .'karena akunnya dipilih saat menjalankan depresiasi dan tidak disimpan pada asetnya.');
    }

    private function uangMukaOperasional(string $asOf): array
    {
        $pembantu = '0';
        foreach (DB::table('operational_advances')->where('status', 'outstanding')
            ->get(['nominal', 'nominal_diselesaikan']) as $u) {
            $pembantu = Money::add($pembantu, Money::sub($u->nominal, $u->nominal_diselesaikan));
        }

        $akun = DB::table('operational_advances')->whereNotNull('kode_coa_uang_muka')
            ->distinct()->pluck('kode_coa_uang_muka')->all();

        return $this->baris('uang-muka-operasional', 'Uang Muka Operasional', $akun, 'debet', $pembantu, $asOf,
            'Sisa uang muka yang belum diselesaikan',
            route('kontrol.uang_muka_operasional'));
    }

    // ---- Perkakas ----

    /**
     * Rakit satu baris perbandingan.
     *
     * @param  list<string>  $akun  akun buku besar pasangannya
     * @param  'debet'|'kredit'  $sisi  sisi normal kelompok akun itu
     */
    private function baris(
        string $kunci,
        string $label,
        array $akun,
        string $sisi,
        string $pembantu,
        string $asOf,
        string $keterangan,
        ?string $tautan = null,
        ?string $catatan = null,
    ): array {
        $akun = array_values(array_unique(array_filter($akun)));
        $bukuBesar = $this->saldoNormal($akun, $sisi, $asOf);
        $selisih = Money::sub($pembantu, $bukuBesar);

        return [
            'kunci' => $kunci,
            'label' => $label,
            'keterangan' => $keterangan,
            'akun' => $akun,
            'sisi' => $sisi,
            'saldo_pembantu' => Money::of($pembantu),
            'saldo_buku_besar' => $bukuBesar,
            'selisih' => $selisih,
            'cocok' => Money::isZero($selisih),
            'tautan' => $tautan,
            // Akun yang belum ditentukan sama sekali membuat sisi buku besar
            // selalu nol — itu bukan "selisih", itu master yang belum diisi.
            'catatan' => $akun === []
                ? 'Belum ada akun buku besar yang ditunjuk untuk pos ini, jadi pembandingnya nol. Lengkapi dulu masternya.'
                : $catatan,
        ];
    }

    /**
     * Saldo gabungan beberapa akun pada satu tanggal, disajikan pada sisi
     * NORMALNYA (positif = normal). Saldo pembuka + seluruh mutasi s.d. tanggal.
     */
    private function saldoNormal(array $akun, string $sisi, string $asOf): string
    {
        if ($akun === []) {
            return Money::of('0');
        }

        $pembuka = DB::table('opening_balances')->whereIn('kode_coa', $akun)
            ->selectRaw("COALESCE(SUM(CASE WHEN jenis_saldo = 'debet' THEN saldo ELSE -saldo END), 0) as v")
            ->value('v');

        $mutasi = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.entry_id', '=', 'je.id')
            ->whereIn('jl.kode_coa', $akun)
            ->where('je.tanggal', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(jl.debet) - SUM(jl.kredit), 0) as v')
            ->value('v');

        $debet = Money::add($pembuka, $mutasi);

        return $sisi === 'debet' ? Money::of($debet) : Money::sub('0', $debet);
    }

    /** Nama akun untuk ditampilkan; kode yang tak dikenal dikembalikan apa adanya. */
    public function namaAkun(array $kodeCoa): array
    {
        $nama = CoaDetail::whereIn('kode_coa', $kodeCoa)->pluck('nama_coa', 'kode_coa')->all();

        return array_map(fn ($k) => isset($nama[$k]) ? "{$k} — {$nama[$k]}" : $k, $kodeCoa);
    }

    private function rp(string $v): string
    {
        return 'Rp '.number_format((float) $v, 0, ',', '.');
    }
}
