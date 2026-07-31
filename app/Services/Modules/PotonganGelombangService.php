<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Jenjang;
use App\Models\PotonganGelombang;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Master potongan uang pangkal per gelombang PER JENJANG. Penerbitan pakai baris
 * `aktif` untuk (gelombang, jenjang), fallback UMUM (kode_jenjang null). Hanya
 * SATU baris aktif per (gelombang, jenjang); menyalakan satu baris otomatis
 * mengarsipkan yang lain.
 */
class PotonganGelombangService
{
    public function list()
    {
        // `jenjang` dimuat karena daftarnya menyebut NAMA jenjang, bukan kode `J001`.
        return PotonganGelombang::with('jenjang')
            ->orderByDesc('tahun_ajaran')->orderBy('gelombang')->get();
    }

    public function get(int $id): PotonganGelombang
    {
        $row = PotonganGelombang::find($id);
        if (! $row) {
            throw new AppException(404, 'Data potongan tidak ditemukan.');
        }

        return $row;
    }

    public function create(array $data): PotonganGelombang
    {
        $kodeJenjang = $data['kode_jenjang'] ?? null;
        $this->assertNominalSah($data['potongan']);
        $this->assertBelumAda($data['tahun_ajaran'], (int) $data['gelombang'], $kodeJenjang, null);
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

    /**
     * SUNTING satu baris. Dulu tak ada — salah ketik harus dihapus lalu dibuat
     * ulang, padahal pesan bentrokan di create() sendiri sudah menyuruh
     * "sunting baris itu". Menghapus juga membuang riwayat kebijakannya.
     *
     * Tagihan yang SUDAH TERBIT tidak ikut berubah, dan itu memang benar:
     * `potongan_uang_pangkal` menyalin `nominal_normal` & `potongan` saat tagihan
     * lahir, jadi angka yang sudah dijanjikan ke wali tetap seperti semula.
     * Suntingan ini hanya berlaku untuk tagihan berikutnya.
     */
    public function update(int $id, array $data): PotonganGelombang
    {
        $lama = $this->get($id);
        $gabungan = array_merge($lama->toArray(), $data);
        $kodeJenjang = ($gabungan['kode_jenjang'] ?? null) ?: null;

        $this->assertNominalSah($gabungan['potongan']);
        $this->assertBelumAda($gabungan['tahun_ajaran'], (int) $gabungan['gelombang'], $kodeJenjang, $id);
        $aktif = (bool) ($gabungan['aktif'] ?? false);

        return DB::transaction(function () use ($lama, $gabungan, $kodeJenjang, $aktif, $id) {
            if ($aktif) {
                $this->nonaktifkanLain((int) $gabungan['gelombang'], $kodeJenjang, $id);
            }
            $lama->update([
                'tahun_ajaran' => $gabungan['tahun_ajaran'], 'gelombang' => (int) $gabungan['gelombang'],
                'kode_jenjang' => $kodeJenjang, 'potongan' => Money::of($gabungan['potongan']),
                'masa_berlaku_hari' => $gabungan['masa_berlaku_hari'] ?? 7, 'aktif' => $aktif,
                'keterangan' => $gabungan['keterangan'] ?? null,
            ]);

            return $lama->refresh();
        });
    }

    public function remove(int $id): void
    {
        $this->get($id);
        PotonganGelombang::destroy($id);
    }

    /**
     * PERINGATAN (bukan penolakan): potongan yang ≥ uang pangkalnya akan ditolak
     * nanti oleh `SantriService::tagihkanUangPangkal`, dan tanpa isyarat di sini
     * kekeliruannya baru ketahuan saat petugas menagih santri pertama.
     *
     * Tidak ditolak karena tarif uang pangkalnya boleh belum diisi, atau memang
     * akan dinaikkan setelah ini — urutan mengisi master tak boleh dipaksakan.
     * Sel yang dibandingkan adalah baris Umum (tanpa jalur), karena potongan
     * gelombang pun tak mengenal jalur.
     */
    public function peringatanNominal(PotonganGelombang $row): ?string
    {
        $tarif = (new TarifService)->cari('uang_pangkal', $row->tahun_ajaran, $row->kode_jenjang, null);
        if ($tarif['status'] !== 'ada' || ! Money::gte($row->potongan, $tarif['nominal'])) {
            return null;
        }

        return 'Perhatian: potongan ini ('.Money::of($row->potongan).') tidak lebih kecil dari uang pangkal '
            .$this->labelJenjang($row->kode_jenjang)." T.A {$row->tahun_ajaran} ({$tarif['nominal']}), "
            .'sehingga penagihan uang pangkal akan DITOLAK. Turunkan potongannya atau naikkan tarif uang pangkalnya.';
    }

    private function assertNominalSah(mixed $potongan): void
    {
        if (Money::isNegative($potongan)) {
            throw new AppException(422, 'Potongan tidak boleh negatif.');
        }
    }

    /** Satu baris per (T.A, gelombang, jenjang) — termasuk yang sudah diarsipkan. */
    private function assertBelumAda(string $tahunAjaran, int $gelombang, ?string $kodeJenjang, ?int $kecualiId): void
    {
        $bentrok = PotonganGelombang::where('tahun_ajaran', $tahunAjaran)
            ->where('gelombang', $gelombang)
            ->where('kode_jenjang', $kodeJenjang)
            ->when($kecualiId, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->first();
        if ($bentrok) {
            throw new AppException(409, "Potongan Gelombang {$gelombang} ".$this->labelJenjang($kodeJenjang)
                ." untuk T.A {$tahunAjaran} sudah ada. Sunting baris itu.");
        }
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

    /**
     * Jenjang disebut lewat NAMA, bukan kodenya: sejak kode berformat `J001` ia
     * tak lagi bercerita apa pun bagi pembaca pesan. Kode dipakai sebagai
     * cadangan bila barisnya sudah tak ada di master.
     */
    private function labelJenjang(?string $kodeJenjang): string
    {
        if (! $kodeJenjang) {
            return '(umum)';
        }

        return 'jenjang '.(Jenjang::whereKey($kodeJenjang)->value('nama') ?: $kodeJenjang);
    }

    private function nonaktifkanLain(int $gelombang, ?string $kodeJenjang, ?int $kecualiId): void
    {
        PotonganGelombang::where('gelombang', $gelombang)->where('kode_jenjang', $kodeJenjang)
            ->where('aktif', true)
            ->when($kecualiId, fn ($q) => $q->where('id', '!=', $kecualiId))
            ->update(['aktif' => false]);
    }
}
