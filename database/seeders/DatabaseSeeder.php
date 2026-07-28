<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\ApprovalStep;
use App\Models\Bagian;
use App\Models\BusinessUnit;
use App\Models\CoaDetail;
use App\Models\CoaGroup;
use App\Models\CompanySettings;
use App\Models\Level;
use App\Models\LevelPengajuan;
use App\Models\UnitDefault;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seed data awal minimum agar sistem bisa dipakai (port dari prisma/seed.ts):
 *  - 4 level otorisasi keuangan (L1 = TERTINGGI / tak terbatas)
 *  - 4 level pengajuan (1 = tertinggi)
 *  - 5 grup COA induk + grup & akun kontrol PPSB/Kesantrian
 *  - unit bisnis "PPSB & Kesantrian" (U006) + default unit MutasiDompet
 *  - struktur organisasi (Bagian) contoh
 *  - rantai persetujuan default (BUDGET-STD, BAYAR-STD)
 *  - 1 pengguna admin (admin / admin123, level tertinggi)
 *  - pengaturan perusahaan default (singleton id=1)
 *
 * Idempotent: memakai updateOrCreate sehingga aman dijalankan berulang;
 * data yang mungkin sudah disesuaikan admin TIDAK ditimpa (values kosong).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLevels();
        $this->seedLevelPengajuan();
        $this->seedCoa();
        $this->seedUnitBisnis();
        $this->seedBagian();
        $this->seedApprovalFlows();
        $this->seedJalurPendaftaran();
        $this->seedAdmin();
        $this->seedCompanySettings();

        $this->command?->info('Seed selesai. Login awal: admin / admin123');
    }

    /**
     * Level otorisasi KEUANGAN — batas nominal transaksi internal.
     * L1 = TERTINGGI (Direktur, tak terbatas). max_transaksi null = unlimited.
     */
    private function seedLevels(): void
    {
        $levels = [
            ['kode_level' => 'L1', 'nama_level' => 'Direktur', 'max_transaksi' => null],
            ['kode_level' => 'L2', 'nama_level' => 'Manajer', 'max_transaksi' => '100000000'],
            ['kode_level' => 'L3', 'nama_level' => 'Supervisor', 'max_transaksi' => '25000000'],
            ['kode_level' => 'L4', 'nama_level' => 'Staff', 'max_transaksi' => '5000000'],
        ];
        foreach ($levels as $lv) {
            Level::updateOrCreate(['kode_level' => $lv['kode_level']], $lv);
        }
    }

    /**
     * Level otorisasi PENGAJUAN — peringkat pada rantai Pengajuan Pembayaran.
     * 1 = tertinggi. Nama bebas diubah admin; peringkat tetap 1–4.
     */
    private function seedLevelPengajuan(): void
    {
        $rows = [
            ['peringkat' => 1, 'nama' => 'Ketua Yayasan', 'keterangan' => 'Peringkat tertinggi. Hanya ikut rantai pembayaran bila pengajuan menembus anggaran.'],
            ['peringkat' => 2, 'nama' => 'Mudir Umum', 'keterangan' => 'Persetujuan wajib untuk seluruh pengajuan pembayaran.'],
            ['peringkat' => 3, 'nama' => 'Mudir Bagian', 'keterangan' => 'Persetujuan wajib, terbatas pada pengajuan dari bagiannya sendiri.'],
            ['peringkat' => 4, 'nama' => 'Staff', 'keterangan' => 'Pemohon. Hanya peringkat ini yang boleh membuat pengajuan pembayaran.'],
        ];
        foreach ($rows as $lp) {
            LevelPengajuan::updateOrCreate(['peringkat' => $lp['peringkat']], $lp);
        }
    }

    /**
     * Chart of Account: 5 grup induk (level 1) + grup PPSB (level 2 & 3) +
     * akun kontrol yang dipakai modul PPSB & Kesantrian.
     *
     * Akun titipan WAJIB ada (hardcoded di DompetPolicy):
     *   2.1.01.009 Titipan Dompet Wali, 2.1.01.006 Titipan Uang Saku,
     *   2.1.01.007 Titipan Tabungan Santri.
     */
    private function seedCoa(): void
    {
        // Grup level 1 (kelompok utama 1-5)
        $groups = [
            ['kode_grup' => '1', 'nama_grup' => 'Aset', 'kode_induk' => null, 'level' => 1],
            ['kode_grup' => '2', 'nama_grup' => 'Liabilitas', 'kode_induk' => null, 'level' => 1],
            ['kode_grup' => '3', 'nama_grup' => 'Ekuitas', 'kode_induk' => null, 'level' => 1],
            ['kode_grup' => '4', 'nama_grup' => 'Pendapatan', 'kode_induk' => null, 'level' => 1],
            ['kode_grup' => '5', 'nama_grup' => 'Beban', 'kode_induk' => null, 'level' => 1],
        ];

        // Grup level 2 & 3 penampung akun PPSB (urutan induk dulu untuk FK).
        $grupPpsb = [
            ['kode_grup' => '1.1', 'nama_grup' => 'Aset Lancar', 'kode_induk' => '1', 'level' => 2],
            ['kode_grup' => '1.1.01', 'nama_grup' => 'Kas', 'kode_induk' => '1.1', 'level' => 3],
            ['kode_grup' => '1.1.02', 'nama_grup' => 'Piutang Pendidikan', 'kode_induk' => '1.1', 'level' => 3],
            ['kode_grup' => '2.1', 'nama_grup' => 'Jangka Pendek', 'kode_induk' => '2', 'level' => 2],
            ['kode_grup' => '2.1.01', 'nama_grup' => 'Hutang/Kewajiban Jangka Pendek', 'kode_induk' => '2.1', 'level' => 3],
            ['kode_grup' => '2.1.02', 'nama_grup' => 'Pendapatan Diterima Dimuka', 'kode_induk' => '2.1', 'level' => 3],
            ['kode_grup' => '4.1', 'nama_grup' => 'Unit Pendidikan', 'kode_induk' => '4', 'level' => 2],
            ['kode_grup' => '4.1.01', 'nama_grup' => 'Pendidikan Dasar dan Menengah', 'kode_induk' => '4.1', 'level' => 3],
            // Beban (kelompok 5) — wajib ada agar modul Anggaran bisa dipakai.
            ['kode_grup' => '5.1', 'nama_grup' => 'Beban Operasional', 'kode_induk' => '5', 'level' => 2],
            ['kode_grup' => '5.1.01', 'nama_grup' => 'Beban Umum & Administrasi', 'kode_induk' => '5.1', 'level' => 3],
        ];

        foreach (array_merge($groups, $grupPpsb) as $g) {
            CoaGroup::updateOrCreate(['kode_grup' => $g['kode_grup']], $g);
        }

        $akun = [
            // Kas & bank
            ['kode_coa' => '1.1.01.001', 'nama_coa' => 'Kas Ditangan', 'kode_grup' => '1.1.01', 'jenis_saldo' => 'debet'],
            ['kode_coa' => '1.1.01.002', 'nama_coa' => 'Bank', 'kode_grup' => '1.1.01', 'jenis_saldo' => 'debet'],
            ['kode_coa' => '1.1.01.003', 'nama_coa' => 'Kas Xendit Settlement', 'kode_grup' => '1.1.01', 'jenis_saldo' => 'debet'],
            // Piutang santri
            ['kode_coa' => '1.1.02.001', 'nama_coa' => 'Piutang SPP', 'kode_grup' => '1.1.02', 'jenis_saldo' => 'debet'],
            ['kode_coa' => '1.1.02.003', 'nama_coa' => 'Piutang Uang Pangkal', 'kode_grup' => '1.1.02', 'jenis_saldo' => 'debet'],
            // Titipan (wadi'ah) — LIABILITAS
            ['kode_coa' => '2.1.01.006', 'nama_coa' => 'Titipan Uang Saku', 'kode_grup' => '2.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '2.1.01.007', 'nama_coa' => 'Titipan Tabungan Santri', 'kode_grup' => '2.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '2.1.01.009', 'nama_coa' => 'Titipan Dompet Wali', 'kode_grup' => '2.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '2.1.01.010', 'nama_coa' => 'Titipan Biaya Admin Xendit', 'kode_grup' => '2.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '2.1.01.011', 'nama_coa' => 'Titipan Kantin Pihak Ketiga', 'kode_grup' => '2.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '2.1.02.001', 'nama_coa' => 'Pendapatan Diterima Dimuka', 'kode_grup' => '2.1.02', 'jenis_saldo' => 'kredit'],
            // Pendapatan
            ['kode_coa' => '4.1.01.001', 'nama_coa' => 'Pendapatan Registrasi', 'kode_grup' => '4.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '4.1.01.002', 'nama_coa' => 'Pendapatan Uang Pangkal', 'kode_grup' => '4.1.01', 'jenis_saldo' => 'kredit'],
            ['kode_coa' => '4.1.01.003', 'nama_coa' => 'Pendapatan SPP', 'kode_grup' => '4.1.01', 'jenis_saldo' => 'kredit'],
            // Beban — dasar modul Anggaran (hanya kelompok 5 yang bisa dianggarkan).
            ['kode_coa' => '5.1.01.001', 'nama_coa' => 'Beban Gaji & Tunjangan', 'kode_grup' => '5.1.01', 'jenis_saldo' => 'debet'],
            ['kode_coa' => '5.1.01.002', 'nama_coa' => 'Beban Listrik & Air', 'kode_grup' => '5.1.01', 'jenis_saldo' => 'debet'],
            ['kode_coa' => '5.1.01.003', 'nama_coa' => 'Beban ATK & Perlengkapan', 'kode_grup' => '5.1.01', 'jenis_saldo' => 'debet'],
        ];
        foreach ($akun as $a) {
            CoaDetail::updateOrCreate(['kode_coa' => $a['kode_coa']], $a);
        }
    }

    /**
     * Satu unit bisnis untuk seluruh PPSB & Kesantrian (U006) + default unit
     * untuk jurnal mutasi dompet (yang tak membawa unit sendiri).
     */
    private function seedUnitBisnis(): void
    {
        BusinessUnit::updateOrCreate(
            ['kode_unit' => 'U006'],
            ['kode_unit' => 'U006', 'nama_unit' => 'PPSB & Kesantrian'],
        );

        UnitDefault::updateOrCreate(
            ['sumber_modul' => 'MutasiDompet'],
            [
                'sumber_modul' => 'MutasiDompet',
                'kode_unit' => 'U006',
                'keterangan' => "Dompet & tabungan santri (wadi'ah)",
            ],
        );
    }

    /**
     * Struktur organisasi (Bagian) contoh — Yayasan → Bidang → Bagian.
     * Urutan penting: induk dibuat lebih dulu (FK kode_induk).
     */
    private function seedBagian(): void
    {
        $bagian = [
            ['kode_bagian' => 'YYS', 'nama_bagian' => 'Yayasan AL Wafi', 'kode_induk' => null, 'level' => 1],

            ['kode_bagian' => 'BID-PEND', 'nama_bagian' => 'Bidang Pendidikan', 'kode_induk' => 'YYS', 'level' => 2],
            ['kode_bagian' => 'BID-ADM', 'nama_bagian' => 'Bidang Administrasi & Keuangan', 'kode_induk' => 'YYS', 'level' => 2],
            ['kode_bagian' => 'BID-SAR', 'nama_bagian' => 'Bidang Sarana & Prasarana', 'kode_induk' => 'YYS', 'level' => 2],

            ['kode_bagian' => 'KUR', 'nama_bagian' => 'Bagian Kurikulum', 'kode_induk' => 'BID-PEND', 'level' => 3],
            ['kode_bagian' => 'KES', 'nama_bagian' => 'Bagian Kesantrian', 'kode_induk' => 'BID-PEND', 'level' => 3],
            ['kode_bagian' => 'KEU', 'nama_bagian' => 'Bagian Keuangan', 'kode_induk' => 'BID-ADM', 'level' => 3],
            ['kode_bagian' => 'TU', 'nama_bagian' => 'Bagian Tata Usaha', 'kode_induk' => 'BID-ADM', 'level' => 3],
            ['kode_bagian' => 'SAR', 'nama_bagian' => 'Bagian Sarana & Prasarana', 'kode_induk' => 'BID-SAR', 'level' => 3],
            ['kode_bagian' => 'RT', 'nama_bagian' => 'Bagian Rumah Tangga', 'kode_induk' => 'BID-SAR', 'level' => 3],
        ];
        foreach ($bagian as $b) {
            Bagian::updateOrCreate(['kode_bagian' => $b['kode_bagian']], $b);
        }
    }

    /**
     * Rantai persetujuan default. Digerakkan PERINGKAT + satu tahap khusus
     * eskalasi overbudget. Tiap tahap mengisi TEPAT SATU peringkat/fungsi.
     */
    private function seedApprovalFlows(): void
    {
        $flows = [
            [
                'kode_flow' => 'BUDGET-STD',
                'nama_flow' => 'Pengajuan Anggaran (standar)',
                'jenis_dokumen' => 'Budget',
                'steps' => [
                    ['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'fungsi' => null, 'scope' => 'bagian', 'syarat' => null],
                    ['urutan' => 2, 'nama_tahap' => 'Mudir Umum', 'peringkat' => 2, 'fungsi' => null, 'scope' => 'yayasan', 'syarat' => null],
                    ['urutan' => 3, 'nama_tahap' => 'Ketua Yayasan', 'peringkat' => 1, 'fungsi' => null, 'scope' => 'yayasan', 'syarat' => null],
                ],
            ],
            [
                'kode_flow' => 'BAYAR-STD',
                'nama_flow' => 'Pengajuan Pembayaran (standar)',
                'jenis_dokumen' => 'PengajuanPembayaran',
                'steps' => [
                    ['urutan' => 1, 'nama_tahap' => 'Mudir Bagian', 'peringkat' => 3, 'fungsi' => null, 'scope' => 'bagian', 'syarat' => null],
                    ['urutan' => 2, 'nama_tahap' => 'Mudir Umum', 'peringkat' => 2, 'fungsi' => null, 'scope' => 'yayasan', 'syarat' => null],
                    // §4.c — tahap khusus: hanya aktif bila menembus anggaran.
                    ['urutan' => 3, 'nama_tahap' => 'Ketua Yayasan (eskalasi overbudget)', 'peringkat' => 1, 'fungsi' => null, 'scope' => 'yayasan', 'syarat' => 'overbudget'],
                ],
            ],
        ];

        foreach ($flows as $f) {
            $steps = $f['steps'];
            unset($f['steps']);

            ApprovalFlow::updateOrCreate(['kode_flow' => $f['kode_flow']], $f);

            foreach ($steps as $s) {
                ApprovalStep::updateOrCreate(
                    ['kode_flow' => $f['kode_flow'], 'urutan' => $s['urutan']],
                    array_merge($s, ['kode_flow' => $f['kode_flow']]),
                );
            }
        }
    }

    /**
     * Jalur pendaftaran default (reguler/tahfizh/beasiswa) — master terkelola.
     * `reguler` adalah jalur bawaan (default nilai santri.jalur).
     */
    private function seedJalurPendaftaran(): void
    {
        $jalur = [
            ['kode' => 'reguler', 'nama' => 'Reguler', 'keterangan' => 'Jalur pendaftaran umum.'],
            ['kode' => 'tahfizh', 'nama' => 'Tahfizh', 'keterangan' => 'Jalur program tahfizh Al-Qur\'an.'],
            ['kode' => 'beasiswa', 'nama' => 'Beasiswa', 'keterangan' => 'Jalur beasiswa / keringanan.'],
        ];
        foreach ($jalur as $j) {
            \App\Models\JalurPendaftaran::updateOrCreate(['kode' => $j['kode']], $j);
        }
    }

    /**
     * Pengguna admin: melewati matriks hak akses modul (is_admin), TIDAK ikut
     * rantai pengajuan (peringkat_pengajuan null). Login: admin / admin123.
     */
    private function seedAdmin(): void
    {
        User::updateOrCreate(
            ['username' => 'admin'],
            [
                'nama' => 'Administrator',
                'jabatan' => 'Direktur',
                'password_hash' => Hash::make('admin123'),
                'kode_level' => 'L1',
                'is_admin' => true,
            ],
        );
    }

    /**
     * Pengaturan perusahaan (singleton id=1). periode_awal_pembukuan = 1 Jan
     * tahun berjalan; bulan_awal_anggaran = Januari.
     */
    private function seedCompanySettings(): void
    {
        $tahun = now()->year;
        CompanySettings::updateOrCreate(
            ['id' => 1],
            [
                'id' => 1,
                'nama_perusahaan' => 'AL Wafi Islamic Boarding School',
                'jenis_perusahaan' => 'Yayasan',
                'mata_uang' => 'IDR',
                'periode_awal_pembukuan' => "{$tahun}-01-01",
                'bulan_awal_anggaran' => 1,
            ],
        );
    }
}
