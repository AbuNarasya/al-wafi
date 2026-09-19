<?php

namespace App\Http\Controllers;

use App\Models\Jenjang;
use App\Services\Reports\PenerimaanKesantrianService;
use App\Support\Export\Exporter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Laporan penerimaan kesantrian per periode (READ-ONLY): setoran terverifikasi
 * per jenis biaya × bulan, dengan uji-diri terhadap jurnal PembayaranSantri.
 */
class PenerimaanKesantrianController extends Controller
{
    /** Label singkat perilaku — daftar panjang di TipeBiaya::PERILAKU untuk layar setelan, bukan untuk penyaring. */
    private const PERILAKU = [
        'registrasi' => 'Registrasi',
        'uang_pangkal' => 'Uang Pangkal',
        'perlengkapan' => 'Perlengkapan',
        'daftar_ulang' => 'Daftar Ulang',
        'spp' => 'SPP',
        'lain' => 'Lain-lain',
    ];

    public function index(Request $request): View
    {
        [$from, $to, $jenjang, $perilaku] = $this->saringan($request);

        return view('kesantrian.penerimaan', [
            'data' => (new PenerimaanKesantrianService)->laporan($from, $to, $jenjang, $perilaku),
            'from' => $from, 'to' => $to, 'jenjang' => $jenjang, 'perilaku' => $perilaku,
            'jenjangOptions' => Jenjang::orderBy('urutan')->pluck('nama', 'kode')->all(),
            'perilakuOptions' => self::PERILAKU,
        ]);
    }

    public function download(Request $request)
    {
        [$from, $to, $jenjang, $perilaku] = $this->saringan($request);
        $d = (new PenerimaanKesantrianService)->laporan($from, $to, $jenjang, $perilaku);

        $rows = [];
        foreach ($d['baris'] as $b) {
            $baris = ['Jenjang' => $b['nama_jenjang'], 'Jenis Biaya' => $b['nama_jenis'], 'Perilaku' => $b['perilaku'] ?? ''];
            foreach ($d['bulan'] as $bl) {
                $baris[$bl['label']] = $b['per_bulan'][$bl['kunci']];
            }
            $baris['Total'] = $b['total'];
            $rows[] = $baris;
        }

        $totalRow = ['Jenjang' => '', 'Jenis Biaya' => 'TOTAL', 'Perilaku' => ''];
        foreach ($d['bulan'] as $bl) {
            $totalRow[$bl['label']] = $d['total_per_bulan'][$bl['kunci']];
        }
        $totalRow['Total'] = $d['total'];
        $rows[] = $totalRow;

        return Exporter::download(
            $request->query('format', 'csv'),
            "penerimaan_kesantrian_{$from}_{$to}",
            "Penerimaan Kesantrian {$from} s.d. {$to}",
            $rows,
        );
    }

    /** @return array{0:string,1:string,2:?string,3:?string} */
    private function saringan(Request $request): array
    {
        $from = $request->query('from', now()->startOfYear()->toDateString());
        $to = $request->query('to', now()->toDateString());

        $jenjang = trim((string) $request->query('kode_jenjang', ''));
        $jenjang = $jenjang !== '' && Jenjang::whereKey($jenjang)->exists() ? $jenjang : null;

        $perilaku = trim((string) $request->query('perilaku', ''));
        $perilaku = isset(self::PERILAKU[$perilaku]) ? $perilaku : null;

        return [$from, $to, $jenjang, $perilaku];
    }
}
