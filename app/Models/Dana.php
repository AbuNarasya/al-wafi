<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master dana: wakaf, donasi berperuntukan, beasiswa, dana bantuan.
 *
 * `jenis` memakai kosakata pelaporan nirlaba:
 *  - `tidak_terikat`    — boleh dipakai untuk apa saja
 *  - `terikat_temporer` — batasnya gugur bila peruntukannya tercapai atau
 *                         masanya lewat; sesudah itu ia pindah ke tidak terikat
 *  - `terikat_permanen` — pokoknya tak boleh dipakai selamanya (wakaf abadi)
 *
 * `akun` = daftar akun beban yang BOLEH dibebani dana ini. Kosong berarti tak
 * ada pembatasan akun. Lihat [[App\Services\Ledger\DanaPolicy]].
 */
class Dana extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'dana';

    protected $table = 'dana';

    protected $primaryKey = 'kode_dana';

    public $incrementing = false;

    protected $keyType = 'string';

    public const JENIS = [
        'tidak_terikat' => 'Tidak Terikat',
        'terikat_temporer' => 'Terikat Temporer',
        'terikat_permanen' => 'Terikat Permanen',
    ];

    protected $fillable = [
        'kode_dana', 'nama_dana', 'jenis', 'donatur', 'peruntukan',
        'tanggal_mulai', 'tanggal_selesai', 'target_nominal', 'status', 'urutan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'target_nominal' => 'decimal:2',
            'urutan' => 'integer',
        ];
    }

    public function akun(): HasMany
    {
        return $this->hasMany(DanaAkun::class, 'kode_dana', 'kode_dana');
    }

    public function terikat(): bool
    {
        return $this->jenis !== 'tidak_terikat';
    }

    public function labelJenis(): string
    {
        return self::JENIS[$this->jenis] ?? $this->jenis;
    }
}
