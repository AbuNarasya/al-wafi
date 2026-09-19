<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
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
        'harga_perolehan',
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
        ];
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
