<?php

namespace App\Models;

use App\Services\Ppsb\Tahap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SATU SIKLUS PENERIMAAN: berkas, nilai seleksi, med check, dan tahapannya.
 *
 * Satu santri bisa punya BEBERAPA baris — sekali saat masuk (`jenis` = `baru`),
 * lalu sekali lagi tiap naik jenjang (`jenis` = `lanjutan`). Karena itu status
 * tahapan tinggal di sini, bukan hanya di `santri.status`: santri kelas akhir yang
 * sedang mendaftar ke jenjang berikutnya tetap berstatus AKTIF sepanjang proses.
 *
 * Rantai tahapannya berbeda menurut `jenis` — lihat Tahap::TRANSISI_LANJUTAN.
 */
class Pendaftaran extends Model
{
    protected $table = 'pendaftaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'verifikasi_ok' => 'boolean',
            'nilai_baca' => 'decimal:2',
            'nilai_akademik' => 'decimal:2',
            'medcheck_ok' => 'boolean',
            'dokumen_lengkap' => 'boolean',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function jalur(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'kode_jalur', 'kode');
    }

    /** Siklus yang masih berjalan (belum berakhir menurut rantai `jenis`-nya). */
    public function scopeTerbuka(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w
            ->where(fn ($b) => $b->where('jenis', 'lanjutan')->whereNotIn('status', Tahap::FINAL_LANJUTAN))
            ->orWhere(fn ($b) => $b->where('jenis', '!=', 'lanjutan')->whereNotIn('status', Tahap::STATUS_FINAL)));
    }

    public function scopeLanjutan(Builder $q): Builder
    {
        return $q->where('jenis', 'lanjutan');
    }

    /** Label tahapan yang dibaca pemakai. */
    public function labelStatus(): string
    {
        return Tahap::labelStatus((string) $this->status);
    }
}
