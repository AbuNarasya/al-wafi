@props(['arah' => 'bawah'])
{{-- Pemilih bahasa portal bergaya pemilih bahasa Windows (permintaan user 22 Okt 2026): tombol kecil berisi
     kode bahasa aktif (ID / ENG / ع); diketuk → daftar dengan kode besar di kiri, nama bahasa (dalam bahasanya
     sendiri) + keterangan di kanan, pilihan aktif disorot. Lihat App\Support\BahasaPortal. --}}
@php
    $kini = app()->getLocale();
    $daftar = \App\Support\BahasaPortal::BAHASA;
@endphp
<div x-data="{ buka: false }" @keydown.escape.window="buka = false" @click.outside="buka = false" {{ $attributes->merge(['class' => 'relative']) }}>
    <button type="button" @click="buka = ! buka" :aria-expanded="buka" aria-haspopup="true" aria-label="{{ __('Pilih bahasa') }}"
            class="flex h-10 min-w-10 items-center justify-center rounded-lg px-2 text-xs font-bold tracking-wide text-slate-600 hover:bg-slate-100"
            lang="{{ $kini }}">{{ \App\Support\BahasaPortal::LABEL[$kini] }}</button>

    <form method="POST" action="{{ route('portal.bahasa') }}" x-show="buka" x-cloak x-transition.opacity data-no-confirm
          @class(['absolute end-0 z-50 w-64 overflow-hidden rounded-xl bg-slate-800 text-white shadow-xl ring-1 ring-black/10',
                  'top-full mt-2' => $arah === 'bawah', 'bottom-full mb-2' => $arah !== 'bawah'])>
        @csrf
        @foreach ($daftar as $kode => $nama)
            <button type="submit" name="bahasa" value="{{ $kode }}" @if ($kode === $kini) aria-current="true" @endif
                    @class(['flex w-full items-center gap-4 px-4 py-3 text-start', 'bg-brand' => $kode === $kini, 'hover:bg-white/10' => $kode !== $kini])>
                <span class="w-10 shrink-0 text-center text-base font-bold" lang="{{ $kode }}">{{ \App\Support\BahasaPortal::LABEL[$kode] }}</span>
                <span class="min-w-0 leading-snug">
                    <span class="block text-sm" lang="{{ $kode }}">{{ $nama }}</span>
                    <span class="block text-xs text-white/70">{{ __(\App\Support\BahasaPortal::KETERANGAN[$kode]) }}</span>
                </span>
            </button>
        @endforeach
    </form>
</div>
