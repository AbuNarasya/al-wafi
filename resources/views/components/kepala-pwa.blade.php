{{--
  Penanda PWA — aplikasi bisa dipasang ke layar utama & terbuka tanpa bilah alamat.

  SATU komponen untuk SEMUA halaman, termasuk halaman masuk. Dulu penanda ini
  hanya ada di layout ber-sesi, dan itu merusak dua hal sekaligus di halaman
  masuk: Chrome tak menawarkan "Pasang" karena tak ada manifest yang tertaut,
  dan — jauh lebih parah — `meta[name="pwa"]` yang tak ada terbaca app.js
  sebagai "mati", sehingga halaman masuk MENCABUT pekerja layanan yang sudah
  terpasang. Setiap kali keluar akun atau sesi kedaluwarsa, pemasangannya
  dibongkar sendiri.

  `pwa` dibaca app.js — bila "mati", pekerja layanan yang sudah terpasang justru
  DICABUT saat halaman dimuat. Itu tombol darurat dari sisi server (env
  PWA_AKTIF=false lalu deploy), bukan sesuatu yang perlu disentuh satu per satu
  di ponsel staf.
--}}
{{-- Portal Wali memakai manifest SENDIRI (/manifest-wali.json, lingkup /wali/) supaya terpasang
     sebagai aplikasi terpisah "Al Wafi Wali" yang langsung membuka beranda wali — bukan dasbor staf. --}}
@props(['manifest' => null, 'judul' => null])
@php
    // Bawaan per instans (config/instans.php); Portal Wali tetap mengoper manifest & judulnya sendiri.
    $manifest ??= \App\Support\Instans::manifest();
    $judul ??= \App\Support\Instans::nama();
@endphp
<meta name="pwa" content="{{ config('app.pwa_aktif') ? 'aktif' : 'mati' }}">
<meta name="theme-color" content="#164a9e">
<link rel="manifest" href="{{ $manifest }}">
{{-- `?v=` = versi ikon: server mengirim /ikon/ dengan max-age 7 hari, jadi nama yang sama
     membuat HP memakai gambar lama. Naikkan bersama manifest*.json tiap ikon diganti. --}}
<link rel="apple-touch-icon" href="/ikon/apple-touch-icon.png?v=6">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $judul }}">
