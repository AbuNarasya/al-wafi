<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalStep;
use App\Models\Bagian;
use App\Models\BankAccount;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\LevelPengajuan;
use App\Models\LokasiPersediaan;

/**
 * DATA AWAL INSTANS WAFI MART (INSTANS=wafimart) — keputusan user 20 Okt 2026:
 * BADAN USAHA SENDIRI (modal disetor, NPWP sendiri), mulai buku 1 Januari 2027,
 * dua toko (Koperasi Banin & Koperasi Banat) + satu gudang.
 *
 *  - COA ritel (aset lancar, aset tetap, hutang usaha & pajak, ekuitas badan usaha,
 *    penjualan, HPP, beban operasional toko). Bisa ditambah lewat Chart of Account.
 *  - Unit bisnis: U001 Kantor Pusat (penampung neraca), U002 Toko Banin, U003 Toko Banat —
 *    laba rugi per toko terbaca dari unitnya.
 *  - Lokasi L002/L003 lengkap dengan akun kasir sehingga shift kasir bisa langsung dibuka.
 *
 * Tanpa data pesantren sama sekali (tahun ajaran, jalur, titipan santri, Midtrans).
 * Idempotent seperti DatabaseSeeder: aman dijalankan berulang.
 */
class WafiMartSeeder extends DatabaseSeeder
{
    public function run(): void
    {
        $this->seedLevels();
        $this->seedLevelPengajuanToko();
        $this->seedCoaRitel();
        $this->seedUnitToko();
        $this->seedRekening();
        $this->seedBagianToko();
        $this->seedLokasiToko();
        $this->seedApprovalFlowsToko();
        $this->seedAdmin();
        $this->seedPengaturanToko();

        $this->command?->info('Seed Wafi Mart selesai. Login awal: admin / admin123 — GANTI segera.');
    }

    private function seedLevelPengajuanToko(): void
    {
        $rows = [
            ['peringkat' => 1, 'nama' => 'Direktur', 'keterangan' => 'Peringkat tertinggi. Ikut rantai pembayaran bila pengajuan menembus anggaran.'],
            ['peringkat' => 2, 'nama' => 'Manajer', 'keterangan' => 'Persetujuan wajib untuk seluruh pengajuan pembayaran.'],
            ['peringkat' => 3, 'nama' => 'Kepala Toko / Kepala Bagian', 'keterangan' => 'Persetujuan wajib, terbatas pada pengajuan dari bagiannya sendiri.'],
            ['peringkat' => 4, 'nama' => 'Staff', 'keterangan' => 'Pemohon pembayaran & anggaran, dalam bagiannya sendiri.'],
        ];
        foreach ($rows as $lp) {
            LevelPengajuan::updateOrCreate(['peringkat' => $lp['peringkat']], $lp + LevelPengajuan::peranBawaan($lp['peringkat']));
        }
    }

    private function seedCoaRitel(): void
    {
        $grup = [
            ['1', 'Aset', null, 1], ['2', 'Liabilitas', null, 1], ['3', 'Ekuitas', null, 1], ['4', 'Pendapatan', null, 1], ['5', 'Beban', null, 1],
            ['1.1', 'Aset Lancar', '1', 2],
            ['1.1.01', 'Kas', '1.1', 3], ['1.1.02', 'Bank', '1.1', 3], ['1.1.03', 'Piutang', '1.1', 3],
            ['1.1.04', 'Persediaan', '1.1', 3], ['1.1.05', 'Uang Muka & Dibayar Dimuka', '1.1', 3],
            ['1.2', 'Aset Tetap', '1', 2],
            ['1.2.01', 'Aset Tetap', '1.2', 3], ['1.2.02', 'Akumulasi Penyusutan', '1.2', 3],
            ['2.1', 'Liabilitas Jangka Pendek', '2', 2],
            ['2.1.01', 'Hutang Usaha', '2.1', 3], ['2.1.02', 'Hutang Lain-lain', '2.1', 3], ['2.1.03', 'Hutang Pajak', '2.1', 3],
            ['2.2', 'Liabilitas Jangka Panjang', '2', 2],
            ['2.2.01', 'Pinjaman', '2.2', 3],
            ['3.1', 'Modal', '3', 2],
            ['3.1.01', 'Modal & Saldo Laba', '3.1', 3],
            ['4.1', 'Pendapatan Usaha', '4', 2],
            ['4.1.01', 'Penjualan', '4.1', 3],
            ['4.2', 'Pendapatan Lain-lain', '4', 2],
            ['4.2.01', 'Pendapatan Lain-lain', '4.2', 3],
            ['5.1', 'Harga Pokok Penjualan', '5', 2],
            ['5.1.01', 'Harga Pokok Penjualan', '5.1', 3],
            ['5.2', 'Beban Operasional', '5', 2],
            ['5.2.01', 'Beban Pegawai', '5.2', 3], ['5.2.02', 'Beban Toko & Umum', '5.2', 3], ['5.2.03', 'Beban Penyusutan', '5.2', 3],
            ['5.3', 'Beban Lain-lain', '5', 2],
            ['5.3.01', 'Beban Lain-lain', '5.3', 3],
        ];
        foreach ($grup as [$kode, $nama, $induk, $level]) {
            CoaGroup::updateOrCreate(['kode_grup' => $kode], ['kode_grup' => $kode, 'nama_grup' => $nama, 'kode_induk' => $induk, 'level' => $level]);
        }

        $d = 'debet';
        $k = 'kredit';
        $akun = [
            ['1.1.01.001', 'Kas Besar', $d], ['1.1.01.002', 'Kas Kecil', $d],
            ['1.1.01.011', 'Laci Kasir Koperasi Banin', $d], ['1.1.01.012', 'Laci Kasir Koperasi Banat', $d],
            ['1.1.02.001', 'Bank Wafi Mart', $d],
            ['1.1.03.001', 'Piutang ke Al Wafi (Belanja Kartu Santri)', $d], ['1.1.03.002', 'Piutang Usaha', $d], ['1.1.03.003', 'Piutang Karyawan', $d],
            ['1.1.04.001', 'Persediaan Barang Dagang', $d],
            ['1.1.05.001', 'Uang Muka Pembelian', $d], ['1.1.05.002', 'Biaya Dibayar Dimuka', $d], ['1.1.05.003', 'Pajak Dibayar Dimuka', $d],
            ['1.2.01.001', 'Peralatan Toko', $d], ['1.2.01.002', 'Kendaraan', $d], ['1.2.01.003', 'Bangunan & Renovasi Toko', $d],
            ['1.2.02.001', 'Akumulasi Penyusutan Peralatan Toko', $k], ['1.2.02.002', 'Akumulasi Penyusutan Kendaraan', $k],
            ['1.2.02.003', 'Akumulasi Penyusutan Bangunan & Renovasi', $k],
            ['2.1.01.001', 'Hutang Usaha (Vendor)', $k], ['2.1.01.002', 'Barang Diterima Belum Ditagih', $k],
            ['2.1.02.001', 'Hutang Gaji', $k], ['2.1.02.002', 'Biaya Masih Harus Dibayar', $k], ['2.1.02.003', 'Uang Muka Pelanggan', $k],
            ['2.1.02.004', 'Hutang ke Al Wafi', $k],
            ['2.1.03.001', 'Hutang PPh 21', $k], ['2.1.03.002', 'Hutang PPh Final / PPh Badan', $k], ['2.1.03.003', 'Hutang Pajak Lainnya', $k],
            ['2.2.01.001', 'Pinjaman Bank', $k],
            ['3.1.01.001', 'Modal Disetor', $k], ['3.1.01.002', 'Laba Ditahan', $k], ['3.1.01.003', 'Dividen', $d],
            ['4.1.01.001', 'Penjualan Barang Dagang', $k],
            ['4.2.01.001', 'Pendapatan Sewa Display & Promosi', $k], ['4.2.01.002', 'Pendapatan Bunga Bank', $k],
            ['4.2.01.003', 'Selisih Kas Lebih', $k], ['4.2.01.004', 'Laba/Rugi Pelepasan Aset', $k], ['4.2.01.009', 'Pendapatan Lain-lain', $k],
            ['5.1.01.001', 'HPP Barang Dagang', $d], ['5.1.01.002', 'Selisih, Kerusakan & Kehilangan Persediaan', $d],
            ['5.2.01.001', 'Beban Gaji & Tunjangan', $d], ['5.2.01.002', 'Beban BPJS & Kesejahteraan Pegawai', $d],
            ['5.2.02.001', 'Beban Listrik & Air', $d], ['5.2.02.002', 'Beban Sewa', $d], ['5.2.02.003', 'Beban Perlengkapan & Kemasan', $d],
            ['5.2.02.004', 'Beban Transport & Pengiriman', $d], ['5.2.02.005', 'Beban Pemeliharaan & Perbaikan', $d],
            ['5.2.02.006', 'Beban Telepon & Internet', $d], ['5.2.02.007', 'Beban ATK & Administrasi', $d],
            ['5.2.02.008', 'Selisih Kas Kurang', $d],
            ['5.2.03.001', 'Beban Penyusutan Aset Tetap', $d],
            ['5.3.01.001', 'Beban Administrasi Bank', $d], ['5.3.01.002', 'Beban Pajak', $d], ['5.3.01.009', 'Beban Lain-lain', $d],
        ];
        foreach ($akun as [$kode, $nama, $saldo]) {
            CoaDetail::updateOrCreate(['kode_coa' => $kode], ['kode_coa' => $kode, 'nama_coa' => $nama, 'kode_grup' => substr($kode, 0, 6), 'jenis_saldo' => $saldo]);
        }
    }

    private function seedUnitToko(): void
    {
        foreach (['U001' => 'Kantor Pusat Wafi Mart', 'U002' => 'Toko Koperasi Banin', 'U003' => 'Toko Koperasi Banat'] as $kode => $nama) {
            BusinessUnit::updateOrCreate(['kode_unit' => $kode], ['kode_unit' => $kode, 'nama_unit' => $nama]);
        }
    }

    private function seedRekening(): void
    {
        $rekening = [
            ['1.1.01.001', 'Kas Besar', 'tunai'], ['1.1.01.002', 'Kas Kecil', 'tunai'],
            ['1.1.01.011', 'Laci Kasir Koperasi Banin', 'tunai'], ['1.1.01.012', 'Laci Kasir Koperasi Banat', 'tunai'],
            ['1.1.02.001', 'Bank Wafi Mart', 'bank'],
        ];
        foreach ($rekening as [$kode, $nama, $jenis]) {
            BankAccount::firstOrCreate(['kode_coa' => $kode], ['nama_rekening' => $nama, 'jenis_rekening' => $jenis, 'status' => 'aktif']);
        }
    }

    private function seedBagianToko(): void
    {
        $bagian = [
            ['WM', 'Direksi Wafi Mart', null, 1],
            ['WM-OPS', 'Operasional Toko', 'WM', 2], ['WM-KEU', 'Keuangan & Administrasi', 'WM', 2],
            ['WM-BANIN', 'Toko Koperasi Banin', 'WM-OPS', 3], ['WM-BANAT', 'Toko Koperasi Banat', 'WM-OPS', 3],
            ['WM-GDG', 'Gudang & Pembelian', 'WM-OPS', 3], ['WM-AKT', 'Akuntansi & Pajak', 'WM-KEU', 3],
        ];
        foreach ($bagian as [$kode, $nama, $induk, $level]) {
            Bagian::updateOrCreate(['kode_bagian' => $kode], ['kode_bagian' => $kode, 'nama_bagian' => $nama, 'kode_induk' => $induk, 'level' => $level]);
        }
    }

    private function seedLokasiToko(): void
    {
        // L001 "Gudang Utama" dibuat migrasi 000065; di sini cukup diberi unitnya.
        LokasiPersediaan::where('kode', 'L001')->update(['kode_unit' => 'U001']);

        foreach ([['L002', 'Koperasi Banin', 'U002', '1.1.01.011', 'WM-BANIN', 2], ['L003', 'Koperasi Banat', 'U003', '1.1.01.012', 'WM-BANAT', 3]] as [$kode, $nama, $unit, $laci, $bagian, $urut]) {
            LokasiPersediaan::firstOrCreate(['kode' => $kode], [
                'nama' => $nama, 'jenis' => 'toko', 'kode_unit' => $unit, 'status' => 'aktif', 'urutan' => $urut,
                'kode_coa_kas' => $laci, 'kode_coa_pendapatan' => '4.1.01.001', 'kode_coa_hpp' => '5.1.01.001',
                'kode_coa_selisih_kas' => '5.2.02.008', 'kode_bagian' => $bagian,
            ]);
        }
    }

    private function seedApprovalFlowsToko(): void
    {
        $flows = [
            ['BUDGET-STD', 'Pengajuan Anggaran (standar)', 'Budget', [
                [1, 'Kepala Toko / Kepala Bagian', 3, 'bagian', null], [2, 'Manajer', 2, 'induk', null], [3, 'Direktur', 1, 'yayasan', null],
            ]],
            ['BAYAR-STD', 'Pengajuan Pembayaran (standar)', 'PengajuanPembayaran', [
                [1, 'Kepala Toko / Kepala Bagian', 3, 'bagian', null], [2, 'Manajer', 2, 'induk', null],
                [3, 'Direktur (eskalasi overbudget)', 1, 'yayasan', 'overbudget'],
            ]],
        ];
        foreach ($flows as [$kode, $nama, $jenis, $steps]) {
            ApprovalFlow::updateOrCreate(['kode_flow' => $kode], ['kode_flow' => $kode, 'nama_flow' => $nama, 'jenis_dokumen' => $jenis]);
            foreach ($steps as [$urut, $tahap, $peringkat, $scope, $syarat]) {
                ApprovalStep::updateOrCreate(['kode_flow' => $kode, 'urutan' => $urut], [
                    'kode_flow' => $kode, 'urutan' => $urut, 'nama_tahap' => $tahap, 'peringkat' => $peringkat,
                    'fungsi' => null, 'scope' => $scope, 'syarat' => $syarat,
                ]);
            }
        }
    }

    private function seedPengaturanToko(): void
    {
        $ada = CompanySettings::find(1);
        CompanySettings::updateOrCreate(['id' => 1], [
            'id' => 1,
            // Nama & jenis yang sudah diubah admin tak ditimpa saat seeder diulang.
            'nama_perusahaan' => $ada?->nama_perusahaan ?: 'Wafi Mart',
            'jenis_perusahaan' => $ada?->jenis_perusahaan ?: 'Badan Usaha (Perdagangan Eceran)',
            'mata_uang' => 'IDR',
            'periode_awal_pembukuan' => $ada?->periode_awal_pembukuan?->toDateString() ?: '2027-01-01',
            'bulan_awal_anggaran' => $ada?->bulan_awal_anggaran ?: 1,
            'kode_unit_neraca' => $ada?->kode_unit_neraca ?: 'U001',
            'kode_coa_grni' => $ada?->kode_coa_grni ?: '2.1.01.002',
            'topup_tunai_dompet_santri' => false,
        ]);
    }
}
