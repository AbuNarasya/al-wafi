@extends('layouts.app')

@section('title', 'Level Pengajuan')

@section('content')
    <p class="mb-4 max-w-2xl text-sm text-gray-500">
        Peringkat 1–4 adalah tulang punggung rantai persetujuan pembayaran (1 = tertinggi)
        dan tidak dapat ditambah/dihapus — hanya label &amp; status yang dapat disesuaikan.
    </p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Peringkat</th>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">Jumlah Pengguna</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $row->peringkat }}</td>
                        <td class="px-4 py-3">{{ $row->nama }}</td>
                        <td class="px-4 py-3 max-w-md text-gray-500">{{ $row->keterangan }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row->users_count }}</td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $row->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if (\App\Support\Akses::boleh('level-pengajuan', 'ubah'))
                                <a href="{{ route('level_pengajuan.edit', $row) }}" class="text-brand hover:underline">Ubah</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
