<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\CompanySettings;
use App\Models\PengajuanKeluar;
use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

/**
 * BERITA ACARA CLEARANCE PENGUNDURAN DIRI — PDF disahkan elektronik (keputusan user 11 Nov 2026).
 *
 *  • Tersedia hanya setelah SELURUH clearance selesai (status siap_dikeluarkan / selesai).
 *  • Diunduh staf (hak pengunduran-diri lihat); wali TIDAK.
 *  • Keaslian: kode verifikasi = HMAC (APP_KEY) atas isi pokok pengajuan + QR ke halaman cek publik.
 *    Isi pokok berubah (mis. tahap dibuka ulang) → kode lama tak cocok lagi.
 */
final class BeritaAcaraClearance
{
    public const STATUS = ['siap_dikeluarkan', 'selesai'];

    /** Tahap & labelnya, urut sesuai alur. */
    public const TAHAP = [
        'wali_kelas' => 'Verifikasi wali kelas',
        'kepala_sekolah' => 'Persetujuan kepala sekolah',
        'keuangan' => 'Clearance keuangan (tagihan & tunggakan)',
        'kesantrian' => 'Clearance kesantrian (keasramaan)',
        'admin' => 'Clearance administrasi sekolah (surat mutasi, SK)',
    ];

    public static function tersedia(PengajuanKeluar $p): bool
    {
        return in_array($p->status, self::STATUS, true);
    }

    /** Kode verifikasi 12 karakter, dikelompokkan 4-4-4 saat tampil. */
    public static function kode(PengajuanKeluar $p): string
    {
        $isi = implode('|', ['clearance', $p->id, $p->nomor, $p->id_santri, ...array_map(
            fn ($t) => ($p->{"{$t}_oleh"} ?? '').'@'.($p->{"{$t}_pada"}?->timestamp ?? ''), array_keys(self::TAHAP))]);

        return strtoupper(substr(hash_hmac('sha256', $isi, (string) config('app.key')), 0, 12));
    }

    public static function kodeTampil(string $kode): string
    {
        return implode('-', str_split($kode, 4));
    }

    public static function cocok(PengajuanKeluar $p, string $kode): bool
    {
        return self::tersedia($p) && hash_equals(self::kode($p), strtoupper(str_replace(['-', ' '], '', $kode)));
    }

    public static function urlVerifikasi(PengajuanKeluar $p): string
    {
        return route('verifikasi_dokumen.clearance', ['nomor' => $p->nomor, 'kode' => self::kode($p)]);
    }

    /** @return array<string,mixed> */
    public function data(PengajuanKeluar $p): array
    {
        $p->loadMissing(['santri.kelas', 'santri.jenjang', 'wali', 'dokumen']);
        $nama = fn (?int $id) => $id ? (User::where('id_pengguna', $id)->value('nama') ?? '—') : null;

        $tahap = [];
        foreach (self::TAHAP as $k => $label) {
            $tahap[] = ['label' => $label, 'oleh' => $nama($p->{"{$k}_oleh"}), 'pada' => $p->{"{$k}_pada"}, 'catatan' => $p->{"{$k}_catatan"}];
        }
        $peran = \App\Models\Wali::PERAN[$p->diajukan_peran] ?? $p->diajukan_peran;
        $url = self::urlVerifikasi($p);

        return [
            'p' => $p,
            // Kop = nama SEKOLAH (keputusan user 11 Nov 2026), bukan nama yayasan di Pengaturan Perusahaan.
            'lembaga' => \App\Support\Instans::subjudul() ?: \App\Support\Instans::nama(),
            'alamat' => CompanySettings::query()->value('alamat'),
            'pengaju' => $peran ? $peran.' — '.($p->wali?->{"nama_{$p->diajukan_peran}"} ?: $p->wali?->nama) : ($p->wali?->nama ?? '—'),
            'tahap' => $tahap,
            'keuangan' => (new PengunduranDiriService)->keuangan($p->santri),
            'dikeluarkan_oleh' => $nama($p->dikeluarkan_oleh),
            'dapodik_oleh' => $nama($p->dapodik_oleh),
            'kode' => self::kodeTampil(self::kode($p)),
            'url' => $url,
            'qr' => self::qrPng($url),
            'dicetak' => now('Asia/Jakarta'),
        ];
    }

    /**
     * QR sebagai PNG data-URI. Pola QR dari pustaka (matriks), gambarnya digambar sendiri dengan GD: pemberi
     * gambar bawaan pustaka memanggil imagedestroy() yang usang di PHP 8.5 dan gagal (11 Nov 2026).
     */
    public static function qrPng(string $teks, int $skala = 4): string
    {
        $m = (new QRCode(new QROptions(['addQuietzone' => true, 'quietzoneSize' => 2])))->addByteSegment($teks)->getQRMatrix();
        $n = $m->getSize();
        $img = imagecreate($n * $skala, $n * $skala);
        imagecolorallocate($img, 255, 255, 255); // warna pertama = latar
        $hitam = imagecolorallocate($img, 0, 0, 0);
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($m->check($x, $y)) {
                    imagefilledrectangle($img, $x * $skala, $y * $skala, ($x + 1) * $skala - 1, ($y + 1) * $skala - 1, $hitam);
                }
            }
        }
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    public function unduh(PengajuanKeluar $p): Response
    {
        if (! self::tersedia($p)) {
            throw new AppException(422, 'Berita acara baru tersedia setelah seluruh clearance selesai.');
        }
        $opsi = new Options;
        $opsi->set('isRemoteEnabled', false);
        $opsi->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opsi);
        $pdf->loadHtml(view('pengunduran-diri.berita-acara', $this->data($p))->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        $nama = 'Berita-Acara-Clearance-'.preg_replace('/[^A-Za-z0-9\-]+/', '-', $p->nomor).'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$nama.'"',
        ]);
    }
}
