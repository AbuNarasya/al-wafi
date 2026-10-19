<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Santri;
use App\Services\Modules\KartuSantriService;
use App\Services\PortalWali\PinTransaksi;
use App\Services\PortalWali\SesiWali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Portal wali — KARTU BELANJA WAFI MART per anak: batas harian, PIN belanja anak, blokir
 * kartu hilang. Ketiganya dengan PIN WALI (papan PIN portal).
 *
 * SENGAJA di bawah /wali/anak/…, BUKAN /wali/data: sesi mode verifikasi (pemegang link WA
 * yang belum membuktikan email) hanya diloloskan ke /wali/data* — lihat CLAUDE.md.
 */
class PortalKartuController extends Controller
{
    public function __construct(private readonly KartuSantriService $kartu) {}

    public function batas(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['batas_harian' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'pin' => ['nullable', 'string']]);

        return $this->jalankan($request, $id, function (Santri $s) use ($data) {
            $this->kartu->aturBatas($s, $data['batas_harian'] ?? null);

            return ($data['batas_harian'] ?? null) === null
                ? "Batas belanja {$s->nama} kembali ke batas bawaan."
                : "Batas belanja harian {$s->nama} disimpan.";
        });
    }

    public function pinAnak(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'pin_anak' => ['required', 'string'], 'pin_anak_ulang' => ['required', 'string'], 'pin' => ['nullable', 'string'],
        ]);

        return $this->jalankan($request, $id, function (Santri $s) use ($data, $request) {
            $this->kartu->pasangPin($s, $data['pin_anak'], $data['pin_anak_ulang'], SesiWali::wali()->id, (string) SesiWali::peran($request));

            return "PIN belanja {$s->nama} tersimpan. Beri tahu anak Anda — PIN diminta bila belanja melewati batas harian.";
        });
    }

    public function blokir(Request $request, int $id): RedirectResponse
    {
        $request->validate(['pin' => ['nullable', 'string']]);

        return $this->jalankan($request, $id, function (Santri $s) {
            $k = $s->kartuAktif ?? throw new AppException(422, "{$s->nama} tidak punya kartu aktif.");
            $this->kartu->blokir($k, 'Diblokir wali (kartu hilang)', null, SesiWali::wali()->id);

            return "Kartu {$s->nama} diblokir — tak bisa dipakai belanja. Kartu baru dipasang di kantor.";
        });
    }

    /** Anak milik keluarga ini & aktif, PIN wali benar, lalu aksi. */
    private function jalankan(Request $request, int $id, \Closure $aksi): RedirectResponse
    {
        $wali = SesiWali::wali();
        $s = Santri::with('kartuAktif')->where('id_wali', $wali->id)->findOrFail($id);

        try {
            if ($s->status !== 'aktif') {
                throw new AppException(422, 'Kartu belanja hanya untuk santri aktif.');
            }
            (new PinTransaksi($wali, (string) SesiWali::peran($request)))->periksa($request->input('pin'));
            $pesan = $aksi($s);
        } catch (AppException $e) {
            if ($e->status === 428) {
                return PortalDompetController::tanganiGalat($e, $request);
            }

            // PIN wali MAUPUN PIN anak tak boleh ikut tersimpan sebagai isian lama di sesi.
            return back()->withInput($request->except(['pin', 'pin_anak', 'pin_anak_ulang']))->with('error', $e->getMessage());
        }

        return redirect()->route('portal.anak', $s->id)->with('status', $pesan);
    }
}
