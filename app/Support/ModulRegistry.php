<?php

namespace App\Support;

/**
 * REGISTRI MODUL — sumber kebenaran hak akses per modul (port modul-registry.ts).
 *
 * `kode` sama dengan segmen pertama rute web. Modul yang TIDAK terdaftar di sini
 * DITOLAK oleh middleware hak akses (deny by default). Registri ada di KODE
 * (bukan database) karena rutenya memang kode.
 */
final class ModulRegistry
{
    /**
     * @var list<array{kode:string,nama:string,grup:string,sub?:string}>
     */
    public const MODUL = [
        // Dua tab pada satu halaman Dashboard, haknya terpisah: panitia PPSB bisa
        // diberi 'dashboard-ppsb' saja tanpa melihat angka keuangan.
        ['kode' => 'dashboard', 'nama' => 'Dashboard — tab Keuangan', 'grup' => 'UMUM'],
        ['kode' => 'dashboard-ppsb', 'nama' => 'Dashboard — tab PPSB', 'grup' => 'UMUM'],
        // Dipisah dari tab Keuangan supaya mudir/kepala bagian bisa memantau
        // anggaran & pengajuannya tanpa ikut melihat kas dan laba rugi.
        ['kode' => 'dashboard-anggaran', 'nama' => 'Dashboard — tab Anggaran & Pengajuan', 'grup' => 'UMUM'],
        // Dipisah dari tab Keuangan: bagian kesantrian perlu melihat keadaan
        // piutang santri tanpa ikut melihat kas, hutang, dan laba rugi.
        ['kode' => 'dashboard-kesantrian', 'nama' => 'Dashboard — tab Tagihan Santri Aktif', 'grup' => 'UMUM'],

        // ---- Setting Awal ----
        // Sub-nya mengikuti sidebar (lihat Navigation::SUB_ORDER): Setting Umum =
        // lembaga & orangnya, Setting Biaya = besaran yang ditagihkan.
        ['kode' => 'company-settings', 'nama' => 'Pengaturan Perusahaan', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],
        ['kode' => 'bagian', 'nama' => 'Bagian / Struktur Organisasi', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],
        ['kode' => 'users', 'nama' => 'Pengguna', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],
        ['kode' => 'levels', 'nama' => 'Level Otorisasi Keuangan', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],
        ['kode' => 'level-pengajuan', 'nama' => 'Level Pengajuan', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],
        // 'hak-akses' TIDAK didaftarkan di sini: ia adminOnly & masuk MODUL_BEBAS,
        // jadi tak punya baris di matriks walau menunya ada di sub yang sama.
        ['kode' => 'karyawan', 'nama' => 'Karyawan', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],
        // Alat pindahan, bukan pemasukan harian. 'buat' = boleh menulis hasil
        // impor; sebaiknya dicabut lagi setelah migrasi selesai.
        ['kode' => 'impor-data-awal', 'nama' => 'Impor Data Awal (pindahan sistem)', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Umum'],

        ['kode' => 'jenjang', 'nama' => 'Jenjang Pendidikan', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Biaya'],
        ['kode' => 'tipe-biaya', 'nama' => 'Tipe Biaya (perilaku alur biaya)', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Biaya'],
        ['kode' => 'jenis-biaya', 'nama' => 'Jenis Biaya (akun & unit tiap biaya)', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Biaya'],
        // Dipisah dari jenis-biaya: yang boleh mengubah BESARAN tarif belum tentu
        // sama dengan yang boleh mengubah pemetaan akunnya.
        ['kode' => 'tarif', 'nama' => 'Tarif (besaran biaya per T.A / jenjang / jalur)', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Biaya'],
        ['kode' => 'reminder-tagihan', 'nama' => 'Reminder Tagihan Jatuh Tempo', 'grup' => 'SETTING AWAL', 'sub' => 'Setting Biaya'],

        // ---- Anggaran ----
        // 'buat' pada modul ini = boleh MENGAJUKAN anggaran lewat rantai approval
        // (menulis langsung ke tabel anggaran tetap khusus admin).
        ['kode' => 'budget', 'nama' => 'Anggaran (Input, Pengajuan & Realisasi)', 'grup' => 'ANGGARAN'],

        // ---- Pengajuan Pembayaran ----
        ['kode' => 'pengajuan-pembayaran', 'nama' => 'Pengajuan Pembayaran', 'grup' => 'PENGAJUAN PEMBAYARAN', 'sub' => 'Pengajuan'],

        // ---- Keuangan ----
        ['kode' => 'vendor-types', 'nama' => 'Jenis Vendor', 'grup' => 'KEUANGAN', 'sub' => 'Vendor & Customer'],
        ['kode' => 'vendors', 'nama' => 'Vendor', 'grup' => 'KEUANGAN', 'sub' => 'Vendor & Customer'],
        ['kode' => 'customer-types', 'nama' => 'Jenis Customer', 'grup' => 'KEUANGAN', 'sub' => 'Vendor & Customer'],
        ['kode' => 'customers', 'nama' => 'Customer', 'grup' => 'KEUANGAN', 'sub' => 'Vendor & Customer'],

        ['kode' => 'inventory', 'nama' => 'Persediaan', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        ['kode' => 'lokasi-persediaan', 'nama' => 'Lokasi Persediaan (gudang / toko)', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        ['kode' => 'kategori-barang', 'nama' => 'Kategori Barang', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        ['kode' => 'merk-barang', 'nama' => 'Merk Barang', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        // buat = kirim, ubah = terima di tujuan, hapus = batalkan kiriman yang belum diterima.
        ['kode' => 'transfer-persediaan', 'nama' => 'Transfer Antar Lokasi (kirim / terima / batal)', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        // buat = membuat lembar, menghitung, mengajukan; hapus = membatalkan.
        ['kode' => 'opname-persediaan', 'nama' => 'Stok Opname (hitung & ajukan)', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        // TANPA MENU sendiri: tombolnya ada di halaman transfer & opname. Dipisah supaya
        // yang menghitung/menerima bukan orang yang memutus selisihnya (dua tangan).
        ['kode' => 'persetujuan-persediaan', 'nama' => 'Persetujuan Persediaan (putus selisih transfer, setujui opname)', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        ['kode' => 'asset-categories', 'nama' => 'Kategori Aset', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],
        ['kode' => 'assets', 'nama' => 'Aset Tetap', 'grup' => 'KEUANGAN', 'sub' => 'Aset & Persediaan'],

        ['kode' => 'purchase-orders', 'nama' => 'Purchase Order', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        // Layar kasir Wafi Mart (/pos): `lihat` = membuka layar & riwayat struk,
        // `buat` = buka shift & berjualan. Pembatalan struk & penerimaan setoran
        // sengaja di modul lain — kasir tak memutus atas uangnya sendiri.
        ['kode' => 'pos', 'nama' => 'Kasir Toko / POS (buka shift & berjualan)', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        // Pengawas kasir: `lihat` = rekap & rincian shift; `ubah` = batalkan struk,
        // tutup shift kasir yang lupa, terima setoran laci ke kas keuangan.
        ['kode' => 'shift-kasir', 'nama' => 'Shift Kasir & Setoran (rekap, batal struk, terima setoran)', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        // Keuangan: buat = mencairkan belanja kartu santri ke kas toko, hapus = membatalkan.
        ['kode' => 'pencairan-kartu', 'nama' => 'Pencairan Belanja Kartu ke Toko (mingguan)', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        // Keuangan: buat = membuat sesi, ubah = jadwal/peserta/saklar buka + ambil dana, distribusi,
        // selesaikan; hapus = membatalkan sesi / penyerahan yang keliru.
        // MEMUTUS pengajuan wali sengaja di modul lain (`penarikan-tunai`, kesantrian).
        ['kode' => 'sesi-tarik-tunai', 'nama' => 'Sesi Penarikan Tunai Kegiatan (outbound/rihlah)', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        // Pekerjaan gudang/toko: `buat` = mencatat barang datang, `hapus` = membatalkan.
        ['kode' => 'penerimaan-barang', 'nama' => 'Penerimaan Barang (stok masuk dari vendor)', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        // Perintah Pembayaran mendahului Kas Keluar dalam urutan kerja: rencana
        // dulu, realisasi kemudian. Urutan menu mengikuti urutan itu.
        ['kode' => 'perintah-pembayaran', 'nama' => 'Perintah Pembayaran', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'cash-in', 'nama' => 'Kas Masuk', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'cash-out', 'nama' => 'Kas Keluar', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'invoices', 'nama' => 'Invoice Vendor', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'operational-advance', 'nama' => 'Uang Muka Operasional', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'advance-settlement', 'nama' => 'Penyelesaian Uang Muka', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'accrue', 'nama' => 'Accrue & Prepaid', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'book-transfer', 'nama' => 'Pindah Buku', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'bank-loans', 'nama' => 'Pembiayaan Bank', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'pinjaman-karyawan', 'nama' => 'Pinjaman Karyawan', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'bank-reconciliation', 'nama' => 'Rekonsiliasi Bank', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],
        ['kode' => 'journal', 'nama' => 'Jurnal Umum', 'grup' => 'KEUANGAN', 'sub' => 'Transaksi'],

        ['kode' => 'reports', 'nama' => 'Laporan (Neraca, Laba Rugi, … & Export Data)', 'grup' => 'KEUANGAN', 'sub' => 'Laporan'],
        // Dipisah dari `reports`: pengelola toko perlu melihat laku/tidak lakunya barang
        // tanpa ikut membuka neraca & laba rugi yayasan.
        ['kode' => 'laporan-toko', 'nama' => 'Laporan Toko (terlaris, margin, perlu dibeli, tidak laku)', 'grup' => 'KEUANGAN', 'sub' => 'Laporan'],

        ['kode' => 'outstanding', 'nama' => 'Kontrol Outstanding (Aging, Uang Muka, Rekap)', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'coa-detail', 'nama' => 'Chart of Account', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'business-units', 'nama' => 'Unit Bisnis', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'unit-default', 'nama' => 'Default Unit Bisnis', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'bank-accounts', 'nama' => 'Kas & Rekening', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'opening-balance', 'nama' => 'Saldo Awal', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'dana', 'nama' => 'Dana Terikat (wakaf, donasi, beasiswa, bantuan)', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        ['kode' => 'period-close', 'nama' => 'Tutup Buku Periode', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],
        // Dua tangan membuka kembali periode yang sudah ditutup:
        //   `buat` = mengajukan  → admin keuangan
        //   `ubah` = memutuskan  → direktur keuangan
        // Sengaja modul TERPISAH dari `period-close`: yang boleh menutup buku
        // (pekerjaan rutin akhir bulan) bukan orang yang sama dengan yang boleh
        // membatalkannya.
        ['kode' => 'buka-periode', 'nama' => 'Buka Periode (ajukan = admin keuangan, setujui = direktur keuangan)', 'grup' => 'KEUANGAN', 'sub' => 'Kontrol'],

        // ---- Data Siswa/Santri ----
        // Sekelompok dengan grup sidebar bernama sama. Satu modul `santri`
        // menggerbangi SELURUH tahapnya (calon, siap aktivasi, mundur, aktif,
        // alumni, keluar): tujuh menu di sidebar, satu baris di matriks — memang
        // satu daftar orang yang sama dengan penyaring berbeda.
        ['kode' => 'wali', 'nama' => 'Wali / Keluarga Santri', 'grup' => 'DATA SISWA/SANTRI'],
        ['kode' => 'santri', 'nama' => 'Calon Santri & Santri (semua tahap)', 'grup' => 'DATA SISWA/SANTRI'],
        // TANPA MENU sendiri: dibuka dari halaman santri. Ikut ke grup ini karena
        // isinya berkas orang yang sama, bukan urusan pendaftaran.
        ['kode' => 'dokumen-santri', 'nama' => 'Berkas Santri (KTP, akta, KK, med check)', 'grup' => 'DATA SISWA/SANTRI'],
        // Kartu RFID belanja Wafi Mart: buat = pasang/ganti kartu, ubah = blokir & buka blokir.
        ['kode' => 'kartu-santri', 'nama' => 'Kartu Santri (RFID belanja Wafi Mart)', 'grup' => 'DATA SISWA/SANTRI'],

        // ---- PPSB ----
        ['kode' => 'tahun-ajaran', 'nama' => 'Tahun Ajaran', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        // Urutan mengikuti sidebar: jalur dulu, baru jenis biaya yang tarifnya
        // dibedakan per jalur.
        ['kode' => 'jalur-pendaftaran', 'nama' => 'Jalur Pendaftaran', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        ['kode' => 'sumber-informasi', 'nama' => 'Sumber Informasi PPSB', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        // Modul sendiri, bukan menumpang PPSB: isinya mengikat wali seperti
        // perjanjian, jadi penulisnya perlu bisa dibatasi ke orang tertentu.
        ['kode' => 'surat-pernyataan', 'nama' => 'Surat Pernyataan Wali', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        // Satu modul untuk master gelombang DAN matriks potongannya: memisahkan
        // haknya hanya melahirkan keadaan aneh — boleh mengisi potongan tapi tak
        // boleh melihat gelombang yang dipotongnya.
        ['kode' => 'potongan-gelombang', 'nama' => 'Gelombang & Potongan Uang Pangkal', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        ['kode' => 'target-santri', 'nama' => 'Target Santri per Tahun Ajaran & Jenjang', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        ['kode' => 'termin-filter', 'nama' => 'Setting Filter Termin Jatuh Tempo', 'grup' => 'PPSB', 'sub' => 'Setting Awal'],
        // Turun ke Transaksi bersama menunya: ia mengatur uang, bukan master.
        ['kode' => 'angsuran-uang-pangkal', 'nama' => 'Angsuran Uang Pangkal (Termin & Reminder)', 'grup' => 'PPSB', 'sub' => 'Transaksi'],
        ['kode' => 'pembayaran-ppsb', 'nama' => 'Pembayaran Tagihan PPSB (registrasi & uang pangkal)', 'grup' => 'PPSB', 'sub' => 'Transaksi'],
        ['kode' => 'rekap-pembayaran', 'nama' => 'Rekap Pembayaran Santri', 'grup' => 'PPSB', 'sub' => 'Transaksi'],

        // ---- Kependidikan (kode modulnya tetap `…-kesantrian`, lihat Navigation) ----
        ['kode' => 'spp', 'nama' => 'Penagihan SPP (Penerbitan Tagihan, Prabayar, Auto-debet)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Administrasi'],
        ['kode' => 'tagihan-massal', 'nama' => 'Penagihan Daftar Ulang (massal)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Administrasi'],
        // Membuka LAYAR batch saja. Menyusun & merilis batch sebuah modul tetap
        // menuntut hak modul itu sendiri (spp / tagihan-massal / tagihan-lain),
        // ditegakkan di BatchTagihanController — lihat catatan di sana.
        ['kode' => 'batch-tagihan', 'nama' => 'Batch Tagihan (draft, otorisasi, rilis terjadwal)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Administrasi'],
        // 'buat' = boleh MENJALANKAN/MENERBITKAN; 'lihat' cukup untuk menyusun
        // pratinjau, sehingga petugas bisa memeriksa dulu tanpa bisa mengeksekusi.
        ['kode' => 'kenaikan-tingkat', 'nama' => 'Kenaikan Tingkat & Kelulusan', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Administrasi'],
        // 'buat' = boleh MENERBITKAN NIS; 'ubah' = boleh menyetel formatnya.
        // Dipisah: menyetel format menyentuh seluruh angkatan berikutnya.
        ['kode' => 'nis', 'nama' => 'NIS (format & penerbitan massal)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Administrasi'],

        ['kode' => 'pembayaran-kesantrian', 'nama' => 'Pembayaran SPP & Tagihan Lain', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Transaksi'],
        ['kode' => 'dompet', 'nama' => 'Dompet & Tabungan Santri', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Transaksi'],
        // Kesantrian: ubah = menyetujui (boleh nominal lebih kecil) / menolak pengajuan tarik tunai kegiatan.
        ['kode' => 'penarikan-tunai', 'nama' => 'Persetujuan Penarikan Tunai Kegiatan', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Transaksi'],
        // Grupnya sendiri sejak rancangan v2 — dua watak yang cara kerjanya
        // berbeda (laundry berbasis pemakaian, kegiatan berbasis kepesertaan)
        // tak lagi muat sebagai satu baris di antara menu Kependidikan.
        // KODE MODULNYA TETAP `tagihan-lain`: ia tersimpan di `hak_akses_modul`,
        // jadi menggantinya akan memutus hak yang sudah diberikan ke pengguna.
        ['kode' => 'tagihan-lain', 'nama' => 'Tagihan Lain-lain (laundry, ekskul, kegiatan)', 'grup' => 'TAGIHAN LAIN-LAIN', 'sub' => 'Transaksi'],
        // Hak petugas laundry. SENGAJA TERPISAH dari `tagihan-lain`: ia mencatat
        // timbangan tiap hari, dan tak pernah butuh wewenang menerbitkan tagihan
        // ke siapa pun. Penerbitan periodenya tetap menuntut hak `tagihan-lain`,
        // jadi satu layar melayani dua wewenang yang berbeda.
        ['kode' => 'setoran-laundry', 'nama' => 'Setoran Pemakaian (timbangan laundry harian)', 'grup' => 'TAGIHAN LAIN-LAIN', 'sub' => 'Transaksi'],
        // DUA matriks besaran, dua modul. Keduanya menetapkan uang, tapi atas
        // dasar yang berbeda — per jenjang bagi kegiatan berpeserta, per satuan
        // bagi layanan bersatuan — dan biasanya diurus orang yang berbeda pula.
        ['kode' => 'tarif-kepesertaan', 'nama' => 'Matriks Tarif Kegiatan (per jenjang)', 'grup' => 'TAGIHAN LAIN-LAIN', 'sub' => 'Setting Tagihan Lain Lain'],
        ['kode' => 'tarif-pemakaian', 'nama' => 'Matriks Tarif Layanan (per satuan & kuota)', 'grup' => 'TAGIHAN LAIN-LAIN', 'sub' => 'Setting Tagihan Lain Lain'],
        // TANPA MENU: dijalankan dari halaman santri, bukan layar tersendiri.
        // Wewenangnya sengaja terpisah dari modul tagihan mana pun — ia mengubah
        // PIUTANG YANG SUDAH DIBUKUKAN, jadi tak boleh ikut hak `ubah` biasa.
        // Diberikan hanya kepada kepala keuangan lewat matriks hak akses.
        ['kode' => 'koreksi-tagihan', 'nama' => 'Koreksi Nominal Tagihan', 'grup' => 'TANPA MENU'],
        // TANPA MENU: centangnya menempel di form input santri. 'buat' = boleh
        // MEMBEBASKAN biaya registrasi seorang calon. Dipisahkan dari hak `buat`
        // modul santri karena keduanya pekerjaan yang berbeda derajatnya:
        // memasukkan data anak itu tugas harian panitia, membatalkan pemasukan
        // bukan. Tanpa hak ini centangnya tidak muncul sama sekali.
        ['kode' => 'pembebasan-registrasi', 'nama' => 'Pembebasan Biaya Registrasi (per santri)', 'grup' => 'TANPA MENU'],
        // 'ubah' = boleh mengoreksi nominal tagihan yang salah ketik. Dipisahkan
        // dari modul `spp` (yang menerbitkan): memeriksa tunggakan adalah pekerjaan
        // harian, menerbitkan tagihan tidak.
        ['kode' => 'kebijakan-khusus', 'nama' => 'Kebijakan Khusus Santri (keringanan, potongan, beasiswa)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Kontrol'],
        ['kode' => 'outstanding-spp', 'nama' => 'Outstanding SPP (tunggakan & koreksi nominal)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Kontrol'],
        ['kode' => 'outstanding-lain', 'nama' => 'Outstanding Tagihan Lain (lain-lain & daftar ulang)', 'grup' => 'KEPENDIDIKAN', 'sub' => 'Kontrol'],

        // ---- Sistem ----
        // 'void-approvals' DIBUANG 2026-07-28: menunya menunjuk /void-approvals
        // yang tak punya rute (404), dan fiturnya memang belum diport — Void/
        // Posting/EditApprovalService sudah ada tapi TIDAK dipanggil controller
        // mana pun, jadi barisnya tak akan pernah tercipta. Service, model, dan
        // tabelnya sengaja DIPERTAHANKAN; daftarkan lagi di sini + Navigation.php
        // saat pemicunya (void/edit/posting di luar wewenang → antre persetujuan)
        // benar-benar disambungkan.

        // ---- Tanpa menu ----
        ['kode' => 'coa-groups', 'nama' => 'Grup COA', 'grup' => 'TANPA MENU'],
        ['kode' => 'edit-approvals', 'nama' => 'Persetujuan Edit', 'grup' => 'TANPA MENU'],
        ['kode' => 'posting-approvals', 'nama' => 'Persetujuan Posting', 'grup' => 'TANPA MENU'],
        // Kewenangan OTORISASI & PENUTUPAN perintah pembayaran. Diwakili modul
        // tersendiri karena sistem hak di sini hanya mengenal empat aksi
        // (lihat/buat/ubah/hapus) — tak ada aksi "otorisasi". Hak `ubah` di sini
        // berarti boleh mengotorisasi dan menutup; berikan hanya ke pejabatnya.
        ['kode' => 'otorisasi-pembayaran', 'nama' => 'Otorisasi Perintah Pembayaran', 'grup' => 'TANPA MENU'],
        // Akun mana yang mengurangi dana bebas dipakai. Mengubahnya mengubah
        // batas seluruh pembayaran — bukan pengaturan untuk semua orang.
        ['kode' => 'pengaturan-dana-bebas', 'nama' => 'Akun Pengurang Dana Bebas', 'grup' => 'TANPA MENU'],
    ];

    /**
     * Urutan tampil grup — sama dengan urutan sidebar.
     *
     * Grup yang TIDAK disebut di sini tetap ikut tampil, tapi melayang ke paling
     * atas: `array_search` mengembalikan false dan false-dikurangi-angka dihitung
     * sebagai 0 (lihat HakAksesController::modulTerurut). Itulah yang terjadi pada
     * TAGIHAN LAIN-LAIN sampai sekarang — barisnya nongol mendahului UMUM.
     * Tiap grup baru WAJIB didaftarkan di sini.
     */
    public const GRUP_ORDER = [
        'UMUM',
        'SETTING AWAL',
        'ANGGARAN',
        'PENGAJUAN PEMBAYARAN',
        'KEUANGAN',
        'DATA SISWA/SANTRI',
        'PPSB',
        'KEPENDIDIKAN',
        'TAGIHAN LAIN-LAIN',
        'SISTEM',
        'TANPA MENU',
    ];

    /**
     * Urutan sub-grup di dalam grup. Grup tanpa entri di sini (mis. DATA
     * SISWA/SANTRI) menampilkan modulnya sebagai satu deret tanpa sub.
     */
    public const SUB_ORDER = [
        'SETTING AWAL' => ['Setting Umum', 'Setting Biaya'],
        'PENGAJUAN PEMBAYARAN' => ['Pengajuan'],
        'KEUANGAN' => ['Vendor & Customer', 'Aset & Persediaan', 'Transaksi', 'Laporan', 'Kontrol'],
        'PPSB' => ['Setting Awal', 'Transaksi'],
        'KEPENDIDIKAN' => ['Administrasi', 'Transaksi', 'Kontrol'],
        'TAGIHAN LAIN-LAIN' => ['Setting Tagihan Lain Lain', 'Transaksi'],
    ];

    /**
     * Rute yang TIDAK digerbangi hak akses modul (wewenangnya di tempat lain).
     */
    public const MODUL_BEBAS = [
        'auth',
        'notification',
        'approval',
        'hak-akses',
        'referensi',
        'budget-realisasi',
    ];

    /**
     * Modul yang hidup di instans ini — matriks hak akses & menu memakai ini, bukan MODUL
     * mentah, supaya modul yang dimatikan (config/instans.php) tak bisa diberikan ke siapa pun.
     *
     * @return list<array{kode:string,nama:string,grup:string,sub?:string}>
     */
    public static function aktif(): array
    {
        return array_values(array_filter(self::MODUL, fn ($m) => Instans::modulHidup($m['kode'])));
    }

    /** @return array{kode:string,nama:string,grup:string,sub?:string}|null */
    public static function byKode(string $kode): ?array
    {
        foreach (self::MODUL as $m) {
            if ($m['kode'] === $kode) {
                return $m;
            }
        }

        return null;
    }

    public static function isBebas(string $kode): bool
    {
        return in_array($kode, self::MODUL_BEBAS, true);
    }
}
