<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu pengajuan Perizinan Insidental — lihat App\Services\Modules\PerizinanService. */
class Perizinan extends Model
{
    protected $table = 'perizinan';

    protected $guarded = ['id'];

    public const STATUS = [
        'menunggu_1' => 'Menunggu persetujuan tingkat 1', 'menunggu_2' => 'Menunggu persetujuan', 'disetujui' => 'Disetujui',
        'keluar' => 'Sedang di luar', 'kembali' => 'Sudah kembali', 'ditolak' => 'Ditolak', 'dibatalkan' => 'Dibatalkan',
    ];

    /** Masih "hidup": menghalangi pengajuan lain yang rentangnya bertumpuk. */
    public const AKTIF = ['menunggu_1', 'menunggu_2', 'disetujui', 'keluar'];

    public const MENUNGGU = ['menunggu_1', 'menunggu_2'];

    protected function casts(): array
    {
        $c = ['rencana_keluar' => 'datetime', 'rencana_kembali' => 'datetime', 'dibatalkan_pada' => 'datetime', 'disetujui_otomatis' => 'boolean'];
        foreach (['tingkat1', 'tingkat2', 'ditolak', 'keluar', 'kembali'] as $l) {
            $c["{$l}_pada"] = 'datetime';
        }

        return $c;
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(JadwalPerizinanRutin::class, 'id_jadwal');
    }

    public function tipe(): BelongsTo
    {
        return $this->belongsTo(TipeIzin::class, 'kode_tipe', 'kode');
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali');
    }

    public function labelStatus(): string
    {
        return $this->terlambat() ? 'Terlambat kembali' : (self::STATUS[$this->status] ?? $this->status);
    }

    /** Belum kembali padahal jadwal kembali sudah lewat — atau kembali lebih lambat dari rencana. */
    public function terlambat(): bool
    {
        return ($this->status === 'keluar' && $this->rencana_kembali?->isPast())
            || ($this->status === 'kembali' && $this->kembali_pada && $this->rencana_kembali && $this->kembali_pada->gt($this->rencana_kembali));
    }
}
