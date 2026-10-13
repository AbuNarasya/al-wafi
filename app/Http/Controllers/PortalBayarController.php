<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\DompetWali;
use App\Services\PortalWali\PembayaranPortal;
use App\Services\PortalWali\SesiWali;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * PORTAL WALI — pembayaran. Controller tipis → PembayaranPortal (penjaga) →
 * BayarDompet / DompetService / PembayaranSantriService (aturan uang).
 *
 * Bukti transfer disimpan di tempat yang SAMA dengan yang diunggah staf, supaya
 * keuangan memverifikasinya dari layar yang sudah mereka pakai.
 */
class PortalBayarController extends Controller
{
    private function portal(Request $request): PembayaranPortal
    {
        return PembayaranPortal::dari(SesiWali::wali(), (string) SesiWali::peran($request));
    }

    private function rekening(): array
    {
        $lembaga = DB::table('company_settings')->value('nama_perusahaan') ?: 'AL Wafi Islamic Boarding School';

        return BankAccount::untukPortal()->map(fn (BankAccount $r) => [
            'kode' => $r->kode_coa, 'bank' => $r->nama_bank, 'nomor' => $r->no_rekening,
            'atas_nama' => $r->atas_nama ?: $lembaga,
        ])->all();
    }

    // ---- Bayar satu tagihan ----

    public function form(Request $request, int $id): View|RedirectResponse
    {
        try {
            $t = $this->portal($request)->tagihan($id);
        } catch (AppException) {
            return redirect()->route('portal.tagihan')->with('error', 'Tagihan tidak ditemukan.');
        }
        [$alur, $alasan] = PembayaranPortal::alur($t);
        if ($alur === null) {
            // Yang paling sering sampai ke sini: tombol kembali sesudah membayar. Itu
            // bukan galat — sampaikan sebagai kabar biasa.
            return redirect()->route('portal.tagihan', ['anak' => $t->id_santri])
                ->with($t->status === 'lunas' ? 'status' : 'error', $t->status === 'lunas' ? 'Tagihan ini sudah lunas.' : $alasan);
        }

        return view('portal.bayar', [
            'tagihan' => $t, 'alur' => $alur, 'ruang' => PembayaranPortal::ruang($t),
            'penuh' => PembayaranPortal::bisaDompet($alur) && in_array($t->perilaku, PembayaranPortal::PENUH, true),
            'saldo' => DompetWali::where('id_wali', SesiWali::wali()->id)->value('saldo') ?? '0',
            'rekening' => PembayaranPortal::bisaTransfer($alur) ? $this->rekening() : [],
        ]);
    }

    public function dariDompet(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['nominal' => ['required', 'numeric', 'gt:0'], 'pin' => ['nullable', 'string']]);

        try {
            $p = $this->portal($request)->bayarDariDompet($id, (string) $data['nominal'], $data['pin'] ?? null);
        } catch (AppException $e) {
            return PortalDompetController::tanganiGalat($e, $request);
        }

        return redirect()->route('portal.tagihan', ['anak' => $p->id_santri])
            ->with('status', 'Pembayaran Rp '.number_format((float) $p->nominal, 0, ',', '.').' dari Dompet Wali berhasil.');
    }

    public function transfer(Request $request, int $id): RedirectResponse
    {
        $data = $this->validasiTransfer($request);
        $data['bukti_path'] = $request->file('bukti')->store('pembayaran-bukti', 'local');

        try {
            $p = $this->portal($request)->bayarTransfer($id, $data);
        } catch (AppException $e) {
            Storage::disk('local')->delete($data['bukti_path']);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.tagihan', ['anak' => $p->id_santri])
            ->with('status', 'Bukti transfer terkirim. Keuangan akan memeriksanya; statusnya berubah setelah diverifikasi.');
    }

    // ---- Isi dompet ----

    public function formIsi(): View|RedirectResponse
    {
        if (! PortalKeluargaController::punyaDompet()) {
            return redirect()->route('portal.beranda')->with('error', 'Dompet aktif setelah anak Anda berstatus santri aktif.');
        }

        return view('portal.isi-dompet', ['rekening' => $this->rekening()]);
    }

    public function isi(Request $request): RedirectResponse
    {
        if (! PortalKeluargaController::punyaDompet()) {
            return redirect()->route('portal.beranda');
        }
        $data = $this->validasiTransfer($request);
        $data['bukti_path'] = $request->file('bukti')->store('dompet-bukti', 'local');

        try {
            $this->portal($request)->isiDompet($data);
        } catch (AppException $e) {
            Storage::disk('local')->delete($data['bukti_path']);

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('portal.dompet')
            ->with('status', 'Bukti transfer terkirim. Saldo bertambah setelah keuangan memverifikasinya.');
    }

    // ---- Auto-debet ----

    public function autoDebet(Request $request): RedirectResponse
    {
        $aktif = $request->boolean('aktif');
        try {
            $this->portal($request)->setAutoDebet($aktif, $request->input('pin'));
        } catch (AppException $e) {
            return PortalDompetController::tanganiGalat($e, $request);
        }

        return redirect()->route('portal.dompet')->with('status', $aktif
            ? 'Auto-debet aktif. Tagihan santri aktif dibayar otomatis dari Dompet Wali bila saldonya cukup.'
            : 'Auto-debet dimatikan. Tagihan dibayar manual dari halaman Tagihan.');
    }

    private function validasiTransfer(Request $request): array
    {
        return $request->validate([
            'kode_rekening' => ['required', 'string'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'tanggal' => ['required', 'date', 'before_or_equal:today'],
            'bukti' => Unggahan::aturan(),
        ], [
            'bukti.required' => 'Unggah foto atau PDF bukti transfer.',
            'bukti.max' => 'Berkas bukti terlalu besar (maksimal '.Unggahan::maksLabel().').',
            'tanggal.before_or_equal' => 'Tanggal transfer tidak boleh di masa depan.',
        ]);
    }
}
