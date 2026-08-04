<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Master potongan uang pangkal per gelombang/jenjang/tahun ajaran. */
class PotonganGelombang extends Model
{
    protected $table = 'potongan_gelombang';

    protected $guarded = ['id'];

    /** Jenjangnya — layar menyebut NAMA-nya, bukan kode `J001`. Null = semua jenjang. */
    public function jenjang(): BelongsTo
    {
        return $this->belongsTo(Jenjang::class, 'kode_jenjang', 'kode');
    }

    protected function casts(): array
    {
        return [
            'gelombang' => 'integer',
            'potongan' => 'decimal:2',
            'masa_berlaku_hari' => 'integer',
            'berlaku_mulai' => 'date',
            'berlaku_sampai' => 'date',
            'aktif' => 'boolean',
        ];
    }

    /**
     * Keadaan baris pada suatu tanggal — satu sumber untuk layar & penyaringan.
     *
     * `arsip` (dimatikan orang) sengaja dibedakan dari `kedaluwarsa` (mati
     * karena waktu): yang pertama keputusan, yang kedua tinggal diperpanjang.
     *
     * @return 'arsip'|'belum_mulai'|'kedaluwarsa'|'berlaku'
     */
    public function keadaan(?string $tanggal = null): string
    {
        if (! $this->aktif) {
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
