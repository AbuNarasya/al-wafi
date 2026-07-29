<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Services\Impor\ImporSaldoAwal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * IMPOR DATA AWAL — memasukkan keadaan yang sudah berjalan di luar sistem saat
 * pindah ke aplikasi ini. Satu halaman, banyak jenis berkas (lihat ImporSaldoAwal).
 *
 * Alurnya dipaksa bertahap: unggah → PRATINJAU → Impor. Tidak ada rute yang
 * menulis langsung dari unggahan, karena satu berkas bisa berisi ratusan baris
 * dan kekeliruannya baru kelihatan setelah dibaca.
 */
class ImporDataAwalController extends Controller
{
    /** Folder penampung berkas antara pratinjau dan impor. */
    private const FOLDER = 'impor';

    public function __construct(private readonly ImporSaldoAwal $impor) {}

    public function index(Request $request): View
    {
        return view('impor-data-awal.index', $this->data($request->query('jenis')));
    }

    /** Unduh template CSV berisi judul kolom + satu baris contoh. */
    public function template(string $jenis)
    {
        try {
            $isi = $this->impor->template($jenis);
        } catch (AppException $e) {
            abort($e->status, $e->getMessage());
        }

        return response($isi, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="template-'.$jenis.'.csv"',
        ]);
    }

    public function pratinjau(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'jenis' => ['required', 'string'],
            'berkas' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
        ]);

        $path = $request->file('berkas')->store(self::FOLDER);

        try {
            $param = $this->paramDari($request, $data['jenis']);
            $hasil = $this->impor->pratinjau($data['jenis'], Storage::path($path), $param);
        } catch (AppException $e) {
            Storage::delete($path);

            return redirect()->route('impor_data_awal.index', ['jenis' => $data['jenis']])->with('error', $e->getMessage());
        }

        // array_merge, BUKAN operator "+": union array mempertahankan nilai kiri,
        // sehingga 'hasil' => null bawaan data() akan menang dan pratinjaunya
        // tak pernah tampil.
        return view('impor-data-awal.index', array_merge($this->data($data['jenis']), [
            'hasil' => $hasil,
            'berkasPath' => $path,
            'namaBerkas' => $request->file('berkas')->getClientOriginalName(),
            'param' => $param,
        ]));
    }

    public function jalankan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'jenis' => ['required', 'string'],
            // Hanya berkas yang memang baru diunggah lewat pratinjau; pola ini
            // menutup jalan menunjuk berkas lain di penyimpanan.
            'berkas_path' => ['required', 'string', 'regex:/^'.self::FOLDER.'\/[A-Za-z0-9]+\.(csv|txt)$/'],
        ]);

        if (! Storage::exists($data['berkas_path'])) {
            return redirect()->route('impor_data_awal.index', ['jenis' => $data['jenis']])
                ->with('error', 'Berkas unggahan sudah tidak ada. Unggah ulang lalu periksa lagi.');
        }

        try {
            $hasil = $this->impor->jalankan(
                $data['jenis'],
                Storage::path($data['berkas_path']),
                $this->paramDari($request, $data['jenis']),
            );
        } catch (AppException $e) {
            return redirect()->route('impor_data_awal.index', ['jenis' => $data['jenis']])->with('error', $e->getMessage());
        } finally {
            Storage::delete($data['berkas_path']);
        }

        $rincian = collect($hasil['tersimpan'])->filter()->map(fn ($n, $k) => "{$n} {$k}")->join(', ');
        $pesan = "Impor selesai — {$rincian}.";
        if ($hasil['lewati'] > 0) {
            $pesan .= " {$hasil['lewati']} baris dilewati karena sudah pernah masuk.";
        }
        if ($hasil['masalah'] > 0) {
            $pesan .= " {$hasil['masalah']} baris bermasalah tidak diimpor.";
        }

        return redirect()->route('impor_data_awal.index', ['jenis' => $data['jenis']])->with('status', $pesan);
    }

    /** @return array<string,string> */
    private function paramDari(Request $request, string $jenis): array
    {
        $hasil = [];
        foreach (array_keys(ImporSaldoAwal::pemeta($jenis)->parameter()) as $nama) {
            $hasil[$nama] = trim((string) $request->input("param.{$nama}", ''));
        }

        return $hasil;
    }

    /** @return array<string,mixed> */
    private function data(?string $jenis): array
    {
        $daftar = ImporSaldoAwal::daftar();
        $jenis = $jenis && isset($daftar[$jenis]) ? $jenis : array_key_first($daftar);
        $pemeta = ImporSaldoAwal::pemeta($jenis);

        return [
            'daftar' => collect($daftar)->map(fn ($k) => $k::judul())->all(),
            'jenis' => $jenis,
            'judul' => $pemeta::judul(),
            'penjelasan' => $pemeta::penjelasan(),
            'kolom' => $pemeta->kolom(),
            'parameter' => $pemeta->parameter(),
            'hasil' => null,
            'berkasPath' => null,
            'namaBerkas' => null,
            'param' => [],
        ];
    }
}
