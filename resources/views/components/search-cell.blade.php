{{--
  Dropdown searchable untuk sel dalam <template x-for> (form multi-baris).
  Panel dirender position:FIXED (koordinat dihitung dari tombol) agar TIDAK
  ter-clip oleh kontainer tabel ber-overflow. Mengikat ke properti baris di
  scope Alpine induk, memakai array opsi induk.
    <x-search-cell name="`details[${i}][kode_coa]`" model="row.kode_coa" options="coaOpts" placeholder="— pilih akun —" />
--}}
@props(['name', 'model', 'options', 'placeholder' => '— pilih —'])

<div x-data="{
        open: false, q: '', gaya: '',
        posisikan() {
            const r = $refs.btn.getBoundingClientRect();
            const lebar = Math.max(r.width, 224);
            const ruangBawah = window.innerHeight - r.bottom;
            const keAtas = ruangBawah < 240 && r.top > ruangBawah;
            this.gaya = `left:${r.left}px; width:${lebar}px;` + (keAtas ? `bottom:${window.innerHeight - r.top + 2}px;` : `top:${r.bottom + 2}px;`);
        },
        buka() { this.open = !this.open; if (this.open) $nextTick(() => { this.posisikan(); $refs.q && $refs.q.focus(); }); },
     }"
     x-init="const f = () => { if (open) posisikan(); }; window.addEventListener('scroll', f, true); window.addEventListener('resize', f);"
     class="relative" @click.outside="open = false" @keydown.escape="open = false">
    <input type="hidden" :name="{{ $name }}" :value="{{ $model }}">
    <button type="button" x-ref="btn" @click="buka()"
            class="flex w-full items-center justify-between gap-1 rounded border border-gray-400 bg-white px-2 py-1.5 text-left text-sm">
        <span class="truncate"
              x-text="(({{ $options }}.find(o => String(o.v) === String({{ $model }})) || {}).l) || @js($placeholder)"
              :class="{{ $model }} ? 'text-gray-800' : 'text-gray-400'"></span>
        <span class="shrink-0 text-gray-400">▾</span>
    </button>
    <div x-show="open" x-cloak :style="gaya"
         class="fixed z-50 max-h-56 overflow-auto rounded-lg border border-gray-200 bg-white shadow-xl">
        <input x-ref="q" x-model="q" type="text" placeholder="Cari…"
               class="sticky top-0 w-full border-0 border-b border-gray-200 px-3 py-2 text-sm focus:outline-none">
        <template x-for="o in {{ $options }}.filter(o => String(o.l).toLowerCase().includes(q.trim().toLowerCase()))" :key="o.v">
            <div @click="{{ $model }} = String(o.v); open = false; q = ''"
                 class="cursor-pointer truncate px-3 py-1.5 text-sm hover:bg-brand-soft"
                 :class="String(o.v) === String({{ $model }}) ? 'bg-brand-soft font-medium text-brand' : 'text-gray-700'"
                 x-text="o.l"></div>
        </template>
        <div x-show="{{ $options }}.filter(o => String(o.l).toLowerCase().includes(q.trim().toLowerCase())).length === 0"
             class="px-3 py-2 text-sm text-gray-400">Tidak ada hasil.</div>
    </div>
</div>
