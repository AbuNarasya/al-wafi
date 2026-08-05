<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Master Gelombang pendaftaran: identitas & WAKTU-nya.
 *
 * Besaran potongannya tidak di sini — itu matriks tersendiri
 * (`potongan_gelombang`), satu sel per jenjang. Yang di sini berlaku untuk
 * gelombangnya secara utuh, jadi tak mungkin berbeda antar jenjang.
 */
class Gelombang extends Model
{
    protected $table = 'gelombang';

    protected $fillable = [
        'tahun_ajaran', 'kode', 'nama', 'berlaku_mulai', 'berlaku_sampai',
        'masa_berlaku_hari', 'status', 'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'masa_berlaku_hari' => 'integer',
        ];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class, 'tahun_ajaran', 'kode');
    }

    public function potongan(): HasMany
    {
        return $this->hasMany(PotonganGelombang::class, 'gelombang', 'kode')
            ->where('tahun_ajaran', $this->tahun_ajaran);
    }

    /**
     * Keadaan gelombang pada suatu tanggal — satu sumber untuk layar &
     * penyaringan. `arsip` (dimatikan orang) sengaja dibedakan dari
     * `kedaluwarsa` (mati karena waktu): yang pertama keputusan, yang kedua
     * tinggal diperpanjang.
     *
     * @return 'arsip'|'belum_mulai'|'kedaluwarsa'|'berlaku'
     */
    public function keadaan(?string $tanggal = null): string
    {
        if ($this->status !== 'aktif') {
            return 'arsip';
        }

        $tanggal = $tanggal ?: now()->toDateString();
        if ($this->berlaku_mulai && $tanggal < $this->berlaku_mulai->toDateString()) {
            return 'belum_mulai';
        }
        if ($this->berlaku_sampai && $tanggal > $this->berlaku_sampai->toDateString()) {
            return 'kedaluwarsa';
        }

        return 'berlaku';
    }

    /** Periode dalam satu kalimat; "—" bila memang tak dibatasi waktu. */
    public function labelPeriode(): string
    {
        $mulai = $this->berlaku_mulai?->format('d M Y');
        $sampai = $this->berlaku_sampai?->format('d M Y');

        return match (true) {
            $mulai && $sampai => "{$mulai} – {$sampai}",
            (bool) $mulai => "sejak {$mulai}",
            (bool) $sampai => "sampai {$sampai}",
            default => '—',
        };
    }
}
