<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BarcodePersediaan;
use App\Models\CompanySettings;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\Penjualan;
use App\Models\ShiftKasir;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\KasirService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * LAYAR KASIR (/pos) — layout penuh sendiri, terpisah dari menu ERP.
 *
 * Kasir tanpa shift terbuka melihat layar Buka Shift; dengan shift terbuka, layar
 * berjualan. Pencarian & penyimpanan struk lewat JSON supaya pemindaian beruntun tak
 * memuat ulang halaman. Harga & stok SELALU dihitung ulang di server
 * ([[App\Services\Modules\KasirService]]) — angka di layar hanya pratinjau.
 */
class PosController extends Controller
{
    public function __construct(private readonly KasirService $kasir) {}

    public function index(Request $request): View
    {
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return view('pos.buka', [
                'tokoOptions' => LokasiPersediaan::where('status', 'aktif')->where('jenis', 'toko')
                    ->orderBy('urutan')->orderBy('kode')->get(),
            ]);
        }

        return view('pos.kasir', [
            'shift' => $shift->load('lokasi'),
            'ringkas' => $this->ringkas($shift),
            'pengawas' => $this->pengawas($request),
        ]);
    }

    public function bukaShift(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kode_lokasi' => ['required', 'string', 'exists:lokasi_persediaan,kode'],
            'modal_awal' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $shift = $this->kasir->bukaShift($data['kode_lokasi'], $data['modal_awal'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.index')->with('status', "Shift {$shift->nomor} dibuka. Selamat bertugas.");
    }

    /**
     * Cari barang untuk keranjang. `barcode` = pindaian (cocok persis, satuannya ikut
     * barcode); `q` = ketikan nama/kode. Hanya barang yang dijual di toko & berharga.
     */
    public function cari(Request $request): JsonResponse
    {
        $shift = $this->shiftTerbuka($request) ?? abort(409, 'Belum ada shift terbuka.');
        $barcode = trim((string) $request->query('barcode', ''));
        $q = trim((string) $request->query('q', ''));

        $satuanBarcode = null;
        if ($barcode !== '') {
            $b = BarcodePersediaan::find($barcode);
            $items = $b ? Inventory::whereKey($b->kode_persediaan)->get() : new EloquentCollection;
            $satuanBarcode = $b?->satuan;
        } elseif (mb_strlen($q) >= 2) {
            $items = Inventory::where(fn ($w) => $w->where('nama_persediaan', 'ilike', "%{$q}%")->orWhere('kode_persediaan', 'ilike', "%{$q}%"))
                ->orderBy('nama_persediaan')->limit(20)->get();
        } else {
            $items = new EloquentCollection;
        }

        // Harga Rp 0 = belum diisi → tak muncul (dan ditolak server bila dikirim juga).
        $items = $items->filter(fn ($i) => $i->status === 'aktif' && $i->dijual_di_toko && (float) $i->harga_jual > 0)->values();
        $stok = InventoryMovement::stokPerLokasi($items->pluck('kode_persediaan')->all());
        $items->load('satuanLain');

        return response()->json([
            'items' => $items->map(fn ($i) => [
                'kode' => $i->kode_persediaan,
                'nama' => $i->nama_persediaan,
                'stok' => (float) ($stok[$i->kode_persediaan][$shift->kode_lokasi] ?? 0),
                'satuan_dasar' => $i->satuan,
                'satuan_dipilih' => $satuanBarcode ?? $i->satuan,
                'barcode' => $barcode !== '' ? $barcode : null,
                'opsi' => collect([[$i->satuan, '1']])
                    ->merge($i->satuanLain->map(fn ($s) => [$s->satuan, Money::of($s->isi, 4)]))
                    ->map(fn ($s) => ['satuan' => $s[0], 'isi' => (float) $s[1],
                        'harga' => (float) KasirService::hargaJual($i, $s[0], $s[1]),
                        'normal' => (float) KasirService::hargaNormal($i, $s[0], $s[1])])
                    ->values()->all(),
            ])->all(),
        ]);
    }

    public function jual(Request $request): JsonResponse
    {
        $data = $request->validate([
            'baris' => ['required', 'array', 'min:1'],
            'baris.*.kode_persediaan' => ['required', 'string'],
            'baris.*.satuan' => ['nullable', 'string', 'max:50'],
            'baris.*.barcode' => ['nullable', 'string', 'max:64'],
            'baris.*.kuantiti' => ['required', 'numeric', 'gt:0'],
            'baris.*.diskon' => ['nullable', 'numeric', 'min:0'],
            'bayar' => ['required', 'numeric', 'min:0'],
            'diskon_struk' => ['nullable', 'numeric', 'min:0'],
            'alasan_diskon' => ['nullable', 'string', 'max:255'],
            'id_pengawas' => ['nullable', 'integer'],
            'pin' => ['nullable', 'string', 'max:6'],
            // Bayar kartu: UID dari pembaca RFID + PIN belanja santri (bila melewati batas harian).
            'kartu_uid' => ['nullable', 'string', 'max:80'],
            'pin_santri' => ['nullable', 'string', 'max:6'],
            // Lanjutan "Cek lagi": niat bayar kartu yang sudah dipastikan terpotong — struk disimpan tanpa memotong lagi.
            'permintaan_kartu' => ['nullable', 'integer'],
            // Kunci acak per keranjang dari layar — kiriman ulang sesudah koneksi putus tak menggandakan struk.
            'kunci' => ['nullable', 'string', 'max:64'],
        ]);
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return response()->json(['message' => 'Shift sudah ditutup — muat ulang halaman.'], 409);
        }

        // Barcode hanya catatan baris; barang & satuannya sudah ditentukan saat dipindai.
        $baris = array_map(fn ($b) => [
            'kode_persediaan' => $b['kode_persediaan'], 'satuan' => $b['satuan'] ?? null, 'kuantiti' => $b['kuantiti'],
            'diskon' => $b['diskon'] ?? 0,
        ], $data['baris']);

        try {
            $jual = $this->kasir->jual($shift->id_shift, $baris, $data['bayar'], $request->user()->id_pengguna, [
                'struk' => $data['diskon_struk'] ?? 0,
                'alasan' => $data['alasan_diskon'] ?? null,
                'id_pengawas' => $data['id_pengawas'] ?? null,
                'pin' => $data['pin'] ?? null,
            ], ($data['kartu_uid'] ?? '') !== '' ? ['uid' => $data['kartu_uid'], 'pin' => $data['pin_santri'] ?? null,
                'permintaan' => $data['permintaan_kartu'] ?? null] : null, $data['kunci'] ?? null);
        } catch (\App\Services\Penghubung\PermintaanKartuTertunda $e) {
            // Kartu belum pasti / sudah terpotong tanpa struk: layar menahan keranjang & menawarkan "Cek lagi".
            return response()->json(['message' => $e->getMessage(), 'kode' => $e->status, 'tertunda' => $e->idPermintaan], 422);
        } catch (AppException $e) {
            // 428/423 dibawa ke layar: kasir perlu tahu bedanya "minta PIN" dengan "PIN terkunci".
            return response()->json(['message' => $e->getMessage(), 'kode' => $e->status], 422);
        }

        return response()->json([
            'nomor' => $jual->nomor,
            'total' => (float) $jual->total,
            'bayar' => (float) $jual->bayar,
            'kembalian' => (float) $jual->kembalian,
            'struk' => route('pos.struk', ['penjualan' => $jual->id_penjualan, 'cetak' => 1]),
            'ringkas' => $this->ringkas($shift),
        ]);
    }

    /** "Cek lagi" atas pembayaran kartu yang belum pasti: {status: berhasil|gagal|belum_pasti, sudah_struk}. */
    public function pastikanKartu(Request $request, int $permintaan): JsonResponse
    {
        return response()->json($this->kasir->cekPermintaan($permintaan, $request->user()->id_pengguna));
    }

    /** Struk 58 mm. Kasir hanya bisa membuka struk shift-nya sendiri; pemegang hak lihat lain (admin) semua. */
    public function struk(Request $request, Penjualan $penjualan): View
    {
        $penjualan->load(['details.persediaan', 'kasir', 'shift.lokasi', 'penyetuju']);
        abort_unless($penjualan->id_kasir === $request->user()->id_pengguna || $request->user()->is_admin, 403);

        return view('pos.struk', [
            'jual' => $penjualan->load('santri:id,nama'),
            // Sisa uang saku sesudah belanja — dicetak untuk santrinya sendiri.
            // Kartu lewat penghubung: sisa saldo snapshot jawaban ERP Al Wafi.
            'sisaSaldo' => $penjualan->id_mutasi_dompet
                ? \App\Models\MutasiDompet::whereKey($penjualan->id_mutasi_dompet)->value('saldo_setelah')
                : $penjualan->sisa_saldo_kartu,
            'perusahaan' => CompanySettings::find(1),
            'cetak' => $request->boolean('cetak'),
        ]);
    }

    public function riwayat(Request $request): View
    {
        $shift = $this->shiftTerbuka($request);

        return view('pos.riwayat', [
            'shift' => $shift?->load('lokasi'),
            'rows' => $shift ? Penjualan::where('id_shift', $shift->id_shift)->orderByDesc('id_penjualan')->paginate(30) : collect(),
        ]);
    }

    /**
     * Tutup shift — HITUNG BUTA: kasir tak melihat berapa seharusnya isi laci sebelum
     * menghitung; angka yang terlihat menggoda untuk "dicocokkan" alih-alih dihitung.
     * Selisihnya baru tampil di laporan sesudah ditutup.
     */
    public function formTutup(Request $request): View|RedirectResponse
    {
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return redirect()->route('pos.index')->with('error', 'Tidak ada shift terbuka.');
        }

        return view('pos.tutup', ['shift' => $shift->load('lokasi'), 'ringkas' => $this->ringkas($shift)]);
    }

    public function tutup(Request $request): RedirectResponse
    {
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return redirect()->route('pos.index')->with('error', 'Tidak ada shift terbuka.');
        }
        $data = $request->validate([
            'pecahan' => ['required', 'array'],
            'pecahan.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);
        $pecahan = array_filter(array_map('intval', $data['pecahan']));

        try {
            // Laci kosong sama sekali tetap sah — dicatat sebagai uang dihitung nol.
            $tutup = $this->kasir->tutupShift($shift->id_shift, $pecahan ?: null, $pecahan ? null : 0,
                $data['catatan'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('pos.laporan', $tutup->id_shift)->with('status', "Shift {$tutup->nomor} ditutup. Serahkan uang laci ke bagian keuangan.");
    }

    /** Laporan tutup shift (bisa dicetak) — milik kasirnya sendiri atau pengawas kasir. */
    public function laporan(Request $request, ShiftKasir $shift): View
    {
        $milikSendiri = $shift->id_kasir === $request->user()->id_pengguna && \App\Support\Akses::boleh('pos', 'lihat');
        abort_unless($milikSendiri || \App\Support\Akses::boleh('shift-kasir', 'lihat'), 403);

        return view('pos.laporan', ['shift' => $shift->load(['lokasi', 'kasir', 'penerima'])]);
    }

    /**
     * Kartu ditempel — siapa pemiliknya & perlukah PIN untuk total ini. SALDO SENGAJA tak
     * dikirim ke layar kasir (bukan urusan kasir); yang kurang cukup ditolak saat bayar.
     */
    public function kartu(Request $request): JsonResponse
    {
        abort_unless($this->shiftTerbuka($request), 409, 'Belum ada shift terbuka.');
        if (KasirService::lewatPenghubung()) {
            // Instans Wafi Mart: tanya ERP Al Wafi; bentuk jawabannya disamakan dengan mode lokal.
            try {
                $h = app(\App\Services\Penghubung\KlienDompetToko::class)
                    ->cekKartu((string) $request->query('uid'), Money::of($request->query('total', 0)));
            } catch (\App\Exceptions\AppException $e) {
                return response()->json(['message' => $e->getMessage()], $e->status);
            }

            return response()->json([
                'nama' => $h['nama'] ?? null, 'nis' => $h['nis'] ?? null,
                'sisa_batas' => (float) ($h['sisa_batas'] ?? 0),
                'perlu_pin' => (bool) ($h['perlu_pin'] ?? false), 'punya_pin' => (bool) ($h['punya_pin'] ?? false),
            ]);
        }
        $k = (new \App\Services\Modules\KartuSantriService)->cariAktif((string) $request->query('uid'));
        if (! $k || $k->santri->status !== 'aktif') {
            return response()->json(['message' => 'Kartu tidak dikenal, diblokir, atau bukan santri aktif.'], 404);
        }
        $total = Money::of($request->query('total', 0));
        $pakai = KasirService::belanjaKartuHariIni($k->santri->id);
        $batas = \App\Services\Modules\KartuSantriService::batasHarian($k->santri->id);

        return response()->json([
            'nama' => $k->santri->nama,
            'nis' => $k->santri->nis,
            'sisa_batas' => (float) max(0, (float) Money::sub($batas, $pakai)),
            'perlu_pin' => Money::gt(Money::add($pakai, $total), $batas),
            'punya_pin' => \App\Services\Modules\KartuSantriService::punyaPin($k->santri->id),
        ]);
    }

    /** Layar retur: cari struk asal, lalu pilih barang & kondisinya. */
    public function formRetur(Request $request): View|RedirectResponse
    {
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return redirect()->route('pos.index')->with('error', 'Buka shift dulu — uang retur keluar dari laci shift yang sedang buka.');
        }
        $nomor = trim((string) $request->query('struk', ''));
        $jual = $nomor !== '' ? Penjualan::with('details.persediaan', 'kasir')->where('nomor', $nomor)->first() : null;

        $sudah = $jual ? \App\Models\ReturPenjualanDetail::whereIn('id_penjualan_detail', $jual->details->pluck('id'))
            ->groupBy('id_penjualan_detail')->selectRaw('id_penjualan_detail, SUM(kuantiti_input) AS qty')
            ->pluck('qty', 'id_penjualan_detail')->all() : [];

        return view('pos.retur', [
            'shift' => $shift->load('lokasi'),
            'nomor' => $nomor,
            'jual' => $jual,
            'sudah' => $sudah,
            'pengawas' => $this->pengawas($request),
        ]);
    }

    public function retur(Request $request): RedirectResponse
    {
        $shift = $this->shiftTerbuka($request);
        if (! $shift) {
            return redirect()->route('pos.index')->with('error', 'Tidak ada shift terbuka.');
        }
        $data = $request->validate([
            'nomor_struk' => ['required', 'string'],
            'baris' => ['required', 'array'],
            'baris.*.id_penjualan_detail' => ['required', 'integer'],
            'baris.*.kuantiti' => ['nullable', 'numeric', 'min:0'],
            // Baris yang sudah habis diretur terkirim tanpa jumlah & kondisi (isiannya dimatikan).
            'baris.*.kondisi' => ['nullable', 'in:layak,rusak'],
            'alasan' => ['required', 'string', 'max:255'],
            'id_pengawas' => ['required', 'integer'],
            'pin' => ['required', 'string', 'max:6'],
        ]);

        try {
            $retur = $this->kasir->retur($shift->id_shift, $data['nomor_struk'], array_values($data['baris']), $data['alasan'],
                (int) $data['id_pengawas'], $data['pin'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput($request->except('pin'))->with('error', $e->getMessage());
        }

        return redirect()->route('pos.index')->with('status',
            "Retur {$retur->nomor} tersimpan — kembalikan uang Rp ".number_format((float) $retur->total, 0, ',', '.').' ke pembeli.')
            ->with('cetak_retur', route('pos.struk_retur', ['retur' => $retur->id_retur, 'cetak' => 1]));
    }

    public function strukRetur(Request $request, \App\Models\ReturPenjualan $retur): View
    {
        $retur->load(['details.persediaan', 'details.baris', 'penjualan', 'kasir', 'penyetuju', 'shift.lokasi']);
        abort_unless($retur->id_kasir === $request->user()->id_pengguna || \App\Support\Akses::boleh('shift-kasir', 'lihat'), 403);

        return view('pos.struk-retur', ['retur' => $retur, 'perusahaan' => CompanySettings::find(1), 'cetak' => $request->boolean('cetak')]);
    }

    /**
     * Pengawas yang bisa menyetujui di layar ini: sudah memasang PIN, masih berhak
     * shift-kasir/ubah, dan bukan kasirnya sendiri.
     *
     * @return list<array{id:int,nama:string}>
     */
    private function pengawas(Request $request): array
    {
        return \App\Models\User::whereIn('id_pengguna', \App\Models\PinPengawas::pluck('id_pengguna'))
            ->where('id_pengguna', '!=', $request->user()->id_pengguna)
            ->where('status', 'aktif')->orderBy('nama')->get()
            ->filter(fn ($u) => \App\Support\Akses::bolehUntuk($u, 'shift-kasir', 'ubah'))
            ->map(fn ($u) => ['id' => $u->id_pengguna, 'nama' => $u->nama])->values()->all();
    }

    private function shiftTerbuka(Request $request): ?ShiftKasir
    {
        return ShiftKasir::where('id_kasir', $request->user()->id_pengguna)->where('status', 'buka')->first();
    }

    /** Angka berjalan shift untuk kepala layar kasir. */
    private function ringkas(ShiftKasir $shift): array
    {
        $jual = Penjualan::where('id_shift', $shift->id_shift)->where('status', 'selesai');

        return ['struk' => (clone $jual)->count(), 'omzet' => (float) (clone $jual)->sum('total')];
    }
}
