<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Kasir') — {{ $mitra->nama }}</title>

    <x-kepala-pwa />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
  Layout MITRA KANTIN — dibuka dari HP mitra. Tanpa menu ERP sama sekali: akun mitra hanya punya
  tiga layar (kasir, riwayat, laporan). Navigasi di bawah supaya terjangkau ibu jari.
--}}
<body class="h-full bg-slate-100 text-gray-800 antialiased">
<div id="bingkai-mitra" class="mx-auto flex min-h-full max-w-xl flex-col pb-[calc(3.25rem+env(safe-area-inset-bottom))]">
    <header class="flex items-center justify-between gap-2 bg-brand-dark px-4 py-3 text-white">
        <div class="min-w-0 leading-tight">
            <div class="truncate font-semibold">{{ $mitra->nama }}</div>
            <div class="text-xs text-white/70">Mitra Wafi Mart · {{ auth()->user()->username }}</div>
        </div>
        <form method="POST" action="{{ route('logout') }}" data-no-confirm>
            @csrf
            <button class="rounded border border-white/30 px-2 py-1 text-xs hover:bg-white/10">Keluar</button>
        </form>
    </header>

    @if (session('status'))
        <div class="bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="bg-red-50 px-4 py-2 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    <main class="flex-1 p-3">
        @yield('content')
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-20 border-t border-gray-200 bg-white pb-[env(safe-area-inset-bottom)]">
        @php $kabarSaldo = \App\Services\Modules\KabarKantin::kabarMitraBelumDibaca(auth()->id()); @endphp
        <div class="mx-auto grid max-w-xl grid-cols-4 text-center text-xs font-semibold">
            @foreach ([['mitra.kasir', 'Kasir'], ['mitra.riwayat', 'Riwayat'], ['mitra.laporan', 'Laporan'], ['mitra.saldo', 'Saldo']] as [$rute, $label])
                <a href="{{ route($rute) }}" class="relative py-3 {{ request()->routeIs($rute) ? 'text-brand' : 'text-gray-500' }}">
                    {{ $label }}
                    {{-- Titik merah: ada kabar penarikan (sudah ditransfer / ditolak) yang belum dilihat. --}}
                    @if ($rute === 'mitra.saldo' && $kabarSaldo > 0)
                        <span class="absolute right-1/4 top-2 h-2 w-2 rounded-full bg-red-500" aria-label="Ada kabar baru"></span>
                    @endif
                </a>
            @endforeach
        </div>
    </nav>
</div>
</body>
</html>
