<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pengajuan EDIT (payload JSON diterapkan saat disetujui). created_at + decided_at. */
class EditApproval extends Model
{
    protected $table = 'edit_approvals';

    public const UPDATED_AT = null;

    protected $fillable = [
        'modul', 'id_record', 'ref', 'nominal', 'payload', 'ringkasan', 'status',
        'id_pengguna', 'nama_pemohon', 'decided_by', 'nama_penyetuju', 'catatan', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'decided_at' => 'datetime'];
    }
}
