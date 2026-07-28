<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengajuan VOID (approval bila melebihi otorisasi). Hanya created_at + decided_at. */
class VoidApproval extends Model
{
    protected $table = 'void_approvals';

    public const UPDATED_AT = null;

    protected $fillable = [
        'modul', 'id_record', 'ref', 'nominal', 'alasan', 'status',
        'id_pengguna', 'nama_pemohon', 'decided_by', 'nama_penyetuju', 'catatan', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'decided_at' => 'datetime'];
    }
}
