<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\JenisBiaya;
use App\Models\PesertaTagihanLain;
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
            'opsiStatus' => ['belum_bayar' => 'Belum Bayar', 'sebagian' => 'Sebagian', 'lunas' => 'Lunas', 'batal' => 'Batal', 'dihapus' => 'Dihapus'],
        ]);
    }

    public function create(Request $request): View
    {
        $daftar = JenisBiaya::whereIn('tipe', TipeBiaya::kodeBerperilaku('lain'))
            ->where('status', 'aktif')->orderBy('kode')->get();

        $kode = trim((string) $request->query('jenis', ''));
        $jenis = $kode !== '' ? $daftar->firstWhere('kode', $kode) : null;

        return view('tagihan-lain.create', [
            // Jenjang ikut ditampilkan: sejak jenjang bisa diisi pada jenis
            // berperilaku "lain", dua baris bisa bernama mirip dan hanya
            // dibedakan jenjangnya.
            'jenisOptions' => $daftar->mapWithKeys(fn ($j) => [
                $j->kode => "{$j->kode} — {$j->nama}".($j->kode_jenjang ? " ({$j->kode_jenjang})" : ''),
            ])->all(),
            'kodeJenis' => $jenis?->kode ?? '',
            'jenis' => $jenis,
            'santriAktif' => $jenis ? $this->santriUntuk($jenis) : [],
            'sumberDaftar' => $jenis ? $this->sumberDaftar($jenis) : null,
        ]);
    }

    /**
     * Santri yang MASUK AKAL ditagih untuk jenis ini.
     *
     * Dulu layar ini menumpahkan SELURUH santri aktif — 202 baris centang tanpa
     * penyaring apa pun, termasuk santri SDTQ pada baris "Laundry SMA". Yang
     * ditawarkan kini menyempit mengikuti sifat jenisnya:
     *
     *   kepesertaan → hanya yang terdaftar sebagai peserta & masih ikut
     *   berjenjang → hanya santri jenjang itu
     *   selain itu  → seluruh santri aktif (jenis lintas jenjang tanpa peserta)
     *
     * @return array<int,string>
     */
    private function santriUntuk(JenisBiaya $jenis): array
    {
        if ($jenis->cara_tagih === 'kepesertaan') {
            $ids = PesertaTagihanLain::where('kode_jenis', $jenis->kode)->where('status', 'ikut')->pluck('id_santri');

            return Referensi::santri('aktif', hanyaId: $ids->all());
        }

        return Referensi::santri('aktif', $jenis->kode_jenjang);
    }

    /** Kalimat yang menerangkan KENAPA daftarnya sependek itu. */
    private function sumberDaftar(JenisBiaya $jenis): string
    {
        return match (true) {
            $jenis->cara_tagih === 'kepesertaan' => 'Hanya peserta terdaftar kegiatan ini. Tambah/hentikan peserta di menu Peserta Kegiatan.',
            $jenis->cara_tagih === 'pemakaian' => 'Nominal layanan bersatuan biasanya diterbitkan dari menu Setoran Laundry, bukan diketik di sini.',
            (bool) $jenis->kode_jenjang => 'Hanya santri aktif jenjang jenis biaya ini.',
            default => 'Seluruh santri aktif — jenis biaya ini tidak terikat jenjang.',
        };
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

        return redirect()->route('tagihan_lain.index')->with('status', $this->service->ringkasanTerbit($hasil));
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
