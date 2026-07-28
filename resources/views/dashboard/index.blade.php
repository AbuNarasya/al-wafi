@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    {{-- Satu halaman Dashboard, isinya dipisah per tab. Tab yang tampil hanya
         yang haknya dimiliki pengguna (lihat DashboardController::index), jadi
         panitia PPSB bisa diberi tab PPSB tanpa melihat angka keuangan. --}}
    @if (count($tabs) > 1)
        <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200">
            @foreach ($tabs as $kunci => $label)
                <a href="{{ route('dashboard', ['tab' => $kunci]) }}"
                   class="-mb-px rounded-t-lg border-b-2 px-4 py-2 text-sm font-medium {{ $tab === $kunci
                       ? 'border-brand text-brand'
                       : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    @endif

    @include('dashboard.' . $tab)
@endsection
