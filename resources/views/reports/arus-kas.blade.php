@extends('layouts.app')

@section('title', 'Arus Kas')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex items-end gap-2">
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @include('reports._download', ['type' => 'arus-kas'])
    </div>

    <p class="mb-3 text-sm text-gray-500">Kas Masuk dan Kas Keluar dikelompokkan per akun lawan (COA). Klik <b>Lihat</b> untuk rincian transaksinya.</p>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ([['Kas Masuk', $data['kas_masuk'], 'emerald'], ['Kas Keluar', $data['kas_keluar'], 'red']] as [$judul, $groups, $warna])
            <div x-data="{ open: {} }" class="rounded-xl border border-gray-200 bg-white shadow-sm">
                <div class="border-b border-gray-100 px-4 py-3 text-sm font-semibold uppercase tracking-wide text-{{ $warna }}-700">{{ $judul }}</div>
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="px-4 py-2">Akun COA</th>
                            <th class="px-4 py-2 text-right">Total</th>
                            <th class="px-4 py-2 text-right">Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($groups as $g)
                            <tr class="border-t border-gray-50">
                                <td class="px-4 py-2 text-gray-700">{{ $g['kode_coa'] }} — {{ $g['nama_coa'] }}</td>
                                <td class="px-4 py-2 text-right tabular-nums text-{{ $warna }}-700 font-medium">@rp($g['total'])</td>
                                <td class="px-4 py-2 text-right">
                                    @if (!empty($g['transaksi']))
                                        <button type="button" @click="open['{{ $g['kode_coa'] }}'] = !open['{{ $g['kode_coa'] }}']"
                                                class="text-xs text-brand hover:underline"
                                                x-text="open['{{ $g['kode_coa'] }}'] ? 'Tutup' : 'Lihat'">Lihat</button>
                                    @else
                                        <span class="text-xs text-gray-300">—</span>
                                    @endif
                                </td>
                            </tr>
                            @foreach ($g['transaksi'] as $t)
                                <tr x-show="open['{{ $g['kode_coa'] }}']" x-cloak class="bg-gray-50/60 text-xs">
                                    <td class="px-4 py-1 pl-8 text-gray-500">
                                        <span class="font-mono">{{ $t['nomor'] }}</span>
                                        · {{ \Illuminate\Support\Carbon::parse($t['tanggal'])->format('d/m/Y') }}
                                        @if (!empty($t['keterangan'])) · {{ $t['keterangan'] }}@endif
                                        @if (!empty($t['pihak'])) · {{ $t['pihak'] }}@endif
                                        @if (!empty($t['unit'])) · {{ $t['unit'] }}@endif
                                    </td>
                                    <td class="px-4 py-1 text-right tabular-nums text-gray-500">@rp($t['nominal'])</td>
                                    <td></td>
                                </tr>
                            @endforeach
                        @empty
                            <tr><td class="px-4 py-4 text-center text-gray-400" colspan="3">Tidak ada transaksi.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endforeach
    </div>

    <div class="mt-4 flex flex-wrap gap-4">
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm"><span class="text-emerald-700">Total Masuk:</span> <strong class="tabular-nums">@rp($data['total_masuk'])</strong></div>
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm"><span class="text-red-700">Total Keluar:</span> <strong class="tabular-nums">@rp($data['total_keluar'])</strong></div>
        <div class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm"><span class="text-gray-600">Kas Bersih:</span> <strong class="tabular-nums">@rp($data['kas_bersih'])</strong></div>
    </div>
@endsection
