<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\TahunAjaran;
use App\Services\Modules\TarifService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Menu TARIF — satu grid per (tahun ajaran × jenjang): baris jalur, kolom
 * komponen biaya. Menggantikan matriks baris jenis_biaya yang dulu harus
 * diduplikasi tiap tahun.
 *
 * Sengaja SATU layar per jenjang, bukan satu layar untuk semuanya: enam jalur ×
 * empat komponen sudah 24 sel: menumpuk tiga jenjang di satu halaman membuatnya
 * tak terbaca dan salah isi jadi mudah.
 */
class TarifController extends Controller
{
    public function __construct(private readonly TarifService $service) {}

    public function index(Request $request): View
    {
        $opsiTa = TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all();
        // "(tanpa jenjang)" selalu ada: pesantren yang tak memakai jenjang tetap
        // butuh tempat mengisi tarifnya, dan tanpa opsi ini sel bertanda kosong
        // itu tak akan pernah bisa disunting dari layar.
        $opsiJenjang = ['' => '— (tanpa jenjang) —']
            + Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();

        $ta = (string) $request->query('ta', '');
        if (! isset($opsiTa[$ta])) {
            // T.A default pendaftaran lebih berguna sebagai pilihan awal daripada
            // yang terbaru: itulah tahun yang sedang dikerjakan petugas PPSB.
            $ta = (string) (TahunAjaran::where('default_pendaftaran', true)->value('kode')
                ?? array_key_first($opsiTa) ?? '');
        }
        // Jenjang pertama yang SUNGGUHAN jadi pilihan awal, bukan "(tanpa jenjang)":
        // itulah yang dipakai hampir semua pesantren.
        $jenjang = (string) $request->query('jenjang', array_keys($opsiJenjang)[1] ?? '');
        if (! isset($opsiJenjang[$jenjang])) {
            $jenjang = '';
        }

        return view('tarif.index', [
            'opsiTa' => $opsiTa,
            'opsiJenjang' => $opsiJenjang,
            'ta' => $ta,
            'jenjang' => $jenjang,
            // Dipisah untuk LAYAR saja — penyimpanannya tetap satu tabel. Perilaku
            // yang tak mengenal jalur dulu ikut jadi kolom grid, dan barisnya
            // menyisakan sel mati sebanyak jumlah jalur.
            'perilakuJalur' => array_diff_key(TarifService::PERILAKU, array_flip(TarifService::TANPA_JALUR)),
            'perilakuUmum' => array_intersect_key(TarifService::PERILAKU, array_flip(TarifService::TANPA_JALUR)),
            'grid' => $ta !== '' ? $this->service->grid($ta, $jenjang ?: null) : null,
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['nullable', 'string', 'exists:jenjang,kode'],
            'sel' => ['array'],
            'umum' => ['array'],
        ]);
        $jenjang = ($data['kode_jenjang'] ?? '') ?: null;

        try {
            // Satu tombol Simpan, dua kelompok dimensi — biaya masuk (per jalur)
            // dan biaya santri aktif (per tingkat). Keduanya dalam satu kiriman form.
            $this->service->simpan($data['tahun_ajaran'], $jenjang, $data['sel'] ?? []);
            $this->service->simpanUmum($data['tahun_ajaran'], $jenjang, $data['umum'] ?? []);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tarif.index', ['ta' => $data['tahun_ajaran'], 'jenjang' => $jenjang])
            ->with('status', 'Tarif '.($jenjang ?? 'tanpa jenjang')." T.A {$data['tahun_ajaran']} tersimpan.");
    }

    /**
     * Tandai jalur tidak berlaku di (T.A, jenjang) ini — mis. SDTQ tak punya
     * jalur OSS. Butuh hak UBAH, bukan sekadar lihat: ia membuang sel tarif
     * yang mungkin sudah diisi.
     */
    public function nonaktifkanJalur(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahun_ajaran' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['required', 'string', 'exists:jenjang,kode'],
            'kode_jalur' => ['required', 'string', 'exists:jalur_pendaftaran,kode'],
            'aksi' => ['required', 'in:nonaktifkan,aktifkan'],
        ]);

        try {
            if ($data['aksi'] === 'nonaktifkan') {
                $this->service->nonaktifkanJalur($data['tahun_ajaran'], $data['kode_jenjang'], $data['kode_jalur']);
                $pesan = "Jalur {$data['kode_jalur']} tidak lagi berlaku di {$data['kode_jenjang']} T.A {$data['tahun_ajaran']}.";
            } else {
                $this->service->aktifkanJalur($data['tahun_ajaran'], $data['kode_jenjang'], $data['kode_jalur']);
                $pesan = "Jalur {$data['kode_jalur']} berlaku lagi di {$data['kode_jenjang']} T.A {$data['tahun_ajaran']}.";
            }
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tarif.index', ['ta' => $data['tahun_ajaran'], 'jenjang' => $data['kode_jenjang']])
            ->with('status', $pesan);
    }

    public function salin(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sumber' => ['required', 'string', 'exists:tahun_ajaran,kode'],
            'tujuan' => ['required', 'string', 'different:sumber', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['nullable', 'string', 'exists:jenjang,kode'],
            'semua_jenjang' => ['nullable', 'boolean'],
        ]);

        $jenjang = $request->boolean('semua_jenjang') ? null : ($data['kode_jenjang'] ?? null);

        try {
            $hasil = $this->service->salin($data['sumber'], $data['tujuan'], $jenjang);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        $pesan = "{$hasil['disalin']} sel tarif disalin dari T.A {$data['sumber']} ke {$data['tujuan']}.";
        if ($hasil['dilewati'] > 0) {
            $pesan .= " {$hasil['dilewati']} sel dilewati karena di tahun tujuan sudah terisi.";
        }
        if ($hasil['jalur_ditutup'] > 0) {
            $pesan .= " {$hasil['jalur_ditutup']} penonaktifan jalur ikut disalin.";
        }

        return redirect()->route('tarif.index', ['ta' => $data['tujuan'], 'jenjang' => $data['kode_jenjang'] ?? null])
            ->with('status', $pesan);
    }
}
