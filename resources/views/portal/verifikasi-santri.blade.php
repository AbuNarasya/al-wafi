@extends('layouts.portal')

@section('title', __('Verifikasi Data'))

@section('content')
    @include('portal._langkah-verifikasi', ['aktif' => 2, 'lewat' => $lewat])

    <p class="mb-3 text-sm text-slate-500">{!! __('Periksa dan betulkan data santri. Data yang sudah benar tidak perlu diubah. Isian bertanda :bintang wajib diisi.', ['bintang' => '<span class="text-red-500">*</span>']) !!}</p>

    {{-- novalidate: isian wajib di panel anak yang tertutup tak bisa disorot browser (kirim gagal diam-diam);
         server yang memeriksa, lalu panel anak yang bermasalah terbuka dengan pesannya. --}}
    <form method="POST" action="{{ route('portal.verifikasi.santri') }}" class="space-y-5" data-no-confirm novalidate>
        @csrf
        @method('PUT')

        @forelse ($anak as $s)
            {{-- Banyak anak: satu panel per anak, yang pertama terbuka. --}}
            <div x-data="{ buka: @js($loop->first || $errors->has('santri.'.$s->id.'.*')) }" class="rounded-3xl p-2 ring-1 ring-slate-200">
                <button type="button" @click="buka = ! buka" class="flex w-full items-center justify-between gap-2 px-2 py-2 text-start">
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-slate-900">{{ $s->nama }}</span>
                        <span class="block text-xs text-slate-500">{{ $s->jenjang?->nama ?? $s->kode_jenjang }}{{ $s->nis ? ' · '.__('NIS :nis', ['nis' => $s->nis]) : '' }}</span>
                    </span>
                    <span class="shrink-0 text-xs font-semibold text-brand" x-text="buka ? @js(__('Tutup')) : @js(__('Periksa'))"></span>
                </button>
                <div x-show="buka" x-cloak class="mt-2 space-y-3">
                    @if (\App\Services\PortalWali\UbahDataWali::bolehUbahSantri($s))
                        @include('portal._isian-santri', ['santri' => $s, 'banyak' => true])
                    @else
                        <p class="rounded-3xl bg-white p-4 text-sm text-slate-600 ring-1 ring-slate-200">{{ __('Data santri berstatus :status tidak bisa diubah lewat portal. Hubungi bagian kesantrian.', ['status' => \App\Support\BahasaPortal::statusSantri((string) $s->status)]) }}</p>
                    @endif
                </div>
            </div>
        @empty
            <p class="rounded-3xl bg-white p-4 text-sm text-slate-600 shadow-sm ring-1 ring-slate-200">{{ __('Tidak ada santri aktif yang tercatat. Bila ini keliru, hubungi bagian kesantrian.') }}</p>
        @endforelse

        <div class="flex gap-2">
            <a href="{{ route('portal.verifikasi.wali') }}" class="flex-1 rounded-2xl bg-white py-3 text-center text-sm font-bold text-slate-700 ring-1 ring-slate-300"><span class="inline-block rtl:-scale-x-100">&larr;</span> {{ __('Kembali') }}</a>
            <button class="flex-[2] rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Simpan & lanjut') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></button>
        </div>
    </form>
@endsection
