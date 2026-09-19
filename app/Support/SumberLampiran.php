<?php

namespace App\Support;

use App\Models\AdvanceSettlement;
use App\Models\KebijakanKhusus;
use App\Models\LampiranDokumen;
use App\Models\OperationalAdvance;
use App\Models\PelepasanAset;
use App\Models\PengajuanPembayaran;
use Illuminate\Database\Eloquent\Model;

/**
 * SUMBER LAMPIRAN — satu tempat yang tahu segalanya tentang dokumen yang boleh
 * dilampiri: modelnya, modul hak aksesnya, status yang masih boleh disunting,
 * dan ke mana tombol Kembali mengantar.
 *
 * Semuanya dikumpulkan di sini supaya menambah modul berikutnya (Kas Keluar,
 * Invoice Vendor, Perintah Pembayaran) cukup satu baris — tanpa menyentuh
 * controller, service, maupun layarnya.
 *
 * `status_terbuka` = daftar status yang masih boleh DITAMBAH & DIHAPUS
 * lampirannya. Di luar itu berkasnya tetap bisa dilihat & diunduh, tapi tak
 * bisa diubah lagi: begitu keuangan memverifikasi, lampiran menjadi bagian
 * bukti pembukuan, dan bukti yang sudah dibaca penyetuju tak boleh bisa
 * ditukar diam-diam sesudahnya.
 */
final class SumberLampiran
{
    public const PENGAJUAN = 'pengajuan_pembayaran';

    public const UANG_MUKA = 'operational_advance';

    public const PENYELESAIAN = 'advance_settlement';

    public const PELEPASAN_ASET = 'pelepasan_aset';

    /**
     * Dua surat wajib pada Kebijakan Khusus. Sengaja DUA jenis dokumen yang
     * berbeda meski menunjuk baris yang sama: hanya dengan begitu sistem bisa
     * tahu mana yang sudah ada dan mana yang belum — kalau digabung jadi satu
     * jenis, dua permohonan tanpa persetujuan pun akan terbaca lengkap.
     */
    public const KEBIJAKAN_PERMOHONAN = 'kebijakan_permohonan';

    public const KEBIJAKAN_PERSETUJUAN = 'kebijakan_persetujuan';

    /**
     * @var array<string,array{label:string,model:class-string<Model>,modul:string,status_terbuka:list<string>,kolom_nomor:string}>
     */
    public const SUMBER = [
        self::PENGAJUAN => [
            'label' => 'Pengajuan Pembayaran',
            'model' => PengajuanPembayaran::class,
            'modul' => 'pengajuan-pembayaran',
            // Sesudah diverifikasi keuangan hutangnya sudah dijurnal.
            'status_terbuka' => ['diajukan', 'ditolak'],
            'kolom_nomor' => 'nomor',
        ],
        self::UANG_MUKA => [
            'label' => 'Uang Muka Operasional',
            'model' => OperationalAdvance::class,
            'modul' => 'operational-advance',
            'status_terbuka' => ['outstanding'],
            'kolom_nomor' => 'nomor_ref',
        ],
        self::PENYELESAIAN => [
            'label' => 'Penyelesaian Uang Muka',
            'model' => AdvanceSettlement::class,
            'modul' => 'advance-settlement',
            'status_terbuka' => ['aktif'],
            'kolom_nomor' => 'nomor_referensi',
        ],
        self::PELEPASAN_ASET => [
            'label' => 'Pelepasan Aset',
            'model' => PelepasanAset::class,
            'modul' => 'assets',
            // Berita acara & bukti jualnya masih boleh dilengkapi selama
            // dokumennya hidup. Begitu di-void, berkasnya tetap bisa dibaca
            // tapi tak bisa ditukar lagi.
            'status_terbuka' => ['aktif'],
            'kolom_nomor' => 'nomor_ref',
        ],
        self::KEBIJAKAN_PERMOHONAN => [
            'label' => 'Surat Permohonan Wali',
            'model' => KebijakanKhusus::class,
            'modul' => 'kebijakan-khusus',
            // Masih boleh diganti selama belum diputuskan. Sesudah disetujui,
            // surat itu sudah jadi dasar keputusan dan tak boleh ditukar.
            'status_terbuka' => ['diajukan'],
            'kolom_nomor' => 'id',
        ],
        self::KEBIJAKAN_PERSETUJUAN => [
            'label' => 'Surat Persetujuan Yayasan',
            'model' => KebijakanKhusus::class,
            'modul' => 'kebijakan-khusus',
            'status_terbuka' => ['diajukan'],
            'kolom_nomor' => 'id',
        ],
    ];

    public static function dikenal(string $jenis): bool
    {
        return isset(self::SUMBER[$jenis]);
    }

    /** @return array{label:string,model:class-string<Model>,modul:string,status_terbuka:list<string>,kolom_nomor:string} */
    public static function konfigurasi(string $jenis): array
    {
        return self::SUMBER[$jenis] ?? throw new \InvalidArgumentException("Sumber lampiran \"{$jenis}\" tidak dikenal.");
    }

    /** Kode modul hak akses yang menggerbangi lampiran sumber ini. */
    public static function modul(string $jenis): string
    {
        return self::konfigurasi($jenis)['modul'];
    }

    public static function label(string $jenis): string
    {
        return self::konfigurasi($jenis)['label'];
    }

    /** Dokumen induknya, atau null bila sudah tak ada. */
    public static function dokumen(string $jenis, string $id): ?Model
    {
        $model = self::konfigurasi($jenis)['model'];

        return $model::find($id);
    }

    /**
     * Masih boleh ditambah/dihapus lampirannya? Dokumen yang sudah hilang
     * dianggap terkunci — bukan terbuka — supaya tak ada berkas baru menempel
     * pada nomor yang tak lagi ada.
     */
    public static function terbuka(string $jenis, ?Model $dokumen): bool
    {
        if (! $dokumen) {
            return false;
        }

        return in_array($dokumen->status, self::konfigurasi($jenis)['status_terbuka'], true);
    }

    /** Nomor dokumen untuk judul layar; jatuh ke "#id" bila nomornya kosong. */
    public static function nomor(string $jenis, Model $dokumen): string
    {
        $kolom = self::konfigurasi($jenis)['kolom_nomor'];

        return (string) ($dokumen->{$kolom} ?: '#'.$dokumen->getKey());
    }

    /** Ke mana tombol Kembali mengantar — halaman detailnya bila ada, daftarnya bila tidak. */
    public static function urlKembali(string $jenis, string $id): string
    {
        return match ($jenis) {
            // Hanya pengajuan yang punya halaman detail; dua lainnya baru punya daftar.
            self::PENGAJUAN => route('pengajuan.show', $id),
            self::UANG_MUKA => route('operational_advance.index'),
            self::PENYELESAIAN => route('advance_settlement.index'),
            self::PELEPASAN_ASET => route('assets.pelepasan'),
            self::KEBIJAKAN_PERMOHONAN, self::KEBIJAKAN_PERSETUJUAN => route('kebijakan_khusus.index'),
        };
    }

    /**
     * Jumlah lampiran untuk sekumpulan dokumen sejenis — satu query untuk satu
     * halaman daftar, bukan satu query per baris.
     *
     * @param  list<int|string>  $ids
     * @return array<string,int>
     */
    public static function jumlahPer(string $jenis, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return LampiranDokumen::where('jenis_dokumen', $jenis)
            ->whereIn('id_dokumen', array_map('strval', $ids))
            ->selectRaw('id_dokumen, count(*) as jml')
            ->groupBy('id_dokumen')
            ->pluck('jml', 'id_dokumen')
            ->all();
    }
}
