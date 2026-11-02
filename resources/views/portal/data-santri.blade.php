@extends('layouts.portal')

@section('title', __('Perbaharui Data Santri'))
@section('kembali', route('portal.data', ['tab' => 'santri']))

@section('content')
    <p class="mb-3 text-sm text-slate-500">{!! __('Perubahan langsung tersimpan dan bagian kesantrian diberi tahu. Isian bertanda :bintang wajib diisi.', ['bintang' => '<span class="text-red-500">*</span>']) !!}</p>
    <x-peringatan-kk portal class="mb-4" />

    <form method="POST" action="{{ route('portal.data.santri', $santri->id) }}" class="space-y-5"
          data-confirm="{{ __('Simpan perubahan data :nama?', ['nama' => $santri->nama]) }}">
        @csrf
        @method('PUT')

        @include('portal._isian-santri', ['santri' => $santri])

        <button class="w-full rounded-2xl bg-brand py-3 text-sm font-bold text-white">{{ __('Simpan perubahan') }}</button>
        <a href="{{ route('portal.data', ['tab' => 'santri']) }}" class="block text-center text-sm font-semibold text-slate-500">{{ __('Batal') }}</a>
    </form>
@endsection
