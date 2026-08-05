@extends('layouts.app')

@section('title', 'Jenjang')

@section('content')
    @php $bolehUrut = \App\Support\Akses::boleh('jenjang', 'ubah'); @endphp
    <div x-data="rowFilter" x-cloak>
    <p class="mb-3 text-sm text-gray-500">Master jenjang pendidikan. Menjadi sumber pilihan jenjang di data santri, jenis biaya, tarif SPP, potongan gelombang, dan target santri.</p>
    <x-urut-tabel :url="route('jenjang.urutan')" :boleh="$bolehUrut">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <x-filter-bar placeholder="Cari kode / nama…" />
        @if (\App\Support\Akses::boleh('jenjang', 'buat'))
            <a href="{{ route('jenjang.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Jenjang</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{-- Kolom "Urutan" dibuang: nomornya tak perlu dibaca lagi sejak
                     susunan barisnya sendiri yang menyatakan urutan (kolom pegangan). --}}
                <tr><x-urut-kepala /><th class="px-4 py-3">Kode</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3 text-right">Tingkat</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr>
                <tr class="bg-white">
                    <x-fcol type="blank" /><x-fcol :col="1" /><x-fcol :col="2" /><x-fcol type="blank" /><x-fcol :col="4" /><x-fcol :col="5" type="select" /><x-fcol type="blank" />
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    <tr data-row data-kode="{{ $r->kode }}" class="hover:bg-gray-50">
                        <x-urut-sel :boleh="$bolehUrut" />
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->kode }}</td>
                        <td class="px-4 py-3">{{ $r->nama }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">
                            @if ($r->jumlah_tingkat)
                                {{-- Rentangnya disebut, bukan cuma jumlahnya: sejak penomoran
                                     berkelanjutan, "3 tingkat" tak lagi memberi tahu tingkat berapa. --}}
                                <span class="text-gray-700">{{ $r->jumlah_tingkat }} tingkat</span>
                                <span class="block text-xs text-gray-400">Tingkat {{ $r->tingkatMulai() }}–{{ $r->tingkatAkhir() }}</span>
                            @else
                                <span class="text-xs text-amber-600" title="Tanpa ini, Tingkat tak bisa dipilih saat mendaftarkan calon santri">belum diisi</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $r->keterangan }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $r->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">{{ ucfirst($r->status) }}</span></td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('jenjang', 'ubah'))<a href="{{ route('jenjang.edit', $r->kode) }}" class="text-brand hover:underline">Ubah</a>@endif
                                @if (\App\Support\Akses::boleh('jenjang', 'hapus'))
                                    <form method="POST" action="{{ route('jenjang.destroy', $r->kode) }}" data-confirm="Hapus jenjang {{ $r->kode }}?">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-400">
                        Belum ada jenjang. Tambahkan dulu — pilihan jenjang di seluruh modul mengambil dari sini.
                    </td></tr>
                @endforelse
                <tr data-empty style="display:none"><td colspan="7" class="px-4 py-10 text-center text-gray-400">Tidak ada data yang cocok dengan filter.</td></tr>
            </tbody>
        </table>
    </div>
    </x-urut-tabel>
    </div>
@endsection
