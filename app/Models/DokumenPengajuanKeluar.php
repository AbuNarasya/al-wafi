<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Berkas pengajuan pengunduran diri: surat pengajuan (wali), surat mutasi, SK, lainnya (admin sekolah). */
class DokumenPengajuanKeluar extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'dokumen_pengajuan_keluar';

    protected $guarded = ['id'];

    public const JENIS = ['surat_pengajuan' => 'Surat pengajuan pengunduran diri', 'surat_mutasi' => 'Surat mutasi',
        'surat_keputusan' => 'Surat keputusan', 'lainnya' => 'Dokumen lain'];
}
