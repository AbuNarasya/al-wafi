@extends('layouts.app')

@section('title', 'Level Pengajuan')

@section('content')
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-2xl text-sm text-gray-500">
            Anak tangga rantai persetujuan. <strong>Peringkat hanyalah urutan</strong> (1 = tertinggi);
            yang menentukan wewenang adalah tanda peran di kolom Peran. Jumlah levelnya bebas disesuaikan
            dengan struktur pesantren — peringkatnya sendiri tak bisa diubah setelah dibuat karena
            dirujuk data pengguna &amp; tahap rantai.
        </p>
        @if (\App\Support\Akses::boleh('level-pengajuan', 'buat'))
            <a href="{{ route('level_pengajuan.create') }}" class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Level</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Peringkat</th>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Peran</th>
                    <th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 text-right">Dipakai</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ($rows as $row)
                    @php($dipakaiTahap = $tahap[$row->peringkat] ?? 0)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $row->peringkat }}</td>
                        <td class="px-4 py-3">{{ $row->nama }}</td>
                        <td class="px-4 py-3">
                            {{-- Peran yang mati sengaja TIDAK ditampilkan: daftar penuh
                                 bertanda silang membuat yang menyala sulit dikenali. --}}
                            @php($aktif = collect(\App\Models\LevelPengajuan::PERAN)->filter(fn ($l, $k) => $row->{$k}))
                            @forelse ($aktif as $label)
                                <span class="mb-1 mr-1 inline-block rounded-full bg-brand/10 px-2 py-0.5 text-xs font-medium text-brand">{{ $label }}</span>
                            @empty
                                <span class="text-xs text-gray-400">Penyetuju saja</span>
                            @endforelse
                        </td>
                        <td class="max-w-md px-4 py-3 text-gray-500">{{ $row->keterangan }}</td>
                        <td class="px-4 py-3 text-right text-xs tabular-nums text-gray-500">
                            {{ $row->users_count }} pengguna<br>
                            {{ $dipakaiTahap }} tahap rantai
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $row->status === 'aktif' ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500' }}">
                                {{ ucfirst($row->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if (\App\Support\Akses::boleh('level-pengajuan', 'ubah'))
                                    <a href="{{ route('level_pengajuan.edit', $row) }}" class="text-brand hover:underline">Ubah</a>
                                @endif
                                @if (\App\Support\Akses::boleh('level-pengajuan', 'hapus'))
                                    @if ($row->users_count > 0 || $dipakaiTahap > 0)
                                        {{-- Penghalangnya disebut di tempat tombolnya berada,
                                             bukan baru setelah ditekan. --}}
                                        <span class="cursor-not-allowed text-gray-300" title="Masih dipakai {{ $row->users_count }} pengguna & {{ $dipakaiTahap }} tahap rantai">Hapus</span>
                                    @else
                                        <form method="POST" action="{{ route('level_pengajuan.destroy', $row) }}"
                                              data-confirm="Hapus level pengajuan {{ $row->nama }} (peringkat {{ $row->peringkat }})?">@csrf @method('DELETE')<button class="text-red-600 hover:underline">Hapus</button></form>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
