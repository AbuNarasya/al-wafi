<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perintah Pembayaran — dokumen KAS, tidak pernah menjurnal.
 *
 * Dua keputusan yang membingkainya: OTORISASI (apa yang boleh dibayar, kapan,
 * dengan metode apa) dan PENUTUPAN (titik saat perintah dinyatakan tuntas).
 * Keduanya sengaja terpisah — lihat komentar pada `status`.
 */
class PerintahPembayaran extends Model
{
    protected $table = 'perintah_pembayaran';

    protected $primaryKey = 'kode_transaksi';

    protected $guarded = ['kode_transaksi'];

    /**
     * Status dokumen.
     *
     * `selesai` HANYA lewat penekanan tombol "PP Sudah Selesai" — tidak pernah
     * otomatis, bahkan ketika seluruh barisnya sudah lunas. Kalau penutupan bisa
     * terjadi sendiri, tak ada satu titik pun yang bisa disebut "inilah saat
     * perintah ini tuntas", dan pertanyaan "kenapa sisanya tak dibayar?" tak
     * punya jawaban tertulis.
     */
    public const STATUS = [
        'draf' => 'Draf',
        'menunggu' => 'Menunggu Otorisasi',
        'diotorisasi' => 'Diotorisasi',
        'sebagian' => 'Terbayar Sebagian',
        // Seluruhnya lunas TETAPI belum ditutup — keadaan ini perlu namanya
        // sendiri supaya layar bisa menyodorkan tombol "PP Sudah Selesai",
        // alih-alih menyebutnya "sebagian" yang menyesatkan.
        'terbayar' => 'Terbayar Penuh',
        'selesai' => 'Selesai',
        'ditolak' => 'Ditolak',
    ];

    /** Status yang masih MENGUNCI kewajiban di dalamnya. */
    public const STATUS_HIDUP = ['draf', 'menunggu', 'diotorisasi', 'sebagian', 'terbayar'];

    public const METODE = [
        'transfer' => 'Transfer — internet banking',
        'teller' => 'Transfer — teller bank',
        'tunai' => 'Tunai',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'tanggal_usulan' => 'date',
            'tanggal_bayar' => 'date',
            'total_diajukan' => 'decimal:2',
            'total_diotorisasi' => 'decimal:2',
            'diotorisasi_pada' => 'datetime',
            'ditutup_pada' => 'datetime',
        ];
    }

    public function detail(): HasMany
    {
        return $this->hasMany(PerintahPembayaranDetail::class, 'kode_transaksi', 'kode_transaksi');
    }

    public function penyusun(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disusun_oleh', 'id_pengguna');
    }

    public function pengotorisasi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diotorisasi_oleh', 'id_pengguna');
    }

    public function rekeningRencana(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'kode_rekening_rencana', 'kode_coa');
    }

    /** Sudah boleh direalisasikan lewat Kas Keluar? */
    public function bolehDibayar(): bool
    {
        return in_array($this->status, ['diotorisasi', 'sebagian'], true);
    }
}
