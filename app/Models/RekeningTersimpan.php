<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Buku rekening tujuan milik seorang pemohon — dipanggil kembali saat menyusun
 * pengajuan berikutnya. Bukan sumber nilai dokumen: pengajuan menyimpan
 * salinannya sendiri.
 */
class RekeningTersimpan extends Model
{
    protected $table = 'rekening_tersimpan';

    protected $fillable = ['id_pengguna', 'bank', 'no_rekening', 'atas_nama'];

    public function pemilik(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_pengguna', 'id_pengguna');
    }

    /**
     * Simpan rekening ke buku pemohon bila belum ada. Pembandingnya (pemilik,
     * bank, nomor) sama dengan indeks uniknya — "atas nama" boleh diperbarui,
     * karena itu yang paling sering diperbaiki ejaannya.
     */
    public static function simpanUntuk(int $idPengguna, string $bank, string $noRekening, string $atasNama): self
    {
        return static::updateOrCreate(
            ['id_pengguna' => $idPengguna, 'bank' => $bank, 'no_rekening' => $noRekening],
            ['atas_nama' => $atasNama],
        );
    }

    /**
     * Daftar rekening milik seorang pemohon untuk isian di layar.
     *
     * @return list<array{id:int,bank:string,no_rekening:string,atas_nama:string,label:string}>
     */
    public static function untukPemohon(int $idPengguna): array
    {
        return static::where('id_pengguna', $idPengguna)
            ->orderBy('bank')->orderBy('atas_nama')->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'bank' => $r->bank,
                'no_rekening' => $r->no_rekening,
                'atas_nama' => $r->atas_nama,
                'label' => "{$r->bank} · {$r->no_rekening} · {$r->atas_nama}",
            ])->all();
    }
}
