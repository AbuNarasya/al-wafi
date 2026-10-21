@extends('layouts.app')

@section('title', 'Riwayat Klinik '.$santri->nama)

@section('content')
    <a href="{{ route('klinik.index') }}" class="text-sm text-brand hover:underline">&larr; Klinik</a>
    <h1 class="mb-4 mt-1 text-lg font-bold text-gray-900">{{ $santri->nama }} <span class="text-sm font-normal text-gray-500">{{ $santri->nis }} · {{ $santri->jenjang?->nama }}{{ $santri->kelas ? ' · '.$santri->kelas->nama : '' }}</span></h1>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-2">Tanggal</th><th class="px-4 py-2">Keluhan</th><th class="px-4 py-2">Pemeriksaan</th><th class="px-4 py-2">Obat</th><th class="px-4 py-2">Tindak lanjut</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($kunjungan as $k)
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap"><a href="{{ route('klinik.show', $k->id) }}" class="text-brand hover:underline">{{ $k->datang_pada->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</a></td>
                        <td class="px-4 py-2">{{ $k->keluhan }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $k->pemeriksaan ?: '—' }}</td>
                        <td class="px-4 py-2 text-xs">{{ $k->obat->pluck('nama')->join(', ') ?: '—' }}</td>
                        <td class="px-4 py-2">{{ $k->labelTindakLanjut() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum pernah ke klinik.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $kunjungan->links() }}</div>
@endsection
