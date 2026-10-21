@extends('layouts.portal')

@section('title', 'Menu Lengkap')
@section('kembali', route('portal.beranda'))

@section('content')
    @php $maks = \App\Services\PortalWali\MenuPortal::MAKS; @endphp

    <div x-data="{ atur: false }">
        {{-- Seluruh menu, dikelompokkan (Keuangan · Akademik · Administrasi · Lainnya) --}}
        <div x-show="! atur" class="space-y-5">
            @foreach ($grup as $g)
                <section>
                    <h2 class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ $g['label'] }}</h2>
                    <div class="grid grid-cols-4 gap-x-2 gap-y-4">
                        @foreach ($g['menu'] as [$rute, $label, $ikon, $warna])
                            <a href="{{ route($rute) }}" class="flex flex-col items-center gap-1.5 text-center">
                                <span class="flex h-14 w-14 items-center justify-center rounded-2xl text-white shadow-sm" style="background: {{ $warna }}"><x-portal.ikon :nama="$ikon" /></span>
                                <span class="text-[11px] leading-tight text-slate-700">{{ $label }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <button type="button" @click="atur = true" class="w-full rounded-2xl bg-white py-3 text-sm font-semibold text-brand shadow-sm ring-1 ring-slate-200">
                Atur menu utama di beranda
            </button>
        </div>

        {{-- Atur menu utama: centang paling banyak 7, urutkan dengan panah. --}}
        <form x-show="atur" x-cloak method="POST" action="{{ route('portal.menu.simpan') }}" class="space-y-3"
              x-data="{
                  pilih: @js($pilihan),
                  semua: @js(collect($tersedia)->map(fn ($m, $k) => ['kunci' => $k, 'label' => $m[1], 'ikon' => $m[2], 'warna' => $m[3]])->values()),
                  maks: {{ $maks }},
                  label(k) { return this.semua.find(m => m.kunci === k)?.label ?? k },
                  alih(k) { const i = this.pilih.indexOf(k); if (i >= 0) this.pilih.splice(i, 1); else if (this.pilih.length < this.maks) this.pilih.push(k) },
                  geser(i, d) { const j = i + d; if (j < 0 || j >= this.pilih.length) return; [this.pilih[i], this.pilih[j]] = [this.pilih[j], this.pilih[i]] },
              }">
            @csrf
            <template x-for="k in pilih" :key="k"><input type="hidden" name="menu[]" :value="k"></template>

            <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-sm font-semibold text-slate-900">Urutan di beranda <span class="font-normal text-slate-500" x-text="`(${pilih.length}/${maks})`"></span></p>
                <ol class="mt-2 space-y-1">
                    <template x-for="(k, i) in pilih" :key="k">
                        <li class="flex items-center gap-2 rounded-2xl bg-slate-50 px-3 py-2 text-sm">
                            <span class="w-5 text-xs text-slate-400" x-text="i + 1"></span>
                            <span class="flex-1 text-slate-800" x-text="label(k)"></span>
                            <button type="button" @click="geser(i, -1)" class="px-2 text-slate-500" aria-label="Naik">&uarr;</button>
                            <button type="button" @click="geser(i, 1)" class="px-2 text-slate-500" aria-label="Turun">&darr;</button>
                        </li>
                    </template>
                </ol>
            </div>

            <div class="rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
                <p class="text-sm font-semibold text-slate-900">Pilih menu (paling banyak {{ $maks }})</p>
                <div class="mt-2 space-y-1">
                    <template x-for="m in semua" :key="m.kunci">
                        <label class="flex items-center gap-3 rounded-2xl px-2 py-1.5 text-sm" :class="! pilih.includes(m.kunci) && pilih.length >= maks ? 'opacity-40' : ''">
                            <input type="checkbox" :checked="pilih.includes(m.kunci)" @change="alih(m.kunci); $event.target.checked = pilih.includes(m.kunci)" class="rounded border-slate-300">
                            <span class="h-3 w-3 rounded" :style="`background: ${m.warna}`"></span>
                            <span class="text-slate-800" x-text="m.label"></span>
                        </label>
                    </template>
                </div>
                <p class="mt-2 text-xs text-slate-500">Menu yang tidak dipilih tetap ada di "Menu Lengkap".</p>
            </div>

            <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white" :disabled="pilih.length === 0">Simpan menu utama</button>
            <div class="flex justify-between text-sm">
                <button type="button" @click="atur = false" class="font-semibold text-slate-500">Batal</button>
                <button name="bawaan" value="1" class="font-semibold text-brand">Kembalikan bawaan</button>
            </div>
        </form>
    </div>
@endsection
