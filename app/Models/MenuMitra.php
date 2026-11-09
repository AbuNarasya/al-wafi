<?php

namespace App\Models;

use App\Support\Audit\MencatatJejak;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Menu & harga satu mitra kantin; perubahan harga tercatat di Jejak Audit (MencatatJejak). */
class MenuMitra extends Model
{
    use MencatatJejak;

    protected $jejakModul = 'mitra-kantin';

    protected $table = 'menu_mitra';

    protected $fillable = ['id_mitra', 'nama', 'harga', 'aktif'];

    protected function casts(): array
    {
        return ['harga' => 'decimal:2', 'aktif' => 'boolean'];
    }

    public function mitra(): BelongsTo
    {
        return $this->belongsTo(Mitra::class, 'id_mitra');
    }
}
