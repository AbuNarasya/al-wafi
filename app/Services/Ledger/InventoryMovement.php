<?php

namespace App\Services\Ledger;

use App\Exceptions\AppException;
use App\Models\Inventory;
use App\Models\LapisanPersediaan;
use App\Models\LokasiPersediaan;
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
 *
 * LOKASI (Wafi Mart tahap 1a, 18 Okt 2026): tiap lapisan & baris kartu stok milik satu
 * lokasi, dan FIFO dihitung PER LOKASI — `keluar()` hanya menggerus lapisan di lokasi
 * yang disebut. Tanpa `kode_lokasi` pergerakan jatuh ke `LokasiPersediaan::BAWAAN`,
 * jadi pemanggil lama tetap berjalan seperti dulu. Harga & nilai di master tetap TOTAL
 * seluruh lokasi.
 */
final class InventoryMovement
{
    /**
     * Pemasukan barang: melahirkan satu lapisan FIFO + satu baris kartu stok.
     *
     * @param  array{
     *     kode_persediaan:string, kuantiti:string|int|float, nilai:string|int|float,
     *     kode_lokasi?:?string, tanggal?:string, alasan?:string, sumber_modul:string,
     *     sumber_ref?:?string, journal_entry_id?:?int, keterangan?:?string, id_pengguna?:?int
     * }  $p  `nilai` = TOTAL rupiah yang masuk (bukan harga satuan).
     */
    public static function masuk(array $p): MutasiPersediaan
    {
        $item = self::item($p['kode_persediaan']);
        $lokasi = self::lokasi($p['kode_lokasi'] ?? null);
        $qty = Money::of($p['kuantiti'], 4);
        $nilai = Money::of($p['nilai']);

        if (! Money::gtZero($qty, 4)) {
            throw new AppException(422, 'Kuantiti masuk harus lebih besar dari nol.');
        }
        if (Money::isNegative($nilai)) {
            throw new AppException(422, 'Nilai barang masuk tidak boleh negatif.');
        }

        $tanggal = $p['tanggal'] ?? now()->toDateString();

        return DB::transaction(function () use ($item, $lokasi, $qty, $nilai, $tanggal, $p) {
            $mutasi = self::catat($item, [
                'kode_lokasi' => $lokasi,
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
                'kode_lokasi' => $lokasi,
                'tanggal' => $tanggal,
                'kuantiti_awal' => $qty,
                'kuantiti_sisa' => $qty,
                // Kuantiti pada skala 4 (dulu skala 2: 0,005 → pembagi nol, 0,125 → 0,12).
                'harga_satuan' => bcdiv(Money::of($nilai), Money::of($qty, 4), 6),
                'nilai_sisa' => Money::of($nilai),
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
     *     kode_persediaan:string, kuantiti:string|int|float, kode_lokasi?:?string,
     *     tanggal?:string, alasan?:string, sumber_modul:string, sumber_ref?:?string,
     *     journal_entry_id?:?int, keterangan?:?string, id_pengguna?:?int,
     *     lapisan_sumber?:array{0:string,1:string}
     * }  $p  `lapisan_sumber` = [sumber_modul, sumber_ref]: hanya lapisan yang lahir dari
     *        dokumen itu yang boleh digerus. Dipakai lokasi TRANSIT, tempat barang beberapa
     *        transfer bisa bercampur — tanpa ini, menerima transfer A bisa memakan harga
     *        pokok milik transfer B.
     */
    public static function keluar(array $p): MutasiPersediaan
    {
        $item = self::item($p['kode_persediaan']);
        $lokasi = self::lokasi($p['kode_lokasi'] ?? null);
        $qty = Money::of($p['kuantiti'], 4);
        $sumber = $p['lapisan_sumber'] ?? null;

        if (! Money::gtZero($qty, 4)) {
            throw new AppException(422, 'Kuantiti keluar harus lebih besar dari nol.');
        }

        $tersedia = $sumber
            ? Money::of(LapisanPersediaan::where('kode_persediaan', $item->kode_persediaan)->where('kode_lokasi', $lokasi)
                ->where('sumber_modul', $sumber[0])->where('sumber_ref', $sumber[1])->sum('kuantiti_sisa'), 4)
            : self::stok($item->kode_persediaan, $lokasi);
        if (Money::gt($qty, $tersedia, 4)) {
            // Nama lokasi disebut hanya bila memang ada lebih dari satu — pesan lama
            // tetap utuh bagi pesantren yang cuma punya gudang utama.
            $di = LokasiPersediaan::where('jenis', '!=', 'transit')->count() > 1 || $lokasi === LokasiPersediaan::TRANSIT
                ? ' di '.LokasiPersediaan::whereKey($lokasi)->value('nama') : '';
            throw new AppException(422, "Stok {$item->nama_persediaan}{$di} tidak cukup: diminta {$qty}, tersedia {$tersedia}.");
        }

        return DB::transaction(function () use ($item, $lokasi, $qty, $p, $sumber) {
            [$nilai, $rincian] = self::gerusLapisan($item->kode_persediaan, $lokasi, $qty, $sumber);

            $mutasi = self::catat($item, [
                'kode_lokasi' => $lokasi,
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
        DB::transaction(fn () => self::batalkanMasukTerkunci($m, $idPengguna));
    }

    private static function batalkanMasukTerkunci(MutasiPersediaan $m, ?int $idPengguna): void
    {
        // Dikunci sebelum diperiksa: penjualan serentak tak boleh menggerus lapisan yang sedang dicabut.
        $lapisan = LapisanPersediaan::where('mutasi_id', $m->id)->lockForUpdate()->first();
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
                'kode_lokasi' => $m->kode_lokasi,
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
                // Dikunci: penjualan yang serentak menggerus lapisan yang sama dulu bisa menimpa
                // pengembalian ini (lost update) — audit 22 Okt 2026.
                $lapisan = LapisanPersediaan::whereKey($r['lapisan_id'])->lockForUpdate()->first();
                if (! $lapisan) {
                    // Lapisannya sudah ikut terhapus oleh pembatalan pemasukan.
                    // Tak ada yang perlu dipulihkan — dan memaksa membuat lapisan
                    // baru justru menghidupkan stok yang memang sudah dicabut.
                    continue;
                }
                $lapisan->update([
                    'kuantiti_sisa' => Money::add($lapisan->kuantiti_sisa, $r['kuantiti'], 4),
                    'nilai_sisa' => Money::add($lapisan->nilai_sisa, $r['nilai']),
                ]);
            }

            // Urutan sama seperti pembatalan pemasukan: baris pembalik dulu,
            // kolom turunan menyusul.
            $pembalik = self::catat($m->persediaan, [
                'kode_lokasi' => $m->kode_lokasi,
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
    private static function gerusLapisan(string $kodePersediaan, string $lokasi, string $qty, ?array $sumber = null): array
    {
        $sisaDiminta = $qty;
        $nilai = '0';
        $rincian = [];

        $lapisan = LapisanPersediaan::where('kode_persediaan', $kodePersediaan)
            ->where('kode_lokasi', $lokasi)
            ->when($sumber, fn ($q) => $q->where('sumber_modul', $sumber[0])->where('sumber_ref', $sumber[1]))
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
            // Habis → sisa nilainya PERSIS; sebagian → porsinya dari sisa nilai (dibulatkan). Selama hidup lapisan,
            // nilai keluar = nilai masuk tanpa sen tertinggal (audit 21 Okt 2026).
            $habis = Money::eq($ambil, $l->kuantiti_sisa, 4);
            $nilaiAmbil = $habis
                ? Money::of($l->nilai_sisa)
                : self::bulat(bcdiv(bcmul(Money::of($l->nilai_sisa), $ambil, 8), Money::of($l->kuantiti_sisa, 4), 8));

            $l->update([
                'kuantiti_sisa' => Money::sub($l->kuantiti_sisa, $ambil, 4),
                'nilai_sisa' => $habis ? '0.00' : Money::sub($l->nilai_sisa, $nilaiAmbil),
            ]);

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

    /** Stok saat ini = jumlah sisa lapisan — seluruh lokasi, atau satu lokasi bila disebut. */
    public static function stok(string $kodePersediaan, ?string $kodeLokasi = null): string
    {
        return Money::of(
            LapisanPersediaan::where('kode_persediaan', $kodePersediaan)
                ->when($kodeLokasi, fn ($q) => $q->where('kode_lokasi', $kodeLokasi))
                ->sum('kuantiti_sisa'),
            4,
        );
    }

    /**
     * Stok per lokasi untuk banyak barang sekaligus (satu kueri, untuk daftar).
     *
     * @param  list<string>|null  $kodePersediaan  null = semua barang
     * @return array<string,array<string,string>> [kode_persediaan][kode_lokasi] => stok
     */
    public static function stokPerLokasi(?array $kodePersediaan = null): array
    {
        $hasil = [];
        LapisanPersediaan::query()
            ->when($kodePersediaan !== null, fn ($q) => $q->whereIn('kode_persediaan', $kodePersediaan))
            ->groupBy('kode_persediaan', 'kode_lokasi')
            ->selectRaw('kode_persediaan, kode_lokasi, SUM(kuantiti_sisa) AS stok')
            ->get()
            ->each(function ($r) use (&$hasil) {
                $hasil[$r->kode_persediaan][$r->kode_lokasi] = Money::of($r->stok, 4);
            });

        return $hasil;
    }

    /** Nilai persediaan saat ini = Σ sisa nilai tiap lapisan (hanya yang masih bersisa). */
    public static function nilai(string $kodePersediaan, ?string $kodeLokasi = null): string
    {
        return Money::of(LapisanPersediaan::where('kode_persediaan', $kodePersediaan)
            ->when($kodeLokasi, fn ($q) => $q->where('kode_lokasi', $kodeLokasi))
            ->where('kuantiti_sisa', '>', 0)->sum('nilai_sisa'));
    }

    /** Bulatkan ke 2 desimal (setengah menjauhi nol) — bcmath hanya memotong. */
    private static function bulat(string $v): string
    {
        return bcadd($v, str_starts_with(ltrim($v), '-') ? '-0.005' : '0.005', 2);
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
                ? bcdiv(Money::of($nilai), Money::of($stok, 4), 2)   // stok skala 4: 0,005 tak jadi pembagi nol
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
            'saldo_kuantiti_lokasi' => self::stok($mutasi->kode_persediaan, $mutasi->kode_lokasi),
        ]);

        return $mutasi->refresh();
    }

    /** Lokasi yang disebut (wajib ada & aktif), atau lokasi bawaan bila tak disebut. */
    private static function lokasi(?string $kode): string
    {
        $kode = $kode ?: LokasiPersediaan::BAWAAN;
        $status = LokasiPersediaan::whereKey($kode)->value('status');
        if ($status === null) {
            throw new AppException(404, "Lokasi persediaan {$kode} tidak ditemukan.");
        }
        if ($status !== 'aktif') {
            throw new AppException(422, 'Lokasi '.LokasiPersediaan::whereKey($kode)->value('nama').' sudah nonaktif — stok tidak bisa digerakkan di sana.');
        }

        return $kode;
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
