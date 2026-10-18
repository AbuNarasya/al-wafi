<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master persediaan — identitas barang & akun-akunnya, diisi BAGIAN KEUANGAN.
 *
 * Sumber kebenaran stok ada di [[LapisanPersediaan]] (lot FIFO) dan riwayatnya
 * di [[MutasiPersediaan]] (kartu stok). Empat kolom di sini — `stok_masuk`,
 * `stok_keluar`, `harga_perolehan`, `nilai_persediaan` — adalah TURUNAN yang
 * dihitung ulang oleh `InventoryMovement::segarkanTurunan()` tiap kali stok
 * bergerak. Jangan menulisinya langsung: angkanya akan tertimpa pada pergerakan
 * berikutnya, dan sementara itu laporan menampilkan yang salah.
 *
 * `harga_perolehan` = rata-rata lapisan yang MASIH TERSISA (untuk ditampilkan).
 * Untuk nilai rupiahnya pakai `nilai_persediaan`, bukan stok × harga_perolehan:
 * harga sudah dibulatkan 2 desimal, dan perkaliannya meleset beberapa rupiah.
 */
class Inventory extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'inventory';

    /**
     * Kolom turunan tak perlu ikut jejak: ia bergerak pada SETIAP pergerakan
     * stok, dan riwayat sesungguhnya sudah tersimpan utuh di kartu stok.
     */
    protected $jejakAbaikan = ['stok_masuk', 'stok_keluar', 'nilai_persediaan', 'harga_perolehan'];

    protected $table = 'inventory';

    protected $primaryKey = 'kode_persediaan';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_persediaan',
        'nama_persediaan',
        'satuan',
        'kode_kategori',
        'kode_merk',
        'harga_perolehan',
        'harga_jual',
        'stok_minimum',
        'dijual_di_toko',
        'stok_masuk',
        'stok_keluar',
        'nilai_persediaan',
        'kode_coa',
        'kode_coa_beban',
        'kode_coa_selisih',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'harga_perolehan' => 'decimal:2',
            'stok_masuk' => 'decimal:4',
            'stok_keluar' => 'decimal:4',
            'nilai_persediaan' => 'decimal:2',
            'harga_jual' => 'decimal:2',
            'stok_minimum' => 'decimal:4',
            'dijual_di_toko' => 'boolean',
        ];
    }

    public function kategori(): BelongsTo
    {
        return $this->belongsTo(KategoriBarang::class, 'kode_kategori', 'kode');
    }

    public function merk(): BelongsTo
    {
        return $this->belongsTo(MerkBarang::class, 'kode_merk', 'kode');
    }

    /** Satuan konversi selain satuan dasar (DUS = 24 PCS, …). */
    public function satuanLain(): HasMany
    {
        return $this->hasMany(SatuanPersediaan::class, 'kode_persediaan', 'kode_persediaan')->orderBy('isi');
    }

    /**
     * Satuan yang disebut petugas → [nama satuan, isi dalam satuan dasar].
     * Kosong / sama dengan satuan dasar → [satuan dasar, '1']. null = satuan tak dikenal.
     *
     * @return array{0:string,1:string}|null
     */
    public function satuanKe(?string $satuan): ?array
    {
        $satuan = trim((string) $satuan);
        if ($satuan === '' || mb_strtolower($satuan) === mb_strtolower((string) $this->satuan)) {
            return [(string) $this->satuan, '1'];
        }
        $konversi = $this->satuanLain()->getQuery()->whereRaw('LOWER(satuan) = ?', [mb_strtolower($satuan)])->first();

        return $konversi ? [$konversi->satuan, \App\Support\Money::of($konversi->isi, 4)] : null;
    }

    public function barcode(): HasMany
    {
        return $this->hasMany(BarcodePersediaan::class, 'kode_persediaan', 'kode_persediaan')->orderBy('barcode');
    }

    public function lapisan(): HasMany
    {
        return $this->hasMany(LapisanPersediaan::class, 'kode_persediaan', 'kode_persediaan');
    }

    public function mutasi(): HasMany
    {
        return $this->hasMany(MutasiPersediaan::class, 'kode_persediaan', 'kode_persediaan');
    }
}
