<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\CoaDetail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * MATRIKS KLASIFIKASI ARUS KAS — seluruh akun neraca dalam satu layar.
 *
 * Kolom `klasifikasi_arus_kas` sudah lama bisa disunting, tetapi hanya satu per
 * satu lewat form akun. Empat puluh akun berarti empat puluh kali buka–pilih–
 * simpan, dan pekerjaan sebesar itu berakhir seperti yang sudah-sudah: tak
 * pernah dikerjakan, lalu Laporan Arus Kas selamanya menampilkan kelompok
 * "Belum Diklasifikasikan" yang lebih besar daripada tiga kelompok lainnya.
 *
 * Hanya akun NERACA yang ditampilkan (Aset, Liabilitas, Ekuitas). Pendapatan &
 * Beban tak pernah kosong: keduanya lahir bertanda `operasi` dari model, dan
 * memang tak punya pilihan lain yang masuk akal.
 *
 * TANPA modul hak akses baru — menumpang `coa-detail`, karena yang disunting
 * memang kolom pada akun. Modul baru berarti satu kotak lagi yang harus
 * dicentangi admin sebelum layar ini bisa dipakai siapa pun.
 */
class KlasifikasiArusKasController extends Controller
{
    /** Kelompok akun yang muncul di Neraca — hanya ini yang perlu diklasifikasi. */
    private const AKAR_NERACA = ['1', '2', '3'];

    public function index(): View
    {
        $akun = CoaDetail::with('grup')->orderBy('kode_coa')->get()
            ->filter(fn ($a) => in_array(CoaDetail::akarKelompok($a->kode_grup), self::AKAR_NERACA, true));

        // Rekening kas sengaja DITANDAI, bukan disembunyikan. Laporan Arus Kas
        // menjelaskan perubahan saldo akun-akun inilah, jadi mengklasifikasikan
        // kas berarti menghitungnya dua kali — tetapi menghilangkannya dari
        // daftar hanya akan membuat orang mencarinya dan mengira ada yang rusak.
        $kas = BankAccount::pluck('kode_coa')->flip();

        return view('coa-detail.klasifikasi', [
            'akun' => $akun->groupBy(fn ($a) => CoaDetail::akarKelompok($a->kode_grup)),
            'kas' => $kas,
            'belum' => $akun->whereNull('klasifikasi_arus_kas')->count(),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'klasifikasi' => ['array'],
            'klasifikasi.*' => ['nullable', Rule::in(['operasi', 'investasi', 'pendanaan'])],
        ]);

        // Hanya akun neraca yang boleh disentuh dari sini. Kiriman bisa disusun
        // sendiri, dan menulis klasifikasi ke akun Pendapatan lewat pintu ini
        // akan memindahkannya diam-diam di Laporan Arus Kas.
        $boleh = CoaDetail::all(['kode_coa', 'kode_grup'])
            ->filter(fn ($a) => in_array(CoaDetail::akarKelompok($a->kode_grup), self::AKAR_NERACA, true))
            ->pluck('kode_coa')->flip();

        $berubah = 0;
        DB::transaction(function () use ($data, $boleh, &$berubah) {
            foreach ($data['klasifikasi'] ?? [] as $kode => $nilai) {
                if (! $boleh->has($kode)) {
                    continue;
                }
                $akun = CoaDetail::find($kode);
                // Perbandingan longgar tak dipakai: '' dari dropdown dan null di
                // basis data sama-sama berarti "belum ditentukan", dan tanpa
                // penyeragaman ini setiap simpan akan menulis ulang semua baris.
                $baru = ($nilai ?: null);
                if (! $akun || $akun->klasifikasi_arus_kas === $baru) {
                    continue;
                }
                $akun->update(['klasifikasi_arus_kas' => $baru]);
                $berubah++;
            }
        });

        return redirect()->route('coa.klasifikasi.index')
            ->with('status', $berubah === 0
                ? 'Tidak ada perubahan.'
                : "{$berubah} akun diperbarui klasifikasi arus kasnya.");
    }
}
