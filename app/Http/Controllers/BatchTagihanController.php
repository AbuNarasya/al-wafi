<?php

namespace App\Http\Controllers;

use App\Exceptions\AppException;
use App\Models\BatchTagihan;
use App\Models\JenisBiaya;
use App\Models\Jenjang;
use App\Models\TahunAjaran;
use App\Services\Modules\BatchTagihanService;
use App\Services\Modules\PengingatTerbitService;
use App\Support\Akses;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * BATCH TAGIHAN — susun draft, otorisasi, rilis.
 *
 * ══ HAK AKSES BERLAPIS, DAN INI DISENGAJA ══
 * `batch-tagihan` hanya membuka LAYARNYA. Untuk menyusun & merilis batch sebuah
 * modul, pengguna tetap harus memegang hak modul itu sendiri (spp /
 * tagihan-massal / tagihan-lain). Tanpa lapis kedua ini, memberi seseorang hak
 * `batch-tagihan` diam-diam memberinya kuasa menerbitkan SPP seluruh pesantren
 * — kuasa yang tak pernah diputuskan siapa pun.
 */
class BatchTagihanController extends Controller
{
    /** Modul batch → modul hak akses yang sebenarnya menerbitkan. */
    private const HAK = [
        'spp' => ['spp', 'ubah'],
        'daftar_ulang' => ['tagihan-massal', 'buat'],
        'tagihan_lain' => ['tagihan-lain', 'buat'],
    ];

    public function __construct(private readonly BatchTagihanService $service) {}

    public function index(Request $request): View
    {
        // Pemicu cadangan: kalau cron mati diam-diam, batch yang jatuh tempo
        // tetap terbit begitu ada yang membuka halaman ini. Murah — sekali kueri
        // berindeks saat tak ada pekerjaan. Lihat BatchTagihanService.
        $this->service->pemicuCadangan();

        $status = (string) $request->query('status', '');

        $daftar = BatchTagihan::with(['penyusun:id_pengguna,nama', 'pengotorisasi:id_pengguna,nama'])
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'diotorisasi' THEN 0 WHEN 'draft' THEN 1 ELSE 2 END")
            ->orderByDesc('id')
            ->paginate(25)->withQueryString();

        return view('batch-tagihan.index', [
            'daftar' => $daftar,
            'status' => $status,
            // Pengingat yang masih menunggu orang ini — tugasnya dimunculkan di
            // halaman tempat pekerjaannya dilakukan, bukan hanya di lonceng.
            'pengingat' => (new PengingatTerbitService)->terbukaUntuk((int) $request->user()->id_pengguna),
            'bolehSusun' => array_keys(array_filter(
                self::HAK, fn ($h) => Akses::boleh($h[0], $h[1]),
            )),
        ]);
    }

    public function susun(): View
    {
        return view('batch-tagihan.susun', $this->opsi());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'modul' => ['required', 'string', 'in:spp,daftar_ulang,tagihan_lain'],
            'judul' => ['nullable', 'string', 'max:200'],
            'rilis_tanggal' => ['nullable', 'date'],
            'rilis_jam' => ['nullable', 'date_format:H:i'],

            // Kewajiban isian ditegakkan DI SINI, bukan di service: service
            // dipanggil juga oleh perilis terjadwal, yang tak punya form.
            'periode' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'tahun_ajaran' => ['nullable', 'string', 'exists:tahun_ajaran,kode'],
            'kode_jenjang' => ['nullable', 'string', 'exists:jenjang,kode'],
            'angkatan' => ['nullable', 'string', 'exists:tahun_ajaran,kode'],
            'tingkat' => ['nullable', 'integer', 'min:1'],
            'kode_jenis' => ['nullable', 'string', 'exists:jenis_biaya,kode'],
            'sumber' => ['nullable', 'string', 'in:manual,peserta,pemakaian'],
            'nominal' => ['nullable', 'numeric', 'gt:0'],
            'id_santri' => ['nullable', 'array'],
            'id_santri.*' => ['integer'],
            'jatuh_tempo' => ['nullable', 'date'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'alasan_lintas_ta' => ['nullable', 'string', 'max:255'],
        ]);

        $this->pastikanBerhak($data['modul']);

        // Kewajiban yang berbeda-beda per modul. Ditegakkan di controller supaya
        // pesannya sampai ke form, bukan jadi galat 422 di tengah penyusunan.
        $wajib = match ($data['modul']) {
            'spp' => ['periode' => 'Periode SPP'],
            'daftar_ulang' => ['tahun_ajaran' => 'Tahun ajaran tagihan', 'kode_jenjang' => 'Jenjang'],
            'tagihan_lain' => ['kode_jenis' => 'Jenis biaya', 'sumber' => 'Sumber peserta'],
        };
        foreach ($wajib as $kunci => $label) {
            if (($data[$kunci] ?? '') === '' || ($data[$kunci] ?? null) === null) {
                return back()->withInput()->with('error', "{$label} wajib dipilih.");
            }
        }

        $rilis = $this->gabungWaktuRilis($data);
        if ($rilis === false) {
            return back()->withInput()->with('error', 'Tanggal rilis wajib diisi bila jamnya diisi. '
                .'Kosongkan keduanya bila batch ini hanya akan dirilis dengan tombol.');
        }

        try {
            $batch = $this->service->susun([
                'modul' => $data['modul'],
                'judul' => $data['judul'] ?? null,
                'rilis_pada' => $rilis,
                'parameter' => array_filter([
                    'periode' => $data['periode'] ?? null,
                    'tahun_ajaran' => $data['tahun_ajaran'] ?? null,
                    'kode_jenjang' => $data['kode_jenjang'] ?? null,
                    'angkatan' => $data['angkatan'] ?? null,
                    'tingkat' => $data['tingkat'] ?? null,
                    'kode_jenis' => $data['kode_jenis'] ?? null,
                    'sumber' => $data['sumber'] ?? null,
                    'nominal' => $data['nominal'] ?? null,
                    'id_santri' => $data['id_santri'] ?? null,
                    'jatuh_tempo' => $data['jatuh_tempo'] ?? null,
                    'keterangan' => $data['keterangan'] ?? null,
                    'alasan_lintas_ta' => $data['alasan_lintas_ta'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''),
            ], (int) $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('batch_tagihan.show', $batch->id)
            ->with('status', 'Draft tersusun. Periksa barisnya, lalu otorisasi bila sudah benar.');
    }

    public function show(int $id): View
    {
        $batch = $this->service->cari($id);
        $batch->load(['penyusun:id_pengguna,nama', 'pengotorisasi:id_pengguna,nama', 'perilis:id_pengguna,nama']);

        return view('batch-tagihan.show', [
            'batch' => $batch,
            'baris' => $batch->baris()->with('santri:id,nis,nama,kode_jenjang,status')
                ->orderByRaw("CASE keputusan WHEN 'terbit' THEN 0 ELSE 1 END")
                ->orderBy('id')->paginate(50),
            'berhak' => $this->berhak($batch->modul),
        ]);
    }

    public function otorisasi(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'rilis_tanggal' => ['nullable', 'date'],
            'rilis_jam' => ['nullable', 'date_format:H:i'],
        ]);
        $batch = $this->service->cari($id);
        $this->pastikanBerhak($batch->modul);

        $rilis = $this->gabungWaktuRilis($data);
        if ($rilis === false) {
            return back()->with('error', 'Tanggal rilis wajib diisi bila jamnya diisi.');
        }

        // Kuncinya dikirim HANYA bila formnya memang memuat isian itu — service
        // membedakan "tak disebut" (pertahankan yang lama) dari "dikosongkan"
        // (hapus jadwalnya).
        $kiriman = $request->has('rilis_tanggal') ? ['rilis_pada' => $rilis] : [];

        try {
            $this->service->otorisasi($id, $kiriman, (int) $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $rilis !== null
            ? 'Batch diotorisasi. Ia akan terbit sendiri pada waktu yang Anda tetapkan.'
            : 'Batch diotorisasi. Tekan Rilis bila sudah waktunya diterbitkan.');
    }

    public function rilis(Request $request, int $id): RedirectResponse
    {
        $batch = $this->service->cari($id);
        $this->pastikanBerhak($batch->modul);

        try {
            $hasil = $this->service->rilis($id, (int) $request->user()->id_pengguna);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($hasil['status'] === 'gagal' ? 'error' : 'status', $hasil['pesan']);
    }

    public function batalkan(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['nullable', 'string', 'max:255']]);
        $batch = $this->service->cari($id);
        $this->pastikanBerhak($batch->modul);

        try {
            $this->service->batalkan($id, (int) $request->user()->id_pengguna, $data['alasan'] ?? null);
        } catch (AppException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('batch_tagihan.index')->with('status', 'Batch dibatalkan.');
    }

    /**
     * Gabungkan isian tanggal & jam jadi satu waktu rilis.
     *
     * Keduanya dipisah di layar karena `datetime-local` bawaan peramban sulit
     * dipakai — kolom jam & menitnya tak selalu bisa diubah — sedangkan
     * `type="date"` dan `type="time"` sudah terbukti di seluruh aplikasi ini.
     *
     * @return string|null|false  false = jam diisi tanpa tanggal
     */
    private function gabungWaktuRilis(array $data): string|null|false
    {
        $tanggal = trim((string) ($data['rilis_tanggal'] ?? ''));
        $jam = trim((string) ($data['rilis_jam'] ?? ''));

        if ($tanggal === '') {
            // Jam bawaan form adalah 00:00, jadi jam TANPA tanggal hanya berarti
            // "tak dijadwalkan" — bukan kesalahan. Yang ditolak adalah jam lain
            // yang sengaja diketik tetapi tanggalnya lupa diisi.
            return ($jam === '' || $jam === '00:00') ? null : false;
        }

        return $tanggal.' '.($jam !== '' ? $jam : '00:00');
    }

    private function berhak(string $modul): bool
    {
        [$kode, $aksi] = self::HAK[$modul];

        return Akses::boleh($kode, $aksi);
    }

    private function pastikanBerhak(string $modul): void
    {
        if (! $this->berhak($modul)) {
            $label = BatchTagihan::MODUL[$modul] ?? $modul;
            abort(403, "Anda belum berhak menerbitkan {$label}, jadi batch-nya pun tak bisa Anda jalankan.");
        }
    }

    private function opsi(): array
    {
        return [
            'opsiTa' => TahunAjaran::orderByDesc('kode')->pluck('kode', 'kode')->all(),
            'opsiJenjang' => Jenjang::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all(),
            // Hanya jenis berperilaku `lain` — tiga modul lain punya jalurnya sendiri.
            'opsiJenisLain' => JenisBiaya::where('status', 'aktif')
                ->whereIn('tipe', \App\Models\TipeBiaya::where('perilaku', 'lain')->pluck('kode'))
                ->orderBy('nama')->get(['kode', 'nama', 'cara_tagih']),
            'bolehSusun' => array_keys(array_filter(
                self::HAK, fn ($h) => Akses::boleh($h[0], $h[1]),
            )),
        ];
    }
}
