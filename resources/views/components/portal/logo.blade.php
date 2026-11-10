{{--
  Logo Portal Wali "SANAD Wali" (10 Nov 2026) — TERPISAH dari logo ERP staf (<x-logo>).
    varian="lambang"  lambang tanpa tulisan, untuk ukuran kecil (kepala halaman)
    varian="lengkap"  lambang + tulisan SANAD, untuk halaman depan & masuk
  Berkasnya di public/ikon/wali-*.png (cabang /ikon/ di sw.js selalu menyegarkan gambar). Ganti gambar = naikkan ?v=.
  Ukuran & sudut ditentukan pemanggil lewat `class`.
--}}
@props(['varian' => 'lambang'])
<img src="{{ asset($varian === 'lengkap' ? 'ikon/wali-logo.png' : 'ikon/wali-lambang.png') }}?v=1" alt="SANAD Wali"
     {{ $attributes->class(['object-contain bg-white']) }}>
