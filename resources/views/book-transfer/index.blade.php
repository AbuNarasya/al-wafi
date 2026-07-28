@extends('layouts.app')

@section('title', 'Pindah Buku')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Transfer dana antar rekening kas/bank. Jurnal: Debit rekening tujuan, Kredit rekening asal.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari referensi / rekening…" />
        @if (\App\Support\Akses::boleh('book-transfer', 'buat'))
            <a href="{{ route('book_transfer.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Pindah Buku Baru</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Referensi</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Dari → Ke</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Nominal</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" /><x-fcol :col="3" /><x-fcol type="blank" /><x-fcol :col="5" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r['referensi'] }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r['tanggal'])->format('d/m/Y') }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            <span class="text-gray-500">{{ $r['nama_asal'] }}</span>
                            <span class="mx-1 text-gray-400">&rarr;</span>
                            <span>{{ $r['nama_tujuan'] }}</span>
                        </td>
                        <td class="px-4 py-3 text-gray-600">{{ $r['keterangan'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">@rp($r['nominal'])</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r['status'] === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r['status']) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            @if ($r['status'] === 'aktif' && \App\Support\Akses::boleh('book-transfer', 'hapus'))
                                <div x-data="{ open: false }" class="relative inline-block text-left">
                                    <button @click="open = !open" class="text-red-600 hover:underline">Void</button>
                                    <form x-show="open" x-cloak @click.outside="open = false" method="POST" action="{{ route('book_transfer.void', $r['id']) }}"
                                          onsubmit="return confirm('Void pindah buku {{ $r['referensi'] }}?')"
                                          class="absolute right-0 z-10 mt-2 w-64 space-y-2 rounded-lg border border-gray-200 bg-white p-3 text-left shadow-lg">
                                        @csrf @method('DELETE')
                                        <label class="block text-xs font-medium text-gray-600">Alasan void</label>
                                        <input type="text" name="alasan" required maxlength="255" placeholder="mis. salah rekening" class="w-full rounded border-gray-300 text-sm">
                                        <button class="w-full rounded bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">Konfirmasi Void</button>
                                    </form>
                                </div>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Belum ada pindah buku.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="7" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
