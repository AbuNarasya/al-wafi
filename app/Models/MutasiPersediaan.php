<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KARTU STOK — satu baris untuk SETIAP pergerakan persediaan, dari modul mana
 * pun, beserta saldo berjalannya. Inilah yang menjawab "stok sekian ini dari
 * mana dan keluar ke siapa" — pertanyaan yang dulu tak terjawab sama sekali
 * karena `inventory` cuma menyimpan dua angka akumulatif.
 *
 * `rincian_lapisan` hanya terisi pada arah keluar: daftar lapisan yang tergerus
 * beserta qty & harganya, dipakai saat pembatalan untuk mengembalikan lapisan
 * yang BENAR.
 */
class MutasiPersediaan extends Model
{
    protected $table = 'mutasi_persediaan';

    /** Alasan yang menentukan akun lawan jurnalnya. */
    public const ALASAN = [
        'pembelian' => 'Pembelian',
        'pemakaian' => 'Pemakaian',
        'opname' => 'Penyesuaian Opname',
        'pembatalan' => 'Pembatalan Transaksi',
    ];

    protected $fillable = [
        'kode_persediaan', 'tanggal', 'arah', 'alasan', 'kuantiti', 'nilai',
        'saldo_kuantiti', 'saldo_nilai', 'sumber_modul', 'sumber_ref',
        'journal_entry_id', 'rincian_lapisan', 'keterangan', 'id_pengguna',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'kuantiti' => 'decimal:4',
            'nilai' => 'decimal:2',
            'saldo_kuantiti' => 'decimal:4',
            'saldo_nilai' => 'decimal:2',
            'rincian_lapisan' => 'array',
        ];
    }

    public function persediaan(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'kode_persediaan', 'kode_persediaan');
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id', 'id');
    }

    public function pengguna(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    public function labelAlasan(): string
    {
        return self::ALASAN[$this->alasan] ?? $this->alasan;
    }
}
