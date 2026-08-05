@extends('layouts.app')

@section('title', 'Status Pengajuan')

@php
    $labelStatus = [
        'diajukan' => 'bg-amber-100 text-amber-700', 'disetujui' => 'bg-blue-100 text-blue-700',
        'diverifikasi' => 'bg-indigo-100 text-indigo-700', 'diposting' => 'bg-emerald-100 text-emerald-700',
        'lunas' => 'bg-emerald-100 text-emerald-700', 'ditolak' => 'bg-red-100 text-red-700',
        'dibatalkan' => 'bg-gray-100 text-gray-500',
    ];
@endphp

@php $adaFilter = $q !== '' || array_filter($filter); @endphp

@section('content')
    <form method="GET" id="filterPengajuan"></form>

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari nomor / keterangan…" :total="$rows->total()"
                         :reset="route('pengajuan.index')" :aktif="(bool) $adaFilter" form="filterPengajuan" />
        @if (\App\Support\Akses::boleh('pengajuan-pembayaran', 'buat'))
            <a href="{{ route('pengajuan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Pengajuan Baru</a>
        @endif
    </div>

    @php $uid = auth()->user()->id_pengguna; $isAdmin = auth()->user()->is_admin; @endphp
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">No. PB</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Jenis</th>
                    <th class="px-4 py-3">Bagian</th><th class="px-4 py-3">Keterangan</th>
                    {{-- "Sisa Dibayar", bukan "Sisa Hutang": kolom ini melayani dua
                         jenis dokumen — hutang pengajuan dan kekurangan penyelesaian. --}}
                    <th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3 text-right">Sisa Dibayar</th>
                    <th class="px-4 py-3">Status</th><th class="px-4 py-3">Menunggu Di</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr>
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="jenis" :options="$opsiJenis" :value="$filter['jenis']" form="filterPengajuan" />
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterPengajuan" />
                    <x-scol type="blank" /><x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50 align-top">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $r->tanggal->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ ucfirst(str_replace('_', ' ', $r->jenis)) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->bagian?->nama_bagian ?? $r->kode_bagian }}</td>
                        <td class="px-4 py-3 text-gray-500"><div class="max-w-[14rem] truncate" title="{{ $r->keterangan }}">{{ $r->keterangan }}</div></td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        {{-- sisaTagihan(), bukan sisa_hutang: pada penyelesaian uang muka
                             kolom itu menyimpan nominal uang mukanya, bukan kewajiban. --}}
                        @php $sisaTagih = $r->sisaTagihan(); @endphp
                        <td class="px-4 py-3 text-right tabular-nums">@if ((float) $sisaTagih > 0)@rp($sisaTagih)@else<span class="text-gray-300">—</span>@endif</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$r->status] ?? 'bg-gray-100 text-gray-500' }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                        <td class="px-4 py-3">
                            @php $m = $menunggu[$r->id] ?? null; @endphp
                            @if ($m)
                                <div class="font-medium text-gray-800">{{ $m['nama_tahap'] }}</div>
                                @if (count($m['kandidat']))
                                    <div class="text-xs text-brand">{{ implode(', ', $m['kandidat']) }}</div>
                                @else
                                    <div class="text-xs text-red-600">tak ada penyetuju</div>
                                @endif
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if ($r->status === 'ditolak' && $r->id_pengguna === $uid)
                                    <a href="{{ route('pengajuan.edit', $r->id) }}" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50">Perbaiki</a>
                                    <form method="POST" action="{{ route('pengajuan.ajukan_ulang', $r->id) }}" onsubmit="return confirm('Ajukan ulang {{ $r->nomor }}? Rantai persetujuan berjalan lagi.')">
                                        @csrf<button class="rounded border border-brand px-2 py-1 text-xs font-medium text-brand hover:bg-brand-soft">Ajukan Ulang</button>
                                    </form>
                                @endif
                                <a href="{{ route('pengajuan.show', $r->id) }}" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">Detail</a>
                                @if (in_array($r->status, ['diajukan', 'ditolak'], true) && ($isAdmin || $r->id_pengguna === $uid))
                                    <div x-data="{ open: false }" class="relative inline-block">
                                        <button @click="open = !open" class="rounded border border-red-300 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50">Void</button>
                                        <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('pengajuan.void', $r->id) }}"
                                              class="absolute right-0 z-10 mt-1 w-56 space-y-2 rounded-lg border border-gray-200 bg-white p-2 text-left shadow-lg">
                                            @csrf @method('DELETE')
                                            <input type="text" name="alasan" required maxlength="255" placeholder="Alasan void" class="w-full rounded border-gray-300 text-xs">
                                            <button class="w-full rounded bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">Konfirmasi Void</button>
                                        </form>
                                    </div>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada pengajuan.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
