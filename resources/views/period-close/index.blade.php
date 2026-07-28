@extends('layouts.app')

@section('title', 'Tutup Buku Periode')

@php $NAMA_BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember']; @endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex items-end gap-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tahun</label>
                <input type="number" name="tahun" value="{{ $tahun }}" min="2000" max="2100"
                       class="w-28 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-brand focus:ring-brand">
            </div>
            <button class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50">Tampilkan</button>
        </form>
        @if ($status['tahun_ditutup'])
            <span class="rounded-full bg-blue-100 px-3 py-1 text-xs font-medium text-blue-700">🔒 Tahun {{ $tahun }} sudah ditutup buku ({{ $status['referensi_tutup_tahun'] }})</span>
        @endif
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    {{-- Grid 12 bulan --}}
    <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
        @foreach ($status['bulan'] as $b)
            <div class="rounded-xl border {{ $b['status'] === 'closed' ? 'border-gray-300 bg-gray-50' : 'border-gray-200 bg-white' }} p-4 shadow-sm">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-gray-800">{{ $NAMA_BULAN[$b['bulan']] }}</span>
                    @if ($b['status'] === 'closed')
                        <span class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-medium text-gray-600">Ditutup</span>
                    @else
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-700">Terbuka</span>
                    @endif
                </div>
                @if ($b['closed_at'])<div class="mt-1 text-[10px] text-gray-400">oleh {{ $b['nama_closed_by'] ?? '—' }}</div>@endif
                <div class="mt-2">
                    @if ($b['status'] === 'closed')
                        <form method="POST" action="{{ route('period_close.buka_bulan') }}" onsubmit="return confirm('Buka kembali {{ $NAMA_BULAN[$b['bulan']] }} {{ $tahun }}?')">
                            @csrf<input type="hidden" name="tahun" value="{{ $tahun }}"><input type="hidden" name="bulan" value="{{ $b['bulan'] }}">
                            <button class="text-xs text-indigo-600 hover:underline">Buka kembali</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('period_close.tutup_bulan') }}" onsubmit="return confirm('Tutup {{ $NAMA_BULAN[$b['bulan']] }} {{ $tahun }}?')">
                            @csrf<input type="hidden" name="tahun" value="{{ $tahun }}"><input type="hidden" name="bulan" value="{{ $b['bulan'] }}">
                            <button class="text-xs text-gray-600 hover:text-gray-800 hover:underline">Tutup bulan</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- Tutup buku tahunan --}}
    <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50/60 p-5">
        <h3 class="mb-2 text-sm font-semibold text-gray-800">Tutup Buku Tahunan {{ $tahun }}</h3>
        <p class="mb-3 text-xs text-gray-500">Menol-kan seluruh akun Pendapatan &amp; Beban tahun ini; laba/rugi bersih dipindah ke Laba Ditahan (jurnal TUTUP-{{ $tahun }}, 31 Des).</p>
        @if ($status['tahun_ditutup'])
            <form method="POST" action="{{ route('period_close.buka_tahun') }}" onsubmit="return confirm('Buka tutup buku tahunan {{ $tahun }} (balik jurnal penutup)?')">
                @csrf<input type="hidden" name="tahun" value="{{ $tahun }}">
                <button class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Buka Tutup Buku {{ $tahun }}</button>
            </form>
        @else
            <form method="POST" action="{{ route('period_close.tutup_tahun') }}" onsubmit="return confirm('Tutup buku tahunan {{ $tahun }}?')" class="flex flex-wrap items-end gap-3">
                @csrf<input type="hidden" name="tahun" value="{{ $tahun }}">
                <div class="min-w-[18rem]"><x-field name="kode_coa_laba_ditahan" label="Akun Laba Ditahan (Ekuitas)" :options="$coaOptions" required /></div>
                <button class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Tutup Buku {{ $tahun }}</button>
            </form>
        @endif
    </div>
@endsection
