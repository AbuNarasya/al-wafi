<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tempat stok berada — gudang, toko (Koperasi Banin/Banat), atau transit.
 *
 * FIFO dihitung PER LOKASI (lihat [[App\Services\Ledger\InventoryMovement]]). `L001`
 * lahir bersama migrasinya dan menampung stok lama; pemanggil yang tak menyebut lokasi
 * (invoice, kas keluar/masuk, jurnal umum) jatuh ke sana.
 */
class LokasiPersediaan extends Model
{
    use MencatatJejak;

    /** Lokasi cadangan untuk pergerakan yang tak menyebut lokasi. */
    public const BAWAAN = 'L001';

    /**
     * Lokasi SISTEM "Dalam Perjalanan" — barang transfer yang sudah dikirim tetapi belum
     * diterima, atau selisih kirim–terima yang menunggu putusan. Tak bisa dipilih petugas.
     */
    public const TRANSIT = 'TRANSIT';

    public const JENIS = ['gudang' => 'Gudang', 'toko' => 'Toko'];

    protected $jejakModul = 'lokasi-persediaan';

    protected $table = 'lokasi_persediaan';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode', 'nama', 'jenis', 'kode_unit', 'status', 'urutan',
        // Akun kasir toko (Wafi Mart tahap 2a).
        'kode_coa_kas', 'kode_coa_pendapatan', 'kode_coa_hpp', 'kode_coa_selisih_kas', 'kode_bagian',
        'batas_hari_retur',
    ];

    /** Akun kasir yang wajib lengkap sebelum shift pertama dibuka. */
    public const AKUN_KASIR = [
        'kode_coa_kas' => 'Kas Laci',
        'kode_coa_pendapatan' => 'Pendapatan Penjualan',
        'kode_coa_hpp' => 'Harga Pokok Penjualan',
        'kode_coa_selisih_kas' => 'Selisih Kas',
        'kode_bagian' => 'Bagian pengelola toko',
    ];

    /** @return list<string> label akun kasir yang belum diisi */
    public function akunKasirKurang(): array
    {
        return array_values(array_filter(self::AKUN_KASIR, fn ($label, $kolom) => empty($this->{$kolom}), ARRAY_FILTER_USE_BOTH));
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    /** @return array<string,string> kode => nama, lokasi aktif yang boleh dipilih petugas (tanpa TRANSIT). */
    public static function pilihan(): array
    {
        return self::where('status', 'aktif')->where('jenis', '!=', 'transit')
            ->orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();
    }

    /** @return array<string,string> kode => nama SEMUA lokasi, termasuk nonaktif & TRANSIT — untuk menampilkan. */
    public static function semuaNama(): array
    {
        return self::orderBy('urutan')->orderBy('kode')->pluck('nama', 'kode')->all();
    }
}
