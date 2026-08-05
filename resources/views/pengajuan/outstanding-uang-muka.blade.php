@extends('layouts.app')

@section('title', 'Outstanding Uang Muka Saya')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500">Uang muka operasional milik Anda yang masih outstanding. Selesaikan lewat "Buat Penyelesaian Uang Muka".</p>
        <a href="{{ route('pengajuan.create_penyelesaian') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Buat Penyelesaian</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Nomor</th><th class="px-4 py-3">Akun Uang Muka</th><th class="px-4 py-3">Unit</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Sisa</th><th class="px-4 py-3 text-right">Aksi</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono">{{ $r['nomor_ref'] }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['nama_coa_uang_muka'] ?? $r['kode_coa_uang_muka'] }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $r['nama_unit'] ?? $r['kode_unit'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r['keterangan'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium">@rp($r['sisa'])</td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('pengajuan.create_penyelesaian') }}" class="text-brand hover:underline">Selesaikan</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada uang muka outstanding milik Anda.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
