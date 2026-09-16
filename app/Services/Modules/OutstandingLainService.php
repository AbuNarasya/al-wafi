<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\Jenjang;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * OUTSTANDING TAGIHAN LAIN — tunggakan Kesantrian SELAIN SPP.
 *
 * Latar: penanda "Tugas Saya" menghitung tagihan yang mendekati atau lewat jatuh
 * tempo, tetapi hanya SPP yang punya layar untuk menelusurinya. Untuk laundry,
 * ekskul, seragam, dan daftar ulang, petugas hanya melihat ANGKA tugas — tanpa
 * tahu siapa yang harus ditagih dan berapa. Daftar ini yang menjawabnya.
 *
 * Cakupannya `lain` + `daftar_ulang`: keduanya memicu penanda tugas yang sama
 * ("Pembayaran SPP & Tagihan Lain"), jadi meninggalkan salah satunya akan
 * mengulang persis masalah yang hendak diperbaiki. SPP punya layarnya sendiri;
 * uang pangkal punya modul Angsuran sendiri — keduanya sengaja di luar sini
 * supaya tak ada dua layar yang melaporkan angka berbeda untuk hal yang sama.
 *
 * Dibaca LANGSUNG dari `tagihan_santri`. Tak ada tabel salinan, jadi sebuah
 * baris hilang dengan sendirinya begitu tagihannya lunas atau dihapus.
 */
class OutstandingLainService
{
    /** Perilaku yang ditangani layar ini. Lihat alasan cakupannya di kepala kelas. */
    public const PERILAKU = ['lain', 'daftar_ulang'];

    /** Status tagihan yang dianggap masih menggantung. */
    private const BELUM_TERTUTUP = ['belum_bayar', 'sebagian'];

    /**
     * Daftar tagihan yang belum tertutup.
     *
     * `terbayar` dihitung dari `nominal - sisa`, BUKAN dari jumlah baris
     * pembayaran: auto-debet Dompet Wali menutup tagihan tanpa meninggalkan baris
     * pembayaran sama sekali, sehingga menjumlahkan pembayaran akan melaporkan
     * tunggakan yang lebih besar dari kenyataan.
     *
     * @param  array{tahun_ajaran?:string, jenis?:string, jenjang?:string, q?:string, tempo?:string}  $filter
     * @return list<array<string,mixed>>
     */
    public function daftar(array $filter = []): array
    {
        $ta = trim((string) ($filter['tahun_ajaran'] ?? ''));
        $jenis = trim((string) ($filter['jenis'] ?? ''));
        $jenjang = trim((string) ($filter['jenjang'] ?? ''));
        $cari = trim((string) ($filter['q'] ?? ''));
        $tempo = trim((string) ($filter['tempo'] ?? ''));
        $kini = Carbon::now()->startOfDay();

        $rows = TagihanSantri::query()
            ->whereIn('perilaku', self::PERILAKU)
            ->whereIn('status', self::BELUM_TERTUTUP)
            ->when($ta !== '', fn ($q) => $q->where('tahun_ajaran', $ta))
            ->when($jenis !== '', fn ($q) => $q->where('kode_jenis', $jenis))
            ->when($jenjang !== '', fn ($q) => $q->where('kode_jenjang', $jenjang))
            // Penyaring jatuh tempo memakai tanggal, bukan kolom turunan: yang
            // dicari petugas adalah "mana yang sudah telat", dan itu pertanyaan
            // tentang hari ini — bukan tentang isi sebuah kolom.
            ->when($tempo === 'lewat', fn ($q) => $q->whereNotNull('jatuh_tempo')->whereDate('jatuh_tempo', '<', $kini))
            ->when($tempo === 'tanpa', fn ($q) => $q->whereNull('jatuh_tempo'))
            ->when($cari !== '', fn ($q) => $q->whereHas('santri', fn ($s) => $s
                ->where('nama', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%")))
            ->with(['jenis', 'santri.wali.dompet'])
            ->get();

        // Setoran yang sudah dicatat tapi belum diverifikasi keuangan BELUM
        // mengurangi sisa. Tanpa kolomnya, petugas akan menagih ulang orang yang
        // sebenarnya sudah membayar kemarin.
        $menunggu = PembayaranSantri::whereIn('id_tagihan', $rows->pluck('id'))
            ->where('status', 'menunggu_verifikasi')
            ->selectRaw('id_tagihan, COALESCE(SUM(nominal), 0) AS n')
            ->groupBy('id_tagihan')->pluck('n', 'id_tagihan');

        $namaJenjang = Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();
        $urutanJenjang = array_flip(array_keys($namaJenjang));

        $hasil = [];
        foreach ($rows as $t) {
            $s = $t->santri;
            $wali = $s?->wali;
            $sisa = Money::of($t->sisa);

            $hasil[] = [
                'id_tagihan' => $t->id,
                'id_santri' => $t->id_santri,
                'nis' => $s?->nis,
                'nama' => $s?->nama,
                'status_santri' => $s?->status,
                'kode_jenjang' => $t->kode_jenjang,
                'jenjang' => $namaJenjang[$t->kode_jenjang] ?? $t->kode_jenjang,
                'tingkat' => $s?->tingkat,
                'perilaku' => $t->perilaku,
                'kode_jenis' => $t->kode_jenis,
                'jenis' => $t->jenis?->nama ?? $t->kode_jenis,
                'periode' => $t->periode,
                'keterangan' => $t->keterangan,
                'tahun_ajaran' => $t->tahun_ajaran,
                'nominal' => Money::of($t->nominal),
                'terbayar' => Money::sub($t->nominal, $sisa),
                'menunggu' => Money::of($menunggu[$t->id] ?? 0),
                'sisa' => $sisa,
                'jatuh_tempo' => $t->jatuh_tempo,
                // `(int)` bukan hiasan: diffInDays() mengembalikan float, dan
                // "lewat 11.0 hari" bocor ke layar sekaligus membuat perbandingan
                // di test & penyaring jadi tak terduga.
                'hari_lewat' => $t->jatuh_tempo
                    ? (int) ($kini->diffInDays(Carbon::parse($t->jatuh_tempo)->startOfDay(), false) * -1)
                    : null,
                // Tunggakan warisan ditandai: nominalnya tak lahir dari penagihan
                // di aplikasi ini, jadi salah-tagihnya pun beda sebabnya.
                'saldo_awal' => (bool) $t->saldo_awal,
                'nama_wali' => $wali?->nama,
                'telepon_wali' => $wali?->telepon,
                // Kenapa tagihan ini TIDAK terpotong otomatis — pertanyaan pertama
                // yang muncul saat melihat daftar ini, jadi jawabannya dibawa serta.
                'auto_debet' => (bool) ($wali?->auto_debet),
                'saldo_dompet' => Money::of($wali?->dompet?->saldo ?? 0),
                // Tagihan lain-lain harus dilunasi SEKALIGUS (lihat
                // PembayaranSantriService::LUNAS_SEKALIGUS): dompet yang isinya
                // kurang dari sisa tak menolong sama sekali, dan itu keterangan
                // yang menentukan tindakan petugas.
                'dompet_cukup' => Money::gte($wali?->dompet?->saldo ?? 0, $sisa),
            ];
        }

        // Yang paling telat lebih dulu — itulah urutan kerja yang sebenarnya.
        // Tagihan tanpa jatuh tempo turun ke bawah (bukan dibuang): ia tetap
        // tunggakan, hanya tak punya tenggat.
        usort($hasil, function ($a, $b) use ($urutanJenjang) {
            $la = $a['hari_lewat'] ?? PHP_INT_MIN;
            $lb = $b['hari_lewat'] ?? PHP_INT_MIN;
            if ($la !== $lb) {
                return $lb <=> $la;
            }
            $ja = $urutanJenjang[$a['kode_jenjang']] ?? PHP_INT_MAX;
            $jb = $urutanJenjang[$b['kode_jenjang']] ?? PHP_INT_MAX;

            return [$ja, (string) $a['nis'], (string) $a['nama']] <=> [$jb, (string) $b['nis'], (string) $b['nama']];
        });

        return $hasil;
    }

    /**
     * Ringkasan kepala halaman.
     *
     * Dipecah per JENIS TAGIHAN, bukan per jenjang seperti layar SPP: di sini
     * satu santri bisa menunggak beberapa hal sekaligus, dan tindakan untuk
     * laundry berbeda dari tindakan untuk daftar ulang. Jenjangnya tetap ada
     * sebagai penyaring.
     *
     * @param  list<array<string,mixed>>  $daftar
     */
    public function ringkasan(array $daftar): array
    {
        $sisa = '0';
        $menunggu = '0';
        $santri = [];
        $lewat = 0;
        $lewatSisa = '0';
        $perJenis = [];

        foreach ($daftar as $r) {
            $sisa = Money::add($sisa, $r['sisa']);
            $menunggu = Money::add($menunggu, $r['menunggu']);
            $santri[$r['id_santri']] = true;

            if (($r['hari_lewat'] ?? 0) > 0) {
                $lewat++;
                $lewatSisa = Money::add($lewatSisa, $r['sisa']);
            }

            $kode = $r['kode_jenis'];
            $perJenis[$kode] ??= ['kode_jenis' => $kode, 'nama' => $r['jenis'], 'baris' => 0, 'sisa' => '0', 'santri' => []];
            $perJenis[$kode]['baris']++;
            $perJenis[$kode]['sisa'] = Money::add($perJenis[$kode]['sisa'], $r['sisa']);
            $perJenis[$kode]['santri'][$r['id_santri']] = true;
        }

        $perJenis = array_map(fn ($j) => $j + ['jumlah_santri' => count($j['santri'])], array_values($perJenis));
        usort($perJenis, fn ($a, $b) => Money::cmp($b['sisa'], $a['sisa']));

        return [
            'baris' => count($daftar),
            'santri' => count($santri),
            'sisa' => Money::of($sisa),
            'menunggu' => Money::of($menunggu),
            'lewat' => $lewat,
            'lewat_sisa' => Money::of($lewatSisa),
            'per_jenis' => array_map(fn ($j) => array_diff_key($j, ['santri' => true]), $perJenis),
        ];
    }

    /**
     * Pilihan jenis biaya untuk penyaring — hanya yang BENAR-BENAR punya
     * tunggakan, bukan seluruh master.
     *
     * Master jenis biaya memuat jenis dari jenjang mana pun dan dari tahun kapan
     * pun; menawarkan semuanya membuat petugas menyaring ke daftar kosong dan
     * menyangka datanya hilang.
     *
     * @return array<string,string>
     */
    public function opsiJenis(): array
    {
        // Kolomnya DIKUALIFIKASI: `jenis_biaya` dan `tagihan_santri` sama-sama
        // punya `status`, dan PostgreSQL menolak rujukan yang ambigu.
        return TagihanSantri::query()
            ->join('jenis_biaya', 'jenis_biaya.kode', '=', 'tagihan_santri.kode_jenis')
            ->whereIn('tagihan_santri.perilaku', self::PERILAKU)
            ->whereIn('tagihan_santri.status', self::BELUM_TERTUTUP)
            ->distinct()->orderBy('jenis_biaya.nama')
            ->pluck('jenis_biaya.nama', 'tagihan_santri.kode_jenis')->all();
    }

    /** @return list<string> */
    public function opsiTahunAjaran(): array
    {
        return TagihanSantri::query()
            ->whereIn('perilaku', self::PERILAKU)
            ->whereIn('status', self::BELUM_TERTUTUP)
            ->whereNotNull('tahun_ajaran')
            ->distinct()->orderByDesc('tahun_ajaran')
            ->pluck('tahun_ajaran')->all();
    }

    /**
     * Koreksi dari layar ini — nominal lewat KoreksiTagihanService, jatuh tempo
     * langsung.
     *
     * Nominalnya TIDAK ditimpa di sini. Tagihan lain-lain bisa berpengakuan
     * akrual (piutangnya sudah di buku besar), dan menimpanya diam-diam membuat
     * piutang tak lagi sama dengan jumlah sisa tagihan. Servicenya sudah
     * menangani itu beserta kelebihan bayar ke Dompet Wali dan jadwal angsuran
     * yang gugur — jadi dipanggil, bukan ditiru.
     *
     * Jatuh tempo sebaliknya BUKAN peristiwa akuntansi: menggesernya tak
     * menyentuh sepeser pun, jadi ia diperlakukan sebagai suntingan biasa yang
     * cukup meninggalkan jejak di log.
     *
     * @param  array{nominal:string|int|float, jatuh_tempo?:string|null, alasan:string}  $data
     */
    public function koreksi(int $idTagihan, array $data, int $idPengguna): TagihanSantri
    {
        $tagihan = TagihanSantri::with(['jenis', 'santri'])->find($idTagihan);
        if (! $tagihan) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        if (! in_array($tagihan->perilaku, self::PERILAKU, true)) {
            throw new AppException(422, 'Tagihan ini bukan tagihan lain-lain atau daftar ulang, '
                .'jadi tak bisa dikoreksi dari layar ini.');
        }

        $baru = Money::of($data['nominal']);
        $alasan = trim((string) $data['alasan']);

        if (! Money::eq($baru, $tagihan->nominal)) {
            (new KoreksiTagihanService)->koreksi($idTagihan, $baru, $alasan, $idPengguna);
        }

        $tempoBaru = ($data['jatuh_tempo'] ?? '') !== '' ? Carbon::parse($data['jatuh_tempo'])->toDateString() : null;
        $tempoLama = $tagihan->jatuh_tempo?->toDateString();
        if ($tempoBaru !== $tempoLama) {
            $tagihan->update(['jatuh_tempo' => $tempoBaru]);
            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'ubah_jatuh_tempo_tagihan',
                'detail' => json_encode([
                    'id_tagihan' => $tagihan->id,
                    'id_santri' => $tagihan->id_santri,
                    'nama' => $tagihan->santri?->nama,
                    'kode_jenis' => $tagihan->kode_jenis,
                    'dari' => $tempoLama,
                    'menjadi' => $tempoBaru,
                    'alasan' => $alasan,
                ], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $tagihan->refresh();
    }
}
