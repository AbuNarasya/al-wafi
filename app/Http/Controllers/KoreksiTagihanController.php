<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Modules\KoreksiTagihanService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * KOREKSI NOMINAL TAGIHAN SANTRI.
 *
 * Wewenangnya sengaja berdiri sendiri (`koreksi-tagihan`), bukan menumpang
 * `santri,ubah`: aksi ini mengubah PIUTANG YANG SUDAH DIBUKUKAN dan menerbitkan
 * jurnal penyesuaian. Yang boleh menyunting nama dan alamat santri belum tentu
 * boleh menggeser angka di buku besar.
 */
class KoreksiTagihanController extends Controller
{
    public function __construct(private readonly KoreksiTagihanService $service) {}

    public function koreksi(Request $request, int $id): RedirectResponse
    {
        // `nominal_baru`, bukan `nominal`: di halaman santri `name="nominal"`
        // sudah berarti isian uang pangkal, dan ketiadaannya dipakai sebagai
        // bukti bahwa jalur bebas uang pangkal memang tak menawarkannya.
        $data = $request->validate([
            // `min:0`, bukan `gt:0`: nol berarti penghapusan penuh — jurnal
            // penyesuaiannya tetap terbit dan yang terlanjur dibayar pindah ke
            // Dompet Wali. Yang mustahil hanyalah nominal negatif.
            'nominal_baru' => ['required', 'numeric', 'min:0'],
            'alasan' => ['required', 'string', 'max:255'],
        ]);

        try {
            $hasil = $this->service->koreksi(
                $id,
                (string) $data['nominal_baru'],
                $data['alasan'],
                $request->user()->id_pengguna,
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = 'Nominal tagihan dikoreksi menjadi '.Money::of($hasil['koreksi']->nominal_baru).'.';

        // Kelebihan bayar & jadwal yang gugur DISEBUTKAN, bukan dibiarkan
        // ditemukan sendiri: keduanya akibat yang tak diminta petugas secara
        // langsung, dan yang paling mudah luput justru yang paling penting.
        if (Money::gtZero((string) $hasil['koreksi']->kelebihan_ke_dompet)) {
            $pesan .= ' Kelebihan bayar '.Money::of($hasil['koreksi']->kelebihan_ke_dompet)
                .' dipindahkan ke Dompet Wali sebagai titipan.';
        }
        if ($hasil['jadwal_dibatalkan']) {
            $pesan .= ' Jadwal angsurannya digugurkan — susun ulang bersama walinya.';
        }

        return back()->with('status', $pesan);
    }
}
