@extends('layouts.app')

@section('title', 'Buku Besar')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('reports.index') }}" class="text-sm text-gray-500 hover:text-gray-700">&larr; Semua Laporan</a>
        <form method="GET" class="flex items-end gap-2">
            <div class="w-72"><label class="mb-1 block text-xs font-medium text-gray-500">Akun</label>
                <x-search-select name="kode_coa" :options="$akunList" :value="$kodeCoa" placeholder="— Pilih akun —" />
            </div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Dari</label>
                <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <div><label class="mb-1 block text-xs font-medium text-gray-500">Sampai</label>
                <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm"></div>
            <button class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">Tampilkan</button>
        </form>
        @if ($kodeCoa && $data)
            @include('reports._download', ['type' => 'buku-besar'])
        @endif
    </div>

    @if (! $data)
        <div class="rounded-xl border border-dashed border-gray-300 bg-white px-4 py-12 text-center text-gray-400">Pilih akun untuk menampilkan buku besar.</div>
    @else
        <div class="mb-3 flex items-center justify-between rounded-lg bg-white px-4 py-2 shadow-sm border border-gray-200">
            <div class="font-semibold text-gray-900">{{ $data['akun']['kode_coa'] }} — {{ $data['akun']['nama_coa'] }}</div>
            <div class="text-sm text-gray-500">Saldo Awal: <strong class="tabular-nums">@rp($data['saldo_awal'])</strong></div>
        </div>
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Debet</th><th class="px-4 py-3 text-right">Kredit</th><th class="px-4 py-3 text-right">Saldo</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($data['mutasi'] as $m)
                        <tr class="hover:bg-gray-50 {{ $m['status'] !== 'aktif' ? 'text-gray-400 line-through' : '' }}">
                            <td class="px-4 py-1.5 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($m['tanggal'])->format('d/m/Y') }}</td>
                            <td class="px-4 py-1.5">{{ $m['referensi'] }}</td>
                            <td class="px-4 py-1.5 text-gray-600">{{ $m['keterangan'] }}</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">@rp($m['debet'])</td>
                            <td class="px-4 py-1.5 text-right tabular-nums">@rp($m['kredit'])</td>
                            <td class="px-4 py-1.5 text-right tabular-nums font-medium">@rp($m['saldo'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-center text-gray-400">Tidak ada mutasi pada periode ini.</td></tr>
                    @endforelse
                    <tr class="border-t-2 border-gray-200 bg-gray-50 font-semibold">
                        <td class="px-4 py-2" colspan="5">Saldo Akhir</td>
                        <td class="px-4 py-2 text-right tabular-nums">@rp($data['saldo_akhir'])</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
@endsection
