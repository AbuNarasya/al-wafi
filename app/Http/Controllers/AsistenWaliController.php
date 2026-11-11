<?php

namespace App\Http\Controllers;

use App\Services\PortalWali\AsistenWali;
use App\Support\Audit\Jejak;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Layar staf ASISTEN BANTUAN PORTAL (hak `asisten-wali`, 11 Nov 2026): saklar & penyedia AI, uji banding
 * Groq vs Gemini, artikel panduan (bahan jawaban AI sekaligus FAQ cadangan), dan riwayat pertanyaan wali.
 */
class AsistenWaliController extends Controller
{
    public function edit(): View
    {
        $atur = AsistenWali::pengaturan();
        $awalHari = now('Asia/Jakarta')->startOfDay()->utc();

        return view('asisten-wali.edit', [
            'atur' => $atur,
            'kunci' => collect(AsistenWali::PENYEDIA)->map(fn ($_, $p) => AsistenWali::kunci($p) !== null)->all(),
            'artikel' => DB::table('artikel_bantuan')->orderBy('urutan')->orderBy('id')->get(),
            'hariIni' => DB::table('percakapan_asisten')->where('created_at', '>=', $awalHari)
                ->selectRaw('sumber, count(*) as n')->groupBy('sumber')->pluck('n', 'sumber'),
            'diubahOleh' => $atur->diubah_oleh ? DB::table('users')->where('id_pengguna', $atur->diubah_oleh)->value('nama') : null,
            'uji' => session('uji'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'aktif' => ['boolean'],
            'penyedia' => ['required', Rule::in(array_keys(AsistenWali::PENYEDIA))],
            'model_groq' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\/:\-]+$/'],
            'model_gemini' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'batas_harian' => ['required', 'integer', 'min:1', 'max:200'],
        ], ['model_groq.regex' => 'Nama model hanya huruf, angka, titik, garis, dan garis miring.', 'model_gemini.regex' => 'Nama model hanya huruf, angka, titik, dan garis.']);
        $data['aktif'] = $request->boolean('aktif');

        $lama = AsistenWali::pengaturan();
        DB::table('pengaturan_asisten_wali')->updateOrInsert(['id' => $lama->id ?? 1],
            $data + ['diubah_oleh' => $request->user()->id_pengguna, 'updated_at' => now()] + (isset($lama->id) ? [] : ['created_at' => now()]));

        $perubahan = [];
        foreach ($data as $k => $v) {
            if ((string) ($lama->{$k} ?? '') !== (string) $v) {
                $perubahan[$k] = ['dari' => $lama->{$k} ?? null, 'ke' => $v];
            }
        }
        if ($perubahan !== []) {
            Jejak::catat('ubah_asisten_wali', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna,
                'detail' => ($data['aktif'] ? 'Nyala' : 'Mati').', penyedia '.AsistenWali::PENYEDIA[$data['penyedia']].', batas '.$data['batas_harian'].'/hari',
                'perubahan' => $perubahan]);
        }

        $pesan = 'Pengaturan asisten disimpan.';
        if ($data['aktif'] && AsistenWali::kunci($data['penyedia']) === null) {
            $pesan .= ' Catatan: kunci API '.AsistenWali::PENYEDIA[$data['penyedia']].' belum dipasang di server, jadi wali hanya mendapat hasil pencarian panduan.';
        }

        return redirect()->route('asisten_wali.edit')->with('status', $pesan);
    }

    public function uji(Request $request, AsistenWali $asisten): RedirectResponse
    {
        $data = $request->validate(['pertanyaan' => ['required', 'string', 'max:'.AsistenWali::MAKS_PERTANYAAN]]);

        return redirect()->route('asisten_wali.edit')->withInput()->with('uji', [
            'pertanyaan' => $data['pertanyaan'],
            'artikel' => AsistenWali::cariArtikel(AsistenWali::samarkan($data['pertanyaan']), 5)->pluck('judul')->all(),
            'hasil' => $asisten->bandingkan($data['pertanyaan']),
        ]);
    }

    public function riwayat(Request $request): View
    {
        $sumber = $request->query('sumber');

        return view('asisten-wali.riwayat', [
            'baris' => DB::table('percakapan_asisten as p')->join('wali as w', 'w.id', '=', 'p.id_wali')
                ->when(in_array($sumber, ['groq', 'gemini', 'artikel'], true), fn ($q) => $q->where('p.sumber', $sumber))
                ->when($request->boolean('galat'), fn ($q) => $q->whereNotNull('p.galat'))
                ->orderByDesc('p.id')
                ->select('p.*', 'w.nama as keluarga')
                ->paginate(50)->withQueryString(),
            'sumber' => $sumber,
        ]);
    }

    public function artikelBaru(): View
    {
        return view('asisten-wali.artikel', ['a' => null]);
    }

    public function artikelSimpan(Request $request): RedirectResponse
    {
        $data = $this->validasiArtikel($request);
        $id = DB::table('artikel_bantuan')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        Jejak::catat('buat_artikel_bantuan', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna, 'detail' => "#{$id} {$data['judul']}"]);

        return redirect()->route('asisten_wali.edit')->with('status', 'Artikel ditambahkan.');
    }

    public function artikelUbah(int $id): View
    {
        return view('asisten-wali.artikel', ['a' => DB::table('artikel_bantuan')->where('id', $id)->first() ?? abort(404)]);
    }

    public function artikelPerbarui(Request $request, int $id): RedirectResponse
    {
        abort_unless(DB::table('artikel_bantuan')->where('id', $id)->exists(), 404);
        $data = $this->validasiArtikel($request, $id);
        DB::table('artikel_bantuan')->where('id', $id)->update($data + ['updated_at' => now()]);
        Jejak::catat('ubah_artikel_bantuan', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna, 'detail' => "#{$id} {$data['judul']}"]);

        return redirect()->route('asisten_wali.edit')->with('status', 'Artikel disimpan.');
    }

    public function artikelHapus(Request $request, int $id): RedirectResponse
    {
        $judul = DB::table('artikel_bantuan')->where('id', $id)->value('judul') ?? abort(404);
        DB::table('artikel_bantuan')->where('id', $id)->delete();
        Jejak::catat('hapus_artikel_bantuan', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna, 'detail' => "#{$id} {$judul}"]);

        return redirect()->route('asisten_wali.edit')->with('status', 'Artikel dihapus.');
    }

    /** @return array{judul:string, isi:string, kata_kunci:?string, urutan:int, aktif:bool} */
    private function validasiArtikel(Request $request, ?int $id = null): array
    {
        $data = $request->validate([
            'judul' => ['required', 'string', 'max:200', Rule::unique('artikel_bantuan', 'judul')->ignore($id)],
            'isi' => ['required', 'string', 'max:5000'],
            'kata_kunci' => ['nullable', 'string', 'max:1000'],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['judul.unique' => 'Judul ini sudah dipakai artikel lain.']);

        return ['judul' => trim($data['judul']), 'isi' => trim($data['isi']), 'kata_kunci' => trim((string) ($data['kata_kunci'] ?? '')) ?: null,
            'urutan' => (int) ($data['urutan'] ?? 0), 'aktif' => $request->boolean('aktif')];
    }
}
