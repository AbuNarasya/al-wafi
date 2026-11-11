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
    /** Pembaca artikel yang sedang dikelola — instans tanpa portal wali (Wafi Mart) hanya punya staf. */
    private function audiens(?string $minta): string
    {
        if (! \App\Support\Instans::portalWali()) {
            return 'staf';
        }

        return array_key_exists((string) $minta, AsistenWali::AUDIENS) ? $minta : 'wali';
    }

    public function edit(Request $request): View
    {
        $atur = AsistenWali::pengaturan();
        $awalHari = now('Asia/Jakarta')->startOfDay()->utc();
        $uji = session('uji');
        $audiens = $this->audiens($request->query('audiens', $uji['audiens'] ?? null));

        return view('asisten-wali.edit', [
            'atur' => $atur,
            'audiens' => $audiens,
            'portalWali' => \App\Support\Instans::portalWali(),
            'kunci' => collect(AsistenWali::PENYEDIA)->map(fn ($_, $p) => AsistenWali::kunci($p) !== null)->all(),
            'artikel' => DB::table('artikel_bantuan')->where('audiens', $audiens)->orderBy('urutan')->orderBy('id')->get(),
            'jumlahArtikel' => DB::table('artikel_bantuan')->selectRaw('audiens, count(*) as n')->groupBy('audiens')->pluck('n', 'audiens'),
            'hariIni' => DB::table('percakapan_asisten')->where('created_at', '>=', $awalHari)
                ->selectRaw('audiens, count(*) as n')->groupBy('audiens')->pluck('n', 'audiens'),
            'diubahOleh' => $atur->diubah_oleh ? DB::table('users')->where('id_pengguna', $atur->diubah_oleh)->value('nama') : null,
            'uji' => $uji,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'aktif' => ['boolean'],
            'aktif_staf' => ['boolean'],
            'cadangan' => ['boolean'],
            'penyedia' => ['required', Rule::in(array_keys(AsistenWali::PENYEDIA))],
            'model_groq' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\/:\-]+$/'],
            'model_gemini' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'batas_harian' => ['required', 'integer', 'min:1', 'max:200'],
            'batas_harian_staf' => ['required', 'integer', 'min:1', 'max:500'],
        ], ['model_groq.regex' => 'Nama model hanya huruf, angka, titik, garis, dan garis miring.', 'model_gemini.regex' => 'Nama model hanya huruf, angka, titik, dan garis.']);
        // Instans tanpa portal wali (Wafi Mart) tak menampilkan saklar wali — nilainya tetap mati.
        $data['aktif'] = \App\Support\Instans::portalWali() && $request->boolean('aktif');
        $data['aktif_staf'] = $request->boolean('aktif_staf');
        $data['cadangan'] = $request->boolean('cadangan');

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
                'detail' => 'Wali '.($data['aktif'] ? 'nyala' : 'mati').', staf '.($data['aktif_staf'] ? 'nyala' : 'mati')
                    .', penyedia '.AsistenWali::PENYEDIA[$data['penyedia']].', batas wali '.$data['batas_harian'].'/hari, staf '.$data['batas_harian_staf'].'/hari',
                'perubahan' => $perubahan]);
        }

        $pesan = 'Pengaturan asisten disimpan.';
        if (($data['aktif'] || $data['aktif_staf']) && AsistenWali::kunci($data['penyedia']) === null) {
            $pesan .= ' Catatan: kunci API '.AsistenWali::PENYEDIA[$data['penyedia']].' belum dipasang di server, jadi penanya hanya mendapat hasil pencarian panduan.';
        }

        return redirect()->route('asisten_wali.edit')->with('status', $pesan);
    }

    public function uji(Request $request, AsistenWali $asisten): RedirectResponse
    {
        $data = $request->validate(['pertanyaan' => ['required', 'string', 'max:'.AsistenWali::MAKS_PERTANYAAN], 'audiens' => ['nullable', 'string']]);
        $audiens = $this->audiens($data['audiens'] ?? null);

        return redirect()->route('asisten_wali.edit', ['audiens' => $audiens])->withInput()->with('uji', [
            'pertanyaan' => $data['pertanyaan'],
            'audiens' => $audiens,
            'artikel' => AsistenWali::cariArtikel(AsistenWali::samarkan($data['pertanyaan']), 5, $audiens)->pluck('judul')->all(),
            'hasil' => $asisten->bandingkan($data['pertanyaan'], $audiens),
        ]);
    }

    public function riwayat(Request $request): View
    {
        $sumber = $request->query('sumber');
        $audiens = $request->query('audiens');

        return view('asisten-wali.riwayat', [
            'baris' => DB::table('percakapan_asisten as p')
                ->leftJoin('wali as w', 'w.id', '=', 'p.id_wali')
                ->leftJoin('users as u', 'u.id_pengguna', '=', 'p.id_pengguna')
                ->when(in_array($sumber, ['groq', 'gemini', 'artikel'], true), fn ($q) => $q->where('p.sumber', $sumber))
                ->when(array_key_exists((string) $audiens, AsistenWali::AUDIENS), fn ($q) => $q->where('p.audiens', $audiens))
                ->when($request->boolean('galat'), fn ($q) => $q->whereNotNull('p.galat'))
                ->orderByDesc('p.id')
                ->select('p.*', DB::raw("COALESCE(u.nama, w.nama) as penanya"))
                ->paginate(50)->withQueryString(),
            'sumber' => $sumber,
            'audiens' => $audiens,
            'portalWali' => \App\Support\Instans::portalWali(),
        ]);
    }

    public function artikelBaru(Request $request): View
    {
        return view('asisten-wali.artikel', ['a' => null, 'audiens' => $this->audiens($request->query('audiens')), 'portalWali' => \App\Support\Instans::portalWali()]);
    }

    public function artikelSimpan(Request $request): RedirectResponse
    {
        $data = $this->validasiArtikel($request);
        $id = DB::table('artikel_bantuan')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
        Jejak::catat('buat_artikel_bantuan', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna, 'detail' => "#{$id} [{$data['audiens']}] {$data['judul']}"]);

        return redirect()->route('asisten_wali.edit', ['audiens' => $data['audiens']])->with('status', 'Artikel ditambahkan.');
    }

    public function artikelUbah(int $id): View
    {
        $a = DB::table('artikel_bantuan')->where('id', $id)->first() ?? abort(404);

        return view('asisten-wali.artikel', ['a' => $a, 'audiens' => $a->audiens, 'portalWali' => \App\Support\Instans::portalWali()]);
    }

    public function artikelPerbarui(Request $request, int $id): RedirectResponse
    {
        abort_unless(DB::table('artikel_bantuan')->where('id', $id)->exists(), 404);
        $data = $this->validasiArtikel($request, $id);
        DB::table('artikel_bantuan')->where('id', $id)->update($data + ['updated_at' => now()]);
        Jejak::catat('ubah_artikel_bantuan', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna, 'detail' => "#{$id} [{$data['audiens']}] {$data['judul']}"]);

        return redirect()->route('asisten_wali.edit', ['audiens' => $data['audiens']])->with('status', 'Artikel disimpan.');
    }

    public function artikelHapus(Request $request, int $id): RedirectResponse
    {
        $a = DB::table('artikel_bantuan')->where('id', $id)->first() ?? abort(404);
        DB::table('artikel_bantuan')->where('id', $id)->delete();
        Jejak::catat('hapus_artikel_bantuan', ['modul' => AsistenWali::MODUL, 'id_pengguna' => $request->user()->id_pengguna, 'detail' => "#{$id} [{$a->audiens}] {$a->judul}"]);

        return redirect()->route('asisten_wali.edit', ['audiens' => $a->audiens])->with('status', 'Artikel dihapus.');
    }

    /** @return array{audiens:string, judul:string, isi:string, kata_kunci:?string, urutan:int, aktif:bool} */
    private function validasiArtikel(Request $request, ?int $id = null): array
    {
        $audiens = $this->audiens($request->input('audiens'));
        $data = $request->validate([
            'judul' => ['required', 'string', 'max:200', Rule::unique('artikel_bantuan', 'judul')->where('audiens', $audiens)->ignore($id)],
            'isi' => ['required', 'string', 'max:5000'],
            'kata_kunci' => ['nullable', 'string', 'max:1000'],
            'urutan' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ], ['judul.unique' => 'Judul ini sudah dipakai artikel lain.']);

        return ['audiens' => $audiens, 'judul' => trim($data['judul']), 'isi' => trim($data['isi']), 'kata_kunci' => trim((string) ($data['kata_kunci'] ?? '')) ?: null,
            'urutan' => (int) ($data['urutan'] ?? 0), 'aktif' => $request->boolean('aktif')];
    }
}
