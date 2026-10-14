@extends('layouts.portal')

@section('title', 'Verifikasi Data')

@php
    $label = \App\Models\Wali::PERAN[$peran] ?? $peran;
    $namaSaya = $wali->{'nama_'.$peran} ?: $wali->nama;
    $langkah = fn (bool $beres) => $beres
        ? 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-600 text-sm font-bold text-white'
        : 'flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-slate-200 text-sm font-bold text-slate-600';
@endphp

@section('content')
    <div class="mb-4">
        <h1 class="text-xl font-bold text-slate-900">Assalamu'alaikum, {{ $namaSaya }}</h1>
        <p class="text-sm text-slate-500">Mohon periksa data keluarga Anda. Selesaikan sebelum
            <b>{{ $link->berlaku_sampai->copy()->subDay()->locale('id')->translatedFormat('j F Y') }}</b>.</p>
    </div>

    <x-peringatan-kk portal class="mb-4" />

    {{-- 1. Data keluarga --}}
    <section class="mb-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start gap-3">
            <span class="{{ $langkah(false) }}">1</span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-slate-900">Data orang tua / wali</p>
                <p class="text-sm text-slate-500">
                    @foreach (\App\Models\Wali::PERAN as $p => $l)
                        @if (filled($wali->{'nama_'.$p}))
                            <span class="block">{{ $l }}: <b class="text-slate-700">{{ $wali->{'nama_'.$p} }}</b>{{ filled($wali->{'telepon_'.$p}) ? ' · '.$wali->{'telepon_'.$p} : '' }}</span>
                        @endif
                    @endforeach
                </p>
                <a href="{{ route('portal.data', ['tab' => 'wali']) }}" class="mt-2 inline-block text-sm font-semibold text-brand">Periksa &amp; perbarui &rarr;</a>
            </div>
        </div>
    </section>

    {{-- 2. Data anak --}}
    <section class="mb-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start gap-3">
            <span class="{{ $langkah(false) }}">2</span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-slate-900">Data santri ({{ $anak->count() }} anak)</p>
                <ul class="text-sm text-slate-500">
                    @forelse ($anak as $s)
                        <li><b class="text-slate-700">{{ $s->nama }}</b> &middot; {{ $s->jenjang?->nama ?? $s->kode_jenjang }}{{ $s->nis ? ' · NIS '.$s->nis : '' }}</li>
                    @empty
                        <li>Tidak ada santri aktif yang tercatat. Bila ini keliru, hubungi bagian kesantrian.</li>
                    @endforelse
                </ul>
                <a href="{{ route('portal.data', ['tab' => 'santri']) }}" class="mt-2 inline-block text-sm font-semibold text-brand">Periksa &amp; perbarui &rarr;</a>
            </div>
        </div>
    </section>

    {{-- 3. Email aktif — WAJIB, menjadi akun Portal Wali --}}
    <section class="mb-3 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        <div class="flex items-start gap-3">
            <span class="{{ $langkah($terbukti) }}">{!! $terbukti ? '&#10003;' : '3' !!}</span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-slate-900">Email aktif Anda ({{ strtolower($label) }})
                    @unless ($terbukti)
                        <span class="ml-1 rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-700">wajib</span>
                    @endunless
                </p>
                <p class="text-sm text-slate-500">Dipakai untuk masuk Portal Wali: kode masuk dikirim ke email ini.</p>
                @if ($email === '')
                    <p class="mt-2 text-sm font-medium text-amber-700">Belum ada email tercatat.</p>
                    <a href="{{ route('portal.data.email', $peran) }}" class="mt-2 inline-block rounded-2xl bg-brand px-4 py-2 text-sm font-semibold text-white">Isi email</a>
                @else
                    <p class="mt-2 break-all text-sm font-semibold text-slate-900">{{ $email }}</p>
                    @if ($terbukti)
                        <p class="text-sm font-medium text-emerald-700">Terbukti aktif.</p>
                    @elseif ($emailKembar)
                        <p class="mt-1 text-sm font-medium text-amber-700">Email ini juga tercatat di keluarga lain — ganti dengan email Anda sendiri.</p>
                        <a href="{{ route('portal.data.email', $peran) }}" class="mt-2 inline-block rounded-2xl bg-brand px-4 py-2 text-sm font-semibold text-white">Ganti email</a>
                    @else
                        <div class="mt-2 flex flex-wrap gap-2">
                            <form method="POST" action="{{ route('portal.verifikasi.email') }}" data-no-confirm>
                                @csrf
                                <button class="rounded-2xl bg-brand px-4 py-2 text-sm font-semibold text-white">Masih aktif — kirim kode</button>
                            </form>
                            <a href="{{ route('portal.data.email', $peran) }}" class="rounded-2xl px-4 py-2 text-sm font-semibold text-brand ring-1 ring-slate-300">Ganti email</a>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </section>

    {{-- Selesai --}}
    <form method="POST" action="{{ route('portal.verifikasi.selesai') }}" class="mt-4 rounded-3xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
          data-confirm="Selesaikan verifikasi? Pastikan data keluarga & santri sudah benar.">
        @csrf
        <label class="flex items-start gap-3 text-sm text-slate-700">
            <input type="checkbox" name="setuju" value="1" required @disabled(! $terbukti) class="mt-0.5 h-5 w-5 rounded border-slate-300 text-brand focus:ring-brand">
            <span>Saya sudah memeriksa data keluarga dan santri di atas, dan datanya <b>sudah benar &amp; sesuai Kartu Keluarga</b>.</span>
        </label>
        @error('setuju')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

        @if ($perluSandi && $terbukti)
            {{-- Password sekalian dibuat — login berikutnya langsung email + password, tanpa kode. --}}
            <div class="mt-4 space-y-3 rounded-2xl bg-slate-50 p-3">
                <p class="text-sm font-semibold text-slate-900">Buat password untuk masuk Portal Wali</p>
                @include('portal._sandi', ['nama' => 'sandi', 'label' => 'Password (minimal '.\App\Services\PortalWali\KataSandiWali::MIN.' karakter)',
                    'autocomplete' => 'new-password', 'min' => \App\Services\PortalWali\KataSandiWali::MIN])
                @include('portal._sandi', ['nama' => 'sandi_ulang', 'label' => 'Ulangi password', 'autocomplete' => 'new-password'])
            </div>
        @endif
        {{-- Data yang sudah benar tak perlu diubah — satu-satunya syarat adalah email terbukti.
             Labelnya menyebut syarat itu, supaya tombol abu-abu tak dikira "harus mengubah sesuatu". --}}
        <button @disabled(! $terbukti) class="mt-3 w-full rounded-2xl bg-emerald-600 py-3 text-sm font-bold text-white disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-600">
            {{ $terbukti ? 'Selesai — data sudah benar' : 'Buktikan email dulu (langkah 3)' }}
        </button>
        <p class="mt-2 text-center text-xs text-slate-500">Data yang sudah benar tidak perlu diubah.</p>
    </form>
@endsection
