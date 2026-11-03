<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Satu catatan penagihan manual atas SATU tagihan santri — lihat PenagihanTunggakanService. Tak pernah diubah. */
class CatatanPenagihan extends Model
{
    public const UPDATED_AT = null;

    public const CARA = [
        'telepon' => 'Telepon', 'whatsapp' => 'WhatsApp', 'tatap_muka' => 'Tatap muka', 'surat' => 'Surat', 'lainnya' => 'Lainnya',
    ];

    protected $table = 'catatan_penagihan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ditagih_pada' => 'date', 'janji_tanggal' => 'date', 'janji_nominal' => 'decimal:2', 'created_at' => 'datetime'];
    }

    public function tagihan(): BelongsTo
    {
        return $this->belongsTo(TagihanSantri::class, 'id_tagihan');
    }

    public function pencatat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh', 'id_pengguna');
    }
}
