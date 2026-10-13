<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rekening kas/bank. PK = FK ke coa_detail (satu akun kas/bank = satu COA).
 */
class BankAccount extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'bank-accounts';

    protected $table = 'bank_accounts';

    protected $primaryKey = 'kode_coa';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_coa',
        'nama_rekening',
        'jenis_rekening',
        'nama_bank',
        'no_rekening',
        'status',
        'tampil_di_portal',
        'atas_nama',
    ];

    protected function casts(): array
    {
        return ['tampil_di_portal' => 'boolean'];
    }

    /**
     * Rekening tujuan transfer yang ditunjukkan kepada wali — hanya bank aktif
     * yang dipilih keuangan di Keuangan → Rekening Portal Wali.
     */
    public static function untukPortal()
    {
        return static::where('tampil_di_portal', true)->where('status', 'aktif')
            ->where('jenis_rekening', 'bank')->whereNotNull('no_rekening')
            ->orderBy('nama_bank')->orderBy('kode_coa')->get();
    }

    public function coa(): BelongsTo
    {
        return $this->belongsTo(CoaDetail::class, 'kode_coa', 'kode_coa');
    }
}
