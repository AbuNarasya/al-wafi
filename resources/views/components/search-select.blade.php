{{--
  Dropdown yang bisa dicari (ketik untuk memfilter), pengganti <select>.
  Nilai dikirim lewat <input type="hidden" name=...>.
    <x-search-select name="kode_coa" :options="$opts" :value="old('kode_coa')" placeholder="— pilih akun —" />
  $options boleh berupa [value => label] ATAU list [['v'=>..,'l'=>..], ...].
--}}
@props(['name', 'options' => [], 'value' => null, 'placeholder' => '— pilih —', 'required' => false])

@php
    // Normalisasi ke list [{v,l}].
    $opts = collect($options)->map(function ($item, $key) {
        if (is_array($item) && array_key_exists('v', $item)) {
            return ['v' => (string) $item['v'], 'l' => (string) $item['l']];
        }
        return ['v' => (string) $key, 'l' => (string) $item];
    })->values()->all();
    $val = (string) old($name, $value);
@endphp

<div x-data="searchSelect({ options: {{ \Illuminate\Support\Js::from($opts) }}, value: @js($val) })"
     class="relative" @click.outside="open = false" @keydown.escape="open = false">
    <input type="hidden" name="{{ $name }}" :value="value" @if ($required) data-required @endif>
    <button type="button" x-ref="btn" @click="buka()"
            {{ $attributes->merge(['class' => 'flex w-full items-center justify-between gap-2 rounded-lg border border-gray-400 bg-white px-3 py-2 text-left text-sm focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand']) }}>
        <span x-text="label() || @js($placeholder)" :class="value ? 'text-gray-800' : 'text-gray-400'" class="truncate"></span>
        <span class="shrink-0 text-gray-400">▾</span>
    </button>
    {{--
      Isian cari DI LUAR area gulir, bukan `sticky` di dalamnya.

      Dulu ia `sticky top-0` tetapi TANPA latar: sticky memang menahannya di
      tempat, namun baris pilihan yang lewat di belakangnya tetap terlihat
      menembus, sehingga tulisan "Cari…" bertindihan dengan nama santri begitu
      daftarnya digulir. Memberinya `bg-white` saja sudah menutup gejalanya, tapi
      masih menyisakan urusan z-index dengan baris yang datang sesudahnya di DOM.

      Dijadikan dua bagian — kepala tetap + badan bergulir — persoalannya hilang
      seluruhnya: tak ada yang perlu ditumpuk, jadi tak ada yang bisa tembus.
    --}}
    <div x-show="open" x-cloak :style="gaya"
         class="fixed z-50 flex max-h-60 flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-xl">
        <input x-ref="q" x-model="q" type="text" placeholder="Cari…"
               class="w-full shrink-0 border-0 border-b border-gray-200 bg-white px-3 py-2 text-sm focus:outline-none">
        {{-- `min-h-0` wajib: tanpa itu anak flex menolak menyusut di bawah tinggi
             isinya, dan `overflow-auto` tak pernah aktif — panelnya justru
             memanjang melewati `max-h-60`. --}}
        <div class="min-h-0 flex-1 overflow-auto">
            <template x-for="o in filtered()" :key="o.v">
                <div @click="pick(o.v)"
                     class="cursor-pointer truncate px-3 py-1.5 text-sm hover:bg-brand-soft"
                     :class="String(o.v) === String(value) ? 'bg-brand-soft font-medium text-brand' : 'text-gray-700'"
                     x-text="o.l"></div>
            </template>
            <div x-show="filtered().length === 0" class="px-3 py-2 text-sm text-gray-400">Tidak ada hasil.</div>
        </div>
    </div>
</div>
