@extends('layouts.app')

@section('title', 'Level Otorisasi Keuangan')

@section('content')
    <div x-data="rowFilter" x-cloak>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <x-filter-bar placeholder="Cari kode / nama…" />

            @if (\App\Support\Akses::boleh('levels', 'buat'))
                <a href="{{ route('levels.create') }}"
                   class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">
                    + Tambah Level
                </a>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-4 py-3">Kode</th>
                        <th class="px-4 py-3">Nama Level</th>
                        <th class="px-4 py-3 text-right">Maks. Transaksi</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3 text-right">Aksi</th>
                    </tr>
                    <tr class="bg-white">
                        <x-fcol :col="0" />
                        <x-fcol :col="1" />
                        <x-fcol :col="2" />
                        <x-fcol :col="3" type="select" />
                        <x-fcol type="blank" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($levels as $level)
                        <tr data-row class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $level->kode_level }}</td>
                            <td class="px-4 py-3">{{ $level->nama_level }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if (is_null($level->max_transaksi))Tak terbatas@else @rp($level->max_transaksi)@endif
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $level->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ ucfirst($level->status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if (\App\Support\Akses::boleh('levels', 'ubah'))
                                        <a href="{{ route('levels.edit', $level) }}" class="text-brand hover:underline">Ubah</a>
                                    @endif
                                    @if (\App\Support\Akses::boleh('levels', 'hapus'))
                                        <form method="POST" action="{{ route('levels.destroy', $level) }}"
                                              onsubmit="return confirm('Hapus level {{ $level->kode_level }}?')">
                                            @csrf @method('DELETE')
                                            <button class="text-red-600 hover:underline">Hapus</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-gray-400">Belum ada data.</td>
                        </tr>
                    @endforelse
                    <tr data-empty style="display:none">
                        <td colspan="5" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
@endsection
