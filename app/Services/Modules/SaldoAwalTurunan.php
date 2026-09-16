<?php

namespace App\Services\Modules;

use App\Models\Accrue;
use App\Models\Asset;
use App\Models\BankLoan;
use App\Models\CoaDetail;
use App\Models\OperationalAdvance;
use App\Models\PengajuanPembayaran;
use App\Models\TagihanSantri;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * BARIS TURUNAN SALDO AWAL — buku besar mengikuti sendiri apa yang sudah dicatat.
 *
 * Sebelum ini menu Saldo Awal hanya menerima ketikan tangan, sementara rincian
 * pindahan sistem masuk lewat pintu lain (impor & pintu manual per dokumen).
 * Keduanya harus bertemu di angka yang sama, dan yang menyamakannya adalah
 * ingatan petugas — tepat jenis penjagaan yang paling mudah gagal tanpa suara.
 *
 * Kelas ini menghitungnya: setiap dokumen bertanda `saldo_awal` — artinya
 * dokumen itu TAK PERNAH BERJURNAL — dijumlahkan per akun, lalu ditawarkan
 * sebagai baris jurnal pembuka yang tak bisa diketik ulang.
 *
 * DIHITUNG HIDUP, TIDAK DISALIN. Angka salinan akan basi begitu sebuah dokumen
 * dibatalkan lewat jalur lain, dan basinya tak bersuara. Dihitung saat dibaca,
 * ia tak mungkin meleset dari dokumennya.
 *
 * ── Dua aturan yang menentukan isi ────────────────────────────────────────────
 *
 * 1. HANYA SISI NERACA. Sebuah accrue saldo awal berbunyi "Debit Beban Listrik,
 *    Kredit Hutang Listrik". Bebannya milik PERIODE LALU dan sudah melebur ke
 *    ekuitas awal — memasukkannya ke jurnal pembuka berarti mengakui beban tahun
 *    lalu sebagai beban tahun ini. Yang diambil hanya sisi neracanya; lawannya
 *    adalah baris ekuitas yang diketik petugas sebagai penyeimbang.
 *
 * 2. NOMINAL SEBAGAIMANA TERCATAT, bukan sisa berjalan. Jurnal pembuka menyatakan
 *    keadaan pada TANGGAL PEMBUKAAN. Pembayaran yang terjadi sesudahnya sudah
 *    punya jurnalnya sendiri yang mengkredit piutang; kalau di sini dipakai sisa
 *    berjalan, pengurangannya terhitung dua kali.
 *
 * ── Yang SENGAJA tidak ikut ───────────────────────────────────────────────────
 *
 * • HUTANG VENDOR (invoice). Impornya memanggil InvoiceService yang MENERBITKAN
 *   jurnal — Debit akun perantara, Kredit hutang. Hutangnya karena itu sudah ada
 *   di buku besar; menurunkannya lagi di sini membuatnya dobel.
 * • ASET TETAP. Perolehannya bisa diturunkan, tetapi akumulasi depresiasinya
 *   tidak: akun akumulasi TIDAK tersimpan pada asetnya — ia dipilih saat
 *   menjalankan depresiasi bulanan. Menurunkan satu sisi saja menghasilkan
 *   neraca yang timpang, jadi keduanya ditinggalkan dan dilaporkan sebagai
 *   catatan agar tetap diketik tangan.
 */
class SaldoAwalTurunan
{
    /** Kelompok akun yang tinggal di NERACA (1 aset, 2 liabilitas, 3 ekuitas). */
    private const KELOMPOK_NERACA = ['1', '2', '3'];

    /**
     * Baris turunan per akun, sudah dijumlahkan dan diberi nama akun.
     *
     * @return list<array{kode_coa:string,nama_coa:string,jenis_saldo:string,saldo:string,sumber:list<string>,jumlah_dokumen:int}>
     */
    public function baris(): array
    {
        /** @var array<string,array{kode_coa:string,jenis_saldo:string,saldo:string,sumber:array<string,true>,jumlah_dokumen:int}> $kumpul */
        $kumpul = [];

        $this->tagihanSantri($kumpul);
        $this->pengajuanBelumDibayar($kumpul);
        $this->pembiayaanBank($kumpul);
        $this->pinjamanKaryawan($kumpul);
        $this->uangMukaOperasional($kumpul);
        $this->accrue($kumpul);

        if ($kumpul === []) {
            return [];
        }

        $nama = CoaDetail::whereIn('kode_coa', array_column($kumpul, 'kode_coa'))
            ->pluck('nama_coa', 'kode_coa');

        $hasil = [];
        foreach ($kumpul as $k) {
            // Nol tak perlu ditampilkan: baris yang tak membawa angka hanya
            // memenuhi layar, dan keputusan "sembunyikan yang nol" sudah diambil.
            if (! Money::gtZero($k['saldo'])) {
                continue;
            }
            $hasil[] = [
                'kode_coa' => $k['kode_coa'],
                'nama_coa' => $nama[$k['kode_coa']] ?? $k['kode_coa'],
                'jenis_saldo' => $k['jenis_saldo'],
                'saldo' => Money::of($k['saldo']),
                'sumber' => array_keys($k['sumber']),
                'jumlah_dokumen' => $k['jumlah_dokumen'],
            ];
        }

        usort($hasil, fn ($a, $b) => strcmp($a['kode_coa'], $b['kode_coa']));

        return $hasil;
    }

    /**
     * Hal-hal yang TIDAK bisa diturunkan otomatis tetapi tetap perlu diketahui —
     * supaya yang harus diketik tangan tak menjadi yang terlupakan.
     *
     * @return list<string>
     */
    public function catatan(): array
    {
        $catatan = [];

        $aset = Asset::where('saldo_awal', true)->where('status', '!=', 'dilepas')->count();
        if ($aset > 0) {
            $catatan[] = "{$aset} aset tetap bertanda pindahan sistem belum bisa diturunkan otomatis: "
                .'akun akumulasi depresiasi tidak tersimpan pada asetnya (ia dipilih saat menjalankan '
                .'depresiasi bulanan). Masukkan harga perolehan dan akumulasinya sebagai baris manual.';
        }

        return $catatan;
    }

    /** Akun neraca? Akun pendapatan (4) & beban (5) tak pernah masuk jurnal pembuka. */
    private function akunNeraca(?string $kodeCoa): bool
    {
        return $kodeCoa !== null && $kodeCoa !== ''
            && in_array($kodeCoa[0], self::KELOMPOK_NERACA, true);
    }

    /**
     * @param  array<string,array{kode_coa:string,jenis_saldo:string,saldo:string,sumber:array<string,true>,jumlah_dokumen:int}>  $kumpul
     */
    private function tambah(array &$kumpul, ?string $kodeCoa, string $jenisSaldo, string|float|int|null $nominal, string $sumber, int $jumlah = 1): void
    {
        if (! $this->akunNeraca($kodeCoa)) {
            return;
        }
        $nilai = Money::of($nominal);
        if (! Money::gtZero($nilai)) {
            return;
        }

        // Akun yang sama dari sumber berbeda MENYATU jadi satu baris jurnal —
        // sebuah akun hanya boleh muncul sekali di jurnal pembuka.
        $kunci = $kodeCoa.'|'.$jenisSaldo;
        if (! isset($kumpul[$kunci])) {
            $kumpul[$kunci] = ['kode_coa' => $kodeCoa, 'jenis_saldo' => $jenisSaldo, 'saldo' => '0', 'sumber' => [], 'jumlah_dokumen' => 0];
        }
        $kumpul[$kunci]['saldo'] = Money::add($kumpul[$kunci]['saldo'], $nilai);
        $kumpul[$kunci]['sumber'][$sumber] = true;
        $kumpul[$kunci]['jumlah_dokumen'] += $jumlah;
    }

    /** Piutang santri — akunnya tinggal di master jenis biaya, bukan di tagihannya. */
    private function tagihanSantri(array &$kumpul): void
    {
        $baris = TagihanSantri::query()
            ->join('jenis_biaya', 'jenis_biaya.kode', '=', 'tagihan_santri.kode_jenis')
            ->where('tagihan_santri.saldo_awal', true)
            ->whereNotIn('tagihan_santri.status', TagihanSantri::TIDAK_BERLAKU)
            ->whereNotNull('jenis_biaya.kode_coa_piutang')
            ->groupBy('jenis_biaya.kode_coa_piutang')
            ->selectRaw('jenis_biaya.kode_coa_piutang AS kode_coa, SUM(tagihan_santri.nominal) AS total, COUNT(*) AS n')
            ->get();

        foreach ($baris as $b) {
            $this->tambah($kumpul, $b->kode_coa, 'debet', $b->total, 'Tunggakan santri', (int) $b->n);
        }
    }

    /** Pengajuan yang sudah diposting tetapi tak pernah berjurnal. */
    private function pengajuanBelumDibayar(array &$kumpul): void
    {
        $baris = PengajuanPembayaran::query()
            ->where('saldo_awal', true)
            ->where('status', '!=', 'void')
            ->whereNotNull('kode_coa_hutang')
            ->groupBy('kode_coa_hutang')
            ->select('kode_coa_hutang')
            ->selectRaw('SUM(nominal) AS total, COUNT(*) AS n')
            ->get();

        foreach ($baris as $b) {
            $this->tambah($kumpul, $b->kode_coa_hutang, 'kredit', $b->total, 'Pengajuan belum dibayar', (int) $b->n);
        }
    }

    /** Pembiayaan bank tanpa jurnal pencairan. */
    private function pembiayaanBank(array &$kumpul): void
    {
        $baris = BankLoan::query()
            ->where('saldo_awal', true)
            ->where('status', '!=', 'void')
            ->whereNotNull('kode_coa_hutang')
            ->groupBy('kode_coa_hutang')
            ->select('kode_coa_hutang')
            ->selectRaw('SUM(pokok_awal) AS total, COUNT(*) AS n')
            ->get();

        foreach ($baris as $b) {
            $this->tambah($kumpul, $b->kode_coa_hutang, 'kredit', $b->total, 'Pembiayaan bank', (int) $b->n);
        }
    }

    /** Pinjaman karyawan tanpa jurnal pencairan. */
    private function pinjamanKaryawan(array &$kumpul): void
    {
        $baris = DB::table('pinjaman_karyawan')
            ->where('saldo_awal', true)
            ->where('status', '!=', 'void')
            ->whereNotNull('kode_coa_piutang')
            ->groupBy('kode_coa_piutang')
            ->selectRaw('kode_coa_piutang, SUM(pokok) AS total, COUNT(*) AS n')
            ->get();

        foreach ($baris as $b) {
            $this->tambah($kumpul, $b->kode_coa_piutang, 'debet', $b->total, 'Pinjaman karyawan', (int) $b->n);
        }
    }

    /** Uang muka operasional yang didaftarkan tanpa jurnal. */
    private function uangMukaOperasional(array &$kumpul): void
    {
        $baris = OperationalAdvance::query()
            ->where('saldo_awal', true)
            ->where('status', '!=', 'void')
            ->groupBy('kode_coa_uang_muka')
            ->select('kode_coa_uang_muka')
            ->selectRaw('SUM(nominal) AS total, COUNT(*) AS n')
            ->get();

        foreach ($baris as $b) {
            $this->tambah($kumpul, $b->kode_coa_uang_muka, 'debet', $b->total, 'Uang muka operasional', (int) $b->n);
        }
    }

    /**
     * Accrue & prepaid saldo awal — DUA sisi diperiksa, yang diambil hanya yang
     * tinggal di neraca. Lihat aturan 1 di kepala kelas.
     */
    private function accrue(array &$kumpul): void
    {
        foreach (Accrue::where('saldo_awal', true)->where('status', 'aktif')->get() as $a) {
            $this->tambah($kumpul, $a->kode_coa_debet, 'debet', $a->nominal, 'Accrue & prepaid');
            $this->tambah($kumpul, $a->kode_coa_kredit, 'kredit', $a->nominal, 'Accrue & prepaid');
        }
    }
}
