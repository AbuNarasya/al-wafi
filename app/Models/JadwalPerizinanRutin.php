<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Satu jadwal Perizinan Rutin (Penjengukan / Kepulangan) beserta cakupan jenjang × ikhwan/akhwat-nya. */
class JadwalPerizinanRutin extends Model
{
    protected $table = 'jadwal_perizinan_rutin';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['mulai' => 'datetime', 'selesai' => 'datetime', 'khusus' => 'boolean', 'pengingat_pada' => 'datetime'];
    }

    public function tipe(): BelongsTo
    {
        return $this->belongsTo(TipeIzin::class, 'kode_tipe', 'kode');
    }

    public function cakupan(): HasMany
    {
        return $this->hasMany(JadwalPerizinanRutinCakupan::class, 'id_jadwal');
    }

    public function izin(): HasMany
    {
        return $this->hasMany(Perizinan::class, 'id_jadwal');
    }

    /** Santri ini termasuk cakupan jadwal? (jenjang & jenis kelaminnya cocok) */
    public function mencakup(Santri $s): bool
    {
        $this->loadMissing('cakupan');

        return $this->cakupan->contains(fn ($c) => $c->kode_jenjang === $s->kode_jenjang && $c->jenis_kelamin === $s->jenis_kelamin);
    }

    /** Query santri aktif dalam cakupan jadwal ini. */
    public function santriQuery()
    {
        $pasangan = $this->cakupan()->get(['kode_jenjang', 'jenis_kelamin']);

        return Santri::query()->where('status', 'aktif')->where(function ($w) use ($pasangan) {
            $w->whereRaw('1 = 0');
            foreach ($pasangan as $c) {
                $w->orWhere(fn ($x) => $x->where('kode_jenjang', $c->kode_jenjang)->where('jenis_kelamin', $c->jenis_kelamin));
            }
        });
    }

    /** Ringkasan cakupan untuk layar: "SMP Ikhwan, SMA Akhwat". */
    public function labelCakupan(): string
    {
        $this->loadMissing('cakupan');
        $jenjang = Jenjang::pluck('nama', 'kode');

        return $this->cakupan->sortBy(['kode_jenjang', 'jenis_kelamin'])
            ->map(fn ($c) => ($jenjang[$c->kode_jenjang] ?? $c->kode_jenjang).' '.($c->jenis_kelamin === 'L' ? 'Ikhwan' : 'Akhwat'))->join(', ');
    }

    /** Batas akhir wali mengajukan untuk jadwal ini (null = tanpa batas, cukup sebelum mulai). */
    public function batasPengajuan(): ?\Carbon\CarbonInterface
    {
        return $this->tipe?->batasPengajuan($this->mulai);
    }

    public function masihBisaDiajukan(): bool
    {
        $batas = $this->batasPengajuan();

        return $this->status === 'aktif' && now()->lt($this->mulai) && ($batas === null || now()->lte($batas));
    }
}
