<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BagianController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BusinessUnitController;
use App\Http\Controllers\CoaDetailController;
use App\Http\Controllers\CoaGroupController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HakAksesController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\LevelPengajuanController;
use App\Http\Controllers\UnitDefaultController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

/**
 * Registrasi rute CRUD standar untuk satu modul master, dengan gate hak akses
 * per-aksi (lihat/buat/ubah/hapus). Nama rute: "<kode>.index|create|store|
 * edit|update|destroy". $param = nama parameter route model binding.
 */
if (! function_exists('crudModul')) {
    function crudModul(string $kode, string $controller, string $param): void
    {
        $nama = str_replace('-', '_', $kode);

    Route::prefix($kode)->name("{$nama}.")->group(function () use ($controller, $kode, $param) {
        Route::get('/', [$controller, 'index'])->name('index')->middleware("hakakses:{$kode},lihat");
        Route::get('/create', [$controller, 'create'])->name('create')->middleware("hakakses:{$kode},buat");
        Route::post('/', [$controller, 'store'])->name('store')->middleware("hakakses:{$kode},buat");
        Route::get("/{{$param}}/edit", [$controller, 'edit'])->name('edit')->middleware("hakakses:{$kode},ubah");
        Route::put("/{{$param}}", [$controller, 'update'])->name('update')->middleware("hakakses:{$kode},ubah");
        Route::delete("/{{$param}}", [$controller, 'destroy'])->name('destroy')->middleware("hakakses:{$kode},hapus");
    });
    }
}

// ---- Autentikasi (tamu) ----
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ---- Aplikasi (wajib login) ----
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route('dashboard'));

    // Tanpa middleware hakakses: satu rute melayani dua tab (keuangan & PPSB)
    // dengan hak berbeda — gerbangnya di DashboardController::index().
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/export/{type}', [DashboardController::class, 'download'])
        ->middleware('hakakses:dashboard,lihat')
        ->name('dashboard.download');
    // Unduhan rincian kartu PPSB — haknya tab PPSB, dicek di controller karena
    // middleware hakakses hanya tahu satu modul per rute.
    Route::get('/dashboard/ppsb/export/{jenis}', [DashboardController::class, 'exportPpsb'])
        ->name('dashboard.ppsb_export');

    // ---- Setting Awal ----
    crudModul('levels', LevelController::class, 'level');
    crudModul('business-units', BusinessUnitController::class, 'business_unit');
    crudModul('bagian', BagianController::class, 'bagian');

    // Level Pengajuan — edit-only (peringkat 1–4 tetap).
    Route::prefix('level-pengajuan')->name('level_pengajuan.')->group(function () {
        Route::get('/', [LevelPengajuanController::class, 'index'])->name('index')->middleware('hakakses:level-pengajuan,lihat');
        Route::get('/{level_pengajuan}/edit', [LevelPengajuanController::class, 'edit'])->name('edit')->middleware('hakakses:level-pengajuan,ubah');
        Route::put('/{level_pengajuan}', [LevelPengajuanController::class, 'update'])->name('update')->middleware('hakakses:level-pengajuan,ubah');
    });

    // Pengaturan Perusahaan — singleton, edit-only.
    Route::prefix('company-settings')->name('company_settings.')->group(function () {
        Route::get('/', [CompanySettingsController::class, 'edit'])->name('edit')->middleware('hakakses:company-settings,lihat');
        Route::put('/', [CompanySettingsController::class, 'update'])->name('update')->middleware('hakakses:company-settings,ubah');
    });

    // Master Jenjang — sumber tunggal daftar jenjang lintas modul.
    Route::prefix('jenjang')->name('jenjang.')->group(function () {
        $j = \App\Http\Controllers\JenjangController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:jenjang,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:jenjang,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:jenjang,buat');
        Route::get('/{kode}/edit', [$j, 'edit'])->name('edit')->middleware('hakakses:jenjang,ubah');
        Route::put('/{kode}', [$j, 'update'])->name('update')->middleware('hakakses:jenjang,ubah');
        Route::delete('/{kode}', [$j, 'destroy'])->name('destroy')->middleware('hakakses:jenjang,hapus');
    });

    // Reminder Tagihan Jatuh Tempo — singleton setting + pratinjau + kirim manual.
    Route::prefix('reminder-tagihan')->name('reminder_tagihan.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ReminderTagihanController::class, 'index'])->name('index')->middleware('hakakses:reminder-tagihan,lihat');
        Route::put('/', [\App\Http\Controllers\ReminderTagihanController::class, 'update'])->name('update')->middleware('hakakses:reminder-tagihan,ubah');
        Route::post('/kirim', [\App\Http\Controllers\ReminderTagihanController::class, 'kirim'])->name('kirim')->middleware('hakakses:reminder-tagihan,ubah');
    });

    crudModul('users', UserController::class, 'user');

    // Matriks hak akses per pengguna — KHUSUS ADMIN (di luar matriks modul).
    Route::middleware(\App\Http\Middleware\RequireAdmin::class)->group(function () {
        Route::get('/hak-akses', [HakAksesController::class, 'index'])->name('hak_akses.index');
        Route::get('/hak-akses/{user}', [HakAksesController::class, 'edit'])->name('hak_akses.edit');
        Route::put('/hak-akses/{user}', [HakAksesController::class, 'update'])->name('hak_akses.update');
    });

    // ---- Keuangan: Kontrol / Master ----
    // Chart of Account terpadu (tab Struktur Pohon / Grup / Detail).
    Route::get('/coa', [\App\Http\Controllers\CoaController::class, 'index'])->name('coa.index')->middleware('hakakses:coa-detail,lihat');
    crudModul('coa-groups', CoaGroupController::class, 'coa_group');
    crudModul('coa-detail', CoaDetailController::class, 'coa_detail');
    crudModul('bank-accounts', BankAccountController::class, 'bank_account');
    crudModul('unit-default', UnitDefaultController::class, 'unit_default');

    // Master "jenis" sederhana (kode + nama + status) via controller generik.
    crudModul('vendor-types', \App\Http\Controllers\SimpleMasterController::class, 'id');
    crudModul('customer-types', \App\Http\Controllers\SimpleMasterController::class, 'id');
    crudModul('asset-categories', \App\Http\Controllers\SimpleMasterController::class, 'id');

    // Aset Tetap (CRUD + jalankan depresiasi bulanan).
    Route::post('/assets/run-depreciation', [\App\Http\Controllers\AssetController::class, 'runDepreciation'])->name('assets.run_depreciation')->middleware('hakakses:assets,ubah');
    crudModul('assets', \App\Http\Controllers\AssetController::class, 'asset');

    // Persediaan (CRUD + mutasi stok manual).
    crudModul('inventory', \App\Http\Controllers\InventoryController::class, 'inventory');
    Route::post('/inventory/{inventory}/mutasi', [\App\Http\Controllers\InventoryController::class, 'mutasi'])->name('inventory.mutasi')->middleware('hakakses:inventory,ubah');

    crudModul('vendors', \App\Http\Controllers\VendorController::class, 'vendor');
    crudModul('customers', \App\Http\Controllers\CustomerController::class, 'customer');

    // ---- Saldo Awal (jurnal pembuka) ----
    Route::prefix('opening-balance')->name('opening_balance.')->group(function () {
        $o = \App\Http\Controllers\OpeningBalanceController::class;
        Route::get('/', [$o, 'index'])->name('index')->middleware('hakakses:opening-balance,lihat');
        Route::post('/lines', [$o, 'addLine'])->name('add')->middleware('hakakses:opening-balance,ubah');
        Route::delete('/lines/{id}', [$o, 'removeLine'])->name('remove')->middleware('hakakses:opening-balance,ubah')->whereNumber('id');
        Route::post('/post', [$o, 'post'])->name('post')->middleware('hakakses:opening-balance,ubah');
        Route::post('/void', [$o, 'void'])->name('void')->middleware('hakakses:opening-balance,ubah');
    });

    // ---- Tutup Buku Periode ----
    Route::prefix('period-close')->name('period_close.')->group(function () {
        $pc = \App\Http\Controllers\PeriodCloseController::class;
        Route::get('/', [$pc, 'index'])->name('index')->middleware('hakakses:period-close,lihat');
        Route::post('/tutup-bulan', [$pc, 'tutupBulan'])->name('tutup_bulan')->middleware('hakakses:period-close,ubah');
        Route::post('/buka-bulan', [$pc, 'bukaBulan'])->name('buka_bulan')->middleware('hakakses:period-close,ubah');
        Route::post('/tutup-tahun', [$pc, 'tutupTahun'])->name('tutup_tahun')->middleware('hakakses:period-close,ubah');
        Route::post('/buka-tahun', [$pc, 'bukaTahun'])->name('buka_tahun')->middleware('hakakses:period-close,ubah');
    });

    // ---- Export Data (CSV / Excel / PDF) ----
    Route::prefix('export')->name('export.')->group(function () {
        $ex = \App\Http\Controllers\ExportController::class;
        Route::get('/', [$ex, 'index'])->name('index');
        Route::get('/jurnal-mentah', [$ex, 'jurnalMentah'])->name('jurnal_mentah');
        Route::get('/buku-besar', [$ex, 'bukuBesar'])->name('buku_besar');
        Route::get('/aset', [$ex, 'aset'])->name('aset');
        Route::get('/dataset/{key}', [$ex, 'dataset'])->name('dataset');
    });

    // ---- Kontrol Outstanding (read-only lintas modul) ----
    Route::prefix('kontrol')->name('kontrol.')->group(function () {
        $k = \App\Http\Controllers\KontrolController::class;
        Route::get('/ringkasan', [$k, 'ringkasan'])->name('ringkasan');
        Route::get('/aging-ap', [$k, 'agingAp'])->name('aging_ap');
        Route::get('/uang-muka-customer', [$k, 'uangMukaCustomer'])->name('uang_muka_customer');
        Route::get('/uang-muka-operasional', [$k, 'uangMukaOperasional'])->name('uang_muka_operasional');
        Route::get('/accrue-prepaid', [$k, 'accruePrepaid'])->name('accrue_prepaid');
        Route::get('/rekap-pembiayaan', [$k, 'rekapPembiayaan'])->name('rekap_pembiayaan');
        Route::get('/export/{type}', [$k, 'download'])->name('download');
    });

    // ---- Karyawan & Pinjaman Karyawan ----
    // Master karyawan ringkas; kelak diambil alih HRD.
    Route::prefix('karyawan')->name('karyawan.')->group(function () {
        $k = \App\Http\Controllers\KaryawanController::class;
        Route::get('/', [$k, 'index'])->name('index')->middleware('hakakses:karyawan,lihat');
        Route::get('/create', [$k, 'create'])->name('create')->middleware('hakakses:karyawan,buat');
        Route::post('/', [$k, 'store'])->name('store')->middleware('hakakses:karyawan,buat');
        Route::get('/{kode}/edit', [$k, 'edit'])->name('edit')->middleware('hakakses:karyawan,ubah');
        Route::put('/{kode}', [$k, 'update'])->name('update')->middleware('hakakses:karyawan,ubah');
        Route::delete('/{kode}', [$k, 'destroy'])->name('destroy')->middleware('hakakses:karyawan,hapus');
    });

    Route::prefix('pinjaman-karyawan')->name('pinjaman_karyawan.')->group(function () {
        $p = \App\Http\Controllers\PinjamanKaryawanController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:pinjaman-karyawan,lihat');
        Route::get('/buat', [$p, 'create'])->name('create')->middleware('hakakses:pinjaman-karyawan,buat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:pinjaman-karyawan,buat');
        Route::get('/{id}', [$p, 'show'])->name('show')->middleware('hakakses:pinjaman-karyawan,lihat')->whereNumber('id');
        // Mencatat cicilan = mengubah pinjaman, bukan membuat dokumen baru.
        Route::post('/{id}/bayar', [$p, 'bayar'])->name('bayar')->middleware('hakakses:pinjaman-karyawan,ubah')->whereNumber('id');
        Route::post('/{id}/termin', [$p, 'aturTermin'])->name('termin')->middleware('hakakses:pinjaman-karyawan,ubah')->whereNumber('id');
    });

    // ---- Impor Data Awal (alat pindahan sistem) ----
    // Menulis dokumen TANPA jurnal; saldonya masuk lewat menu Saldo Awal.
    // 'lihat' cukup untuk melihat & memeriksa berkas; menulis butuh 'buat'.
    Route::prefix('impor-data-awal')->name('impor_data_awal.')->group(function () {
        $i = \App\Http\Controllers\ImporDataAwalController::class;
        Route::get('/', [$i, 'index'])->name('index')->middleware('hakakses:impor-data-awal,lihat');
        Route::get('/template/{jenis}', [$i, 'template'])->name('template')->middleware('hakakses:impor-data-awal,lihat');
        Route::post('/pratinjau', [$i, 'pratinjau'])->name('pratinjau')->middleware('hakakses:impor-data-awal,buat');
        Route::post('/jalankan', [$i, 'jalankan'])->name('jalankan')->middleware('hakakses:impor-data-awal,buat');
    });

    // ---- Anggaran (Input & Realisasi) ----
    Route::prefix('budget')->name('budget.')->group(function () {
        $b = \App\Http\Controllers\BudgetController::class;
        // Realisasi bebas matriks — gerbang bertingkat di service (admin |
        // Yayasan | Direktorat subtree | Mudir Bagian/Staff bagian sendiri).
        Route::get('/realisasi', [$b, 'realisasi'])->name('realisasi');
        // Input Anggaran — lihat lewat matriks modul 'budget'.
        Route::get('/', [$b, 'index'])->name('index')->middleware('hakakses:budget,lihat');

        // Pengajuan Anggaran (§3.c) — jalur non-admin lewat rantai BUDGET-STD.
        // Digerbangi hak modul 'budget' (sama seperti app lama): 'lihat' untuk
        // melihat status, 'buat' untuk mengajukan & membatalkan miliknya.
        // Diletakkan SEBELUM PUT '/' yang admin-only agar tak ikut tergerbang.
        $bp = \App\Http\Controllers\BudgetPengajuanController::class;
        Route::get('/pengajuan', [$bp, 'index'])->name('pengajuan.index')->middleware('hakakses:budget,lihat');
        Route::get('/pengajuan/buat', [$bp, 'create'])->name('pengajuan.create')->middleware('hakakses:budget,buat');
        Route::post('/pengajuan', [$bp, 'store'])->name('pengajuan.store')->middleware('hakakses:budget,buat');
        Route::get('/pengajuan/{id}', [$bp, 'show'])->name('pengajuan.show')->middleware('hakakses:budget,lihat')->whereNumber('id');
        // "batal" (bukan "void") agar tergerbang aksi BUAT, bukan hapus — pemohon
        // yang boleh membuat boleh membatalkan miliknya. Kepemilikan tetap
        // ditegakkan di service.
        Route::post('/pengajuan/{id}/batal', [$bp, 'batal'])->name('pengajuan.batal')->middleware('hakakses:budget,buat')->whereNumber('id');
        // Tulis anggaran langsung + kunci/buka = KHUSUS ADMIN (jalur darurat).
        Route::put('/', [$b, 'save'])->name('save')->middleware(\App\Http\Middleware\RequireAdmin::class);
        Route::post('/lock', [$b, 'lock'])->name('lock')->middleware(\App\Http\Middleware\RequireAdmin::class);
        Route::delete('/lock/{tahun}', [$b, 'unlock'])->name('unlock')->middleware(\App\Http\Middleware\RequireAdmin::class)->whereNumber('tahun');
    });

    // ---- Pengajuan Pembayaran (§4) ----
    Route::prefix('pengajuan-pembayaran')->name('pengajuan.')->group(function () {
        $p = \App\Http\Controllers\PengajuanController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:pengajuan-pembayaran,lihat');
        Route::get('/buat', [$p, 'create'])->name('create')->middleware('hakakses:pengajuan-pembayaran,buat');
        Route::get('/buat/uang-muka', [$p, 'createUangMuka'])->name('create_uang_muka')->middleware('hakakses:pengajuan-pembayaran,buat');
        Route::get('/buat/penyelesaian', [$p, 'createPenyelesaian'])->name('create_penyelesaian')->middleware('hakakses:pengajuan-pembayaran,buat');
        Route::get('/outstanding-uang-muka', [$p, 'outstandingUangMuka'])->name('outstanding_uang_muka')->middleware('hakakses:pengajuan-pembayaran,lihat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:pengajuan-pembayaran,buat');
        Route::get('/{id}/perbaiki', [$p, 'edit'])->name('edit')->middleware('hakakses:pengajuan-pembayaran,buat')->whereNumber('id');
        Route::get('/{id}/cetak', [$p, 'cetak'])->name('cetak')->middleware('hakakses:pengajuan-pembayaran,lihat')->whereNumber('id');
        Route::get('/{id}', [$p, 'show'])->name('show')->middleware('hakakses:pengajuan-pembayaran,lihat')->whereNumber('id');
        Route::put('/{id}', [$p, 'update'])->name('update')->middleware('hakakses:pengajuan-pembayaran,buat')->whereNumber('id');
        Route::post('/{id}/ajukan-ulang', [$p, 'ajukanUlang'])->name('ajukan_ulang')->middleware('hakakses:pengajuan-pembayaran,buat')->whereNumber('id');
        Route::post('/{id}/verifikasi', [$p, 'verifikasi'])->name('verifikasi')->middleware('hakakses:pengajuan-pembayaran,ubah')->whereNumber('id');
        Route::delete('/{id}', [$p, 'void'])->name('void')->middleware('hakakses:pengajuan-pembayaran,hapus')->whereNumber('id');
    });

    // Persetujuan Saya (approval inbox) — di luar matriks modul (wewenang dari
    // peringkat/fungsi), hanya wajib login.
    Route::prefix('approvals')->name('approvals.')->group(function () {
        $a = \App\Http\Controllers\ApprovalController::class;
        Route::get('/', [$a, 'inbox'])->name('inbox');
        Route::post('/{id}/approve', [$a, 'approve'])->name('approve')->whereNumber('id');
        Route::post('/{id}/reject', [$a, 'reject'])->name('reject')->whereNumber('id');
    });

    // Master Tipe Biaya (Setting Awal) — perilaku tiap tipe menentukan alurnya.
    Route::prefix('tipe-biaya')->name('tipe_biaya.')->group(function () {
        $t = \App\Http\Controllers\TipeBiayaController::class;
        Route::get('/', [$t, 'index'])->name('index')->middleware('hakakses:tipe-biaya,lihat');
        Route::get('/create', [$t, 'create'])->name('create')->middleware('hakakses:tipe-biaya,buat');
        Route::post('/', [$t, 'store'])->name('store')->middleware('hakakses:tipe-biaya,buat');
        Route::get('/{kode}/edit', [$t, 'edit'])->name('edit')->middleware('hakakses:tipe-biaya,ubah');
        Route::put('/{kode}', [$t, 'update'])->name('update')->middleware('hakakses:tipe-biaya,ubah');
        Route::delete('/{kode}', [$t, 'destroy'])->name('destroy')->middleware('hakakses:tipe-biaya,hapus');
    });

    // Master Sumber Informasi (PPSB → Setting Awal).
    Route::prefix('ppsb/sumber-informasi')->name('sumber_informasi.')->group(function () {
        $s = \App\Http\Controllers\SumberInformasiController::class;
        Route::get('/', [$s, 'index'])->name('index')->middleware('hakakses:sumber-informasi,lihat');
        Route::get('/create', [$s, 'create'])->name('create')->middleware('hakakses:sumber-informasi,buat');
        Route::post('/', [$s, 'store'])->name('store')->middleware('hakakses:sumber-informasi,buat');
        Route::get('/{kode}/edit', [$s, 'edit'])->name('edit')->middleware('hakakses:sumber-informasi,ubah');
        Route::put('/{kode}', [$s, 'update'])->name('update')->middleware('hakakses:sumber-informasi,ubah');
        Route::delete('/{kode}', [$s, 'destroy'])->name('destroy')->middleware('hakakses:sumber-informasi,hapus');
    });

    // Notifikasi pribadi — seperti approval inbox, di luar matriks modul: tiap
    // pengguna hanya melihat barisnya sendiri.
    Route::prefix('notifikasi')->name('notifikasi.')->group(function () {
        $n = \App\Http\Controllers\NotifikasiController::class;
        Route::get('/', [$n, 'index'])->name('index');
        Route::post('/baca-semua', [$n, 'bacaSemua'])->name('baca_semua');
        Route::post('/{id}/baca', [$n, 'baca'])->name('baca')->whereNumber('id');
    });

    // ---- Keuangan: Transaksi ----
    // Kas Keluar (Debit rincian; Kredit Kas/Bank) — jenis "lainnya".
    Route::prefix('cash-out')->name('cash_out.')->group(function () {
        $c = \App\Http\Controllers\CashOutController::class;
        Route::get('/', [$c, 'index'])->name('index')->middleware('hakakses:cash-out,lihat');
        Route::get('/create', [$c, 'create'])->name('create')->middleware('hakakses:cash-out,buat');
        Route::post('/', [$c, 'store'])->name('store')->middleware('hakakses:cash-out,buat');
        Route::get('/{cash_out}', [$c, 'show'])->name('show')->middleware('hakakses:cash-out,lihat');
        Route::delete('/{cash_out}', [$c, 'void'])->name('void')->middleware('hakakses:cash-out,hapus');
    });

    // Kas Masuk (Debit Kas/Bank; Kredit rincian).
    Route::prefix('cash-in')->name('cash_in.')->group(function () {
        $c = \App\Http\Controllers\CashInController::class;
        Route::get('/', [$c, 'index'])->name('index')->middleware('hakakses:cash-in,lihat');
        Route::get('/create', [$c, 'create'])->name('create')->middleware('hakakses:cash-in,buat');
        Route::post('/', [$c, 'store'])->name('store')->middleware('hakakses:cash-in,buat');
        Route::post('/{cash_in}/akui', [$c, 'akui'])->name('akui')->middleware('hakakses:cash-in,buat');
        Route::get('/{cash_in}', [$c, 'show'])->name('show')->middleware('hakakses:cash-in,lihat');
        Route::delete('/{cash_in}', [$c, 'void'])->name('void')->middleware('hakakses:cash-in,hapus');
    });

    // Rekonsiliasi Bank (workflow: draft → cleared/penyesuaian → finalize).
    Route::prefix('bank-reconciliation')->name('bank_reconciliation.')->group(function () {
        $b = \App\Http\Controllers\BankReconciliationController::class;
        Route::get('/', [$b, 'index'])->name('index')->middleware('hakakses:bank-reconciliation,lihat');
        Route::get('/create', [$b, 'create'])->name('create')->middleware('hakakses:bank-reconciliation,buat');
        Route::post('/', [$b, 'store'])->name('store')->middleware('hakakses:bank-reconciliation,buat');
        Route::get('/{id}', [$b, 'show'])->name('show')->middleware('hakakses:bank-reconciliation,lihat')->whereNumber('id');
        Route::post('/{id}/items/{itemId}', [$b, 'toggleItem'])->name('toggle')->middleware('hakakses:bank-reconciliation,ubah')->whereNumber('id')->whereNumber('itemId');
        Route::post('/{id}/adjustment', [$b, 'adjustment'])->name('adjustment')->middleware('hakakses:bank-reconciliation,ubah')->whereNumber('id');
        Route::post('/{id}/finalize', [$b, 'finalize'])->name('finalize')->middleware('hakakses:bank-reconciliation,ubah')->whereNumber('id');
        Route::delete('/{id}', [$b, 'destroy'])->name('destroy')->middleware('hakakses:bank-reconciliation,hapus')->whereNumber('id');
    });

    // Purchase Order (dokumen komitmen, tanpa jurnal) + batal.
    Route::prefix('purchase-orders')->name('purchase_orders.')->group(function () {
        $p = \App\Http\Controllers\PurchaseOrderController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:purchase-orders,lihat');
        Route::get('/create', [$p, 'create'])->name('create')->middleware('hakakses:purchase-orders,buat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:purchase-orders,buat');
        Route::get('/{purchase_order}/print', [$p, 'print'])->name('print')->middleware('hakakses:purchase-orders,lihat');
        Route::get('/{purchase_order}', [$p, 'show'])->name('show')->middleware('hakakses:purchase-orders,lihat');
        Route::delete('/{purchase_order}', [$p, 'cancel'])->name('cancel')->middleware('hakakses:purchase-orders,hapus');
    });

    // Invoice Vendor (Debit rincian; Kredit hutang usaha).
    Route::prefix('invoices')->name('invoices.')->group(function () {
        $i = \App\Http\Controllers\InvoiceController::class;
        Route::get('/', [$i, 'index'])->name('index')->middleware('hakakses:invoices,lihat');
        Route::get('/create', [$i, 'create'])->name('create')->middleware('hakakses:invoices,buat');
        Route::post('/', [$i, 'store'])->name('store')->middleware('hakakses:invoices,buat');
        Route::get('/{invoice}', [$i, 'show'])->name('show')->middleware('hakakses:invoices,lihat');
        Route::delete('/{invoice}', [$i, 'void'])->name('void')->middleware('hakakses:invoices,hapus');
    });

    // Penyelesaian Uang Muka (Kredit UM; Debit realisasi; selisih via kas).
    Route::prefix('advance-settlement')->name('advance_settlement.')->group(function () {
        $s = \App\Http\Controllers\AdvanceSettlementController::class;
        Route::get('/', [$s, 'index'])->name('index')->middleware('hakakses:advance-settlement,lihat');
        Route::get('/create', [$s, 'create'])->name('create')->middleware('hakakses:advance-settlement,buat');
        Route::post('/', [$s, 'store'])->name('store')->middleware('hakakses:advance-settlement,buat');
    });

    // Uang Muka Operasional (Debit akun uang muka; Kredit kas/bank).
    Route::prefix('operational-advance')->name('operational_advance.')->group(function () {
        $u = \App\Http\Controllers\OperationalAdvanceController::class;
        Route::get('/', [$u, 'index'])->name('index')->middleware('hakakses:operational-advance,lihat');
        Route::get('/create', [$u, 'create'])->name('create')->middleware('hakakses:operational-advance,buat');
        Route::post('/', [$u, 'store'])->name('store')->middleware('hakakses:operational-advance,buat');
        Route::delete('/{operational_advance}', [$u, 'void'])->name('void')->middleware('hakakses:operational-advance,hapus');
    });

    // Accrue & Prepaid (jurnal penyesuaian) + reversal awal bulan.
    Route::prefix('accrue')->name('accrue.')->group(function () {
        $a = \App\Http\Controllers\AccrueController::class;
        Route::get('/', [$a, 'index'])->name('index')->middleware('hakakses:accrue,lihat');
        Route::get('/create', [$a, 'create'])->name('create')->middleware('hakakses:accrue,buat');
        Route::post('/', [$a, 'store'])->name('store')->middleware('hakakses:accrue,buat');
        Route::post('/run-reversal', [$a, 'runReversal'])->name('run_reversal')->middleware('hakakses:accrue,buat');
    });

    // Pembiayaan Bank (syariah) — pencairan Debit Kas/Bank; Kredit hutang.
    Route::prefix('bank-loans')->name('bank_loans.')->group(function () {
        $l = \App\Http\Controllers\BankLoanController::class;
        Route::get('/', [$l, 'index'])->name('index')->middleware('hakakses:bank-loans,lihat');
        Route::get('/create', [$l, 'create'])->name('create')->middleware('hakakses:bank-loans,buat');
        Route::post('/', [$l, 'store'])->name('store')->middleware('hakakses:bank-loans,buat');
        Route::get('/{bank_loan}', [$l, 'show'])->name('show')->middleware('hakakses:bank-loans,lihat');
        Route::delete('/{bank_loan}', [$l, 'void'])->name('void')->middleware('hakakses:bank-loans,hapus');
    });

    // Pindah Buku (Debit rekening tujuan; Kredit rekening asal).
    Route::prefix('book-transfer')->name('book_transfer.')->group(function () {
        $b = \App\Http\Controllers\BookTransferController::class;
        Route::get('/', [$b, 'index'])->name('index')->middleware('hakakses:book-transfer,lihat');
        Route::get('/create', [$b, 'create'])->name('create')->middleware('hakakses:book-transfer,buat');
        Route::post('/', [$b, 'store'])->name('store')->middleware('hakakses:book-transfer,buat');
        Route::delete('/{id}', [$b, 'void'])->name('void')->middleware('hakakses:book-transfer,hapus');
    });

    // Jurnal Umum (template modul transaksi: controller tipis → service).
    Route::prefix('journal')->name('journal.')->group(function () {
        $j = \App\Http\Controllers\JournalController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:journal,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:journal,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:journal,buat');
        Route::get('/{journal}', [$j, 'show'])->name('show')->middleware('hakakses:journal,lihat');
        Route::delete('/{journal}', [$j, 'void'])->name('void')->middleware('hakakses:journal,hapus');
    });

    // ---- PPSB & Kesantrian ----
    // Tahun Ajaran (master, CRUD) — rujukan master PPSB lain & registrasi.
    Route::prefix('ppsb/tahun-ajaran')->name('tahun_ajaran.')->group(function () {
        $t = \App\Http\Controllers\TahunAjaranController::class;
        Route::get('/', [$t, 'index'])->name('index')->middleware('hakakses:tahun-ajaran,lihat');
        Route::get('/create', [$t, 'create'])->name('create')->middleware('hakakses:tahun-ajaran,buat');
        Route::post('/', [$t, 'store'])->name('store')->middleware('hakakses:tahun-ajaran,buat');
        Route::get('/{id}/edit', [$t, 'edit'])->name('edit')->middleware('hakakses:tahun-ajaran,ubah')->whereNumber('id');
        Route::put('/{id}', [$t, 'update'])->name('update')->middleware('hakakses:tahun-ajaran,ubah')->whereNumber('id');
        Route::delete('/{id}', [$t, 'destroy'])->name('destroy')->middleware('hakakses:tahun-ajaran,hapus')->whereNumber('id');
    });

    // Jenis Biaya (registrasi/uang pangkal/SPP/lain).
    Route::prefix('ppsb/jenis-biaya')->name('jenis_biaya.')->group(function () {
        $j = \App\Http\Controllers\JenisBiayaController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:jenis-biaya,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:jenis-biaya,buat');
        // Duplikat butuh hak BUAT (menciptakan baris baru), bukan sekadar lihat.
        Route::get('/duplikat', [$j, 'duplikatForm'])->name('duplikat_form')->middleware('hakakses:jenis-biaya,buat');
        Route::post('/duplikat', [$j, 'duplikat'])->name('duplikat')->middleware('hakakses:jenis-biaya,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:jenis-biaya,buat');
        Route::get('/{kode}/edit', [$j, 'edit'])->name('edit')->middleware('hakakses:jenis-biaya,ubah');
        Route::put('/{kode}', [$j, 'update'])->name('update')->middleware('hakakses:jenis-biaya,ubah');
        Route::delete('/{kode}', [$j, 'destroy'])->name('destroy')->middleware('hakakses:jenis-biaya,hapus');
    });

    // Potongan Gelombang (create/list/remove).
    Route::prefix('ppsb/potongan-gelombang')->name('potongan_gelombang.')->group(function () {
        $p = \App\Http\Controllers\PotonganGelombangController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:potongan-gelombang,lihat');
        Route::get('/create', [$p, 'create'])->name('create')->middleware('hakakses:potongan-gelombang,buat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:potongan-gelombang,buat');
        Route::delete('/{id}', [$p, 'destroy'])->name('destroy')->middleware('hakakses:potongan-gelombang,hapus')->whereNumber('id');
    });

    // Angsuran Uang Pangkal (rencana termin + reminder).
    Route::prefix('ppsb/angsuran-uang-pangkal')->name('angsuran_uang_pangkal.')->controller(\App\Http\Controllers\AngsuranUangPangkalController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:angsuran-uang-pangkal,lihat');
        Route::get('/create', 'create')->name('create')->middleware('hakakses:angsuran-uang-pangkal,buat');
        Route::post('/', 'store')->name('store')->middleware('hakakses:angsuran-uang-pangkal,buat');
        Route::post('/termin/{idTermin}/ingatkan', 'ingatkan')->name('ingatkan')->middleware('hakakses:angsuran-uang-pangkal,ubah')->whereNumber('idTermin');
        Route::post('/termin/{idTermin}/feedback', 'feedback')->name('feedback')->middleware('hakakses:angsuran-uang-pangkal,ubah')->whereNumber('idTermin');
        Route::post('/potongan/evaluasi', 'evaluasiPotongan')->name('evaluasi_potongan')->middleware('hakakses:angsuran-uang-pangkal,ubah');
        Route::get('/cetak-rekap', 'cetakRekap')->name('cetak_rekap')->middleware('hakakses:angsuran-uang-pangkal,lihat');
        Route::get('/{idSantri}', 'show')->name('show')->middleware('hakakses:angsuran-uang-pangkal,lihat')->whereNumber('idSantri');
        Route::get('/{idSantri}/cetak', 'cetakDetail')->name('cetak_detail')->middleware('hakakses:angsuran-uang-pangkal,lihat')->whereNumber('idSantri');
        Route::post('/{idSantri}/renegosiasi', 'renegosiasi')->name('renegosiasi')->middleware('hakakses:angsuran-uang-pangkal,ubah')->whereNumber('idSantri');
    });

    // Setting Filter Termin Jatuh Tempo — singleton, edit-only.
    Route::prefix('ppsb/termin-filter')->name('termin_filter.')->group(function () {
        $t = \App\Http\Controllers\TerminFilterController::class;
        Route::get('/', [$t, 'edit'])->name('edit')->middleware('hakakses:termin-filter,lihat');
        Route::put('/', [$t, 'update'])->name('update')->middleware('hakakses:termin-filter,ubah');
    });

    // Jalur Pendaftaran (master, CRUD).
    Route::prefix('ppsb/jalur-pendaftaran')->name('jalur_pendaftaran.')->group(function () {
        $j = \App\Http\Controllers\JalurPendaftaranController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:jalur-pendaftaran,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:jalur-pendaftaran,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:jalur-pendaftaran,buat');
        Route::get('/{kode}/edit', [$j, 'edit'])->name('edit')->middleware('hakakses:jalur-pendaftaran,ubah');
        Route::put('/{kode}', [$j, 'update'])->name('update')->middleware('hakakses:jalur-pendaftaran,ubah');
        Route::delete('/{kode}', [$j, 'destroy'])->name('destroy')->middleware('hakakses:jalur-pendaftaran,hapus');
    });

    // Target Santri (CRUD).
    Route::prefix('ppsb/target-santri')->name('target_santri.')->group(function () {
        $t = \App\Http\Controllers\TargetSantriController::class;
        Route::get('/', [$t, 'index'])->name('index')->middleware('hakakses:target-santri,lihat');
        Route::get('/create', [$t, 'create'])->name('create')->middleware('hakakses:target-santri,buat');
        Route::post('/', [$t, 'store'])->name('store')->middleware('hakakses:target-santri,buat');
        Route::get('/{id}/edit', [$t, 'edit'])->name('edit')->middleware('hakakses:target-santri,ubah')->whereNumber('id');
        Route::put('/{id}', [$t, 'update'])->name('update')->middleware('hakakses:target-santri,ubah')->whereNumber('id');
        Route::delete('/{id}', [$t, 'destroy'])->name('destroy')->middleware('hakakses:target-santri,hapus')->whereNumber('id');
    });

    // Santri (calon = PPSB, aktif = Kesantrian; satu model, filter status).
    Route::controller(\App\Http\Controllers\SantriController::class)->group(function () {
        Route::get('/ppsb/calon-santri', 'index')->name('santri.calon')->defaults('lingkup', 'calon')->middleware('hakakses:santri,lihat');
        Route::get('/kesantrian/santri', 'index')->name('santri.aktif')->defaults('lingkup', 'aktif')->middleware('hakakses:santri,lihat');
        Route::get('/santri/create', 'create')->name('santri.create')->middleware('hakakses:santri,buat');
        Route::post('/santri', 'store')->name('santri.store')->middleware('hakakses:santri,buat');
        Route::get('/santri/{id}', 'show')->name('santri.show')->middleware('hakakses:santri,lihat')->whereNumber('id');
        // Sunting data santri. Yang PPSB (jalur, gelombang, tahun ajaran) &
        // status sengaja tak ikut — lihat SantriController::update().
        Route::get('/santri/{id}/edit', 'edit')->name('santri.edit')->middleware('hakakses:santri,ubah')->whereNumber('id');
        Route::put('/santri/{id}', 'update')->name('santri.update')->middleware('hakakses:santri,ubah')->whereNumber('id');
        Route::post('/santri/{id}/aksi/{aksi}', 'aksi')->name('santri.aksi')->middleware('hakakses:santri,ubah')->whereNumber('id');
    });

    // Berkas Santri (dari detail santri; tanpa menu sidebar sendiri).
    Route::controller(\App\Http\Controllers\DokumenSantriController::class)->group(function () {
        Route::get('/santri/{id}/dokumen', 'index')->name('dokumen_santri.index')->middleware('hakakses:dokumen-santri,lihat')->whereNumber('id');
        Route::post('/santri/{id}/dokumen', 'store')->name('dokumen_santri.store')->middleware('hakakses:dokumen-santri,buat')->whereNumber('id');
        Route::put('/santri/{id}/dokumen/wali-kelas', 'waliKelas')->name('dokumen_santri.wali_kelas')->middleware('hakakses:dokumen-santri,buat')->whereNumber('id');
        Route::delete('/dokumen-santri/{dokumen}', 'destroy')->name('dokumen_santri.destroy')->middleware('hakakses:dokumen-santri,hapus')->whereNumber('dokumen');
        Route::get('/dokumen-santri/{dokumen}/download', 'download')->name('dokumen_santri.download')->middleware('hakakses:dokumen-santri,lihat')->whereNumber('dokumen');
        Route::get('/dokumen-santri/{dokumen}/berkas', 'berkas')->name('dokumen_santri.berkas')->middleware('hakakses:dokumen-santri,lihat')->whereNumber('dokumen');
    });

    // Pembayaran Santri — dua modul (PPSB & Kesantrian) via controller bersama.
    foreach ([
        ['ppsb', '/ppsb/pembayaran', 'pembayaran_ppsb', 'pembayaran-ppsb'],
        ['kesantrian', '/kesantrian/pembayaran', 'pembayaran_kesantrian', 'pembayaran-kesantrian'],
    ] as [$lingkup, $prefix, $name, $kode]) {
        Route::prefix($prefix)->name($name . '.')->controller(\App\Http\Controllers\PembayaranSantriController::class)
            ->group(function () use ($kode, $lingkup) {
                Route::get('/', 'index')->name('index')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},lihat");
                Route::get('/create', 'create')->name('create')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},buat");
                Route::post('/', 'store')->name('store')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},buat");
                Route::post('/bayar-dompet', 'bayarDompet')->name('bayar_dompet')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},buat");
                Route::get('/{id}/bukti', 'bukti')->name('bukti')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},lihat")->whereNumber('id');
                Route::get('/{id}/kuitansi', 'kuitansi')->name('kuitansi')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},lihat")->whereNumber('id');
                Route::post('/{id}/verifikasi', 'verifikasi')->name('verifikasi')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},ubah")->whereNumber('id');
                Route::post('/{id}/tolak', 'tolak')->name('tolak')->defaults('lingkup', $lingkup)->middleware("hakakses:{$kode},hapus")->whereNumber('id');
            });
    }

    // Rekap Pembayaran Santri (riwayat tagihan + pembayaran per santri, + cetak).
    Route::prefix('rekap-pembayaran')->name('rekap_pembayaran.')->controller(\App\Http\Controllers\RekapPembayaranController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:rekap-pembayaran,lihat');
        Route::get('/{idSantri}/cetak', 'cetak')->name('cetak')->middleware('hakakses:rekap-pembayaran,lihat')->whereNumber('idSantri');
        Route::get('/{idSantri}', 'show')->name('show')->middleware('hakakses:rekap-pembayaran,lihat')->whereNumber('idSantri');
    });

    // Tagihan Lain-lain (tanpa menu sidebar sendiri — dari Pembayaran Kesantrian).
    Route::prefix('kesantrian/tagihan-lain')->name('tagihan_lain.')->controller(\App\Http\Controllers\TagihanLainController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:tagihan-lain,lihat');
        Route::get('/create', 'create')->name('create')->middleware('hakakses:tagihan-lain,buat');
        Route::post('/', 'store')->name('store')->middleware('hakakses:tagihan-lain,buat');
        Route::delete('/{id}', 'batalkan')->name('batalkan')->middleware('hakakses:tagihan-lain,hapus')->whereNumber('id');
    });

    // Dompet & Tabungan Santri (wadi'ah).
    Route::prefix('kesantrian/dompet')->name('dompet.')->controller(\App\Http\Controllers\DompetController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:dompet,lihat');
        Route::post('/topup', 'topUp')->name('topup')->middleware('hakakses:dompet,buat');
        Route::post('/topup-santri', 'topUpSantri')->name('topup_santri')->middleware('hakakses:dompet,buat');
        Route::post('/auto-debet', 'jalankanAutoDebet')->name('auto_debet')->middleware('hakakses:dompet,ubah');
        Route::post('/topup/{id}/verifikasi', 'verifikasiTopUp')->name('topup.verifikasi')->middleware('hakakses:dompet,ubah')->whereNumber('id');
        Route::post('/topup/{id}/tolak', 'tolakTopUp')->name('topup.tolak')->middleware('hakakses:dompet,hapus')->whereNumber('id');
        Route::post('/pindah', 'pindah')->name('pindah')->middleware('hakakses:dompet,ubah');
        Route::post('/kunci/{idSantri}', 'kunci')->name('kunci')->middleware('hakakses:dompet,ubah')->whereNumber('idSantri');
        Route::get('/mutasi/{mutasi}/bukti', 'bukti')->name('mutasi.bukti')->middleware('hakakses:dompet,lihat')->whereNumber('mutasi');
    });

    // SPP — tarif + generate tagihan per periode.
    Route::prefix('kesantrian/spp')->name('spp.')->controller(\App\Http\Controllers\SppController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:spp,lihat');
        Route::post('/generate', 'generate')->name('generate')->middleware('hakakses:spp,ubah');
        Route::put('/santri/{id}/nominal', 'setNominalKhusus')->name('nominal_khusus')->middleware('hakakses:spp,ubah')->whereNumber('id');
        Route::post('/prabayar', 'prabayar')->name('prabayar')->middleware('hakakses:spp,buat');
    });

    // Wali / Keluarga Santri.
    Route::prefix('wali')->name('wali.')->group(function () {
        $w = \App\Http\Controllers\WaliController::class;
        Route::get('/', [$w, 'index'])->name('index')->middleware('hakakses:wali,lihat');
        Route::get('/create', [$w, 'create'])->name('create')->middleware('hakakses:wali,buat');
        Route::post('/', [$w, 'store'])->name('store')->middleware('hakakses:wali,buat');
        Route::get('/{id}/edit', [$w, 'edit'])->name('edit')->middleware('hakakses:wali,ubah')->whereNumber('id');
        Route::put('/{id}', [$w, 'update'])->name('update')->middleware('hakakses:wali,ubah')->whereNumber('id');
        Route::delete('/{id}', [$w, 'destroy'])->name('destroy')->middleware('hakakses:wali,hapus')->whereNumber('id');
    });

    // ---- Laporan (read-only) ----
    Route::prefix('reports')->name('reports.')->middleware('hakakses:reports,lihat')->group(function () {
        $r = \App\Http\Controllers\ReportsController::class;
        Route::get('/', [$r, 'index'])->name('index');
        Route::get('/neraca', [$r, 'neraca'])->name('neraca');
        Route::get('/laba-rugi', [$r, 'labaRugi'])->name('laba_rugi');
        Route::get('/perubahan-modal', [$r, 'perubahanModal'])->name('perubahan_modal');
        Route::get('/arus-kas', [$r, 'arusKas'])->name('arus_kas');
        Route::get('/buku-besar', [$r, 'bukuBesar'])->name('buku_besar');
        Route::get('/aset', [$r, 'aset'])->name('aset');
        Route::get('/persediaan', [$r, 'persediaan'])->name('persediaan');
        Route::get('/jurnal', [$r, 'jurnalMentah'])->name('jurnal');
        Route::get('/export/{type}', [$r, 'download'])->name('export');
    });
});
