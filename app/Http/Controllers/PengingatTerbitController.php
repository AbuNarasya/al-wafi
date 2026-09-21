<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BatchTagihan;
use App\Models\JadwalPengingatTerbit;
use App\Models\JenisBiaya;
use App\Models\TipeBiaya;
use App\Services\Modules\PengingatTerbitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Jadwal pengingat penerbitan — menumpang hak modul `batch-tagihan`.
 *
 * SENGAJA tidak punya modul hak sendiri: menyetel pengingat adalah bagian dari
 * mengurus batch, dan matriks hak akses di sini sudah panjang. Tiap modul baru
 * menambah satu baris yang harus dicentang manual di produksi — dan yang lupa
 * dicentang gagalnya senyap.
 */
class PengingatTerbitController extends Controller
{
    public function __construct(private readonly PengingatTerbitService $service) {}

    public function index(): View
    {
        $jadwal = JadwalPengingatTerbit::with('jenis:kode,nama')->orderBy('modul')->orderBy('id')->get();

        return view('pengingat-terbit.index', [
            'jadwal' => $jadwal,
            // Kapan masing-masing berbunyi lagi — supaya setelan yang salah
            // ketahuan di layar, bukan sebulan kemudian saat tak ada yang ditepuk.
            'berikutnya' => $jadwal->mapWithKeys(fn ($j) => [$j->id => $this->berikutnya($j)])->all(),
            'opsiModul' => BatchTagihan::MODUL,
            'opsiJenisLain' => JenisBiaya::where('status', 'aktif')
                ->whereIn('tipe', TipeBiaya::where('perilaku', 'lain')->pluck('kode'))
                ->orderBy('nama')->get(['kode', 'nama']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->simpan($request, new JadwalPengingatTerbit);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $jadwal = JadwalPengingatTerbit::find($id);
        if (! $jadwal) {
            return back()->with('error', 'Jadwal pengingat tidak ditemukan.');
        }

        return $this->simpan($request, $jadwal);
    }

    private function simpan(Request $request, JadwalPengingatTerbit $jadwal): RedirectResponse
    {
        $data = $request->validate([
            'modul' => ['required', 'string', 'in:spp,daftar_ulang,tagihan_lain'],
            'kode_jenis' => ['nullable', 'string', 'exists:jenis_biaya,kode'],
            'judul' => ['required', 'string', 'max:200'],
            'catatan' => ['nullable', 'string', 'max:255'],
            'irama' => ['required', 'string', 'in:bulanan,tahunan'],
            // 0 = hari terakhir bulan; lihat JadwalPengingatTerbit::AKHIR_BULAN.
            'tanggal' => ['required', 'integer', 'min:0', 'max:31'],
            'bulan' => ['nullable', 'integer', 'min:1', 'max:12'],
            'hari_sebelum' => ['nullable', 'integer', 'min:0', 'max:60'],
            'aktif' => ['nullable', 'boolean'],
        ]);

        // Kewajiban bersyarat ditegakkan di controller, bukan di service: service
        // dipanggil juga oleh perintah terjadwal, yang tak punya form untuk
        // menerima pesannya.
        if ($data['modul'] === 'tagihan_lain' && ($data['kode_jenis'] ?? '') === '') {
            return back()->withInput()->with('error', 'Jenis biaya wajib dipilih untuk pengingat Tagihan Lain-lain.');
        }
        if ($data['irama'] === 'tahunan' && ($data['bulan'] ?? null) === null) {
            return back()->withInput()->with('error', 'Bulan wajib dipilih untuk pengingat tahunan.');
        }

        $jadwal->fill([
            'modul' => $data['modul'],
            'kode_jenis' => $data['modul'] === 'tagihan_lain' ? $data['kode_jenis'] : null,
            'judul' => $data['judul'],
            'catatan' => $data['catatan'] ?? null,
            'irama' => $data['irama'],
            'tanggal' => $data['tanggal'],
            'bulan' => $data['irama'] === 'tahunan' ? $data['bulan'] : null,
            'hari_sebelum' => $data['hari_sebelum'] ?? 0,
            'aktif' => (bool) ($data['aktif'] ?? false),
        ])->save();

        return redirect()->route('pengingat_terbit.index')->with('status', 'Jadwal pengingat tersimpan.');
    }

    public function destroy(int $id): RedirectResponse
    {
        JadwalPengingatTerbit::where('id', $id)->delete();

        return redirect()->route('pengingat_terbit.index')->with('status', 'Jadwal pengingat dihapus.');
    }

    /**
     * "Sudah saya kerjakan" — memadamkan tugasnya untuk SEMUA penerima, bukan
     * hanya yang menekan: begitu satu orang mengerjakannya, yang lain tak perlu
     * lagi ditagih.
     */
    public function konfirmasi(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'id_jadwal' => ['required', 'integer'],
            'periode' => ['required', 'string', 'max:10'],
        ]);

        try {
            $this->service->konfirmasi($data['id_jadwal'], $data['periode'], (int) $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Pengingat ditandai sudah dikerjakan.');
    }

    /** Kapan jadwal ini berbunyi lagi, atau null bila nonaktif. */
    private function berikutnya(JadwalPengingatTerbit $jadwal): ?string
    {
        if (! $jadwal->aktif) {
            return null;
        }

        $hari = now()->startOfDay();
        $kandidat = $jadwal->irama === 'tahunan'
            ? [$hari->format('Y'), $hari->copy()->addYear()->format('Y')]
            : [$hari->format('Y-m'), $hari->copy()->addMonthNoOverflow()->format('Y-m')];

        foreach ($kandidat as $periode) {
            $tanggal = $this->service->tanggalKirim($jadwal, $periode);
            if ($tanggal->gte($hari)) {
                return $tanggal->translatedFormat('d F Y');
            }
        }

        return null;
    }
}
