<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PembayaranSantri;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Rekap pembayaran per santri: ringkasan tagihan vs terbayar, rincian tagihan
 * per jenis biaya, dan riwayat seluruh pembayaran. Murni baca — tidak menyentuh
 * jurnal. Angka "terbayar" dihitung dari pembayaran TERVERIFIKASI (uang yang
 * benar-benar diakui), sementara yang menunggu verifikasi ditampilkan terpisah
 * agar petugas tahu ada dana yang belum diakui.
 */
class RekapPembayaranService
{
    /** Status pembayaran yang dihitung sebagai uang masuk. */
    private const DIAKUI = 'terverifikasi';

    public function santri(int $idSantri): Santri
    {
        // `jenjang` ikut dimuat karena rekap & kuitansi menyebut NAMA jenjang,
        // bukan kode `J001`.
        $santri = Santri::with(['wali', 'jenjang'])->find($idSantri);
        if (! $santri) {
            throw new AppException(404, 'Santri tidak ditemukan.');
        }

        return $santri;
    }

    /**
     * @return array{
     *   santri:Santri,
     *   ringkasan:array{tagihan:string,terbayar:string,sisa:string,menunggu:string,jumlah_pembayaran:int},
     *   tagihan:list<array<string,mixed>>,
     *   pembayaran:Collection
     * }
     */
    public function rekap(int $idSantri): array
    {
        $santri = $this->santri($idSantri);

        $tagihan = $santri->tagihan()->with('jenis')->orderBy('id')->get();
        $pembayaran = PembayaranSantri::where('id_santri', $idSantri)
            ->with(['tagihan.jenis', 'pencatat', 'pemverifikasi'])
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        // Terbayar per tagihan dari pembayaran terverifikasi (sisa tagihan tidak
        // dipakai langsung agar rekap tetap benar untuk tagihan yang dibatalkan).
        $terbayarPerTagihan = $pembayaran->where('status', self::DIAKUI)
            ->groupBy('id_tagihan')
            ->map(fn ($grup) => $grup->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0'));

        // Dicatat tapi belum diverifikasi — ditampilkan per tagihan agar terlihat
        // tagihan MANA yang sedang menunggu, bukan cuma totalnya.
        $menungguPerTagihan = $pembayaran->where('status', 'menunggu_verifikasi')
            ->groupBy('id_tagihan')
            ->map(fn ($grup) => $grup->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0'));

        $totalTagihan = '0';
        $totalTerbayar = '0';
        $rincian = [];
        foreach ($tagihan as $t) {
            $dibayar = $terbayarPerTagihan[$t->id] ?? '0';
            if ($t->status !== 'batal') {
                $totalTagihan = Money::add($totalTagihan, $t->nominal);
                $totalTerbayar = Money::add($totalTerbayar, $dibayar);
            }
            $rincian[] = [
                'id' => $t->id,
                'jenis' => $t->jenis?->nama ?? $t->kode_jenis,
                // Perilaku, bukan kode tipe: view memberi warna per perilaku dan
                // tipe buatan sendiri harus tetap kebagian warna yang benar.
                'tipe' => TipeBiaya::perilakuDari($t->jenis?->tipe) ?? 'lain',
                'periode' => $t->periode,
                'nominal' => Money::of($t->nominal),
                'terbayar' => $dibayar,
                'sisa' => Money::of($t->sisa),
                'menunggu' => Money::of($menungguPerTagihan[$t->id] ?? '0'),
                'status' => $t->status,
                'jatuh_tempo' => $t->jatuh_tempo,
            ];
        }

        $menunggu = $pembayaran->where('status', 'menunggu_verifikasi')
            ->reduce(fn ($t, $p) => Money::add($t, $p->nominal), '0');

        return [
            'santri' => $santri,
            'ringkasan' => [
                'tagihan' => $totalTagihan,
                'terbayar' => $totalTerbayar,
                'sisa' => Money::sub($totalTagihan, $totalTerbayar),
                'menunggu' => $menunggu,
                'jumlah_pembayaran' => $pembayaran->where('status', self::DIAKUI)->count(),
            ],
            'tagihan' => $rincian,
            'pembayaran' => $pembayaran,
        ];
    }

    /**
     * Ringkasan status pembayaran BANYAK santri sekaligus — dipakai daftar calon
     * santri & pemilih rekap agar statusnya terlihat tanpa membuka satu per satu.
     *
     * Sengaja 2 query agregat (bukan per baris) supaya daftar berisi puluhan
     * santri tak memicu N+1.
     *
     * Yang membuat status ini "mutakhir": pembayaran yang baru dicatat PPSB dan
     * belum diverifikasi keuangan TIDAK mengurangi `tagihan.sisa` — tanpa
     * diperhitungkan di sini, santri yang sudah menyetor tampak seolah belum
     * bayar sampai keuangan sempat memverifikasi. Karena itu nominal menunggu
     * dihitung terpisah dan status "menunggu" menang atas "belum/sebagian",
     * tanpa pernah dicampur ke angka terbayar (uang itu memang belum diakui).
     *
     * @param  iterable<int>  $idSantri
     * @return array<int,array{tagihan:string,terbayar:string,sisa:string,menunggu:string,status:string}>
     */
    public function ringkasMassal(iterable $idSantri): array
    {
        $ids = collect($idSantri)->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $tagihan = TagihanSantri::whereIn('id_santri', $ids)
            ->whereNotIn('status', TagihanSantri::TIDAK_BERLAKU)
            ->selectRaw('id_santri, COALESCE(SUM(nominal), 0) AS total, COALESCE(SUM(sisa), 0) AS sisa')
            ->groupBy('id_santri')->get()->keyBy('id_santri');

        $menunggu = PembayaranSantri::whereIn('id_santri', $ids)
            ->where('status', 'menunggu_verifikasi')
            ->selectRaw('id_santri, COALESCE(SUM(nominal), 0) AS total')
            ->groupBy('id_santri')->get()->keyBy('id_santri');

        $hasil = [];
        foreach ($ids as $id) {
            $total = Money::of($tagihan[$id]->total ?? 0);
            $sisa = Money::of($tagihan[$id]->sisa ?? 0);
            $tunggu = Money::of($menunggu[$id]->total ?? 0);
            $terbayar = Money::sub($total, $sisa);

            $hasil[$id] = [
                'tagihan' => $total,
                'terbayar' => $terbayar,
                'sisa' => $sisa,
                'menunggu' => $tunggu,
                'status' => $this->statusRingkas($total, $sisa, $terbayar, $tunggu),
            ];
        }

        return $hasil;
    }

    /** tanpa_tagihan | lunas | menunggu | sebagian | belum */
    private function statusRingkas(string $total, string $sisa, string $terbayar, string $menunggu): string
    {
        if (Money::lte($total, '0')) {
            return 'tanpa_tagihan';
        }
        if (Money::lte($sisa, '0')) {
            return 'lunas';
        }
        // Sudah menyetor tapi keuangan belum memverifikasi — inilah keadaan yang
        // dulu tak terlihat sama sekali di daftar.
        if (Money::gt($menunggu, '0')) {
            return 'menunggu';
        }

        return Money::gt($terbayar, '0') ? 'sebagian' : 'belum';
    }

    /**
     * Nominal menunggu verifikasi PER TAGIHAN seorang santri (untuk detail
     * santri & rekap): [id_tagihan => nominal].
     *
     * @return array<int,string>
     */
    public function menungguPerTagihan(int $idSantri): array
    {
        return PembayaranSantri::where('id_santri', $idSantri)
            ->where('status', 'menunggu_verifikasi')
            ->selectRaw('id_tagihan, COALESCE(SUM(nominal), 0) AS total')
            ->groupBy('id_tagihan')->get()
            ->mapWithKeys(fn ($r) => [(int) $r->id_tagihan => Money::of($r->total)])
            ->all();
    }

    /** Daftar santri untuk pemilih (calon & aktif), dengan pencarian. */
    /**
     * Komponen yang menjadi urusan PPSB — dibayar di awal pendaftaran, sekali
     * seumur masuk. SPP & tagihan lain berulang tiap periode dan itu urusan
     * Kependidikan, jadi tak ikut menentukan siapa yang tampil di lingkup PPSB.
     */
    private const KOMPONEN_PPSB = ['uang_pangkal', 'perlengkapan'];

    /**
     * @param  'semua'|'ppsb'  $lingkup  'ppsb' = hanya yang MASIH punya kewajiban
     *                                   uang pangkal / perlengkapan.
     */
    public function opsiSantri(string $cari = '', string $lingkup = 'semua')
    {
        return Santri::query()
            ->when($cari !== '', fn ($q) => $q->where(
                fn ($w) => $w->where('nama', 'ilike', "%{$cari}%")
                    ->orWhere('no_pendaftaran', 'ilike', "%{$cari}%")
                    ->orWhere('nis', 'ilike', "%{$cari}%"),
            ))
            // Begitu KEDUA komponennya tertutup, santrinya hilang dari daftar PPSB
            // dengan sendirinya — rekapnya tetap utuh dan tetap terbuka lewat
            // menu Kependidikan, yang memang memuat seluruh riwayat.
            ->when($lingkup === 'ppsb', fn ($q) => $q->whereHas('tagihan', fn ($t) => $t
                ->whereIn('perilaku', self::KOMPONEN_PPSB)
                ->whereIn('status', ['belum_bayar', 'sebagian'])))
            ->orderBy('nama')->limit(100)->get(['id', 'nama', 'no_pendaftaran', 'nis', 'status']);
    }
}
