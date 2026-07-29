@extends('layouts.app')

@section('title', 'Status Pengajuan Anggaran')

@php
    $labelStatus = [
        'diajukan' => 'bg-amber-100 text-amber-700', 'disetujui' => 'bg-emerald-100 text-emerald-700',
        'ditolak' => 'bg-red-100 text-red-700', 'dibatalkan' => 'bg-gray-100 text-gray-500',
    ];
    $adaFilter = $q !== '' || array_filter($filter);
@endphp

@section('content')
    {{-- Form GET berdiri sendiri: baris tabel memuat tombol POST (Batal),
         jadi tabel TIDAK boleh dibungkus form (form bersarang tak sah). --}}
    <form method="GET" id="filterAjuanAnggaran"></form>

    <div class="mb-3">
        <h2 class="text-xl font-semibold text-gray-900">Status Pengajuan Anggaran</h2>
        <p class="mt-1 max-w-3xl text-sm text-gray-500">
            Usulan anggaran per bagian yang menunggu persetujuan berjenjang. Anggaran baru berlaku
            setelah rantai <b>tuntas</b> — sebelum itu ia tidak memengaruhi realisasi maupun pemeriksaan overbudget.
        </p>
    </div>

    @if (session('status'))<div class="mb-3 rounded bg-emerald-50 px-3 py-2 text-sm text-emerald-700">{{ session('status') }}</div>@endif
    @if (session('error'))<div class="mb-3 rounded bg-red-50 px-3 py-2 text-sm text-red-700">{{ session('error') }}</div>@endif

    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-server placeholder="Cari nomor / keterangan…" :total="$rows->total()"
                         :reset="route('budget.pengajuan.index')" :aktif="(bool) $adaFilter" form="filterAjuanAnggaran" />
        @if ($bolehAjukan)
            <a href="{{ route('budget.pengajuan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Ajukan Anggaran</a>
        @endif
    </div>

    @php $uid = auth()->user()->id_pengguna; $isAdmin = auth()->user()->is_admin; @endphp
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">No. PA</th><th class="px-4 py-3">Tahun Anggaran</th>
                    <th class="px-4 py-3">Bagian</th><th class="px-4 py-3">Unit</th>
                    <th class="px-4 py-3 text-right">Akun</th><th class="px-4 py-3 text-right">Total Setahun</th>
                    <th class="px-4 py-3">Status</th><th class="px-4 py-3">Menunggu Di</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr>
                <tr class="bg-white">
                    <x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" /><x-scol type="blank" />
                    <x-scol type="blank" /><x-scol type="blank" />
                    <x-scol name="status" :options="$opsiStatus" :value="$filter['status']" form="filterAjuanAnggaran" />
                    <x-scol type="blank" /><x-scol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50 align-top">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nomor }}</td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600">{{ \App\Services\Ledger\AnggaranPeriode::labelTahunAnggaran($r->tahun, $r->bulan_awal) }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->bagian?->nama_bagian ?? $r->kode_bagian }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->kode_unit ?: 'Semua Unit' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-600">{{ $r->details_count }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r->nominal)</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $labelStatus[$r->status] ?? 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
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
                                <a href="{{ route('budget.pengajuan.show', $r->id) }}" class="rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50">Detail</a>
                                @if (in_array($r->status, ['diajukan', 'ditolak'], true) && ($isAdmin || $r->id_pengguna === $uid))
                                    <form method="POST" action="{{ route('budget.pengajuan.batal', $r->id) }}"
                                          onsubmit="return confirm('Batalkan pengajuan {{ $r->nomor }}? Rantai persetujuannya ikut ditutup.')">
                                        @csrf
                                        <button class="rounded border border-red-300 px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50">Batal</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="px-4 py-10 text-center text-gray-400">
                        {{ $adaFilter ? 'Tidak ada data yang cocok dengan filter.' : 'Belum ada pengajuan anggaran.' }}
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rows->links() }}</div>
@endsection
