<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JalurPendaftaran;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Models\Wali;
use App\Services\Modules\SantriManualService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Input Manual Santri Aktif — KHUSUS ADMIN.
 *
 * Wewenangnya `RequireAdmin`, bukan modul hak seperti menu lain. Yang dilakukan
 * di sini melompati seluruh alur PPSB: santri lahir langsung berstatus aktif,
 * tanpa pendaftaran dan tanpa tagihan registrasi, dan tagihannya boleh memilih
 * sendiri apakah menerbitkan jurnal. Itu bukan wewenang petugas kesantrian.
 */
class SantriManualController extends Controller
{
    public function __construct(private readonly SantriManualService $service) {}

    public function create(): View
    {
        // Yang ditampilkan hanya santri yang LAHIR DI SINI — bertanda LAMA- dan
        // tanpa batch impor. Daftar ini gunanya untuk menarik kembali yang baru
        // saja keliru dimasukkan, bukan untuk menelusuri seluruh santri.
        $terakhir = Santri::whereNull('id_batch')
            ->where('no_pendaftaran', 'like', 'LAMA-%')
            ->with('wali')->orderByDesc('id')->limit(20)->get();

        return view('santri-manual.create', [
            'terakhir' => $terakhir,
            'halangan' => $terakhir->mapWithKeys(fn ($s) => [$s->id => SantriManualService::halangan($s)])->all(),
            'opsiWali' => Wali::where('status', 'aktif')->orderBy('nama')->get(['id', 'nama', 'telepon'])
                ->mapWithKeys(fn ($w) => [$w->id => $w->nama.($w->telepon ? " ({$w->telepon})" : '')])->all(),
            'opsiJenjang' => Jenjang::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')
                ->pluck('nama', 'kode')->all(),
            'opsiTahunAjaran' => TahunAjaran::orderByDesc('kode')->pluck('kode')->all(),
            'opsiJalur' => JalurPendaftaran::where('status', 'aktif')->orderBy('urutan')->orderBy('kode')
                ->pluck('nama', 'kode')->all(),
            // Jenis biaya beserta keterangan akun, supaya petugas tahu sejak awal
            // mana yang siap dipakai perlakuan Saldo Awal / Akrual.
            'opsiJenisBiaya' => JenisBiaya::where('status', 'aktif')->orderBy('nama')
                ->get(['kode', 'nama', 'kode_coa_piutang', 'kode_coa_pendapatan']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nis' => ['required', 'string', 'max:50'],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', 'in:L,P'],
            'tempat_lahir' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date'],
            'nisn' => ['nullable', 'string', 'max:255'],
            'kode_jenjang' => ['required', 'string', Rule::exists('jenjang', 'kode')->where('status', 'aktif')],
            'tingkat' => ['required', 'integer', 'min:1'],
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'tahun_ajaran_berjalan' => ['nullable', 'string', 'exists:tahun_ajaran,kode'],
            'jalur' => ['required', 'string', 'exists:jalur_pendaftaran,kode'],

            // Wali: pilih yang sudah ada, ATAU isi wali baru. Salah satunya wajib,
            // dan itu ditegakkan di sini — bukan di service, yang juga dipakai test.
            //
            // Isiannya BERPERAN (`nama_ayah`, `telepon_ibu`, …), mengikuti kontrak
            // WaliService: `nama` & `telepon` wali TIDAK diisi langsung, melainkan
            // disalin dari peran yang ditunjuk `kontak_utama`. Menyediakan isian
            // `nama` polos di sini akan lolos validasi lalu ditolak service dengan
            // pesan yang membingungkan.
            'id_wali' => ['nullable', 'integer', 'exists:wali,id', 'required_without:wali_baru'],
            'wali_baru' => ['nullable', 'array', 'required_without:id_wali'],
            'wali_baru.kontak_utama' => ['required_with:wali_baru', 'in:ayah,ibu,wali'],
            'wali_baru.alamat' => ['nullable', 'string'],
            'wali_baru.nama_ayah' => ['nullable', 'string', 'max:255'],
            'wali_baru.telepon_ayah' => ['nullable', 'string', 'max:255'],
            'wali_baru.nama_ibu' => ['nullable', 'string', 'max:255'],
            'wali_baru.telepon_ibu' => ['nullable', 'string', 'max:255'],
            'wali_baru.nama_wali' => ['nullable', 'string', 'max:255'],
            'wali_baru.telepon_wali' => ['nullable', 'string', 'max:255'],

            'tagihan' => ['nullable', 'array'],
            'tagihan.*.kode_jenis' => ['required', 'string', 'exists:jenis_biaya,kode'],
            'tagihan.*.nominal' => ['required', 'numeric', 'gt:0'],
            'tagihan.*.posting' => ['required', Rule::in(SantriManualService::POSTING)],
            'tagihan.*.tahun_ajaran' => ['nullable', 'string', 'exists:tahun_ajaran,kode'],
            'tagihan.*.jatuh_tempo' => ['nullable', 'date'],
            'tagihan.*.keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        // Baris kosong yang tertinggal dari tombol "+ baris" tidak dianggap
        // kekeliruan — cukup diabaikan.
        $tagihan = array_values(array_filter(
            $data['tagihan'] ?? [],
            fn ($b) => ($b['kode_jenis'] ?? '') !== '' && ($b['nominal'] ?? '') !== '',
        ));

        if (empty($data['id_wali'])) {
            $data['wali_baru'] = ($data['wali_baru'] ?? []) + ['status' => 'aktif'];
        }

        try {
            $santri = $this->service->buat($data, $tagihan, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $pesan = "Santri {$santri->nama} ({$santri->no_pendaftaran}) dicatat sebagai santri AKTIF";
        $pesan .= $tagihan === [] ? ' tanpa tagihan.' : ' beserta '.count($tagihan).' tagihan.';

        return redirect()->route('santri.show', $santri->id)->with('status', $pesan);
    }

    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->service->hapus($id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Santri beserta tagihannya dihapus. Tak ada jurnal yang dibalik — memang tak pernah ada.');
    }
}
