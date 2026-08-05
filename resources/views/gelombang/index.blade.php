@extends('layouts.app')

@section('title', 'Gelombang')

@section('content')
    <p class="mb-1 text-sm text-gray-500">
        Gelombang pendaftaran beserta <b>masa berlakunya</b>. Satu baris per gelombang — bukan per jenjang.
    </p>
    <p class="mb-4 text-sm text-gray-500">
        Besaran potongannya diatur terpisah di
        <a href="{{ route('gelombang.potongan', ['ta' => $ta]) }}" class="font-medium text-brand hover:underline">Potongan Gelombang</a>,
        karena satu gelombang bisa berbeda potongan tiap jenjang.
    </p>

    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <div>
                <label class="mb-1 block text-xs font-medium text-gray-600">Tahun Ajaran</label>
                <select name="ta" onchange="this.form.submit()" class="rounded-lg border border-gray-400 px-3 py-2 text-sm">
                    @foreach ($opsiTa as $kode => $label)
                        <option value="{{ $kode }}" @selected($kode === $ta)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <noscript><button class="rounded-lg border border-gray-300 px-3 py-2 text-sm">Tampilkan</button></noscript>
        </form>

        @if (\App\Support\Akses::boleh('potongan-gelombang', 'buat'))
            <a href="{{ route('gelombang.create', ['ta' => $ta]) }}"
               class="rounded-lg bg-brand px-3 py-1.5 text-sm font-semibold text-white hover:bg-brand-dark">+ Tambah Gelombang</a>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">
                <tr>
                    <th class="px-4 py-3">Kode</th>
                    <th class="px-4 py-3">Nama</th>
                    <th class="px-4 py-3">Periode Gelombang</th>
                    <th class="px-4 py-3 text-right">Masa Berlaku</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($rows as $r)
                    @php
                        $keadaan = $r->keadaan();
                        [$label, $warna] = match ($keadaan) {
                            'berlaku' => ['Aktif', 'bg-emerald-100 text-emerald-700'],
                            'belum_mulai' => ['Belum Mulai', 'bg-blue-100 text-blue-700'],
                            'kedaluwarsa' => ['Kedaluwarsa', 'bg-amber-100 text-amber-800'],
                            default => ['Arsip', 'bg-gray-100 text-gray-500'],
                        };
                    @endphp
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-mono text-xs text-gray-500">{{ $r->kode }}</td>
                        <td class="px-4 py-3 font-medium text-gray-900">{{ $r->nama }}</td>
                        <td class="px-4 py-3 text-gray-600">{{ $r->labelPeriode() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-gray-600">{{ $r->masa_berlaku_hari }} hari</td>
                        <td class="px-4 py-3">
                            {{-- Status DIHITUNG: gelombang berhenti berlaku sendiri saat
                                 periodenya lewat, tanpa ada yang mematikannya. --}}
                            <span class="rounded-full px-2 py-0.5 text-xs {{ $warna }}">{{ $label }}</span>
                            @if ($keadaan === 'kedaluwarsa')
                                <span class="mt-0.5 block text-[11px] text-gray-400">perpanjang untuk memberlakukan lagi</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-3">
                                @if (\App\Support\Akses::boleh('potongan-gelombang', 'ubah'))
                                    <a href="{{ route('gelombang.edit', $r->id) }}" class="text-brand hover:underline">Sunting</a>
                                @endif
                                @if (\App\Support\Akses::boleh('potongan-gelombang', 'hapus'))
                                    <form method="POST" action="{{ route('gelombang.destroy', $r->id) }}"
                                          data-confirm="Hapus gelombang {{ $r->nama }} beserta seluruh sel potongannya?">
                                        @csrf @method('DELETE')
                                        <button class="text-red-600 hover:underline">Hapus</button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-400">Belum ada gelombang untuk T.A ini.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endsection
