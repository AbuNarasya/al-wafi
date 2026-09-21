<?php

namespace App\Http\Controllers;

use App\Services\Reports\SaldoDompetService;
use App\Support\Export\Exporter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Saldo Dompet — daftar saldo seluruh wali & santri.
 *
 * MENUMPANG hak modul `dompet`: siapa yang boleh melihat dompet, boleh melihat
 * daftarnya. Tak ada modul hak baru, jadi tak ada centang tambahan yang harus
 * diberikan manual di produksi — dan yang lupa dicentang gagalnya senyap.
 *
 * Seluruh rutenya BACA SAJA. Top-up, pemindahan, dan penarikan tetap di layar
 * Dompet & Tabungan; menaruh tombol pengubah saldo di layar yang tugasnya
 * mencocokkan angka adalah cara termudah mengubah angka yang sedang dicocokkan.
 */
class SaldoDompetController extends Controller
{
    public function __construct(private readonly SaldoDompetService $service) {}

    public function index(Request $request): View
    {
        $f = $this->saringan($request);

        return view('saldo-dompet.index', [
            'f' => $f,
            'wali' => $this->service->wali($f),
            'santri' => $this->service->santri($f),
            'coa' => SaldoDompetService::COA,
        ]);
    }

    /** Unduh salah satu daftar. Saringannya ikut, pemenggalan halamannya tidak. */
    public function unduh(Request $request, string $lingkup)
    {
        abort_unless(in_array($lingkup, ['wali', 'santri'], true), 404);

        $rows = $this->service->untukUnduh($lingkup, $this->saringan($request));

        return Exporter::download(
            (string) $request->query('format', 'csv'),
            'saldo_dompet_'.$lingkup,
            $lingkup === 'wali' ? 'Saldo Dompet Wali' : 'Saldo Dompet & Tabungan Santri',
            $rows,
        );
    }

    /**
     * @return array{cari:string, bersaldo:bool, urut:string, arah:string}
     */
    private function saringan(Request $request): array
    {
        return [
            'cari' => trim((string) $request->query('cari', '')),
            'bersaldo' => $request->boolean('bersaldo'),
            // Nilai di luar daftar dijatuhkan ke bawaannya oleh service — di sini
            // cukup diteruskan apa adanya.
            'urut' => (string) $request->query('urut', 'saldo'),
            'arah' => (string) $request->query('arah', 'desc'),
        ];
    }
}
