{{--
  Bilah cari STANDAR untuk daftar BERPAGINASI (penyaringan dikerjakan server).
  Tampil & terasa sama dengan <x-filter-bar> milik halaman master, bedanya:
  filter dikirim sebagai query string sehingga tetap sahih lintas halaman.

  Mengetik langsung menyaring (lihat auto-filter di app.js) — TIDAK ada tombol
  "Cari"; menekan Enter tetap bekerja sebagai cadangan bila JavaScript mati.

  Pakai di dalam <form method="GET"> yang sama dengan baris <x-scol>:
    <form method="GET">
      <x-filter-server placeholder="Cari nomor…" :total="$rows->total()"
                       :reset="route('invoices.index')" :aktif="$adaFilter" />
      …tabel dengan baris <x-scol> di dalam <thead>…
    </form>
--}}
@props([
    'placeholder' => 'Cari…',
    'total' => null,
    'reset' => null,
    'aktif' => false,
    'name' => 'q',
    // id <form method="GET"> tujuan. Dipakai bila form TIDAK boleh membungkus
    // tabel — mis. halaman yang punya tombol POST di toolbar/baris tabel, sebab
    // form bersarang tidak sah di HTML dan merusak tombolnya.
    'form' => null,
])

<div class="flex flex-wrap items-center gap-2">
    <input type="text" name="{{ $name }}" value="{{ request($name) }}" placeholder="{{ $placeholder }}"
           @if ($form) form="{{ $form }}" @endif
           data-filter-auto autocomplete="off"
           class="w-64 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
    @if ($aktif && $reset)
        <a href="{{ $reset }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
    @endif
    @if ($total !== null)
        <span class="text-xs text-gray-400">{{ $total }} data</span>
    @endif
</div>
