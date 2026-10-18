<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\Bagian;
use App\Models\Inventory;
use App\Models\LokasiPersediaan;
use App\Models\TransferPersediaan;
use App\Services\Ledger\InventoryMovement;
use App\Services\Modules\TransferPersediaanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Transfer stok antar lokasi (kirim → terima → putus selisih).
 * Hak: transfer-persediaan buat = kirim, ubah = terima, hapus = batal;
 * memutus selisih = persetujuan-persediaan ubah. Logika: [[TransferPersediaanService]].
 */
class TransferPersediaanController extends Controller
{
    public function __construct(private readonly TransferPersediaanService $service) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');

        return view('transfer-persediaan.index', [
            'rows' => TransferPersediaan::with(['asal', 'tujuan', 'details'])
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->orderByDesc('tanggal_kirim')->orderByDesc('id_transfer')->paginate(25)->withQueryString(),
            'status' => $status,
            'menunggu' => TransferPersediaan::whereIn('status', ['dikirim', 'selisih'])->count(),
        ]);
    }

    public function create(): View
    {
        $items = Inventory::with('satuanLain')->where('status', 'aktif')->orderBy('nama_persediaan')->get();

        return view('transfer-persediaan.create', [
            'lokasiOptions' => LokasiPersediaan::pilihan(),
            'itemOpts' => $items->map(fn ($i) => [
                'v' => $i->kode_persediaan,
                'l' => "{$i->nama_persediaan} ({$i->satuan})",
                'satuan' => array_merge(
                    [['s' => $i->satuan, 'isi' => '1']],
                    $i->satuanLain->map(fn ($s) => ['s' => $s->satuan, 'isi' => rtrim(rtrim((string) $s->isi, '0'), '.')])->all(),
                ),
            ])->values()->all(),
            // Stok per lokasi — pemilih barang menampilkan stok di lokasi asal yang dipilih.
            'stok' => InventoryMovement::stokPerLokasi(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['details' => array_values(array_filter((array) $request->input('details', []),
            fn ($d) => ($d['kode_persediaan'] ?? '') !== '' && (float) ($d['kuantiti'] ?? 0) > 0))]);

        $data = $request->validate([
            'tanggal_kirim' => ['required', 'date'],
            'kode_lokasi_asal' => ['required', 'string', 'exists:lokasi_persediaan,kode'],
            'kode_lokasi_tujuan' => ['required', 'string', 'exists:lokasi_persediaan,kode', 'different:kode_lokasi_asal'],
            'keterangan' => ['nullable', 'string', 'max:500'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.kode_persediaan' => ['required', 'string', 'exists:inventory,kode_persediaan'],
            'details.*.satuan' => ['nullable', 'string', 'max:50'],
            'details.*.kuantiti' => ['required', 'numeric', 'gt:0'],
            'details.*.keterangan' => ['nullable', 'string', 'max:255'],
        ], [
            'details.required' => 'Belum ada barang yang dikirim.',
            'kode_lokasi_tujuan.different' => 'Lokasi tujuan tidak boleh sama dengan asal.',
        ]);

        try {
            $doc = $this->service->kirim($data, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('transfer_persediaan.show', $doc->id_transfer)
            ->with('status', "Transfer {$doc->nomor} dikirim — barang kini tercatat Dalam Perjalanan sampai diterima di tujuan.");
    }

    public function show(TransferPersediaan $transfer): View
    {
        $transfer->load(['details.persediaan', 'asal', 'tujuan', 'pengirim', 'penerima', 'pemutus']);

        return view('transfer-persediaan.show', [
            'doc' => $transfer,
            'bagianOptions' => ['' => '— pilih bagian —'] + Bagian::orderBy('nama_bagian')->pluck('nama_bagian', 'kode_bagian')->all(),
        ]);
    }

    public function terima(Request $request, TransferPersediaan $transfer): RedirectResponse
    {
        $data = $request->validate([
            'tanggal_terima' => ['required', 'date'],
            'diterima' => ['array'],
            'diterima.*' => ['nullable', 'numeric', 'min:0'],
            'catatan_terima' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $doc = $this->service->terima($transfer->id_transfer, $data['tanggal_terima'], $data['diterima'] ?? [],
                $data['catatan_terima'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $doc->status === 'selisih'
            ? "Diterima dengan selisih — sisanya tertahan Dalam Perjalanan sampai diputus atasan."
            : "Transfer {$doc->nomor} diterima utuh.");
    }

    public function putus(Request $request, TransferPersediaan $transfer): RedirectResponse
    {
        $data = $request->validate([
            'putusan' => ['required', Rule::in(['kembali', 'hilang'])],
            'kode_bagian' => ['nullable', 'required_if:putusan,hilang', 'string', 'exists:bagian,kode_bagian'],
            'catatan_putusan' => ['nullable', 'string', 'max:255'],
        ], ['kode_bagian.required_if' => 'Pilih Bagian yang menanggung kehilangan.']);

        try {
            $this->service->putus($transfer->id_transfer, $data['putusan'], $data['kode_bagian'] ?? null,
                $data['catatan_putusan'] ?? null, $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', $data['putusan'] === 'kembali'
            ? 'Selisih dikembalikan ke lokasi asal.'
            : 'Selisih dibukukan sebagai kehilangan.');
    }

    public function batal(Request $request, TransferPersediaan $transfer): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        try {
            $this->service->batal($transfer->id_transfer, $data['alasan'], $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Transfer dibatalkan — barang kembali ke lokasi asal.');
    }
}
