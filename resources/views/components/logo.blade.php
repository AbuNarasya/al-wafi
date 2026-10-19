{{--
  Lambang aplikasi — berkasnya per instans (config/instans.php: Al Wafi `public/ikon/logo.png`,
  Wafi Mart `public/ikon-wafimart/logo.png`).

  Bila berkasnya belum ada, yang tampil kotak kuning berhuruf awal nama instans — BUKAN gambar
  rusak. Halaman login adalah satu-satunya halaman yang dilihat orang yang belum bisa masuk,
  jadi ia tak boleh terlihat pecah hanya karena sebuah berkas gambar terlewat saat memasang.

  Ukuran & pembulatan sudutnya ditentukan pemanggil lewat `class` — nama kelas
  Tailwind wajib muncul di berkas Blade pemanggilnya, bukan dirakit di sini.
--}}
@php $logo = \App\Support\Instans::logo(); @endphp
@if ($logo)
    <img src="{{ asset($logo) }}" alt="{{ \App\Support\Instans::nama() }}" {{ $attributes->class(['object-contain']) }}>
@else
    <div {{ $attributes->class(['flex items-center justify-center bg-accent font-bold text-brand-dark']) }}>{{ mb_substr(\App\Support\Instans::nama(), 0, 1) }}</div>
@endif
