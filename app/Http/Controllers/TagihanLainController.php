<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\TipeBiaya;
use App\Services\Modules\TagihanLainService;
use App\Support\Referensi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tagihan Lain-lain (seragam, kegiatan, denda). Terbitkan ke banyak santri
 * sekaligus; akrual bila jenis biaya punya akun piutang. Controller tipis →
 * TagihanLainService.
 */
class TagihanLainController extends Controller
{
    public function __construct(private readonly TagihanLainService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $fJenis = trim((string) $request->query('jenis', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $jenisLain = JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('lain'))->orderBy('nama')->pluck('nama', 'kode');
        $rows = TagihanSantri::query()->with(['santri', 'jenis'])
            ->whereIn('kode_jenis', $jenisLain->keys())
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('keterangan', 'ilike', "%{$q}%")
                ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$q}%")
                    ->orWhere('no_pendaftaran', 'ilike', "%{$q}%")->orWhere('nis', 'ilike', "%{$q}%")),
            ))
            ->when($fJenis !== '', fn ($query) => $query->where('kode_jenis', $fJenis))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('tagihan-lain.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['jenis' => $fJenis, 'status' => $fStatus],
            'opsiJenis' => $jenisLain->all(),
            'opsiStatus' => ['belum_bayar' => 'Belum Bayar', 'sebagian' => 'Sebagian', 'lunas' => 'Lunas', 'batal' => 'Batal'],
        ]);
    }

    public function create(): View
    {
        return view('tagihan-lain.create', [
            'jenisOptions' => ['' => '— pilih jenis —'] + JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('lain'))->where('status', 'aktif')->orderBy('kode')->get()
                // Jenjang ikut ditampilkan: sejak jenjang bisa diisi pada jenis
                // berperilaku "lain", dua baris bisa bernama mirip dan hanya
                // dibedakan jenjangnya.
                ->mapWithKeys(fn ($j) => [$j->kode => "{$j->kode} — {$j->nama}".($j->kode_jenjang ? " ({$j->kode_jenjang})" : '')])->all(),
            // [id => "NIS - Nama - Jenjang - Tingkat"] — daftar centang ini dulu
            // hanya menampilkan nama + KODE jenjang mentah (`J001`), sehingga dua
            // santri bernama mirip tak bisa dibedakan sama sekali.
            'santriAktif' => Referensi::santri('aktif'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_jenis' => ['required', 'string', 'exists:jenis_biaya,kode'],
            'id_santri' => ['required', 'array', 'min:1'],
            'id_santri.*' => ['integer', 'exists:santri,id'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'periode' => ['nullable', 'string', 'max:255'],
            'tanggal' => ['required', 'date'],
            // Keduanya sudah lama dibaca service, tapi tak pernah dikirim dari
            // layarnya. Akibatnya jatuh tempo tagihan lain SELALU kosong, dan
            // Reminder Tagihan — yang menyaring lewat jatuh tempo — tak pernah
            // menjaring satu pun tagihan lain-lain.
            'jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal'],
            'keterangan' => ['nullable', 'string', 'max:500'],
        ]);
        $data['id_santri'] = array_map('intval', $data['id_santri']);

        try {
            $hasil = $this->service->terbitkan($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.index')->with('status', $this->ringkasanTerbit($hasil));
    }

    /**
     * Susun hasil penerbitan jadi satu kalimat.
     *
     * Service sudah menghitung semuanya sejak dulu — berapa terbit, berapa
     * dilewati beserta namanya, totalnya, dan nomor jurnalnya — lalu seluruhnya
     * dibuang dan diganti "Tagihan lain berhasil diterbitkan". Petugas jadi tak
     * pernah tahu ada santri yang tak kebagian tagihan sampai ada yang menagih.
     *
     * @param  array<string,mixed>  $hasil
     */
    private function ringkasanTerbit(array $hasil): string
    {
        $rupiah = 'Rp '.number_format((float) $hasil['total'], 0, ',', '.');
        $pesan = "{$hasil['terbit']} tagihan terbit — total {$rupiah}.";

        if ($hasil['akrual'] && $hasil['referensi']) {
            $pesan .= " Jurnal akrual {$hasil['referensi']}.";
        }

        if ($hasil['dilewati'] > 0) {
            // Daftar nama dipenggal: satu kali terbit bisa melewati puluhan
            // santri, dan pesan sepanjang layar tak lagi terbaca siapa pun.
            $nama = $hasil['dilewati_nama'];
            $tampil = array_slice($nama, 0, 8);
            $sisa = count($nama) - count($tampil);
            $daftar = implode(', ', $tampil).($sisa > 0 ? " dan {$sisa} lainnya" : '');
            $pesan .= " {$hasil['dilewati']} dilewati karena sudah punya tagihan ini: {$daftar}.";
        }

        if ($hasil['tidak_aktif'] > 0) {
            $pesan .= " {$hasil['tidak_aktif']} dilewati karena sudah tidak berstatus aktif.";
        }

        return $pesan;
    }

    public function batalkan(string $id): RedirectResponse
    {
        try {
            $this->service->batalkan((int) $id);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('tagihan_lain.index')->with('status', 'Tagihan dibatalkan.');
    }
}
