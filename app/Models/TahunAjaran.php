<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master tahun ajaran PPSB. kode ("2026/2027") dirujuk tabel lain sebagai string. */
class TahunAjaran extends Model
{
    protected $table = 'tahun_ajaran';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'default_pendaftaran' => 'boolean',
        ];
    }

    /**
     * Rentang tanggal diisikan dari KODE-nya bila tak disebut: "2026/2027" →
     * 1 Juli 2026 s/d 30 Juni 2027.
     *
     * Rentang inilah jangkar `TahunAjaranService::berjalan()`, dan seluruh
     * penjaga tahun ajaran bergantung padanya. Membiarkannya kosong — atau
     * salah ketik seperti "selesai = mulai" — membuat ada hari yang tak dimiliki
     * tahun ajaran mana pun, dan penerbitan tagihan berhenti tanpa sebab yang
     * terlihat. Kode sudah memuat kedua tahunnya, jadi tak ada gunanya menuntut
     * petugas mengetik ulang apa yang sudah ia tulis.
     *
     * Hanya BAWAAN: nilai yang disebut eksplisit tak pernah ditimpa, sehingga
     * pesantren yang kalender akademiknya tak mulai 1 Juli tetap bisa mengaturnya.
     */
    protected static function booted(): void
    {
        static::creating(function (self $ta) {
            if (! preg_match('/^(\d{4})\D+(\d{4})$/', (string) $ta->kode, $m)) {
                return;
            }
            $ta->tanggal_mulai ??= "{$m[1]}-07-01";
            $ta->tanggal_selesai ??= "{$m[2]}-06-30";
        });
    }
}
