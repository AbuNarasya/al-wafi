<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pengaturan perusahaan — baris tunggal (singleton, id=1).
 * Hanya punya kolom updated_at (tanpa created_at).
 */
class CompanySettings extends Model
{
    protected $table = 'company_settings';

    public $incrementing = false;

    protected $keyType = 'int';

    public const CREATED_AT = null;

    protected $fillable = [
        'id',
        'nama_perusahaan',
        'jenis_perusahaan',
        'alamat',
        'telepon',
        'email',
        'npwp',
        'mata_uang',
        'periode_awal_pembukuan',
        'bulan_awal_anggaran',
        'topup_tunai_dompet_santri',
        'kode_unit_neraca',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'periode_awal_pembukuan' => 'date',
            'bulan_awal_anggaran' => 'integer',
            'topup_tunai_dompet_santri' => 'boolean',
        ];
    }
}
