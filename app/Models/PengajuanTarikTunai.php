<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pengajuan tarik tunai kegiatan dari Portal Wali — lihat [[App\Services\Modules\PenarikanTunaiService]]. */
class PengajuanTarikTunai extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'penarikan-tunai';

    protected $table = 'pengajuan_tarik_tunai';

    public const LABEL = [
        'diajukan' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan',
        'gugur' => 'Gugur', 'diserahkan' => 'Sudah diterima', 'tak_diambil' => 'Tidak diambil',
    ];

    protected $fillable = [
        'nomor', 'id_sesi', 'id_santri', 'id_wali', 'peran', 'nominal', 'nominal_disetujui', 'status', 'alasan_tolak',
        'diputus_oleh', 'diputus_pada',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'nominal_disetujui' => 'decimal:2', 'diputus_pada' => 'datetime'];
    }

    /** Status yang dilihat orang: `diajukan` yang melewati batas persetujuan sesinya = gugur. */
    public function statusEfektif(): string
    {
        if ($this->status === 'diajukan' && $this->sesi && now()->gt($this->sesi->batas_persetujuan)) {
            return 'gugur';
        }

        return $this->status;
    }

    public function labelStatus(): string
    {
        return self::LABEL[$this->statusEfektif()];
    }

    /** Nominal yang (akan) diserahkan: hasil putusan bila sudah disetujui. */
    public function nominalBerlaku(): string
    {
        return (string) ($this->nominal_disetujui ?? $this->nominal);
    }

    public function sesi(): BelongsTo
    {
        return $this->belongsTo(SesiTarikTunai::class, 'id_sesi');
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh', 'id_pengguna');
    }

    public function namaPengaju(): string
    {
        return ($this->wali?->{'nama_'.$this->peran} ?: $this->wali?->nama) ?? '-';
    }
}
