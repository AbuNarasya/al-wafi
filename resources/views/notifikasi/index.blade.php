@extends('layouts.app')

@section('title', 'Notifikasi')

@php
    $notif = new \App\Services\Modules\NotificationService;
    $tugasNyata = \App\Support\TugasSaya::daftar();
@endphp

@section('content')
    <div class="mx-auto max-w-3xl space-y-6">
        {{-- Bagian TUGAS: dihitung dari keadaan nyata, bukan dari baris notifikasi.
             Karena itu tak ada tombol "tandai dibaca" di sini — satu-satunya cara
             menghilangkannya adalah mengerjakan dokumennya. --}}
        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Menunggu dikerjakan</h2>
                <span class="text-xs text-gray-400">{{ \App\Support\TugasSaya::total() }} item</span>
            </div>
            @forelse ($tugasNyata as $t)
                <a href="{{ url($t['url']) }}" class="flex items-center gap-3 border-b border-gray-50 px-4 py-3 hover:bg-gray-50">
                    <span class="flex h-7 min-w-7 items-center justify-center rounded-full bg-amber-100 px-1.5 text-xs font-bold text-amber-800">{{ $t['jumlah'] }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-gray-900">{{ $t['menu'] }}</span>
                        <span class="block text-xs text-gray-500">{{ $t['label'] }}</span>
                    </span>
                    <span class="text-xs font-medium text-brand">Kerjakan &rarr;</span>
                </a>
            @empty
                <div class="px-4 py-8 text-center text-sm text-gray-400">Tidak ada pekerjaan yang menunggu Anda.</div>
            @endforelse
            @if (count($tugasNyata) > 0)
                <p class="px-4 py-2 text-[11px] text-gray-400">
                    Penanda ini bertahan sampai dokumennya diproses — membukanya saja tidak menghilangkannya.
                </p>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Kabar</h2>
                @if ($kabar->where('dibaca', false)->count() > 0)
                    <form method="POST" action="{{ route('notifikasi.baca_semua') }}" data-no-confirm>
                        @csrf
                        <button class="text-xs font-medium text-brand hover:underline">Tandai semua sudah dibaca</button>
                    </form>
                @endif
            </div>
            @forelse ($kabar as $n)
                @php $tautan = $notif->tautan($n, $idPpsb); @endphp
                <div class="flex items-start gap-3 border-b border-gray-50 px-4 py-3 {{ $n->dibaca ? 'opacity-60' : '' }}">
                    <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full {{ $n->dibaca ? 'bg-gray-300' : 'bg-brand' }}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium text-gray-900">{{ $n->judul }}</p>
                        <p class="mt-0.5 text-xs text-gray-600">{{ $n->pesan }}</p>
                        <p class="mt-1 text-[11px] text-gray-400">
                            {{ optional($n->created_at)->translatedFormat('d M Y H:i') }}
                            @if ($tautan)
                                &middot; <a href="{{ url($tautan) }}" class="text-brand hover:underline">Lihat dokumen</a>
                            @endif
                        </p>
                    </div>
                    @unless ($n->dibaca)
                        <form method="POST" action="{{ route('notifikasi.baca', $n->id) }}" data-no-confirm>
                            @csrf
                            <button class="shrink-0 text-xs text-gray-400 hover:text-gray-700">Tandai dibaca</button>
                        </form>
                    @endunless
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-gray-400">Belum ada kabar.</div>
            @endforelse
        </div>

        @if ($tugas->count() > 0)
            {{-- Notifikasi tugas yang barisnya masih tercatat menunggu. Ditampilkan
                 apa adanya sebagai riwayat; angka yang dipercaya tetap yang di atas. --}}
            <p class="text-center text-[11px] text-gray-400">
                {{ $tugas->count() }} catatan tugas tersimpan di riwayat notifikasi.
            </p>
        @endif
    </div>
@endsection
