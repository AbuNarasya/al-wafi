<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kebijakan khusus seorang santri: keringanan, potongan, beasiswa, dsb.
 *
 * Satu tabel untuk semua bentuk, dibedakan `perilaku` — kosakata yang sama
 * dengan jenis biaya & tagihan. Menambah bentuk berikutnya tak perlu tabel baru.
 *
 * DUA SURAT wajib terlampir sebelum boleh disetujui: permohonan wali dan
 * persetujuan yayasan. Lihat [[App\Services\Modules\KebijakanKhususService]].
 */
class KebijakanKhusus extends Model
{
    use MencatatJejak;

    /** Kode modul hak akses — penyaring di layar Jejak Audit. */
    protected $jejakModul = 'kebijakan-khusus';

    protected $table = 'kebijakan_khusus';

    public const JENIS = [
        'keringanan' => 'Keringanan',
        'potongan' => 'Potongan',
        'beasiswa' => 'Beasiswa',
        'lainnya' => 'Lainnya',
    ];

    public const CARA = [
        'nominal_khusus' => 'Nominal khusus (ganti tarifnya)',
        'nominal' => 'Potong sejumlah rupiah',
        'persen' => 'Potong sekian persen',
    ];

    protected $fillable = [
        'id_santri', 'jenis', 'perilaku', 'cara', 'besaran',
        'tahun_ajaran', 'berlaku_mulai', 'berlaku_sampai',
        'alasan', 'status',
        'diajukan_oleh', 'diajukan_pada', 'diputus_oleh', 'diputus_pada', 'catatan_keputusan',
    ];

    protected function casts(): array
    {
        return [
            'besaran' => 'decimal:2',
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'diajukan_pada' => 'datetime',
            'diputus_pada' => 'datetime',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function pemohon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh', 'id_pengguna');
    }

    public function pemutus(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diputus_oleh', 'id_pengguna');
    }

    public function labelJenis(): string
    {
        return self::JENIS[$this->jenis] ?? $this->jenis;
    }

    public function labelCara(): string
    {
        return self::CARA[$this->cara] ?? $this->cara;
    }

    /** Ringkas untuk ditampilkan di samping angka tagihan. */
    public function ringkas(): string
    {
        return match ($this->cara) {
            'persen' => "potongan {$this->besaran}%",
            'nominal' => 'potongan Rp '.number_format((float) $this->besaran, 0, ',', '.'),
            default => 'nominal khusus Rp '.number_format((float) $this->besaran, 0, ',', '.'),
        };
    }
}
