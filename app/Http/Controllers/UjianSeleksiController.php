<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Gelombang;
use App\Models\JadwalUjian;
use App\Models\Jenjang;
use App\Models\KehadiranUjian;
use App\Models\Santri;
use App\Models\TahunAjaran;
use App\Services\Ppsb\UjianSeleksi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Ujian seleksi PSB (staf): jadwal, kartu peserta, tanggal khusus peserta, absensi QR, daftar hadir — 21 Okt 2026. */
class UjianSeleksiController extends Controller
{
    // ---- Jadwal ----

    public function jadwal(Request $request): View
    {
        $ta = TahunAjaran::orderByDesc('kode')->pluck('kode');
        $pilih = $request->query('ta') ?: $ta->first();

        return view('ujian.jadwal', [
            'ta' => $ta, 'pilih' => $pilih,
            'jadwal' => JadwalUjian::with('jenjang:kode,nama')->where('tahun_ajaran', $pilih)->orderBy('tanggal')->get(),
            'jenjang' => Jenjang::orderBy('urutan')->pluck('nama', 'kode'),
            'gelombang' => Gelombang::where('tahun_ajaran', $pilih)->orderBy('kode')->pluck('nama', 'kode'),
            'pengaturan' => \App\Models\CompanySettings::find(1),
            'perhatianBawaan' => UjianSeleksi::PERHATIAN_BAWAAN,
        ]);
    }

    public function simpanJadwal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['nullable', 'exists:jenjang,kode'],
            'kode_gelombang' => ['nullable', 'string', 'max:50'],
            'tanggal' => ['required', 'date'],
            'jam' => ['nullable', 'string', 'max:50'],
            'tempat' => ['nullable', 'string', 'max:255'],
        ]);
        $kembar = JadwalUjian::where('tahun_ajaran', $data['tahun_ajaran'])
            ->where('kode_jenjang', $data['kode_jenjang'] ?? null)->where('kode_gelombang', $data['kode_gelombang'] ?? null)->exists();
        if ($kembar) {
            return back()->withInput()->with('error', 'Jadwal untuk cakupan jenjang & gelombang ini sudah ada — hapus atau ubah yang lama.');
        }
        JadwalUjian::create($data);

        return redirect()->route('ujian.jadwal', ['ta' => $data['tahun_ajaran']])->with('status', 'Jadwal ujian disimpan.');
    }

    /** Isi kartu yang bisa diatur: jumlah orang di kupon makan & daftar PERHATIAN. */
    public function pengaturanKartu(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kupon_makan_orang' => ['required', 'integer', 'min:1', 'max:20'],
            'perhatian_kartu_ujian' => ['nullable', 'string', 'max:3000'],
        ]);
        \App\Models\CompanySettings::query()->whereKey(1)->update($data);

        return back()->with('status', 'Pengaturan kartu peserta disimpan.');
    }

    public function hapusJadwal(JadwalUjian $jadwal): RedirectResponse
    {
        $jadwal->delete();

        return back()->with('status', 'Jadwal ujian dihapus.');
    }

    // ---- Kartu & tanggal khusus peserta ----

    public function kartu(int $id): View
    {
        $s = Santri::findOrFail($id);
        abort_unless(UjianSeleksi::bolehKartu($s), 403, 'Kartu peserta tersedia sejak registrasi lunas sampai seleksi selesai.');

        return view('ujian.kartu', ['k' => UjianSeleksi::dataKartu($s), 'kembali' => route('santri.show', $s->id)]);
    }

    public function tanggalPeserta(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['tanggal_ujian' => ['nullable', 'date']]);
        Santri::findOrFail($id)->update(['tanggal_ujian' => $data['tanggal_ujian'] ?: null]);

        return back()->with('status', $data['tanggal_ujian'] ? 'Tanggal ujian peserta disimpan.' : 'Tanggal ujian peserta dikosongkan (kembali mengikuti jadwal).');
    }

    // ---- Absensi ----

    /** Dibuka dari QR kartu (tautan bertanda tangan): mencatat hadir lalu menampilkan pesertanya. */
    public function hadir(Request $request, Santri $santri): View
    {
        try {
            [$k, $baru] = UjianSeleksi::catatHadir($santri, $request->user()->id_pengguna);
            $galat = null;
        } catch (AppException $e) {
            [$k, $baru, $galat] = [null, false, $e->getMessage()];
        }

        return view('ujian.hadir', ['data' => UjianSeleksi::dataKartu($santri), 'hadir' => $k?->load('pencatat'), 'baru' => $baru, 'galat' => $galat]);
    }

    public function daftarHadir(Request $request): View
    {
        $ta = TahunAjaran::orderByDesc('kode')->pluck('kode');
        $pilih = $request->query('ta') ?: $ta->first();
        $jenjang = $request->query('jenjang');

        $hadir = KehadiranUjian::with('pencatat:id_pengguna,nama')->where('tahun_ajaran', $pilih)->get()->keyBy('id_santri');
        $peserta = Santri::with('jenjang:kode,nama')->where('tahun_ajaran', $pilih)
            ->where(fn ($q) => $q->whereIn('status', UjianSeleksi::STATUS_KARTU)->orWhereIn('id', $hadir->keys()))
            ->when($jenjang, fn ($q) => $q->where('kode_jenjang', $jenjang))
            ->orderBy('kode_jenjang')->orderBy('jenis_kelamin')->orderBy('nama')->get();

        return view('ujian.daftar-hadir', [
            'ta' => $ta, 'pilih' => $pilih, 'jenjangPilih' => $jenjang, 'peserta' => $peserta, 'hadir' => $hadir,
            'jenjang' => Jenjang::orderBy('urutan')->pluck('nama', 'kode'),
        ]);
    }
}
