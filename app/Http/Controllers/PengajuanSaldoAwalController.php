<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\PengajuanPembayaran;
use App\Services\Modules\PengajuanSaldoAwalService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pintu manual "Pengajuan Belum Dibayar" — saldo awal hutang yang sudah
 * disetujui di pembukuan lama tetapi belum dicairkan.
 *
 * Wewenangnya menumpang `impor-data-awal`, BUKAN `pengajuan-pembayaran`. Yang
 * dikerjakan di sini adalah pemindahan keadaan, bukan pengajuan; dan yang
 * dilahirkannya melompati seluruh rantai persetujuan, jadi ia tak boleh jatuh ke
 * tangan setiap orang yang boleh mengajukan pembayaran.
 */
class PengajuanSaldoAwalController extends Controller
{
    public function __construct(private readonly PengajuanSaldoAwalService $service) {}

    public function index(): View
    {
        $rows = PengajuanPembayaran::where('saldo_awal', true)
            ->orderByDesc('tanggal')->orderByDesc('id')->get();

        return view('pengajuan-saldo-awal.index', [
            'rows' => $rows,
            'halangan' => $rows->mapWithKeys(fn ($r) => [$r->id => PengajuanSaldoAwalService::halangan($r)])->all(),
            'bagianOptions' => ['' => '— pilih —'] + Bagian::where('status', 'aktif')->orderBy('nama_bagian')->get()
                ->mapWithKeys(fn ($b) => [$b->kode_bagian => $b->nama_bagian])->all(),
            'unitOptions' => ['' => '— pilih —'] + BusinessUnit::where('status', 'aktif')->orderBy('kode_unit')->get()
                ->mapWithKeys(fn ($u) => [$u->kode_unit => $u->nama_unit])->all(),
            'coaOptions' => ['' => '— pilih —'] + CoaDetail::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nomor' => ['required', 'string', 'max:50'],
            'tanggal' => ['required', 'date'],
            'kode_bagian' => ['required', 'string', 'exists:bagian,kode_bagian'],
            'kode_coa_hutang' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_coa_beban' => ['required', 'string', 'exists:coa_detail,kode_coa'],
            'kode_unit' => ['required', 'string', 'exists:business_units,kode_unit'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'keterangan' => ['required', 'string', 'max:255'],
        ]);

        try {
            $rec = $this->service->tambah($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', "Hutang saldo awal {$rec->nomor} sebesar ".Money::of($rec->nominal)
            .' dicatat. Tidak ada jurnal yang terbit: angkanya masuk buku besar lewat baris turunan di menu Saldo Awal. '
            .'Dokumen ini SUDAH bisa dicairkan lewat Kas Keluar.');
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->hapus($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Hutang saldo awal dihapus. Tak ada jurnal yang dibalik — memang tak pernah ada.');
    }
}
