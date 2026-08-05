@extends('layouts.app')

@section('title', 'Tipe Biaya')

@section('content')
    @php $bolehUrut = \App\Support\Akses::boleh('tipe-biaya', 'ubah'); @endphp
    <div x-data="rowFilter" x-cloak>
        <p class="mb-3 text-sm text-gray-500">
            Tipe biaya menentukan <b>alur</b> yang diikuti sebuah jenis biaya. Tipe buatan sendiri wajib memilih salah satu
            perilaku bawaan agar tagihannya tetap tertangani modul pembayaran.
        </p>
        <x-urut-tabel :url="route('tipe_biaya.urutan')" :boleh="$bolehUrut">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <x-filter-bar placeholder="Cari kode / nama…" />
            @if (\App\Support\Akses::boleh('tipe-biaya', 'buat'))
                <a href="{{ route('tipe_biaya.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Tipe Biaya</a>
            @endif
        </div>

        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                    <tr><x-urut-kepala /><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Perilaku</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                    <tr class="bg-white">
                        <x-fcol type="blank" /><x-fcol :col="1" /><x-fcol :col="2" /><x-fcol :col="3" type="select" /><x-fcol :col="4" /><x-fcol :col="5" type="select" /><x-fcol type="blank" />
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $r)
                        <tr data-row data-kode="{{ $r->kode }}" class="hover:bg-gray-50">
                            <x-urut-sel :boleh="$bolehUrut" />
                            <td class="px-4 py-3 font-medium text-gray-900">{{ $r->kode }}
                                @if ($r->bawaan)<span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-slate-600">bawaan</span>@endif
                            </td>
                            <td class="px-4 py-3">{{ $r->nama }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded bg-indigo-50 px-1.5 py-0.5 text-xs font-medium text-indigo-700">{{ str_replace('_', ' ', ucfirst($r->perilaku)) }}</span>
                            </td>
                            <td class="px-4 py-3 text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($r->keterangan, 90) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if (\App\Support\Akses::boleh('tipe-biaya', 'ubah'))<a href="{{ route('tipe_biaya.edit', $r->kode) }}" class="text-brand hover:underline">Ubah</a>@endif
                                    @if (\App\Support\Akses::boleh('tipe-biaya', 'hapus') && ! $r->bawaan)
                                        <form method="POST" action="{{ route('tipe_biaya.destroy', $r->kode) }}" onsubmit="return confirm('Hapus tipe {{ $r->kode }}?')">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">Belum ada tipe biaya.</td></tr>
                    @endforelse
                    <tr data-empty style="display:none"><td colspan="7" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
                </tbody>
            </table>
        </div>
        </x-urut-tabel>
    </div>
@endsection
