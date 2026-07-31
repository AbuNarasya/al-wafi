<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JadwalPerubahanSantri;
use App\Models\Jenjang;
use App\Models\TahunAjaran;
use App\Services\Modules\KenaikanTingkatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Kenaikan Tingkat & Kelulusan massal — dalam satu jenjang, serentak satu angkatan.
 *
 * Pratinjau dulu, penetapan kemudian, dengan tombol yang berbeda: memuat ulang
 * halaman tak boleh menetapkan apa pun.
 *
 * Yang ditekan petugas MENJADWALKAN, bukan mengubah. Perubahannya menyala saat
 * tahun ajaran tujuan benar-benar dimulai — lihat KenaikanTingkatService::tetapkan().
 */
class KenaikanTingkatController extends Controller
{
    public function __construct(private readonly KenaikanTingkatService $service) {}

    public function index(): View
    {
        return view('kenaikan-tingkat.index', $this->opsi() + ['filter' => [], 'hasil' => null]);
    }

    public function pratinjau(Request $request): View|RedirectResponse
    {
        $filter = $request->validate([
            // Tahun ajaran TUJUAN — tahun yang akan dijalani sesudah naik.
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            'tingkat' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $hasil = $this->service->pratinjau($filter);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return view('kenaikan-tingkat.index', $this->opsi() + ['filter' => $filter, 'hasil' => $hasil]);
    }

    /** Menetapkan perubahan — tidak mengubah santri sekarang juga. */
    public function tetapkan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'tanggal_lulus' => ['nullable', 'date'],
            'keputusan' => ['required', 'array'],
            'keputusan.*' => ['string', 'in:'.implode(',', array_keys(KenaikanTingkatService::KEPUTUSAN))],
        ]);

        try {
            $hasil = $this->service->tetapkan(
                $data['tahun_ajaran'],
                $data['keputusan'],
                (int) $request->user()->id_pengguna,
                ['tanggal_lulus' => $data['tanggal_lulus'] ?? null],
            );
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Kapan berlakunya disebut TERUS TERANG. Tanpa itu petugas mengira
        // perubahannya sudah jalan, lalu bingung melihat tingkat santri tak
        // berubah di daftar.
        $mulai = $hasil['berlaku_mulai']
            ? ' Berlaku mulai '.\Illuminate\Support\Carbon::parse($hasil['berlaku_mulai'])->format('d/m/Y').'.'
            : '';
        $menunggu = $hasil['melanjutkan'] > 0
            ? " {$hasil['melanjutkan']} santri yang melanjutkan menunggu proses PPSB-nya tuntas lebih dulu."
            : '';

        return redirect()->route('kenaikan_tingkat.index')->with('status',
            "Perubahan T.A {$data['tahun_ajaran']} ditetapkan: {$hasil['naik']} naik tingkat, "
            ."{$hasil['mengulang']} mengulang, {$hasil['melanjutkan']} melanjutkan, "
            ."{$hasil['lulus']} lulus.{$mulai}{$menunggu}"
        );
    }

    private function opsi(): array
    {
        // Jadwal yang sudah jatuh tempo dinyalakan di sini juga, bukan hanya oleh
        // penjadwal harian: produksi berjalan di paket gratis yang tidur, jadi
        // cron bisa tak pernah menyala. Murah karena hanya menyentuh baris yang
        // benar-benar jatuh tempo (indeks status + tahun ajaran).
        $this->service->terapkanYangJatuhTempo();

        return [
            'opsiTa' => TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all(),
            'opsiJenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all(),
            'opsiKeputusan' => KenaikanTingkatService::KEPUTUSAN,
            // Daftar kerja: apa yang sudah ditetapkan tapi belum menyala.
            'terjadwal' => JadwalPerubahanSantri::hidup()
                ->with(['santri:id,nama,nis,no_pendaftaran,kode_jenjang,tingkat', 'jenjangTujuan:kode,nama', 'pendaftaran:id,nomor,status'])
                ->orderBy('tahun_ajaran')->orderBy('status')->get(),
        ];
    }
}
