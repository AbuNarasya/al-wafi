<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Akun detail (level 4). jenis_saldo (debet/kredit) = sisi normal akun.
 */
class CoaDetail extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'coa-detail';

    protected $table = 'coa_detail';

    protected $primaryKey = 'kode_coa';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'kode_coa',
        'nama_coa',
        'kode_grup',
        'jenis_saldo',
        // operasi | investasi | pendanaan — kelompok akun ini di Laporan Arus
        // Kas. Kosong = belum ditentukan; laporannya menampilkannya terpisah,
        // bukan menebak.
        'klasifikasi_arus_kas',
        'status',
        'keterangan',
    ];

    /**
     * Klasifikasi arus kas bawaan saat akun BARU dibuat tanpa menyebutnya.
     *
     * Aturannya sama persis dengan yang dipakai migrasi pengisian awal, dan
     * sengaja ditaruh di sini juga: migrasi hanya menyentuh akun yang sudah
     * ada saat itu, sehingga setiap akun yang dibuat sesudahnya akan lahir
     * tanpa klasifikasi dan diam-diam menumpuk di kelompok "Belum
     * Diklasifikasikan" — persis masalah yang hendak dihindari.
     *
     * Pendapatan & beban → operasi. Ekuitas → pendanaan. Aset & liabilitas
     * DIBIARKAN kosong: piutang itu operasi, aset tetap itu investasi,
     * pinjaman itu pendanaan, dan tak ada cara menebaknya dari nomor akun.
     */
    protected static function booted(): void
    {
        static::creating(function (self $akun) {
            if ($akun->klasifikasi_arus_kas !== null) {
                return;
            }
            $akun->klasifikasi_arus_kas = match (self::akarKelompok($akun->kode_grup)) {
                '4', '5' => 'operasi',
                '3' => 'pendanaan',
                default => null,
            };
        });
    }

    /** Telusuri grup ke atas sampai akar kelompoknya (1..5). */
    public static function akarKelompok(?string $kodeGrup): ?string
    {
        $cur = $kodeGrup ? CoaGroup::find($kodeGrup) : null;
        $lihat = [];
        while ($cur && $cur->kode_induk && ! in_array($cur->kode_grup, $lihat, true)) {
            $lihat[] = $cur->kode_grup;
            $cur = CoaGroup::find($cur->kode_induk);
        }

        return $cur?->kode_grup;
    }

    public function grup(): BelongsTo
    {
        return $this->belongsTo(CoaGroup::class, 'kode_grup', 'kode_grup');
    }

    public function bankAccount(): HasOne
    {
        return $this->hasOne(BankAccount::class, 'kode_coa', 'kode_coa');
    }
}
