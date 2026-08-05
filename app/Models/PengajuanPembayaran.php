<?php

namespace App\Models;

use App\Support\LingkupBagian;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pengajuan Pembayaran (§4). jenis: pembayaran | uang_muka | penyelesaian_uang_muka.
 * Unit melekat di baris. kode_coa_hutang ditetapkan keuangan saat verifikasi.
 */
class PengajuanPembayaran extends Model
{
    protected $table = 'pengajuan_pembayaran';

    protected $fillable = [
        'nomor', 'tanggal', 'jenis', 'kode_bagian', 'kode_coa_hutang',
        'nominal', 'sisa_hutang', 'sisa_kurang_bayar', 'keterangan', 'referensi', 'status',
        'bank_tujuan', 'no_rekening_tujuan', 'atas_nama_tujuan',
        'id_uang_muka', 'kode_rekening', 'void_reason', 'void_by', 'void_at',
        'journal_entry_id', 'id_pengguna',
    ];

    /**
     * Yang benar-benar masih harus DIBAYAR atas dokumen ini.
     *
     * `sisa_hutang` tak bisa dipakai apa adanya: pada dokumen PENYELESAIAN uang
     * muka kolom itu menyimpan nominal uang muka yang diselesaikan — dibaca saat
     * pembatalan untuk mengembalikan angka ke pool uang muka — sehingga
     * menampilkannya sebagai "sisa hutang" memberi angka yang jauh lebih besar
     * daripada kewajiban yang sebenarnya (10 juta uang muka vs 2 juta kekurangan).
     */
    public function sisaTagihan(): string
    {
        return \App\Support\Money::of(
            $this->jenis === 'penyelesaian_uang_muka' ? $this->sisa_kurang_bayar : $this->sisa_hutang
        );
    }

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'nominal' => 'decimal:2',
            'sisa_hutang' => 'decimal:2',
            'sisa_kurang_bayar' => 'decimal:2',
            'void_at' => 'datetime',
        ];
    }

    /**
     * Dokumen yang boleh dilihat seorang pengguna: miliknya sendiri, atau milik
     * bagiannya BESERTA seluruh bawahannya. Admin & tim keuangan tanpa batas.
     *
     * Dipakai daftar Status Pengajuan maupun ringkasan dashboard — satu aturan,
     * supaya dua layar tak pernah bercerita berbeda tentang dokumen yang sama.
     */
    public function scopeTerlihatOleh(Builder $query, User $user): Builder
    {
        $lingkup = LingkupBagian::untukPengguna($user);
        if ($lingkup === null) {
            return $query;
        }

        return $query->where(fn ($w) => $w
            ->where('id_pengguna', $user->id_pengguna)
            ->when($lingkup !== [], fn ($q) => $q->orWhereIn('kode_bagian', $lingkup)));
    }

    public function bagian(): BelongsTo
    {
        return $this->belongsTo(Bagian::class, 'kode_bagian', 'kode_bagian');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PengajuanPembayaranDetail::class, 'id_pengajuan', 'id');
    }

    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    /** Akun hutang yang ditentukan keuangan — dipakai agar layar menyebut namanya, bukan kodenya saja. */
    public function coaHutang(): BelongsTo
    {
        return $this->belongsTo(CoaDetail::class, 'kode_coa_hutang', 'kode_coa');
    }

    public function riwayatRekening(): HasMany
    {
        return $this->hasMany(PengajuanRekeningRiwayat::class, 'id_pengajuan', 'id');
    }

    /** Rekening tujuan terisi hanya bila ketiganya ada (setengah data = tak terpakai). */
    public function punyaRekeningTujuan(): bool
    {
        return (bool) ($this->bank_tujuan && $this->no_rekening_tujuan && $this->atas_nama_tujuan);
    }
}
