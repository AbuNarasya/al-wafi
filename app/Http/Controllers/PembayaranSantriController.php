<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BankAccount;
use App\Models\CompanySettings;
use App\Models\DompetWali;
use App\Models\PembayaranSantri;
use App\Models\TagihanSantri;
use App\Services\Modules\PembayaranSantriService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Pembayaran Santri — dipakai dua modul: PPSB (registrasi & uang pangkal) dan
 * Kesantrian (SPP & tagihan lain), dibedakan `lingkup`. Alur: catat (PPSB/
 * Kesantrian) → verifikasi keuangan (jurnal terbit). Controller tipis → service.
 */
class PembayaranSantriController extends Controller
{
    /**
     * Lingkup modul dipilah menurut PERILAKU tipe biaya, bukan nama tipenya —
     * tipe baru yang dibuat lewat master Tipe Biaya ikut masuk ke lingkup yang
     * benar tanpa menyentuh kode ini lagi.
     *
     * @return list<string> kode tipe
     */
    private function tipe(string $lingkup): array
    {
        return $lingkup === 'ppsb'
            ? \App\Models\TipeBiaya::kodeBerperilaku('registrasi', 'uang_pangkal', 'perlengkapan')
            : \App\Models\TipeBiaya::kodeBerperilaku('spp', 'lain');
    }

    public function __construct(private readonly PembayaranSantriService $service) {}

    private function rt(string $lingkup): string
    {
        return $lingkup === 'ppsb' ? 'pembayaran_ppsb' : 'pembayaran_kesantrian';
    }

    public function index(Request $request, string $lingkup): View
    {
        $tipes = $this->tipe($lingkup);
        $cari = trim((string) $request->query('q', ''));
        $fStatus = trim((string) $request->query('status', ''));

        $rows = PembayaranSantri::query()->with(['santri', 'tagihan.jenis'])
            ->whereHas('tagihan.jenis', fn ($q) => $q->whereIn('tipe', $tipes))
            ->when($cari !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('nomor', 'ilike', "%{$cari}%")
                ->orWhereHas('santri', fn ($s) => $s->where('nama', 'ilike', "%{$cari}%")
                    ->orWhere('no_pendaftaran', 'ilike', "%{$cari}%")->orWhere('nis', 'ilike', "%{$cari}%")),
            ))
            ->when($fStatus !== '', fn ($query) => $query->where('status', $fStatus))
            ->orderByDesc('id')->paginate(25)->withQueryString();

        // "Bayar dari Dompet Wali" hanya untuk Kesantrian (santri aktif punya dompet).
        // Daftar tagihannya LINTAS lingkup: syaratnya santri aktif & walinya punya
        // Dompet Wali — bukan tipe tagihannya (port perilaku tagihan-dompet dev).
        $bolehDompet = $lingkup === 'kesantrian';
        $tagihanDompet = $bolehDompet ? $this->tagihanDompet() : collect();

        return view('pembayaran-santri.index', [
            'rows' => $rows,
            'lingkup' => $lingkup,
            'bolehDompet' => $bolehDompet,
            'tagihanDompet' => $tagihanDompet,
            'q' => $cari,
            'filter' => ['status' => $fStatus],
            'opsiStatus' => [
                'menunggu_verifikasi' => 'Menunggu Verifikasi', 'terverifikasi' => 'Terverifikasi',
                'ditolak' => 'Ditolak', 'void' => 'Void',
            ],
        ]);
    }

    /** Tagihan yang bisa dibayar dari Dompet Wali: santri aktif + wali punya dompet. */
    private function tagihanDompet(): \Illuminate\Support\Collection
    {
        $waliBerdompet = DompetWali::pluck('id_wali')->all();

        return TagihanSantri::query()->with(['santri', 'jenis'])
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->whereHas('santri', fn ($q) => $q->where('status', 'aktif')->whereIn('id_wali', $waliBerdompet))
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'label' => "{$t->santri?->nama} — {$t->jenis?->nama} · sisa Rp " . number_format((float) $t->sisa, 0, ',', '.'),
                'sisa' => (float) $t->sisa,
            ])->values();
    }

    public function create(Request $request, string $lingkup): View
    {
        $tipes = $this->tipe($lingkup);
        $tagihan = TagihanSantri::query()->with(['santri', 'jenis'])
            ->whereIn('status', ['belum_bayar', 'sebagian'])
            ->whereHas('jenis', fn ($q) => $q->whereIn('tipe', $tipes))
            ->get();

        // Kelompokkan tagihan per santri untuk selector Alpine. No. pendaftaran &
        // NIS ikut dikirim: nama saja tak cukup membedakan santri yang namanya
        // mirip, dan petugas biasanya memegang nomor pendaftarannya.
        $santriData = $tagihan->groupBy('id_santri')->map(fn ($items) => [
            'id' => $items->first()->id_santri,
            'nama' => $items->first()->santri?->nama,
            'no_pendaftaran' => $items->first()->santri?->no_pendaftaran,
            'nis' => $items->first()->santri?->nis,
            'jenjang' => $items->first()->santri?->kode_jenjang,
            'tagihan' => $items->map(fn ($t) => [
                'id' => $t->id, 'label' => "{$t->jenis?->nama} — sisa Rp " . number_format((float) $t->sisa, 0, ',', '.'), 'sisa' => (float) $t->sisa,
            ])->values(),
        ])->values();

        return view('pembayaran-santri.create', [
            'lingkup' => $lingkup,
            'santriData' => $santriData,
            'rekeningOptions' => ['' => '— pilih —'] + BankAccount::where('status', 'aktif')->orderBy('kode_coa')->get()
                ->mapWithKeys(fn ($r) => [$r->kode_coa => "{$r->nama_rekening} ({$r->kode_coa})"])->all(),
        ]);
    }

    public function store(Request $request, string $lingkup): RedirectResponse
    {
        $data = $request->validate([
            'id_santri' => ['required', 'integer', 'exists:santri,id'],
            'id_tagihan' => ['required', 'integer', 'exists:tagihan_santri,id'],
            'tanggal' => ['required', 'date'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'kode_rekening' => ['required', 'string', 'exists:bank_accounts,kode_coa'],
            'metode' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'string'],
            // Bukti transfer (opsional) — diperiksa tim keuangan sebelum verifikasi.
            'bukti' => ['nullable', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);
        $data['id_santri'] = (int) $data['id_santri'];
        $data['id_tagihan'] = (int) $data['id_tagihan'];

        if ($request->hasFile('bukti')) {
            $data['bukti_path'] = $request->file('bukti')->store('pembayaran-bukti', 'local');
        }

        try {
            $this->service->catat($data, $request->user()->id_pengguna, $lingkup);
        } catch (AppException $e) {
            // Bersihkan berkas yang telanjur terunggah bila pencatatan gagal.
            if (! empty($data['bukti_path'])) {
                Storage::disk('local')->delete($data['bukti_path']);
            }

            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route($this->rt($lingkup) . '.index')->with('status', 'Pembayaran dicatat, menunggu verifikasi keuangan.');
    }

    /** Bayar tagihan langsung dari Dompet Wali (berlaku seketika, tanpa verifikasi). */
    public function bayarDompet(Request $request, string $lingkup): RedirectResponse
    {
        $data = $request->validate([
            'id_tagihan' => ['required', 'integer', 'exists:tagihan_santri,id'],
            'tanggal' => ['required', 'date'],
            'nominal' => ['required', 'numeric', 'gt:0'],
            'catatan' => ['nullable', 'string'],
        ]);
        $data['id_tagihan'] = (int) $data['id_tagihan'];

        try {
            $this->service->bayarDariDompet($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->rt($lingkup) . '.index')->with('status', 'Tagihan dibayar dari Dompet Wali.');
    }

    /**
     * Sajikan berkas bukti transfer INLINE (bila ada).
     * Urutan param HARUS ikut route (`{id}` dulu, `lingkup` dari defaults) —
     * binding controller bersifat POSISIONAL; menukar urutan menukar nilainya.
     */
    public function bukti(string $id, string $lingkup)
    {
        $pembayaran = PembayaranSantri::findOrFail((int) $id);
        abort_if(! $pembayaran->bukti_path || ! Storage::disk('local')->exists($pembayaran->bukti_path), 404);

        return Storage::disk('local')->response($pembayaran->bukti_path);
    }

    /**
     * Kuitansi pembayaran — HANYA untuk pembayaran terverifikasi (uang sudah
     * dipastikan masuk & jurnal terposting), agar tak ada bukti bayar "sakti".
     * Urutan param ikut route (`{id}` dulu, `lingkup` dari defaults).
     */
    public function kuitansi(string $id, string $lingkup): View
    {
        $pembayaran = PembayaranSantri::with([
            'santri.wali', 'tagihan.jenis', 'pencatat', 'pemverifikasi', 'rekening',
        ])->findOrFail((int) $id);

        abort_unless($pembayaran->status === 'terverifikasi', 403, 'Kuitansi hanya bisa dicetak untuk pembayaran yang sudah diverifikasi keuangan.');

        return view('pembayaran-santri.kuitansi', [
            'p' => $pembayaran,
            'lingkup' => $lingkup,
            'company' => CompanySettings::find(1),
        ]);
    }

    public function verifikasi(Request $request, string $id, string $lingkup): RedirectResponse
    {
        try {
            $this->service->verifikasi((int) $id, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->rt($lingkup) . '.index')->with('status', 'Pembayaran diverifikasi & jurnal diposting.');
    }

    public function tolak(Request $request, string $id, string $lingkup): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->tolak((int) $id, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route($this->rt($lingkup) . '.index')->with('status', 'Pembayaran ditolak.');
    }
}
