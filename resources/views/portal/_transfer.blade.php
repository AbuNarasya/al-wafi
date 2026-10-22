{{--
  Isian transfer bank langsung: rekening tujuan + nominal + tanggal + bukti.
  Dipakai Isi Dompet & bayar tagihan calon. $rekening = list dari PortalBayarController::rekening().

  FOTO BUKTI DIKECILKAN DI HP sebelum dikirim. Batas unggahan aplikasi 500 KB
  (App\Support\Unggahan), sedangkan foto kamera HP lazimnya 2–4 MB — tanpa ini
  hampir setiap wali ditolak. PDF tak bisa dikecilkan, jadi yang terlalu besar
  diberi tahu sebelum terkirim.
--}}
@php
    $kelasInput = 'w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 text-base focus:border-brand focus:ring-brand';
    $maksByte = \App\Support\Unggahan::MAKS_KB * 1024;
@endphp

@if ($rekening === [])
    <div class="rounded-3xl bg-amber-50 p-5 text-sm text-amber-900 ring-1 ring-amber-200">
        {{ __('Rekening tujuan transfer belum disiapkan. Silakan hubungi bagian keuangan.') }}
    </div>
@else
    <section>
        <h2 class="mb-2 flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('1. Transfer ke rekening') }}</h2>
        <div class="space-y-2">
            @foreach ($rekening as $i => $r)
                <label class="flex cursor-pointer items-center gap-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200 has-[:checked]:ring-2 has-[:checked]:ring-brand">
                    <input type="radio" name="kode_rekening" value="{{ $r['kode'] }}" required class="text-brand focus:ring-brand"
                           @checked(old('kode_rekening', count($rekening) === 1 ? $r['kode'] : null) === $r['kode'])>
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-brand"><x-portal.ikon nama="bank" kelas="h-5 w-5" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold text-slate-900">{{ $r['bank'] }}</span>
                        <span class="block font-mono text-base tracking-wide text-slate-900">{{ $r['nomor'] }}</span>
                        <span class="block text-xs text-slate-500">{{ __('a.n. :nama', ['nama' => $r['atas_nama']]) }}</span>
                    </span>
                    <button type="button" class="shrink-0 rounded-xl bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"
                            onclick="event.preventDefault(); navigator.clipboard?.writeText(@js(preg_replace('/\D/', '', $r['nomor']))); this.textContent = @js(__('Tersalin'))">{{ __('Salin') }}</button>
                </label>
            @endforeach
        </div>
        @error('kode_rekening')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
    </section>

    <section class="mt-5 space-y-4">
        <h2 class="flex items-center gap-2 text-sm font-bold text-slate-800"><span class="h-4 w-1 rounded-full bg-brand"></span> {{ __('2. Isi data transfer') }}</h2>
        {{-- Bayar beberapa tagihan: nominalnya per tagihan di keranjang (nominal[id]), bukan di sini. --}}
        @unless ($tanpaNominal ?? false)
        <div>
            <label for="nominal" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Nominal yang ditransfer') }}</label>
            <input id="nominal" name="nominal" type="number" min="1" step="1" required
                   value="{{ old('nominal', $nominalAwal ?? '') }}" @if ($kunciNominal ?? false) readonly @endif class="{{ $kelasInput }}">
            @if ($kunciNominal ?? false)<p class="mt-1 text-xs text-slate-500">{{ __('Tagihan ini dilunasi sekaligus.') }}</p>@endif
            @error('nominal')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        @endunless
        <div>
            <label for="tanggal" class="mb-1 block text-sm font-medium text-slate-700">{{ __('Tanggal transfer') }}</label>
            <input id="tanggal" name="tanggal" type="date" required max="{{ now()->toDateString() }}"
                   value="{{ old('tanggal', now()->toDateString()) }}" class="{{ $kelasInput }}">
            @error('tanggal')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
        <div x-data="unggahBukti({{ $maksByte }})">
            <label class="mb-1 block text-sm font-medium text-slate-700">{{ __('Bukti transfer') }}</label>
            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-3xl border-2 border-dashed border-slate-300 bg-white p-5 text-center"
                   :class="seret && 'border-brand bg-blue-50'"
                   @dragover.prevent="seret = true" @dragleave.prevent="seret = false" @drop.prevent="jatuhkan($event)">
                <template x-if="pratinjau"><img :src="pratinjau" alt="{{ __('Pratinjau bukti') }}" class="max-h-48 rounded-xl"></template>
                <span x-show="! pratinjau" class="flex h-12 w-12 items-center justify-center rounded-2xl bg-slate-100 text-slate-500"><x-portal.ikon nama="unggah" /></span>
                <span class="text-sm font-semibold text-brand" x-text="nama || @js(__('Pilih foto / PDF bukti transfer'))"></span>
                <span class="text-xs text-slate-500" x-text="keterangan"></span>
                <span x-show="! nama" class="hidden text-xs text-slate-400 sm:inline" x-text="seret ? @js(__('Lepaskan berkas di sini')) : @js(__('atau seret berkas ke kotak ini'))"></span>
                <input type="file" name="bukti" accept="image/*,application/pdf" required class="sr-only" @change="pilih($event)">
            </label>
            <p x-show="galat" x-text="galat" x-cloak class="mt-1 text-xs text-red-700"></p>
            @error('bukti')<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
        </div>
    </section>

    {{-- window.unggahBukti (pengecil foto) kini di layouts/portal — dipakai juga halaman Berkas. --}}
@endif
