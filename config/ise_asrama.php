<?php

/*
 * JENIS KEGIATAN ASRAMA ISE → nama Indonesia & kelompok (keputusan user 15 Nov 2026), dari GET /histori/status-kamus.
 * Kunci = attendance_type_id ISE. Kelompok:
 *   absen      → menu Absensi, tab Asrama (shalat, dzikir, mandi, makan, absen pagi/malam)
 *   penilaian  → menu Penilaian Asrama (nilai A–D / ujian). ISE memetakan nilai ke enum kehadiran secara KELIRU
 *                (B→excused, C→late, D→absent), jadi nilai ini TIDAK boleh dibaca sebagai kehadiran.
 *   perizinan  → kunjungan/kepulangan (check in/out): tetap di tab Asrama, label apa adanya, warna netral
 *                (enum ISE-nya juga tak bermakna kehadiran; perizinan resmi tetap modul ERP).
 * Jenis baru yang belum terdaftar = absen dengan nama asli ISE.
 */
return [
    'kegiatan' => [
        1 => ['Absensi Malam', 'absen'],
        2 => ['Absen Pagi', 'absen'],
        3 => ['Penilaian Ranjang', 'penilaian'],
        4 => ['Shalat Tahajjud', 'absen'],
        5 => ['Puasa Sunnah', 'absen'],
        6 => ['Absensi Pagi', 'absen'],
        7 => ['Absensi Malam', 'absen'],
        8 => ['Dzikir Pagi', 'absen'],
        9 => ['Setoran Kosakata', 'absen'],
        10 => ['Dzikir Petang', 'absen'],
        11 => ['Mandi Pagi', 'absen'],
        12 => ['Mandi Sore', 'absen'],
        13 => ['Kebersihan Ranjang', 'penilaian'],
        14 => ['Kebersihan Lemari & Loker', 'penilaian'],
        15 => ['Absensi Sharing Time', 'absen'],
        16 => ['Adab Berpakaian', 'penilaian'],
        17 => ['Adab kepada Guru & yang Lebih Tua', 'penilaian'],
        18 => ['Adab kepada Teman', 'penilaian'],
        19 => ['Evaluasi Kedisiplinan', 'penilaian'],
        20 => ['Shalat Subuh Berjamaah', 'absen'],
        21 => ['Shalat Dzuhur Berjamaah', 'absen'],
        22 => ['Shalat Ashar Berjamaah', 'absen'],
        23 => ['Shalat Maghrib Berjamaah', 'absen'],
        24 => ['Shalat Isya Berjamaah', 'absen'],
        25 => ['Ujian Lisan Bahasa Asing', 'penilaian'],
        26 => ['Ujian Bulanan Bahasa Asing', 'penilaian'],
        27 => ['Kunjungan Santri', 'perizinan'],
        28 => ['Pulang Bulanan', 'perizinan'],
        29 => ['Izin Keluar Lingkungan', 'perizinan'],
        30 => ['Libur Semester (Pulang)', 'perizinan'],
        31 => ['Libur Semester (Kembali ke Pondok)', 'perizinan'],
        32 => ['Kedatangan setelah Libur Idul Fitri', 'perizinan'],
        34 => ['Absensi Sarapan', 'absen'],
        35 => ['Absensi Makan Malam', 'absen'],
        36 => ['Absensi Makan Siang', 'absen'],
        37 => ['Shalat Qabliyah Subuh', 'absen'],
        38 => ['Shalat Qabliyah Dzuhur', 'absen'],
        39 => ["Shalat Ba'diyah Dzuhur", 'absen'],
        40 => ["Shalat Ba'diyah Maghrib", 'absen'],
        41 => ["Shalat Ba'diyah Isya", 'absen'],
    ],

    // Label status ISE (huruf kecil) → Indonesia. Yang tak terdaftar ditampilkan apa adanya.
    'status' => [
        'present' => 'Hadir', 'late' => 'Terlambat', 'absent' => 'Tidak hadir', 'excused' => 'Izin',
        'telat banget' => 'Sangat terlambat', 'tidak hadir' => 'Tidak hadir',
        'check out' => 'Keluar', 'check in tepat waktu' => 'Kembali tepat waktu', 'check in terlambat' => 'Kembali terlambat',
        'dijemput orang tua' => 'Dijemput orang tua', 'dijemput taksi/ojol' => 'Dijemput taksi/ojol', 'pulang sendiri' => 'Pulang sendiri',
        'tidak pulang' => 'Tidak pulang', 'datang tepat waktu' => 'Datang tepat waktu', 'izin terlambat' => 'Izin terlambat',
    ],

    // Status rinci yang kategorinya tak bisa diambil dari enum ISE (ISE memetakannya keliru).
    'kategori_tambahan' => ['telat banget' => 'terlambat', 'tidak hadir' => 'tidak_hadir'],
];
