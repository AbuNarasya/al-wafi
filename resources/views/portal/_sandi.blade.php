{{-- Isian password Portal Wali dengan tombol mata — salah ketik yang tak terlihat adalah sebab
     tersering "password saya tidak bisa dipakai". Pakai: @include('portal._sandi', ['nama' => 'sandi', 'label' => 'Password']) --}}
@php $autocomplete = $autocomplete ?? 'current-password'; @endphp
<div x-data="{ lihat: false }">
    <label for="{{ $nama }}" class="mb-1 block text-sm font-medium text-slate-700">{{ $label }}</label>
    <div class="relative">
        <input id="{{ $nama }}" name="{{ $nama }}" type="password" :type="lihat ? 'text' : 'password'" required
               autocomplete="{{ $autocomplete }}" @if (! empty($min)) minlength="{{ $min }}" @endif
               class="w-full rounded-2xl border border-slate-300 bg-white px-4 py-3 pr-12 text-base focus:border-brand focus:ring-brand">
        <button type="button" @click="lihat = ! lihat" tabindex="-1" :aria-label="lihat ? 'Sembunyikan password' : 'Tampilkan password'"
                class="absolute inset-y-0 right-0 flex items-center px-4 text-slate-400 hover:text-slate-600">
            <x-ikon-mata />
        </button>
    </div>
    @error($nama)<p class="mt-1 text-xs text-red-700">{{ $message }}</p>@enderror
</div>
