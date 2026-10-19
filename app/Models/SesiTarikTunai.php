<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Sesi penarikan tunai kegiatan — lihat [[App\Services\Modules\PenarikanTunaiService]]. */
class SesiTarikTunai extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'sesi-tarik-tunai';

    protected $table = 'sesi_tarik_tunai';

    protected $primaryKey = 'id_sesi';

    public const LABEL_TAHAP = [
        'pengajuan' => 'Pengajuan wali', 'persetujuan' => 'Persetujuan', 'siap_ambil' => 'Siap ambil di bank',
        'distribusi' => 'Distribusi', 'selesai' => 'Selesai', 'batal' => 'Batal',
    ];

    protected $fillable = [
        'nomor', 'nama', 'keterangan', 'batas_pengajuan', 'batas_persetujuan', 'tanggal_ambil', 'tanggal_distribusi',
        'maks_per_santri', 'kode_coa_bank', 'kode_coa_kas', 'dibuka', 'status', 'alasan_batal', 'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'batas_pengajuan' => 'datetime', 'batas_persetujuan' => 'datetime', 'tanggal_ambil' => 'date',
            'tanggal_distribusi' => 'date', 'maks_per_santri' => 'decimal:2', 'dibuka' => 'boolean',
        ];
    }

    /**
     * Tahap DIHITUNG dari tanggal (keputusan user 19 Okt 2026: tanpa penjadwal). Hanya langkah
     * yang dilakukan orang — dana diambil, selesai, batal — yang tersimpan di `status`.
     */
    public function tahap(): string
    {
        return match ($this->status) {
            'selesai', 'batal' => $this->status,
            'dana_diambil' => 'distribusi',
            default => match (true) {
                now()->lte($this->batas_pengajuan) => 'pengajuan',
                now()->lte($this->batas_persetujuan) => 'persetujuan',
                default => 'siap_ambil',
            },
        };
    }

    public function labelTahap(): string
    {
        return self::LABEL_TAHAP[$this->tahap()];
    }

    public function santri(): BelongsToMany
    {
        return $this->belongsToMany(Santri::class, 'peserta_tarik_tunai', 'id_sesi', 'id_santri')->withTimestamps();
    }

    public function pengajuan(): HasMany
    {
        return $this->hasMany(PengajuanTarikTunai::class, 'id_sesi');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'kode_coa_bank', 'kode_coa');
    }

    public function kas(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'kode_coa_kas', 'kode_coa');
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh', 'id_pengguna');
    }
}
