@extends('layouts.app')

@section('title', 'Perizinan Insidental')

@php
    $jam = fn ($t) => $t?->timezone('Asia/Jakarta')->format('d/m H:i') ?? '—';
    $labelStatus = fn ($z) => match ($z->status) {
        'menunggu_1' => 'Menunggu '.$p->label_tingkat1,
        'menunggu_2' => 'Menunggu '.$p->label_tingkat2,
        default => $z->labelStatus(),
    };
@endphp

@section('content')
    <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">Perizinan Insidental</h2>
            <p class="text-sm text-gray-500">Izin pulang &amp; keluar lingkungan ma'had yang diajukan wali lewat portal (atau dicatat staf atas nama wali).</p>
        </div>
        <div class="flex gap-2">
            @if (\App\Support\Akses::boleh('perizinan', 'ubah'))
                <a href="{{ route('perizinan.pos') }}" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white">Pos keluar/masuk</a>
            @endif
            @if (\App\Support\Akses::boleh('perizinan', 'buat'))
                <a href="{{ route('perizinan.buat') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white">+ Atas nama wali</a>
            @endif
        </div>
    </div>

    @if ($terlambat > 0)
        <a href="{{ route('perizinan.index', ['tab' => 'keluar']) }}" class="mb-3 block rounded-xl border border-red-300 bg-red-50 px-4 py-3 text-sm font-semibold text-red-800">
            {{ $terlambat }} santri terlambat kembali dari izin — lihat &rarr;</a>
    @endif

    <div class="mb-3 flex flex-wrap gap-2 text-sm">
        @foreach (\App\Http\Controllers\PerizinanController::TAB as $k => $label)
            <a href="{{ route('perizinan.index', array_filter(['tab' => $k, 'q' => $cari ?: null, 'tipe' => $tipe, 'jk' => $jk])) }}" @class(['rounded-full px-4 py-1.5 font-semibold',
                'bg-brand text-white' => $tab === $k, 'bg-white text-gray-600 ring-1 ring-gray-300 hover:bg-gray-50' => $tab !== $k])>{{ $label }}@if ($jumlah[$k] !== null) <span class="ms-1 opacity-80">{{ $jumlah[$k] }}</span>@endif</a>
        @endforeach
    </div>

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2 rounded-xl border border-gray-200 bg-white p-3 shadow-sm">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <input name="q" value="{{ $cari }}" placeholder="Cari nama / NIS / nomor" class="w-56 rounded-lg border-gray-300 text-sm">
        <select name="tipe" class="rounded-lg border-gray-300 text-sm"><option value="">semua tipe</option>
            @foreach ($opsiTipe as $k => $n)<option value="{{ $k }}" @selected($tipe === $k)>{{ $n }}</option>@endforeach</select>
        <select name="jk" class="rounded-lg border-gray-300 text-sm"><option value="">ikhwan &amp; akhwat</option>
            <option value="L" @selected($jk === 'L')>ikhwan</option><option value="P" @selected($jk === 'P')>akhwat</option></select>
        <button class="rounded-lg bg-gray-800 px-3 py-2 text-sm font-semibold text-white">Saring</button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-100 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"><tr>
                <th class="px-4 py-2">Nomor</th><th class="px-4 py-2">Santri</th><th class="px-4 py-2">Tipe</th>
                <th class="px-4 py-2">Keluar</th><th class="px-4 py-2">Kembali</th><th class="px-4 py-2">Status</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $z)
                    <tr class="hover:bg-gray-50">
                        <td class="whitespace-nowrap px-4 py-2"><a href="{{ route('perizinan.show', $z->id) }}" class="font-mono text-xs text-brand hover:underline">{{ $z->nomor }}</a></td>
                        <td class="px-4 py-2">{{ $z->santri?->nama }} <span class="text-xs text-gray-400">{{ $z->santri?->kelas?->nama ?? $z->santri?->nis }}</span></td>
                        <td class="px-4 py-2 text-xs">{{ $z->tipe?->nama }}@if ($z->keperluan)<div class="max-w-xs truncate text-gray-500" title="{{ $z->keperluan }}">{{ $z->keperluan }}</div>@endif</td>
                        <td class="whitespace-nowrap px-4 py-2 text-xs">{{ $jam($z->rencana_keluar) }}@if ($z->keluar_pada)<div class="text-gray-500">keluar {{ $jam($z->keluar_pada) }}</div>@endif</td>
                        <td class="whitespace-nowrap px-4 py-2 text-xs">{{ $jam($z->rencana_kembali) }}@if ($z->kembali_pada)<div class="text-gray-500">kembali {{ $jam($z->kembali_pada) }}</div>@endif</td>
                        <td class="px-4 py-2"><span @class(['rounded px-1.5 py-0.5 text-[11px]',
                            'bg-amber-100 text-amber-800' => in_array($z->status, \App\Models\Perizinan::MENUNGGU, true),
                            'bg-sky-100 text-sky-800' => $z->status === 'disetujui', 'bg-red-100 text-red-800' => $z->terlambat() || $z->status === 'ditolak',
                            'bg-violet-100 text-violet-800' => $z->status === 'keluar' && ! $z->terlambat(),
                            'bg-emerald-100 text-emerald-800' => $z->status === 'kembali' && ! $z->terlambat(), 'bg-gray-100 text-gray-600' => $z->status === 'dibatalkan'])>{{ $labelStatus($z) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-400">Tidak ada data.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $rows->links() }}</div>
@endsection
