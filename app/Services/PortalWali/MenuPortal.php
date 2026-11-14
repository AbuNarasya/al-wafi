<?php

namespace App\Services\PortalWali;

use App\Exceptions\AppException;
use App\Models\Wali;
use Illuminate\Support\Facades\DB;

/**
 * MENU PORTAL WALI (keputusan user 21 Okt 2026).
 *
 *  • Beranda menampilkan paling banyak MAKS ikon pilihan wali (per orang), lalu ikon "Menu Lengkap".
 *  • Menu Lengkap mengelompokkan SELURUH menu: Keuangan · Akademik · Administrasi · Lainnya
 *    (kelompok tanpa menu tidak ditampilkan).
 *  • Menu bersyarat `dompet` hanya ada bila keluarga punya anak aktif (merekalah yang ber-Dompet Wali).
 *  • Menu baru di portal WAJIB didaftarkan di KATALOG supaya bisa dipilih.
 */
final class MenuPortal
{
    public const MAKS = 7;

    public const GRUP = ['keuangan' => 'Keuangan', 'akademik' => 'Akademik', 'administrasi' => 'Administrasi', 'lainnya' => 'Lainnya'];

    /** kunci => [rute, label, ikon, warna, grup, syarat] */
    public const KATALOG = [
        'tagihan' => ['portal.tagihan', 'Tagihan', 'tagihan', '#be123c', 'keuangan', null],
        'riwayat_tagihan' => ['portal.tagihan.riwayat', 'Riwayat tagihan', 'riwayat', '#b8956a', 'keuangan', null],
        'dompet' => ['portal.dompet', 'Dompet', 'dompet', '#0f172a', 'keuangan', 'dompet'],
        'isi_saldo' => ['portal.dompet.isi', 'Isi saldo', 'isi', '#4f7a5c', 'keuangan', 'dompet'],
        'mutasi_anak' => ['portal.dompet.mutasi_anak', 'Mutasi dompet anak', 'dompet', '#164a9e', 'keuangan', 'dompet'],
        'pindah_saldo' => ['portal.dompet.pindah', 'Pindah saldo', 'otomatis', '#0e7490', 'keuangan', 'dompet'],
        'tabungan' => ['portal.dompet.tabungan', 'Tabungan', 'bank', '#7c3aed', 'keuangan', 'dompet'],
        'tarik_tunai' => ['portal.tarik_tunai.index', 'Penarikan tunai', 'kartu', '#b45309', 'keuangan', 'dompet'],
        'data' => ['portal.data', 'Data wali & santri', 'data', '#475569', 'administrasi', null],
        'perizinan' => ['portal.perizinan', 'Perizinan Insidental', 'keluar', '#0f766e', 'administrasi', null],
        'perizinan_rutin' => ['portal.perizinan_rutin', 'Perizinan Rutin', 'kalender', '#4338ca', 'administrasi', null],
        // Menu lama (data kiriman ISE) — disembunyikan saat menu dari API Histori ISE menyala (keputusan user 14 Nov 2026);
        // kabar "tidak hadir" tetap dari jalur kiriman ini, dan alamatnya dialihkan ke Absensi → Asrama.
        'kehadiran' => ['portal.kehadiran', 'Kehadiran asrama', 'absensi', '#15803d', 'akademik', 'kehadiran_lama'],
        // Dari API Histori ISE (PortalAkademikController) — hanya bila HistoriIse::aktifUntuk().
        'akademik' => ['portal.akademik', 'Akademik', 'akademik', '#1d4ed8', 'akademik', 'ise'],
        'absensi' => ['portal.absensi', 'Absensi', 'absensi', '#15803d', 'akademik', 'ise'],
        'prestasi' => ['portal.prestasi', 'Prestasi', 'prestasi', '#b45309', 'akademik', 'ise'],
        'umpan_balik' => ['portal.umpan_balik', 'Umpan Balik Guru', 'umpan_balik', '#7c3aed', 'akademik', 'ise'],
        'kegiatan' => ['portal.kegiatan', 'Kegiatan', 'kegiatan', '#0e7490', 'akademik', 'ise'],
        'pendaftaran' => ['portal.daftar', 'Pendaftaran', 'psb', '#9b7bb0', 'administrasi', null],
        'klinik' => ['portal.klinik', 'Riwayat klinik', 'klinik', '#b91c1c', 'lainnya', null],
        'laundry' => ['portal.laundry', 'Laundry', 'laundry', '#0891b2', 'lainnya', null],
        'kabar' => ['portal.kabar', 'Kabar', 'lonceng', '#c98a5e', 'lainnya', null],
        // Hanya ada selama asisten bantuan dinyalakan staf (App\Services\PortalWali\AsistenWali).
        'bantuan' => ['portal.bantuan', 'Bantuan', 'bantuan', '#2563eb', 'lainnya', 'bantuan'],
        'akun' => ['portal.akun', 'Akun', 'profil', '#64748b', 'lainnya', null],
    ];

    public const BAWAAN = ['riwayat_tagihan', 'mutasi_anak', 'isi_saldo', 'klinik', 'pendaftaran', 'kabar', 'akun'];

    /** @return array<string,array> kunci => [rute, label, ikon, warna, grup] menu yang berlaku untuk keluarga ini */
    public static function tersedia(Wali $wali): array
    {
        $berdompet = \App\Services\PortalWali\PembayaranPortal::keluargaBerdompet((int) $wali->id);
        $ise = HistoriIse::aktifUntuk($wali);
        $syarat = ['dompet' => $berdompet, 'bantuan' => AsistenWali::aktif(), 'ise' => $ise, 'kehadiran_lama' => ! $ise];

        // Label diterjemahkan di sini (bahasa portal wali) — semua layar pemakai menerima nama menu siap tampil.
        return array_map(function ($m) {
            $m[1] = __($m[1]);

            return $m;
        }, array_filter(self::KATALOG, fn ($m) => $m[5] === null || ($syarat[$m[5]] ?? false)));
    }

    /**
     * Ikon yang diberi TANDA SERU merah di pojoknya (permintaan user 3 Nov 2026): Laundry bila ada anak aktif yang
     * pemakaian bulan ini melebihi kuota atau cuciannya tertahan karena tunggakan.
     *
     * @return array<string,string> kunci menu => keterangan (untuk aria-label/title)
     */
    public static function peringatan(Wali $wali): array
    {
        $kuota = new \App\Services\Modules\TagihanLaundryService;
        $monitor = new \App\Services\Modules\MonitorLaundryService;
        $hasil = [];
        foreach (\App\Models\Santri::where('id_wali', $wali->id)->where('status', 'aktif')->get(['id', 'kode_jenjang', 'jenis_kelamin']) as $s) {
            if ($monitor->tertahan($s)) {
                $hasil['laundry'] = __('Cucian bersih tertahan');
                break;
            }
            if ((float) $kuota->ringkas($s, hanyaDiperiksa: true)['lebih'] > 0) {
                $hasil['laundry'] = __('Over kuota');
            }
        }

        return $hasil;
    }

    /** @return array<string,string> kode grup => nama grup dalam bahasa portal */
    public static function grup(): array
    {
        return array_map(fn ($label) => __($label), self::GRUP);
    }

    /** @return list<string> kunci menu yang dipilih (atau bawaan), berurutan, hanya yang tersedia, paling banyak MAKS. */
    public static function pilihan(Wali $wali, string $peran): array
    {
        $tersedia = self::tersedia($wali);
        $simpan = DB::table('preferensi_menu_wali')->where('id_wali', $wali->id)->where('peran', $peran)->value('menu');
        $kunci = $simpan !== null ? (array) json_decode($simpan, true) : self::BAWAAN;
        $hasil = array_values(array_filter($kunci, fn ($k) => isset($tersedia[$k])));
        // Bawaan yang gugur (mis. menu dompet bagi keluarga tanpa anak aktif) diisi menu tersedia berikutnya.
        if ($simpan === null) {
            foreach (array_keys($tersedia) as $k) {
                if (count($hasil) >= self::MAKS) {
                    break;
                }
                if (! in_array($k, $hasil, true)) {
                    $hasil[] = $k;
                }
            }
        }

        return array_slice($hasil, 0, self::MAKS);
    }

    /** @param list<string> $kunci */
    public static function simpan(Wali $wali, string $peran, array $kunci): void
    {
        $kunci = array_values(array_unique(array_map('strval', $kunci)));
        if ($kunci === []) {
            throw new AppException(422, __('Pilih minimal satu menu.'));
        }
        if (count($kunci) > self::MAKS) {
            throw new AppException(422, __('Menu utama paling banyak :maks ikon. Menu lainnya tetap ada di "Menu Lengkap".', ['maks' => self::MAKS]));
        }
        $tersedia = self::tersedia($wali);
        foreach ($kunci as $k) {
            if (! isset($tersedia[$k])) {
                throw new AppException(422, __('Menu tidak dikenal.'));
            }
        }
        DB::table('preferensi_menu_wali')->updateOrInsert(['id_wali' => $wali->id, 'peran' => $peran],
            ['menu' => json_encode($kunci), 'updated_at' => now(), 'created_at' => now()]);
    }

    public static function kembalikanBawaan(Wali $wali, string $peran): void
    {
        DB::table('preferensi_menu_wali')->where('id_wali', $wali->id)->where('peran', $peran)->delete();
    }
}
