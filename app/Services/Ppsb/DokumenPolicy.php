<?php

namespace App\Services\Ppsb;

/**
 * Kebijakan dokumen santri. Jenis MENENTUKAN tahapnya (bukan dikirim klien).
 * Dokumen pasca_lulus hanya boleh diunggah setelah calon dinyatakan lulus.
 */
final class DokumenPolicy
{
    public const TAHAP_DOKUMEN = [
        'ktp_ayah' => 'registrasi', 'ktp_ibu' => 'registrasi', 'ktp_wali' => 'registrasi',
        'akta_kelahiran' => 'registrasi', 'kartu_keluarga' => 'registrasi', 'foto' => 'registrasi',
        // Berkas pindahan dibawa saat mendaftar → tahap registrasi (opsional, hanya untuk calon pindahan).
        'surat_keterangan_aktif' => 'registrasi', 'surat_keterangan_kelakuan_baik' => 'registrasi',
        'rapot_terakhir' => 'registrasi',
        'surat_keterangan_sekolah' => 'pasca_lulus', 'hasil_medcheck' => 'pasca_lulus', 'lainnya' => 'pasca_lulus',
    ];

    public const LABEL_DOKUMEN = [
        'ktp_ayah' => 'KTP Ayah', 'ktp_ibu' => 'KTP Ibu', 'ktp_wali' => 'KTP Wali',
        'akta_kelahiran' => 'Akta Kelahiran', 'kartu_keluarga' => 'Kartu Keluarga', 'foto' => 'Foto',
        'surat_keterangan_aktif' => 'Surat Keterangan Aktif',
        'surat_keterangan_kelakuan_baik' => 'Surat Keterangan Kelakuan Baik',
        'rapot_terakhir' => 'Rapot Terakhir',
        'surat_keterangan_sekolah' => 'Surat Keterangan Siswa Sekolah Sebelumnya',
        'hasil_medcheck' => 'Hasil Med Check', 'lainnya' => 'Dokumen Lainnya',
    ];

    /**
     * KELOMPOK tampilan checklist — beda dari TAHAP (yang menggerbangi kapan
     * boleh diunggah). Berkas pindahan bertahap "registrasi" tapi tampil di
     * kelompoknya sendiri karena hanya berlaku bagi calon pindahan.
     */
    public const KELOMPOK_DOKUMEN = [
        'ktp_ayah' => 'registrasi', 'ktp_ibu' => 'registrasi', 'ktp_wali' => 'registrasi',
        'akta_kelahiran' => 'registrasi', 'kartu_keluarga' => 'registrasi', 'foto' => 'registrasi',
        'surat_keterangan_aktif' => 'pindahan', 'surat_keterangan_kelakuan_baik' => 'pindahan',
        'rapot_terakhir' => 'pindahan',
        'surat_keterangan_sekolah' => 'pasca_lulus', 'hasil_medcheck' => 'pasca_lulus', 'lainnya' => 'pasca_lulus',
    ];

    public const LABEL_KELOMPOK = [
        'registrasi' => 'Berkas Registrasi',
        'pindahan' => 'Berkas Pindahan Dari Sekolah Asal',
        'pasca_lulus' => 'Berkas Setelah Lulus',
    ];

    private const SUDAH_LULUS = ['diterima', 'lolos_kesehatan', 'aktif', 'alumni'];

    /** @return array{boleh:bool,alasan?:string} */
    public static function bolehUnggah(string $jenis, string $statusSantri): array
    {
        if ((self::TAHAP_DOKUMEN[$jenis] ?? null) === 'pasca_lulus' && ! in_array($statusSantri, self::SUDAH_LULUS, true)) {
            return ['boleh' => false, 'alasan' => "\"".(self::LABEL_DOKUMEN[$jenis] ?? $jenis)."\" baru diminta setelah calon dinyatakan lulus. Selesaikan dulu tahap pengumuman."];
        }

        return ['boleh' => true];
    }
}
