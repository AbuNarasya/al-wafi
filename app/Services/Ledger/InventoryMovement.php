<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\Inventory;
use App\Models\LapisanPersediaan;
use App\Models\MutasiPersediaan;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA PINTU pergerakan persediaan. Metode FIFO.
 *
 * Sebelumnya logika rata-rata tertimbang tersalin di tiga tempat —
 * `InventoryMovement`, `CashOutService`, `InvoiceService` — dan ketiganya sudah
 * saling menyimpang. Semua kini bermuara ke sini.
 *
 * Aturannya:
 *  - `masuk()`  melahirkan satu LAPISAN berharga sendiri.
 *  - `keluar()` menggerus lapisan TERTUA lebih dulu; nilai keluarnya (harga
 *    pokok) DIHITUNG dari lapisan, bukan diterima dari pemanggil.
 *  - Tiap pergerakan menulis satu baris KARTU STOK beserta saldo berjalannya.
 *  - Pembatalan mengembalikan lapisan yang BENAR lewat `rincian_lapisan` —
 *    bukan sekadar mengurangi kuantiti lalu membiarkan harganya salah selamanya.
 *
 * Kolom `stok_masuk`, `stok_keluar`, `harga_perolehan`, `nilai_persediaan` di
 * master adalah turunan; `segarkanTurunan()` yang memeliharanya.
 */
final class InventoryMovement
{
    /**
     * Pemasukan barang: melahirkan satu lapisan FIFO + satu baris kartu stok.
     *
     * @param  array{
     *     kode_persediaan:string, kuantiti:string|int|float, nilai:string|int|float,
     *     tanggal?:string, alasan?:string, sumber_modul:string, sumber_ref?:?string,
     *     journal_entry_id?:?int, keterangan?:?string, id_pengguna?:?int
     * }  $p  `nilai` = TOTAL rupiah yang masuk (bukan harga satuan).
     */
    public static function masuk(array $p): MutasiPersediaan
    {
        $item = self::item($p['kode_persediaan']);
        $qty = Money::of($p['kuantiti'], 4);
        $nilai = Money::of($p['nilai']);

        if (! Money::gtZero($qty, 4)) {
            throw new AppException(422, 'Kuantiti masuk harus lebih besar dari nol.');
        }
        if (Money::isNegative($nilai)) {
            throw new AppException(422, 'Nilai barang masuk tidak boleh negatif.');
        }

        $tanggal = $p['tanggal'] ?? now()->toDateString();

        return DB::transaction(function () use ($item, $qty, $nilai, $tanggal, $p) {
            $mutasi = self::catat($item, [
                'tanggal' => $tanggal,
                'arah' => 'masuk',
                'alasan' => $p['alasan'] ?? 'pembelian',
                'kuantiti' => $qty,
                'nilai' => $nilai,
                'sumber_modul' => $p['sumber_modul'],
                'sumber_ref' => $p['sumber_ref'] ?? null,
                'journal_entry_id' => $p['journal_entry_id'] ?? null,
                'keterangan' => $p['keterangan'] ?? null,
                'id_pengguna' => $p['id_pengguna'] ?? null,
            ]);

            LapisanPersediaan::create([
                'kode_persediaan' => $item->kode_persediaan,
                'tanggal' => $tanggal,
                'kuantiti_awal' => $qty,
                'kuantiti_sisa' => $qty,
                'harga_satuan' => Money::div($nilai, $qty),
                'mutasi_id' => $mutasi->id,
                'sumber_modul' => $p['sumber_modul'],
                'sumber_ref' => $p['sumber_ref'] ?? null,
            ]);

            self::segarkanTurunan($item->kode_persediaan);

            return self::perbaruiSaldo($mutasi);
        });
    }

    /**
     * Pengeluaran barang: menggerus lapisan tertua. Harga pokoknya HASIL
     * hitungan, tersedia di `$mutasi->nilai` setelah pemanggilan.
     *
     * @param  array{
     *     kode_persediaan:string, kuantiti:string|int|float, tanggal?:string,
     *     alasan?:string, sumber_modul:string, sumber_ref?:?string,
     *     journal_entry_id?:?int, keterangan?:?string, id_pengguna?:?int
     * }  $p
     */
    public static function keluar(array $p): MutasiPersediaan
    {
        $item = self::item($p['kode_persediaan']);
        $qty = Money::of($p['kuantiti'], 4);

        if (! Money::gtZero($qty, 4)) {
            throw new AppException(422, 'Kuantiti keluar harus lebih besar dari nol.');
        }

        $tersedia = self::stok($item->kode_persediaan);
        if (Money::gt($qty, $tersedia, 4)) {
            throw new AppException(422, "Stok {$item->nama_persediaan} tidak cukup: diminta {$qty}, tersedia {$tersedia}.");
        }

        return DB::transaction(function () use ($item, $qty, $p) {
            [$nilai, $rincian] = self::gerusLapisan($item->kode_persediaan, $qty);

            $mutasi = self::catat($item, [
                'tanggal' => $p['tanggal'] ?? now()->toDateString(),
                'arah' => 'keluar',
                'alasan' => $p['alasan'] ?? 'pemakaian',
                'kuantiti' => $qty,
                'nilai' => $nilai,
                'sumber_modul' => $p['sumber_modul'],
                'sumber_ref' => $p['sumber_ref'] ?? null,
                'journal_entry_id' => $p['journal_entry_id'] ?? null,
                'rincian_lapisan' => $rincian,
                'keterangan' => $p['keterangan'] ?? null,
                'id_pengguna' => $p['id_pengguna'] ?? null,
            ]);

            self::segarkanTurunan($item->kode_persediaan);

            return self::perbaruiSaldo($mutasi);
        });
    }

    /**
     * Batalkan SELURUH pergerakan milik satu dokumen (void kas keluar, void
     * invoice, void jurnal). Dijalankan mundur — pergerakan terakhir dibatalkan
     * lebih dulu — supaya lapisan pulih ke keadaan sebelum dokumen itu ada.
     */
    public static function batalkanDokumen(string $sumberModul, ?string $sumberRef, ?int $idPengguna = null): void
    {
        if (! $sumberRef) {
            return;
        }

        $mutasi = MutasiPersediaan::where('sumber_modul', $sumberModul)
            ->where('sumber_ref', $sumberRef)
            ->where('alasan', '!=', 'pembatalan')
            ->orderByDesc('id')
            ->get();

        foreach ($mutasi as $m) {
            $m->arah === 'masuk'
                ? self::batalkanMasuk($m, $idPengguna)
                : self::batalkanKeluar($m, $idPengguna);
        }
    }

    // ---- Bagian dalam ----

    /**
     * Batalkan satu pemasukan: lapisan yang lahir darinya ditarik kembali.
     *
     * Kalau barangnya sudah terlanjur dipakai, pembatalan DITOLAK dengan terang
     * — bukan diam-diam dibiarkan seperti dulu. Menarik barang yang sudah jadi
     * beban berarti mengarang stok negatif dan harga pokok yang tak pernah ada.
     */
    private static function batalkanMasuk(MutasiPersediaan $m, ?int $idPengguna): void
    {
        $lapisan = LapisanPersediaan::where('mutasi_id', $m->id)->first();
        if (! $lapisan) {
            return;
        }

        if (Money::lt($lapisan->kuantiti_sisa, $m->kuantiti, 4)) {
            $terpakai = Money::sub($m->kuantiti, $lapisan->kuantiti_sisa, 4);
            throw new AppException(409,
                "Pembatalan ditolak: {$terpakai} dari {$m->kuantiti} "
                ."{$m->persediaan->satuan} {$m->persediaan->nama_persediaan} sudah terlanjur dipakai/dikeluarkan. "
                .'Batalkan dulu pengeluarannya, atau catat penyesuaian lewat Opname.');
        }

        DB::transaction(function () use ($m, $lapisan, $idPengguna) {
            $lapisan->delete();

            // Baris pembalik dibuat LEBIH DULU, baru kolom turunan disegarkan:
            // `stok_masuk`/`stok_keluar` dijumlahkan dari kartu stok, jadi
            // menyegarkan sebelum barisnya ada membuat pembatalan seolah tak
            // pernah terjadi di master.
            $pembalik = self::catat($m->persediaan, [
                'tanggal' => now()->toDateString(),
                'arah' => 'keluar',
                'alasan' => 'pembatalan',
                'kuantiti' => $m->kuantiti,
                'nilai' => $m->nilai,
                'sumber_modul' => $m->sumber_modul,
                'sumber_ref' => $m->sumber_ref,
                'keterangan' => "Pembatalan pemasukan #{$m->id}",
                'id_pengguna' => $idPengguna,
            ]);

            self::segarkanTurunan($m->kode_persediaan);
            self::perbaruiSaldo($pembalik);
        });
    }

    /** Batalkan satu pengeluaran: tiap lapisan yang tergerus dikembalikan persis. */
    private static function batalkanKeluar(MutasiPersediaan $m, ?int $idPengguna): void
    {
        DB::transaction(function () use ($m, $idPengguna) {
            foreach ($m->rincian_lapisan ?? [] as $r) {
                $lapisan = LapisanPersediaan::find($r['lapisan_id']);
                if (! $lapisan) {
                    // Lapisannya sudah ikut terhapus oleh pembatalan pemasukan.
                    // Tak ada yang perlu dipulihkan — dan memaksa membuat lapisan
                    // baru justru menghidupkan stok yang memang sudah dicabut.
                    continue;
                }
                $lapisan->update([
                    'kuantiti_sisa' => Money::add($lapisan->kuantiti_sisa, $r['kuantiti'], 4),
                ]);
            }

            // Urutan sama seperti pembatalan pemasukan: baris pembalik dulu,
            // kolom turunan menyusul.
            $pembalik = self::catat($m->persediaan, [
                'tanggal' => now()->toDateString(),
                'arah' => 'masuk',
                'alasan' => 'pembatalan',
                'kuantiti' => $m->kuantiti,
                'nilai' => $m->nilai,
                'sumber_modul' => $m->sumber_modul,
                'sumber_ref' => $m->sumber_ref,
                'keterangan' => "Pembatalan pengeluaran #{$m->id}",
                'id_pengguna' => $idPengguna,
            ]);

            self::segarkanTurunan($m->kode_persediaan);
            self::perbaruiSaldo($pembalik);
        });
    }

    /**
     * Gerus lapisan tertua sampai kuantiti terpenuhi.
     *
     * @return array{0:string,1:list<array{lapisan_id:int,kuantiti:string,harga_satuan:string,nilai:string}>}
     */
    private static function gerusLapisan(string $kodePersediaan, string $qty): array
    {
        $sisaDiminta = $qty;
        $nilai = '0';
        $rincian = [];

        $lapisan = LapisanPersediaan::where('kode_persediaan', $kodePersediaan)
            ->where('kuantiti_sisa', '>', 0)
            ->orderBy('tanggal')->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($lapisan as $l) {
            if (! Money::gtZero($sisaDiminta, 4)) {
                break;
            }
            $ambil = Money::lte($sisaDiminta, $l->kuantiti_sisa, 4)
                ? $sisaDiminta
                : Money::of($l->kuantiti_sisa, 4);
            $nilaiAmbil = Money::mul($ambil, $l->harga_satuan);

            $l->update(['kuantiti_sisa' => Money::sub($l->kuantiti_sisa, $ambil, 4)]);

            $rincian[] = [
                'lapisan_id' => $l->id,
                'kuantiti' => $ambil,
                'harga_satuan' => Money::of($l->harga_satuan),
                'nilai' => $nilaiAmbil,
            ];
            $nilai = Money::add($nilai, $nilaiAmbil);
            $sisaDiminta = Money::sub($sisaDiminta, $ambil, 4);
        }

        if (Money::gtZero($sisaDiminta, 4)) {
            // Tak seharusnya terjadi — stok sudah diperiksa di `keluar()`. Kalau
            // sampai ke sini, lapisan dan turunannya sudah tak sinkron, dan
            // melanjutkan hanya akan menuliskan angka karangan.
            throw new AppException(500, "Lapisan persediaan {$kodePersediaan} tidak cukup untuk {$qty}; data lapisan tidak sinkron dengan saldo.");
        }

        return [$nilai, $rincian];
    }

    /** Stok saat ini = jumlah sisa seluruh lapisan. */
    public static function stok(string $kodePersediaan): string
    {
        return Money::of(
            LapisanPersediaan::where('kode_persediaan', $kodePersediaan)->sum('kuantiti_sisa'),
            4,
        );
    }

    /** Nilai persediaan saat ini = Σ (sisa × harga) tiap lapisan. */
    public static function nilai(string $kodePersediaan): string
    {
        $nilai = '0';
        foreach (LapisanPersediaan::where('kode_persediaan', $kodePersediaan)
            ->get(['kuantiti_sisa', 'harga_satuan']) as $l) {
            $nilai = Money::add($nilai, Money::mul($l->kuantiti_sisa, $l->harga_satuan));
        }

        return $nilai;
    }

    /**
     * Hitung ulang kolom turunan di master dari lapisan & kartu stok.
     * `stok_masuk`/`stok_keluar` dijumlahkan dari kartu stok supaya tetap
     * mencerminkan lalu lintasnya, bukan sekadar saldo bersihnya.
     */
    public static function segarkanTurunan(string $kodePersediaan): void
    {
        $item = Inventory::find($kodePersediaan);
        if (! $item) {
            return;
        }

        $masuk = Money::of(MutasiPersediaan::where('kode_persediaan', $kodePersediaan)->where('arah', 'masuk')->sum('kuantiti'), 4);
        $keluar = Money::of(MutasiPersediaan::where('kode_persediaan', $kodePersediaan)->where('arah', 'keluar')->sum('kuantiti'), 4);
        $stok = self::stok($kodePersediaan);
        $nilai = self::nilai($kodePersediaan);

        $item->update([
            'stok_masuk' => $masuk,
            'stok_keluar' => $keluar,
            'nilai_persediaan' => $nilai,
            // Harga tampilan = rata-rata lapisan tersisa. Saat stok habis,
            // harga terakhir DIPERTAHANKAN: nol akan membuat pembelian
            // berikutnya dan laporan lama terbaca seolah barangnya tak bernilai.
            'harga_perolehan' => Money::gtZero($stok, 4)
                ? Money::div($nilai, $stok)
                : Money::of($item->harga_perolehan),
        ]);
    }

    private static function catat(Inventory $item, array $data): MutasiPersediaan
    {
        return MutasiPersediaan::create([
            'kode_persediaan' => $item->kode_persediaan,
            'saldo_kuantiti' => '0',
            'saldo_nilai' => '0',
            ...$data,
        ]);
    }

    /** Isi saldo berjalan setelah lapisan diperbarui. */
    private static function perbaruiSaldo(MutasiPersediaan $mutasi): MutasiPersediaan
    {
        $mutasi->update([
            'saldo_kuantiti' => self::stok($mutasi->kode_persediaan),
            'saldo_nilai' => self::nilai($mutasi->kode_persediaan),
        ]);

        return $mutasi->refresh();
    }

    private static function item(string $kodePersediaan): Inventory
    {
        $item = Inventory::find($kodePersediaan);
        if (! $item) {
            throw new AppException(404, "Item persediaan {$kodePersediaan} tidak ditemukan.");
        }

        return $item;
    }
}
