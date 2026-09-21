<?php

namespace App\Services\Reports;

use App\Models\Santri;
use App\Models\Wali;
use App\Services\Ppsb\DompetPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * SALDO DOMPET PER PEMILIK — rincian di balik angka Rekonsiliasi Buku Pembantu.
 *
 * Rekonsiliasi sudah membandingkan TOTAL titipan terhadap buku besar, tetapi
 * ketika angkanya selisih tak ada layar yang bisa menunjukkan selisihnya ada
 * pada siapa. Berkas ini menjawab itu, dan hanya itu: BACA SAJA, tak satu pun
 * baris di sini mengubah saldo.
 *
 * ══ LEFT JOIN, BUKAN INNER ══
 * Baris `dompet_wali` / `dompet_santri` baru lahir saat ada transaksi pertama.
 * Menyambungnya dengan INNER JOIN akan membuat daftar ini hanya memuat yang
 * pernah bertransaksi — diam-diam menyembunyikan mayoritas orang, persis pada
 * layar yang tugasnya memberi gambaran menyeluruh.
 *
 * ══ TOTAL DIHITUNG TERPISAH DARI SARINGAN ══
 * Angka total yang ditampilkan adalah total SELURUH pemilik, bukan yang
 * kebetulan tampil di halaman ini. Itu angka yang harus cocok dengan buku
 * besar; total yang ikut berubah tiap kali orang mengetik di kotak pencarian
 * tak bisa dipakai mencocokkan apa pun.
 */
class SaldoDompetService
{
    /** Akun buku besar pasangan tiap dompet. */
    public const COA = DompetPolicy::COA_TITIPAN;

    public const PER_HALAMAN = 50;

    // ══════════════════════════════════════════════════════════════════
    //  DOMPET WALI
    // ══════════════════════════════════════════════════════════════════

    /**
     * @param  array{cari?:string, bersaldo?:bool, urut?:string, arah?:string}  $f
     * @return array{baris:\Illuminate\Contracts\Pagination\LengthAwarePaginator, total:string, jumlah:int, bersaldo:int}
     */
    public function wali(array $f = []): array
    {
        return [
            'baris' => $this->kueriWali($f)->paginate(self::PER_HALAMAN, ['*'], 'hal_wali')->withQueryString(),
            // Dihitung dari tabel dompetnya langsung, tanpa saringan: wali tanpa
            // baris dompet menyumbang nol, jadi hasilnya sama dan kueri-nya jauh
            // lebih sederhana daripada menjumlahkan lewat join.
            'total' => (string) (DB::table('dompet_wali')->sum('saldo') ?: '0'),
            'jumlah' => Wali::count(),
            'bersaldo' => DB::table('dompet_wali')->where('saldo', '<>', 0)->count(),
        ];
    }

    private function kueriWali(array $f): Builder
    {
        $q = Wali::query()
            ->leftJoin('dompet_wali', 'dompet_wali.id_wali', '=', 'wali.id')
            ->select([
                'wali.id',
                'wali.nama',
                'wali.telepon',
                'wali.status',
                DB::raw('COALESCE(dompet_wali.saldo, 0) AS saldo'),
                DB::raw("COALESCE(dompet_wali.va_number, '') AS va_number"),
            ])
            ->withCount('santri');

        $this->cari($q, $f, ['wali.nama', 'wali.telepon']);

        if (! empty($f['bersaldo'])) {
            $q->whereRaw('COALESCE(dompet_wali.saldo, 0) <> 0');
        }

        return $this->urutkan($q, $f, ['nama' => 'wali.nama', 'saldo' => 'saldo'], 'wali.nama');
    }

    // ══════════════════════════════════════════════════════════════════
    //  DOMPET & TABUNGAN SANTRI
    // ══════════════════════════════════════════════════════════════════

    /**
     * Dompet jajan dan tabungan dalam SATU baris: keduanya milik orang yang
     * sama dan sama-sama titipan, jadi memisahkannya jadi dua daftar hanya
     * memaksa pembaca menjodohkan nama sendiri.
     *
     * @param  array{cari?:string, bersaldo?:bool, urut?:string, arah?:string}  $f
     * @return array{baris:\Illuminate\Contracts\Pagination\LengthAwarePaginator, total_dompet:string, total_tabungan:string, jumlah:int, bersaldo:int}
     */
    public function santri(array $f = []): array
    {
        return [
            'baris' => $this->kueriSantri($f)->paginate(self::PER_HALAMAN, ['*'], 'hal_santri')->withQueryString(),
            'total_dompet' => (string) (DB::table('dompet_santri')->sum('saldo') ?: '0'),
            'total_tabungan' => (string) (DB::table('tabungan_santri')->sum('saldo') ?: '0'),
            'jumlah' => Santri::count(),
            'bersaldo' => Santri::query()
                ->leftJoin('dompet_santri', 'dompet_santri.id_santri', '=', 'santri.id')
                ->leftJoin('tabungan_santri', 'tabungan_santri.id_santri', '=', 'santri.id')
                ->whereRaw(self::JUMLAH_SANTRI.' <> 0')
                ->count(),
        ];
    }

    /** Ungkapan penjumlah dua dompet santri — dipakai di saringan, urutan, & select. */
    private const JUMLAH_SANTRI = 'COALESCE(dompet_santri.saldo, 0) + COALESCE(tabungan_santri.saldo, 0)';

    private function kueriSantri(array $f): Builder
    {
        $q = Santri::query()
            ->leftJoin('dompet_santri', 'dompet_santri.id_santri', '=', 'santri.id')
            ->leftJoin('tabungan_santri', 'tabungan_santri.id_santri', '=', 'santri.id')
            // Nama jenjang, bukan kodenya: "J002" tak bercerita apa pun kepada
            // orang yang sedang mencari letak selisih saldo.
            ->leftJoin('jenjang', 'jenjang.kode', '=', 'santri.kode_jenjang')
            ->leftJoin('wali', 'wali.id', '=', 'santri.id_wali')
            ->select([
                'santri.id',
                'santri.nis',
                'santri.nama',
                'santri.status',
                DB::raw('COALESCE(jenjang.nama, santri.kode_jenjang) AS jenjang'),
                DB::raw("COALESCE(wali.nama, '') AS nama_wali"),
                DB::raw('COALESCE(dompet_santri.saldo, 0) AS saldo'),
                DB::raw('COALESCE(dompet_santri.kunci_tarik, false) AS kunci_tarik'),
                DB::raw('COALESCE(tabungan_santri.saldo, 0) AS tabungan'),
                DB::raw(self::JUMLAH_SANTRI.' AS jumlah'),
            ]);

        $this->cari($q, $f, ['santri.nama', 'santri.nis']);

        if (! empty($f['bersaldo'])) {
            $q->whereRaw(self::JUMLAH_SANTRI.' <> 0');
        }

        return $this->urutkan($q, $f,
            ['nama' => 'santri.nama', 'nis' => 'santri.nis', 'saldo' => DB::raw(self::JUMLAH_SANTRI)],
            'santri.nama');
    }

    // ══════════════════════════════════════════════════════════════════
    //  UNDUHAN
    // ══════════════════════════════════════════════════════════════════

    /**
     * Baris siap unduh — SELURUHNYA, bukan sehalaman.
     *
     * Saringannya tetap dihormati supaya yang terunduh sama dengan yang dilihat;
     * yang TIDAK ikut hanyalah pemenggalan halaman. Berkas unduhan yang cuma
     * memuat 50 baris pertama adalah cara paling halus menyesatkan pembacanya.
     *
     * @return list<array<string,mixed>>
     */
    public function untukUnduh(string $lingkup, array $f = []): array
    {
        if ($lingkup === 'wali') {
            return $this->kueriWali($f)->get()->map(fn ($r) => [
                'Nama Wali' => $r->nama,
                'Telepon' => $r->telepon,
                'Jumlah Santri' => $r->santri_count,
                'Status' => $r->status,
                'Nomor VA' => $r->va_number,
                'Saldo Dompet Wali' => $r->saldo,
            ])->all();
        }

        return $this->kueriSantri($f)->get()->map(fn ($r) => [
            'NIS' => $r->nis,
            'Nama Santri' => $r->nama,
            'Jenjang' => $r->jenjang,
            'Wali' => $r->nama_wali,
            'Status' => $r->status,
            'Dompet Santri' => $r->saldo,
            'Tabungan' => $r->tabungan,
            'Jumlah' => $r->jumlah,
            'Penarikan Dikunci' => $r->kunci_tarik ? 'ya' : 'tidak',
        ])->all();
    }

    // ══════════════════════════════════════════════════════════════════
    //  PENOPANG
    // ══════════════════════════════════════════════════════════════════

    /** @param  list<string>  $kolom */
    private function cari(Builder $q, array $f, array $kolom): void
    {
        $cari = trim((string) ($f['cari'] ?? ''));
        if ($cari === '') {
            return;
        }

        $q->where(function ($w) use ($kolom, $cari) {
            foreach ($kolom as $k) {
                $w->orWhere($k, 'ilike', "%{$cari}%");
            }
        });
    }

    /**
     * Pemecah seri WAJIB disebut lengkap dengan nama tabelnya: kueri santri
     * menyambung `wali` yang juga punya kolom `nama`, dan `ORDER BY nama` yang
     * mentah di sana bergantung pada kebetulan alias.
     *
     * @param  array<string,string|\Illuminate\Contracts\Database\Query\Expression>  $peta
     */
    private function urutkan(Builder $q, array $f, array $peta, string $pemecahSeri): Builder
    {
        $urut = (string) ($f['urut'] ?? 'saldo');
        $kolom = $peta[$urut] ?? $peta['saldo'];
        $arah = ($f['arah'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $q->orderBy($kolom, $arah)->orderBy($pemecahSeri);
    }
}
