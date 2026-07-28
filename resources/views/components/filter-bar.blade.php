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
    <button type="button" x-show="hasFilter" @click="reset()" x-cloak
            class="text-sm text-gray-500 hover:text-gray-700">Reset</button>
    <span class="text-xs text-gray-400"><span x-text="count"></span> data</span>
</div>
