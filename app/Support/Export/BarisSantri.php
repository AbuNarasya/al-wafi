<?php

namespace App\Support\Export;

use App\Models\Santri;
use App\Models\SumberInformasi;
use App\Models\TagihanSantri;
use App\Models\Wali;
use App\Services\Ppsb\Tahap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Baris export daftar santri — SATU susunan kolom untuk dua pintu unduhan:
 * halaman Export Data (seluruh daftar) dan tombol Unduh di tiap daftar santri
 * (yang membawa penyaring yang sedang aktif). Dipisah ke kelas sendiri supaya
 * kedua pintu itu tak pernah menghasilkan kolom yang berbeda.
 *
 * ISINYA SENGAJA LENGKAP — seluruh kolom master santri berikut seluruh data
 * keluarganya (ayah, ibu, wali). Berkas ini dipakai di luar aplikasi: mengisi
 * berkas Kemenag/Dapodik, menyusun buku induk, menghubungi wali per angkatan.
 * Daftar yang hanya memuat kolom layar memaksa orang membuka satu per satu,
 * dan itulah yang selama ini terjadi. Yang TIDAK ikut hanya kunci internal
 * (id, id_wali), jejak sunting (updated_at), dan kelengkapan OTP wali —
 * ketiganya tak bermakna di luar aplikasi.
 *
 * SISA TAGIHAN ikut dibawa karena itulah yang paling sering dicari saat
 * daftarnya ditarik keluar: alumni yang masih menunggak tetap boleh ditagih,
 * jadi angkanya harus terlihat tanpa membuka satu per satu. Dihitung dengan
 * satu kueri agregat, bukan per baris.
 *
 * Konsekuensi yang perlu diketahui: dengan ±50 kolom, format PDF menjadi sangat
 * padat. PDF memang bukan tujuan berkas selengkap ini — CSV & Excel yang dipakai
 * mengolah lanjut; PDF tetap disediakan untuk daftar yang sudah disaring sempit.
 */
final class BarisSantri
{
    /**
     * @param  Builder|null  $kueri  daftar yang mau diunduh; kosong = seluruh lingkup
     * @return array<int,array<string,scalar|null>>
     */
    public static function dari(string $lingkup, ?Builder $kueri = null): array
    {
        $santri = ($kueri ?? Santri::query()->lingkup($lingkup))
            ->with(['wali', 'jenjang', 'jalurPendaftaran'])
            // Urutan BERKAS, bukan urutan layar: yang membuka berkasnya membaca
            // per kelas, sedangkan daftar di layar mengutamakan baris terbaru.
            ->reorder()->orderBy('kode_jenjang')->orderBy('tingkat')->orderBy('nama')
            ->get();

        $sisa = TagihanSantri::whereIn('id_santri', $santri->pluck('id'))
            ->whereNotIn('status', TagihanSantri::TIDAK_BERLAKU)
            ->selectRaw('id_santri, COALESCE(SUM(sisa), 0) AS sisa')
            ->groupBy('id_santri')->pluck('sisa', 'id_santri');

        // Sumber informasi ber-master sendiri (PPSB → Setting Awal); kodenya yang
        // disimpan di baris santri, jadi namanya diambil sekali di sini.
        $sumber = SumberInformasi::pluck('nama', 'kode');

        $alumni = $lingkup === 'alumni';

        return $santri->map(fn ($s) => array_filter([
            // ---- Identitas ----
            'NIS' => $s->nis ?? '',
            'No. Pendaftaran' => $s->no_pendaftaran,
            'Nama' => $s->nama,
            'Status' => Tahap::labelStatus((string) $s->status),
            'L/P' => $s->jenis_kelamin,
            'Tempat Lahir' => $s->tempat_lahir ?? '',
            'Tanggal Lahir' => self::tgl($s->tanggal_lahir),
            'NISN' => $s->nisn ?? '',

            // ---- Pendidikan ----
            'Jenjang' => $s->jenjang?->nama ?? $s->kode_jenjang ?? '',
            $alumni ? 'Tingkat Akhir' : 'Tingkat' => $s->tingkat ?? '',
            'Angkatan (T.A Masuk)' => $s->tahun_ajaran ?? '',
            'T.A Berjalan' => $s->taBerjalan() ?? '',
            // Kolom ini hanya terisi untuk alumni; disisipkan bersyarat supaya
            // berkas daftar lain tak punya kolom yang selalu kosong.
            'Tanggal Lulus' => $alumni ? self::tgl($s->tanggal_lulus) : null,

            // ---- Penerimaan (PPSB) ----
            'Jalur' => $s->jalurPendaftaran?->nama ?? $s->jalur ?? '',
            // NULL di sini bukan "kosong belum diisi" melainkan pilihan sadar
            // (pindahan & kasus khusus), jadi disebut, bukan dibiarkan kosong.
            'Gelombang' => $s->gelombang ?? 'Tanpa Gelombang',
            'Sumber Informasi' => $s->sumber_informasi ? ($sumber[$s->sumber_informasi] ?? $s->sumber_informasi) : '',
            'Keterangan Sumber Informasi' => $s->sumber_informasi_lain ?? '',
            'Tanggal Didaftarkan' => self::tgl($s->created_at),

            // ---- Sekolah asal ----
            'Asal Sekolah' => $s->asal_sekolah ?? '',
            'Alamat Sekolah Asal' => $s->alamat_sekolah_asal ?? '',
            'Kepala Sekolah Asal' => $s->kepala_sekolah_asal ?? '',
            'CP Kepala Sekolah Asal' => $s->cp_kepala_sekolah_asal ?? '',
            'Wali Kelas Asal' => $s->wali_kelas_asal ?? '',
            'CP Wali Kelas Asal' => $s->cp_wali_kelas_asal ?? '',

            // ---- Keuangan ----
            // Kosong = mengikuti tarif jenjangnya; terisi = nominal khusus anak ini.
            'Nominal SPP Khusus' => $s->nominal_spp ?? '',
            'Keterangan SPP' => $s->keterangan_spp ?? '',
            'Sisa Tagihan' => $sisa[$s->id] ?? 0,

            // ---- Keluarga ----
            ...self::barisWali($s->wali),
        ], fn ($v) => $v !== null))->all();
    }

    /**
     * Kolom keluarga. KETIGA PERAN dibawa utuh, bukan hanya kontak utamanya:
     * yang menghubungi wali sering kali gagal di nomor ayah dan butuh nomor ibu
     * pada baris yang sama, dan berkas Kemenag meminta data kedua orang tua.
     *
     * @return array<string,scalar|null>
     */
    private static function barisWali(?Wali $w): array
    {
        // Nama & telepon "wali" pada kolom kontak utama adalah TURUNAN dari peran
        // yang dipilih (lihat form Wali) — dinamai tegas supaya tak tertukar
        // dengan kolom Nama/Telepon Wali di bawah, yang berarti wali BUKAN
        // orang tua.
        $baris = [
            'Kontak Utama' => Wali::PERAN[$w?->kontak_utama] ?? ($w?->kontak_utama ?? ''),
            'Nama Kontak Utama' => $w?->nama ?? '',
            'Telepon Kontak Utama' => $w?->telepon ?? '',
            'NIK Keluarga' => $w?->nik ?? '',
            'Alamat' => $w?->alamat ?? '',
        ];

        foreach (Wali::PERAN as $peran => $label) {
            $baris["Nama {$label}"] = $w?->{"nama_{$peran}"} ?? '';
            $baris["Telepon {$label}"] = $w?->{"telepon_{$peran}"} ?? '';
            $baris["Email {$label}"] = $w?->{"email_{$peran}"} ?? '';
            $baris["Pekerjaan {$label}"] = $w?->{"pekerjaan_{$peran}"} ?? '';
            $pendapatan = $w?->{"pendapatan_{$peran}"};
            $baris["Pendapatan {$label}"] = $pendapatan ? (Wali::PENDAPATAN[$pendapatan] ?? $pendapatan) : '';
        }

        $baris['Auto-Debet Dompet'] = $w?->auto_debet ? 'Ya' : 'Tidak';
        $baris['Status Wali'] = $w?->status ?? '';

        return $baris;
    }

    private static function tgl($v): string
    {
        if (! $v) {
            return '';
        }

        return $v instanceof \DateTimeInterface ? $v->format('d/m/Y') : Carbon::parse($v)->format('d/m/Y');
    }
}
