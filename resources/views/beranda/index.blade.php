@extends('layouts.app')

@section('title', 'Beranda')

@section('content')
    @php
        $jam = (int) now()->format('G');
        $sapa = match (true) { $jam < 11 => 'Selamat pagi', $jam < 15 => 'Selamat siang', $jam < 18 => 'Selamat sore', default => 'Selamat malam' };
    @endphp

    {{-- Sapaan --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
        <div>
            <p class="text-lg font-semibold text-gray-900">{{ $sapa }}, {{ $pengguna->nama }}</p>
            <p class="text-sm text-gray-500">{{ now()->locale('id')->translatedFormat('l, j F Y') }} &middot; {{ \App\Support\Instans::nama() }}</p>
        </div>
        @if ($punyaDashboard)
            <a href="{{ route('dashboard') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand-dark">Buka Dashboard</a>
        @endif
    </div>

    {{-- Tugas Saya --}}
    <section class="mb-6">
        <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-600">Tugas Saya</h2>
        @if ($tugas === [])
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-5 py-4 text-sm text-gray-500">Tidak ada tugas yang menunggu.</div>
        @else
            <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($tugas as $t)
                    <a href="{{ url($t['url']) }}" class="flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 hover:bg-amber-100">
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold text-gray-900">{{ $t['menu'] }}</span>
                            <span class="block truncate text-xs text-gray-600">{{ $t['label'] }}</span>
                        </span>
                        <span class="shrink-0 rounded-full bg-red-600 px-2.5 py-0.5 text-sm font-bold text-white">{{ $t['jumlah'] }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Menu Saya: hanya yang boleh dibuka pengguna ini (sumbernya sama dengan sidebar). --}}
    <section>
        <h2 class="mb-2 text-sm font-bold uppercase tracking-wide text-gray-600">Menu Saya</h2>
        @if ($grup === [])
            <div class="rounded-xl border border-dashed border-gray-300 bg-white px-5 py-4 text-sm text-gray-500">
                Anda belum diberi akses ke menu mana pun. Hubungi admin untuk meminta hak akses.
            </div>
        @else
            <div class="gap-4 md:columns-2 xl:columns-3">
                @foreach ($grup as $g)
                    <div class="mb-4 break-inside-avoid rounded-xl border border-gray-200 bg-white shadow-sm">
                        <p class="border-b border-gray-100 px-4 py-2.5 text-xs font-bold uppercase tracking-wide text-brand">{{ $g['group'] }}</p>
                        <div class="px-4 py-2">
                            @foreach ($g['subs'] as $sub)
                                @if ($sub['sub'])
                                    <p class="mt-2 text-[11px] font-semibold uppercase text-gray-400">{{ $sub['sub'] }}</p>
                                @endif
                                <ul class="py-1">
                                    @foreach ($sub['items'] as $item)
                                        <li>
                                            <a href="{{ url($item['href']) }}" class="flex items-center justify-between gap-2 rounded px-1 py-1 text-sm text-gray-700 hover:bg-gray-50 hover:text-brand">
                                                <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                                                <x-badge-tugas :url="$item['url']" />
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endsection
