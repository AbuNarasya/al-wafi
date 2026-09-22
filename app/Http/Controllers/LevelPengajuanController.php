<?php

namespace App\Http\Controllers;

use App\Models\ApprovalStep;
use App\Models\LevelPengajuan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Level Pengajuan — anak tangga rantai persetujuan. PERINGKAT hanyalah urutan
 * (1 = tertinggi); wewenangnya ada pada empat tanda peran (LevelPengajuan::PERAN).
 *
 * Dulu tabel ini terkunci pada empat baris — tanpa Tambah maupun Hapus — karena
 * angkanya dipakai sebagai aturan di selusin tempat. Sejak aturan itu pindah ke
 * tanda peran, jumlah levelnya jadi urusan pesantren.
 *
 * Yang masih dijaga: peringkat tak bisa diubah (dirujuk `users` & `approval_steps`
 * sebagai kunci asing), dan level yang masih dipakai tak bisa dihapus.
 */
class LevelPengajuanController extends Controller
{
    public function index(): View
    {
        $rows = LevelPengajuan::withCount('users')->orderBy('peringkat')->get();

        // Berapa tahap rantai yang memakai tiap peringkat — penghalang penghapusan
        // yang harus terlihat SEBELUM tombolnya ditekan.
        $tahap = ApprovalStep::whereNotNull('peringkat')
            ->selectRaw('peringkat, count(*) as jml')->groupBy('peringkat')
            ->pluck('jml', 'peringkat')->all();

        return view('level-pengajuan.index', compact('rows', 'tahap'));
    }

    public function create(): View
    {
        // Peringkat berikutnya ditawarkan, bukan dipaksakan: menyisipkan level di
        // TENGAH susunan tetap sah selama angkanya belum dipakai.
        $baru = new LevelPengajuan([
            'peringkat' => (int) LevelPengajuan::max('peringkat') + 1,
            'status' => 'aktif',
        ]);

        return view('level-pengajuan.form', ['row' => $baru, 'baru' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'peringkat' => ['required', 'integer', 'min:1', Rule::unique('level_pengajuan', 'peringkat')],
        ] + $this->aturanIsi());

        LevelPengajuan::create($this->bacaPeran($request, $data));

        return redirect()->route('level_pengajuan.index')
            ->with('status', "Level pengajuan \"{$data['nama']}\" ditambahkan pada peringkat {$data['peringkat']}.");
    }

    public function edit(LevelPengajuan $level_pengajuan): View
    {
        return view('level-pengajuan.form', ['row' => $level_pengajuan, 'baru' => false]);
    }

    public function update(Request $request, LevelPengajuan $level_pengajuan): RedirectResponse
    {
        $data = $request->validate($this->aturanIsi());
        $isi = $this->bacaPeran($request, $data);

        // Mematikan satu-satunya pintu masuk pengajuan pembayaran akan membuat
        // seluruh modulnya buntu tanpa satu pun pesan yang menjelaskan sebabnya.
        if ($pesan = $this->melumpuhkanPemohon($level_pengajuan, $isi)) {
            return back()->withInput()->with('error', $pesan);
        }

        $level_pengajuan->update($isi);

        return redirect()->route('level_pengajuan.index')->with('status', 'Level pengajuan berhasil diperbarui.');
    }

    public function destroy(LevelPengajuan $level_pengajuan): RedirectResponse
    {
        $halangan = [];
        if ($jml = $level_pengajuan->users()->count()) {
            $halangan[] = "{$jml} pengguna";
        }
        if ($jml = ApprovalStep::where('peringkat', $level_pengajuan->peringkat)->count()) {
            $halangan[] = "{$jml} tahap rantai persetujuan";
        }
        if ($halangan !== []) {
            return back()->with('error', "Level \"{$level_pengajuan->nama}\" masih dipakai ("
                .implode(' dan ', $halangan).'). Pindahkan dulu yang memakainya, '
                .'atau nonaktifkan levelnya saja.');
        }

        if ($pesan = $this->melumpuhkanPemohon($level_pengajuan, ['boleh_ajukan_pembayaran' => false])) {
            return back()->with('error', $pesan);
        }

        $nama = $level_pengajuan->nama;
        $level_pengajuan->delete();

        return redirect()->route('level_pengajuan.index')->with('status', "Level pengajuan \"{$nama}\" dihapus.");
    }

    /** @return array<string,array<int,mixed>> aturan di luar `peringkat` */
    private function aturanIsi(): array
    {
        $aturan = [
            'nama' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ];
        foreach (LevelPengajuan::kolomPeran() as $peran) {
            $aturan[$peran] = ['nullable', 'boolean'];
        }

        return $aturan;
    }

    /**
     * Centang yang TIDAK dikirim berarti mati — kotak centang HTML memang tak
     * mengirim apa pun saat kosong, dan tanpa ini mematikan sebuah peran menjadi
     * mustahil lewat form.
     *
     * @return array<string,mixed>
     */
    private function bacaPeran(Request $request, array $data): array
    {
        foreach (LevelPengajuan::kolomPeran() as $peran) {
            $data[$peran] = $request->boolean($peran);
        }

        return $data;
    }

    /**
     * Pesan penghalang bila perubahan ini menyisakan NOL level aktif yang boleh
     * mengajukan pembayaran.
     *
     * Bukan sekadar kerapian: tanpa satu pun pemohon, form pengajuan pembayaran
     * menolak siapa pun yang membukanya, dan pesannya justru menyuruh mengatur
     * level — yang tak akan menolong orang yang tak tahu ini yang terjadi.
     */
    private function melumpuhkanPemohon(LevelPengajuan $level, array $isi): ?string
    {
        $masihPemohon = ($isi['boleh_ajukan_pembayaran'] ?? false)
            && ($isi['status'] ?? $level->status) === 'aktif';
        if ($masihPemohon) {
            return null;
        }

        $lain = LevelPengajuan::where('peringkat', '!=', $level->peringkat)
            ->where('status', 'aktif')->where('boleh_ajukan_pembayaran', true)->exists();

        return $lain ? null
            : 'Ini satu-satunya level aktif yang boleh mengajukan pembayaran. '
                .'Tunjuk level lain sebagai pemohon lebih dulu — tanpa seorang pun pemohon, '
                .'modul Pengajuan Pembayaran berhenti bisa dipakai siapa pun.';
    }
}
