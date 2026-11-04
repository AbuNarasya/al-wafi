@extends('layouts.app')

@section('title', 'Perizinan Rutin')

@php $wkt = fn ($t) => $t?->timezone('Asia/Jakarta')->locale('id')->translatedFormat('D, d M Y H:i'); @endphp

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm text-gray-500">Penjengukan &amp; Kepulangan per jadwal. Klik jadwal untuk melihat santri, pengajuan, dan menyetujui sekaligus.</p>
        <div class="flex gap-2 text-sm">
            <a href="{{ route('perizinan_rutin.index') }}" @class(['rounded-lg px-3 py-1.5', 'bg-brand text-white' => ! $lalu, 'border border-gray-300' => $lalu])>Akan datang</a>
            <a href="{{ route('perizinan_rutin.index', ['lalu' => 1]) }}" @class(['rounded-lg px-3 py-1.5', 'bg-brand text-white' => $lalu, 'border border-gray-300' => ! $lalu])>Sudah lewat</a>
            @if (\App\Support\Akses::boleh('perizinan-jadwal', 'lihat'))
                <a href="{{ route('perizinan_rutin.jadwal') }}" class="rounded-lg border border-gray-300 px-3 py-1.5 hover:bg-gray-50">Kelola jadwal</a>
            @endif
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Jadwal</th><th class="px-4 py-3">Cakupan</th><th class="px-4 py-3 text-right">Menunggu</th>
                    <th class="px-4 py-3 text-right">Disetujui</th><th class="px-4 py-3 text-right">Di luar</th><th class="px-4 py-3 text-right">Kembali</th></tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($jadwal as $j)
                    @php $h = ($hitung[$j->id] ?? collect())->pluck('n', 'status'); @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <a href="{{ route('perizinan_rutin.show', $j->id) }}" class="font-semibold text-brand hover:underline">{{ $j->tipe->nama }}</a>
                            @if ($j->status !== 'aktif')<span class="ms-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-700">Ditiadakan</span>@endif
                            @if ($j->khusus)<span class="ms-1 rounded bg-indigo-100 px-1.5 py-0.5 text-xs text-indigo-700">Khusus</span>@endif
                            <span class="block text-xs text-gray-500">{{ $wkt($j->mulai) }} → {{ $wkt($j->selesai) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs text-gray-600">{{ $j->labelCakupan() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ ($h['menunggu_1'] ?? 0) + ($h['menunggu_2'] ?? 0) ?: '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $h['disetujui'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $h['keluar'] ?? '—' }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $h['kembali'] ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">Belum ada jadwal. @if (\App\Support\Akses::boleh('perizinan-jadwal', 'lihat'))Buat lewat <a href="{{ route('perizinan_rutin.jadwal') }}" class="text-brand underline">Kelola jadwal</a>.@endif</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
