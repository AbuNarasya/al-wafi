<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Otorisasi input Accrue/Jurnal Umum di atas batas. created_at + decided_at. */
class PostingApproval extends Model
{
    protected $table = 'posting_approvals';

    public const UPDATED_AT = null;

    protected $fillable = [
        'modul', 'ref', 'nominal', 'payload', 'ringkasan', 'status',
        'id_pengguna', 'nama_pemohon', 'decided_by', 'nama_penyetuju', 'catatan', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'decided_at' => 'datetime'];
    }
}
