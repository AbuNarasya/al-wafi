<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Definisi rantai persetujuan per jenis dokumen. */
class ApprovalFlow extends Model
{
    protected $table = 'approval_flows';

    protected $primaryKey = 'kode_flow';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['kode_flow', 'nama_flow', 'jenis_dokumen', 'status'];

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'kode_flow', 'kode_flow');
    }
}
