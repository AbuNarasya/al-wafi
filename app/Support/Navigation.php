<?php

namespace App\Support;

use App\Services\Ledger\PeringkatPengajuan;
use Illuminate\Support\Facades\Auth;

/**
 * Struktur SIDEBAR — meniru persis `frontend/src/components/AppShell.tsx` (NAV,
 * GROUP_ORDER, SUB_ORDER) dari aplikasi asli. Ini BERBEDA dari [[ModulRegistry]]
 * yang dipakai matriks hak akses; sidebar punya urutan, label, dan item sendiri
 * (mis. tiap laporan jadi link terpisah, ada "Buat Pengajuan", grup "Kontrol").
 *
 * `url` = tautan tujuan (memakai path aplikasi ini; sebagian modul belum dibangun
 * sehingga 404 sampai dibuat). `modul` = kode hak akses (sumbu `menu`).
 * Flag khusus: adminOnly, staffOnly, direktoratOrAdmin — sama seperti asli;
 * staffOrMudirBagian ditambahkan di konversi ini untuk Pengajuan Anggaran.
 */
final class Navigation
{
    /**
     * @var list<array{url:string,label:string,group:string,sub?:string,modul?:string,adminOnly?:bool,staffOnly?:bool,staffOrMudirBagian?:bool,direktoratOrAdmin?:bool}>
     */
    public const ITEMS = [
        // Cukup berhak atas SALAH SATU tab untuk melihat menunya.
        ['url' => '/dashboard', 'label' => 'Dashboard', 'group' => '', 'modulAny' => ['dashboard', 'dashboard-ppsb']],

        // ---- 1. Setting Awal ----
        // Dipecah dua sub: yang menyetel LEMBAGA & ORANGNYA (Setting Umum) vs yang
        // menyetel BIAYA (Setting Biaya). Urutan di dalam Setting Biaya = urutan
        // pengisiannya: jenjang → tipe → jenis → tarif.
        ['url' => '/company-settings', 'label' => 'Pengaturan Perusahaan', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'company-settings'],
        ['url' => '/bagian', 'label' => 'Bagian / Struktur Organisasi', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'bagian'],
        ['url' => '/users', 'label' => 'Pengguna', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'users'],
        ['url' => '/levels', 'label' => 'Level Otorisasi Keuangan', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'levels'],
        ['url' => '/level-pengajuan', 'label' => 'Level Pengajuan', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'level-pengajuan'],
        ['url' => '/hak-akses', 'label' => 'Hak Akses Modul', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'adminOnly' => true],
        ['url' => '/karyawan', 'label' => 'Karyawan', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'karyawan'],
        ['url' => '/impor-data-awal', 'label' => 'Impor Data Awal', 'group' => 'SETTING AWAL', 'sub' => 'Setting Umum', 'modul' => 'impor-data-awal'],

        ['url' => '/jenjang', 'label' => 'Jenjang Pendidikan', 'group' => 'SETTING AWAL', 'sub' => 'Setting Biaya', 'modul' => 'jenjang'],
        // Tipe Biaya mendahului Jenis Biaya: jenis biaya memilih tipenya dari sini.
        ['url' => '/tipe-biaya', 'label' => 'Tipe Biaya', 'group' => 'SETTING AWAL', 'sub' => 'Setting Biaya', 'modul' => 'tipe-biaya'],
        ['url' => '/ppsb/jenis-biaya', 'label' => 'Jenis Biaya', 'group' => 'SETTING AWAL', 'sub' => 'Setting Biaya', 'modul' => 'jenis-biaya'],
        // Tarif menyusul Jenis Biaya: selnya baru bisa dipakai kalau baris akunnya ada.
        ['url' => '/tarif', 'label' => 'Tarif', 'group' => 'SETTING AWAL', 'sub' => 'Setting Biaya', 'modul' => 'tarif'],
        ['url' => '/reminder-tagihan', 'label' => 'Reminder Tagihan Jatuh Tempo', 'group' => 'SETTING AWAL', 'sub' => 'Setting Biaya', 'modul' => 'reminder-tagihan'],

        // ---- 2. Anggaran ----
        ['url' => '/budget', 'label' => 'Input Anggaran', 'group' => 'ANGGARAN', 'modul' => 'budget'],
        // Pengajuan anggaran = jalur penyusun bagian. Yang boleh MENGAJUKAN cuma
        // Staff & Mudir Bagian (ditegakkan BudgetPengajuanService), jadi menunya
        // pun hanya muncul bagi mereka — daripada mengantar orang ke pesan 403.
        ['url' => '/budget/pengajuan/buat', 'label' => 'Ajukan Anggaran', 'group' => 'ANGGARAN', 'modul' => 'budget', 'staffOrMudirBagian' => true],
        ['url' => '/budget/pengajuan', 'label' => 'Status Pengajuan Anggaran', 'group' => 'ANGGARAN', 'modul' => 'budget'],
        ['url' => '/budget/realisasi', 'label' => 'Realisasi Anggaran', 'group' => 'ANGGARAN', 'direktoratOrAdmin' => true],

        // ---- 3. Pengajuan Pembayaran ----
        ['url' => '/pengajuan-pembayaran/buat', 'label' => 'Buat Pengajuan Pembayaran', 'group' => 'PENGAJUAN PEMBAYARAN', 'sub' => 'Buat Pengajuan', 'modul' => 'pengajuan-pembayaran', 'staffOnly' => true],
        ['url' => '/pengajuan-pembayaran/buat/uang-muka', 'label' => 'Buat Pengajuan Uang Muka', 'group' => 'PENGAJUAN PEMBAYARAN', 'sub' => 'Buat Pengajuan', 'modul' => 'pengajuan-pembayaran', 'staffOnly' => true],
        ['url' => '/pengajuan-pembayaran/buat/penyelesaian', 'label' => 'Buat Penyelesaian Uang Muka', 'group' => 'PENGAJUAN PEMBAYARAN', 'sub' => 'Buat Pengajuan', 'modul' => 'pengajuan-pembayaran', 'staffOnly' => true],
        ['url' => '/pengajuan-pembayaran', 'label' => 'Status Pengajuan', 'group' => 'PENGAJUAN PEMBAYARAN', 'sub' => 'Pengajuan', 'modul' => 'pengajuan-pembayaran'],
        ['url' => '/pengajuan-pembayaran/outstanding-uang-muka', 'label' => 'Outstanding Uang Muka', 'group' => 'PENGAJUAN PEMBAYARAN', 'sub' => 'Pengajuan', 'modul' => 'pengajuan-pembayaran'],

        // ---- 4. Keuangan ----
        ['url' => '/vendor-types', 'label' => 'Jenis Vendor', 'group' => 'KEUANGAN', 'sub' => 'Vendor & Customer', 'modul' => 'vendor-types'],
        ['url' => '/vendors', 'label' => 'Vendor', 'group' => 'KEUANGAN', 'sub' => 'Vendor & Customer', 'modul' => 'vendors'],
        ['url' => '/customer-types', 'label' => 'Jenis Customer', 'group' => 'KEUANGAN', 'sub' => 'Vendor & Customer', 'modul' => 'customer-types'],
        ['url' => '/customers', 'label' => 'Customer', 'group' => 'KEUANGAN', 'sub' => 'Vendor & Customer', 'modul' => 'customers'],

        ['url' => '/inventory', 'label' => 'Persediaan', 'group' => 'KEUANGAN', 'sub' => 'Aset & Persediaan', 'modul' => 'inventory'],
        ['url' => '/asset-categories', 'label' => 'Kategori Aset', 'group' => 'KEUANGAN', 'sub' => 'Aset & Persediaan', 'modul' => 'asset-categories'],
        ['url' => '/assets', 'label' => 'Aset Tetap', 'group' => 'KEUANGAN', 'sub' => 'Aset & Persediaan', 'modul' => 'assets'],

        ['url' => '/purchase-orders', 'label' => 'Purchase Order', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'purchase-orders'],
        ['url' => '/cash-in', 'label' => 'Kas Masuk', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'cash-in'],
        ['url' => '/cash-out', 'label' => 'Kas Keluar', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'cash-out'],
        ['url' => '/invoices', 'label' => 'Invoice Vendor', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'invoices'],
        ['url' => '/operational-advance', 'label' => 'Uang Muka Operasional', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'operational-advance'],
        ['url' => '/advance-settlement', 'label' => 'Penyelesaian Uang Muka', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'advance-settlement'],
        ['url' => '/accrue', 'label' => 'Accrue & Prepaid', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'accrue'],
        ['url' => '/book-transfer', 'label' => 'Pindah Buku', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'book-transfer'],
        ['url' => '/bank-loans', 'label' => 'Pembiayaan', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'bank-loans'],
        ['url' => '/pinjaman-karyawan', 'label' => 'Pinjaman Karyawan', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'pinjaman-karyawan'],
        ['url' => '/bank-reconciliation', 'label' => 'Rekonsiliasi Bank', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'bank-reconciliation'],
        ['url' => '/journal', 'label' => 'Jurnal Umum', 'group' => 'KEUANGAN', 'sub' => 'Transaksi', 'modul' => 'journal'],

        ['url' => '/reports/neraca', 'label' => 'Neraca', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],
        ['url' => '/reports/laba-rugi', 'label' => 'Laba Rugi', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],
        ['url' => '/reports/perubahan-modal', 'label' => 'Perubahan Modal', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],
        ['url' => '/reports/arus-kas', 'label' => 'Arus Kas', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],
        ['url' => '/reports/buku-besar', 'label' => 'Buku Besar', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],
        ['url' => '/reports/aset', 'label' => 'Aset & Depresiasi', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],
        ['url' => '/reports/persediaan', 'label' => 'Persediaan', 'group' => 'KEUANGAN', 'sub' => 'Laporan', 'modul' => 'reports'],

        ['url' => '/kontrol/ringkasan', 'label' => 'Ringkasan Outstanding', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'outstanding'],
        ['url' => '/kontrol/aging-ap', 'label' => 'Aging AP', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'outstanding'],
        ['url' => '/kontrol/uang-muka-customer', 'label' => 'Uang Muka Customer', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'outstanding'],
        ['url' => '/kontrol/uang-muka-operasional', 'label' => 'Uang Muka Operasional', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'outstanding'],
        ['url' => '/kontrol/accrue-prepaid', 'label' => 'Accrue & Prepaid', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'outstanding'],
        ['url' => '/kontrol/rekap-pembiayaan', 'label' => 'Rekap Pembiayaan', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'outstanding'],
        ['url' => '/coa', 'label' => 'Chart of Account', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'coa-detail'],
        ['url' => '/business-units', 'label' => 'Unit Bisnis', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'business-units'],
        ['url' => '/unit-default', 'label' => 'Default Unit Bisnis', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'unit-default'],
        ['url' => '/bank-accounts', 'label' => 'Kas & Rekening', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'bank-accounts'],
        ['url' => '/opening-balance', 'label' => 'Saldo Awal', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'opening-balance'],
        ['url' => '/period-close', 'label' => 'Tutup Buku Periode', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'period-close'],
        ['url' => '/export', 'label' => 'Export Data', 'group' => 'KEUANGAN', 'sub' => 'Kontrol', 'modul' => 'reports'],

        // ---- 5. PPSB & Kependidikan ----
        // CATATAN: label grupnya "KEPENDIDIKAN", tetapi URL (/kesantrian/…) dan
        // kode modul (`pembayaran-kesantrian`) sengaja TIDAK ikut diganti —
        // kode modul tersimpan di `hak_akses_modul`, jadi menggantinya akan
        // memutus hak akses yang sudah diberikan ke pengguna.
        ['url' => '/ppsb/tahun-ajaran', 'label' => 'Tahun Ajaran', 'group' => 'PPSB', 'sub' => 'Setting Awal', 'modul' => 'tahun-ajaran'],
        // Jalur mendahului Jenis Biaya: tarif dibedakan per jalur, jadi jalurnya
        // harus sudah ada sebelum jenis biaya diisi.
        ['url' => '/ppsb/jalur-pendaftaran', 'label' => 'Jalur Pendaftaran', 'group' => 'PPSB', 'sub' => 'Setting Awal', 'modul' => 'jalur-pendaftaran'],
        ['url' => '/ppsb/sumber-informasi', 'label' => 'Sumber Informasi', 'group' => 'PPSB', 'sub' => 'Setting Awal', 'modul' => 'sumber-informasi'],
        ['url' => '/ppsb/potongan-gelombang', 'label' => 'Potongan Gelombang', 'group' => 'PPSB', 'sub' => 'Setting Awal', 'modul' => 'potongan-gelombang'],
        ['url' => '/ppsb/target-santri', 'label' => 'Target Santri', 'group' => 'PPSB', 'sub' => 'Setting Awal', 'modul' => 'target-santri'],
        ['url' => '/ppsb/termin-filter', 'label' => 'Setting Filter Termin Jatuh Tempo', 'group' => 'PPSB', 'sub' => 'Setting Awal', 'modul' => 'termin-filter'],
        ['url' => '/wali', 'label' => 'Wali / Keluarga', 'group' => 'PPSB', 'sub' => 'Data Master', 'modul' => 'wali'],
        ['url' => '/ppsb/calon-santri', 'label' => 'Calon Santri', 'group' => 'PPSB', 'sub' => 'Data Master', 'modul' => 'santri'],
        // Arsip, sejajar dengan Alumni & Santri Keluar di Kependidikan: calon yang
        // mundur bukan lagi pekerjaan berjalan, dan membiarkannya di daftar Calon
        // membuat jumlah pendaftar di layar tak pernah sama dengan yang ditunggu.
        // Berkasnya tuntas, tinggal menunggu tahun ajarannya dimulai. Dipisah dari
        // Calon Santri supaya jumlah yang MASIH diproses tak bercampur dengan yang
        // sudah selesai diurus — dan supaya aktivasi massalnya punya satu tempat.
        ['url' => '/ppsb/siap-aktivasi', 'label' => 'Calon Santri Siap Aktivasi', 'group' => 'PPSB', 'sub' => 'Data Master', 'modul' => 'santri'],
        ['url' => '/ppsb/calon-mundur', 'label' => 'Calon Mengundurkan Diri', 'group' => 'PPSB', 'sub' => 'Data Master', 'modul' => 'santri'],
        ['url' => '/ppsb/angsuran-uang-pangkal', 'label' => 'Angsuran Uang Pangkal', 'group' => 'PPSB', 'sub' => 'Data Master', 'modul' => 'angsuran-uang-pangkal'],
        ['url' => '/ppsb/pembayaran', 'label' => 'Pembayaran Tagihan PPSB', 'group' => 'PPSB', 'sub' => 'Transaksi', 'modul' => 'pembayaran-ppsb'],
        // URL-nya BEDA dari kembarannya di Kependidikan: yang ini hanya memuat
        // santri yang masih punya kewajiban uang pangkal / perlengkapan. Dulu
        // keduanya menunjuk /rekap-pembayaran yang sama persis, sehingga menu
        // PPSB menampilkan seluruh santri — termasuk yang sudah lama lunas.
        ['url' => '/ppsb/rekap-pembayaran', 'label' => 'Rekap Pembayaran Santri', 'group' => 'PPSB', 'sub' => 'Transaksi', 'modul' => 'rekap-pembayaran'],
        // SATU daftar untuk seluruh jenjang. Penyaring jenjang ada DI HALAMANNYA
        // (`?jenjang=…`), bukan dipecah jadi menu sendiri-sendiri seperti dulu:
        // sidebar jadi memanjang seiring bertambahnya jenjang, padahal isinya
        // halaman yang sama dengan satu penyaring berbeda.
        ['url' => '/kesantrian/santri', 'label' => 'Santri Aktif', 'group' => 'KEPENDIDIKAN', 'sub' => 'Master', 'modul' => 'santri'],
        // Alumni & santri keluar berdaftar SENDIRI: dulu keduanya ikut nongol di
        // daftar Santri, sehingga jumlah di layar tak pernah sama dengan jumlah
        // santri yang benar-benar bersekolah. Modul hak aksesnya tetap `santri`.
        ['url' => '/kesantrian/alumni', 'label' => 'Alumni', 'group' => 'KEPENDIDIKAN', 'sub' => 'Master', 'modul' => 'santri'],
        ['url' => '/kesantrian/santri-keluar', 'label' => 'Santri Keluar', 'group' => 'KEPENDIDIKAN', 'sub' => 'Master', 'modul' => 'santri'],

        // Administrasi = pekerjaan BERKALA yang MENERBITKAN sesuatu (tagihan,
        // tingkat baru, nomor induk) — dipisah dari Transaksi yang menerima uang
        // harian. PERINGATAN URUTAN KERJA (bukan urutan menu): kenaikan tingkat
        // harus DIJALANKAN LEBIH DULU daripada penagihan daftar ulang, karena
        // tarif daftar ulang mengikuti tingkat yang BARU.
        ['url' => '/kesantrian/spp', 'label' => 'Penagihan SPP', 'group' => 'KEPENDIDIKAN', 'sub' => 'Administrasi', 'modul' => 'spp'],
        ['url' => '/kesantrian/tagihan-massal', 'label' => 'Penagihan Daftar Ulang', 'group' => 'KEPENDIDIKAN', 'sub' => 'Administrasi', 'modul' => 'tagihan-massal'],
        ['url' => '/kesantrian/kenaikan-tingkat', 'label' => 'Kenaikan Tingkat & Kelulusan', 'group' => 'KEPENDIDIKAN', 'sub' => 'Administrasi', 'modul' => 'kenaikan-tingkat'],
        // Penerbitan NIS: sengaja manual & massal, karena nomornya berurut
        // menurut abjad satu angkatan jenjang — bukan urutan kedatangan.
        ['url' => '/kesantrian/nis', 'label' => 'Generate NIS', 'group' => 'KEPENDIDIKAN', 'sub' => 'Administrasi', 'modul' => 'nis'],

        ['url' => '/kesantrian/pembayaran', 'label' => 'Pembayaran SPP & Tagihan Lain', 'group' => 'KEPENDIDIKAN', 'sub' => 'Transaksi', 'modul' => 'pembayaran-kesantrian'],
        ['url' => '/kesantrian/dompet', 'label' => 'Dompet & Tabungan', 'group' => 'KEPENDIDIKAN', 'sub' => 'Transaksi', 'modul' => 'dompet'],
        ['url' => '/rekap-pembayaran', 'label' => 'Rekap Pembayaran Santri', 'group' => 'KEPENDIDIKAN', 'sub' => 'Transaksi', 'modul' => 'rekap-pembayaran'],
        // Kontrol = memeriksa yang MASIH menggantung, bukan menerbitkan yang baru.
        // Sejajar dengan sub "Kontrol" di KEUANGAN, dan sengaja dipisah dari menu
        // Penagihan SPP: yang satu pekerjaan bulanan, yang ini pekerjaan harian.
        ['url' => '/kesantrian/outstanding-spp', 'label' => 'Daftar Outstanding SPP', 'group' => 'KEPENDIDIKAN', 'sub' => 'Kontrol', 'modul' => 'outstanding-spp'],

        // ---- 6. Sistem ----
        ['url' => '/approvals', 'label' => 'Persetujuan Saya', 'group' => 'SISTEM'],
        // "Outstanding Approval" (/void-approvals) dibuang 2026-07-28 — tak ada
        // rutenya (404) & fiturnya belum diport. Lihat catatan di ModulRegistry.
    ];

    public const GROUP_ORDER = ['', 'SETTING AWAL', 'ANGGARAN', 'PENGAJUAN PEMBAYARAN', 'KEUANGAN', 'PPSB', 'KEPENDIDIKAN', 'SISTEM'];

    public const SUB_ORDER = [
        'SETTING AWAL' => ['Setting Umum', 'Setting Biaya'],
        'PENGAJUAN PEMBAYARAN' => ['Buat Pengajuan', 'Pengajuan'],
        'KEUANGAN' => ['Vendor & Customer', 'Aset & Persediaan', 'Transaksi', 'Laporan', 'Kontrol'],
        'PPSB' => ['Setting Awal', 'Data Master', 'Transaksi'],
        'KEPENDIDIKAN' => ['Master', 'Administrasi', 'Transaksi', 'Kontrol'],
    ];

    /**
     * Daftar item menu.
     *
     * Dulu fungsi ini menyisipkan item yang lahir dari DATA: satu menu Santri per
     * baris master Jenjang. Sejak daftar santri digabung jadi SATU menu "Santri
     * Aktif" — penyaring jenjangnya pindah ke halamannya — tak ada lagi item
     * dinamis, dan sidebar tak lagi ikut memanjang tiap kali jenjang bertambah.
     * Pembungkusnya dipertahankan karena seluruh berkas ini memanggilnya.
     *
     * @return list<array<string,mixed>>
     */
    public static function items(): array
    {
        return self::ITEMS;
    }

    /**
     * URL item menu yang sedang dibuka; sidebar menyalakan item dengan
     * `$item['url'] === $aktif`. String kosong = tak ada yang disorot.
     */
    public static function activeUrl(): string
    {
        return self::activeItem()['url'] ?? '';
    }

    /**
     * Item yang paling cocok dengan permintaan sekarang: prefix path TERPANJANG
     * yang menang (`/ppsb/jenis-biaya` mengalahkan `/ppsb`).
     *
     * Query string TIDAK ikut dinilai — tak ada lagi item menu yang membawa
     * penyaring. `/kesantrian/santri?jenjang=SMP` tetap menyalakan menu Santri
     * Aktif, apa pun penyaring yang sedang dipakai di halamannya.
     *
     * @return array<string,mixed>|null
     */
    private static function activeItem(): ?array
    {
        $path = '/'.trim(request()->path(), '/');
        $terbaik = null;
        $skorTerbaik = -1;

        foreach (self::items() as $n) {
            $url = $n['url'];

            // Dashboard tak ikut bersaing lewat prefix; ia hanya dipakai sebagai
            // cadangan terakhir di bawah.
            if ($url === '/dashboard' || ! str_starts_with($path, $url)) {
                continue;
            }

            if (strlen($url) > $skorTerbaik) {
                $skorTerbaik = strlen($url);
                $terbaik = $n;
            }
        }

        if ($terbaik === null && str_starts_with($path, '/dashboard')) {
            foreach (self::items() as $n) {
                if ($n['url'] === '/dashboard') {
                    return $n;
                }
            }
        }

        return $terbaik;
    }

    /** Label menu sebuah URL (dipakai lonceng menyebut asal pekerjaan). */
    public static function labelUrl(string $url): ?string
    {
        foreach (self::items() as $n) {
            if ($n['url'] === $url) {
                return $n['label'];
            }
        }

        return null;
    }

    /** Grup yang memuat item aktif (untuk membuka accordion secara default). */
    public static function activeGroup(): string
    {
        return self::activeItem()['group'] ?? '';
    }

    /** Sub-grup yang memuat item aktif (untuk membuka sub-accordion default). */
    public static function activeSub(): ?string
    {
        return self::activeItem()['sub'] ?? null;
    }

    /** Apakah item terlihat untuk pengguna login (replika filter AppShell). */
    private static function canSee(array $n): bool
    {
        $user = Auth::user();
        if (! $user) {
            return false;
        }

        if ($n['adminOnly'] ?? false) {
            return (bool) $user->is_admin;
        }
        if ($n['direktoratOrAdmin'] ?? false) {
            if ($user->is_admin) {
                return true;
            }
            $level = $user->bagian?->level;
            if ($level !== null && $level <= 2) {
                return true;
            }

            return $user->kode_bagian
                && in_array($user->peringkat_pengajuan, [PeringkatPengajuan::MUDIR_BAGIAN, PeringkatPengajuan::STAFF], true);
        }
        if (($n['staffOnly'] ?? false) && ! ($user->is_admin || $user->peringkat_pengajuan === PeringkatPengajuan::STAFF)) {
            return false;
        }
        // Pengajuan anggaran boleh dari Staff MAUPUN Mudir Bagian (mudir yang
        // mengajukan otomatis melewati tahap bagiannya sendiri). SENGAJA tidak
        // meloloskan is_admin seperti staffOnly: admin lazimnya tanpa peringkat
        // & tanpa bagian, jadi menunya akan mengantar ke form yang tak bisa
        // dipakai. Admin punya jalurnya sendiri (Input Anggaran, simpan
        // langsung). Admin yang MEMANG ber-peringkat Staff/Mudir Bagian tetap
        // melihatnya lewat pemeriksaan di bawah.
        if (($n['staffOrMudirBagian'] ?? false) && ! in_array(
            $user->peringkat_pengajuan,
            [PeringkatPengajuan::STAFF, PeringkatPengajuan::MUDIR_BAGIAN],
            true,
        )) {
            return false;
        }
        // Menu yang isinya beberapa bagian berhak-akses sendiri (mis. Dashboard
        // ber-tab): tampil bila berhak atas salah satunya.
        if (! empty($n['modulAny'])) {
            foreach ($n['modulAny'] as $kode) {
                if (Akses::bolehMenu($kode)) {
                    return true;
                }
            }

            return false;
        }
        if (empty($n['modul'])) {
            return true;
        }

        return Akses::bolehMenu($n['modul']);
    }

    /**
     * Pohon sidebar terurut: grup → sub → item. Grup '' tampil tanpa header.
     *
     * @return list<array{group:string,subs:list<array{sub:?string,items:list<array{url:string,label:string}>}>}>
     */
    public static function tree(): array
    {
        $tampil = array_values(array_filter(self::items(), fn ($n) => self::canSee($n)));

        $hasil = [];
        foreach (self::GROUP_ORDER as $group) {
            $anggota = array_values(array_filter($tampil, fn ($n) => $n['group'] === $group));
            if ($anggota === []) {
                continue;
            }

            $subs = [];
            $tanpaSub = array_values(array_filter($anggota, fn ($n) => empty($n['sub'])));
            if ($tanpaSub !== []) {
                $subs[] = ['sub' => null, 'items' => self::petakan($tanpaSub)];
            }
            foreach (self::SUB_ORDER[$group] ?? [] as $sub) {
                $isi = array_values(array_filter($anggota, fn ($n) => ($n['sub'] ?? null) === $sub));
                if ($isi !== []) {
                    $subs[] = ['sub' => $sub, 'items' => self::petakan($isi)];
                }
            }

            $hasil[] = ['group' => $group, 'subs' => $subs];
        }

        return $hasil;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{url:string,label:string}>
     */
    private static function petakan(array $items): array
    {
        return array_map(fn ($n) => ['url' => $n['url'], 'label' => $n['label']], $items);
    }
}
