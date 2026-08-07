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
        // Akrual tagihan lain-lain. Dulu menumpang 'TagihanSpp', sehingga jurnal
        // laundry & kegiatan tak bisa dibedakan dari jurnal SPP saat ditelusuri
        // per modul — dan unit bawaannya pun terpaksa ikut aturan SPP.
        'TagihanLain',
        // Pencairan & cicilan pinjaman karyawan. Keduanya memanggil postJournal
        // TANPA mengoper kode_unit sama sekali, jadi selama ia tak terdaftar di
        // sini, `resolveDefaultUnit` tak punya baris untuk ditemukan dan seluruh
        // baris jurnalnya lahir tanpa unit — tanpa cara membetulkannya dari layar.
        'PinjamanKaryawan',
        // Jurnal penyesuaian hasil koreksi nominal tagihan. Ia mengoper kode_unit
        // sendiri dari jenis biayanya, jadi ketiadaannya di sini tak pernah
        // berakibat pada angka — tapi layar Unit Default jadi tak mengenalnya.
        'KoreksiTagihan',
    ];

    public static function isValid(string $v): bool
    {
        return in_array($v, self::ALL, true);
    }
}
