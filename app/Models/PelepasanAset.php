<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dokumen pelepasan aset tetap: dijual, dihibahkan, atau dihapuskan.
 *
 * Kolom `nilai_perolehan`, `akumulasi`, `nilai_buku`, `laba_rugi` adalah POTRET
 * keadaan aset saat dilepas — bukan bacaan langsung dari masternya. Master aset
 * masih ada dan masih bisa berubah; dokumen ini harus tetap bisa menjelaskan
 * dirinya sendiri bertahun-tahun kemudian.
 */
class PelepasanAset extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'assets';

    protected $table = 'pelepasan_aset';

    public const PERLAKUAN = [
        'dijual' => 'Dijual',
        'dihibahkan' => 'Dihibahkan',
        'dihapuskan' => 'Dihapuskan (rusak / usang)',
    ];

    protected $fillable = [
        'nomor_ref', 'kode_aset', 'tanggal', 'perlakuan',
        'harga_jual', 'kode_rekening', 'kode_coa_akumulasi', 'kode_coa_labarugi',
        'kode_unit', 'kode_bagian',
        'nilai_perolehan', 'akumulasi', 'nilai_buku', 'laba_rugi',
        'alasan', 'status', 'journal_entry_id', 'id_pengguna',
        'void_reason', 'void_by', 'void_at',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'harga_jual' => 'decimal:2',
            'nilai_perolehan' => 'decimal:2',
            'akumulasi' => 'decimal:2',
            'nilai_buku' => 'decimal:2',
            'laba_rugi' => 'decimal:2',
            'void_at' => 'datetime',
        ];
    }

    public function aset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'kode_aset', 'kode_aset');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function labelPerlakuan(): string
    {
        return self::PERLAKUAN[$this->perlakuan] ?? $this->perlakuan;
    }

    /** Penjualan dinilai untung/rugi; hibah & penghapusan selalu beban. */
    public function untung(): bool
    {
        return (float) $this->laba_rugi > 0;
    }
}
