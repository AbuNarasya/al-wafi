<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pengajuan pengunduran diri santri aktif — lihat migrasi 000091 & PengunduranDiriService. */
class PengajuanKeluar extends Model
{
    protected $table = 'pengajuan_keluar';

    protected $guarded = ['id'];

    public const LABEL = [
        'menunggu_wali_kelas' => 'Menunggu verifikasi wali kelas', 'dikembalikan' => 'Dikembalikan ke wali',
        'menunggu_kepala_sekolah' => 'Menunggu persetujuan kepala sekolah', 'ditolak' => 'Tidak disetujui',
        'clearance' => 'Proses clearance', 'siap_dikeluarkan' => 'Siap dikeluarkan (akhir bulan)',
        'selesai' => 'Selesai — santri dikeluarkan', 'dibatalkan' => 'Dibatalkan wali',
    ];

    /** Status yang masih berjalan — satu santri paling banyak satu pengajuan berjalan. */
    public const BERJALAN = ['menunggu_wali_kelas', 'dikembalikan', 'menunggu_kepala_sekolah', 'clearance', 'siap_dikeluarkan'];

    /** Tiga pihak clearance (kolom `{pihak}_oleh/_pada/_catatan`). */
    public const CLEARANCE = ['keuangan' => 'Keuangan (tagihan & tunggakan)', 'kesantrian' => 'Kesantrian (keasramaan)', 'admin' => 'Admin sekolah (surat mutasi, SK)'];

    protected function casts(): array
    {
        $c = ['rencana_tanggal' => 'date', 'dikeluarkan_pada' => 'datetime', 'dapodik_pada' => 'datetime'];
        foreach (['wali_kelas', 'kepala_sekolah', 'keuangan', 'kesantrian', 'admin'] as $t) {
            $c["{$t}_pada"] = 'datetime';
        }

        return $c;
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri');
    }

    public function wali(): BelongsTo
    {
        return $this->belongsTo(Wali::class, 'id_wali');
    }

    public function dokumen(): HasMany
    {
        return $this->hasMany(DokumenPengajuanKeluar::class, 'id_pengajuan');
    }

    public function oleh(string $tahap): ?User
    {
        return ($id = $this->{"{$tahap}_oleh"}) ? User::find($id) : null;
    }

    public function labelStatus(): string
    {
        return self::LABEL[$this->status] ?? $this->status;
    }
}
