<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\DokumenSantri;
use App\Models\Santri;
use App\Models\StatusBerkasSantri;
use Illuminate\Support\Facades\DB;
use App\Services\Ppsb\DokumenPolicy;
use App\Services\Ppsb\Tahap;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Dokumen santri — metadata di DB, isi berkas di FileStorage (disk lokal di luar
 * webroot). Tahap diturunkan dari jenis. Baris & berkas dibuang bersama saat hapus.
 */
class DokumenSantriService
{
    private const DISK = 'local';
    private const DIR = 'dokumen-santri';

    public function list(int $idSantri)
    {
        return DokumenSantri::where('id_santri', $idSantri)
            ->orderBy('tahap')->orderBy('jenis')->orderByDesc('created_at')->get();
    }

    public function get(int $id): DokumenSantri
    {
        $row = DokumenSantri::find($id);
        if (! $row) {
            throw new AppException(404, 'Dokumen tidak ditemukan.');
        }

        return $row;
    }

    /**
     * Unggah berkas + catat metadata. Tahap diturunkan dari jenis.
     *
     * Pengunggahnya STAF ($idPengguna) atau WALI ($wali = [id_wali, peran]; Portal Wali,
     * 15 Okt 2026). Unggahan staf langsung dianggap DITERIMA — staf memegang berkasnya.
     * Unggahan wali menunggu diperiksa; berkas yang sudah diterima tak bisa ditimpa wali.
     *
     * @param  array{id_wali:int, peran:string}|null  $wali
     */
    public function unggah(array $data, UploadedFile $berkas, ?int $idPengguna, ?array $wali = null): DokumenSantri
    {
        $santri = Santri::find($data['id_santri']);
        if (! $santri) {
            throw new AppException(404, __('Santri tidak ditemukan.'));
        }
        if (Tahap::isFinal($santri->status)) {
            throw new AppException(422, __('Proses santri ini sudah berakhir dengan status ":status"; berkas tidak bisa ditambahkan lagi.', ['status' => $santri->status]));
        }
        $izin = DokumenPolicy::bolehUnggah($data['jenis'], $santri->status);
        if (! $izin['boleh']) {
            throw new AppException(422, $izin['alasan']);
        }
        if ($data['jenis'] === 'lainnya' && empty($data['keterangan'])) {
            throw new AppException(422, __('Jenis "Dokumen Lainnya" wajib disertai keterangan isinya.'));
        }
        $putusan = StatusBerkasSantri::where('id_santri', $santri->id)->where('jenis', $data['jenis'])->first();
        if ($wali !== null && $putusan?->status === 'diterima') {
            throw new AppException(422, __('":berkas" sudah diterima pesantren. Bila perlu diganti, hubungi bagian PSB.',
                ['berkas' => __(DokumenPolicy::LABEL_DOKUMEN[$data['jenis']])]));
        }

        $hash = hash_file('sha256', $berkas->getRealPath());
        $path = $berkas->store(self::DIR, self::DISK);

        try {
            return DB::transaction(function () use ($data, $berkas, $path, $hash, $idPengguna, $wali, $putusan, $santri) {
                $dok = DokumenSantri::create([
                    'id_santri' => $data['id_santri'], 'jenis' => $data['jenis'],
                    'tahap' => DokumenPolicy::TAHAP_DOKUMEN[$data['jenis']], // diturunkan
                    'nama_asli' => $berkas->getClientOriginalName(), 'path' => $path,
                    'mime' => \App\Support\Unggahan::mime($berkas), 'ukuran' => $berkas->getSize(),
                    'hash_sha256' => $hash, 'keterangan' => $data['keterangan'] ?? null,
                    'diunggah_oleh' => $wali === null ? $idPengguna : null,
                    'diunggah_wali' => $wali['id_wali'] ?? null, 'diunggah_peran' => $wali['peran'] ?? null,
                ]);
                if ($wali === null) {
                    $this->putuskan($santri->id, $data['jenis'], 'diterima', null, $idPengguna);
                } elseif ($putusan?->status === 'perlu_diganti') {
                    $putusan->delete(); // unggahan pengganti → kembali "menunggu diperiksa"
                }

                return $dok;
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISK)->delete($path); // jangan tinggalkan sampah
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════
    //  STATUS PER JENIS BERKAS (Portal Wali, 15 Okt 2026)
    // ══════════════════════════════════════════════════════════════════

    public const STATUS = [
        'belum' => 'Belum diunggah', 'menunggu' => 'Menunggu diperiksa',
        'diterima' => 'Diterima', 'perlu_diganti' => 'Perlu diganti',
    ];

    /**
     * Staf memutus satu jenis berkas. "diterima" sah TANPA unggahan — berkasnya bisa
     * diserahkan fisik (proses manual tetap berlaku). "perlu_diganti" wajib beralasan.
     *
     * @throws AppException
     */
    public function putuskan(int $idSantri, string $jenis, string $status, ?string $catatan, ?int $idPengguna): StatusBerkasSantri
    {
        if (! isset(DokumenPolicy::TAHAP_DOKUMEN[$jenis]) || ! in_array($status, ['diterima', 'perlu_diganti'], true)) {
            throw new AppException(422, 'Jenis atau status berkas tidak dikenal.');
        }
        if ($status === 'perlu_diganti' && trim((string) $catatan) === '') {
            throw new AppException(422, 'Sebutkan alasan berkas perlu diganti — wali membacanya di portal.');
        }

        return StatusBerkasSantri::updateOrCreate(['id_santri' => $idSantri, 'jenis' => $jenis],
            ['status' => $status, 'catatan' => $catatan !== null ? trim($catatan) : null, 'diputus_oleh' => $idPengguna]);
    }

    public function batalkanPutusan(int $idSantri, string $jenis): void
    {
        StatusBerkasSantri::where('id_santri', $idSantri)->where('jenis', $jenis)->delete();
    }

    /**
     * Status setiap jenis berkas santri, berurutan sesuai daftar periksa.
     * Berkas pasca-lulus hanya disertakan bila sudah boleh diunggah.
     *
     * @return list<array{jenis:string, label:string, kelompok:string, status:string, catatan:?string, berkas:\Illuminate\Support\Collection}>
     */
    public function statusBerkas(Santri $santri): array
    {
        $berkas = DokumenSantri::where('id_santri', $santri->id)->orderByDesc('created_at')->get()->groupBy('jenis');
        $putusan = StatusBerkasSantri::where('id_santri', $santri->id)->get()->keyBy('jenis');

        $hasil = [];
        foreach (DokumenPolicy::TAHAP_DOKUMEN as $jenis => $tahap) {
            if (! DokumenPolicy::bolehUnggah($jenis, (string) $santri->status)['boleh'] && ! isset($putusan[$jenis]) && ! isset($berkas[$jenis])) {
                continue;
            }
            $p = $putusan[$jenis] ?? null;
            $hasil[] = [
                'jenis' => $jenis, 'label' => DokumenPolicy::LABEL_DOKUMEN[$jenis],
                'kelompok' => DokumenPolicy::KELOMPOK_DOKUMEN[$jenis] ?? $tahap,
                'status' => $p?->status ?? (isset($berkas[$jenis]) ? 'menunggu' : 'belum'),
                'catatan' => $p?->catatan,
                'berkas' => $berkas[$jenis] ?? collect(),
            ];
        }

        return $hasil;
    }

    /**
     * Wali menghapus unggahannya sendiri (salah pilih berkas) — hanya selama jenis itu
     * belum diterima pesantren.
     *
     * @throws AppException
     */
    public function hapusOlehWali(int $id, int $idWali): void
    {
        $row = DokumenSantri::with('santri:id,id_wali,status')->find($id);
        if (! $row || (int) $row->santri?->id_wali !== $idWali || (int) $row->diunggah_wali !== $idWali) {
            throw new AppException(404, __('Berkas tidak ditemukan.'));
        }
        if (StatusBerkasSantri::where('id_santri', $row->id_santri)->where('jenis', $row->jenis)->value('status') === 'diterima') {
            throw new AppException(422, __('Berkas ini sudah diterima pesantren dan tak bisa dihapus lagi.'));
        }
        $this->hapus($id);
    }

    /** Hapus berkas salah unggah (baris + berkas bersama). Selama proses belum berakhir. */
    public function hapus(int $id): void
    {
        $row = DokumenSantri::with('santri:id,status')->find($id);
        if (! $row) {
            throw new AppException(404, 'Dokumen tidak ditemukan.');
        }
        if (Tahap::isFinal($row->santri->status)) {
            throw new AppException(422, 'Proses santri ini sudah berakhir; berkasnya bagian dari riwayat dan tidak bisa dihapus.');
        }
        $path = $row->path;
        $row->delete();
        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Penanda kelengkapan untuk DAFTAR santri (12 Nov 2026) — 2 query untuk satu halaman.
     * Satu jenis wajib dianggap ADA bila diterima (termasuk diterima fisik tanpa unggahan) atau sudah
     * diunggah dan belum diputus; yang ditandai "perlu diganti" dianggap belum ada.
     *
     * @param  iterable<Santri>  $santri
     * @return array<int, array{status:string, ada:int, wajib:int, menunggu:int}>  status: lengkap|sebagian|belum
     */
    public function ringkasMassal(iterable $santri): array
    {
        $santri = collect($santri);
        $ids = $santri->pluck('id')->all();
        if ($ids === []) {
            return [];
        }
        $unggah = DokumenSantri::whereIn('id_santri', $ids)->select('id_santri', 'jenis')->distinct()->get()
            ->groupBy('id_santri')->map(fn ($g) => $g->pluck('jenis')->flip());
        $putusan = StatusBerkasSantri::whereIn('id_santri', $ids)->get(['id_santri', 'jenis', 'status'])
            ->groupBy('id_santri')->map(fn ($g) => $g->pluck('status', 'jenis'));

        $hasil = [];
        foreach ($santri as $s) {
            $wajib = DokumenPolicy::wajib((string) $s->status);
            $ada = 0;
            foreach ($wajib as $jenis) {
                $p = $putusan[$s->id][$jenis] ?? null;
                if ($p === 'diterima' || ($p === null && isset($unggah[$s->id][$jenis]))) {
                    $ada++;
                }
            }
            // Yang menunggu diperiksa dihitung atas SEMUA jenis (termasuk pindahan & lainnya) — sama dengan
            // saringan "berkas menunggu" (saringBerkasMenunggu).
            $menunggu = collect(array_keys(($unggah[$s->id] ?? collect())->all()))
                ->filter(fn ($jenis) => ! isset($putusan[$s->id][$jenis]))->count();
            $hasil[$s->id] = ['status' => $ada === 0 ? 'belum' : ($ada === count($wajib) ? 'lengkap' : 'sebagian'),
                'ada' => $ada, 'wajib' => count($wajib), 'menunggu' => $menunggu];
        }

        return $hasil;
    }

    /**
     * Santri yang punya unggahan belum diputus staf (unggahan ulang menghapus putusan lama, jadi ikut).
     * Dipakai saringan daftar santri DAN hitungan tugas — definisinya satu.
     */
    public static function saringBerkasMenunggu(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereExists(fn ($d) => $d->from('dokumen_santri as d')->whereColumn('d.id_santri', 'santri.id')
            ->whereNotExists(fn ($s) => $s->from('status_berkas_santri as sb')
                ->whereColumn('sb.id_santri', 'd.id_santri')->whereColumn('sb.jenis', 'd.jenis')));
    }

    /** Ringkasan kelengkapan untuk layar verifikasi berkas. */
    public function kelengkapan(int $idSantri): array
    {
        $ada = DokumenSantri::where('id_santri', $idSantri)->pluck('jenis')->unique()->all();

        return array_map(fn ($jenis, $tahap) => [
            'jenis' => $jenis,
            'label' => DokumenPolicy::LABEL_DOKUMEN[$jenis],
            'tahap' => $tahap,
            'kelompok' => DokumenPolicy::KELOMPOK_DOKUMEN[$jenis] ?? $tahap,
            'ada' => in_array($jenis, $ada, true),
        ], array_keys(DokumenPolicy::TAHAP_DOKUMEN), array_values(DokumenPolicy::TAHAP_DOKUMEN));
    }
}
