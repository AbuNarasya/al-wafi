{{--
  Toolbar cari global + reset + penghitung untuk tabel ber-rowFilter.
  Pakai di dalam elemen ber-x-data="rowFilter". Contoh:
    <div x-data="rowFilter" x-cloak>
      <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar />
        <a href="…">+ Tambah</a>
      </div>
      …tabel…
    </div>
--}}
@props(['placeholder' => 'Cari…'])
<div class="flex flex-wrap items-center gap-2">
    <input type="text" data-role="global" @input="apply()" placeholder="{{ $placeholder }}"
           class="w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
    {{-- Hanya di layar sempit: baris filter per kolom dilipat ke balik tombol ini.
         Titik biru = ada filter yang sedang aktif, supaya hasil yang tersaring
         tak pernah terlihat seperti "datanya hilang". --}}
    <button type="button" x-show="adaKolom" @click="bukaFilter()" x-cloak
            class="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium md:hidden"
            :class="filterTampak ? 'bg-gray-100' : 'bg-white'">
        Filter
        <span x-show="hasFilter" class="h-1.5 w-1.5 rounded-full bg-brand"></span>
    </button>
    <button type="button" x-show="hasFilter" @click="reset()" x-cloak
            class="text-sm text-gray-500 hover:text-gray-700">Reset</button>
    <span class="text-xs text-gray-400"><span x-text="count"></span> data</span>
</div>
