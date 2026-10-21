<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\KunjunganKlinik;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Services\Ledger\DocNumber;
use App\Services\PortalWali\KabarWali;
use Illuminate\Support\Facades\DB;

/**
 * KLINIK (keputusan user 21 Okt 2026) — catatan kunjungan santri.
 *
 *  • Tindak lanjut "dirawat" membiarkan kunjungan TERBUKA sampai petugas menyelesaikannya; selain itu
 *    langsung selesai.
 *  • Wali dikabari SETIAP kunjungan (dan saat rawat selesai) — kategori push "kesehatan", bisa dimatikan wali.
 *  • Obat hanya dicatat (tanpa stok).
 *  • Biaya: tagihan LAIN-LAIN lewat TagihanLainService (periode = nomor kunjungan → satu tagihan per kunjungan).
 */
class KlinikService
{
    /** @param list<array{nama:?string, dosis:?string, jumlah:?string}> $obat */
    public function catat(array $d, array $obat, int $idPengguna): KunjunganKlinik
    {
        $santri = Santri::find($d['id_santri']) ?? throw new AppException(404, 'Santri tidak ditemukan.');
        if ($santri->status !== 'aktif') {
            throw new AppException(422, "{$santri->nama} bukan santri aktif.");
        }
        if ($d['tindak_lanjut'] === 'dirujuk' && trim((string) ($d['rujukan_ke'] ?? '')) === '') {
            throw new AppException(422, 'Sebutkan rumah sakit / fasilitas rujukannya.');
        }

        $k = DB::transaction(function () use ($d, $obat, $idPengguna, $santri) {
            $datang = $d['datang_pada'] ?? now();
            $base = DocNumber::docBase('KLN', now());
            $dirawat = $d['tindak_lanjut'] === 'dirawat';
            $k = KunjunganKlinik::create([
                'nomor' => DocNumber::nextDocNumber($base, KunjunganKlinik::where('nomor', 'like', $base.'%')->orderByDesc('nomor')->value('nomor')),
                'id_santri' => $santri->id, 'datang_pada' => $datang, 'keluhan' => trim($d['keluhan']),
                'suhu' => $d['suhu'] ?? null, 'tensi' => $d['tensi'] ?? null, 'nadi' => $d['nadi'] ?? null, 'berat' => $d['berat'] ?? null,
                'pemeriksaan' => $d['pemeriksaan'] ?? null, 'tindakan' => $d['tindakan'] ?? null,
                'tindak_lanjut' => $d['tindak_lanjut'], 'rujukan_ke' => $d['rujukan_ke'] ?? null,
                'status' => $dirawat ? 'dirawat' : 'selesai', 'selesai_pada' => $dirawat ? null : now(),
                'dicatat_oleh' => $idPengguna,
            ]);
            foreach ($obat as $o) {
                if (trim((string) ($o['nama'] ?? '')) !== '') {
                    $k->obat()->create(['nama' => trim($o['nama']), 'dosis' => $o['dosis'] ?? null, 'jumlah' => $o['jumlah'] ?? null]);
                }
            }

            return $k;
        });

        $this->kabari($k, "{$santri->nama} ke klinik", $this->ringkas($k));

        return $k;
    }

    public function selesaikan(KunjunganKlinik $k, ?string $catatan, int $idPengguna): void
    {
        if ($k->status !== 'dirawat') {
            throw new AppException(422, 'Kunjungan ini sudah selesai.');
        }
        $k->update(['status' => 'selesai', 'selesai_pada' => now(), 'catatan_selesai' => $catatan ?: null, 'diselesaikan_oleh' => $idPengguna]);
        $this->kabari($k, "{$k->santri->nama} selesai dirawat di klinik", 'Sudah kembali ke asrama.'.($catatan ? " Catatan: {$catatan}" : ''));
    }

    /** Tagihan lain-lain ke wali atas kunjungan ini (obat khusus, rujukan, dll). */
    public function terbitkanTagihan(KunjunganKlinik $k, string $kodeJenis, string|int|float $nominal, ?string $keterangan, int $idPengguna): TagihanSantri
    {
        if ($k->id_tagihan) {
            throw new AppException(422, 'Kunjungan ini sudah punya tagihan.');
        }
        (new TagihanLainService)->terbitkan([
            'kode_jenis' => $kodeJenis, 'nominal' => $nominal, 'id_santri' => [$k->id_santri],
            'tanggal' => now()->toDateString(), 'periode' => $k->nomor,
            'keterangan' => trim('Klinik '.$k->nomor.($keterangan ? " — {$keterangan}" : '')),
        ], $idPengguna);
        $t = TagihanSantri::where('id_santri', $k->id_santri)->where('kode_jenis', $kodeJenis)->where('periode', $k->nomor)->latest('id')->first()
            ?? throw new AppException(422, 'Tagihan tidak terbit — periksa jenis biayanya (harus Lain-lain & aktif).');
        $k->update(['id_tagihan' => $t->id]);

        return $t;
    }

    public function ringkas(KunjunganKlinik $k): string
    {
        $vital = array_filter([$k->suhu ? "suhu {$k->suhu}°C" : null, $k->tensi ? "tensi {$k->tensi}" : null]);

        return $k->datang_pada->timezone('Asia/Jakarta')->format('H:i')." — {$k->keluhan}".($vital ? ' ('.implode(', ', $vital).')' : '')
            .'. Tindak lanjut: '.$k->labelTindakLanjut().($k->rujukan_ke ? " ({$k->rujukan_ke})" : '').'.';
    }

    private function kabari(KunjunganKlinik $k, string $judul, string $pesan): void
    {
        $k->loadMissing('santri.wali');
        KabarWali::keKeluarga($k->santri?->wali, ['judul' => $judul, 'pesan' => $pesan, 'jenis' => 'klinik',
            'ref_jenis' => 'KunjunganKlinik', 'ref_id' => (string) $k->id, 'id_santri' => $k->id_santri]);
    }
}
