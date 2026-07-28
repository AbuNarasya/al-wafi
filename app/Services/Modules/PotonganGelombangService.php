<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\PotonganGelombang;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Master potongan uang pangkal per gelombang PER JENJANG (append-only, riwayat).
 * Penerbitan pakai baris `aktif` untuk (gelombang, jenjang), fallback UMUM
 * (kode_jenjang null). Hanya SATU baris aktif per (gelombang, jenjang).
 */
class PotonganGelombangService
{
    public function list()
    {
        return PotonganGelombang::orderByDesc('tahun_ajaran')->orderBy('gelombang')->get();
    }

    public function create(array $data): PotonganGelombang
    {
        if (Money::isNegative($data['potongan'])) {
            throw new AppException(422, 'Potongan tidak boleh negatif.');
        }
        $kodeJenjang = $data['kode_jenjang'] ?? null;
        $bentrok = PotonganGelombang::where('tahun_ajaran', $data['tahun_ajaran'])
            ->where('gelombang', $data['gelombang'])
            ->where('kode_jenjang', $kodeJenjang)->first();
        if ($bentrok) {
            throw new AppException(409, "Potongan Gelombang {$data['gelombang']} ".$this->labelJenjang($kodeJenjang)." untuk T.A {$data['tahun_ajaran']} sudah ada. Sunting baris itu.");
        }
        $aktif = $data['aktif'] ?? true;

        return DB::transaction(function () use ($data, $kodeJenjang, $aktif) {
            if ($aktif) {
                $this->nonaktifkanLain($data['gelombang'], $kodeJenjang, null);
            }

            return PotonganGelombang::create([
                'tahun_ajaran' => $data['tahun_ajaran'], 'gelombang' => $data['gelombang'],
                'kode_jenjang' => $kodeJenjang, 'potongan' => Money::of($data['potongan']),
                'masa_berlaku_hari' => $data['masa_berlaku_hari'] ?? 7, 'aktif' => $aktif,
                'keterangan' => $data['keterangan'] ?? null,
            ]);
        });
    }

    public function remove(int $id): void
    {
        if (! PotonganGelombang::find($id)) {
            throw new AppException(404, 'Data potongan tidak ditemukan.');
        }
        PotonganGelombang::destroy($id);
    }

    /**
     * Potongan AKTIF untuk (gelombang, jenjang): khusus jenjang dulu, fallback
     * UMUM. $tahunAjaran (TA santri) membatasi ke potongan TA itu saja.
     *
     * $gelombang NULL = santri "Tanpa Gelombang" (pindahan & kasus khusus) →
     * TIDAK PERNAH dapat potongan; pencocokan dilewati sejak awal agar tak ada
     * potongan gelombang lain yang menempel keliru.
     */
    public function potonganAktif(?int $gelombang, ?string $kodeJenjang, ?string $tahunAjaran = null): ?PotonganGelombang
    {
        if ($gelombang === null) {
            return null;
        }

        if ($kodeJenjang) {
            $khusus = PotonganGelombang::where('gelombang', $gelombang)->where('kode_jenjang', $kodeJenjang)
                ->when($tahunAjaran, fn ($q) => $q->where('tahun_ajaran', $tahunAjaran))
                ->where('aktif', true)->orderByDesc('tahun_ajaran')->first();
            if ($khusus) {
                return $khusus;
            }
        }

        return PotonganGelombang::where('gelombang', $gelombang)->whereNull('kode_jenjang')
            ->when($tahunAjaran, fn ($q) => $q->where('tahun_ajaran', $tahunAjaran))
            ->where('aktif', true)->orderByDesc('tahun_ajaran')->first();
    }

    private function labelJenjang(?string $kodeJenjang): string
    {
        return $kodeJenjang ? "jenjang {$kodeJenjang}" : '(umum)';
    }

    private function nonaktifkanLain(int $gelombang, ?string $kodeJenjang, ?int $kecualiId): void
    {
        PotonganGelombang::where('gelombang', $gelombang)->where('kode_jenjang', $kodeJenjang)
            ->where('aktif', true)
            ->when($kecualiId, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->update(['aktif' => false]);
    }
}
