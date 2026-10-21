<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Buku besar mutasi dompet. Perpindahan antar-dompet = 2 baris (id_pasangan). */
class MutasiDompet extends Model
{
    use Concerns\DicatatStafAtauWali;

    protected $table = 'mutasi_dompet';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Kabar & push ke keluarga atas mutasi uang saku anak (kategori "dompet") — SESUDAH transaksinya tersimpan,
        // sehingga mutasi yang tergulung tak pernah dikabarkan, dan kegagalan kabar tak menggagalkan uangnya.
        static::saved(function (self $m) {
            if ($m->pemilik !== 'santri' || $m->status !== 'terverifikasi' || ! ($m->wasRecentlyCreated || $m->wasChanged('status'))) {
                return;
            }
            \Illuminate\Support\Facades\DB::afterCommit(function () use ($m) {
                try {
                    \App\Services\PortalWali\KabarKeluarga::mutasiDompet($m);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        });
    }

    protected function casts(): array
    {
        return [
            'nominal' => 'decimal:2',
            'saldo_setelah' => 'decimal:2',
            'tanggal' => 'date',
            'diverifikasi_pada' => 'datetime',
        ];
    }
}
