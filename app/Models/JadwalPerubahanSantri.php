<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu perubahan yang SUDAH DITETAPKAN tetapi BELUM berlaku — lihat migrasi
 * `jadwal_perubahan_santri` untuk arti tiap status.
 */
class JadwalPerubahanSantri extends Model
{
    protected $table = 'jadwal_perubahan_santri';

    protected $guarded = ['id'];

    /** Status yang belum menyala; keduanya menahan indeks unik per (santri, T.A). */
    public const HIDUP = ['siap', 'menunggu_ppsb'];

    protected function casts(): array
    {
        return [
            'tingkat_tujuan' => 'integer',
            'tanggal_lulus' => 'date',
            'ditetapkan_pada' => 'datetime',
            'diterapkan_pada' => 'datetime',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function pendaftaran(): BelongsTo
    {
        return $this->belongsTo(Pendaftaran::class, 'id_pendaftaran');
    }

    /** Jenjang & jalur tujuan disebut lewat NAMA di layar, bukan kode `J003`. */
    public function jenjangTujuan(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang_tujuan', 'kode');
    }

    public function jalurTujuan(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'kode_jalur_tujuan', 'kode');
    }

    public function scopeHidup(Builder $q): Builder
    {
        return $q->whereIn('status', self::HIDUP);
    }

    /**
     * Jadwal yang sudah waktunya menyala: berstatus `siap` DAN tahun ajaran
     * tujuannya sudah dimulai menurut kalender.
     *
     * Dibandingkan lewat `tanggal_mulai`, bukan kode: kode "2026/2027" kebetulan
     * terurut benar secara abjad, tetapi kalenderlah yang menentukan.
     */
    public function scopeJatuhTempo(Builder $q, ?string $tanggal = null): Builder
    {
        $tanggal ??= now()->toDateString();

        return $q->where('status', 'siap')
            ->whereIn('tahun_ajaran', TahunAjaran::whereNotNull('tanggal_mulai')
                ->whereDate('tanggal_mulai', '<=', $tanggal)->select('kode'));
    }
}
