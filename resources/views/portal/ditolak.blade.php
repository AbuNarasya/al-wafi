@extends('layouts.portal')

@section('title', 'Belum bisa masuk')

@section('content')
    <div class="mt-6 rounded-3xl bg-amber-50 p-5 text-sm leading-relaxed text-amber-900 ring-1 ring-amber-200">
        <p class="mb-1 font-bold">Belum bisa masuk</p>
        {{ $pesan }}
    </div>
    <a href="{{ route('portal.masuk') }}" class="mt-5 block text-center text-sm font-semibold text-brand">Kembali</a>
@endsection
