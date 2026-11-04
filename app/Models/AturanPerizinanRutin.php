<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** Satu sel matriks Perizinan Rutin: (jenis, jenjang, ikhwan/akhwat) → "hari X ke-N dalam bulan, jam → +D hari jam". */
class AturanPerizinanRutin extends Model
{
    public const HARI = ['Ahad', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    protected $table = 'aturan_perizinan_rutin';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['hari' => 'integer', 'pekan_ke' => 'integer', 'lama_hari' => 'integer'];
    }

    public function tipe(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(TipeIzin::class, 'kode_tipe', 'kode');
    }

    /** Waktu mulai & selesai pada bulan tertentu; null bila bulan itu tak punya hari X yang ke-N (mis. Ahad ke-5). */
    public function waktuPada(int $tahun, int $bulan): ?array
    {
        $awal = CarbonImmutable::create($tahun, $bulan, 1, 0, 0, 0, config('app.timezone'));
        $hari = $awal->addDays(($this->hari - $awal->dayOfWeek + 7) % 7)->addWeeks($this->pekan_ke - 1);
        if ($hari->month !== $bulan) {
            return null;
        }
        [$jm, $mm] = array_map('intval', explode(':', (string) $this->jam_mulai));
        [$js, $ms] = array_map('intval', explode(':', (string) $this->jam_selesai));

        return [$hari->setTime($jm, $mm), $hari->addDays($this->lama_hari)->setTime($js, $ms)];
    }

    /** "Ahad ke-2, 07:00 → Ahad 17:00" */
    public function label(): string
    {
        $selesai = self::HARI[($this->hari + $this->lama_hari) % 7];

        return self::HARI[$this->hari].' ke-'.$this->pekan_ke.', '.substr((string) $this->jam_mulai, 0, 5)
            .' → '.($this->lama_hari > 0 ? $selesai.' ' : '').substr((string) $this->jam_selesai, 0, 5);
    }
}
