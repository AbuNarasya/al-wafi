<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CoaDetail;
use App\Models\CompanySettings;
use App\Models\MenuMitra;
use App\Models\Mitra;
use App\Models\PenarikanMitra;
use App\Models\PenjualanMitra;
use App\Models\User;
use App\Services\Modules\MitraKantinService;
use App\Services\Modules\SaldoMitraService;
use App\Support\Unggahan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * MITRA KANTIN — layar staf Wafi Mart (hak `mitra-kantin`): data mitra & masa sewa, daftar menu (diinput kepala
 * Wafi Mart dari setoran mitra), akun mitra, laporan penjualan kartu. Lihat [[MitraKantinService]].
 */
class MitraKantinController extends Controller
{
    public function __construct(private readonly MitraKantinService $s, private readonly SaldoMitraService $saldo) {}

    // ---- Penarikan saldo mitra (Paket 3) ----

    public function penarikan(Request $request): View
    {
        $status = (string) $request->query('status', 'diajukan');

        return view('mitra-kantin.penarikan', [
            'status' => $status,
            'rows' => PenarikanMitra::with('mitra:id,nama,kode', 'pemroses:id_pengguna,nama')
                ->when($status !== 'semua', fn ($q) => $q->where('status', $status))->orderByDesc('id')->paginate(30)->withQueryString(),
            'bankOptions' => BankAccount::where('status', 'aktif')->orderBy('nama_rekening')->pluck('nama_rekening', 'kode_coa')->all(),
            'akunHutang' => CompanySettings::query()->value('kode_coa_hutang_mitra'),
            'coaOptions' => CoaDetail::where('kode_coa', 'like', '2%')->orderBy('kode_coa')->get(['kode_coa', 'nama_coa'])
                ->mapWithKeys(fn ($c) => [$c->kode_coa => "{$c->kode_coa} — {$c->nama_coa}"])->all(),
        ]);
    }

    public function proses(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate([
            'biaya_transfer' => ['nullable', 'numeric', 'min:0'], 'kode_coa_bank' => ['required', 'string'],
            'tanggal_transfer' => ['required', 'date', 'before_or_equal:today'], 'bukti' => Unggahan::aturan(),
        ]);
        try {
            $p = $this->saldo->proses($id, $d['biaya_transfer'] ?? 0, $d['kode_coa_bank'], $d['tanggal_transfer'], $request->file('bukti'), $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', "Penarikan {$p->nomor} selesai — diterima mitra Rp ".number_format((float) $p->diterima(), 0, ',', '.').'.');
    }

    public function tolak(Request $request, int $id): RedirectResponse
    {
        $d = $request->validate(['alasan' => ['required', 'string', 'max:255']]);
        try {
            $p = $this->saldo->tolak($id, $d['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Penarikan {$p->nomor} ditolak.");
    }

    public function bukti(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $p = PenarikanMitra::findOrFail($id);
        abort_unless($p->bukti_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($p->bukti_path), 404);

        return Unggahan::sajikan('local', $p->bukti_path, $p->bukti_nama);
    }

    /** Akun "Hutang ke Mitra Kantin" — dibuat otomatis oleh migrasi, bisa diganti di sini. */
    public function akunHutang(Request $request): RedirectResponse
    {
        $d = $request->validate(['kode_coa_hutang_mitra' => ['required', 'string', 'exists:coa_detail,kode_coa']]);
        $lama = CompanySettings::query()->value('kode_coa_hutang_mitra');
        CompanySettings::query()->update(['kode_coa_hutang_mitra' => $d['kode_coa_hutang_mitra']]);
        \App\Support\Audit\Jejak::catat('ubah_akun_hutang_mitra', ['modul' => MitraKantinService::MODUL, 'id_pengguna' => $request->user()->id_pengguna,
            'perubahan' => ['kode_coa_hutang_mitra' => ['dari' => $lama, 'ke' => $d['kode_coa_hutang_mitra']]]]);

        return back()->with('status', 'Akun Hutang ke Mitra Kantin disimpan.');
    }

    public function index(): View
    {
        $hariIni = now()->toDateString();
        $awalBulan = now()->startOfMonth()->toDateString();
        $ringkas = fn (string $dari) => PenjualanMitra::where('status', 'berhasil')->whereDate('waktu', '>=', $dari)
            ->selectRaw('id_mitra, count(*) as jumlah, sum(total) as total')->groupBy('id_mitra')->get()->keyBy('id_mitra');

        return view('mitra-kantin.index', [
            'rows' => Mitra::withCount(['menu' => fn ($q) => $q->where('aktif', true)])->orderBy('status')->orderBy('nama')->get(),
            'hariIni' => $ringkas($hariIni),
            'bulanIni' => $ringkas($awalBulan),
            'saldo' => $this->saldo->saldoSemua(),
        ]);
    }

    public function create(): View
    {
        return view('mitra-kantin.form', ['mitra' => new Mitra(['status' => 'aktif'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $m = $this->s->simpanMitra($this->dataMitra($request), null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('mitra_kantin.show', $m)->with('status', "Mitra {$m->nama} ditambahkan. Lanjutkan dengan daftar menu dan akun mitra.");
    }

    public function show(Mitra $mitra): View
    {
        return view('mitra-kantin.show', [
            'mitra' => $mitra,
            'menu' => MenuMitra::where('id_mitra', $mitra->id)->orderByDesc('aktif')->orderBy('nama')->get(),
            'akun' => User::where('id_mitra', $mitra->id)->orderBy('username')->get(),
            'saldo' => $this->saldo->saldo($mitra->id),
        ]);
    }

    public function edit(Mitra $mitra): View
    {
        return view('mitra-kantin.form', ['mitra' => $mitra]);
    }

    public function update(Request $request, Mitra $mitra): RedirectResponse
    {
        try {
            $this->s->simpanMitra($this->dataMitra($request), $mitra, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('mitra_kantin.show', $mitra)->with('status', 'Data mitra disimpan.');
    }

    public function simpanMenu(Request $request, Mitra $mitra, ?MenuMitra $menu = null): RedirectResponse
    {
        $d = $request->validate([
            'nama' => ['required', 'string', 'max:100'], 'harga' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'aktif' => ['nullable', 'boolean'],
        ]);
        try {
            $this->s->simpanMenu($mitra, $d + ['aktif' => $request->boolean('aktif', true)], $menu);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('mitra_kantin.show', $mitra)->with('status', $menu ? 'Menu diperbarui.' : 'Menu ditambahkan.');
    }

    public function buatAkun(Request $request, Mitra $mitra): RedirectResponse
    {
        $d = $request->validate(['username' => ['required', 'string', 'max:50'], 'sandi' => ['required', 'string', 'min:8', 'max:100']]);
        try {
            $u = $this->s->buatAkun($mitra, $d['username'], $d['sandi'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput($request->except('sandi'))->with('error', $e->getMessage());
        }

        return redirect()->route('mitra_kantin.show', $mitra)->with('status', "Akun {$u->username} dibuat. Berikan username & kata sandinya kepada mitra; mitra masuk lewat halaman login biasa.");
    }

    public function ubahAkun(Request $request, Mitra $mitra, User $akun): RedirectResponse
    {
        $d = $request->validate(['sandi' => ['nullable', 'string', 'min:8', 'max:100'], 'status' => ['nullable', 'in:aktif,nonaktif']]);
        try {
            $this->s->ubahAkun($mitra, $akun, $d['sandi'] ?? null, $d['status'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('mitra_kantin.show', $mitra)->with('status', "Akun {$akun->username} diperbarui.");
    }

    public function laporan(Request $request): View
    {
        $dari = KasirMitraController::tanggal($request->query('dari'), now()->startOfMonth()->toDateString());
        $sampai = KasirMitraController::tanggal($request->query('sampai'), now()->toDateString());
        $idMitra = (int) $request->query('mitra') ?: null;

        return view('mitra-kantin.laporan', [
            'dari' => $dari, 'sampai' => $sampai, 'idMitra' => $idMitra,
            'mitraOptions' => Mitra::orderBy('nama')->pluck('nama', 'id')->all(),
            'lap' => $this->s->laporan($idMitra, $dari, $sampai),
        ]);
    }

    private function dataMitra(Request $request): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20'], 'nama' => ['required', 'string', 'max:100'],
            'pemilik' => ['nullable', 'string', 'max:100'], 'telepon' => ['nullable', 'string', 'max:30'],
            'bank_nama' => ['nullable', 'string', 'max:50'], 'bank_rekening' => ['nullable', 'string', 'max:40'],
            'bank_atas_nama' => ['nullable', 'string', 'max:100'],
            'sewa_mulai' => ['nullable', 'date'], 'sewa_sampai' => ['nullable', 'date', 'after_or_equal:sewa_mulai'],
            'keterangan_sewa' => ['nullable', 'string', 'max:2000'], 'status' => ['required', 'in:aktif,nonaktif'],
        ]);
    }
}
