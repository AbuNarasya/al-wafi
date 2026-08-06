{{--
  Lambang aplikasi. Berkasnya `public/ikon/logo.png`.

  Bila berkasnya belum ada, yang tampil kotak kuning berhuruf "A" seperti dulu —
  BUKAN gambar rusak. Halaman login adalah satu-satunya halaman yang dilihat
  orang yang belum bisa masuk, jadi ia tak boleh terlihat pecah hanya karena
  sebuah berkas gambar terlewat saat memasang.

  Ukuran & pembulatan sudutnya ditentukan pemanggil lewat `class` — nama kelas
  Tailwind wajib muncul di berkas Blade pemanggilnya, bukan dirakit di sini.
--}}
@if (file_exists(public_path('ikon/logo.png')))
    <img src="{{ asset('ikon/logo.png') }}" alt="Al Wafi ERP" {{ $attributes->class(['object-contain']) }}>
@else
    <div {{ $attributes->class(['flex items-center justify-center bg-accent font-bold text-brand-dark']) }}>A</div>
@endif
