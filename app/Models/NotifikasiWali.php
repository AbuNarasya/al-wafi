<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kabar untuk SATU orang di sebuah keluarga (ayah/ibu/wali) — lihat KabarWali. */
class NotifikasiWali extends Model
{
    public const UPDATED_AT = null;

    /**
     * Kategori push yang bisa dipilih wali (keputusan user 21 Okt 2026). Urutan = urutan di layar Akun.
     * Laundry, akademik & pengumuman belum punya sumber kabar — pilihannya sudah tersedia supaya begitu fiturnya
     * ada, kabarnya langsung mengikuti pilihan wali.
     */
    public const KATEGORI = [
        'tagihan' => 'Tagihan dan pembayaran',
        'dompet' => 'Mutasi dompet santri',
        'laundry' => 'Progress laundry',
        'akademik' => 'Akademik dan kepengasuhan',
        'pengajuan' => 'Status pengajuan (perizinan, penarikan tabungan, kepulangan, dll.)',
        'pengumuman' => 'Pengumuman dan berita',
        'lainnya' => 'Lainnya',
    ];

    /** Jenis kabar → kategori. Jenis yang tak terdaftar masuk "lainnya". */
    public const JENIS_KATEGORI = [
        'pembayaran_santri_ditolak' => 'tagihan', 'pembayaran_santri_diverifikasi' => 'tagihan',
        'midtrans_lunas' => 'tagihan', 'tagihan_terbit' => 'tagihan',
        'topup_dompet_ditolak' => 'dompet', 'mutasi_dompet' => 'dompet',
        'tarik_tunai' => 'pengajuan', 'tarik_tabungan' => 'pengajuan',
    ];

    protected $table = 'notifikasi_wali';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $n) {
            if (! $n->kategori || $n->kategori === 'lainnya') {
                $n->kategori = self::JENIS_KATEGORI[$n->jenis] ?? ($n->kategori ?: 'lainnya');
            }
        });
    }

    protected function casts(): array
    {
        return ['dibaca_pada' => 'datetime', 'didorong_pada' => 'datetime'];
    }
}
