@extends('layouts.portal')

@section('title', __('Verifikasi Data'))

@section('content')
    <div class="mb-4">
        <h1 class="text-xl font-bold text-slate-900">{{ __("Assalamu'alaikum, :nama", ['nama' => $wali->{'nama_'.$peranSaya} ?: $wali->nama]) }}</h1>
        <p class="text-sm text-slate-500">{!! __('Mohon periksa data keluarga Anda. Selesaikan sebelum :tanggal.', ['tanggal' => '<b>'.e($link->berlaku_sampai->copy()->subDay()->locale(app()->getLocale())->translatedFormat('j F Y')).'</b>']) !!}</p>
    </div>

    @include('portal._langkah-verifikasi', ['aktif' => 1, 'lewat' => $lewat])

    <p class="mb-3 text-sm text-slate-500">{!! __('Periksa dan betulkan data orang tua / wali. Data yang sudah benar tidak perlu diubah. Isian bertanda :bintang wajib diisi.', ['bintang' => '<span class="text-red-500">*</span>']) !!}</p>
    <x-peringatan-kk portal class="mb-4" />

    <form method="POST" action="{{ route('portal.verifikasi.wali') }}" class="space-y-5" data-no-confirm>
        @csrf
        @method('PUT')

        @include('portal._isian-keluarga', ['wali' => $wali, 'peranSaya' => $peranSaya, 'emailLangsung' => true, 'punyaSandi' => $punyaSandi])

        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Simpan & lanjut') }} <span class="inline-block rtl:-scale-x-100">&rarr;</span></button>
    </form>
@endsection
