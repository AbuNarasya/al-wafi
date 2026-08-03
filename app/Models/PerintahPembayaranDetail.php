<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris Perintah Pembayaran — MENUNJUK kewajiban yang sudah ada, tak pernah
 * menciptakannya. Karena itu tidak ada sumber "lain-lain": kewajiban yang belum
 * berdokumen harus jadi Pengajuan Pembayaran lebih dulu.
 */
class PerintahPembayaranDetail extends Model
{
    protected $table = 'perintah_pembayaran_detail';

    protected $guarded = ['id'];

    /** Sumber kewajiban yang boleh ditunjuk. */
    public const SUMBER = [
        'pengajuan' => 'Pengajuan Pembayaran',
        'invoice' => 'Invoice Vendor',
        'uang_muka' => 'Uang Muka Operasional',
        'bank_loan' => 'Pembiayaan Bank',
    ];

    /**
     * Status baris.
     *
     * `ditunda` & `batal` MELEPAS kuncinya (lihat indeks parsial
     * `perintah_pembayaran_kewajiban_hidup`) sehingga kewajibannya bebas
     * diajukan lagi di PP berikutnya — dengan riwayat yang ikut terbawa.
     */
    public const STATUS = [
        'diajukan' => 'Diajukan',
        'disetujui' => 'Disetujui',
        'ditunda' => 'Ditunda',
        'batal' => 'Batal (PP ditutup)',
    ];

    /** Status baris yang masih mengunci kewajibannya. */
    public const STATUS_MENGUNCI = ['diajukan', 'disetujui'];

    protected function casts(): array
    {
        return [
            'jatuh_tempo' => 'date',
            'nominal_diajukan' => 'decimal:2',
            'nominal_diotorisasi' => 'decimal:2',
            'terbayar' => 'decimal:2',
            'sisa' => 'decimal:2',
            'ditambahkan_pengotorisasi' => 'boolean',
        ];
    }

    public function perintah(): BelongsTo
    {
        return $this->belongsTo(PerintahPembayaran::class, 'kode_transaksi', 'kode_transaksi');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class, 'kode_unit', 'kode_unit');
    }

    public function labelSumber(): string
    {
        return self::SUMBER[$this->sumber] ?? $this->sumber;
    }
}
