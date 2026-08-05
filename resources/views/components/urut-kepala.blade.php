{{--
  Sel header kolom pegangan urutan. Selalu dirender — juga untuk pengguna tanpa
  hak ubah — agar jumlah kolom (dan indeks <x-fcol :col="…">) tetap sama.
  Pasangannya di baris filter: <x-fcol type="blank" />.
--}}
<th class="w-12 px-2 py-3"><span class="sr-only">Urutan</span></th>
