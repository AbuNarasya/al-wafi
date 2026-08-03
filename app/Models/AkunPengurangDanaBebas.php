<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Akun yang MENGURANGI saldo kas saat menghitung dana bebas dipakai — titipan
 * tabungan santri, dompet wali, kantin pihak ketiga, dan sejenisnya.
 *
 * Sengaja tabel pengaturan tersendiri, bukan penanda di master COA: daftarnya
 * keputusan kebijakan yang bisa berubah, bukan sifat bawaan akunnya. Menaruhnya
 * di sini juga membuatnya terlihat sebagai PENGATURAN yang bisa ditinjau, bukan
 * aturan yang tersembunyi di dalam kode.
 */
class AkunPengurangDanaBebas extends Model
{
    protected $table = 'akun_pengurang_dana_bebas';

    protected $guarded = ['id'];

    public function akun(): BelongsTo
    {
        return $this->belongsTo(CoaDetail::class, 'kode_coa', 'kode_coa');
    }
}
