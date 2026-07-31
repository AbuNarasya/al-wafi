<?php

namespace App\Support\Export;

use App\Models\Accrue;
use App\Models\AdvanceSettlement;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CashIn;
use App\Models\CashOut;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\OpeningBalance;
use App\Models\OperationalAdvance;
use App\Models\PurchaseOrder;
use App\Models\Santri;
use App\Models\TagihanSantri;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorType;
use App\Services\Modules\BookTransferService;
use App\Services\Ppsb\Tahap;
use Illuminate\Support\Carbon;

/**
 * Registry dataset export (port MAPPERS + DATASETS dev/ExportDataPage). Tiap
 * dataset → baris asosiatif [label => nilai] dengan kolom Bahasa Indonesia &
 * relasi (unit/vendor/customer/akun) di-resolve ke nama.
 */
class DatasetRegistry
{
    /** @var array<string,string> */
    private array $unit;

    private array $vendor;

    private array $customer;

    private array $coa;

    private array $level;

    private array $vtype;

    private array $ctype;

    private array $rekening;

    public function __construct()
    {
        $this->unit = BusinessUnit::pluck('nama_unit', 'kode_unit')->all();
        $this->vendor = Vendor::pluck('nama_vendor', 'kode_vendor')->all();
        $this->customer = Customer::pluck('nama_customer', 'kode_customer')->all();
        $this->coa = CoaDetail::pluck('nama_coa', 'kode_coa')->all();
        $this->level = Level::pluck('nama_level', 'kode_level')->all();
        $this->vtype = VendorType::pluck('nama', 'kode_jenis_vendor')->all();
        $this->ctype = CustomerType::pluck('nama', 'kode_jenis_customer')->all();
        $this->rekening = BankAccount::pluck('nama_rekening', 'kode_coa')->all();
    }

    /** @return array<int,array{key:string,label:string,group:string,file:string}> */
    public static function datasets(): array
    {
        return [
            ['key' => 'coa-detail', 'label' => 'Chart of Account (Detail)', 'group' => 'Master', 'file' => 'coa_detail'],
            ['key' => 'coa-groups', 'label' => 'Grup COA', 'group' => 'Master', 'file' => 'grup_coa'],
            ['key' => 'bank-accounts', 'label' => 'Kas & Rekening', 'group' => 'Master', 'file' => 'kas_rekening'],
            ['key' => 'business-units', 'label' => 'Unit Bisnis', 'group' => 'Master', 'file' => 'unit_bisnis'],
            ['key' => 'levels', 'label' => 'Level Otorisasi', 'group' => 'Master', 'file' => 'level_otorisasi'],
            ['key' => 'users', 'label' => 'Pengguna', 'group' => 'Master', 'file' => 'pengguna'],
            ['key' => 'vendor-types', 'label' => 'Jenis Vendor', 'group' => 'Master', 'file' => 'jenis_vendor'],
            ['key' => 'vendors', 'label' => 'Vendor', 'group' => 'Master', 'file' => 'vendor'],
            ['key' => 'customer-types', 'label' => 'Jenis Customer', 'group' => 'Master', 'file' => 'jenis_customer'],
            ['key' => 'customers', 'label' => 'Customer', 'group' => 'Master', 'file' => 'customer'],
            ['key' => 'inventory', 'label' => 'Persediaan', 'group' => 'Master', 'file' => 'persediaan'],
            ['key' => 'asset-categories', 'label' => 'Kategori Aset', 'group' => 'Master', 'file' => 'kategori_aset'],
            ['key' => 'assets', 'label' => 'Aset Tetap', 'group' => 'Master', 'file' => 'aset_tetap'],
            ['key' => 'opening-balance', 'label' => 'Saldo Awal', 'group' => 'Master', 'file' => 'saldo_awal'],
            ['key' => 'purchase-orders', 'label' => 'Purchase Order', 'group' => 'Transaksi', 'file' => 'purchase_order'],
            ['key' => 'invoices', 'label' => 'Invoice Vendor', 'group' => 'Transaksi', 'file' => 'invoice'],
            ['key' => 'cash-in', 'label' => 'Kas Masuk', 'group' => 'Transaksi', 'file' => 'kas_masuk'],
            ['key' => 'cash-out', 'label' => 'Kas Keluar', 'group' => 'Transaksi', 'file' => 'kas_keluar'],
            ['key' => 'operational-advance', 'label' => 'Uang Muka Operasional', 'group' => 'Transaksi', 'file' => 'uang_muka'],
            ['key' => 'advance-settlement', 'label' => 'Penyelesaian Uang Muka', 'group' => 'Transaksi', 'file' => 'penyelesaian_um'],
            ['key' => 'book-transfer', 'label' => 'Pindah Buku', 'group' => 'Transaksi', 'file' => 'pindah_buku'],
            ['key' => 'accrue', 'label' => 'Accrue & Prepaid', 'group' => 'Transaksi', 'file' => 'accrue'],

            // Kesantrian. Empat daftar santri yang paling sering diminta di luar
            // aplikasi: yang bersekolah, yang lulus, yang berhenti, dan calon yang
            // batal masuk — yang terakhir dipakai menghitung ulang target penerimaan.
            ['key' => 'santri-aktif', 'label' => 'Santri Aktif', 'group' => 'Kesantrian', 'file' => 'santri_aktif'],
            ['key' => 'alumni', 'label' => 'Alumni (Lulusan)', 'group' => 'Kesantrian', 'file' => 'alumni'],
            ['key' => 'santri-keluar', 'label' => 'Santri Keluar', 'group' => 'Kesantrian', 'file' => 'santri_keluar'],
            ['key' => 'calon-mundur', 'label' => 'Calon Mengundurkan Diri', 'group' => 'Kesantrian', 'file' => 'calon_mengundurkan_diri'],
        ];
    }

    public static function meta(string $key): ?array
    {
        foreach (self::datasets() as $d) {
            if ($d['key'] === $key) {
                return $d;
            }
        }

        return null;
    }

    private function nm(array $map, $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }

        return $map[(string) $code] ?? (string) $code;
    }

    private function tgl($v): string
    {
        if (! $v) {
            return '';
        }

        return $v instanceof \DateTimeInterface ? $v->format('d/m/Y') : Carbon::parse($v)->format('d/m/Y');
    }

    private function coaLabel($kode): string
    {
        return $kode ? "{$kode} — ".$this->nm($this->coa, $kode) : '';
    }

    /** @return array<int,array<string,scalar|null>> */
    public function rows(string $key): array
    {
        return match ($key) {
            'coa-detail' => CoaDetail::orderBy('kode_coa')->get()->map(fn ($r) => [
                'Kode COA' => $r->kode_coa, 'Nama COA' => $r->nama_coa, 'Kode Grup' => $r->kode_grup,
                'Jenis Saldo' => $r->jenis_saldo, 'Status' => $r->status, 'Keterangan' => $r->keterangan ?? '',
            ])->all(),
            'coa-groups' => CoaGroup::orderBy('kode_grup')->get()->map(fn ($r) => [
                'Kode Grup' => $r->kode_grup, 'Nama Grup' => $r->nama_grup, 'Induk' => $r->kode_induk ?? '', 'Level' => $r->level,
            ])->all(),
            'bank-accounts' => BankAccount::orderBy('kode_coa')->get()->map(fn ($r) => [
                'Kode COA' => $r->kode_coa, 'Nama Rekening' => $r->nama_rekening, 'Jenis' => $r->jenis_rekening,
                'Bank' => $r->nama_bank ?? '', 'No. Rekening' => $r->no_rekening ?? '', 'Status' => $r->status,
            ])->all(),
            'business-units' => BusinessUnit::orderBy('kode_unit')->get()->map(fn ($r) => [
                'Kode Unit' => $r->kode_unit, 'Nama Unit' => $r->nama_unit, 'Status' => $r->status,
            ])->all(),
            'levels' => Level::orderBy('kode_level')->get()->map(fn ($r) => [
                'Kode Level' => $r->kode_level, 'Nama Level' => $r->nama_level,
                'Batas Nominal' => $r->max_transaksi === null ? 'Tanpa batas' : $r->max_transaksi,
                'Keterangan' => $r->keterangan ?? '', 'Status' => $r->status,
            ])->all(),
            'users' => User::orderBy('id_pengguna')->get()->map(fn ($r) => [
                'ID' => $r->id_pengguna, 'Username' => $r->username, 'Nama' => $r->nama, 'Jabatan' => $r->jabatan ?? '',
                'Level' => $this->nm($this->level, $r->kode_level), 'Status' => $r->status,
            ])->all(),
            'vendor-types' => VendorType::orderBy('kode_jenis_vendor')->get()->map(fn ($r) => [
                'Kode' => $r->kode_jenis_vendor, 'Nama' => $r->nama, 'Status' => $r->status,
            ])->all(),
            'vendors' => Vendor::orderBy('kode_vendor')->get()->map(fn ($r) => [
                'Kode' => $r->kode_vendor, 'Nama Vendor' => $r->nama_vendor, 'Jenis' => $this->nm($this->vtype, $r->kode_jenis_vendor),
                'Alamat' => $r->alamat ?? '', 'Telepon' => $r->telepon ?? '', 'Metode Pembayaran' => $r->metode_pembayaran,
                'Termin (hari)' => $r->termin_hari ?? '', 'Bank' => $r->bank ?? '', 'No. Rekening' => $r->no_rekening ?? '',
                'Atas Nama' => $r->atas_nama ?? '', 'Status' => $r->status,
            ])->all(),
            'customer-types' => CustomerType::orderBy('kode_jenis_customer')->get()->map(fn ($r) => [
                'Kode' => $r->kode_jenis_customer, 'Nama' => $r->nama, 'Status' => $r->status,
            ])->all(),
            'customers' => Customer::orderBy('kode_customer')->get()->map(fn ($r) => [
                'Kode' => $r->kode_customer, 'Nama Customer' => $r->nama_customer, 'Jenis' => $this->nm($this->ctype, $r->kode_jenis_customer),
                'COA Pendapatan' => $this->coaLabel($r->kode_coa_pendapatan), 'COA Piutang' => $this->coaLabel($r->kode_coa_piutang),
                'Alamat' => $r->alamat ?? '', 'Telepon' => $r->telepon ?? '', 'Email' => $r->email ?? '', 'Status' => $r->status,
            ])->all(),
            'inventory' => Inventory::orderBy('kode_persediaan')->get()->map(fn ($r) => [
                'Kode' => $r->kode_persediaan, 'Nama' => $r->nama_persediaan, 'Satuan' => $r->satuan ?? '',
                'Harga Perolehan' => $r->harga_perolehan, 'Stok Masuk' => $r->stok_masuk, 'Stok Keluar' => $r->stok_keluar,
                'Stok' => (float) $r->stok_masuk - (float) $r->stok_keluar, 'COA' => $this->coaLabel($r->kode_coa), 'Status' => $r->status,
            ])->all(),
            'asset-categories' => AssetCategory::orderBy('kode_kategori')->get()->map(fn ($r) => [
                'Kode' => $r->kode_kategori, 'Nama' => $r->nama, 'Keterangan' => $r->keterangan ?? '', 'Status' => $r->status,
            ])->all(),
            'assets' => Asset::orderBy('kode_aset')->get()->map(fn ($r) => [
                'Kode' => $r->kode_aset, 'Nama' => $r->nama_aset, 'Kategori' => $r->kategori_aset ?? '', 'Kuantiti' => $r->kuantiti,
                'Harga Perolehan' => $r->harga_perolehan, 'Tanggal Perolehan' => $this->tgl($r->tanggal_perolehan),
                'Umur Manfaat (bln)' => $r->umur_manfaat, 'Metode Depresiasi' => $r->metode_depresiasi, 'Nilai Residu' => $r->nilai_residu,
                'Akumulasi Depresiasi' => $r->akumulasi_depresiasi, 'Nilai Buku' => (float) $r->harga_perolehan - (float) $r->akumulasi_depresiasi,
                'COA' => $this->coaLabel($r->kode_coa), 'Status' => $r->status,
            ])->all(),
            'opening-balance' => OpeningBalance::orderBy('kode_coa')->get()->map(fn ($r) => [
                'Kode COA' => $r->kode_coa, 'Nama COA' => $this->nm($this->coa, $r->kode_coa), 'Jenis Saldo' => $r->jenis_saldo,
                'Saldo' => $r->saldo, 'Sudah Diposting' => $r->posted ? 'Ya' : 'Tidak',
            ])->all(),
            'purchase-orders' => PurchaseOrder::orderByDesc('tanggal_po')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal_po), 'Referensi' => $r->nomor_po, 'Keterangan' => $r->keterangan ?? '',
                'Vendor' => $this->nm($this->vendor, $r->kode_vendor), 'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit),
                'Nominal' => $r->total_po, 'Status' => $r->status,
            ])->all(),
            'invoices' => Invoice::orderByDesc('tanggal_invoice')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal_invoice), 'Referensi' => $r->nomor_invoice, 'Jatuh Tempo' => $this->tgl($r->tanggal_jatuh_tempo),
                'Keterangan' => $r->keterangan ?? '', 'Vendor' => $this->nm($this->vendor, $r->kode_vendor),
                'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit), 'Nominal' => $r->total, 'Sisa Hutang' => $r->sisa_hutang, 'Status' => $r->status,
            ])->all(),
            'cash-in' => CashIn::orderByDesc('tanggal')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal), 'Referensi' => $r->nomor_transaksi, 'Keterangan' => $r->keterangan ?? '',
                'Customer' => $this->nm($this->customer, $r->kode_customer), 'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit),
                'Nominal' => $r->nominal, 'Status' => $r->status,
            ])->all(),
            'cash-out' => CashOut::orderByDesc('tanggal')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal), 'Referensi' => $r->nomor_transaksi, 'Keterangan' => $r->keterangan ?? '',
                'Vendor' => $this->nm($this->vendor, $r->kode_vendor), 'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit),
                'Nominal' => $r->nominal, 'Status' => $r->status,
            ])->all(),
            'operational-advance' => OperationalAdvance::orderByDesc('tanggal')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal), 'Referensi' => $r->nomor_ref, 'Keterangan' => $r->keterangan ?? '',
                'Penerima' => $r->penerima ?? '', 'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit),
                'Nominal' => $r->nominal, 'Sudah Diselesaikan' => $r->nominal_diselesaikan,
                'Sisa' => (float) $r->nominal - (float) $r->nominal_diselesaikan, 'Status' => $r->status,
            ])->all(),
            'advance-settlement' => AdvanceSettlement::orderByDesc('tanggal')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal), 'Referensi' => $r->nomor_referensi ?? '', 'Keterangan' => $r->keterangan ?? '',
                'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit), 'Akun Uang Muka' => $this->nm($this->coa, $r->kode_coa_uang_muka),
                'Nominal Uang Muka' => $r->nominal_uang_muka, 'Akun Realisasi' => $this->nm($this->coa, $r->kode_coa_realisasi),
                'Nominal Realisasi' => $r->nominal_realisasi, 'Status' => $r->status,
            ])->all(),
            'book-transfer' => collect(app(BookTransferService::class)->list())->map(fn ($r) => [
                'Tanggal' => $this->tgl($r['tanggal']), 'Referensi' => $r['referensi'], 'Keterangan' => $r['keterangan'] ?? '',
                'Dari Rekening' => $r['nama_asal'] ?? '', 'Ke Rekening' => $r['nama_tujuan'] ?? '',
                'Unit Bisnis' => $this->nm($this->unit, $r['kode_unit']), 'Nominal' => $r['nominal'], 'Status' => $r['status'],
            ])->all(),
            'accrue' => Accrue::orderByDesc('tanggal')->get()->map(fn ($r) => [
                'Tanggal' => $this->tgl($r->tanggal), 'Referensi' => $r->nomor_referensi ?? '', 'Keterangan' => $r->keterangan ?? '',
                'COA Debet' => $this->nm($this->coa, $r->kode_coa_debet), 'COA Kredit' => $this->nm($this->coa, $r->kode_coa_kredit),
                'Unit Bisnis' => $this->nm($this->unit, $r->kode_unit), 'Nominal' => $r->nominal, 'Status' => $r->status,
            ])->all(),

            'santri-aktif' => $this->barisSantri(['aktif']),
            'alumni' => $this->barisSantri(['alumni']),
            'santri-keluar' => $this->barisSantri(['keluar']),
            'calon-mundur' => $this->barisSantri(['mengundurkan_diri']),

            default => [],
        };
    }

    /**
     * Baris santri untuk export, dipisah menurut status — sama seperti daftar di
     * layar (Santri / Alumni / Santri Keluar).
     *
     * SISA TAGIHAN ikut dibawa karena itulah yang paling sering dicari saat
     * daftarnya ditarik keluar: alumni yang masih menunggak tetap boleh ditagih,
     * jadi angkanya harus terlihat tanpa membuka satu per satu. Dihitung dengan
     * satu kueri agregat, bukan per baris.
     *
     * @param  list<string>  $status
     * @return array<int,array<string,scalar|null>>
     */
    private function barisSantri(array $status): array
    {
        $santri = Santri::with(['wali', 'jenjang', 'jalurPendaftaran'])
            ->whereIn('status', $status)
            ->orderBy('kode_jenjang')->orderBy('tingkat')->orderBy('nama')
            ->get();

        $sisa = TagihanSantri::whereIn('id_santri', $santri->pluck('id'))
            ->where('status', '!=', 'batal')
            ->selectRaw('id_santri, COALESCE(SUM(sisa), 0) AS sisa')
            ->groupBy('id_santri')->pluck('sisa', 'id_santri');

        $alumni = $status === ['alumni'];

        return $santri->map(fn ($s) => array_filter([
            'NIS' => $s->nis ?? '',
            'No. Pendaftaran' => $s->no_pendaftaran,
            'Nama' => $s->nama,
            'L/P' => $s->jenis_kelamin,
            'Tempat Lahir' => $s->tempat_lahir ?? '',
            'Tanggal Lahir' => $this->tgl($s->tanggal_lahir),
            'NISN' => $s->nisn ?? '',
            'Jenjang' => $s->jenjang?->nama ?? $s->kode_jenjang ?? '',
            $alumni ? 'Tingkat Akhir' : 'Tingkat' => $s->tingkat ?? '',
            'Jalur' => $s->jalurPendaftaran?->nama ?? $s->jalur ?? '',
            'Angkatan (T.A Masuk)' => $s->tahun_ajaran ?? '',
            'T.A Berjalan' => $s->taBerjalan() ?? '',
            // Kolom ini hanya terisi untuk alumni; disisipkan bersyarat supaya
            // berkas Santri Aktif/Keluar tak punya kolom yang selalu kosong.
            'Tanggal Lulus' => $alumni ? $this->tgl($s->tanggal_lulus) : null,
            'Wali' => $s->wali?->nama ?? '',
            'Telepon Wali' => $s->wali?->telepon ?? '',
            'Sisa Tagihan' => $sisa[$s->id] ?? 0,
            'Status' => Tahap::labelStatus((string) $s->status),
        ], fn ($v) => $v !== null))->all();
    }
}
