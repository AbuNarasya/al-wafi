<?php

namespace App\Services\Modules;

use App\Exceptions\AppException;
use App\Models\Inventory;
use App\Models\JournalEntry;
use App\Models\LokasiPersediaan;
use App\Models\MutasiPersediaan;
use App\Services\Ledger\DocNumber;
use App\Services\Ledger\InventoryMovement;
use App\Services\Ledger\PostingService;
use App\Services\Ledger\ReversalService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * AKUNTANSI PERSEDIAAN — pasangan jurnal untuk tiap pergerakan stok, dan dua
 * pekerjaan gudang yang menerbitkannya sendiri: Pemakaian & Opname.
 *
 * PEMBAGIAN PERAN (keputusan pengguna): akun lawan ditetapkan BAGIAN KEUANGAN
 * di master persediaan — `kode_coa_beban` untuk pemakaian, `kode_coa_selisih`
 * untuk penyesuaian opname. Petugas gudang hanya memilih barang, alasan, dan
 * jumlahnya; ia tak pernah diminta memilih akun. Itulah sebabnya menu Mutasi
 * Stok bisa berjurnal tanpa menuntut pemahaman akuntansi dari penggunanya.
 *
 * Seluruh jurnal dari sini bersumber_modul `Persediaan` dengan
 * `id_sumber = "{modul asal}:{nomor dokumen}"`, supaya pembatalan dokumen
 * asalnya bisa menemukan SEMUA jurnal turunannya sekaligus — beberapa item
 * dalam satu dokumen berarti beberapa jurnal.
 */
class PersediaanService
{
    public const SUMBER = 'Persediaan';

    /**
     * Jurnalkan satu pergerakan stok.
     *
     * keluar + pemakaian  → D Beban Pemakaian   / K Persediaan
     * keluar + opname     → D Selisih Persediaan/ K Persediaan
     * masuk  + opname     → D Persediaan        / K Selisih Persediaan
     */
    public function jurnalkan(MutasiPersediaan $mutasi, array $p = []): ?JournalEntry
    {
        if (Money::isZero($mutasi->nilai)) {
            // Barang bernilai nol tak punya jurnal — dan memaksakannya hanya
            // melahirkan entry kosong yang ditolak PostingService.
            return null;
        }

        $item = Inventory::findOrFail($mutasi->kode_persediaan);
        $akunPersediaan = $this->wajib($item, 'kode_coa', 'Akun Persediaan');
        $akunLawan = in_array($mutasi->alasan, ['opname', 'kehilangan'], true)
            ? $this->wajib($item, 'kode_coa_selisih', 'Akun Selisih Persediaan')
            : $this->wajib($item, 'kode_coa_beban', 'Akun Beban Pemakaian');

        $nilai = Money::of($mutasi->nilai);
        $tanggal = $mutasi->tanggal->toDateString();

        [$debet, $kredit] = $mutasi->arah === 'keluar'
            ? [$akunLawan, $akunPersediaan]
            : [$akunPersediaan, $akunLawan];

        $ket = $p['keterangan'] ?? "{$mutasi->labelAlasan()} {$item->nama_persediaan} — {$mutasi->kuantiti} {$item->satuan}";

        // `PostingService` mewajibkan kode_bagian pada tiap baris akun Beban.
        // Bagiannya DIPILIH saat mencatat, bukan dipaku di master: barang yang
        // sama bisa dipakai bagian mana saja, dan memakunya berarti membebankan
        // pemakaian ke bagian yang salah setiap kali peminjamnya berbeda.
        $bagian = $p['kode_bagian'] ?? null;

        $entry = PostingService::postJournal([
            'referensi' => DocNumber::nextJournalRef('PRS', $tanggal),
            'tanggal' => $tanggal,
            'kode_unit' => $p['kode_unit'] ?? null,
            'keterangan' => $ket,
            'sumber_modul' => self::SUMBER,
            'id_sumber' => $this->idSumber($mutasi->sumber_modul, $mutasi->sumber_ref),
            'id_pengguna' => $p['id_pengguna'] ?? $mutasi->id_pengguna,
            'lines' => [
                ['kode_coa' => $debet, 'debet' => $nilai, 'kredit' => '0', 'keterangan' => $ket, 'kode_bagian' => $bagian],
                ['kode_coa' => $kredit, 'debet' => '0', 'kredit' => $nilai, 'keterangan' => $ket, 'kode_bagian' => $bagian],
            ],
        ]);

        $mutasi->update(['journal_entry_id' => $entry->id]);

        return $entry;
    }

    /**
     * Batalkan jurnal persediaan turunan sebuah dokumen. Dipanggil dari void
     * modul asalnya, berbarengan dengan `InventoryMovement::batalkanDokumen()`.
     */
    public function batalkanJurnal(string $sumberModulAsal, ?string $sumberRef, ?int $idPengguna = null): void
    {
        if (! $sumberRef) {
            return;
        }

        $entries = JournalEntry::where('sumber_modul', self::SUMBER)
            ->where('id_sumber', $this->idSumber($sumberModulAsal, $sumberRef))
            ->where('status', 'aktif')
            ->get();

        foreach ($entries as $e) {
            ReversalService::reverseJournalEntry($e->id, [
                'id_pengguna' => $idPengguna,
                'keteranganPrefix' => 'Void — ',
            ]);
        }
    }

    /**
     * PEMAKAIAN BARANG (gudang). Stok keluar sejumlah yang dipakai; harga
     * pokoknya dihitung FIFO, akun bebannya diambil dari master.
     */
    public function pemakaian(array $data, ?int $idPengguna): MutasiPersediaan
    {
        return DB::transaction(function () use ($data, $idPengguna) {
            $mutasi = InventoryMovement::keluar([
                'kode_persediaan' => $data['kode_persediaan'],
                'kode_lokasi' => $data['kode_lokasi'] ?? null,
                'kuantiti' => $data['jumlah'],
                'tanggal' => $data['tanggal'],
                'alasan' => 'pemakaian',
                'sumber_modul' => self::SUMBER,
                'sumber_ref' => $this->nomorMutasi($data['tanggal']),
                'keterangan' => $data['keterangan'] ?? null,
                'id_pengguna' => $idPengguna,
            ]);

            $this->jurnalkan($mutasi, [
                'id_pengguna' => $idPengguna,
                'kode_unit' => $data['kode_unit'] ?? $this->unitLokasi($mutasi),
                'kode_bagian' => $data['kode_bagian'] ?? null,
            ]);

            return $mutasi->refresh();
        });
    }

    /**
     * OPNAME FISIK (gudang). Petugas memasukkan STOK HASIL HITUNG, bukan
     * selisihnya — arah dan besarnya ditentukan sistem. Meminta orang menghitung
     * selisih sendiri adalah cara tercepat membuat opname salah arah.
     *
     * Stok lebih → barang masuk seharga rata-rata berjalan (tak ada harga beli
     * untuk barang yang tiba-tiba ada). Stok kurang → barang keluar FIFO.
     */
    public function opname(array $data, ?int $idPengguna): ?MutasiPersediaan
    {
        $item = Inventory::find($data['kode_persediaan']);
        if (! $item) {
            throw new AppException(404, 'Item persediaan tidak ditemukan.');
        }

        $fisik = Money::of($data['stok_fisik'], 4);
        if (Money::isNegative($fisik, 4)) {
            throw new AppException(422, 'Stok fisik tidak boleh negatif.');
        }

        // Opname selalu PER LOKASI: yang dihitung petugas adalah rak di satu tempat,
        // jadi pembandingnya pun stok tercatat di tempat itu, bukan total semua lokasi.
        $lokasi = $data['kode_lokasi'] ?? LokasiPersediaan::BAWAAN;
        $tercatat = InventoryMovement::stok($item->kode_persediaan, $lokasi);
        $selisih = Money::sub($fisik, $tercatat, 4);

        if (Money::isZero($selisih, 4)) {
            return null;
        }

        return $this->sesuaikan([
            'kode_persediaan' => $item->kode_persediaan,
            'kode_lokasi' => $lokasi,
            'selisih' => $selisih,
            'tanggal' => $data['tanggal'],
            'sumber_modul' => self::SUMBER,
            'sumber_ref' => $this->nomorMutasi($data['tanggal']),
            'keterangan' => trim(($data['keterangan'] ?? '')." (opname: fisik {$fisik}, tercatat {$tercatat})"),
            'kode_unit' => $data['kode_unit'] ?? null,
            'kode_bagian' => $data['kode_bagian'] ?? null,
        ], $idPengguna);
    }

    /**
     * PENYESUAIAN BERTANDA — satu pintu untuk opname (per barang maupun dokumen) dan
     * kehilangan barang transfer. `selisih` positif = stok lebih (masuk seharga rata-rata
     * berjalan: barang yang tiba-tiba ada tak punya harga beli), negatif = kurang (keluar
     * FIFO). Lawannya akun Selisih Persediaan barang, unitnya unit pemilik lokasi.
     *
     * @param  array{kode_persediaan:string, kode_lokasi:string, selisih:string, tanggal:string,
     *     sumber_modul:string, sumber_ref:string, keterangan?:?string, kode_unit?:?string,
     *     kode_bagian?:?string, alasan?:string, lapisan_sumber?:array{0:string,1:string}}  $p
     */
    public function sesuaikan(array $p, ?int $idPengguna): ?MutasiPersediaan
    {
        $selisih = Money::of($p['selisih'], 4);
        if (Money::isZero($selisih, 4)) {
            return null;
        }
        $item = Inventory::findOrFail($p['kode_persediaan']);

        return DB::transaction(function () use ($item, $selisih, $p, $idPengguna) {
            $umum = [
                'kode_persediaan' => $item->kode_persediaan,
                'kode_lokasi' => $p['kode_lokasi'],
                'tanggal' => $p['tanggal'],
                'alasan' => $p['alasan'] ?? 'opname',
                'sumber_modul' => $p['sumber_modul'],
                'sumber_ref' => $p['sumber_ref'],
                'keterangan' => $p['keterangan'] ?? null,
                'id_pengguna' => $idPengguna,
            ];

            if (Money::gtZero($selisih, 4)) {
                $harga = Money::gtZero($item->harga_perolehan) ? $item->harga_perolehan : '0';
                $mutasi = InventoryMovement::masuk([...$umum, 'kuantiti' => $selisih, 'nilai' => Money::mul($selisih, $harga)]);
            } else {
                $mutasi = InventoryMovement::keluar([
                    ...$umum,
                    'kuantiti' => Money::sub('0', $selisih, 4),
                    'lapisan_sumber' => $p['lapisan_sumber'] ?? null,
                ]);
            }

            $this->jurnalkan($mutasi, [
                'id_pengguna' => $idPengguna,
                'kode_unit' => ($p['kode_unit'] ?? null) ?: $this->unitLokasi($mutasi),
                'kode_bagian' => $p['kode_bagian'] ?? null,
            ]);

            return $mutasi->refresh();
        });
    }

    /** Kartu stok satu barang, terbaru di atas — seluruh lokasi atau satu lokasi. */
    public function kartuStok(string $kodePersediaan, ?string $from = null, ?string $to = null, ?string $kodeLokasi = null)
    {
        return MutasiPersediaan::where('kode_persediaan', $kodePersediaan)
            ->when($kodeLokasi, fn ($q) => $q->where('kode_lokasi', $kodeLokasi))
            ->when($from, fn ($q) => $q->where('tanggal', '>=', $from))
            ->when($to, fn ($q) => $q->where('tanggal', '<=', $to))
            ->with('pengguna:id_pengguna,nama')
            ->orderByDesc('tanggal')->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();
    }

    private function nomorMutasi(string $tanggal): string
    {
        $base = DocNumber::docBase('MP', $tanggal);
        $last = MutasiPersediaan::where('sumber_ref', 'like', $base.'%')
            ->orderByDesc('sumber_ref')->value('sumber_ref');

        return DocNumber::nextDocNumber($base, $last);
    }

    /**
     * Unit bisnis pemilik lokasi — dulu pemakaian/opname dari layar gudang tak menyebut
     * unit sama sekali, sehingga bebannya jatuh ke unit bawaan modul.
     */
    private function unitLokasi(MutasiPersediaan $mutasi): ?string
    {
        return LokasiPersediaan::whereKey($mutasi->kode_lokasi)->value('kode_unit');
    }

    private function idSumber(string $sumberModulAsal, string $sumberRef): string
    {
        return "{$sumberModulAsal}:{$sumberRef}";
    }

    private function wajib(Inventory $item, string $kolom, string $label): string
    {
        if (empty($item->{$kolom})) {
            throw new AppException(422,
                "Item \"{$item->nama_persediaan}\" belum punya {$label}. "
                .'Lengkapi dulu lewat master Persediaan (diisi bagian keuangan).');
        }

        return $item->{$kolom};
    }
}
