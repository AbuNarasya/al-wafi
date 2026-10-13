<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Services\Midtrans\PembayaranMidtrans;
use App\Services\PortalWali\PembayaranPortal;
use App\Services\PortalWali\SesiWali;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * PORTAL WALI — bayar beberapa tagihan sekaligus (keputusan user 13 Okt 2026).
 * Controller tipis → PembayaranPortal::keranjang() (penjaga) & tiga cara bayarnya.
 * Isian `nominal[id_tagihan]`; cara bayar yang ditawarkan = yang sah untuk SEMUA isinya.
 */
class PortalBayarBanyakController extends Controller
{
    private function portal(Request $request): PembayaranPortal
    {
        return PembayaranPortal::dari(SesiWali::wali(), (string) SesiWali::peran($request));
    }

    /** @return array<int,string> [id_tagihan => nominal] */
    private function isian(Request $request): array
    {
        $data = $request->validate(['nominal' => ['required', 'array', 'min:1', 'max:'.PembayaranPortal::MAKS_KERANJANG],
            'nominal.*' => ['required', 'integer', 'min:1']], ['nominal.required' => 'Pilih minimal satu tagihan.']);

        return collect($data['nominal'])->mapWithKeys(fn ($n, $id) => [(int) $id => (string) $n])->all();
    }

    /** ?t[]=1&t[]=2 — nominal awal = sisa yang belum tertutup setoran lain. */
    public function form(Request $request): View|RedirectResponse
    {
        $ids = collect((array) $request->query('t'))->map(fn ($i) => (int) $i)->filter()->unique()->values();
        try {
            $isian = $ids->mapWithKeys(fn ($id) => [$id => PembayaranPortal::ruang($this->portal($request)->tagihan($id))])->all();
            $k = $this->portal($request)->keranjang($isian);
        } catch (AppException $e) {
            return redirect()->route('portal.tagihan')->with('error', $e->getMessage());
        }

        $lembaga = DB::table('company_settings')->value('nama_perusahaan') ?: 'AL Wafi Islamic Boarding School';

        return view('portal.bayar-banyak', $k + [
            'saldo' => DompetWali::where('id_wali', SesiWali::wali()->id)->value('saldo') ?? '0',
            'biaya' => PembayaranMidtrans::biaya(),
            'rekening' => in_array('transfer', $k['cara'], true) ? BankAccount::untukPortal()->map(fn (BankAccount $r) => [
                'kode' => $r->kode_coa, 'bank' => $r->nama_bank, 'nomor' => $r->no_rekening, 'atas_nama' => $r->atas_nama ?: $lembaga,
            ])->all() : [],
        ]);
    }

    public function dompet(Request $request): RedirectResponse
    {
        $isian = $this->isian($request);
        try {
            $hasil = $this->portal($request)->bayarKelompokDariDompet($isian, $request->input('pin'));
        } catch (AppException $e) {
            return PortalDompetController::tanganiGalat($e, $request);
        }

        return redirect()->route('portal.tagihan')->with('status', $hasil->count().' tagihan dibayar dari Dompet Wali, total Rp '
            .number_format((float) $hasil->sum(fn ($p) => (float) $p->nominal), 0, ',', '.').'.');
    }

    public function transfer(Request $request): RedirectResponse
    {
        $isian = $this->isian($request);
        $data = $request->validate([
            'kode_rekening' => ['required', 'string'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'bukti' => Unggahan::aturan(),
        ], [
            'bukti.required' => 'Unggah foto atau PDF bukti transfer.',
            'bukti.max' => 'Berkas bukti terlalu besar (maksimal '.Unggahan::maksLabel().').',
            'tanggal.before_or_equal' => 'Tanggal transfer tidak boleh di masa depan.',
        ]);
        $data['bukti_path'] = $request->file('bukti')->store('pembayaran-bukti', 'local');

        try {
            $baris = $this->portal($request)->bayarKelompokTransfer($isian, $data);
        } catch (AppException $e) {
            Storage::disk('local')->delete($data['bukti_path']);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.tagihan')->with('status', 'Bukti transfer untuk '.$baris->count()
            .' tagihan terkirim. Keuangan akan memeriksanya; statusnya berubah setelah diverifikasi.');
    }

    public function online(Request $request, PembayaranMidtrans $midtrans): RedirectResponse
    {
        $isian = $this->isian($request);
        try {
            $t = $midtrans->mulaiBayarKelompok(SesiWali::wali(),
                ['id' => SesiWali::wali()->id, 'peran' => (string) SesiWali::peran($request)], $isian);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.online.lihat', $t->order_id);
    }
}
