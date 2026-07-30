<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu SEL pada grid Tarif: berapa yang dipungut untuk satu (tahun ajaran,
 * jenjang, jalur, perilaku). Sengaja dipisah dari `jenis_biaya`, yang kini hanya
 * memegang pemetaan akun — tarif berubah tiap tahun, akun tidak.
 *
 * TIGA KEADAAN yang harus dibedakan (lihat migration pisahkan_tarif_dari_jenis_biaya):
 *   • `nominal` terisi   → tarif berlaku
 *   • `bebas` = true     → sengaja tidak dipungut; tagihannya TIDAK terbit
 *   • barisnya tak ada   → belum diisi; penagihan berhenti dengan pesan menuntun
 * Membedakan "bebas" dari "belum diisi" itu intinya: kalau keduanya sama-sama
 * berarti kosong, santri yang tarifnya lupa diisi akan diam-diam dilewati.
 *
 * `kode_jalur` NULL = baris "Umum (semua jalur)".
 */
class TarifBiaya extends Model
{
    protected $table = 'tarif_biaya';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['nominal' => 'decimal:2', 'bebas' => 'boolean', 'tingkat' => 'integer'];
    }

    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    public function jalur(): BelongsTo
    {
        return $this->belongsTo(JalurPendaftaran::class, 'kode_jalur', 'kode');
    }
}
