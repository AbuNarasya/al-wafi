{{--
  Angka pekerjaan menunggu untuk satu menu sidebar. Tidak menampilkan apa pun
  bila kosong. Angkanya dari TugasSaya (keadaan nyata) — bukan dari baris
  notifikasi — sehingga bertahan sampai dokumennya diproses dan tak bisa
  dipadamkan hanya dengan membuka menunya.
--}}
@props(['url'])
@php $jumlah = \App\Support\TugasSaya::untukUrl($url); @endphp
@if ($jumlah > 0)
    <span class="flex h-4 min-w-4 shrink-0 items-center justify-center rounded-full bg-red-600 px-1 text-[10px] font-bold text-white"
          title="{{ $jumlah }} {{ \App\Support\TugasSaya::labelUrl($url) }}">{{ $jumlah > 99 ? '99+' : $jumlah }}</span>
@endif
