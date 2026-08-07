<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\Jenjang;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\PostingService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OUTSTANDING SPP — tagihan SPP yang sudah terbit tetapi belum tertutup.
 *
 * Isinya lahir dengan sendirinya dari penerbitan: `SppService::generate()`
 * menutup sebagian tagihan seketika lewat saldo prabayar & auto-debet Dompet
 * Wali; yang TIDAK tertutup itulah yang menggantung di sini. Karena daftar ini
 * dibaca langsung dari `tagihan_santri`, sebuah baris hilang dengan sendirinya
 * begitu tagihannya lunas — tak ada tabel salinan yang bisa ketinggalan zaman.
 *
 * Perbedaan penting dengan koreksi uang pangkal: SPP **sudah diakrualkan sejak
 * terbit** (D Piutang / K Pendapatan). Nominalnya karena itu tak boleh sekadar
 * ditimpa — selisihnya WAJIB dibawa ke jurnal penyesuaian, kalau tidak, piutang
 * di buku besar tak lagi sama dengan jumlah sisa tagihan di subledger.
 */
class OutstandingSppService
{
    /** Status tagihan yang dianggap masih menggantung. */
    private const BELUM_TERTUTUP = ['belum_bayar', 'sebagian'];

    /**
     * Daftar tagihan SPP yang belum tertutup.
     *
     * `terbayar` dihitung dari `nominal - sisa`, BUKAN dari jumlah baris
     * pembayaran: saldo prabayar yang dipakai saat penerbitan mengurangi sisa
     * tanpa meninggalkan baris pembayaran sama sekali (lihat
     * SppService::pakaiPrabayar), sehingga menjumlahkan pembayaran akan
     * melaporkan tunggakan yang lebih besar dari kenyataan.
     *
     * @param  array{periode?:string, jenjang?:string, q?:string}  $filter
     * @return list<array<string,mixed>>
     */
    public function daftar(array $filter = []): array
    {
        $periode = trim((string) ($filter['periode'] ?? ''));
        $jenjang = trim((string) ($filter['jenjang'] ?? ''));
        $ta = trim((string) ($filter['tahun_ajaran'] ?? ''));
        $cari = trim((string) ($filter['q'] ?? ''));

        $rows = TagihanSantri::query()
            ->where('perilaku', 'spp')
            ->whereIn('status', self::BELUM_TERTUTUP)
            ->when($periode !== '', fn ($q) => $q->where('periode', $periode))
            ->when($ta !== '', fn ($q) => $q->where('tahun_ajaran', $ta))
            ->when($jenjang !== '', fn ($q) => $q->where('kode_jenjang', $jenjang))
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
        $kini = Carbon::now()->startOfDay();

        $hasil = [];
        foreach ($rows as $t) {
            $s = $t->santri;
            $wali = $s?->wali;
            $sisa = Money::of($t->sisa);
            $hariLewat = $t->jatuh_tempo ? $kini->diffInDays(Carbon::parse($t->jatuh_tempo)->startOfDay(), false) * -1 : null;

            $hasil[] = [
                'id_tagihan' => $t->id,
                'id_santri' => $t->id_santri,
                'nis' => $s?->nis,
                'nama' => $s?->nama,
                'kode_jenjang' => $t->kode_jenjang,
                'jenjang' => $namaJenjang[$t->kode_jenjang] ?? $t->kode_jenjang,
                'tingkat' => $s?->tingkat,
                'periode' => $t->periode,
                'tahun_ajaran' => $t->tahun_ajaran,
                'jenis' => $t->jenis?->nama ?? $t->kode_jenis,
                'nominal' => Money::of($t->nominal),
                'terbayar' => Money::sub($t->nominal, $sisa),
                'menunggu' => Money::of($menunggu[$t->id] ?? 0),
                'sisa' => $sisa,
                'jatuh_tempo' => $t->jatuh_tempo,
                'hari_lewat' => $hariLewat,
                'nama_wali' => $wali?->nama,
                'telepon_wali' => $wali?->telepon,
                // Kenapa tagihan ini TIDAK terpotong otomatis — pertanyaan pertama
                // yang muncul saat melihat daftar ini, jadi jawabannya dibawa serta.
                'auto_debet' => (bool) ($wali?->auto_debet),
                'saldo_dompet' => Money::of($wali?->dompet?->saldo ?? 0),
            ];
        }

        // Jenjang (urutan master) → periode → NIS: sama dengan urutan pratinjau SPP,
        // supaya kedua layar bisa dibaca berdampingan.
        usort($hasil, function ($a, $b) use ($urutanJenjang) {
            $ja = $urutanJenjang[$a['kode_jenjang']] ?? PHP_INT_MAX;
            $jb = $urutanJenjang[$b['kode_jenjang']] ?? PHP_INT_MAX;

            return [$ja, (string) $a['periode'], (string) $a['nis'], (string) $a['nama']]
                <=> [$jb, (string) $b['periode'], (string) $b['nis'], (string) $b['nama']];
        });

        return $hasil;
    }

    /**
     * Ringkasan untuk kepala halaman: berapa baris & berapa rupiah yang menggantung,
     * seluruhnya DAN per jenjang.
     *
     * Rinciannya per jenjang bukan hiasan: angka total sendirian tak memberi tahu
     * ke mana harus menagih, sedangkan jenjang adalah satuan kerja wali kelas —
     * dan tunggakan SMA yang besar berarti tindakan yang berbeda dari tunggakan
     * SDTQ yang tersebar.
     *
     * Urutannya mengikuti `daftar()` yang sudah tersusun menurut urutan master
     * jenjang, jadi warnanya di layar tetap sama dari halaman ke halaman.
     */
    public function ringkasan(array $daftar): array
    {
        $perJenjang = [];
        foreach ($daftar as $r) {
            $kode = $r['kode_jenjang'];
            $perJenjang[$kode] ??= [
                'kode_jenjang' => $kode, 'nama' => $r['jenjang'],
                'baris' => 0, 'sisa' => '0', 'menunggu' => '0', 'santri' => [],
            ];
            $perJenjang[$kode]['baris']++;
            $perJenjang[$kode]['sisa'] = Money::add($perJenjang[$kode]['sisa'], $r['sisa']);
            $perJenjang[$kode]['menunggu'] = Money::add($perJenjang[$kode]['menunggu'], $r['menunggu']);
            $perJenjang[$kode]['santri'][$r['id_santri']] = true;
        }

        return [
            'baris' => count($daftar),
            'santri' => count(array_unique(array_column($daftar, 'id_santri'))),
            'sisa' => array_reduce($daftar, fn ($t, $r) => Money::add($t, $r['sisa']), '0'),
            'menunggu' => array_reduce($daftar, fn ($t, $r) => Money::add($t, $r['menunggu']), '0'),
            'per_jenjang' => array_values(array_map(
                fn ($j) => [...$j, 'santri' => count($j['santri'])],
                $perJenjang,
            )),
        ];
    }

    /** Pilihan periode yang benar-benar ada di daftar — dropdown tanpa periode kosong. */
    public function opsiPeriode(): array
    {
        return TagihanSantri::where('perilaku', 'spp')->whereIn('status', self::BELUM_TERTUTUP)
            ->whereNotNull('periode')->distinct()->orderByDesc('periode')
            ->pluck('periode', 'periode')->all();
    }

    /**
     * Tahun ajaran yang benar-benar punya tunggakan.
     *
     * Penyaringnya ada supaya tunggakan LINTAS TAHUN terbaca sebagai lintas
     * tahun — bukan sebagai satu tumpukan. Santri yang menunggak dua tahun
     * ajaran sekaligus adalah keadaan yang paling perlu terlihat di sini.
     */
    public function opsiTahunAjaran(): array
    {
        return TagihanSantri::where('perilaku', 'spp')->whereIn('status', self::BELUM_TERTUTUP)
            ->whereNotNull('tahun_ajaran')->distinct()->orderByDesc('tahun_ajaran')
            ->pluck('tahun_ajaran', 'tahun_ajaran')->all();
    }

    /**
     * Koreksi nominal satu tagihan SPP yang salah ketik.
     *
     * Yang sudah dibayar dihitung `nominal - sisa` (lihat daftar()), jadi
     * pembayaran lewat kas, Dompet Wali, maupun saldo prabayar sama-sama terhitung.
     *
     * Nol adalah nominal yang SAH — itulah cara membebaskan seorang santri yang
     * telanjur tertagih. Yang ditolak adalah nominal di bawah jumlah yang sudah
     * telanjur dibayar: kelebihan bayar bukan urusan layar ini.
     */
    public function koreksi(int $idTagihan, array $data, int $idPengguna): TagihanSantri
    {
        $t = TagihanSantri::with(['jenis', 'santri'])->find($idTagihan);
        if (! $t) {
            throw new AppException(404, 'Tagihan tidak ditemukan.');
        }
        if ($t->perilaku !== 'spp') {
            throw new AppException(422, 'Layar ini hanya mengoreksi tagihan SPP.');
        }
        if (! $t->berlaku()) {
            throw new AppException(422, $t->status === 'dihapus'
                ? 'Tagihan ini sudah dihapus lewat koreksi nominal, jadi nominalnya tak lagi bermakna.'
                : 'Tagihan ini sudah dibatalkan, jadi nominalnya tak lagi bermakna.');
        }

        $menunggu = PembayaranSantri::where('id_tagihan', $t->id)->where('status', 'menunggu_verifikasi')->count();
        if ($menunggu > 0) {
            throw new AppException(422, "Masih ada {$menunggu} pembayaran yang menunggu verifikasi keuangan. "
                .'Selesaikan dulu agar sisa tagihan tidak dihitung dari angka yang belum pasti.');
        }

        $baru = Money::of($data['nominal']);
        if (Money::isNegative($baru)) {
            throw new AppException(422, 'Nominal tagihan tidak boleh negatif.');
        }

        $lama = Money::of($t->nominal);
        $terbayar = Money::sub($lama, $t->sisa);
        if (Money::lt($baru, $terbayar)) {
            throw new AppException(422, "Nominal baru ({$baru}) lebih kecil dari yang sudah dibayar ({$terbayar}). "
                .'Kelebihan bayar harus diselesaikan keuangan dulu — pengembalian, atau dipindahkan ke tagihan lain.');
        }

        $selisih = Money::sub($baru, $lama);
        $perluJurnal = $t->sudah_akrual && ! Money::isZero($selisih);
        if ($perluJurnal && (! $t->jenis?->kode_coa_piutang || ! $t->jenis?->kode_coa_pendapatan)) {
            throw new AppException(422, "Jenis biaya \"{$t->jenis?->nama}\" belum lengkap akun piutang/pendapatannya, "
                .'sehingga selisih koreksinya tidak bisa dijurnal. Lengkapi dulu di master Jenis Biaya.');
        }

        $sisaBaru = Money::sub($baru, $terbayar);
        $statusBaru = Money::isZero($sisaBaru)
            ? 'lunas'
            : (Money::gtZero($terbayar) ? 'sebagian' : 'belum_bayar');

        return DB::transaction(function () use ($t, $data, $idPengguna, $lama, $baru, $selisih, $sisaBaru, $statusBaru, $terbayar, $perluJurnal) {
            $nomor = null;
            if ($perluJurnal) {
                $nomor = $this->jurnalPenyesuaian($t, $selisih, $data, $idPengguna);
            }

            $t->update([
                'nominal' => $baru,
                'sisa' => $sisaBaru,
                'status' => $statusBaru,
                'jatuh_tempo' => array_key_exists('jatuh_tempo', $data)
                    ? ($data['jatuh_tempo'] ?: null)
                    : $t->jatuh_tempo,
                'keterangan' => trim((string) $t->keterangan.' · koreksi '.($nomor ?? 'tanpa jurnal').": {$data['alasan']}"),
            ]);

            ActivityLog::create([
                'id_pengguna' => $idPengguna,
                'aksi' => 'koreksi_nominal_spp',
                'detail' => json_encode([
                    'id_tagihan' => $t->id, 'id_santri' => $t->id_santri,
                    'nama' => $t->santri?->nama, 'periode' => $t->periode,
                    'nominal_lama' => $lama, 'nominal_baru' => $baru, 'selisih' => $selisih,
                    'sudah_dibayar' => $terbayar, 'sisa_baru' => $sisaBaru,
                    'referensi_jurnal' => $nomor, 'alasan' => $data['alasan'],
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return $t->refresh();
        });
    }

    /**
     * Jurnal penyesuaian atas selisih koreksi.
     *
     * Naik  → menambah piutang & pendapatan (searah akrual aslinya).
     * Turun → kebalikannya, sebesar nilai mutlak selisihnya. Bukan pembatalan
     * seluruh akrual: yang keliru hanya besarannya, bukan kejadiannya.
     */
    private function jurnalPenyesuaian(TagihanSantri $t, string $selisih, array $data, int $idPengguna): string
    {
        $jenis = $t->jenis;
        $tanggal = ($data['tanggal'] ?? null) ?: Carbon::now()->toDateString();
        $base = DocNumber::docBase('KSP', $tanggal);
        $last = JournalEntry::where('referensi', 'like', $base.'%')->orderByDesc('referensi')->value('referensi');
        $nomor = DocNumber::nextDocNumber($base, $last);

        $naik = Money::gtZero($selisih);
        $nilai = $naik ? $selisih : Money::sub('0', $selisih);

        PostingService::postJournal([
            'referensi' => $nomor,
            'tanggal' => $tanggal,
            'kode_unit' => $jenis->kode_unit,
            'sumber_modul' => 'TagihanSpp',
            'id_sumber' => (string) $t->id,
            'id_pengguna' => $idPengguna,
            'keterangan' => "Koreksi nominal {$jenis->nama} {$t->periode} — {$t->santri?->nama}: {$data['alasan']}",
            'lines' => $naik
                ? [
                    ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => $nilai, 'kredit' => '0'],
                    ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => '0', 'kredit' => $nilai],
                ]
                : [
                    ['kode_coa' => $jenis->kode_coa_pendapatan, 'debet' => $nilai, 'kredit' => '0'],
                    ['kode_coa' => $jenis->kode_coa_piutang, 'debet' => '0', 'kredit' => $nilai],
                ],
        ]);

        return $nomor;
    }
}
