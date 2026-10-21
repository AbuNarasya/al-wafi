<?php

namespace App\Support;

use App\Http\Controllers\PortalKeluargaController;
use App\Models\CompanySettings;
use App\Models\PembayaranSantri;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * RECEIPT pembayaran tagihan santri (keputusan user 21 Okt 2026) — satu format untuk ERP & portal wali,
 * mengikuti contoh receipt yang diberikan user: Receipt · Rincian Pembayaran · Pembayar · Lembaga ·
 * Santri · Rincian Tagihan · Total. Satu pembayaran, atau seluruh anggota transfer berkelompok.
 * Hanya untuk pembayaran TERVERIFIKASI (pemanggil yang memastikan).
 */
final class Receipt
{
    private const METODE = ['dompet_wali' => 'Dompet Wali', 'midtrans' => 'Virtual Account', 'xendit' => 'Pembayaran online', 'manual' => 'Transfer / Tunai'];

    /** @param Collection<int,PembayaranSantri> $baris */
    public static function data(Collection $baris): array
    {
        $baris = new \Illuminate\Database\Eloquent\Collection($baris->all()); // collect([...]) tak punya loadMissing
        $baris->loadMissing(['santri.wali', 'santri.jenjang:kode,nama', 'santri.kelas:id,nama', 'tagihan.jenis', 'rekening']);
        $p = $baris->first();
        $wali = $p->santri?->wali;
        $peran = $wali?->kontak_utama ?: 'ayah';
        $c = CompanySettings::find(1);
        // Ikon 192 px, BUKAN logo.png (600 KB): dompdf memekarkan PNG beralfa itu hingga melampaui batas memori 128 MB.
        $logo = public_path('ikon/ikon-any-192.png');
        $metode = (self::METODE[$p->sumber] ?? 'Transfer / Tunai').($p->sumber === 'manual' && $p->metode ? ' · '.$p->metode : '');

        return [
            'nomor' => $baris->count() > 1 ? $p->nomor.' – '.$baris->last()->nomor : $p->nomor,
            'tanggal' => ($p->diverifikasi_pada ?? $p->created_at)?->timezone('Asia/Jakarta'),
            'tanggal_bayar' => $p->tanggal,
            'metode' => $metode,
            'rekening' => $p->rekening?->nama_rekening,
            'wali' => [
                'nama' => $wali?->{'nama_'.$peran} ?: $wali?->nama,
                'email' => $wali?->{'email_'.$peran} ?: ($wali?->email_ayah ?: $wali?->email_ibu ?: $wali?->email_wali),
                'telepon' => $wali?->{'telepon_'.$peran} ?: $wali?->telepon,
            ],
            'lembaga' => ['nama' => $c?->nama_perusahaan ?: config('app.name'), 'npwp' => $c?->npwp, 'alamat' => $c?->alamat,
                'telepon' => $c?->telepon, 'email' => $c?->email],
            'santri' => $baris->pluck('santri')->filter()->unique('id')->values(),
            'item' => $baris->map(fn (PembayaranSantri $b) => [
                'jenis' => $b->tagihan?->jenis?->nama ?? 'Pembayaran',
                'uraian' => ($b->tagihan ? PortalKeluargaController::namaTagihan($b->tagihan) : 'Pembayaran').' — '.$b->santri?->nama,
                'nominal' => (float) $b->nominal,
            ])->values(),
            'total' => (float) $baris->sum(fn ($b) => (float) $b->nominal),
            'logo' => is_file($logo) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logo)) : null,
        ];
    }

    /** @param Collection<int,PembayaranSantri> $baris */
    public static function unduh(Collection $baris, bool $lampiran = true): Response
    {
        $d = self::data($baris);
        $opsi = new Options;
        $opsi->set('isRemoteEnabled', false);
        $opsi->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opsi);
        $pdf->loadHtml(view('receipt.pembayaran', ['r' => $d])->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $nama = 'Receipt-'.preg_replace('/[^A-Za-z0-9\-]+/', '-', $baris->first()->nomor).'.pdf';

        return new \Illuminate\Http\Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($lampiran ? 'attachment' : 'inline').'; filename="'.$nama.'"',
        ]);
    }
}
