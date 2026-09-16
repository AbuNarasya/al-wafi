<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Subledger tagihan per santri. sudah_akrual = penentu sisi kredit saat bayar. */
class TagihanSantri extends Model
{
    /**
     * Status yang berarti tagihannya TIDAK BERLAKU LAGI — tak boleh ditagih,
     * dibayar, dijaring reminder, maupun dihitung sebagai piutang.
     *
     * Dua nilai, karena sebabnya berbeda dan jejaknya pun berbeda:
     *   `batal`   — ditarik kembali SEBELUM menjurnal apa pun.
     *   `dihapus` — dikoreksi sampai Rp 0, JUSTRU dengan jurnal penyesuaian.
     *
     * Dikumpulkan di sini supaya penyaring yang dulu menulis `!= 'batal'` tak
     * satu per satu ketinggalan saat nilai ketiga menyusul kelak.
     */
    public const TIDAK_BERLAKU = ['batal', 'dihapus'];

    /**
     * Perilaku yang hanya boleh punya SATU tagihan berlaku per
     * (santri, jenjang, tahun ajaran, periode).
     *
     * Cerminan indeks unik parsial `tagihan_santri_sekali_per_ta` — tinggal di
     * model, bukan di service mana pun, karena yang diwakilinya adalah aturan
     * TABEL ini. Dipakai untuk menolak tabrakan dengan kalimat yang bisa dibaca
     * petugas, sebelum PostgreSQL menolaknya dengan SQLSTATE[23505] di tengah
     * jalan. Menambah perilaku baru ke indeks berarti menambahkannya di sini juga.
     */
    public const SEKALI_PER_TA = ['registrasi', 'uang_pangkal', 'perlengkapan', 'daftar_ulang', 'spp'];

    protected $table = 'tagihan_santri';

    protected $guarded = ['id'];

    /** Tagihan yang masih berlaku — kebalikan dari TIDAK_BERLAKU. */
    public function scopeBerlaku($query)
    {
        return $query->whereNotIn('status', self::TIDAK_BERLAKU);
    }

    public function berlaku(): bool
    {
        return ! in_array($this->status, self::TIDAK_BERLAKU, true);
    }

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'sisa' => 'decimal:2',
            'sudah_akrual' => 'boolean',
            'saldo_awal' => 'boolean',
            'jatuh_tempo' => 'date',
        ];
    }

    public function santri(): BelongsTo
    {
        return $this->belongsTo(Santri::class, 'id_santri', 'id');
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(JenisBiaya::class, 'kode_jenis', 'kode');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PembayaranSantri::class, 'id_tagihan', 'id');
    }

    public function rencanaAngsuran(): HasMany
    {
        return $this->hasMany(RencanaAngsuranUangPangkal::class, 'id_tagihan', 'id');
    }

    public function potongan(): HasOne
    {
        return $this->hasOne(PotonganUangPangkal::class, 'id_tagihan', 'id');
    }
}
