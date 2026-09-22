<?php

use App\Http\Controllers\AccrueController;
use App\Http\Controllers\AdvanceSettlementController;
use App\Http\Controllers\AngsuranUangPangkalController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BagianController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\BankLoanController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\BatchTagihanController;
use App\Http\Controllers\BebasTanggunganController;
use App\Http\Controllers\BookTransferController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\BudgetPengajuanController;
use App\Http\Controllers\BusinessUnitController;
use App\Http\Controllers\CashInController;
use App\Http\Controllers\CashOutController;
use App\Http\Controllers\CoaController;
use App\Http\Controllers\CoaDetailController;
use App\Http\Controllers\CoaGroupController;
use App\Http\Controllers\CompanySettingsController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DanaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DokumenSantriController;
use App\Http\Controllers\DompetController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GelombangController;
use App\Http\Controllers\HakAksesController;
use App\Http\Controllers\ImporDataAwalController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JalurPendaftaranController;
use App\Http\Controllers\JejakAuditController;
use App\Http\Controllers\JenisBiayaController;
use App\Http\Controllers\JenjangController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\KaryawanController;
use App\Http\Controllers\KebijakanKhususController;
use App\Http\Controllers\KenaikanTingkatController;
use App\Http\Controllers\KlasifikasiArusKasController;
use App\Http\Controllers\KepesertaanLainController;
use App\Http\Controllers\KontrolController;
use App\Http\Controllers\KoreksiTagihanController;
use App\Http\Controllers\LampiranController;
use App\Http\Controllers\LevelController;
use App\Http\Controllers\LevelPengajuanController;
use App\Http\Controllers\NisController;
use App\Http\Controllers\NotifikasiController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\OperationalAdvanceController;
use App\Http\Controllers\OutstandingLainController;
use App\Http\Controllers\OutstandingSppController;
use App\Http\Controllers\PembayaranSantriController;
use App\Http\Controllers\PendaftaranLanjutanController;
use App\Http\Controllers\PenerimaanKesantrianController;
use App\Http\Controllers\PengajuanController;
use App\Http\Controllers\PengajuanSaldoAwalController;
use App\Http\Controllers\PengaturanDanaBebasController;
use App\Http\Controllers\PengingatTerbitController;
use App\Http\Controllers\PerintahPembayaranController;
use App\Http\Controllers\PeriodCloseController;
use App\Http\Controllers\PinjamanKaryawanController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PushController;
use App\Http\Controllers\RekapPembayaranController;
use App\Http\Controllers\ReminderTagihanController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\SaldoDompetController;
use App\Http\Controllers\SantriController;
use App\Http\Controllers\SantriManualController;
use App\Http\Controllers\SetoranPemakaianController;
use App\Http\Controllers\SimpleMasterController;
use App\Http\Controllers\SppController;
use App\Http\Controllers\SumberInformasiController;
use App\Http\Controllers\TagihanLainController;
use App\Http\Controllers\TagihanMassalController;
use App\Http\Controllers\TahunAjaranController;
use App\Http\Controllers\TargetSantriController;
use App\Http\Controllers\TarifController;
use App\Http\Controllers\TerminFilterController;
use App\Http\Controllers\TipeBiayaController;
use App\Http\Controllers\TunggakanAwalController;
use App\Http\Controllers\UnitDefaultController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WaliController;
use App\Http\Middleware\RequireAdmin;
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

    // Level Pengajuan — jumlahnya kini bisa disesuaikan pesantren. `peringkat`
    // (PK) tetap tak bisa diubah: ia dirujuk users & approval_steps.
    Route::prefix('level-pengajuan')->name('level_pengajuan.')->group(function () {
        Route::get('/', [LevelPengajuanController::class, 'index'])->name('index')->middleware('hakakses:level-pengajuan,lihat');
        Route::get('/create', [LevelPengajuanController::class, 'create'])->name('create')->middleware('hakakses:level-pengajuan,buat');
        Route::post('/', [LevelPengajuanController::class, 'store'])->name('store')->middleware('hakakses:level-pengajuan,buat');
        Route::get('/{level_pengajuan}/edit', [LevelPengajuanController::class, 'edit'])->name('edit')->middleware('hakakses:level-pengajuan,ubah')->whereNumber('level_pengajuan');
        Route::put('/{level_pengajuan}', [LevelPengajuanController::class, 'update'])->name('update')->middleware('hakakses:level-pengajuan,ubah')->whereNumber('level_pengajuan');
        Route::delete('/{level_pengajuan}', [LevelPengajuanController::class, 'destroy'])->name('destroy')->middleware('hakakses:level-pengajuan,hapus')->whereNumber('level_pengajuan');
    });

    // Pengaturan Perusahaan — singleton, edit-only.
    Route::prefix('company-settings')->name('company_settings.')->group(function () {
        Route::get('/', [CompanySettingsController::class, 'edit'])->name('edit')->middleware('hakakses:company-settings,lihat');
        Route::put('/', [CompanySettingsController::class, 'update'])->name('update')->middleware('hakakses:company-settings,ubah');
    });

    // Master Jenjang — sumber tunggal daftar jenjang lintas modul.
    Route::prefix('jenjang')->name('jenjang.')->group(function () {
        $j = JenjangController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:jenjang,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:jenjang,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:jenjang,buat');
        Route::get('/{kode}/edit', [$j, 'edit'])->name('edit')->middleware('hakakses:jenjang,ubah');
        Route::put('/{kode}', [$j, 'update'])->name('update')->middleware('hakakses:jenjang,ubah');
        // Urutan tampil (seret baris / tombol naik-turun) — sebelum /{kode}
        // tak perlu, karena polanya POST sedangkan /{kode} memakai PUT & DELETE.
        Route::post('/urutan', [$j, 'urutan'])->name('urutan')->middleware('hakakses:jenjang,ubah');
        Route::delete('/{kode}', [$j, 'destroy'])->name('destroy')->middleware('hakakses:jenjang,hapus');
    });

    // Reminder Tagihan Jatuh Tempo — singleton setting + pratinjau + kirim manual.
    Route::prefix('reminder-tagihan')->name('reminder_tagihan.')->group(function () {
        Route::get('/', [ReminderTagihanController::class, 'index'])->name('index')->middleware('hakakses:reminder-tagihan,lihat');
        Route::put('/', [ReminderTagihanController::class, 'update'])->name('update')->middleware('hakakses:reminder-tagihan,ubah');
        Route::post('/kirim', [ReminderTagihanController::class, 'kirim'])->name('kirim')->middleware('hakakses:reminder-tagihan,ubah');
    });

    crudModul('users', UserController::class, 'user');

    // Profil sendiri — tanpa gerbang hak akses, tiap pengguna berhak mengganti
    // kata sandinya sendiri. Penggantian dibatasi agar tak bisa dijadikan
    // sarana menebak kata sandi lama.
    Route::get('/profil', [ProfilController::class, 'index'])->name('profil.index');
    Route::put('/profil/kata-sandi', [ProfilController::class, 'ubahKataSandi'])
        ->middleware('throttle:10,1')
        ->name('profil.kata_sandi');

    // Langganan push notification milik sendiri — alasannya sama dengan di atas:
    // memutuskan apakah ponsel sendiri berbunyi bukan kewenangan modul mana pun.
    // Keduanya memakai $request->user(), jadi tak ada jalan menyentuh perangkat
    // milik orang lain.
    Route::post('/profil/push', [PushController::class, 'langganan'])->name('profil.push.langganan');
    Route::delete('/profil/push', [PushController::class, 'berhenti'])->name('profil.push.berhenti');

    // Matriks hak akses per pengguna — KHUSUS ADMIN (di luar matriks modul).
    Route::middleware(RequireAdmin::class)->group(function () {
        // Jejak Audit: BACA SAJA, khusus admin. Tak ada rute simpan/ubah/hapus
        // — jejak yang bisa dihapus dari dalam aplikasi berhenti jadi jejak.
        Route::get('/jejak-audit', [JejakAuditController::class, 'index'])->name('jejak_audit.index');

        Route::get('/hak-akses', [HakAksesController::class, 'index'])->name('hak_akses.index');
        Route::get('/hak-akses/{user}', [HakAksesController::class, 'edit'])->name('hak_akses.edit');
        Route::put('/hak-akses/{user}', [HakAksesController::class, 'update'])->name('hak_akses.update');

        // Input Manual Santri Aktif — juga khusus admin, dan bukan kehati-hatian
        // berlebihan: santri lahir langsung AKTIF tanpa melewati PPSB, dan tiap
        // tagihannya memilih sendiri apakah menerbitkan jurnal. Dua wewenang yang
        // di alur biasa dipegang orang yang berbeda.
        $sm = SantriManualController::class;
        Route::get('/santri-manual', [$sm, 'create'])->name('santri_manual.create');
        Route::post('/santri-manual', [$sm, 'store'])->name('santri_manual.store');
        Route::delete('/santri-manual/{id}', [$sm, 'destroy'])->name('santri_manual.destroy')->whereNumber('id');
    });

    // ---- Keuangan: Kontrol / Master ----
    // Chart of Account terpadu (tab Struktur Pohon / Grup / Detail).
    Route::get('/coa', [CoaController::class, 'index'])->name('coa.index')->middleware('hakakses:coa-detail,lihat');
    // Matriks klasifikasi arus kas — seluruh akun neraca sekaligus. Menumpang
    // modul `coa-detail`: yang disunting memang kolom pada akun, dan modul baru
    // hanya menambah satu kotak lagi yang harus dicentangi admin lebih dulu.
    Route::get('/coa/klasifikasi-arus-kas', [KlasifikasiArusKasController::class, 'index'])
        ->name('coa.klasifikasi.index')->middleware('hakakses:coa-detail,lihat');
    Route::put('/coa/klasifikasi-arus-kas', [KlasifikasiArusKasController::class, 'simpan'])
        ->name('coa.klasifikasi.simpan')->middleware('hakakses:coa-detail,ubah');
    crudModul('coa-groups', CoaGroupController::class, 'coa_group');
    crudModul('coa-detail', CoaDetailController::class, 'coa_detail');
    crudModul('bank-accounts', BankAccountController::class, 'bank_account');
    crudModul('unit-default', UnitDefaultController::class, 'unit_default');

    // Master "jenis" sederhana (kode + nama + status) via controller generik.
    crudModul('vendor-types', SimpleMasterController::class, 'id');
    crudModul('customer-types', SimpleMasterController::class, 'id');
    crudModul('asset-categories', SimpleMasterController::class, 'id');

    // Aset Tetap (CRUD + depresiasi bulanan + pelepasan).
    Route::post('/assets/run-depreciation', [AssetController::class, 'runDepreciation'])->name('assets.run_depreciation')->middleware('hakakses:assets,ubah');

    // Pelepasan aset (jual / hibah / hapus). Memakai sumbu `hapus` pada modul
    // `assets`: inilah pintu yang menggantikan tombol Hapus untuk aset yang
    // nilainya sudah masuk buku besar, jadi haknya pun yang sama. Didaftarkan
    // SEBELUM crudModul supaya `/assets/pelepasan` tak tertangkap sebagai
    // `/assets/{asset}`.
    Route::get('/assets/pelepasan', [AssetController::class, 'pelepasanIndex'])->name('assets.pelepasan')->middleware('hakakses:assets,lihat');
    Route::post('/assets/pelepasan', [AssetController::class, 'lepas'])->name('assets.lepas')->middleware('hakakses:assets,hapus');
    Route::delete('/assets/pelepasan/{id}', [AssetController::class, 'voidPelepasan'])->name('assets.pelepasan_void')->middleware('hakakses:assets,hapus')->whereNumber('id');

    crudModul('assets', AssetController::class, 'asset');

    // Persediaan (CRUD + pemakaian/opname berjurnal + kartu stok).
    crudModul('inventory', InventoryController::class, 'inventory');
    Route::post('/inventory/{inventory}/mutasi', [InventoryController::class, 'mutasi'])->name('inventory.mutasi')->middleware('hakakses:inventory,ubah');
    Route::get('/inventory/{inventory}/kartu', [InventoryController::class, 'kartu'])->name('inventory.kartu')->middleware('hakakses:inventory,lihat');

    // Dana terikat (wakaf, donasi berperuntukan, beasiswa, bantuan).
    // Laporannya didaftarkan SEBELUM crudModul supaya `/dana/laporan` tak
    // tertangkap sebagai `/dana/{kode}`.
    Route::get('/dana/laporan', [DanaController::class, 'laporan'])
        ->name('dana.laporan')->middleware('hakakses:dana,lihat');
    crudModul('dana', DanaController::class, 'kode');

    crudModul('vendors', VendorController::class, 'vendor');
    crudModul('customers', CustomerController::class, 'customer');

    // ---- Saldo Awal (jurnal pembuka) ----
    Route::prefix('opening-balance')->name('opening_balance.')->group(function () {
        $o = OpeningBalanceController::class;
        Route::get('/', [$o, 'index'])->name('index')->middleware('hakakses:opening-balance,lihat');
        Route::post('/lines', [$o, 'addLine'])->name('add')->middleware('hakakses:opening-balance,ubah');
        Route::delete('/lines/{id}', [$o, 'removeLine'])->name('remove')->middleware('hakakses:opening-balance,ubah')->whereNumber('id');
        Route::post('/post', [$o, 'post'])->name('post')->middleware('hakakses:opening-balance,ubah');
        Route::post('/void', [$o, 'void'])->name('void')->middleware('hakakses:opening-balance,ubah');
    });

    // ---- Tutup Buku Periode ----
    Route::prefix('period-close')->name('period_close.')->group(function () {
        $pc = PeriodCloseController::class;
        Route::get('/', [$pc, 'index'])->name('index')->middleware('hakakses:period-close,lihat');
        Route::post('/tutup-bulan', [$pc, 'tutupBulan'])->name('tutup_bulan')->middleware('hakakses:period-close,ubah');
        Route::post('/tutup-tahun', [$pc, 'tutupTahun'])->name('tutup_tahun')->middleware('hakakses:period-close,ubah');

        // Membuka kembali periode: DUA TANGAN. Rute `buka_bulan`/`buka_tahun`
        // yang dulu langsung bekerja sengaja dihapus, bukan disembunyikan —
        // rute yang masih hidup tapi tombolnya hilang bukanlah kontrol.
        Route::post('/ajukan-buka', [$pc, 'ajukanBuka'])->name('ajukan_buka')->middleware('hakakses:buka-periode,buat');
        Route::post('/permohonan/{id}/setujui', [$pc, 'setujuiBuka'])->name('setujui_buka')->middleware('hakakses:buka-periode,ubah')->whereNumber('id');
        Route::post('/permohonan/{id}/tolak', [$pc, 'tolakBuka'])->name('tolak_buka')->middleware('hakakses:buka-periode,ubah')->whereNumber('id');
    });

    // ---- Export Data (CSV / Excel / PDF) ----
    Route::prefix('export')->name('export.')->group(function () {
        $ex = ExportController::class;
        Route::get('/', [$ex, 'index'])->name('index');
        Route::get('/jurnal-mentah', [$ex, 'jurnalMentah'])->name('jurnal_mentah');
        Route::get('/buku-besar', [$ex, 'bukuBesar'])->name('buku_besar');
        Route::get('/aset', [$ex, 'aset'])->name('aset');
        Route::get('/dataset/{key}', [$ex, 'dataset'])->name('dataset');
    });

    // ---- Kontrol Outstanding (read-only lintas modul) ----
    Route::prefix('kontrol')->name('kontrol.')->group(function () {
        $k = KontrolController::class;
        Route::get('/ringkasan', [$k, 'ringkasan'])->name('ringkasan');
        Route::get('/aging-ap', [$k, 'agingAp'])->name('aging_ap');
        Route::get('/uang-muka-customer', [$k, 'uangMukaCustomer'])->name('uang_muka_customer');
        Route::get('/uang-muka-operasional', [$k, 'uangMukaOperasional'])->name('uang_muka_operasional');
        Route::get('/accrue-prepaid', [$k, 'accruePrepaid'])->name('accrue_prepaid');
        Route::get('/rekonsiliasi', [$k, 'rekonsiliasi'])->name('rekonsiliasi');
        Route::get('/rekap-pembiayaan', [$k, 'rekapPembiayaan'])->name('rekap_pembiayaan');
        Route::get('/export/{type}', [$k, 'download'])->name('download');
    });

    // ---- Karyawan & Pinjaman Karyawan ----
    // Master karyawan ringkas; kelak diambil alih HRD.
    Route::prefix('karyawan')->name('karyawan.')->group(function () {
        $k = KaryawanController::class;
        Route::get('/', [$k, 'index'])->name('index')->middleware('hakakses:karyawan,lihat');
        Route::get('/create', [$k, 'create'])->name('create')->middleware('hakakses:karyawan,buat');
        Route::post('/', [$k, 'store'])->name('store')->middleware('hakakses:karyawan,buat');
        Route::get('/{kode}/edit', [$k, 'edit'])->name('edit')->middleware('hakakses:karyawan,ubah');
        Route::put('/{kode}', [$k, 'update'])->name('update')->middleware('hakakses:karyawan,ubah');
        Route::delete('/{kode}', [$k, 'destroy'])->name('destroy')->middleware('hakakses:karyawan,hapus');
    });

    Route::prefix('pinjaman-karyawan')->name('pinjaman_karyawan.')->group(function () {
        $p = PinjamanKaryawanController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:pinjaman-karyawan,lihat');
        Route::get('/buat', [$p, 'create'])->name('create')->middleware('hakakses:pinjaman-karyawan,buat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:pinjaman-karyawan,buat');
        Route::get('/{id}', [$p, 'show'])->name('show')->middleware('hakakses:pinjaman-karyawan,lihat')->whereNumber('id');
        // Mencatat cicilan = mengubah pinjaman, bukan membuat dokumen baru.
        Route::post('/{id}/bayar', [$p, 'bayar'])->name('bayar')->middleware('hakakses:pinjaman-karyawan,ubah')->whereNumber('id');
        Route::post('/{id}/termin', [$p, 'aturTermin'])->name('termin')->middleware('hakakses:pinjaman-karyawan,ubah')->whereNumber('id');
    });

    // ---- Pengajuan Belum Dibayar (saldo awal, pintu manual) ----
    // Menumpang hak `impor-data-awal`, BUKAN `pengajuan-pembayaran`: dokumen yang
    // dilahirkannya berstatus `diposting` tanpa melewati rantai persetujuan, dan
    // langsung bisa dicairkan Kas Keluar. Itu wewenang pemindah sistem, bukan
    // wewenang setiap orang yang boleh mengajukan pembayaran.
    Route::prefix('pengajuan-saldo-awal')->name('pengajuan_saldo_awal.')->group(function () {
        $psa = PengajuanSaldoAwalController::class;
        Route::get('/', [$psa, 'index'])->name('index')->middleware('hakakses:impor-data-awal,lihat');
        Route::post('/', [$psa, 'store'])->name('store')->middleware('hakakses:impor-data-awal,buat');
        Route::delete('/{id}', [$psa, 'destroy'])->name('destroy')->middleware('hakakses:impor-data-awal,buat')->whereNumber('id');
    });

    // ---- Impor Data Awal (alat pindahan sistem) ----
    // Menulis dokumen TANPA jurnal; saldonya masuk lewat menu Saldo Awal.
    // 'lihat' cukup untuk melihat & memeriksa berkas; menulis butuh 'buat'.
    Route::prefix('impor-data-awal')->name('impor_data_awal.')->group(function () {
        $i = ImporDataAwalController::class;
        Route::get('/', [$i, 'index'])->name('index')->middleware('hakakses:impor-data-awal,lihat');
        Route::get('/template/{jenis}', [$i, 'template'])->name('template')->middleware('hakakses:impor-data-awal,lihat');
        Route::post('/pratinjau', [$i, 'pratinjau'])->name('pratinjau')->middleware('hakakses:impor-data-awal,buat');
        Route::post('/jalankan', [$i, 'jalankan'])->name('jalankan')->middleware('hakakses:impor-data-awal,buat');
        // Membatalkan impor MENGHAPUS ratusan baris sekaligus — haknya `hapus`,
        // bukan `buat` seperti menjalankan impornya.
        Route::delete('/batch/{id}', [$i, 'batalkanBatch'])->name('batalkan_batch')
            ->middleware('hakakses:impor-data-awal,hapus')->whereNumber('id');
    });

    // ---- Anggaran (Input & Realisasi) ----
    Route::prefix('budget')->name('budget.')->group(function () {
        $b = BudgetController::class;
        // Realisasi bebas matriks — gerbang bertingkat di service (admin |
        // Yayasan | Direktorat subtree | Mudir Bagian/Staff bagian sendiri).
        Route::get('/realisasi', [$b, 'realisasi'])->name('realisasi');
        // Input Anggaran — lihat lewat matriks modul 'budget'.
        Route::get('/', [$b, 'index'])->name('index')->middleware('hakakses:budget,lihat');

        // Pengajuan Anggaran (§3.c) — jalur non-admin lewat rantai BUDGET-STD.
        // Digerbangi hak modul 'budget' (sama seperti app lama): 'lihat' untuk
        // melihat status, 'buat' untuk mengajukan & membatalkan miliknya.
        // Diletakkan SEBELUM PUT '/' yang admin-only agar tak ikut tergerbang.
        $bp = BudgetPengajuanController::class;
        Route::get('/pengajuan', [$bp, 'index'])->name('pengajuan.index')->middleware('hakakses:budget,lihat');
        Route::get('/pengajuan/buat', [$bp, 'create'])->name('pengajuan.create')->middleware('hakakses:budget,buat');
        Route::post('/pengajuan', [$bp, 'store'])->name('pengajuan.store')->middleware('hakakses:budget,buat');
        Route::get('/pengajuan/{id}', [$bp, 'show'])->name('pengajuan.show')->middleware('hakakses:budget,lihat')->whereNumber('id');
        // "batal" (bukan "void") agar tergerbang aksi BUAT, bukan hapus — pemohon
        // yang boleh membuat boleh membatalkan miliknya. Kepemilikan tetap
        // ditegakkan di service.
        Route::post('/pengajuan/{id}/batal', [$bp, 'batal'])->name('pengajuan.batal')->middleware('hakakses:budget,buat')->whereNumber('id');
        // Tulis anggaran langsung + kunci/buka = KHUSUS ADMIN (jalur darurat).
        Route::put('/', [$b, 'save'])->name('save')->middleware(RequireAdmin::class);
        Route::post('/lock', [$b, 'lock'])->name('lock')->middleware(RequireAdmin::class);
        Route::delete('/lock/{tahun}', [$b, 'unlock'])->name('unlock')->middleware(RequireAdmin::class)->whereNumber('tahun');
    });

    // ---- Pengajuan Pembayaran (§4) ----
    Route::prefix('pengajuan-pembayaran')->name('pengajuan.')->group(function () {
        $p = PengajuanController::class;
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

    // ---- Lampiran dokumen keuangan ----
    // SATU layar untuk semua sumber yang boleh dilampiri (lihat SumberLampiran).
    // TANPA middleware `hakakses:` — modul penggerbangnya ditentukan `{jenis}`
    // di URL, jadi pemeriksaannya di controller (Akses::boleh). Rute berkas &
    // unduh sengaja TIDAK menyebut jenis: nomor lampirannya yang menentukan,
    // dan haknya diperiksa dari baris lampiran itu sendiri.
    Route::prefix('lampiran')->name('lampiran.')->group(function () {
        $l = LampiranController::class;
        Route::get('/berkas/{lampiran}', [$l, 'berkas'])->name('berkas')->whereNumber('lampiran');
        Route::get('/unduh/{lampiran}', [$l, 'unduh'])->name('unduh')->whereNumber('lampiran');
        Route::delete('/{lampiran}', [$l, 'destroy'])->name('destroy')->whereNumber('lampiran');
        Route::get('/{jenis}/{id}', [$l, 'index'])->name('index');
        Route::post('/{jenis}/{id}', [$l, 'store'])->name('store');
    });

    // Persetujuan Saya (approval inbox) — di luar matriks modul (wewenang dari
    // peringkat/fungsi), hanya wajib login.
    Route::prefix('approvals')->name('approvals.')->group(function () {
        $a = ApprovalController::class;
        Route::get('/', [$a, 'inbox'])->name('inbox');
        Route::post('/{id}/approve', [$a, 'approve'])->name('approve')->whereNumber('id');
        Route::post('/{id}/reject', [$a, 'reject'])->name('reject')->whereNumber('id');
    });

    // Master Tipe Biaya (Setting Awal) — perilaku tiap tipe menentukan alurnya.
    Route::prefix('tipe-biaya')->name('tipe_biaya.')->group(function () {
        $t = TipeBiayaController::class;
        Route::get('/', [$t, 'index'])->name('index')->middleware('hakakses:tipe-biaya,lihat');
        Route::get('/create', [$t, 'create'])->name('create')->middleware('hakakses:tipe-biaya,buat');
        Route::post('/', [$t, 'store'])->name('store')->middleware('hakakses:tipe-biaya,buat');
        Route::get('/{kode}/edit', [$t, 'edit'])->name('edit')->middleware('hakakses:tipe-biaya,ubah');
        Route::put('/{kode}', [$t, 'update'])->name('update')->middleware('hakakses:tipe-biaya,ubah');
        Route::post('/urutan', [$t, 'urutan'])->name('urutan')->middleware('hakakses:tipe-biaya,ubah');
        Route::delete('/{kode}', [$t, 'destroy'])->name('destroy')->middleware('hakakses:tipe-biaya,hapus');
    });

    // Master Sumber Informasi (PPSB → Setting Awal).
    Route::prefix('ppsb/sumber-informasi')->name('sumber_informasi.')->group(function () {
        $s = SumberInformasiController::class;
        Route::get('/', [$s, 'index'])->name('index')->middleware('hakakses:sumber-informasi,lihat');
        Route::get('/create', [$s, 'create'])->name('create')->middleware('hakakses:sumber-informasi,buat');
        Route::post('/', [$s, 'store'])->name('store')->middleware('hakakses:sumber-informasi,buat');
        Route::get('/{kode}/edit', [$s, 'edit'])->name('edit')->middleware('hakakses:sumber-informasi,ubah');
        Route::put('/{kode}', [$s, 'update'])->name('update')->middleware('hakakses:sumber-informasi,ubah');
        Route::post('/urutan', [$s, 'urutan'])->name('urutan')->middleware('hakakses:sumber-informasi,ubah');
        Route::delete('/{kode}', [$s, 'destroy'])->name('destroy')->middleware('hakakses:sumber-informasi,hapus');
    });

    // Notifikasi pribadi — seperti approval inbox, di luar matriks modul: tiap
    // pengguna hanya melihat barisnya sendiri.
    Route::prefix('notifikasi')->name('notifikasi.')->group(function () {
        $n = NotifikasiController::class;
        Route::get('/', [$n, 'index'])->name('index');
        Route::post('/baca-semua', [$n, 'bacaSemua'])->name('baca_semua');
        Route::post('/{id}/baca', [$n, 'baca'])->name('baca')->whereNumber('id');
    });

    // ---- Keuangan: Transaksi ----
    // Kas Keluar (Debit rincian; Kredit Kas/Bank) — jenis "lainnya".
    Route::prefix('cash-out')->name('cash_out.')->group(function () {
        $c = CashOutController::class;
        Route::get('/', [$c, 'index'])->name('index')->middleware('hakakses:cash-out,lihat');
        Route::get('/create', [$c, 'create'])->name('create')->middleware('hakakses:cash-out,buat');
        Route::post('/', [$c, 'store'])->name('store')->middleware('hakakses:cash-out,buat');
        Route::get('/{cash_out}', [$c, 'show'])->name('show')->middleware('hakakses:cash-out,lihat');
        // Bukti siap tanda tangan. Haknya `lihat` — mencetak tidak mengubah apa pun,
        // dan yang perlu bukti fisik justru sering hanya berhak melihat.
        Route::get('/{cash_out}/print', [$c, 'print'])->name('print')->middleware('hakakses:cash-out,lihat');
        Route::delete('/{cash_out}', [$c, 'void'])->name('void')->middleware('hakakses:cash-out,hapus');
    });

    // Perintah Pembayaran — dokumen KAS (tidak menjurnal). Menyusun memakai hak
    // `perintah-pembayaran`; OTORISASI & PENUTUPAN memakai modul terpisah
    // `otorisasi-pembayaran`, supaya empat mata bisa ditegakkan lewat pemberian
    // hak, bukan sekadar kesepakatan lisan.
    Route::prefix('perintah-pembayaran')->name('perintah_pembayaran.')->group(function () {
        $p = PerintahPembayaranController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:perintah-pembayaran,lihat');
        Route::get('/create', [$p, 'create'])->name('create')->middleware('hakakses:perintah-pembayaran,buat');
        // Didaftarkan SEBELUM /{id} — kalau tidak, "kepatuhan" akan ditelan
        // parameter id (yang whereNumber-nya justru menolaknya → 404).
        Route::get('/kepatuhan', [$p, 'kepatuhan'])->name('kepatuhan')->middleware('hakakses:perintah-pembayaran,lihat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:perintah-pembayaran,buat');
        Route::get('/{id}', [$p, 'show'])->name('show')->middleware('hakakses:perintah-pembayaran,lihat')->whereNumber('id');
        Route::get('/{id}/print', [$p, 'print'])->name('print')->middleware('hakakses:perintah-pembayaran,lihat')->whereNumber('id');
        Route::post('/{id}/ajukan', [$p, 'ajukan'])->name('ajukan')->middleware('hakakses:perintah-pembayaran,ubah')->whereNumber('id');
        Route::post('/{id}/otorisasi', [$p, 'otorisasi'])->name('otorisasi')->middleware('hakakses:otorisasi-pembayaran,ubah')->whereNumber('id');
        Route::post('/{id}/tolak', [$p, 'tolak'])->name('tolak')->middleware('hakakses:otorisasi-pembayaran,ubah')->whereNumber('id');
        // Penutupan membatalkan pembayaran yang sudah DIOTORISASI pejabat, jadi
        // haknya tak boleh sembarangan — TAPI juga tak boleh menumpuk di satu
        // orang. Yang berhak: pejabat pengotorisasi ATAU staf keuangan yang
        // mengeksekusi pembayaran (hak Kas Keluar), karena dialah yang tahu
        // kapan sebuah perintah sudah tuntas dijalankan. `hakakses` hanya tahu
        // satu modul per rute, jadi pilihannya ditegakkan di controller.
        Route::post('/{id}/tutup', [$p, 'tutup'])->name('tutup')->middleware('hakakses:perintah-pembayaran,lihat')->whereNumber('id');
    });

    Route::prefix('pengaturan/dana-bebas')->name('pengaturan_dana_bebas.')->group(function () {
        $d = PengaturanDanaBebasController::class;
        Route::get('/', [$d, 'index'])->name('index')->middleware('hakakses:pengaturan-dana-bebas,lihat');
        Route::put('/', [$d, 'update'])->name('update')->middleware('hakakses:pengaturan-dana-bebas,ubah');
    });

    // Kas Masuk (Debit Kas/Bank; Kredit rincian).
    Route::prefix('cash-in')->name('cash_in.')->group(function () {
        $c = CashInController::class;
        Route::get('/', [$c, 'index'])->name('index')->middleware('hakakses:cash-in,lihat');
        Route::get('/create', [$c, 'create'])->name('create')->middleware('hakakses:cash-in,buat');
        Route::post('/', [$c, 'store'])->name('store')->middleware('hakakses:cash-in,buat');
        Route::post('/{cash_in}/akui', [$c, 'akui'])->name('akui')->middleware('hakakses:cash-in,buat');
        Route::get('/{cash_in}', [$c, 'show'])->name('show')->middleware('hakakses:cash-in,lihat');
        Route::get('/{cash_in}/print', [$c, 'print'])->name('print')->middleware('hakakses:cash-in,lihat');
        Route::delete('/{cash_in}', [$c, 'void'])->name('void')->middleware('hakakses:cash-in,hapus');
    });

    // Rekonsiliasi Bank (workflow: draft → cleared/penyesuaian → finalize).
    Route::prefix('bank-reconciliation')->name('bank_reconciliation.')->group(function () {
        $b = BankReconciliationController::class;
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
        $p = PurchaseOrderController::class;
        Route::get('/', [$p, 'index'])->name('index')->middleware('hakakses:purchase-orders,lihat');
        Route::get('/create', [$p, 'create'])->name('create')->middleware('hakakses:purchase-orders,buat');
        Route::post('/', [$p, 'store'])->name('store')->middleware('hakakses:purchase-orders,buat');
        Route::get('/{purchase_order}/print', [$p, 'print'])->name('print')->middleware('hakakses:purchase-orders,lihat');
        Route::get('/{purchase_order}', [$p, 'show'])->name('show')->middleware('hakakses:purchase-orders,lihat');
        Route::delete('/{purchase_order}', [$p, 'cancel'])->name('cancel')->middleware('hakakses:purchase-orders,hapus');
    });

    // Invoice Vendor (Debit rincian; Kredit hutang usaha).
    Route::prefix('invoices')->name('invoices.')->group(function () {
        $i = InvoiceController::class;
        Route::get('/', [$i, 'index'])->name('index')->middleware('hakakses:invoices,lihat');
        Route::get('/create', [$i, 'create'])->name('create')->middleware('hakakses:invoices,buat');
        Route::post('/', [$i, 'store'])->name('store')->middleware('hakakses:invoices,buat');
        Route::get('/{invoice}', [$i, 'show'])->name('show')->middleware('hakakses:invoices,lihat');
        Route::delete('/{invoice}', [$i, 'void'])->name('void')->middleware('hakakses:invoices,hapus');
    });

    // Penyelesaian Uang Muka (Kredit UM; Debit realisasi; selisih via kas).
    Route::prefix('advance-settlement')->name('advance_settlement.')->group(function () {
        $s = AdvanceSettlementController::class;
        Route::get('/', [$s, 'index'])->name('index')->middleware('hakakses:advance-settlement,lihat');
        Route::get('/create', [$s, 'create'])->name('create')->middleware('hakakses:advance-settlement,buat');
        Route::post('/', [$s, 'store'])->name('store')->middleware('hakakses:advance-settlement,buat');
    });

    // Uang Muka Operasional (Debit akun uang muka; Kredit kas/bank).
    Route::prefix('operational-advance')->name('operational_advance.')->group(function () {
        $u = OperationalAdvanceController::class;
        Route::get('/', [$u, 'index'])->name('index')->middleware('hakakses:operational-advance,lihat');
        Route::get('/create', [$u, 'create'])->name('create')->middleware('hakakses:operational-advance,buat');
        Route::post('/', [$u, 'store'])->name('store')->middleware('hakakses:operational-advance,buat');
        Route::delete('/{operational_advance}', [$u, 'void'])->name('void')->middleware('hakakses:operational-advance,hapus');
    });

    // Accrue & Prepaid (jurnal penyesuaian) + reversal awal bulan.
    Route::prefix('accrue')->name('accrue.')->group(function () {
        $a = AccrueController::class;
        Route::get('/', [$a, 'index'])->name('index')->middleware('hakakses:accrue,lihat');
        Route::get('/create', [$a, 'create'])->name('create')->middleware('hakakses:accrue,buat');
        Route::post('/', [$a, 'store'])->name('store')->middleware('hakakses:accrue,buat');
        Route::post('/run-reversal', [$a, 'runReversal'])->name('run_reversal')->middleware('hakakses:accrue,buat');
    });

    // Pembiayaan Bank (syariah) — pencairan Debit Kas/Bank; Kredit hutang.
    Route::prefix('bank-loans')->name('bank_loans.')->group(function () {
        $l = BankLoanController::class;
        Route::get('/', [$l, 'index'])->name('index')->middleware('hakakses:bank-loans,lihat');
        Route::get('/create', [$l, 'create'])->name('create')->middleware('hakakses:bank-loans,buat');
        Route::post('/', [$l, 'store'])->name('store')->middleware('hakakses:bank-loans,buat');
        Route::get('/{bank_loan}', [$l, 'show'])->name('show')->middleware('hakakses:bank-loans,lihat');
        Route::delete('/{bank_loan}', [$l, 'void'])->name('void')->middleware('hakakses:bank-loans,hapus');
    });

    // Pindah Buku (Debit rekening tujuan; Kredit rekening asal).
    Route::prefix('book-transfer')->name('book_transfer.')->group(function () {
        $b = BookTransferController::class;
        Route::get('/', [$b, 'index'])->name('index')->middleware('hakakses:book-transfer,lihat');
        Route::get('/create', [$b, 'create'])->name('create')->middleware('hakakses:book-transfer,buat');
        Route::post('/', [$b, 'store'])->name('store')->middleware('hakakses:book-transfer,buat');
        Route::delete('/{id}', [$b, 'void'])->name('void')->middleware('hakakses:book-transfer,hapus');
    });

    // Jurnal Umum (template modul transaksi: controller tipis → service).
    Route::prefix('journal')->name('journal.')->group(function () {
        $j = JournalController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:journal,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:journal,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:journal,buat');
        Route::get('/{journal}', [$j, 'show'])->name('show')->middleware('hakakses:journal,lihat');
        Route::delete('/{journal}', [$j, 'void'])->name('void')->middleware('hakakses:journal,hapus');
    });

    // ---- PPSB & Kesantrian ----
    // Tahun Ajaran (master, CRUD) — rujukan master PPSB lain & registrasi.
    Route::prefix('ppsb/tahun-ajaran')->name('tahun_ajaran.')->group(function () {
        $t = TahunAjaranController::class;
        Route::get('/', [$t, 'index'])->name('index')->middleware('hakakses:tahun-ajaran,lihat');
        Route::get('/create', [$t, 'create'])->name('create')->middleware('hakakses:tahun-ajaran,buat');
        Route::post('/', [$t, 'store'])->name('store')->middleware('hakakses:tahun-ajaran,buat');
        Route::get('/{id}/edit', [$t, 'edit'])->name('edit')->middleware('hakakses:tahun-ajaran,ubah')->whereNumber('id');
        Route::put('/{id}', [$t, 'update'])->name('update')->middleware('hakakses:tahun-ajaran,ubah')->whereNumber('id');
        Route::delete('/{id}', [$t, 'destroy'])->name('destroy')->middleware('hakakses:tahun-ajaran,hapus')->whereNumber('id');
    });

    // Jenis Biaya (registrasi/uang pangkal/SPP/lain).
    Route::prefix('ppsb/jenis-biaya')->name('jenis_biaya.')->group(function () {
        $j = JenisBiayaController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:jenis-biaya,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:jenis-biaya,buat');
        // Mesin duplikat-ke-T.A-baru DIBUANG: jenis biaya tak lagi memuat tarif,
        // jadi barisnya berlaku lintas tahun & tak perlu disalin. Yang punya
        // tombol salin sekarang adalah menu Tarif.
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:jenis-biaya,buat');
        Route::get('/{kode}/edit', [$j, 'edit'])->name('edit')->middleware('hakakses:jenis-biaya,ubah');
        Route::put('/{kode}', [$j, 'update'])->name('update')->middleware('hakakses:jenis-biaya,ubah');
        Route::delete('/{kode}', [$j, 'destroy'])->name('destroy')->middleware('hakakses:jenis-biaya,hapus');
    });

    // Tarif — grid besaran biaya per (T.A × jenjang × jalur).
    Route::prefix('tarif')->name('tarif.')->controller(TarifController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:tarif,lihat');
        Route::put('/', 'simpan')->name('simpan')->middleware('hakakses:tarif,ubah');
        // Menonaktifkan jalur membuang sel tarif yang mungkin sudah diisi → hak UBAH.
        Route::post('/jalur', 'nonaktifkanJalur')->name('jalur')->middleware('hakakses:tarif,ubah');
        // Menyalin = menciptakan sel baru di T.A tujuan → hak BUAT.
        Route::post('/salin', 'salin')->name('salin')->middleware('hakakses:tarif,buat');
    });

    // Kenaikan Tingkat & Kelulusan massal (dalam satu jenjang). Naik JENJANG
    // punya jalurnya sendiri lewat Pendaftaran Lanjutan di halaman santri.
    Route::prefix('kesantrian/kenaikan-tingkat')->name('kenaikan_tingkat.')->controller(KenaikanTingkatController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:kenaikan-tingkat,lihat');
        Route::post('/pratinjau', 'pratinjau')->name('pratinjau')->middleware('hakakses:kenaikan-tingkat,lihat');
        // Namanya `tetapkan`, bukan `eksekusi`: yang terjadi adalah PENJADWALAN.
        // Perubahannya menyala saat tahun ajaran tujuan benar-benar dimulai.
        Route::post('/tetapkan', 'tetapkan')->name('tetapkan')->middleware('hakakses:kenaikan-tingkat,buat');
    });

    // Terbitkan Tagihan Massal — daftar ulang santri aktif, jadi KEPENDIDIKAN,
    // bukan PPSB (PPSB tidak punya penerbitan massal).
    Route::prefix('kesantrian/tagihan-massal')->name('tagihan_massal.')->controller(TagihanMassalController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:tagihan-massal,lihat');
        Route::post('/pratinjau', 'pratinjau')->name('pratinjau')->middleware('hakakses:tagihan-massal,lihat');
        Route::post('/terbitkan', 'terbitkan')->name('terbitkan')->middleware('hakakses:tagihan-massal,buat');
    });

    // Batch Tagihan — penerbitan yang disusun & diotorisasi lebih dulu, lalu
    // dirilis kemudian. Berdiri di atas ketiga modul penerbit (SPP, daftar ulang,
    // tagihan lain), jadi haknya BERLAPIS: `batch-tagihan` membuka layarnya,
    // sedangkan hak modul aslinya tetap dituntut di controller. Tanpa lapis itu,
    // satu hak baru diam-diam memberi kuasa menerbitkan SPP seluruh pesantren.
    Route::prefix('kesantrian/batch-tagihan')->name('batch_tagihan.')->controller(BatchTagihanController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:batch-tagihan,lihat');
        Route::get('/susun', 'susun')->name('susun')->middleware('hakakses:batch-tagihan,buat');
        Route::post('/', 'store')->name('store')->middleware('hakakses:batch-tagihan,buat');
        Route::get('/{id}', 'show')->name('show')->middleware('hakakses:batch-tagihan,lihat')->whereNumber('id');
        Route::post('/{id}/otorisasi', 'otorisasi')->name('otorisasi')->middleware('hakakses:batch-tagihan,ubah')->whereNumber('id');
        Route::post('/{id}/rilis', 'rilis')->name('rilis')->middleware('hakakses:batch-tagihan,ubah')->whereNumber('id');
        Route::delete('/{id}', 'batalkan')->name('batalkan')->middleware('hakakses:batch-tagihan,hapus')->whereNumber('id');
    });

    // Jadwal pengingat penerbitan. MENUMPANG hak `batch-tagihan` — menyetel
    // pengingat adalah bagian dari mengurus batch, dan tiap modul hak baru
    // menambah satu baris yang harus dicentang manual di produksi.
    Route::prefix('kesantrian/pengingat-terbit')->name('pengingat_terbit.')->controller(PengingatTerbitController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:batch-tagihan,lihat');
        Route::post('/', 'store')->name('store')->middleware('hakakses:batch-tagihan,ubah');
        Route::post('/konfirmasi', 'konfirmasi')->name('konfirmasi')->middleware('hakakses:batch-tagihan,ubah');
        Route::put('/{id}', 'update')->name('update')->middleware('hakakses:batch-tagihan,ubah')->whereNumber('id');
        Route::delete('/{id}', 'destroy')->name('destroy')->middleware('hakakses:batch-tagihan,hapus')->whereNumber('id');
    });

    // Gelombang: master (identitas & waktu) + MATRIKS potongannya. Keduanya
    // digerbangi modul yang sama — memisahkan haknya hanya akan melahirkan
    // keadaan aneh: boleh mengisi potongan tapi tak boleh melihat gelombangnya.
    Route::prefix('ppsb/gelombang')->name('gelombang.')->group(function () {
        $g = GelombangController::class;
        // Matriks didaftarkan SEBELUM /{id} — kalau tidak, "potongan" akan
        // ditelan parameter id (yang whereNumber-nya justru menolaknya → 404).
        Route::get('/potongan', [$g, 'potongan'])->name('potongan')->middleware('hakakses:potongan-gelombang,lihat');
        Route::put('/potongan', [$g, 'simpanPotongan'])->name('potongan.simpan')->middleware('hakakses:potongan-gelombang,ubah');
        Route::get('/', [$g, 'index'])->name('index')->middleware('hakakses:potongan-gelombang,lihat');
        Route::get('/create', [$g, 'create'])->name('create')->middleware('hakakses:potongan-gelombang,buat');
        Route::post('/', [$g, 'store'])->name('store')->middleware('hakakses:potongan-gelombang,buat');
        Route::get('/{id}/edit', [$g, 'edit'])->name('edit')->middleware('hakakses:potongan-gelombang,ubah')->whereNumber('id');
        Route::put('/{id}', [$g, 'update'])->name('update')->middleware('hakakses:potongan-gelombang,ubah')->whereNumber('id');
        Route::delete('/{id}', [$g, 'destroy'])->name('destroy')->middleware('hakakses:potongan-gelombang,hapus')->whereNumber('id');
    });

    // Angsuran Uang Pangkal (rencana termin + reminder).
    Route::prefix('ppsb/angsuran-uang-pangkal')->name('angsuran_uang_pangkal.')->controller(AngsuranUangPangkalController::class)->group(function () {
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
        $t = TerminFilterController::class;
        Route::get('/', [$t, 'edit'])->name('edit')->middleware('hakakses:termin-filter,lihat');
        Route::put('/', [$t, 'update'])->name('update')->middleware('hakakses:termin-filter,ubah');
    });

    // Jalur Pendaftaran (master, CRUD).
    Route::prefix('ppsb/jalur-pendaftaran')->name('jalur_pendaftaran.')->group(function () {
        $j = JalurPendaftaranController::class;
        Route::get('/', [$j, 'index'])->name('index')->middleware('hakakses:jalur-pendaftaran,lihat');
        Route::get('/create', [$j, 'create'])->name('create')->middleware('hakakses:jalur-pendaftaran,buat');
        Route::post('/', [$j, 'store'])->name('store')->middleware('hakakses:jalur-pendaftaran,buat');
        Route::get('/{kode}/edit', [$j, 'edit'])->name('edit')->middleware('hakakses:jalur-pendaftaran,ubah');
        Route::put('/{kode}', [$j, 'update'])->name('update')->middleware('hakakses:jalur-pendaftaran,ubah');
        Route::post('/urutan', [$j, 'urutan'])->name('urutan')->middleware('hakakses:jalur-pendaftaran,ubah');
        Route::delete('/{kode}', [$j, 'destroy'])->name('destroy')->middleware('hakakses:jalur-pendaftaran,hapus');
    });

    // Target Santri (CRUD).
    Route::prefix('ppsb/target-santri')->name('target_santri.')->group(function () {
        $t = TargetSantriController::class;
        Route::get('/', [$t, 'index'])->name('index')->middleware('hakakses:target-santri,lihat');
        Route::get('/create', [$t, 'create'])->name('create')->middleware('hakakses:target-santri,buat');
        Route::post('/', [$t, 'store'])->name('store')->middleware('hakakses:target-santri,buat');
        Route::get('/{id}/edit', [$t, 'edit'])->name('edit')->middleware('hakakses:target-santri,ubah')->whereNumber('id');
        Route::put('/{id}', [$t, 'update'])->name('update')->middleware('hakakses:target-santri,ubah')->whereNumber('id');
        Route::delete('/{id}', [$t, 'destroy'])->name('destroy')->middleware('hakakses:target-santri,hapus')->whereNumber('id');
    });

    // Santri — SATU model, EMPAT daftar yang dipisahkan menurut daur hidupnya:
    // calon (PPSB) · aktif (Kependidikan) · alumni · keluar. Pemisahan ini murni
    // TAMPILAN: barisnya tetap di tabel `santri` yang sama, jadi tagihan bersisa
    // milik alumni tetap bisa ditagih & dibayar, dan riwayat serta dokumennya
    // tetap menempel pada orang yang sama. Keempatnya memakai modul hak akses
    // `santri` — tak ada hak akses baru yang perlu diberikan.
    Route::controller(SantriController::class)->group(function () {
        Route::get('/ppsb/calon-santri', 'index')->name('santri.calon')->defaults('lingkup', 'calon')->middleware('hakakses:santri,lihat');
        // Calon yang mundur berdaftar sendiri — arsip PPSB, bukan pekerjaan berjalan.
        Route::get('/ppsb/calon-mundur', 'index')->name('santri.mundur')->defaults('lingkup', 'mundur')->middleware('hakakses:santri,lihat');
        // Calon yang berkasnya sudah tuntas & tinggal menunggu tahun ajarannya
        // dimulai. Berdaftar sendiri supaya jumlah "calon yang masih diproses"
        // di layar tak bercampur dengan yang sudah selesai diurus.
        Route::get('/ppsb/siap-aktivasi', 'index')->name('santri.siap_aktivasi')->defaults('lingkup', 'siap_aktivasi')->middleware('hakakses:santri,lihat');
        Route::post('/ppsb/siap-aktivasi/aktifkan', 'aktivasiMassal')->name('santri.aktivasi_massal')->middleware('hakakses:santri,ubah');
        Route::get('/kesantrian/santri', 'index')->name('santri.aktif')->defaults('lingkup', 'aktif')->middleware('hakakses:santri,lihat');
        Route::get('/kesantrian/alumni', 'index')->name('santri.alumni')->defaults('lingkup', 'alumni')->middleware('hakakses:santri,lihat');
        Route::get('/kesantrian/santri-keluar', 'index')->name('santri.keluar')->defaults('lingkup', 'keluar')->middleware('hakakses:santri,lihat');
        // Unduh daftar yang sedang tampil — satu rute untuk keenam daftar, membawa
        // penyaring & pencarian yang aktif lewat query string.
        Route::get('/santri/unduh/{lingkup}', 'unduh')->name('santri.unduh')
            ->whereIn('lingkup', ['calon', 'siap_aktivasi', 'aktif', 'alumni', 'keluar', 'mundur'])
            ->middleware('hakakses:santri,lihat');
        Route::get('/santri/create', 'create')->name('santri.create')->middleware('hakakses:santri,buat');
        Route::post('/santri', 'store')->name('santri.store')->middleware('hakakses:santri,buat');
        Route::get('/santri/{id}', 'show')->name('santri.show')->middleware('hakakses:santri,lihat')->whereNumber('id');
        // Sunting data santri. Yang PPSB (jalur, gelombang, tahun ajaran) &
        // status sengaja tak ikut — lihat SantriController::update().
        Route::get('/santri/{id}/edit', 'edit')->name('santri.edit')->middleware('hakakses:santri,ubah')->whereNumber('id');
        Route::put('/santri/{id}', 'update')->name('santri.update')->middleware('hakakses:santri,ubah')->whereNumber('id');
        Route::post('/santri/{id}/aksi/{aksi}', 'aksi')->name('santri.aksi')->middleware('hakakses:santri,ubah')->whereNumber('id');
        // Koreksi nominal tagihan — hak TERPISAH dari `santri,ubah`. Ia mengubah
        // piutang yang sudah dibukukan dan menerbitkan jurnal penyesuaian, jadi
        // wewenangnya milik kepala keuangan, bukan siapa pun yang boleh
        // menyunting data santri.
        Route::post('/tagihan/{id}/koreksi', [KoreksiTagihanController::class, 'koreksi'])
            ->name('tagihan.koreksi')->middleware('hakakses:koreksi-tagihan,ubah')->whereNumber('id');

        // Tunggakan awal — pintu KEDUA saldo awal, di samping Impor Data Awal.
        // Menulis tagihan TANPA jurnal, jadi wewenangnya menumpang modul impor:
        // pekerjaannya sama (memasukkan keadaan pindahan), orangnya pun sama.
        // `buat` juga untuk hapus — yang dibuang adalah barisnya sendiri yang
        // belum tersentuh, setara membatalkan batch impor.
        Route::post('/santri/{id}/tunggakan-awal', [TunggakanAwalController::class, 'store'])
            ->name('tunggakan_awal.store')->middleware('hakakses:impor-data-awal,buat')->whereNumber('id');
        Route::put('/tunggakan-awal/{id}', [TunggakanAwalController::class, 'update'])
            ->name('tunggakan_awal.update')->middleware('hakakses:impor-data-awal,buat')->whereNumber('id');
        Route::delete('/tunggakan-awal/{id}', [TunggakanAwalController::class, 'destroy'])
            ->name('tunggakan_awal.destroy')->middleware('hakakses:impor-data-awal,buat')->whereNumber('id');
    });

    // Pendaftaran lanjutan (kenaikan jenjang internal lewat proses PPSB) —
    // dijalankan dari halaman detail santri, tanpa menu sidebar sendiri.
    Route::controller(PendaftaranLanjutanController::class)->group(function () {
        Route::post('/santri/{id}/pendaftaran-lanjutan', 'store')
            ->name('pendaftaran_lanjutan.store')->middleware('hakakses:santri,ubah')->whereNumber('id');
        Route::post('/santri/{id}/pendaftaran-lanjutan/{pendaftaran}/aksi/{aksi}', 'aksi')
            ->name('pendaftaran_lanjutan.aksi')->middleware('hakakses:santri,ubah')->whereNumber(['id', 'pendaftaran']);
    });

    // Berkas Santri (dari detail santri; tanpa menu sidebar sendiri).
    Route::controller(DokumenSantriController::class)->group(function () {
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
        Route::prefix($prefix)->name($name.'.')->controller(PembayaranSantriController::class)
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
    Route::prefix('rekap-pembayaran')->name('rekap_pembayaran.')->controller(RekapPembayaranController::class)->group(function () {
        Route::get('/', 'index')->name('index')->defaults('lingkup', 'semua')->middleware('hakakses:rekap-pembayaran,lihat');
        Route::get('/{idSantri}/cetak', 'cetak')->name('cetak')->middleware('hakakses:rekap-pembayaran,lihat')->whereNumber('idSantri');
        Route::get('/{idSantri}', 'show')->name('show')->middleware('hakakses:rekap-pembayaran,lihat')->whereNumber('idSantri');
    });

    // Lingkup PPSB dari halaman yang sama: hanya santri yang MASIH punya kewajiban
    // uang pangkal / perlengkapan. Rutenya terpisah (bukan query string) supaya
    // menunya bisa menyala dengan benar dan tautannya bisa dibagikan apa adanya.
    Route::get('ppsb/rekap-pembayaran', [RekapPembayaranController::class, 'index'])
        ->name('rekap_pembayaran.ppsb')->defaults('lingkup', 'ppsb')
        ->middleware('hakakses:rekap-pembayaran,lihat');

    // Tagihan Lain-lain — kini bergrup sidebar sendiri (lihat Navigation).
    Route::prefix('kesantrian/tagihan-lain')->name('tagihan_lain.')->controller(TagihanLainController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:tagihan-lain,lihat');
        Route::get('/create', 'create')->name('create')->middleware('hakakses:tagihan-lain,buat');
        Route::post('/', 'store')->name('store')->middleware('hakakses:tagihan-lain,buat');
        Route::delete('/{id}', 'batalkan')->name('batalkan')->middleware('hakakses:tagihan-lain,hapus')->whereNumber('id');
    });

    // Keluarga B — matriks tarif per jenjang & daftar peserta kegiatan.
    // Hak aksesnya menumpang modul `tagihan-lain`: memisahkannya hanya melahirkan
    // keadaan aneh — boleh menerbitkan tagihan tapi tak boleh melihat tarif yang
    // menentukan nominalnya.
    Route::prefix('kesantrian/tagihan-lain')->name('tagihan_lain.')->controller(KepesertaanLainController::class)->group(function () {
        // Modul tersendiri, dibedakan dari matriks tarif LAYANAN: yang satu
        // besaran per jenjang untuk kegiatan berpeserta, yang lain besaran per
        // satuan untuk layanan bersatuan.
        Route::get('/tarif', 'tarif')->name('tarif')->middleware('hakakses:tarif-kepesertaan,lihat');
        Route::put('/tarif', 'simpanTarif')->name('tarif.simpan')->middleware('hakakses:tarif-kepesertaan,ubah');

        Route::get('/peserta', 'peserta')->name('peserta')->middleware('hakakses:tagihan-lain,lihat');
        Route::post('/peserta', 'tambahPeserta')->name('peserta.tambah')->middleware('hakakses:tagihan-lain,ubah');
        Route::put('/peserta/{id}', 'ubahPeserta')->name('peserta.ubah')->middleware('hakakses:tagihan-lain,ubah')->whereNumber('id');
        Route::put('/peserta/{id}/status', 'statusPeserta')->name('peserta.status')->middleware('hakakses:tagihan-lain,ubah')->whereNumber('id');

        Route::post('/peserta/terbitkan', 'terbitkan')->name('peserta.terbitkan')->middleware('hakakses:tagihan-lain,buat');
    });

    // Keluarga A — setoran pemakaian (laundry). Haknya TERPISAH: petugas laundry
    // mencatat timbangan, bukan menerbitkan uang. Penerbitan periodenya tetap
    // menuntut hak `tagihan-lain`.
    Route::prefix('kesantrian/setoran-pemakaian')->name('setoran_pemakaian.')->controller(SetoranPemakaianController::class)->group(function () {
        Route::get('/tarif', 'tarif')->name('tarif')->middleware('hakakses:tarif-pemakaian,lihat');
        Route::put('/tarif', 'simpanTarif')->name('tarif.simpan')->middleware('hakakses:tarif-pemakaian,ubah');

        Route::get('/', 'index')->name('index')->middleware('hakakses:setoran-laundry,lihat');
        Route::post('/', 'catat')->name('catat')->middleware('hakakses:setoran-laundry,buat');
        Route::delete('/{id}', 'hapus')->name('hapus')->middleware('hakakses:setoran-laundry,buat')->whereNumber('id');
        Route::post('/terbitkan', 'terbitkan')->name('terbitkan')->middleware('hakakses:tagihan-lain,buat');
    });

    // Saldo Dompet — daftar saldo seluruh wali & santri, BACA SAJA. Menumpang
    // hak modul `dompet`; ia rincian di balik angka Rekonsiliasi Buku Pembantu,
    // yang selama ini hanya bisa menunjukkan totalnya.
    Route::prefix('kesantrian/saldo-dompet')->name('saldo_dompet.')->controller(SaldoDompetController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:dompet,lihat');
        Route::get('/unduh/{lingkup}', 'unduh')->name('unduh')->middleware('hakakses:dompet,lihat');
    });

    // Dompet & Tabungan Santri (wadi'ah).
    Route::prefix('kesantrian/dompet')->name('dompet.')->controller(DompetController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:dompet,lihat');
        Route::post('/topup', 'topUp')->name('topup')->middleware('hakakses:dompet,buat');
        Route::post('/topup-santri', 'topUpSantri')->name('topup_santri')->middleware('hakakses:dompet,buat');
        Route::post('/auto-debet', 'jalankanAutoDebet')->name('auto_debet')->middleware('hakakses:dompet,ubah');
        Route::post('/topup/{id}/verifikasi', 'verifikasiTopUp')->name('topup.verifikasi')->middleware('hakakses:dompet,ubah')->whereNumber('id');
        Route::post('/topup/{id}/tolak', 'tolakTopUp')->name('topup.tolak')->middleware('hakakses:dompet,hapus')->whereNumber('id');
        Route::post('/pindah', 'pindah')->name('pindah')->middleware('hakakses:dompet,ubah');
        Route::post('/tarik', 'tarik')->name('tarik')->middleware('hakakses:dompet,ubah');
        Route::post('/kunci/{idSantri}', 'kunci')->name('kunci')->middleware('hakakses:dompet,ubah')->whereNumber('idSantri');
        Route::get('/mutasi/{mutasi}/bukti', 'bukti')->name('mutasi.bukti')->middleware('hakakses:dompet,lihat')->whereNumber('mutasi');
    });

    // SPP — tarif + generate tagihan per periode.
    Route::prefix('kesantrian/spp')->name('spp.')->controller(SppController::class)->group(function () {
        Route::get('/', 'index')->name('index')->middleware('hakakses:spp,lihat');
        Route::post('/generate', 'generate')->name('generate')->middleware('hakakses:spp,ubah');
        // Santri dikirim di BADAN kiriman, bukan di path: dropdown santrinya kini
        // dropdown-yang-bisa-dicari (nilainya di input hidden), sehingga tak ada
        // lagi <select> yang bisa dipakai merangkai URL dari sisi Alpine.
        Route::put('/nominal-khusus', 'setNominalKhusus')->name('nominal_khusus')->middleware('hakakses:spp,ubah');
        Route::post('/prabayar', 'prabayar')->name('prabayar')->middleware('hakakses:spp,buat');
    });

    // NIS — format & penerbitan massal. Diterbitkan MANUAL karena nomornya
    // berurut menurut abjad satu angkatan jenjang, bukan urutan kedatangan.
    Route::prefix('kesantrian/nis')->name('nis.')
        ->controller(NisController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('hakakses:nis,lihat');
            Route::put('/format', 'simpanFormat')->name('format')->middleware('hakakses:nis,ubah');
            Route::post('/terbitkan', 'terbitkan')->name('terbitkan')->middleware('hakakses:nis,buat');
        });

    // Outstanding SPP — kontrol tunggakan + koreksi nominal yang salah ketik.
    // Modulnya SENDIRI (bukan `spp`): memeriksa tunggakan pekerjaan harian,
    // menerbitkan tagihan tidak — dan keduanya tak selalu di tangan orang yang sama.
    // Laporan penerimaan kesantrian. Memakai modul `rekap-pembayaran` yang sudah
    // ada: pekerjaannya sama — melihat uang santri yang sudah masuk — hanya
    // satuannya berbeda (per periode, bukan per santri).
    Route::prefix('kesantrian/penerimaan')->name('penerimaan_kesantrian.')
        ->controller(PenerimaanKesantrianController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('hakakses:rekap-pembayaran,lihat');
            Route::get('/unduh', 'download')->name('unduh')->middleware('hakakses:rekap-pembayaran,lihat');
        });

    // Kebijakan Khusus santri (keringanan, potongan, beasiswa). Dua surat wajib
    // terlampir sebelum boleh disetujui — ditegakkan service, bukan hanya
    // diingatkan di layar.
    // Bebas Tanggungan: dua arah — yang santri hutang, dan yang dititipkan
    // padanya. Menumpang modul `rekap-pembayaran`: pekerjaannya sama-sama
    // memeriksa keadaan uang santri, hanya sudutnya berbeda.
    Route::get('/kesantrian/bebas-tanggungan', [BebasTanggunganController::class, 'index'])
        ->name('bebas_tanggungan.index')->middleware('hakakses:rekap-pembayaran,lihat');

    Route::prefix('kesantrian/kebijakan-khusus')->name('kebijakan_khusus.')
        ->controller(KebijakanKhususController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('hakakses:kebijakan-khusus,lihat');
            Route::post('/', 'store')->name('store')->middleware('hakakses:kebijakan-khusus,buat');
            Route::post('/{id}/setujui', 'setujui')->name('setujui')->middleware('hakakses:kebijakan-khusus,ubah')->whereNumber('id');
            Route::post('/{id}/tolak', 'tolak')->name('tolak')->middleware('hakakses:kebijakan-khusus,ubah')->whereNumber('id');
            Route::post('/{id}/akhiri', 'akhiri')->name('akhiri')->middleware('hakakses:kebijakan-khusus,ubah')->whereNumber('id');
        });

    Route::prefix('kesantrian/outstanding-spp')->name('outstanding_spp.')
        ->controller(OutstandingSppController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('hakakses:outstanding-spp,lihat');
            Route::put('/{idTagihan}', 'koreksi')->name('koreksi')->middleware('hakakses:outstanding-spp,ubah')->whereNumber('idTagihan');
        });

    // Outstanding Tagihan Lain — tunggakan Kesantrian SELAIN SPP (lain-lain &
    // daftar ulang). Keduanya memicu penanda tugas yang sama seperti SPP, tetapi
    // selama ini tak punya layar untuk menelusuri siapa & berapa. Modulnya
    // sendiri, sejalan dengan `outstanding-spp`.
    Route::prefix('kesantrian/outstanding-lain')->name('outstanding_lain.')
        ->controller(OutstandingLainController::class)->group(function () {
            Route::get('/', 'index')->name('index')->middleware('hakakses:outstanding-lain,lihat');
            Route::put('/{idTagihan}', 'koreksi')->name('koreksi')->middleware('hakakses:outstanding-lain,ubah')->whereNumber('idTagihan');
        });

    // Wali / Keluarga Santri.
    Route::prefix('wali')->name('wali.')->group(function () {
        $w = WaliController::class;
        Route::get('/', [$w, 'index'])->name('index')->middleware('hakakses:wali,lihat');
        Route::get('/create', [$w, 'create'])->name('create')->middleware('hakakses:wali,buat');
        Route::post('/', [$w, 'store'])->name('store')->middleware('hakakses:wali,buat');
        Route::get('/{id}/edit', [$w, 'edit'])->name('edit')->middleware('hakakses:wali,ubah')->whereNumber('id');
        Route::put('/{id}', [$w, 'update'])->name('update')->middleware('hakakses:wali,ubah')->whereNumber('id');
        Route::delete('/{id}', [$w, 'destroy'])->name('destroy')->middleware('hakakses:wali,hapus')->whereNumber('id');
    });

    // ---- Laporan (read-only) ----
    Route::prefix('reports')->name('reports.')->middleware('hakakses:reports,lihat')->group(function () {
        $r = ReportsController::class;
        Route::get('/', [$r, 'index'])->name('index');
        Route::get('/neraca', [$r, 'neraca'])->name('neraca');
        Route::get('/laba-rugi', [$r, 'labaRugi'])->name('laba_rugi');
        Route::get('/perubahan-modal', [$r, 'perubahanModal'])->name('perubahan_modal');
        Route::get('/arus-kas', [$r, 'arusKas'])->name('arus_kas');
        // Laporan khas entitas nirlaba (ISAK 35). Perubahan Modal yang lama
        // SENGAJA dipertahankan: format perusahaan masih dipakai sebagian
        // pihak, dan mencabutnya sekaligus akan membuat pengguna kehilangan
        // laporan yang dikenalnya di tengah masa peralihan.
        Route::get('/perubahan-aset-neto', [$r, 'perubahanAsetNeto'])->name('perubahan_aset_neto');
        Route::get('/neraca-saldo', [$r, 'neracaSaldo'])->name('neraca_saldo');
        Route::get('/buku-besar', [$r, 'bukuBesar'])->name('buku_besar');
        Route::get('/aset', [$r, 'aset'])->name('aset');
        Route::get('/persediaan', [$r, 'persediaan'])->name('persediaan');
        Route::get('/jurnal', [$r, 'jurnalMentah'])->name('jurnal');
        Route::get('/export/{type}', [$r, 'download'])->name('export');
    });
});
