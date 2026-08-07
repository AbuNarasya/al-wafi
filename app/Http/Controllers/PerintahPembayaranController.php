<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\PerintahPembayaran;
use App\Services\Ledger\DocNumber;
use App\Services\Modules\DanaBebasService;
use App\Services\Modules\PerintahPembayaranService;
use App\Support\Akses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Perintah Pembayaran — dokumen KAS, tidak menjurnal.
 *
 * Otorisasi digerbangi hak yang BERBEDA dari penyusunannya: `otorisasi-pembayaran`
 * (aksi `ubah`) versus `perintah-pembayaran` (aksi `buat`). Pemisahan itu yang
 * membuat empat mata bisa ditegakkan lewat pemberian hak, bukan sekadar
 * kesepakatan lisan.
 *
 * PENUTUPAN lebih longgar dari otorisasi — pejabat pengotorisasi ATAU staf
 * keuangan yang mengeksekusi pembayaran. Menutup tidak mengeluarkan uang sepeser
 * pun; ia justru MENGHENTIKAN sisa pembayaran. Menahannya di satu orang membuat
 * perintah yang sudah lunas menggantung tanpa menambah pengamanan apa pun.
 */
class PerintahPembayaranController extends Controller
{
    public function __construct(private readonly PerintahPembayaranService $service) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $rows = PerintahPembayaran::with(['penyusun', 'pengotorisasi'])
            ->withCount('detail')
            ->when($q !== '', fn ($x) => $x->where(fn ($w) => $w
                ->where('nomor', 'ilike', "%{$q}%")->orWhere('keterangan', 'ilike', "%{$q}%")))
            ->when($status !== '', fn ($x) => $x->where('status', $status))
            ->orderByDesc('kode_transaksi')->paginate(25)->withQueryString();

        return view('perintah-pembayaran.index', [
            'rows' => $rows,
            'q' => $q,
            'filter' => ['status' => $status],
            'opsiStatus' => PerintahPembayaran::STATUS,
            // Ditampilkan di kepala daftar: angka inilah yang membatasi seluruh
            // perintah, jadi ia perlu terlihat sebelum orang mulai menyusun.
            'dana' => (new DanaBebasService)->hitung(),
        ]);
    }

    public function create(): View
    {
        // Nomor yang AKAN dipakai — indikatif. Nomor final ditetapkan saat
        // disimpan, dan bisa berbeda bila tanggalnya digeser ke bulan lain atau
        // ada perintah lain yang tersimpan lebih dulu.
        $base = DocNumber::docBase('PP', now());
        $terakhir = PerintahPembayaran::where('nomor', 'like', $base.'%')
            ->orderByDesc('nomor')->value('nomor');

        return view('perintah-pembayaran.create', [
            'nomorPreview' => DocNumber::nextDocNumber($base, $terakhir),
            'kewajiban' => $this->service->kewajibanTersedia(),
            'dana' => (new DanaBebasService)->hitung(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'keterangan' => ['required', 'string', 'max:255'],
            'tanggal_usulan' => ['nullable', 'date'],
            'detail' => ['required', 'array', 'min:1'],
            'detail.*.sumber' => ['required', 'string'],
            'detail.*.id_dokumen' => ['required', 'integer'],
            'detail.*.nominal' => ['required', 'numeric', 'gt:0'],
            'detail.*.keterangan' => ['nullable', 'string'],
        ]);

        try {
            $pp = $this->service->buat($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('perintah_pembayaran.show', $pp->kode_transaksi)
            ->with('status', "Perintah {$pp->nomor} tersimpan sebagai draf.");
    }

    public function show(int $id): View
    {
        // `detail.unit` ikut: baris kewajiban menyebut NAMA unit, bukan kodenya.
        $pp = PerintahPembayaran::with(['detail', 'detail.unit', 'penyusun', 'pengotorisasi', 'rekeningRencana'])->findOrFail($id);

        // Riwayat pengajuan tiap kewajiban di PP LAIN — inilah yang mencegah
        // satu kewajiban ditunda berkali-kali tanpa ada yang menyadarinya.
        $riwayat = [];
        foreach ($pp->detail as $d) {
            $riwayat[$d->id] = $this->service->riwayat($d->sumber, $d->id_dokumen, $id);
        }

        return view('perintah-pembayaran.show', [
            'pp' => $pp,
            'riwayat' => $riwayat,
            'dana' => (new DanaBebasService)->hitung(),
            // Dana bebas TANPA komitmen PP ini — batas sebenarnya saat mengotorisasi.
            'batasOtorisasi' => (new DanaBebasService)->danaBebasKecuali($id),
            'kewajiban' => $pp->status === 'menunggu' ? $this->service->kewajibanTersedia($id) : [],
            'rekeningOptions' => ['' => '— belum ditentukan —'] + BankAccount::where('status', 'aktif')
                ->orderBy('kode_coa')->pluck('nama_rekening', 'kode_coa')->all(),
            'bolehOtorisasi' => \App\Support\Akses::boleh('otorisasi-pembayaran', 'ubah')
                && (int) $pp->disusun_oleh !== (int) auth()->user()->id_pengguna,
        ]);
    }

    /**
     * Kepatuhan realisasi — diotorisasi versus yang benar-benar terjadi.
     *
     * Tanpa halaman ini, penyimpangan memang tercatat tetapi tak pernah ada yang
     * melihatnya.
     */
    public function kepatuhan(Request $request): View
    {
        $filter = [
            'dari' => $request->query('dari') ?: null,
            'sampai' => $request->query('sampai') ?: null,
            'hanya_selisih' => $request->boolean('selisih'),
        ];

        return view('perintah-pembayaran.kepatuhan', [
            'rows' => $this->service->kepatuhan($filter),
            'filter' => $filter,
        ]);
    }

    /** Lembar perintah. Yang sudah diotorisasi membawa "digitally approved"; yang belum, cap DRAF. */
    public function print(int $id): View
    {
        return view('perintah-pembayaran.print', [
            'pp' => PerintahPembayaran::with(['detail', 'penyusun', 'pengotorisasi', 'rekeningRencana'])->findOrFail($id),
            'company' => \App\Models\CompanySettings::find(1),
        ]);
    }

    public function ajukan(Request $request, int $id): RedirectResponse
    {
        return $this->jalankan(fn () => $this->service->ajukan($id, $request->user()->id_pengguna),
            'Perintah diajukan untuk otorisasi.', $id);
    }

    public function otorisasi(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'tanggal_bayar' => ['required', 'date'],
            'metode' => ['required', 'string'],
            'kode_rekening_rencana' => ['nullable', 'string', 'exists:bank_accounts,kode_coa'],
            'catatan' => ['nullable', 'string'],
            'baris' => ['required', 'array'],
            'alasan' => ['nullable', 'array'],
            'tambahan' => ['nullable', 'array'],
        ]);

        return $this->jalankan(fn () => $this->service->otorisasi($id, $data, $request->user()->id_pengguna),
            'Perintah pembayaran diotorisasi.', $id);
    }

    public function tolak(Request $request, int $id): RedirectResponse
    {
        $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        return $this->jalankan(fn () => $this->service->tolak($id, $request->input('alasan'), $request->user()->id_pengguna),
            'Perintah pembayaran ditolak.', $id);
    }

    /**
     * Menutup perintah = menyatakan tuntas, dan membatalkan sisa yang belum
     * dibayar. Dua peran yang berhak, bukan satu:
     *
     *  - pejabat pengotorisasi, yang memerintahkan pembayarannya;
     *  - staf keuangan yang MENGEKSEKUSI pembayaran (hak Kas Keluar), karena
     *    dialah yang tahu kapan perintahnya sudah benar-benar tuntas.
     *
     * Menahan penutupan di satu orang membuat perintah yang sudah lunas
     * menggantung menunggu tanda tangan yang tak menambah apa pun.
     */
    public function tutup(Request $request, int $id): RedirectResponse
    {
        abort_unless(
            Akses::boleh('otorisasi-pembayaran', 'ubah') || Akses::boleh('cash-out', 'buat'),
            403,
            'Penutupan perintah pembayaran hanya boleh oleh pejabat pengotorisasi atau staf keuangan yang mengeksekusi pembayaran.',
        );

        $request->validate(['alasan' => ['nullable', 'string', 'max:255']]);

        return $this->jalankan(fn () => $this->service->tutup($id, $request->input('alasan'), $request->user()->id_pengguna),
            'Perintah pembayaran dinyatakan selesai.', $id);
    }

    private function jalankan(callable $aksi, string $pesan, int $id): RedirectResponse
    {
        try {
            $aksi();
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('perintah_pembayaran.show', $id)->with('status', $pesan);
    }
}
