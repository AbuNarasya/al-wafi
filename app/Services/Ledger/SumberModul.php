<?php

namespace App\Services\Ledger;

/**
 * Daftar nilai `sumber_modul` yang sah pada journal_entries — satu sumber
 * kebenaran untuk validasi peta default unit bisnis & pilihan UI.
 * TAMBAHKAN modul baru di sini saat mulai memanggil postJournal.
 */
final class SumberModul
{
    public const ALL = [
        'JurnalUmum',
        'KasMasuk',
        'KasKeluar',
        'Invoice',
        'Accrue',
        'Depresiasi',
        'PenyelesaianUangMuka',
        'UangMukaOperasional',
        'SaldoAwal',
        'TutupBuku',
        'RekonsiliasiBank',
        'PinjamanBank',
        'PindahBuku',
        'PengakuanPendapatan',
        'PengajuanPembayaran',
        'PembayaranSantri',
        'MutasiDompet',
        'TagihanSpp',
    ];

    public static function isValid(string $v): bool
    {
        return in_array($v, self::ALL, true);
    }
}
