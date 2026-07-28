@extends('layouts.app')

@section('title', 'Tahun Ajaran')

@section('content')
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Master tahun ajaran PPSB. Menjadi rujukan jenis biaya, jalur pendaftaran, potongan gelombang, target santri, dan pendaftaran calon santri baru.</p>
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari tahun ajaran…" />
        @if (\App\Support\Akses::boleh('tahun-ajaran', 'buat'))
            <a href="{{ route('tahun_ajaran.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Tahun Ajaran</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr><th class="px-4 py-3">Tahun Ajaran</th><th class="px-4 py-3">Periode</th><th class="px-4 py-3">Default Pendaftaran</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol :col="0" /><x-fcol :col="1" /><x-fcol :col="2" type="select" /><x-fcol :col="3" /><x-fcol :col="4" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->kode }}</td>
                        <td class="px-4 py-3 text-gray-500">
                            {{ optional($r->tanggal_mulai)->format('d/m/Y') ?? '—' }} s/d {{ optional($r->tanggal_selesai)->format('d/m/Y') ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @if ($r->default_pendaftaran)
                                <span class="rounded-full bg-brand/10 px-2 py-0.5 text-xs font-semibold text-brand">✔ Default</span>
                            @else
                                <span class="text-xs text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->keterangan }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('tahun-ajaran', 'ubah'))<a href="{{ route('tahun_ajaran.edit', $r->id) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('tahun-ajaran', 'hapus'))
                                    <form method="POST" action="{{ route('tahun_ajaran.destroy', $r->id) }}" data-confirm="Hapus tahun ajaran {{ $r->kode }}?">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada tahun ajaran. Tambahkan dulu — pendaftaran calon santri membutuhkannya.</td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="6" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </div>
@endsection
