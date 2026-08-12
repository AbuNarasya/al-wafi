<?php

namespace App\Services\Modules;

use App\Models\Jenjang;
use App\Models\TagihanSantri;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * DASHBOARD TAGIHAN SANTRI AKTIF — keadaan piutang kesantrian dalam satu layar.
 *
 * Menjawab pertanyaan yang selama ini tak punya tempat: "bagaimana keadaan
 * tagihan seluruh santri aktif hari ini". Daftar Outstanding SPP hanya memuat
 * SATU perilaku dari enam, Rekap Pembayaran menuntut kita tahu lebih dulu
 * santri mana yang bermasalah, dan tab PPSB bercerita tentang CALON santri.
 *
 * DUA ATURAN YANG MENENTUKAN SELURUH ANGKA DI SINI:
 *
 * 1. Hanya santri berstatus `aktif`. Alumni & santri keluar sengaja tak ikut —
 *    tunggakan mereka nyata, tetapi ditagih dengan cara yang sama sekali berbeda
 *    dan mencampurnya membuat angka "tunggakan santri" tak bisa ditindaklanjuti.
 *
 * 2. `terbayar` dihitung `nominal - sisa`, BUKAN dari jumlah baris pembayaran.
 *    Saldo prabayar & auto-debet dompet mengurangi `sisa` saat penerbitan tanpa
 *    meninggalkan baris pembayaran sama sekali (lihat SppService::pakaiPrabayar),
 *    jadi menjumlahkan pembayaran akan melaporkan tunggakan LEBIH BESAR daripada
 *    kenyataannya. Aturan ini sama dengan yang dipakai OutstandingSppService.
 */
class KesantrianDashboardService
{
    /** Tagihan yang masih menggantung. */
    public const MENGGANTUNG = ['belum_bayar', 'sebagian'];

    /** Urutan perilaku pada layar — mengikuti urutan kerjanya, bukan abjad. */
    public const PERILAKU = ['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp', 'lain'];

    public const LABEL_PERILAKU = [
        'registrasi' => 'Registrasi',
        'uang_pangkal' => 'Uang Pangkal',
        'perlengkapan' => 'Perlengkapan',
        'daftar_ulang' => 'Daftar Ulang',
        'spp' => 'SPP',
        'lain' => 'Tagihan Lain-lain',
    ];

    /** Tahun ajaran yang sedang berjalan; null bila belum disetel. */
    public function taBerjalan(): ?string
    {
        return (new TahunAjaranService)->berjalan()?->kode;
    }

    /** @return array<string,string> kode => kode, untuk penyaring di layar */
    public function opsiTa(): array
    {
        return TagihanSantri::query()
            ->whereNotNull('tahun_ajaran')
            ->distinct()->orderByDesc('tahun_ajaran')
            ->pluck('tahun_ajaran', 'tahun_ajaran')->all();
    }

    /**
     * Query dasar: tagihan milik santri AKTIF saja.
     *
     * Dipakai ulang oleh seluruh blok supaya definisi "santri aktif" hanya
     * ditulis sekali — kalau kelak berubah, tak ada blok yang tertinggal.
     */
    private function dasar(?string $ta = null)
    {
        return TagihanSantri::query()
            ->join('santri', 'santri.id', '=', 'tagihan_santri.id_santri')
            ->where('santri.status', 'aktif')
            ->when($ta, fn ($q) => $q->where('tagihan_santri.tahun_ajaran', $ta));
    }

    /**
     * Blok 1 — ringkasan uang & orang.
     *
     * @return array<string,mixed>
     */
    public function ringkasan(?string $ta): array
    {
        $agregat = (clone $this->dasar($ta))
            ->selectRaw('COALESCE(SUM(tagihan_santri.nominal), 0) AS tagihan')
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(*) AS jumlah_tagihan')
            ->first();

        $tagihan = (string) ($agregat->tagihan ?? '0');
        $sisa = (string) ($agregat->sisa ?? '0');
        $terbayar = Money::sub($tagihan, $sisa);

        $santriAktif = DB::table('santri')->where('status', 'aktif')->count();
        $menunggak = (clone $this->dasar($ta))
            ->whereIn('tagihan_santri.status', self::MENGGANTUNG)
            ->distinct()->count('tagihan_santri.id_santri');

        return [
            'tagihan' => $tagihan,
            'terbayar' => $terbayar,
            'sisa' => $sisa,
            // Persentase tertagih — angka yang paling sering ditanya pimpinan
            // dan selama ini dihitung manual. Tagihan nol → 100%, bukan galat
            // bagi-nol: tak ada yang tertunggak berarti semuanya tertagih.
            'persen' => Money::gtZero($tagihan)
                ? round(((float) $terbayar / (float) $tagihan) * 100, 1)
                : 100.0,
            'jumlah_tagihan' => (int) ($agregat->jumlah_tagihan ?? 0),
            'santri_aktif' => $santriAktif,
            'menunggak' => $menunggak,
            'lunas_semua' => max(0, $santriAktif - $menunggak),
        ];
    }

    /**
     * Blok 2 — tunggakan per perilaku biaya.
     *
     * Tiap perilaku beda cara menagihnya dan beda orang yang mengurusnya, jadi
     * total gabungan saja tak bisa ditindaklanjuti siapa pun.
     *
     * @return list<array<string,mixed>>
     */
    public function perPerilaku(?string $ta): array
    {
        $rows = (clone $this->dasar($ta))
            ->groupBy('tagihan_santri.perilaku')
            ->selectRaw('tagihan_santri.perilaku')
            ->selectRaw('COALESCE(SUM(tagihan_santri.nominal), 0) AS tagihan')
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(DISTINCT tagihan_santri.id_santri) AS santri')
            ->get()->keyBy('perilaku');

        $hasil = [];
        foreach (self::PERILAKU as $perilaku) {
            $r = $rows->get($perilaku);
            if (! $r) {
                continue; // perilaku yang memang belum pernah ditagihkan
            }
            $tagihan = (string) $r->tagihan;
            $sisa = (string) $r->sisa;
            $hasil[] = [
                'perilaku' => $perilaku,
                'label' => self::LABEL_PERILAKU[$perilaku] ?? $perilaku,
                'tagihan' => $tagihan,
                'terbayar' => Money::sub($tagihan, $sisa),
                'sisa' => $sisa,
                'santri' => (int) $r->santri,
                'persen' => Money::gtZero($tagihan)
                    ? round(((float) Money::sub($tagihan, $sisa) / (float) $tagihan) * 100, 1)
                    : 100.0,
            ];
        }

        return $hasil;
    }

    /**
     * Blok 3 — umur tunggakan.
     *
     * Inilah yang menentukan siapa ditelepon lebih dulu. Hutang ke vendor sudah
     * punya Aging AP sejak lama; piutang santri belum punya sama sekali, padahal
     * nilainya jauh lebih besar.
     *
     * Tagihan tanpa `jatuh_tempo` masuk kelompok "belum jatuh tempo" — bukan
     * dibuang: ia tetap uang yang belum masuk, dan menghilangkannya membuat
     * jumlah aging tak sama dengan total tunggakan di blok 1.
     *
     * @return list<array{kunci:string,label:string,sisa:string,jumlah:int}>
     */
    public function aging(?string $ta): array
    {
        $kelompok = "CASE
            WHEN tagihan_santri.jatuh_tempo IS NULL OR tagihan_santri.jatuh_tempo >= CURRENT_DATE THEN 'belum'
            WHEN tagihan_santri.jatuh_tempo >= CURRENT_DATE - INTERVAL '30 days' THEN 'd30'
            WHEN tagihan_santri.jatuh_tempo >= CURRENT_DATE - INTERVAL '60 days' THEN 'd60'
            WHEN tagihan_santri.jatuh_tempo >= CURRENT_DATE - INTERVAL '90 days' THEN 'd90'
            ELSE 'lebih' END";

        $rows = (clone $this->dasar($ta))
            ->whereIn('tagihan_santri.status', self::MENGGANTUNG)
            ->groupByRaw($kelompok)
            ->selectRaw("{$kelompok} AS kelompok")
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(*) AS jumlah')
            ->get()->keyBy('kelompok');

        $label = [
            'belum' => 'Belum jatuh tempo',
            'd30' => '1–30 hari',
            'd60' => '31–60 hari',
            'd90' => '61–90 hari',
            'lebih' => 'Lebih dari 90 hari',
        ];

        $hasil = [];
        foreach ($label as $kunci => $teks) {
            $r = $rows->get($kunci);
            $hasil[] = [
                'kunci' => $kunci,
                'label' => $teks,
                'sisa' => (string) ($r->sisa ?? '0'),
                'jumlah' => (int) ($r->jumlah ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * Blok 4 — sebaran per jenjang.
     *
     * Menampilkan NAMA jenjang, bukan kodenya (J001 tak berarti apa pun bagi
     * pembacanya). Jenjang yang kodenya sudah tak ada di master tetap tampil
     * apa adanya supaya angkanya tidak diam-diam hilang dari total.
     *
     * @return list<array<string,mixed>>
     */
    public function perJenjang(?string $ta): array
    {
        $nama = Jenjang::orderBy('urutan')->pluck('nama', 'kode');

        $rows = (clone $this->dasar($ta))
            ->groupBy('tagihan_santri.kode_jenjang')
            ->selectRaw('tagihan_santri.kode_jenjang')
            ->selectRaw('COALESCE(SUM(tagihan_santri.nominal), 0) AS tagihan')
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(DISTINCT tagihan_santri.id_santri) AS santri')
            ->get();

        $hasil = [];
        foreach ($rows as $r) {
            $kode = (string) ($r->kode_jenjang ?? '');
            $tagihan = (string) $r->tagihan;
            $sisa = (string) $r->sisa;
            $hasil[] = [
                'kode' => $kode,
                'nama' => $nama[$kode] ?? ($kode !== '' ? $kode : '(tanpa jenjang)'),
                'urutan' => $nama->keys()->search($kode),
                'tagihan' => $tagihan,
                'terbayar' => Money::sub($tagihan, $sisa),
                'sisa' => $sisa,
                'santri' => (int) $r->santri,
            ];
        }

        usort($hasil, fn ($a, $b) => ($a['urutan'] === false ? PHP_INT_MAX : $a['urutan'])
            <=> ($b['urutan'] === false ? PHP_INT_MAX : $b['urutan']));

        return $hasil;
    }

    /**
     * Blok 5 — penunggak terbesar, tertaut ke rekap masing-masing.
     *
     * @return list<array<string,mixed>>
     */
    public function penunggakTeratas(?string $ta, int $batas = 10): array
    {
        return (clone $this->dasar($ta))
            ->whereIn('tagihan_santri.status', self::MENGGANTUNG)
            ->groupBy('santri.id', 'santri.nama', 'santri.nis', 'santri.kode_jenjang', 'santri.tingkat')
            ->selectRaw('santri.id, santri.nama, santri.nis, santri.kode_jenjang, santri.tingkat')
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(*) AS jumlah_tagihan')
            ->orderByDesc('sisa')
            ->limit($batas)
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'nama' => $r->nama,
                'nis' => $r->nis,
                'jenjang' => $r->kode_jenjang,
                'tingkat' => $r->tingkat,
                'sisa' => (string) $r->sisa,
                'jumlah_tagihan' => (int) $r->jumlah_tagihan,
            ])->all();
    }

    /**
     * Blok 6 — santri yang saldo dompet walinya CUKUP tetapi tagihannya masih
     * menunggak. Artinya auto-debet mati atau gagal.
     *
     * Ini blok yang paling langsung menghasilkan uang: dananya sudah ada di kas
     * pesantren, tagihannya tinggal ditutup. Sebelum ini keadaan tersebut tak
     * terlihat dari layar mana pun.
     *
     * Saldo dompet milik WALI (satu keluarga satu dompet), jadi tunggakan
     * dijumlahkan per wali — bukan per santri — supaya kakak-adik tak dihitung
     * dua kali terhadap saldo yang sama.
     *
     * @return array{jumlah_wali:int,jumlah_santri:int,tertutupi:string,baris:list<array<string,mixed>>}
     */
    public function dompetCukup(?string $ta, int $batas = 10): array
    {
        $perWali = (clone $this->dasar($ta))
            ->whereIn('tagihan_santri.status', self::MENGGANTUNG)
            ->whereNotNull('santri.id_wali')
            ->join('dompet_wali', 'dompet_wali.id_wali', '=', 'santri.id_wali')
            ->join('wali', 'wali.id', '=', 'santri.id_wali')
            ->groupBy('santri.id_wali', 'wali.nama', 'dompet_wali.saldo')
            ->selectRaw('santri.id_wali, wali.nama, dompet_wali.saldo')
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(DISTINCT tagihan_santri.id_santri) AS santri')
            ->havingRaw('dompet_wali.saldo >= COALESCE(SUM(tagihan_santri.sisa), 0)')
            ->orderByDesc('sisa')
            ->get();

        return [
            'jumlah_wali' => $perWali->count(),
            'jumlah_santri' => (int) $perWali->sum('santri'),
            'tertutupi' => (string) $perWali->reduce(fn ($t, $r) => Money::add((string) $t, (string) $r->sisa), '0'),
            'baris' => $perWali->take($batas)->map(fn ($r) => [
                'id_wali' => (int) $r->id_wali,
                'nama' => $r->nama,
                'saldo' => (string) $r->saldo,
                'sisa' => (string) $r->sisa,
                'santri' => (int) $r->santri,
            ])->values()->all(),
        ];
    }

    /**
     * Tunggakan tahun ajaran LAMA — dipisah dari angka utama.
     *
     * Di pesantren, tunggakan tahun-tahun sebelumnya justru sering yang terbesar
     * dan paling terlupakan. Menyembunyikannya membuat dashboard melaporkan
     * keadaan yang lebih baik daripada kenyataan.
     *
     * @return list<array{tahun_ajaran:string,sisa:string,santri:int}>
     */
    public function tunggakanTahunLama(?string $taBerjalan): array
    {
        return (clone $this->dasar(null))
            ->whereIn('tagihan_santri.status', self::MENGGANTUNG)
            ->when($taBerjalan, fn ($q) => $q->where('tagihan_santri.tahun_ajaran', '<', $taBerjalan))
            ->groupBy('tagihan_santri.tahun_ajaran')
            ->selectRaw('tagihan_santri.tahun_ajaran')
            ->selectRaw('COALESCE(SUM(tagihan_santri.sisa), 0) AS sisa')
            ->selectRaw('COUNT(DISTINCT tagihan_santri.id_santri) AS santri')
            ->orderByDesc('tagihan_santri.tahun_ajaran')
            ->get()
            ->map(fn ($r) => [
                'tahun_ajaran' => (string) $r->tahun_ajaran,
                'sisa' => (string) $r->sisa,
                'santri' => (int) $r->santri,
            ])->all();
    }
}
