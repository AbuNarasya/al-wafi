<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tipe Perizinan Insidental — lima tipe tetap; batas waktu & saklar aktif diatur di Pengaturan Perizinan. */
class TipeIzin extends Model
{
    protected $table = 'tipe_izin';

    protected $primaryKey = 'kode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pulang' => 'boolean', 'wajib_keperluan' => 'boolean', 'aktif' => 'boolean', 'batas_hari' => 'integer', 'rutin' => 'boolean', 'tunggakan_periode' => 'integer'];
    }

    /** "H-14", atau null bila tanpa batas pengajuan. */
    public function labelBatas(): ?string
    {
        return $this->batas_hari === null ? null : 'H-'.$this->batas_hari;
    }

    /** Hari terakhir wali boleh mengajukan untuk keluar pada $keluar (null = tanpa batas). */
    public function batasPengajuan(\Carbon\CarbonInterface $keluar): ?\Carbon\CarbonInterface
    {
        return $this->batas_hari === null ? null
            : $keluar->copy()->timezone('Asia/Jakarta')->startOfDay()->subDays($this->batas_hari)->endOfDay();
    }
}
